<?php

namespace App\Services\Issues;

use App\Models\Capa\CapaAction;
use App\Models\Capa\RootCause;
use App\Models\Incident;
use App\Models\Issue;
use App\Models\User;
use App\Notifications\CapaCriticalNotification;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The integrated CAPA stage gates (IT-GOV-CAPA-001 §4), built on the issue lifecycle.
 *
 *   identification → containment → rca → action_planning → implementation → verification → closed
 *
 * Each gate checks the stage's evidence before the CAPA may leave it. Issue.status follows the
 * stage (config capa.stage_status) so the SLA clock, overdue sweep and dashboards keep working.
 * Verification is done by an independent internal auditor after the settling period; a failed
 * verification sends the CAPA back to implementation. Issues with no capa_framework never come
 * here: they keep IssueWorkflow::transition().
 */
class CapaWorkflow
{
    public function __construct(private IssueWorkflow $workflow) {}

    /** @return list<string> */
    public function stages(): array
    {
        return array_keys(config('capa.stages'));
    }

    public function nextStage(string $stage): ?string
    {
        $stages = $this->stages();
        $i = array_search($stage, $stages, true);

        return $i === false ? null : ($stages[$i + 1] ?? null);
    }

    /* ============================== Classification ============================== */

    /**
     * Choose / change the framework path, CBN cross-references, CBN correlation and settling
     * period. The first call turns the issue into a CAPA at the identification stage.
     *
     * @throws ValidationException
     */
    public function classify(Issue $issue, array $data, User $actor): string
    {
        if ($issue->capa_stage === 'closed' && $issue->isCapa()) {
            $this->fail('capa', 'A closed CAPA is locked. Raise a recurrence CAPA instead.');
        }
        $enabling = ! $issue->isCapa();
        if ($enabling && ! in_array($issue->status, ['open', 'in_progress'], true)) {
            $this->fail('capa_framework', 'Only an open or in-progress issue can be taken through the CAPA workflow.');
        }

        $framework = $data['capa_framework'] ?? $issue->capa_framework;
        $frameworks = config('capa.frameworks');
        if (! isset($frameworks[$framework])) {
            $this->fail('capa_framework', 'Choose the ISO 27001, COBIT 2019 or ITIL v4 path.');
        }
        $locked = ! $enabling && $issue->capa_stage !== 'identification';
        if ($locked && (($data['capa_framework'] ?? $issue->capa_framework) !== $issue->capa_framework
            || (array_key_exists('capa_classification', $data) && $data['capa_classification'] !== $issue->capa_classification))) {
            $this->fail('capa_framework', 'The framework path and classification are fixed once the CAPA leaves identification.');
        }

        $update = ['capa_framework' => $framework];
        if (array_key_exists('capa_classification', $data) || $enabling) {
            $classification = $data['capa_classification'] ?? null;
            if ($classification !== null && ! isset($frameworks[$framework]['classifications'][$classification])) {
                $this->fail('capa_classification', 'That classification does not belong to the '.$frameworks[$framework]['label'].' path.');
            }
            $update['capa_classification'] = $classification;
        } elseif ($issue->capa_classification && ! isset($frameworks[$framework]['classifications'][$issue->capa_classification])) {
            $update['capa_classification'] = null;
        }
        if (array_key_exists('framework_reference', $data)) {
            $reference = $data['framework_reference'];
            if ($reference !== null && ! isset($frameworks[$framework]['references'][$reference])) {
                $this->fail('framework_reference', 'That reference clause does not belong to the '.$frameworks[$framework]['label'].' path.');
            }
            $update['framework_reference'] = $reference;
        }
        if (array_key_exists('cbn_provisions', $data)) {
            $provisions = array_values(array_unique((array) ($data['cbn_provisions'] ?? [])));
            if (array_diff($provisions, array_keys(config('capa.cbn_provisions')))) {
                $this->fail('cbn_provisions', 'Unknown CBN provision.');
            }
            $update['cbn_provisions'] = $provisions;
        }
        if (array_key_exists('cbn_correlation', $data)) {
            if (in_array($issue->capa_stage, ['implementation', 'verification'], true) && ! $enabling
                && trim((string) $data['cbn_correlation']) !== trim((string) $issue->cbn_correlation)) {
                $this->fail('cbn_correlation', 'The CBN correlation is part of the approved plan and cannot change after approval.');
            }
            $update['cbn_correlation'] = filled($data['cbn_correlation']) ? trim($data['cbn_correlation']) : null;
        }
        if (array_key_exists('settling_days', $data) && $data['settling_days'] !== null) {
            if ($issue->capa_stage === 'verification' && ! $enabling) {
                $this->fail('settling_days', 'The settling period is running; it cannot change during verification.');
            }
            $update['settling_days'] = $this->settlingDays((int) $data['settling_days']);
        }

        if ($enabling) {
            $update += [
                'capa_stage' => 'identification',
                'status' => config('capa.stage_status.identification'),
                'settling_days' => $update['settling_days'] ?? (int) config('capa.settling_days.default'),
                'stage_signoffs' => [],
            ];
        }

        $correlationChanged = array_key_exists('cbn_correlation', $update) && $update['cbn_correlation'] !== $issue->cbn_correlation;
        $issue->update($update);

        if ($enabling) {
            $this->workflow->log($issue, 'capa', 'CAPA opened on the '.$frameworks[$framework]['label'].' path.', $actor, ['framework' => $framework]);
            $this->workflow->applyCapaSla($issue, $actor);
            $this->notifyCritical($issue);

            return 'CAPA opened. Complete identification to start containment.';
        }
        if ($correlationChanged && $issue->capa_stage === 'action_planning') {
            $this->invalidateSignoff($issue, 'action_planning', $actor, 'The CBN correlation changed.');
        }
        $this->workflow->log($issue, 'capa', 'CAPA classification updated.', $actor);

        return 'CAPA details saved.';
    }

