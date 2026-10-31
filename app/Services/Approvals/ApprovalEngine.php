<?php

namespace App\Services\Approvals;

use App\Models\Approvals\ApprovalDecision;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalStep;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\User;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\RequestMemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The generic approval engine behind Workflow Studio (ATH-VAL-016).
 *
 * A module's approve action calls approve() before doing its own final approval. When the bank has
 * configured stages for the process, the record passes through them in sequence: each step is
 * decided by people matching the stage (a role, a named person, a job title, the record owner, or
 * holders of a permission), `min_approvals` of them; and approve() reports "final" only when the
 * last step is approved, so the module completes its own transition then and only then. With no
 * stages configured, approve() is final at once and the module's existing rule (its permission and
 * its own segregation of duties) is the whole approval.
 *
 * Segregation of duties, on top of each module's: the initiator never approves their own request,
 * and nobody decides two steps of the same request.
 */
class ApprovalEngine
{
    /** The bank's active configuration for a process, if it has at least one active stage. */
    public function workflow(int $orgId, string $process): ?ApprovalWorkflow
    {
        $wf = RequestMemo::remember("workflow:{$orgId}:{$process}", fn () => ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $orgId)->where('process', $process)->where('is_active', true)
            ->with(['stages' => fn ($q) => $q->where('is_active', true)])->first());

