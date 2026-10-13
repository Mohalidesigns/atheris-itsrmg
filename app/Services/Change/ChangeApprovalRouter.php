<?php

namespace App\Services\Change;

use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeApprovalStage;
use App\Models\Change\ChangeCabBoard;
use App\Models\Change\ChangeRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Risk-based approval routing and approver resolution (ATH-CHG-001 §2.2, §2.4, §4).
 *
 * - route(): picks the bank's active stages that apply to the change's type and risk level and
 *   snapshots them as sequential approvals. A standard change gets no stages — unless it is
 *   scheduled inside a freeze window, in which case the Change Manager stage is added.
 * - canDecide(): who may clear a given approval, with segregation of duties: the requester and
 *   the implementer never approve their own change, the owner only at business_approval, and the
 *   final change_manager stage always needs `approve changes`.
 * - evaluateBoard(): quorum rule for CAB / ECAB votes.
 */
class ChangeApprovalRouter
{
    public function __construct(private ChangeFreezeChecker $freeze) {}

    /* ------------------------------- routing -------------------------------- */

    /** Active stages of the bank, ordered; seeds the defaults when the bank has none. */
    public function stages(int $orgId): Collection
    {
        $stages = ChangeApprovalStage::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where('is_active', true)->orderBy('sequence')->orderBy('id')->get();
        if ($stages->isEmpty() && ! ChangeApprovalStage::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            ChangePolicySeeder::ensureDefaults($orgId);
            $stages = ChangeApprovalStage::withoutGlobalScopes()->where('organization_id', $orgId)
                ->where('is_active', true)->orderBy('sequence')->orderBy('id')->get();
        }

        return $stages;
    }

    /** The stages a change of this type and risk level would pass through. */
    public function stagesFor(int $orgId, string $type, string $riskLevel, bool $inFreeze = false): Collection
    {
        $stages = $this->stages($orgId);
        $applicable = $stages->filter(fn (ChangeApprovalStage $s) => in_array($type, (array) $s->applies_to_types, true)
            && ChangeRiskScorer::rank($riskLevel) >= ChangeRiskScorer::rank($s->min_risk_level))->values();

        // Inside a freeze a standard change loses its pre-approval: the authoriser must sign it.
        if ($inFreeze && $type !== 'emergency' && ! $applicable->contains(fn ($s) => $s->key === 'change_manager')) {
            $manager = $stages->first(fn ($s) => $s->key === 'change_manager');
            if ($manager) {
                $applicable->push($manager);
            }
        }

        return $applicable->sortBy([['sequence', 'asc'], ['id', 'asc']])->values();
    }

    /** Builds the approval snapshots for a change (replacing any still-pending ones). */
    public function route(ChangeRequest $change): Collection
    {
        $inFreeze = (bool) $this->freeze->blocking($change, $change->organization_id)
            && ! ($change->change_type === 'emergency');
        $stages = $this->stagesFor($change->organization_id, $change->change_type, $change->risk_level, $inFreeze);

        $change->approvals()->where('status', 'pending')->delete();
        $now = now();
        $approvals = collect();
        foreach ($stages as $stage) {
            $assignee = $this->assigneeFor($stage, $change);
            $approvals->push(ChangeApproval::withoutGlobalScopes()->create([
                'organization_id' => $change->organization_id,
                'change_request_id' => $change->id,
                'stage_id' => $stage->id,
                'stage_key' => $stage->key,
                'stage_name' => $stage->name,
                'sequence' => $stage->sequence,
                'approver_type' => $stage->approver_type,
                'approver_ref' => $stage->approver_type === 'owner' ? (string) $change->change_owner_id : $stage->approver_ref,
                'assignee_id' => $assignee,
                'board_id' => $stage->approver_type === 'board' ? (int) $stage->approver_ref : null,
                'status' => 'pending',
                'due_at' => $now->copy()->addHours((int) $stage->sla_hours),
            ]));
        }

        $change->forceFill(['cab_required' => $stages->contains(fn ($s) => $s->approver_type === 'board')])->save();
        $change->unsetRelation('approvals');

        return $approvals;
    }

