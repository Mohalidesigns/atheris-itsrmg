<?php

namespace App\Services\Change;

use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeCabBoard;
use App\Models\Change\ChangeCabMeetingItem;
use App\Models\Change\ChangeCabVote;
use App\Models\Change\ChangeRequest;
use App\Models\Change\ChangeStandardTemplate;
use App\Models\User;
use App\Notifications\Change\ChangeApprovalOverdueNotification;
use App\Notifications\Change\ChangeApprovalRequestedNotification;
use App\Notifications\Change\ChangeDecisionNotification;
use App\Notifications\Change\ChangeOutcomeNotification;
use App\Notifications\Change\ChangeScheduledNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The change lifecycle (ATH-CHG-001 §3).
 *
 *   draft → submitted → in_approval → approved → scheduled → implementing → implemented → review → closed
 *   side states: rejected (→ revise → draft), cancelled; outcomes: backed_out, failed (→ review → closed)
 *
 * submit validates, scores risk, routes approvals and notifies the first stage in one action.
 * Every transition writes a change_events row; every refusal is a ValidationException on
 * `action`, `note` or `decision` so the Inertia forms can show it inline.
 */
class ChangeWorkflow
{
    /** action => statuses it may be taken from (emergency-only exceptions are guarded in code). */
    public const TRANSITIONS = [
        'submit' => ['draft'],
        'decide' => ['in_approval'],
        'vote' => ['in_approval'],
        'revise' => ['rejected'],
        'schedule' => ['approved', 'scheduled'],
        'start' => ['scheduled', 'approved', 'in_approval'],
        'complete' => ['implementing'],
        'back_out' => ['implementing'],
        'fail' => ['implementing'],
        'review' => ['review'],
        'cancel' => ['draft', 'submitted', 'in_approval', 'approved', 'scheduled'],
        'reopen' => ['closed'],
    ];

    public const CLOSURE_FOR_OUTCOME = [
        'successful' => 'implemented', 'successful_with_issues' => 'implemented', 'failed' => 'failed', 'backed_out' => 'backed_out',
    ];

    public function __construct(
        private ChangeRiskScorer $scorer,
        private ChangeApprovalRouter $router,
        private ChangeFreezeChecker $freeze,
        private ChangeConflictDetector $conflicts,
    ) {}

    /* ================================ create ================================ */

    /** Raise a change as a draft: reference, risk preview, first timeline entries. */
    public function create(array $data, User $actor): ChangeRequest
    {
        $orgId = (int) ($data['organization_id'] ?? $actor->organization_id);
        $change = new ChangeRequest($data + [
            'organization_id' => $orgId,
            'change_code' => ChangeRequest::generateNextCode($orgId),
            'status' => 'draft',
            'requester_id' => $data['requester_id'] ?? $actor->id,
            'created_by_id' => $actor->id,
        ]);
        $this->scorer->apply($change, false);
        $change->save();

        $this->log($change, 'created', ucfirst($change->change_type)." change raised ({$change->risk_level} risk, ".str_replace('_', ' ', $change->category).').', $actor);
        if ($change->owner) {
            $this->log($change, 'assigned', "Change owner set to {$change->owner->name}.", $actor);
        }
        if ($change->implementer) {
            $this->log($change, 'assigned', "Implementer set to {$change->implementer->name}.", $actor);
        }

        return $change;
    }

    /** Re-score after an edit; logs when the level moves. */
    public function rescore(ChangeRequest $change, ?User $actor): array
    {
        $before = $change->risk_level;
        $result = $this->scorer->apply($change);
        if ($before !== $result['level']) {
            $this->log($change, 'updated', "Risk level changed from {$before} to {$result['level']}.", $actor, ['factors' => $result['factors']]);
        }

        return $result;
    }

    /* ============================== transitions ============================== */

