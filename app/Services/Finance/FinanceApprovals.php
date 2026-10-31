<?php

namespace App\Services\Finance;

use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Models\Approvals\ApprovalDecision;
use App\Models\Approvals\ApprovalRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Approvals\ApprovalProcesses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The single seam between finance records and the generic approval engine (plan C31). Starting an
 * approval snapshots the DoA band the amount fell in, so a later band change never rewrites history.
 */
class FinanceApprovals
{
    public function __construct(private readonly ApprovalEngine $engine, private readonly DoaBandService $bands) {}

    public function start(string $process, Model $subject, User $actor): ?ApprovalRequest
    {
        $this->engine->assertCanInitiate($process, $actor);
        $request = $this->engine->start($process, $subject, $actor);
        if ($request) {
            $request->forceFill(['context' => $this->snapshot($process, $subject)])->save();
        }
        event(new FinanceApprovalRequested($process, $subject, $request, $actor));

        return $request;
    }

    /** @return array<string, mixed> */
    public function snapshot(string $process, Model $subject): array
    {
        $amount = method_exists($subject, 'approvalBaseAmount') ? (string) $subject->approvalBaseAmount() : (string) ($subject->base_amount ?? $subject->total_base_amount ?? '0');
        $band = $this->bands->bandFor((int) $subject->organization_id, $amount);
        $conditions = [];
        foreach (array_keys(ApprovalProcesses::get($process)['conditions'] ?? []) as $field) {
            $conditions[$field] = (string) $subject->{$field};
        }

        return [
            'captured_at' => now()->toIso8601String(), 'base_amount' => $amount, 'amount_band' => $band['key'],
            'band' => ['label' => $band['label'], 'min' => $band['min'], 'max' => $band['max']],
            'doa_band_version_id' => $band['version_id'], 'doa_band_version_no' => $band['version_no'],
            'conditions' => $conditions,
        ] + (method_exists($subject, 'sodExcludedIds') ? ['sod_excluded_ids' => $subject->sodExcludedIds()] + (array_key_exists('contract_owner_id', $subject->getAttributes()) || method_exists($subject, 'getContractOwnerIdAttribute') ? ['contract_owner_id' => $subject->contract_owner_id] : []) : []);
    }

    /** Records the approval; a message means stages remain (the record stays in review), null means final. */
    public function approve(string $process, Model $subject, User $actor, ?string $note = null): ?string
    {
        if (! $this->engine->current($process, $subject) && $this->engine->workflow((int) $subject->organization_id, $process)) {
            // Workflow switched on after submit: start it here so the SoD snapshot is written before anyone decides.
            $late = $this->engine->start($process, $subject);
            $late && $late->forceFill(['context' => $this->snapshot($process, $subject)])->save();
        }
        $this->assertEligible($process, $subject, $actor);
        $message = $this->engine->gate($process, $subject, $actor, $note);
        if ($message !== null) {
            event(new FinanceApprovalRequested($process, $subject, $this->engine->current($process, $subject), $actor));
        }

        return $message;
    }