    public function settlingDays(int $days): int
    {
        $min = (int) config('capa.settling_days.min');
        $max = (int) config('capa.settling_days.max');
        if ($days < $min || $days > $max) {
            $this->fail('settling_days', "The settling period must be between {$min} and {$max} days.");
        }

        return $days;
    }

    /* ================================ Stage gates ================================ */

    /** What stops the CAPA leaving its current stage (empty = it may advance). */
    public function blockers(Issue $issue): array
    {
        if (! $issue->isCapa()) {
            return ['This issue is not a CAPA.'];
        }
        $blockers = [];
        if ($issue->status === 'blocked') {
            $blockers[] = 'The issue is blocked: unblock it first.';
        }
        $framework = config('capa.frameworks.'.$issue->capa_framework);

        switch ($issue->capa_stage) {
            case 'identification':
                if (! $framework) {
                    $blockers[] = 'Choose a framework path.';
                }
                if (! $issue->capa_classification || ! isset($framework['classifications'][$issue->capa_classification])) {
                    $blockers[] = 'Classify the deficiency for the chosen framework.';
                }
                if (empty($issue->cbn_provisions)) {
                    $blockers[] = 'Cross-reference at least one CBN provision.';
                }
                break;

            case 'containment':
                if (! $issue->containmentActions()->exists()) {
                    $blockers[] = 'Record at least one containment action.';
                }
                break;

            case 'rca':
                $rca = $issue->capaRootCause;
                if (! $rca || ! $rca->isComplete()) {
                    $blockers[] = 'Complete the root cause analysis: a 5-whys chain of at least '.RootCause::MIN_WHYS
                        .' levels or at least one fishbone cause, and a root cause summary.';
                }
                break;

            case 'action_planning':
                $actions = $issue->capaActions()->where('status', '!=', 'cancelled')->get();
                if ($actions->isEmpty()) {
                    $blockers[] = 'Add at least one corrective action.';
                } elseif ($actions->contains(fn (CapaAction $a) => ! $a->owner_id || ! $a->due_date)) {
                    $blockers[] = 'Every corrective action needs an owner and a due date.';
                }
                if (blank($issue->cbn_correlation)) {
                    $blockers[] = 'State which CBN directive the permanent fix satisfies (CBN correlation).';
                }
                if ($this->signoffRequired('action_planning') && ! $this->signoffFor($issue, 'action_planning')) {
                    $blockers[] = 'The action plan needs sign-off by '.$this->accountableLabel('action_planning').'.';
                }
                break;

            case 'implementation':
                $actions = $issue->capaActions()->get();
                if ($actions->contains(fn (CapaAction $a) => $a->isOpen())) {
                    $blockers[] = 'Every corrective action must be done or cancelled.';
                }
                if (! $actions->contains('status', 'done')) {
                    $blockers[] = 'At least one corrective action must be done.';
                }
                break;

            case 'verification':
                $blockers[] = 'Record the independent verification outcome to close the CAPA.';
                break;

            default:
                $blockers[] = 'The CAPA is closed.';
        }

        return $blockers;
    }

