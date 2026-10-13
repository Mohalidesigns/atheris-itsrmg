<?php

namespace App\Services\Issues;

use App\Models\Issue;
use App\Models\User;
use App\Notifications\IssueEscalatedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The issue lifecycle and its SLA clock.
 *
 *   open → in_progress ⇄ blocked → remediated → verified → closed
 *
 * - The SLA comes from the bank's severity policy (vulnerability_sla_policies) and runs from when
 *   the issue was raised. Blocked time does not count: unblocking pushes the due time out.
 * - Remediating needs a root cause and a remediation plan. Verification must be done by someone
 *   other than the remediator, holding `approve issues`. A failed verification sends the issue
 *   back to in_progress; a closed issue can be reopened with a reason.
 * - Escalation is a flag: level 1 when the SLA is breached, level 2 when it is overdue by half its
 *   window again. Each level notifies the next person in the policy's escalation chain.
 */
class IssueWorkflow
{
    public const DEFAULT_SLA_HOURS = ['critical' => 72, 'high' => 336, 'medium' => 720, 'low' => 2160];

    public const DEFAULT_CHAIN = ['owner', 'CISO', 'CRO'];

    /** Chain roles are matched against job titles; these are the titles each one stands for. */
    private const CHAIN_TITLES = [
        'CISO' => ['CISO', 'Chief Information Security Officer'],
        'CRO' => ['Chief Risk Officer', 'CRO'],
        'CCO' => ['Head of Compliance', 'Chief Compliance Officer'],
        'CIO' => ['Chief Information Officer', 'CIO', 'Head of IT Operations'],
    ];

    /** action => statuses it may be taken from. */
    public const TRANSITIONS = [
        'start' => ['open'],
        'block' => ['open', 'in_progress'],
        'unblock' => ['blocked'],
        'remediate' => ['in_progress'],
        'verify' => ['remediated'],
        'reject' => ['remediated'],
        'close' => ['verified'],
        'reopen' => ['closed'],
    ];

    public function slaHours(int $orgId, string $severity): int
    {
        $policySeverity = Issue::POLICY_SEVERITY[$severity] ?? $severity;

        return (int) (DB::table('vulnerability_sla_policies')->where('organization_id', $orgId)
            ->where('severity', $policySeverity)->value('hours_to_remediate')
            ?? self::DEFAULT_SLA_HOURS[$policySeverity] ?? 720);
    }

    public function escalationChain(int $orgId, string $severity): array
    {
        $rules = DB::table('vulnerability_sla_policies')->where('organization_id', $orgId)
            ->where('severity', Issue::POLICY_SEVERITY[$severity] ?? $severity)->value('escalation_rules');
        $chain = json_decode((string) $rules, true)['chain'] ?? null;

        return is_array($chain) && $chain ? array_values($chain) : self::DEFAULT_CHAIN;
    }

    /** Raise an issue: reference, SLA clock, first timeline entry. */
    public function open(array $data, ?User $actor): Issue
    {
        $orgId = (int) ($data['organization_id'] ?? $actor?->organization_id);
        $hours = $this->slaHours($orgId, $data['severity']);
        $raised = now();

        $issue = Issue::create($data + [
            'organization_id' => $orgId,
            'issue_code' => Issue::generateNextCode($orgId),
            'status' => 'open',
            'created_by_id' => $actor?->id,
            'sla_minutes' => $hours * 60,
            'sla_due_at' => $raised->copy()->addHours($hours),
        ]);
        if (empty($data['due_date'])) {
            $issue->update(['due_date' => $issue->sla_due_at->toDateString()]);
        }

        $this->log($issue, 'created', 'Issue raised ('.ucfirst($issue->severity).', '.$this->formatHours($hours).' SLA).', $actor);
        if ($issue->owner) {
            $this->log($issue, 'assigned', "Owner set to {$issue->owner->name}.", $actor);
        }

        return $issue;
    }

    /** A severity change re-bases the SLA on the new policy, still counted from when it was raised. */
    public function changeSeverity(Issue $issue, string $severity, ?User $actor): void
    {
        if ($issue->severity === $severity) {
            return;
        }
        $from = $issue->severity;
        $hours = $this->slaHours($issue->organization_id, $severity);
        $issue->update([
            'severity' => $severity,
            'sla_minutes' => $hours * 60,
            'sla_due_at' => $issue->created_at->copy()->addHours($hours)->addMinutes($issue->sla_paused_minutes),
        ]);
        $this->log($issue, 'severity_changed', "Severity changed from {$from} to {$severity}; SLA re-based to ".$this->formatHours($hours).'.', $actor);
    }