    /**
     * @throws ValidationException when the step is not allowed or required input is missing
     */
    public function transition(ChangeRequest $change, string $action, User $actor, array $input = []): string
    {
        $allowed = self::TRANSITIONS[$action] ?? null;
        if (! $allowed || ! in_array($change->status, $allowed, true)) {
            $this->fail('action', 'That step is not available from '.str_replace('_', ' ', $change->status).'.');
        }
        $note = trim((string) ($input['note'] ?? ''));

        return match ($action) {
            'submit' => $this->submit($change, $actor, $input),
            'decide' => $this->decide($change, $actor, (string) ($input['decision'] ?? ''), $note),
            'vote' => $this->vote($change, $actor, (string) ($input['vote'] ?? ''), (string) ($input['comment'] ?? $note)),
            'revise' => $this->revise($change, $actor, $note),
            'schedule' => $this->schedule($change, $actor, $input)['message'],
            'start' => $this->start($change, $actor, $input),
            'complete' => $this->complete($change, $actor, $note),
            'back_out' => $this->outcome($change, $actor, 'backed_out', $note),
            'fail' => $this->outcome($change, $actor, 'failed', $note),
            'review' => $this->review($change, $actor, $input),
            'cancel' => $this->cancel($change, $actor, $note),
            default => $this->fail('action', 'A closed change is immutable. Raise a new change instead.'),
        };
    }

    /* -------------------------------- submit -------------------------------- */

    public function submit(ChangeRequest $change, User $actor, array $input = []): string
    {
        if ($change->status !== 'draft') {
            $this->fail('action', 'Only a draft can be submitted.');
        }
        if (! empty($input['freeze_override_reason'])) {
            $change->freeze_override_reason = trim((string) $input['freeze_override_reason']);
        }

        $template = null;
        if ($change->change_type === 'standard') {
            $template = $this->eligibleTemplate($change);
            foreach (['implementation_plan', 'backout_plan', 'description'] as $field) {
                if (blank($change->{$field})) {
                    $change->{$field} = $template->{$field};
                }
            }
            if ($change->expected_downtime_minutes === null) {
                $change->expected_downtime_minutes = $template->expected_downtime_minutes;
            }
        } else {
            $missing = collect(['description', 'impact_assessment', 'implementation_plan', 'backout_plan'])
                ->filter(fn ($f) => blank($change->{$f}))->map(fn ($f) => str_replace('_', ' ', $f));
            if ($missing->isNotEmpty()) {
                $this->fail('action', 'Before submitting, record the '.$missing->implode(', ').'.');
            }
            if ($change->impact < 1 || $change->likelihood < 1) {
                $this->fail('action', 'Rate the impact and likelihood before submitting.');
            }
        }
        if ($change->change_type === 'emergency'
            && ! in_array($change->source_type, ['incident', 'vulnerability', 'regulatory'], true)
            && mb_strlen(trim((string) $change->justification)) < 20) {
            $this->fail('action', 'An emergency change must come from an incident, vulnerability or regulatory demand, or carry a justification of at least 20 characters.');
        }

        $risk = $this->scorer->apply($change, false);
        $change->pir_required = $change->change_type === 'emergency' || in_array($risk['level'], ['high', 'critical'], true);

        $this->enforceFreeze($change, $actor);

        $change->submitted_at = now();
        $change->status = 'submitted';
        $change->save();
        $this->log($change, 'submitted', 'Submitted for approval ('.ucfirst($change->change_type).', risk '.$risk['score'].' / '.$risk['level'].').', $actor, ['factors' => $risk['factors']]);

        if ($template) {
            $template->increment('usage_count');
        }

        $approvals = $this->router->route($change);
        if ($approvals->isEmpty()) {
            $this->log($change, 'routed', 'No approval stage applies: pre-approved by standard change template '.($template?->name ?? '').'.', $actor);
            $this->approve($change, $actor, null);

            return "{$change->change_code} submitted and approved (standard change).";
        }

        $change->forceFill(['status' => 'in_approval'])->save();
        $this->log($change, 'routed', 'Routed through '.$approvals->count().' approval stage(s): '.$approvals->pluck('stage_name')->implode(' → ').'.', $actor, [
            'stages' => $approvals->pluck('stage_key')->all(),
        ]);
        $this->notifyStage($change, $approvals->first(), $actor);

        return "{$change->change_code} submitted; awaiting {$approvals->first()->stage_name}.";
    }