        return $wf && $wf->stages->isNotEmpty() ? $wf : null;
    }

    public function canInitiate(string $process, User $user): bool
    {
        $roles = ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $user->organization_id)->where('process', $process)
            ->where('is_active', true)->value('initiator_roles');
        $roles = is_string($roles) ? json_decode($roles, true) : $roles;

        return empty($roles) || $user->hasRole('Super Admin') || $user->hasAnyRole($roles);
    }

    public function assertCanInitiate(string $process, User $user): void
    {
        $this->canInitiate($process, $user) || throw ValidationException::withMessages(['approval' => 'Workflow Studio limits who may start "'
            .ApprovalProcesses::get($process)['label'].'" to: '.implode(', ', (array) ApprovalWorkflow::withoutGlobalScopes()
                ->where('organization_id', $user->organization_id)->where('process', $process)->value('initiator_roles') ?: []).'.']);
    }

    /** Open (pending) request for a record. */
    public function current(string $process, Model $subject): ?ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())->where('status', 'pending')->with('steps.decisions')->latest('id')->first();
    }

    /** Start (or restart) the approval of a record; null when the bank has no stages for the process. */
    public function start(string $process, Model $subject, ?User $initiator = null): ?ApprovalRequest
    {
        $orgId = (int) $subject->organization_id;
        $wf = $this->workflow($orgId, $process);
        if (! $wf) {
            return null;
        }
        $this->cancel($process, $subject, 'Restarted');
        $def = ApprovalProcesses::get($process);
        $initiatorId = $initiator?->id ?? (isset($def['initiator']) ? $subject->{$def['initiator']} : null);

        return DB::transaction(function () use ($process, $subject, $wf, $orgId, $initiatorId) {
            $request = ApprovalRequest::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'workflow_id' => $wf->id, 'process' => $process,
                'subject_type' => $subject::class, 'subject_id' => $subject->getKey(),
                'subject_label' => ApprovalProcesses::label($process, $subject), 'status' => 'pending', 'initiated_by' => $initiatorId,
            ]);
            $applicable = $wf->stages->filter(fn ($s) => ! $s->condition_field
                || in_array((string) $subject->{$s->condition_field}, (array) $s->condition_values, true))->values();
            foreach ($applicable as $i => $stage) {
                ApprovalStep::withoutGlobalScopes()->create([
                    'organization_id' => $orgId, 'request_id' => $request->id, 'stage_id' => $stage->id, 'sequence' => $stage->sequence,
                    'name' => $stage->name, 'approver_type' => $stage->approver_type,
                    'approver_ref' => $stage->approver_type === 'owner' ? (string) $subject->{ApprovalProcesses::get($process)['owner'] ?? 'owner_id'} : $stage->approver_ref,
                    'min_approvals' => $stage->min_approvals, 'status' => 'pending',
                    'due_at' => now()->addHours($stage->sla_hours * ($i + 1)),
                ]);
            }
            if ($applicable->isEmpty()) {
                $request->update(['status' => 'approved', 'completed_at' => now(), 'outcome_note' => 'No configured stage applies to this record.']);
            }

            return $request->load('steps.decisions');
        });
    }

    /**
     * Record an approval by $actor. Returns ['final' => bool, 'step' => ?string, 'next' => ?string]:
     * final means the module should now complete its own approval.
     */
    public function approve(string $process, Model $subject, User $actor, ?string $note = null): array
    {
        $request = $this->current($process, $subject) ?? $this->start($process, $subject);
        if (! $request || $request->status === 'approved') {
            return ['final' => true, 'step' => null, 'next' => null];
        }

        return DB::transaction(function () use ($request, $subject, $actor, $note) {
            // Serialise concurrent decisions on this request (P7 M3): re-read it under a row lock, then judge eligibility on the fresh state.
            $locked = ApprovalRequest::withoutGlobalScopes()->lockForUpdate()->find($request->id);
            ($locked && $locked->status === 'pending') || throw ValidationException::withMessages(['approval' => 'This approval has already been decided.']);
            $request = $locked->load('steps.decisions');
            $step = $request->currentStep();
            $this->assertEligible($step, $request, $subject, $actor);
            ApprovalDecision::withoutGlobalScopes()->create(['organization_id' => $request->organization_id, 'step_id' => $step->id,
                'user_id' => $actor->id, 'decision' => 'approved', 'comment' => $note, 'decided_at' => now()]);
            if ($step->decisions()->where('decision', 'approved')->count() < $step->min_approvals) {
                return ['final' => false, 'step' => $step->name, 'next' => $step->name.' (more approvals needed)'];
            }
            $step->update(['status' => 'approved', 'completed_at' => now()]);
            $next = $request->steps()->where('status', 'pending')->first();
            if ($next) {
                return ['final' => false, 'step' => $step->name, 'next' => $next->name];
            }
            $request->update(['status' => 'approved', 'completed_at' => now()]);

            return ['final' => true, 'step' => $step->name, 'next' => null];
        });
    }

    /**
     * For a module's approve action: records the approval and returns a message when more stages
     * remain (the module must stop there and leave the record awaiting approval), or null when this
     * was the final approval and the module should complete its own transition.
     */
    public function gate(string $process, Model $subject, User $actor, ?string $note = null): ?string
    {
        $o = $this->approve($process, $subject, $actor, $note);

        return $o['final'] ? null : "Approved at \"{$o['step']}\". Now awaiting \"{$o['next']}\".";
    }

    /** Record a rejection / return by $actor at the current step; the request closes. False when no request is open. */
    public function reject(string $process, Model $subject, User $actor, string $note): bool
    {
        $request = $this->current($process, $subject);
        if (! $request) {
            return false;
        }
        $step = $request->currentStep();
        $this->assertEligible($step, $request, $subject, $actor);
        DB::transaction(function () use ($request, $step, $actor, $note) {
            ApprovalDecision::withoutGlobalScopes()->create(['organization_id' => $request->organization_id, 'step_id' => $step->id,
                'user_id' => $actor->id, 'decision' => 'rejected', 'comment' => $note, 'decided_at' => now()]);
            $step->update(['status' => 'rejected', 'completed_at' => now()]);
            $request->update(['status' => 'rejected', 'completed_at' => now(), 'outcome_note' => $note]);
        });

        return true;
    }

    /** Withdraw an open request (the record went back to draft, or was changed materially). */
    public function cancel(string $process, Model $subject, string $reason): void
    {
        ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())->where('status', 'pending')
            ->update(['status' => 'withdrawn', 'completed_at' => now(), 'outcome_note' => $reason, 'updated_at' => now()]);
    }

    public function canDecide(ApprovalStep $step, ApprovalRequest $request, ?Model $subject, User $user): bool
    {
        // Pre-screen the conditions that need no query: the answer is the same (any failure means "no"), without 3 queries per row on lists.
        $def = ApprovalProcesses::PROCESSES[$request->process] ?? [];
        if ((int) $user->organization_id !== (int) $request->organization_id
            || ($request->initiated_by && (int) $request->initiated_by === (int) $user->id)
            || ($subject && ! empty($def['owner_cannot_approve']) && (int) $subject->{$def['owner']} === (int) $user->id && $step->approver_type !== 'owner')
            || in_array((int) $user->id, array_map('intval', (array) ($request->context['sod_excluded_ids'] ?? [])), true)
            || ! $this->matchesForRequest($step, $request, $user)) {
            return false;
        }
        try {
            $this->assertEligible($step, $request, $subject, $user);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function assertEligible(?ApprovalStep $step, ApprovalRequest $request, ?Model $subject, User $actor): void
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['approval' => $m]);
        $step || $fail('Nothing is awaiting approval.');
        (int) $actor->organization_id === (int) $request->organization_id || $fail('This approval belongs to another bank.');
        $request->initiated_by && (int) $request->initiated_by === (int) $actor->id
            && $fail('You started this approval, so you cannot approve it (segregation of duties).');
        $decidedOther = ApprovalDecision::withoutGlobalScopes()->whereIn('step_id', $request->steps()->where('id', '!=', $step->id)->pluck('id'))
            ->where('user_id', $actor->id)->exists();
        $decidedOther && $fail("You already decided an earlier stage of this approval; \"{$step->name}\" needs someone else.");
        $step->decisions()->where('user_id', $actor->id)->exists() && $fail("You have already approved \"{$step->name}\".");
        $def = ApprovalProcesses::PROCESSES[$request->process] ?? [];
        if ($subject && ! empty($def['owner_cannot_approve']) && (int) $subject->{$def['owner']} === (int) $actor->id && $step->approver_type !== 'owner') {
            $fail('You own this record, so you cannot approve it (segregation of duties).');
        }
        // Finance snapshots everyone who took part (owner, recommender, opener, submitter) when the request starts.
        in_array((int) $actor->id, array_map('intval', (array) ($request->context['sod_excluded_ids'] ?? [])), true)
            && $fail('You took part in this record (owner, recommender, opener or requester), so you cannot approve it (segregation of duties).');

        $this->matchesForRequest($step, $request, $actor) || $fail("This is awaiting \"{$step->name}\": ".$this->describe($step).'.');
    }

    /** Finance processes match job titles by exact segment (P7 L4); every other process keeps the engine's rule. */
    private function matchesForRequest(ApprovalStep $step, ApprovalRequest $request, User $user): bool
    {
        if ($step->approver_type === 'job_title' && str_starts_with((string) $request->process, 'finance.') && ! $user->hasRole('Super Admin')) {
            return FinanceApprovals::jobTitleMatches((string) $user->job_title, (string) $step->approver_ref);
        }

        return $this->matches($step, $user);
    }

    /** Does $user fit the step's approver definition? */
    public function matches(ApprovalStep $step, User $user): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        return match ($step->approver_type) {
            'role' => $user->hasRole((string) $step->approver_ref),
            'user', 'owner' => (int) $step->approver_ref === (int) $user->id,
            'job_title' => $step->approver_ref && str_contains(strtolower((string) $user->job_title), strtolower((string) $step->approver_ref)),
            'permission' => $user->can((string) $step->approver_ref),
            default => false,
        };
    }

    /** "anyone holding the role CISO", "Adaeze Kunle-Usman", … */
    public function describe(object $step): string
    {
        return match ($step->approver_type) {
            'role' => "anyone with the role {$step->approver_ref}",
            'user', 'owner' => ($step->approver_type === 'owner' ? 'the record owner, ' : '').(RequestMemo::remember("userById:{$step->approver_ref}", fn () => User::find((int) $step->approver_ref))?->name ?? 'an unassigned person'),
            'job_title' => "anyone whose job title includes \"{$step->approver_ref}\"",
            'permission' => "anyone holding \"{$step->approver_ref}\"",
            default => 'an approver',
        };
    }

    /** People in the bank who could decide the step now (for the inbox). */
    public function eligibleUsers(ApprovalStep $step, ApprovalRequest $request): Collection
    {
        $users = User::where('organization_id', $request->organization_id)->with('roles')->get();

        return $users->filter(fn ($u) => (int) $u->id !== (int) $request->initiated_by && $this->matches($step, $u))->values();
    }

    /**
     * What a record page shows: the configured stages (before anything starts) or the latest
     * request's steps with their decisions.
     */
    public function trail(string $process, Model $subject, ?User $viewer = null): array
    {
        $request = ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())->with(['steps.decisions.user:id,name', 'initiator:id,name'])->latest('id')->first();
        $wf = $this->workflow((int) $subject->organization_id, $process);

        return [
            'process' => $process,
            'label' => ApprovalProcesses::get($process)['label'],
            'configured' => (bool) $wf,
            'default' => ApprovalProcesses::get($process)['default'] ?? null,
            'stages' => $wf ? $wf->stages->map(fn ($s) => ['name' => $s->name, 'who' => $this->describe($s), 'min_approvals' => $s->min_approvals,
                'condition' => $s->condition_field ? $s->condition_field.' in '.implode(', ', (array) $s->condition_values) : null])->values() : [],
            'request' => $request ? [
                'id' => $request->id, 'status' => $request->status, 'initiator' => $request->initiator?->name, 'outcome_note' => $request->outcome_note,
                'steps' => $request->steps->map(fn ($s) => [
                    'name' => $s->name, 'status' => $s->status, 'who' => $this->describe($s), 'min_approvals' => $s->min_approvals, 'due_at' => $s->due_at,
                    'decisions' => $s->decisions->map(fn ($d) => ['user' => $d->user?->name, 'decision' => $d->decision, 'comment' => $d->comment, 'at' => $d->decided_at]),
                    'can_decide' => $viewer && $s->status === 'pending' && $request->status === 'pending' && $request->currentStep()?->id === $s->id
                        && $this->canDecide($s, $request, $subject, $viewer),
                ]),
            ] : null,
        ];
    }
}