    /** A concrete person when the stage resolves to exactly one; null when any holder may decide. */
    private function assigneeFor(ChangeApprovalStage $stage, ChangeRequest $change): ?int
    {
        return match ($stage->approver_type) {
            'owner' => $change->change_owner_id,
            'user' => (int) $stage->approver_ref ?: null,
            'role', 'job_title' => ($users = $this->holders($stage->approver_type, (string) $stage->approver_ref, $change->organization_id))->count() === 1 ? $users->first()->id : null,
            default => null,
        };
    }

    /* --------------------------- approver resolution --------------------------- */

    /** Users in the bank matching a role name or job-title fragment (with the admin fallback for titles). */
    public function holders(string $type, string $ref, int $orgId): Collection
    {
        $base = fn () => User::where('organization_id', $orgId);
        if ($type === 'role') {
            return $base()->whereHas('roles', fn ($q) => $q->whereIn('name', [$ref, 'Super Admin']))->get();
        }
        if ($type === 'job_title') {
            $users = $base()->whereLike('job_title', "%{$ref}%")->get();

            return $users->isNotEmpty() ? $users : $base()->whereHas('roles', fn ($q) => $q->where('name', 'Organization Admin'))->get();
        }

        return collect();
    }

    /** Everyone who may act on an approval (board → voting members); used for notifications. */
    public function eligibleUsers(ChangeApproval $approval): Collection
    {
        $change = $approval->change()->withoutGlobalScopes()->first();
        $orgId = $approval->organization_id;

        $users = match ($approval->approver_type) {
            'role', 'job_title' => $this->holders($approval->approver_type, (string) $approval->approver_ref, $orgId),
            'user' => User::where('organization_id', $orgId)->where('id', (int) $approval->approver_ref)->get(),
            'owner' => User::where('organization_id', $orgId)->where('id', (int) ($change?->change_owner_id ?? $approval->approver_ref))->get(),
            'board' => $this->boardFor($approval)?->members()->where('is_voting', true)->with('user')->get()->pluck('user')->filter()->values() ?? collect(),
            default => collect(),
        };

        return $users->filter(fn (User $u) => $change ? $this->passesSod($approval, $change, $u) : true)->unique('id')->values();
    }

    private function boardFor(ChangeApproval $approval): ?ChangeCabBoard
    {
        return $approval->board_id ? ChangeCabBoard::withoutGlobalScopes()->find($approval->board_id) : null;
    }

    /** Segregation of duties: requester and implementer never approve; the owner only at business_approval. */
    private function passesSod(ChangeApproval $approval, ChangeRequest $change, User $user): bool
    {
        if ($user->id === $change->requester_id || $user->id === $change->implementer_id) {
            return false;
        }
        if ($user->id === $change->change_owner_id && $approval->stage_key !== 'business_approval' && $approval->approver_type !== 'owner') {
            return false;
        }

        return true;
    }

    /** True when $user may approve or reject this approval right now. */
    public function canDecide(ChangeApproval $approval, User $user): bool
    {
        if ($approval->status !== 'pending' || $user->organization_id !== $approval->organization_id) {
            return false;
        }
        $change = $approval->relationLoaded('change') ? $approval->change : $approval->change()->withoutGlobalScopes()->first();
        if (! $change || ! $this->isDecidable($change) || ! $this->isCurrent($approval, $change)) {
            return false;
        }
        if (! $this->passesSod($approval, $change, $user)) {
            return false;
        }
        // The final authority always needs the permission, whoever holds the role.
        if ($approval->stage_key === 'change_manager' && ! $user->can('approve changes')) {
            return false;
        }

        return match ($approval->approver_type) {
            'role' => $user->hasRole('Super Admin') || $user->hasRole((string) $approval->approver_ref),
            'job_title' => $this->holders('job_title', (string) $approval->approver_ref, $approval->organization_id)->contains('id', $user->id),
            'user' => (int) $approval->approver_ref === $user->id,
            'owner' => $change->change_owner_id === $user->id,
            'board' => $user->can('approve changes') || $this->boardFor($approval)?->chair_id === $user->id,
            default => false,
        };
    }

    /** True when $user is a voting member of the approval's board and the stage is current. */
    public function canVote(ChangeApproval $approval, User $user): bool
    {
        if ($approval->approver_type !== 'board' || $approval->status !== 'pending' || $user->organization_id !== $approval->organization_id) {
            return false;
        }
        $change = $approval->relationLoaded('change') ? $approval->change : $approval->change()->withoutGlobalScopes()->first();
        if (! $change || ! $this->isDecidable($change) || ! $this->isCurrent($approval, $change)) {
            return false;
        }
        if (! $this->passesSod($approval, $change, $user)) {
            return false;
        }

        return (bool) $this->boardFor($approval)?->members()->where('user_id', $user->id)->where('is_voting', true)->exists();
    }