    private function eligibleTemplate(ChangeRequest $change): ChangeStandardTemplate
    {
        $template = $change->standard_template_id
            ? ChangeStandardTemplate::withoutGlobalScopes()->where('organization_id', $change->organization_id)->find($change->standard_template_id)
            : null;
        if (! $template) {
            $this->fail('action', 'A standard change must be raised from a standard change template.');
        }
        if (! $template->isEligible()) {
            $this->fail('action', match ($template->state()) {
                'awaiting_approval' => "Template \"{$template->name}\" has not been approved yet.",
                'review_overdue' => "Template \"{$template->name}\" is past its review date and must be re-approved.",
                default => "Template \"{$template->name}\" is not active.",
            });
        }

        return $template;
    }

    /** Freeze-window rule shared by submit and schedule. */
    private function enforceFreeze(ChangeRequest $change, User $actor): void
    {
        $window = $this->freeze->blocking($change, $change->organization_id);
        if (! $window) {
            return;
        }
        if ($this->freeze->passes($change, $window)) {
            return;
        }
        if (blank($change->freeze_override_reason)) {
            $this->fail('scheduled_start_at', "The window falls inside the freeze \"{$window->name}\" (".$window->starts_at->format('j M').' – '.$window->ends_at->format('j M Y').'). Give a freeze override reason or pick another window.');
        }
        $this->log($change, 'freeze_override', "Scheduled inside freeze \"{$window->name}\": {$change->freeze_override_reason}", $actor, ['freeze_window_id' => $window->id]);
    }

    /* -------------------------------- decide -------------------------------- */

    public function decide(ChangeRequest $change, User $actor, string $decision, string $note): string
    {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            $this->fail('decision', 'Choose approve or reject.');
        }
        $approval = $this->router->currentApproval($change);
        if (! $approval) {
            $this->fail('decision', 'There is no approval waiting on this change.');
        }
        if (! $this->router->canDecide($approval, $actor)) {
            $this->fail('decision', $this->whyNot($approval, $change, $actor));
        }

