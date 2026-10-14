<?php

namespace App\Services\Policies;

use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Policy lifecycle and attestation campaigns (ATH-VAL-009).
 *
 * draft → in_review (submit) → approved (approve) → active (publish) → retired (retire);
 * in_review → draft (return, with a reason). The person who submitted a policy, and its owner,
 * cannot approve it. Publishing an approved policy puts it in force and, for a mandatory policy,
 * asks every active member of staff to attest to the published version.
 */
class PolicyWorkflow
{
    public const TRANSITIONS = [
        'submit' => ['from' => ['draft'], 'to' => 'in_review'],
        'return' => ['from' => ['in_review'], 'to' => 'draft'],
        'approve' => ['from' => ['in_review'], 'to' => 'approved'],
        'publish' => ['from' => ['approved'], 'to' => 'active'],
        'retire' => ['from' => ['approved', 'active'], 'to' => 'retired'],
    ];

    public const ATTESTATION_DAYS = 30;

    /** Set by apply('approve') when Workflow Studio stages remain: the message to show instead. */
    public ?string $pending = null;

    public function __construct(private ApprovalEngine $approvals) {}

    public function apply(Policy $policy, string $action, User $user, ?string $note = null): Policy
    {
        $rule = self::TRANSITIONS[$action] ?? null;
        abort_unless($rule, 422, 'Unknown action.');
        if (! in_array($policy->status, $rule['from'], true)) {
            throw ValidationException::withMessages(['action' => "A {$policy->status} policy cannot be {$this->past($action)}."]);
        }

        if (in_array($action, ['return', 'retire'], true) && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Give a reason.']);
        }

        $updates = ['status' => $rule['to'], 'status_note' => $note];
        $this->pending = null;
        switch ($action) {
            case 'submit':
                $this->checkReadyForReview($policy);
                $this->approvals->assertCanInitiate('policy.approval', $user);
                $updates += ['submitted_by' => $user->id, 'submitted_at' => now()];
                break;
            case 'approve':
                $this->checkApprover($policy, $user);
                // Workflow Studio stages (ATH-VAL-016): the policy stays in review until the last one.
                if ($this->pending = $this->approvals->gate('policy.approval', $policy, $user, $note)) {
                    return $policy;
                }
                $updates += ['approved_by' => $user->id, 'approved_at' => now()];
                break;
            case 'return':
                $this->approvals->reject('policy.approval', $policy, $user, $note) || $this->approvals->cancel('policy.approval', $policy, 'Returned to the author');
                break;
            case 'publish':
                $updates += [
                    'published_at' => now(),
                    'effective_date' => $policy->effective_date && $policy->effective_date->gte(today()) ? $policy->effective_date : today(),
                    'review_date' => $policy->review_date && $policy->review_date->gt(today()) ? $policy->review_date : today()->addYear(),
                ];
                break;
            case 'retire':
                $updates['retired_at'] = now();
                break;
        }

        DB::transaction(function () use ($policy, $updates, $action, $user) {
            $policy->update($updates);
            if ($action === 'submit') {
                $this->approvals->start('policy.approval', $policy, $user);
            }
            if ($action === 'publish' && $policy->is_mandatory) {
                $this->launchCampaign($policy);
            }
            if ($action === 'retire') {
                // Nobody needs to attest to a policy that is no longer in force.
                PolicyAttestation::withoutGlobalScopes()->where('policy_id', $policy->id)->where('status', 'pending')
                    ->update(['status' => 'waived', 'notes' => 'Policy retired.']);
            }
        });

        return $policy;
    }

    /** Ask every active member of staff who has not attested to this version. Returns how many were asked. */
    public function launchCampaign(Policy $policy): int
    {
        $already = PolicyAttestation::withoutGlobalScopes()->where('policy_id', $policy->id)
            ->where('version_number', $policy->version_number)->pluck('user_id')->flip();

        $created = 0;
        $users = User::where('organization_id', $policy->organization_id)->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))->pluck('id');
        foreach ($users as $userId) {
            if (isset($already[$userId])) {
                continue;
            }
            // An earlier version's open request is superseded by this one.
            PolicyAttestation::withoutGlobalScopes()->where('policy_id', $policy->id)->where('user_id', $userId)
                ->where('status', 'pending')->update(['status' => 'waived', 'notes' => "Superseded by version {$policy->version_number}."]);

            PolicyAttestation::withoutGlobalScopes()->create([
                'organization_id' => $policy->organization_id, 'policy_id' => $policy->id, 'version_number' => $policy->version_number,
                'user_id' => $userId, 'status' => 'pending', 'due_date' => today()->addDays(self::ATTESTATION_DAYS),
            ]);
            $created++;
        }

        return $created;
    }

    /** The signed-in user acknowledges the version of an active policy they were asked to attest to. */
    public function attest(Policy $policy, User $user, ?string $notes = null): PolicyAttestation
    {
        if ($policy->status !== 'active') {
            throw ValidationException::withMessages(['policy' => 'Only a policy in force can be attested to.']);
        }

        $attestation = PolicyAttestation::withoutGlobalScopes()->where('policy_id', $policy->id)->where('user_id', $user->id)
            ->where('status', 'pending')->latest('id')->first()
            ?? new PolicyAttestation(['organization_id' => $policy->organization_id, 'policy_id' => $policy->id, 'user_id' => $user->id]);

        $attestation->fill([
            'status' => 'completed', 'acknowledged_at' => now(), 'version_number' => $policy->version_number, 'notes' => $notes,
        ])->save();

        return $attestation;
    }

    /** The next minor version ("5.0" → "5.1"). */
    public function nextVersion(Policy $policy): string
    {
        [$major, $minor] = array_map('intval', array_pad(explode('.', (string) ($policy->version_number ?: '1.0')), 2, 0));

        return $major.'.'.($minor + 1);
    }

    private function checkReadyForReview(Policy $policy): void
    {
        $missing = array_keys(array_filter(['content' => blank($policy->content), 'owner' => ! $policy->owner_id]));
        if ($missing) {
            throw ValidationException::withMessages(['action' => 'Add the policy '.implode(' and ', $missing).' before submitting it for approval.']);
        }
    }

    private function checkApprover(Policy $policy, User $user): void
    {
        if ($user->id === (int) $policy->submitted_by || $user->id === (int) $policy->owner_id) {
            throw ValidationException::withMessages(['action' => 'The policy owner and the person who submitted it cannot approve it.']);
        }
    }

    private function past(string $action): string
    {
        return ['submit' => 'submitted', 'return' => 'returned', 'approve' => 'approved', 'publish' => 'published', 'retire' => 'retired'][$action];
    }
}