    /**
     * Move the CAPA to its next stage when the current stage's gate is satisfied.
     *
     * @throws ValidationException
     */
    public function advance(Issue $issue, User $actor, ?string $note = null): string
    {
        $blockers = $this->blockers($issue);
        if ($blockers) {
            $this->fail('capa', implode(' ', $blockers));
        }
        $from = $issue->capa_stage;
        $to = $this->nextStage($from);
        $now = now();
        $update = ['capa_stage' => $to, 'status' => config('capa.stage_status.'.$to)];

        switch ($from) {
            case 'containment':
                $update['containment_completed_at'] = $now;
                break;
            case 'rca':
                $update['rca_completed_at'] = $now;
                if (blank($issue->root_cause)) {
                    $update['root_cause'] = $issue->capaRootCause->root_cause_summary;
                }
                break;
            case 'action_planning':
                $update['plan_approved_at'] = $now;
                if (blank($issue->remediation_plan)) {
                    $update['remediation_plan'] = $issue->capaActions()->where('status', '!=', 'cancelled')->get()
                        ->map(fn (CapaAction $a) => "{$a->action_code} {$a->title} (due {$a->due_date->toDateString()})")->implode("\n");
                }
                break;
            case 'implementation':
                $days = $issue->settling_days ?: (int) config('capa.settling_days.default');
                $update += [
                    'implemented_at' => $now,
                    'remediated_at' => $now,
                    'remediated_by_id' => $actor->id,
                    'settling_days' => $days,
                    'verification_due_at' => $now->copy()->addDays($days),
                ];
                break;
        }

        $issue->update($update);
        $labels = config('capa.stages');
        $suffix = $from === 'implementation'
            ? ' Settling period of '.$issue->settling_days.' days: verification opens '.$issue->verification_due_at->copy()->timezone('Africa/Lagos')->format('j M Y').'.'
            : '';
        $this->workflow->log($issue, 'capa_stage', "{$labels[$from]} complete; moved to ".strtolower($labels[$to]).'.'.($note ? " {$note}" : '').$suffix,
            $actor, ['from' => $from, 'to' => $to]);

        return "CAPA moved to {$labels[$to]}.".$suffix;
    }

    /* ================================ RACI sign-off ================================ */

    /** RACI role keys the user holds (Super Admin holds them all). */
    public function raciRolesOf(User $user): array
    {
        $all = config('capa.raci_roles');
        if ($user->hasRole('Super Admin')) {
            return array_keys($all);
        }

        return collect($all)->filter(fn ($r) => $user->hasAnyRole($r['roles']))->keys()->all();
    }

    public function accountableFor(string $stage): ?string
    {
        $row = config('capa.raci.'.$stage) ?? [];
        $key = array_search('A', $row, true);

        return $key === false ? null : $key;
    }

    public function accountableLabel(string $stage): string
    {
        $key = $this->accountableFor($stage);

        return $key ? config("capa.raci_roles.{$key}.label") : 'the accountable role';
    }

    public function isAccountable(User $user, string $stage): bool
    {
        $key = $this->accountableFor($stage);

        return $key !== null && in_array($key, $this->raciRolesOf($user), true);
    }

    public function signoffRequired(string $stage): bool
    {
        return in_array($stage, (array) config('capa.signoff_required'), true);
    }

    public function signoffFor(Issue $issue, string $stage): ?array
    {
        return ($issue->stage_signoffs ?? [])[$stage] ?? null;
    }