    /** In approval — or an emergency started before approval whose retrospective authorisation is still open. */
    public function isDecidable(ChangeRequest $change): bool
    {
        return $change->status === 'in_approval'
            || ($change->started_before_approval && ! $change->approved_at && $change->isOpen());
    }

    public function isCurrent(ChangeApproval $approval, ?ChangeRequest $change = null): bool
    {
        $current = $this->currentApproval($change ?? $approval->change()->withoutGlobalScopes()->first());

        return $current && $current->id === $approval->id;
    }

    /** The lowest-sequence pending approval — the only one that is actionable. */
    public function currentApproval(?ChangeRequest $change): ?ChangeApproval
    {
        if (! $change) {
            return null;
        }

        return ChangeApproval::withoutGlobalScopes()->where('change_request_id', $change->id)
            ->where('status', 'pending')->orderBy('sequence')->orderBy('id')->first();
    }

    /** The approvals $user can act on now (decide or vote): the My Approvals inbox and the dashboard count. */
    public function pendingFor(User $user): Collection
    {
        $pending = ChangeApproval::withoutGlobalScopes()->where('organization_id', $user->organization_id)
            ->where('status', 'pending')
            ->whereHas('change', fn ($q) => $q->withoutGlobalScopes()->whereNull('deleted_at')
                ->where(fn ($w) => $w->where('status', 'in_approval')->orWhere(fn ($r) => $r->where('started_before_approval', true)->whereNull('approved_at'))))
            ->with(['change' => fn ($q) => $q->withoutGlobalScopes()->with('requester:id,name')])
            ->orderBy('due_at')->get();

        // Only the current stage of each change is actionable.
        $current = $pending->groupBy('change_request_id')->map(fn ($g) => $g->sortBy([['sequence', 'asc'], ['id', 'asc']])->first());

        return $current->filter(fn (ChangeApproval $a) => $this->canDecide($a, $user) || $this->canVote($a, $user))->values();
    }

    /* ------------------------------- CAB voting ------------------------------- */

    /**
     * Quorum rule: approved when approve votes from voting members ≥ quorum and no reject votes;
     * rejected when reject votes ≥ quorum; otherwise still open (null).
     *
     * @return array{outcome: ?string, approve:int, reject:int, abstain:int, defer:int, quorum:int, voting:int}
     */
    public function evaluateBoard(ChangeApproval $approval): array
    {
        $board = $this->boardFor($approval);
        $voting = $board ? $board->votingMemberIds() : [];
        $quorum = $approval->stage_id
            ? (ChangeApprovalStage::withoutGlobalScopes()->find($approval->stage_id)?->quorum ?: ($board->quorum ?? 3))
            : ($board->quorum ?? 3);

        $votes = $approval->votes()->whereIn('user_id', $voting ?: [0])->get();
        $tally = ['approve' => 0, 'reject' => 0, 'abstain' => 0, 'defer' => 0];
        foreach ($votes as $v) {
            $tally[$v->vote] = ($tally[$v->vote] ?? 0) + 1;
        }

        $outcome = null;
        if ($tally['approve'] >= $quorum && $tally['reject'] === 0) {
            $outcome = 'approved';
        } elseif ($tally['reject'] >= $quorum) {
            $outcome = 'rejected';
        }

        return $tally + ['outcome' => $outcome, 'quorum' => (int) $quorum, 'voting' => count($voting)];
    }

    /** Human-readable routing preview: "Technical / peer review → … → Change Manager authorisation". */
    public function preview(int $orgId, string $type, string $riskLevel): array
    {
        return $this->stagesFor($orgId, $type, $riskLevel)->pluck('name')->all();
    }

    /** type × risk ⇒ ordered stage names, for the policy page and the create form. */
    public function matrix(int $orgId): array
    {
        $out = [];
        foreach (ChangeRequest::TYPES as $type) {
            foreach (ChangeRequest::RISK_LEVELS as $level) {
                $out[$type][$level] = $this->preview($orgId, $type, $level);
            }
        }

        return $out;
    }
}