        return $this->applyDecision($change, $approval, $actor, $decision, $note, $approval->approver_type === 'board' ? 'cab_override' : 'approval');
    }

    /** Records a decision on a current approval; callers have already established eligibility. */
    public function applyDecision(ChangeRequest $change, ChangeApproval $approval, ?User $actor, string $decision, string $note, string $eventType = 'approval'): string
    {
        if ($decision === 'reject') {
            if ($note === '') {
                $this->fail('note', 'Say why the change is rejected.');
            }
            $approval->update(['status' => 'rejected', 'decided_by_id' => $actor?->id, 'decided_at' => now(), 'decision_note' => $note]);
            ChangeApproval::withoutGlobalScopes()->where('change_request_id', $change->id)->where('status', 'pending')->update(['status' => 'skipped']);
            $change->forceFill(['status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => $note])->save();
            $this->log($change, $eventType, "{$approval->stage_name}: rejected. {$note}", $actor, ['approval_id' => $approval->id, 'decision' => 'rejected']);
            $this->log($change, 'status', 'Rejected. Later approval stages skipped.', $actor);
            $this->notifyPeople($change, [$change->requester_id, $change->change_owner_id, $change->implementer_id], new ChangeDecisionNotification($change, 'rejected', $approval->stage_name, $note), $actor);

            return "{$change->change_code} rejected at {$approval->stage_name}.";
        }

        $approval->update(['status' => 'approved', 'decided_by_id' => $actor?->id, 'decided_at' => now(), 'decision_note' => $note ?: null]);
        $this->log($change, $eventType, "{$approval->stage_name}: approved".($note !== '' ? ". {$note}" : '.'), $actor, ['approval_id' => $approval->id, 'decision' => 'approved']);

        $next = $this->router->currentApproval($change);
        if ($next) {
            $this->notifyStage($change, $next, $actor);
            $this->notifyPeople($change, [$change->requester_id], new ChangeDecisionNotification($change, 'stage_approved', $approval->stage_name, $note ?: null), $actor);

            return "{$approval->stage_name} approved; now awaiting {$next->stage_name}.";
        }

        $this->approve($change, $actor, $approval->stage_key === 'change_manager' || $approval->approver_type === 'role' ? $actor : null);

        return "{$change->change_code} approved.";
    }

    /** All stages cleared: the change is approved and may be scheduled (or was already started, for a retrospective emergency). */
    private function approve(ChangeRequest $change, ?User $actor, ?User $authority): void
    {
        $attrs = ['approved_at' => now(), 'approved_by_id' => $authority?->id ?? $actor?->id];
        if (in_array($change->status, ['submitted', 'in_approval'], true)) {
            $attrs['status'] = $change->scheduled_start_at ? 'scheduled' : 'approved';
        }
        $change->forceFill($attrs)->save();
        $this->log($change, 'status', $change->started_before_approval
            ? 'Retrospective authorisation complete.'
            : 'Approved.'.($change->status === 'scheduled' ? ' Window already set: scheduled.' : ' Ready to schedule.'), $actor);
        $this->notifyPeople($change, [$change->requester_id, $change->change_owner_id, $change->implementer_id], new ChangeDecisionNotification($change, 'approved', 'final'), $actor);
    }

    private function whyNot(ChangeApproval $approval, ChangeRequest $change, User $actor): string
    {
        if ($actor->id === $change->requester_id) {
            return 'The requester cannot approve their own change.';
        }
        if ($actor->id === $change->implementer_id) {
            return 'The implementer cannot approve the change they will implement.';
        }
        if ($approval->stage_key === 'change_manager' && ! $actor->can('approve changes')) {
            return 'Change Manager authorisation needs the approve changes permission.';
        }

        return "You are not an eligible approver for {$approval->stage_name}.";
    }

    /* --------------------------------- vote --------------------------------- */

    public function vote(ChangeRequest $change, User $actor, string $vote, string $comment): string
    {
        if (! in_array($vote, ChangeCabVote::VOTES, true)) {
            $this->fail('vote', 'Vote approve, reject, abstain or defer.');
        }
        $approval = $this->router->currentApproval($change);
        if (! $approval || $approval->approver_type !== 'board') {
            $this->fail('vote', 'The current stage is not a board stage.');
        }
        if (! $this->router->canVote($approval, $actor)) {
            $this->fail('vote', 'Only voting members of the board can vote on this change.');
        }
        if ($vote === 'reject' && trim($comment) === '') {
            $this->fail('comment', 'Say why you are voting to reject.');
        }

        ChangeCabVote::withoutGlobalScopes()->updateOrCreate(
            ['approval_id' => $approval->id, 'user_id' => $actor->id],
            ['organization_id' => $change->organization_id, 'change_request_id' => $change->id, 'vote' => $vote, 'comment' => $comment ?: null, 'voted_at' => now()],
        );
        $tally = $this->router->evaluateBoard($approval);
        $this->log($change, 'cab_vote', "{$actor->name} voted {$vote}".($comment ? ": {$comment}" : '.')." ({$tally['approve']} approve / {$tally['reject']} reject, quorum {$tally['quorum']}).", $actor, ['vote' => $vote, 'tally' => $tally]);

        if ($tally['outcome'] === 'approved') {
            $board = ChangeCabBoard::withoutGlobalScopes()->find($approval->board_id);

            return $this->applyDecision($change, $approval, null, 'approve', "Quorum reached on {$board?->name}: {$tally['approve']} approve, {$tally['reject']} reject.");
        }
        if ($tally['outcome'] === 'rejected') {
            return $this->applyDecision($change, $approval, null, 'reject', "Rejected by board vote: {$tally['reject']} reject votes.");
        }

        return 'Vote recorded ('.$tally['approve'].' of '.$tally['quorum'].' approvals needed).';
    }

    /** A CAB meeting item marked approved / rejected decides the change's current board approval. */
    public function applyMeetingDecision(ChangeCabMeetingItem $item, User $actor): ?string
    {
        if (! in_array($item->decision, ['approved', 'rejected'], true)) {
            return null;
        }
        $meeting = $item->meeting()->withoutGlobalScopes()->first();
        $change = ChangeRequest::withoutGlobalScopes()->find($item->change_request_id);
        $approval = $change ? $this->router->currentApproval($change) : null;
        if (! $change || ! $approval || $approval->approver_type !== 'board' || (int) $approval->board_id !== (int) $meeting?->board_id) {
            return null;
        }
        if (! $this->router->isDecidable($change)) {
            return null;
        }
        $note = "Decided at {$meeting->title} (".$meeting->scheduled_at?->format('j M Y').')'.($item->notes ? ": {$item->notes}" : '.');

        return $this->applyDecision($change, $approval, $actor, $item->decision === 'approved' ? 'approve' : 'reject', $note, 'cab_override');
    }

    /* -------------------------------- revise -------------------------------- */

    private function revise(ChangeRequest $change, User $actor, string $note): string
    {
        if ($note === '') {
            $this->fail('note', 'Say what has been revised.');
        }
        $change->forceFill(['status' => 'draft', 'submitted_at' => null, 'cab_required' => false])->save();
        $this->log($change, 'status', "Returned to draft for revision: {$note}", $actor);

        return 'Back in draft. Submit again when the revision is complete.';
    }

    /* ------------------------------- schedule ------------------------------- */

    /** @return array{message:string, conflicts:array} */
    public function schedule(ChangeRequest $change, User $actor, array $input): array
    {
        if (! in_array($change->status, ['approved', 'scheduled'], true)) {
            $this->fail('action', 'Only an approved change can be scheduled.');
        }
        $start = ! empty($input['scheduled_start_at']) ? Carbon::parse($input['scheduled_start_at']) : null;
        $end = ! empty($input['scheduled_end_at']) ? Carbon::parse($input['scheduled_end_at']) : null;
        if (! $start) {
            $this->fail('scheduled_start_at', 'Set the start of the implementation window.');
        }
        if ($end && $end->lte($start)) {
            $this->fail('scheduled_end_at', 'The window must end after it starts.');
        }
        if (in_array($change->risk_level, ['high', 'critical'], true) && blank($change->test_plan)) {
            $this->fail('action', 'A high or critical change needs a test plan before it is scheduled.');
        }

        $rescheduled = (bool) $change->scheduled_start_at;
        $change->scheduled_start_at = $start;
        $change->scheduled_end_at = $end;
        if (array_key_exists('freeze_override_reason', $input) && filled($input['freeze_override_reason'])) {
            $change->freeze_override_reason = trim((string) $input['freeze_override_reason']);
        }
        $this->enforceFreeze($change, $actor);

        $change->status = 'scheduled';
        $change->save();
        $label = $start->format('j M Y H:i').($end ? ' – '.$end->format('j M Y H:i') : '');
        $this->log($change, 'scheduled', ($rescheduled ? 'Rescheduled to ' : 'Scheduled for ').$label.'.', $actor);

        $conflicts = $this->conflicts->conflicts($change);
        if ($conflicts) {
            $this->log($change, 'conflict', count($conflicts).' scheduling conflict(s): '.collect($conflicts)->pluck('change_code')->implode(', ').'.', $actor, ['conflicts' => $conflicts]);
        }

        $chair = $change->cab_required ? $this->cabChairId($change->organization_id) : null;
        $this->notifyPeople($change, [$change->implementer_id, $change->change_owner_id, $chair], new ChangeScheduledNotification($change, $rescheduled), $actor);

        return [
            'message' => ($rescheduled ? 'Rescheduled' : 'Scheduled').' for '.$label.($conflicts ? ' — '.count($conflicts).' conflict(s) flagged.' : '.'),
            'conflicts' => $conflicts,
        ];
    }

    /* --------------------------------- start --------------------------------- */

    private function start(ChangeRequest $change, User $actor, array $input): string
    {
        $emergency = $change->change_type === 'emergency';
        if ($change->status === 'in_approval') {
            // COBIT BAI06.02: an emergency fix may go in before the ECAB sits, with a reason, by an authority.
            $reason = trim((string) ($input['emergency_start_reason'] ?? $input['note'] ?? ''));
            if (! $emergency) {
                $this->fail('action', 'Only an emergency change may start before approval.');
            }
            if (! $actor->can('approve changes')) {
                $this->fail('action', 'Starting an emergency change before approval needs the approve changes permission.');
            }
            if ($reason === '') {
                $this->fail('note', 'Give the reason the fix cannot wait for the ECAB.');
            }
            $change->forceFill(['status' => 'implementing', 'actual_start_at' => now(), 'started_before_approval' => true, 'emergency_start_reason' => $reason])->save();
            $this->log($change, 'status', "Emergency implementation started before approval: {$reason} ECAB and Change Manager authorisation must follow within 24 h.", $actor, ['retrospective' => true]);

            return 'Emergency change started. Retrospective authorisation is now due within 24 hours.';
        }
        if ($change->status === 'approved' && ! $emergency) {
            $this->fail('action', 'Schedule the change before starting it.');
        }
        if (! ($actor->can('edit changes') || $actor->id === $change->implementer_id || $actor->id === $change->change_manager_id)) {
            $this->fail('action', 'Only the implementer, the change manager or an edit changes holder can start a change.');
        }
        if (! $emergency && $change->scheduled_start_at && abs(now()->diffInHours($change->scheduled_start_at, false)) > 24) {
            $this->fail('action', 'A change can only start within 24 hours of its scheduled window. Reschedule it first.');
        }
        $change->forceFill(['status' => 'implementing', 'actual_start_at' => now()])->save();
        $this->log($change, 'status', 'Implementation started.', $actor);

        return 'Implementation started.';
    }

    /* ------------------------------- outcomes ------------------------------- */

    private function complete(ChangeRequest $change, User $actor, string $note): string
    {
        $now = now();
        $change->forceFill(['status' => 'implemented', 'actual_end_at' => $now, 'implemented_at' => $now])->save();
        $this->log($change, 'status', 'Implemented'.($note ? ": {$note}" : '.'), $actor);

        if ($change->pir_required) {
            $change->forceFill(['status' => 'review', 'closure_code' => 'implemented'])->save();
            $this->log($change, 'status', 'Awaiting post-implementation review.', $actor);
            $this->notifyOutcome($change, 'implemented', $actor);

            return 'Implemented. A post-implementation review is required before closure.';
        }

        $change->forceFill(['status' => 'closed', 'closure_code' => 'implemented', 'closed_at' => $now])->save();
        $this->log($change, 'status', 'Closed (implemented, no PIR required).', $actor);
        $this->notifyOutcome($change, 'implemented', $actor);

        return 'Implemented and closed.';
    }

    private function outcome(ChangeRequest $change, User $actor, string $outcome, string $note): string
    {
        if ($note === '') {
            $this->fail('note', $outcome === 'backed_out' ? 'Say why the change was backed out.' : 'Say how the change failed.');
        }
        $now = now();
        $change->forceFill(['status' => $outcome, 'actual_end_at' => $now, 'pir_required' => true, 'closure_code' => $outcome])->save();
        $this->log($change, 'status', ($outcome === 'backed_out' ? 'Backed out: ' : 'Failed: ').$note, $actor);
        $change->forceFill(['status' => 'review'])->save();
        $this->log($change, 'status', 'Post-implementation review required.', $actor);
        $this->notifyOutcome($change, $outcome, $actor, $note);

        return ($outcome === 'backed_out' ? 'Backed out.' : 'Marked failed.').' A PIR is now required.';
    }

    /* ---------------------------------- PIR ---------------------------------- */

    private function review(ChangeRequest $change, User $actor, array $input): string
    {
        if (! $actor->can('approve changes')) {
            $this->fail('action', 'A post-implementation review needs the approve changes permission.');
        }
        if ($actor->id === $change->implementer_id) {
            $this->fail('action', 'The implementer cannot review their own change. An independent reviewer must do the PIR.');
        }
        $outcome = (string) ($input['pir_outcome'] ?? '');
        $summary = trim((string) ($input['pir_summary'] ?? $input['note'] ?? ''));
        if (! in_array($outcome, ChangeRequest::PIR_OUTCOMES, true)) {
            $this->fail('pir_outcome', 'Choose the review outcome.');
        }
        if ($summary === '') {
            $this->fail('pir_summary', 'Record the lessons learned.');
        }
        $now = now();
        $change->forceFill([
            'status' => 'closed', 'pir_outcome' => $outcome, 'pir_summary' => $summary, 'pir_by_id' => $actor->id,
            'reviewed_at' => $now, 'closed_at' => $now, 'closure_code' => self::CLOSURE_FOR_OUTCOME[$outcome],
        ])->save();
        $this->log($change, 'pir', 'PIR outcome: '.str_replace('_', ' ', $outcome).". {$summary}", $actor, ['outcome' => $outcome]);
        $this->log($change, 'status', 'Closed after post-implementation review.', $actor);
        $this->notifyOutcome($change, 'closed', $actor);

        return 'Review recorded; the change is closed.';
    }

    /* --------------------------------- cancel --------------------------------- */

    private function cancel(ChangeRequest $change, User $actor, string $note): string
    {
        if ($note === '') {
            $this->fail('note', 'Say why the change is cancelled.');
        }
        ChangeApproval::withoutGlobalScopes()->where('change_request_id', $change->id)->where('status', 'pending')->update(['status' => 'skipped']);
        $change->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'closure_code' => 'cancelled'])->save();
        $this->log($change, 'status', "Cancelled: {$note}", $actor);

        return 'Change cancelled.';
    }

    /* ================================= sweep ================================= */

    /** Hourly: remind the current approvers (and the change manager) of overdue decisions, once per 24 h. */
    public function sweepOverdueApprovals(): int
    {
        $count = 0;
        ChangeApproval::withoutGlobalScopes()->where('status', 'pending')->where('due_at', '<', now())
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subHours(24)))
            ->orderBy('id')->get()
            ->each(function (ChangeApproval $approval) use (&$count) {
                $change = ChangeRequest::withoutGlobalScopes()->find($approval->change_request_id);
                if (! $change || ! $this->router->isDecidable($change) || ! $this->router->isCurrent($approval, $change)) {
                    return;
                }
                $recipients = $this->router->eligibleUsers($approval)->pluck('id')->push($change->change_manager_id)->filter()->unique();
                $this->notifyPeople($change, $recipients->all(), new ChangeApprovalOverdueNotification($change, $approval), null);
                $approval->forceFill(['reminded_at' => now()])->save();
                $this->log($change, 'notified', "Overdue reminder sent for {$approval->stage_name}.", null, ['approval_id' => $approval->id, 'to' => $recipients->values()->all()]);
                $count++;
            });

        return $count;
    }

    /* ================================ helpers ================================ */

    private function notifyStage(ChangeRequest $change, ChangeApproval $approval, ?User $actor): void
    {
        $users = $this->router->eligibleUsers($approval);
        $this->notifyPeople($change, $users->pluck('id')->all(), new ChangeApprovalRequestedNotification($change, $approval), $actor, false);
        $this->log($change, 'notified', "{$approval->stage_name}: ".($users->isEmpty() ? 'no eligible approver found.' : $users->pluck('name')->implode(', ').' notified.'), $actor, [
            'approval_id' => $approval->id, 'to' => $users->pluck('id')->all(),
        ]);
    }

    private function notifyOutcome(ChangeRequest $change, string $outcome, ?User $actor, ?string $note = null): void
    {
        $chair = $change->cab_required ? $this->cabChairId($change->organization_id) : null;
        $this->notifyPeople($change, [$change->requester_id, $change->change_owner_id, $change->change_manager_id, $chair], new ChangeOutcomeNotification($change, $outcome, $note), $actor);
    }

    private function cabChairId(int $orgId): ?int
    {
        return ChangeCabBoard::withoutGlobalScopes()->where('organization_id', $orgId)->where('type', 'cab')->where('is_active', true)->value('chair_id');
    }

    /** Notify a de-duplicated set of in-bank users, skipping the actor (approvers are never skipped). */
    private function notifyPeople(ChangeRequest $change, array $userIds, Notification $notification, ?User $actor, bool $skipActor = true): void
    {
        $ids = collect($userIds)->filter()->unique()->reject(fn ($id) => $skipActor && $actor && (int) $id === $actor->id);
        if ($ids->isEmpty()) {
            return;
        }
        User::where('organization_id', $change->organization_id)->whereIn('id', $ids->all())->get()
            ->each(fn (User $u) => $u->notify($notification));
    }

    public function log(ChangeRequest $change, string $type, string $description, ?User $actor, array $payload = []): void
    {
        $change->events()->create([
            'event_type' => $type,
            'description' => $description,
            'actor_id' => $actor?->id,
            'payload' => $payload ?: null,
            'created_at' => now(),
        ]);
    }

    public function router(): ChangeApprovalRouter
    {
        return $this->router;
    }

    public function conflictsFor(ChangeRequest $change): array
    {
        return $this->conflicts->conflicts($change);
    }

    public function freezeFor(ChangeRequest $change)
    {
        return $this->freeze->blocking($change, $change->organization_id);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