    /**
     * Look-ahead for a decision about to be recorded: refuses it when it would leave a later pending stage with nobody who can still decide
     * (each person decides one stage only, so the actor and everyone who already decided are out, as are the initiator and the SoD snapshot).
     * Stages are matched to distinct people, so two later stages cannot both rely on the same single person.
     */
    public function assertNoStageStranded(string $process, Model $subject, User $actor): void
    {
        $request = $this->engine->current($process, $subject);
        $current = $request?->currentStep();
        if (! $request || ! $current) {
            return;
        }
        $out = array_map('intval', (array) ($request->context['sod_excluded_ids'] ?? []));
        $request->initiated_by && $out[] = (int) $request->initiated_by;
        $out[] = (int) $actor->id;
        $decided = ApprovalDecision::withoutGlobalScopes()->whereIn('step_id', $request->steps->pluck('id'))->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $out = array_merge($out, $decided);
        $slots = [];
        $users = User::where('organization_id', $request->organization_id)->get()->reject(fn ($u) => in_array((int) $u->id, $out, true));
        $steps = $request->steps->where('status', 'pending')->where('id', '!=', $current->id);
        // The current stage may still need more approvals from other people before it closes.
        $remaining = max(0, (int) $current->min_approvals - $current->decisions->where('decision', 'approved')->count() - 1);
        foreach ($steps->prepend($current)->values() as $k => $step) {
            $need = $k === 0 ? $remaining : max(1, (int) $step->min_approvals);
            for ($n = 0; $n < $need; $n++) {
                $slots[] = [$step, $users->filter(fn ($u) => $this->stepMatches($step, $u))->pluck('id')->map(fn ($v) => (int) $v)->all()];
            }
        }
        $owner = [];
        $try = function (int $s, array &$seen) use (&$try, &$owner, $slots): bool {
            foreach ($slots[$s][1] as $uid) {
                if (isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                if (! isset($owner[$uid]) || $try($owner[$uid], $seen)) {
                    $owner[$uid] = $s;

                    return true;
                }
            }

            return false;
        };
        foreach ($slots as $s => [$step]) {
            $seen = [];
            if (! $try($s, $seen)) {
                throw ValidationException::withMessages(['approval' => "This decision would leave \"{$step->name}\" without anyone who can still decide it ("
                    .$this->engine->describe($step).'; people who decided an earlier stage, took part in the record or started it are excluded). '
                    .'Ask an administrator to adjust the workflow or its people first.']);
            }
        }
    }

    /** Segment-exact job-title match (P7-3): the title is split on / , & ; and "and", and any trimmed part must equal the reference, case-insensitively. */
    public static function jobTitleMatches(string $title, string $ref): bool
    {
        $ref = mb_strtolower(trim($ref));
        if ($ref === '') {
            return false;
        }
        foreach (preg_split('/\s*(?:[\/,&;]|\band\b)\s*/iu', $title) ?: [] as $part) {
            if (mb_strtolower(trim($part)) === $ref) {
                return true;
            }
        }

        return false;
    }

    /** Engine matching, except that job-title stages use the exact segment rule (the engine's substring rule is a logged platform defect). */
    private function stepMatches(object $step, User $user): bool
    {
        if ($step->approver_type === 'job_title' && ! $user->hasRole('Super Admin')) {
            return self::jobTitleMatches((string) $user->job_title, (string) $step->approver_ref);
        }

        return $this->engine->matches($step, $user);
    }

    /** Refuses an actor whose job title does not match the current job-title stage, before the engine sees the decision. */
    public function assertEligible(string $process, Model $subject, User $actor): void
    {
        $step = $this->engine->current($process, $subject)?->currentStep();
        if ($step && $step->approver_type === 'job_title' && ! $this->stepMatches($step, $actor)) {
            throw ValidationException::withMessages(['approval' => "Your job title does not match the stage \"{$step->name}\" (".$this->engine->describe($step).'), so you cannot decide it.']);
        }
    }

    public function finalised(string $process, Model $subject, User $actor, ?string $note = null): void
    {
        event(new FinanceApprovalDecided($process, $subject, 'approved', $actor, $note));
    }

    /** $outcome is returned or rejected. */
    public function decline(string $process, Model $subject, User $actor, string $note, string $outcome): void
    {
        $this->assertEligible($process, $subject, $actor);
        $this->engine->reject($process, $subject, $actor, $note)
            || $this->engine->cancel($process, $subject, $outcome === 'returned' ? 'Returned to the author' : 'Rejected');
        event(new FinanceApprovalDecided($process, $subject, $outcome, $actor, $note));
    }

    public function withdraw(string $process, Model $subject, User $actor): void
    {
        $this->engine->cancel($process, $subject, 'Withdrawn by '.$actor->name);
        event(new FinanceApprovalDecided($process, $subject, 'withdrawn', $actor, null));
    }

    /** Cancels a pending request with no acting user (sweeps closing a contract renewal). */
    public function cancelQuietly(string $process, Model $subject, string $reason): void
    {
        $this->engine->cancel($process, $subject, $reason);
    }

    /** True when the record is awaiting a stage of the "owner" type whose named owner is $u (plan P3-6, C74). */
    public function isCurrentOwnerApprover(string $process, Model $subject, User $u): bool
    {
        $request = $this->engine->current($process, $subject);
        $step = $request?->currentStep();

        return $step !== null && $step->approver_type === 'owner' && (int) $step->approver_ref === (int) $u->id;
    }

    public function configured(string $process, int $orgId): bool
    {
        return $this->engine->workflow($orgId, $process) !== null;
    }

    public function trail(string $process, Model $subject, User $viewer): array
    {
        $trail = $this->engine->trail($process, $subject, $viewer);
        $request = ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())->latest('id')->first();
        $trail['context'] = $request?->context;
        if (! app(FinanceMasking::class)->canSeeAmounts($viewer)) { // the DoA band discloses the amount (P5 security M1)
            if (is_array($trail['context'])) {
                foreach (['amount_band', 'amount_band_label', 'approval_base_amount', 'base_amount'] as $k) {
                    array_key_exists($k, $trail['context']) && $trail['context'][$k] = null;
                }
                array_key_exists('band', $trail['context']) && $trail['context']['band'] = null;
                isset($trail['context']['conditions']['amount_band']) && $trail['context']['conditions']['amount_band'] = null;
                foreach (['requires_cfo', 'requires_board'] as $k) { // these flags disclose that the amount crossed a threshold (P6 security L6)
                    array_key_exists($k, $trail['context']['conditions'] ?? []) && $trail['context']['conditions'][$k] = null;
                    array_key_exists($k, $trail['context']) && $trail['context'][$k] = null;
                }
            }
            $trail['stages'] = collect($trail['stages'])->map(fn ($s) => is_array($s) && str_starts_with((string) ($s['condition'] ?? ''), 'amount_band') ? array_merge($s, ['condition' => null]) : $s)->values();
        }

        return $trail;
    }