    /**
     * The current stage's Accountable role signs it off (RACI §5).
     *
     * @throws ValidationException
     */
    public function signOff(Issue $issue, User $actor, ?string $note = null): string
    {
        $stage = $issue->capa_stage;
        if (! $issue->isCapa() || in_array($stage, ['verification', 'closed'], true)) {
            $this->fail('capa', 'This stage is not signed off here.');
        }
        if (! $this->isAccountable($actor, $stage)) {
            $this->fail('capa', 'Only '.$this->accountableLabel($stage).' (Accountable in the RACI matrix) can sign off this stage.');
        }
        $signoffs = $issue->stage_signoffs ?? [];
        $signoffs[$stage] = [
            'user_id' => $actor->id,
            'name' => $actor->name,
            'raci_role' => $this->accountableFor($stage),
            'at' => now()->toIso8601String(),
            'note' => filled($note) ? trim($note) : null,
        ];
        $issue->update(['stage_signoffs' => $signoffs]);
        $this->workflow->log($issue, 'capa_signoff', config('capa.stages.'.$stage).' signed off by '.$actor->name.'.'.($note ? " {$note}" : ''), $actor, ['stage' => $stage]);

        return 'Stage signed off.';
    }

    /** A change to what was signed off voids the sign-off. */
    public function invalidateSignoff(Issue $issue, string $stage, ?User $actor, string $reason): void
    {
        $signoffs = $issue->stage_signoffs ?? [];
        if (! isset($signoffs[$stage])) {
            return;
        }
        unset($signoffs[$stage]);
        $issue->update(['stage_signoffs' => $signoffs]);
        $this->workflow->log($issue, 'capa_signoff', "{$reason} The ".strtolower(config('capa.stages.'.$stage)).' sign-off was withdrawn.', $actor, ['stage' => $stage, 'withdrawn' => true]);
    }

    /* ================================ Verification ================================ */

    /** Why this user may not verify this CAPA (empty = they may). */
    public function verifierBlockers(Issue $issue, User $user): array
    {
        $blockers = [];
        if (! $this->isAccountable($user, 'verification')) {
            $blockers[] = 'Verification is done by '.$this->accountableLabel('verification').' (Accountable in the RACI matrix).';
        }
        if ($issue->owner_id === $user->id) {
            $blockers[] = 'The issue owner cannot verify their own CAPA.';
        }
        if ($issue->remediated_by_id === $user->id) {
            $blockers[] = 'The person who completed implementation cannot verify it.';
        }
        if ($issue->capaActions()->where('owner_id', $user->id)->exists()) {
            $blockers[] = 'An owner of a corrective action cannot verify the CAPA.';
        }

        return $blockers;
    }

    public function canVerify(Issue $issue, User $user): bool
    {
        return $issue->isCapa() && $issue->capa_stage === 'verification' && ! $this->verifierBlockers($issue, $user);
    }

    /**
     * Record the independent effectiveness verification. pass closes the CAPA (only after the
     * settling period, with every framework criterion met); fail returns it to implementation.
     *
     * @param  array<string, bool>  $criteria
     *
     * @throws ValidationException
     */
    public function verify(Issue $issue, User $actor, string $outcome, array $criteria, ?string $note = null): string
    {
        if (! $issue->isCapa() || $issue->capa_stage !== 'verification') {
            $this->fail('capa', 'The CAPA is not awaiting verification.');
        }
        if ($blockers = $this->verifierBlockers($issue, $actor)) {
            $this->fail('capa', implode(' ', $blockers));
        }
        $keys = array_keys(config('capa.frameworks.'.$issue->capa_framework.'.verification_criteria', []));
        $results = collect($keys)->mapWithKeys(fn ($k) => [$k => (bool) ($criteria[$k] ?? false)])->all();
        $note = filled($note) ? trim($note) : null;

        if ($outcome === 'fail') {
            if (! $note) {
                $this->fail('note', 'Say why the fix did not prove effective.');
            }
            $issue->update([
                'capa_stage' => 'implementation',
                'status' => config('capa.stage_status.implementation'),
                'verification_criteria' => $results,
                'implemented_at' => null,
                'verification_due_at' => null,
                'remediated_at' => null,
                'remediated_by_id' => null,
            ]);
            $this->workflow->log($issue, 'capa_verification', "Effectiveness verification failed: {$note} Returned to implementation.", $actor,
                ['outcome' => 'fail', 'criteria' => $results]);

            return 'Verification failed. The CAPA is back in implementation.';
        }

        if ($issue->verification_due_at && now()->lt($issue->verification_due_at)) {
            $this->fail('capa', 'The settling period runs until '.$issue->verification_due_at->copy()->timezone('Africa/Lagos')->format('j M Y H:i')
                .'. Effectiveness cannot be confirmed before then.');
        }
        if (in_array(false, $results, true)) {
            $this->fail('criteria', 'Every '.config('capa.frameworks.'.$issue->capa_framework.'.label').' verification criterion must be met to close the CAPA.');
        }
        if ($issue->capa_framework === 'itilv4' && ($recurrences = $this->recurrencesSinceImplementation($issue))->isNotEmpty()) {
            $this->fail('capa', 'The problem has recurred since implementation ('.$recurrences->implode(', ')
                .'). Fail the verification and address the recurrence.');
        }

        $now = now();
        $issue->update([
            'capa_stage' => 'closed',
            'status' => config('capa.stage_status.closed'),
            'verification_criteria' => $results,
            'verified_at' => $now,
            'verified_by_id' => $actor->id,
            'closed_at' => $now,
        ]);
        $this->workflow->log($issue, 'capa_verification', 'Effectiveness verified independently; CAPA closed.'.($note ? " {$note}" : ''), $actor,
            ['outcome' => 'pass', 'criteria' => $results]);

        return 'Effectiveness verified. The CAPA is closed.';
    }

