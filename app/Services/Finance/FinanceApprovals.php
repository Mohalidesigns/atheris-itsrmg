<?php

namespace App\Services\Finance;

use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
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
        $amount = (string) ($subject->base_amount ?? $subject->total_base_amount ?? '0');
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
        ];
    }

    /** Records the approval; a message means stages remain (the record stays in review), null means final. */
    public function approve(string $process, Model $subject, User $actor, ?string $note = null): ?string
    {
        $message = $this->engine->gate($process, $subject, $actor, $note);
        if ($message !== null) {
            event(new FinanceApprovalRequested($process, $subject, $this->engine->current($process, $subject), $actor));
        }

        return $message;
    }

    public function finalised(string $process, Model $subject, User $actor, ?string $note = null): void
    {
        event(new FinanceApprovalDecided($process, $subject, 'approved', $actor, $note));
    }

    /** $outcome is returned or rejected. */
    public function decline(string $process, Model $subject, User $actor, string $note, string $outcome): void
    {
        $this->engine->reject($process, $subject, $actor, $note)
            || $this->engine->cancel($process, $subject, $outcome === 'returned' ? 'Returned to the author' : 'Rejected');
        event(new FinanceApprovalDecided($process, $subject, $outcome, $actor, $note));
    }

    public function withdraw(string $process, Model $subject, User $actor): void
    {
        $this->engine->cancel($process, $subject, 'Withdrawn by '.$actor->name);
        event(new FinanceApprovalDecided($process, $subject, 'withdrawn', $actor, null));
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

        return $trail;
    }

    /**
     * A configured workflow whose stages all fail their conditions auto-approves with no steps (engine behaviour),
     * which would leave nobody to enforce segregation of duties. For such a request apply the default rule,
     * and also exclude whoever created the record.
     */
    public function assertZeroStepSoD(string $process, Model $subject, User $actor, string $initiatorAttr, ?string $ownerAttr = null): void
    {
        $request = ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())->latest('id')->withCount('steps')->first();
        if (! $request || $request->steps_count > 0) {
            return;
        }
        $this->assertDefaultApprover($subject, $actor, $initiatorAttr, $ownerAttr);
        if ($subject->getAttribute('created_by_id') && (int) $subject->getAttribute('created_by_id') === (int) $actor->id) {
            throw ValidationException::withMessages(['approval' => 'You created this, so you cannot approve it (segregation of duties).']);
        }
    }

    /** Unconfigured default: not the initiator, not the owner, and holding "approve it-finance". */
    public function assertDefaultApprover(Model $subject, User $actor, string $initiatorAttr, ?string $ownerAttr = null): void
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['approval' => $m]);
        (int) $actor->organization_id === (int) $subject->organization_id || $fail('This record belongs to another bank.');
        $actor->can('approve it-finance') || $fail('You need the "approve it-finance" permission to approve.');
        $subject->{$initiatorAttr} && (int) $subject->{$initiatorAttr} === (int) $actor->id
            && $fail('You submitted this, so you cannot approve it (segregation of duties).');
        $ownerAttr && $subject->{$ownerAttr} && (int) $subject->{$ownerAttr} === (int) $actor->id
            && $fail('You own this budget, so you cannot be its sole approver (segregation of duties).');
    }
}