    /**
     * @throws ValidationException when the step is not allowed or required fields are missing
     */
    public function transition(Issue $issue, string $action, User $actor, array $input = []): string
    {
        $allowed = self::TRANSITIONS[$action] ?? null;
        if (! $allowed || ! in_array($issue->status, $allowed, true)) {
            $this->fail('action', 'That step is not available from '.str_replace('_', ' ', $issue->status).'.');
        }
        $note = trim((string) ($input['note'] ?? ''));

        switch ($action) {
            case 'start':
                $issue->update(['status' => 'in_progress']);
                $this->log($issue, 'status', 'Work started.', $actor);

                return 'Issue moved to in progress.';

            case 'block':
                if ($note === '') {
                    $this->fail('note', 'Say what the issue is blocked on.');
                }
                $issue->update(['status' => 'blocked', 'blocked_reason' => $note, 'sla_paused_at' => now()]);
                $this->log($issue, 'status', "Blocked: {$note} The SLA clock is paused.", $actor);

                return 'Issue blocked. The SLA clock is paused.';

            case 'unblock':
                $paused = $issue->sla_paused_at ? (int) $issue->sla_paused_at->diffInMinutes(now()) : 0;
                $issue->update([
                    'status' => 'in_progress',
                    'blocked_reason' => null,
                    'sla_paused_at' => null,
                    'sla_paused_minutes' => $issue->sla_paused_minutes + $paused,
                    'sla_due_at' => $issue->sla_due_at?->copy()->addMinutes($paused),
                ]);
                $this->log($issue, 'status', 'Unblocked'.($note ? ": {$note}" : '.').' SLA extended by '.$this->formatHours($paused / 60).' of blocked time.', $actor);

                return 'Issue unblocked; the SLA due time moved out by the blocked time.';

            case 'remediate':
                if (blank($issue->root_cause) || blank($issue->remediation_plan)) {
                    $this->fail('action', 'Record the root cause and the remediation plan before marking the issue remediated.');
                }
                $issue->update(['status' => 'remediated', 'remediated_at' => now(), 'remediated_by_id' => $actor->id]);
                $this->log($issue, 'status', 'Marked remediated'.($note ? ": {$note}" : '.').' Awaiting independent verification.', $actor);

                return 'Marked remediated. Someone else must now verify the fix.';

            case 'verify':
                if (! $actor->can('approve issues')) {
                    $this->fail('action', 'Verifying remediation needs the approve issues permission.');
                }
                if ($issue->remediated_by_id === $actor->id) {
                    $this->fail('action', 'The person who remediated an issue cannot verify it.');
                }
                $issue->update(['status' => 'verified', 'verified_at' => now(), 'verified_by_id' => $actor->id]);
                $this->log($issue, 'status', 'Remediation verified'.($note ? ": {$note}" : '.'), $actor);

                return 'Remediation verified.';

            case 'reject':
                if ($note === '') {
                    $this->fail('note', 'Say why the fix did not pass verification.');
                }
                if (! $actor->can('approve issues')) {
                    $this->fail('action', 'Rejecting remediation needs the approve issues permission.');
                }
                $issue->update(['status' => 'in_progress', 'remediated_at' => null, 'remediated_by_id' => null]);
                $this->log($issue, 'status', "Verification failed: {$note}", $actor);

                return 'Sent back to in progress. The SLA clock is running again.';

            case 'close':
                $issue->update(['status' => 'closed', 'closed_at' => now()]);
                $this->log($issue, 'status', 'Closed'.($note ? ": {$note}" : '.'), $actor);

                return 'Issue closed.';

            default: // reopen
                if ($note === '') {
                    $this->fail('note', 'Say why the issue is being reopened.');
                }
                $issue->update([
                    'status' => 'in_progress', 'closed_at' => null, 'verified_at' => null, 'verified_by_id' => null,
                    'remediated_at' => null, 'remediated_by_id' => null,
                ]);
                $this->log($issue, 'status', "Reopened: {$note}", $actor);

                return 'Issue reopened.';
        }
    }

    /** Manual escalation to the next level of the chain. */
    public function escalate(Issue $issue, ?User $actor, string $reason): bool
    {
        $chain = $this->escalationChain($issue->organization_id, $issue->severity);
        if (! $issue->isOpen() || $issue->escalation_level >= count($chain) - 1) {
            return false;
        }
        $level = $issue->escalation_level + 1;
        $issue->update(['escalation_level' => $level, 'escalated_at' => now()]);
        $recipients = $this->recipients($issue, $chain[$level]);
        $this->log($issue, 'escalated', "Escalated to level {$level} ({$chain[$level]}): {$reason}", $actor, [
            'level' => $level, 'to' => $chain[$level], 'notified' => $recipients->pluck('name')->all(),
        ]);
        foreach ($recipients as $user) {
            $user->notify(new IssueEscalatedNotification($issue, $level, $chain[$level], $reason));
        }

        return true;
    }

    /**
     * Scheduled sweep across every bank: escalate issues whose SLA is breached (level 1) or
     * overdue by half the SLA window again (level 2). Blocked issues are paused, so are skipped.
     */
    public function escalateOverdue(): int
    {
        $count = 0;
        Issue::withoutGlobalScopes()->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())
            ->orderBy('id')
            ->each(function (Issue $issue) use (&$count) {
                $overdueMinutes = $issue->sla_due_at->diffInMinutes(now());
                $target = $overdueMinutes >= max(1, $issue->sla_minutes) * 0.5 ? 2 : 1;
                while ($issue->escalation_level < $target) {
                    $reason = $issue->escalation_level === 0
                        ? 'SLA breached (due '.$issue->sla_due_at->copy()->timezone('Africa/Lagos')->format('j M Y H:i').').'
                        : 'Still unremediated at 150% of the SLA window.';
                    if (! $this->escalate($issue, null, $reason)) {
                        break;
                    }
                    $count++;
                }
            });

        return $count;
    }

    /** Users a chain step points at, within the issue's bank. */
    public function recipients(Issue $issue, string $step): Collection
    {
        if ($step === 'owner') {
            return collect([$issue->owner])->filter();
        }
        $titles = self::CHAIN_TITLES[strtoupper($step)] ?? [$step];

        return User::where('organization_id', $issue->organization_id)
            ->where(function ($q) use ($titles) {
                foreach ($titles as $title) {
                    $q->orWhereLike('job_title', "%{$title}%");
                }
            })->get();
    }

    public function log(Issue $issue, string $type, string $description, ?User $actor, array $payload = []): void
    {
        $issue->events()->create([
            'event_type' => $type,
            'description' => $description,
            'actor_id' => $actor?->id,
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);
    }

    public function formatHours(float $hours): string
    {
        if ($hours < 1) {
            return round($hours * 60).' min';
        }

        return $hours >= 48 && fmod($hours, 24) == 0.0 ? ($hours / 24).' days' : round($hours, 1).' h';
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