    /**
     * ITIL: evidence the problem came back after implementation — a recurrence CAPA, the same
     * source finding raised again, or an incident tagged "capa:{issue_code}".
     */
    public function recurrencesSinceImplementation(Issue $issue): Collection
    {
        $since = $issue->implemented_at;
        if (! $since) {
            return collect();
        }
        $issues = Issue::where('id', '!=', $issue->id)->where('created_at', '>=', $since)
            ->where(function ($q) use ($issue) {
                $q->where('recurrence_of_id', $issue->id);
                if ($issue->source_id) {
                    $q->orWhere(fn ($w) => $w->where('source_type', $issue->source_type)->where('source_id', $issue->source_id));
                }
            })->pluck('issue_code');

        $tag = 'capa:'.$issue->issue_code;
        $incidents = Incident::where(fn ($q) => $q->where('detected_at', '>=', $since)->orWhere('created_at', '>=', $since))
            ->get(['id', 'incident_id_code', 'tags'])
            ->filter(fn (Incident $i) => in_array($tag, $i->tags ?? [], true))
            ->pluck('incident_id_code');

        return $issues->merge($incidents)->filter()->values();
    }

    /* ================================ Escalation ================================ */

    /**
     * Notify the CIO, CISO and Risk Committee of a critical CAPA (once). Returns whether anything
     * was sent.
     */
    public function notifyCritical(Issue $issue): bool
    {
        if (! $issue->isCapa() || $issue->severity !== 'critical' || $issue->critical_notified_at || $issue->capa_stage === 'closed') {
            return false;
        }
        $rule = config('capa.escalation.critical');
        $steps = (array) ($rule['notify'] ?? []);
        $recipients = collect($steps)->flatMap(fn ($step) => $this->workflow->recipients($issue, $step))->unique('id')->values();

        $issue->forceFill(['critical_notified_at' => now()])->save();
        $this->workflow->log($issue, 'escalated', 'Critical CAPA: '.implode(', ', $steps).' notified (policy: within '.($rule['within_hours'] ?? 2).' h).', null, [
            'to' => $steps, 'notified' => $recipients->pluck('name')->all(), 'capa_critical' => true,
        ]);
        foreach ($recipients as $user) {
            $user->notify(new CapaCriticalNotification($issue, $steps));
        }

        return true;
    }

    /** Scheduled safety net across every bank: critical CAPAs not yet notified. */
    public function escalateCritical(): int
    {
        $count = 0;
        Issue::withoutGlobalScopes()->whereNotNull('capa_framework')->where('severity', 'critical')
            ->whereNull('critical_notified_at')->where('capa_stage', '!=', 'closed')
            ->orderBy('id')
            ->each(function (Issue $issue) use (&$count) {
                if ($this->notifyCritical($issue)) {
                    $count++;
                }
            });

        return $count;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