    /**
     * A configured workflow whose stages all fail their conditions auto-approves with no steps (engine behaviour),
     * which would leave nobody to enforce segregation of duties. For such a request apply the default rule,
     * and also exclude whoever created the record.
     */
    public function assertZeroStepSoD(string $process, Model $subject, User $actor, string $initiatorAttr, ?string $ownerAttr = null, string $permission = 'approve it-finance'): void
    {
        $request = ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())->latest('id')->withCount('steps')->first();
        if ($request && $request->steps_count > 0) {
            return;
        }
        $this->assertDefaultApprover($subject, $actor, $initiatorAttr, $ownerAttr, $permission);
        if ($subject->getAttribute('created_by_id') && (int) $subject->getAttribute('created_by_id') === (int) $actor->id) {
            throw ValidationException::withMessages(['approval' => 'You created this, so you cannot approve it (segregation of duties).']);
        }
    }

    /** Unconfigured default: not the initiator, not the owner, and holding the process permission (default "approve it-finance"). */
    public function assertDefaultApprover(Model $subject, User $actor, string $initiatorAttr, ?string $ownerAttr = null, string $permission = 'approve it-finance'): void
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['approval' => $m]);
        (int) $actor->organization_id === (int) $subject->organization_id || $fail('This record belongs to another bank.');
        $actor->can($permission) || $fail("You need the \"{$permission}\" permission to approve.");
        $subject->{$initiatorAttr} && (int) $subject->{$initiatorAttr} === (int) $actor->id
            && $fail('You submitted this, so you cannot approve it (segregation of duties).');
        $ownerAttr && $subject->{$ownerAttr} && (int) $subject->{$ownerAttr} === (int) $actor->id
            && $fail($ownerAttr === 'owner_id'
                ? 'You own this budget, so you cannot be its sole approver (segregation of duties).'
                : 'You own this record, so you cannot approve it (segregation of duties).');
        method_exists($subject, 'sodExcludedIds') && in_array((int) $actor->id, $subject->sodExcludedIds(), true)
            && $fail('You took part in this record (owner, recommender, opener or requester), so you cannot approve it (segregation of duties).');
    }
}
