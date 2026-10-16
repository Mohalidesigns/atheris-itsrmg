<?php

namespace App\Services\Finance;

use App\Models\Finance\Contract;
use App\Models\Finance\ContractDocument;
use App\Models\Finance\Termination;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Facades\DB;

/**
 * Early termination of a contract through Workflow Studio (plan H2, M4). A termination is requested here, routed through
 * `finance.contract_termination` and only applied to the contract on final approval. Terminating an auto-renewing
 * contract after its notice deadline needs a recorded override reason and a supporting contract document.
 */
class TerminationService
{
    use LogsFinanceEvents;

    public const PROCESS = 'finance.contract_termination';

    public const PERMISSION = 'approve it-finance-contracts';

    public function __construct(private readonly ContractService $contracts, private readonly FinanceApprovals $approvals) {}

    /** True when the contract auto-renews and its notice deadline has passed (plan M4). */
    public function needsOverride(Contract $c): bool
    {
        return $c->is_auto && $c->notice_deadline_on !== null && $c->notice_deadline_on->toDateString() < FinanceClock::today()->toDateString();
    }

    /** @param array{reason?:string,terminated_on?:?string,override_reason?:?string,document_id?:?int} $data */
    public function request(Contract $c, array $data, User $by): Termination
    {
        $reason = (string) ($data['reason'] ?? '');
        $on = $this->contracts->assertTerminationAllowed($c, $reason, $data['terminated_on'] ?? null, $by);
        Termination::withoutGlobalScopes()->where('contract_id', $c->id)->where('status', 'submitted')->exists()
            && $this->fail('This contract already has a termination awaiting approval.');

        $override = null;
        $documentId = null;
        if ($this->needsOverride($c)) {
            $override = trim((string) ($data['override_reason'] ?? ''));
            $override === '' && $this->fail('The notice deadline ('.$c->notice_deadline_on->toDateString().') has passed on an auto-renewing contract: give the reason for overriding it.', 'override_reason');
            $documentId = (int) ($data['document_id'] ?? 0);
            $documentId && ContractDocument::withoutGlobalScopes()->where('organization_id', $c->organization_id)->where('contract_id', $c->id)->whereKey($documentId)->exists()
                || $this->fail('Attach a document of this contract (for example the vendor\'s release or a legal opinion) to support the override.', 'document_id');
        }

        return DB::transaction(function () use ($c, $by, $reason, $on, $override, $documentId) {
            $t = Termination::withoutGlobalScopes()->create([
                'organization_id' => $c->organization_id, 'created_by_id' => $by->id, 'termination_code' => Termination::generateNextCode((int) $c->organization_id),
                'contract_id' => $c->id, 'reason' => trim($reason), 'requested_terminated_on' => $on, 'is_post_deadline' => $override !== null,
                'override_reason' => $override, 'override_document_id' => $documentId, 'status' => 'submitted', 'requested_by_id' => $by->id, 'requested_at' => now(),
            ]);
            $t->setRelation('contract', $c);
            $this->approvals->start(self::PROCESS, $t, $by);
            $this->contracts->event($c, 'termination_requested', array_filter([
                'termination' => $t->termination_code, 'terminated_on' => $on, 'reason' => trim($reason),
                'override' => $override !== null, 'override_reason' => $override, 'override_document_id' => $documentId,
            ], fn ($v) => $v !== null), $by);
            $this->audit($t, $by, 'requested', "Termination {$t->termination_code} requested for {$c->contract_code}", ['override' => $override !== null]);

            return $t->refresh();
        });
    }

    /** Returns a message while stages remain; the final approval terminates the contract. */
    public function approve(Termination $t, User $by, ?string $note = null): string
    {
        $this->assertSubmitted($t);
        $this->assertApprover($t, $by);

        return DB::transaction(function () use ($t, $by, $note) {
            $t = Termination::withoutGlobalScopes()->lockForUpdate()->findOrFail($t->id);
            $this->assertSubmitted($t);
            $this->approvals->assertZeroStepSoD(self::PROCESS, $t, $by, 'requested_by_id', 'contract_owner_id', self::PERMISSION);
            if (! $this->approvals->configured(self::PROCESS, (int) $t->organization_id)) {
                $this->approvals->assertDefaultApprover($t, $by, 'requested_by_id', 'contract_owner_id', self::PERMISSION);
            } elseif ($message = $this->approvals->approve(self::PROCESS, $t, $by, $note)) {
                $this->audit($t, $by, 'stage_approved', $message);

                return $message;
            }
            $c = $this->contract($t);
            $floor = $this->contracts->terminationFloor($c);
            $on = max($t->requested_terminated_on->toDateString(), $floor); // a date that slipped out of the back-date window while waiting is moved up to its edge
            $on > $c->ends_on->toDateString() && $this->fail('The termination date is after the contract end date.', 'terminated_on');
            $t->forceFill(['status' => 'approved', 'approved_by_id' => $by->id, 'approved_at' => now()])->save();
            $this->contracts->applyTermination($c, $t->reason, $on, $by, array_filter([
                'termination' => $t->termination_code, 'override' => $t->is_post_deadline ?: null, 'override_reason' => $t->override_reason,
                'override_document_id' => $t->override_document_id,
            ]));
            $this->contracts->event($c, 'termination_approved', ['termination' => $t->termination_code, 'terminated_on' => $on], $by);
            $this->approvals->finalised(self::PROCESS, $t, $by, $note);
            $this->audit($t, $by, 'approved', "Termination {$t->termination_code} approved and applied", ['terminated_on' => $on]);

            return "Termination {$t->termination_code} approved: {$c->contract_code} is terminated.";
        });
    }

    public function reject(Termination $t, User $by, string $note): Termination
    {
        $this->assertSubmitted($t);
        blank(trim($note)) && $this->fail('Give a reason.', 'note');
        $this->assertApprover($t, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $t->organization_id)) {
            $this->approvals->assertDefaultApprover($t, $by, 'requested_by_id', 'contract_owner_id', self::PERMISSION);
        }
        DB::transaction(function () use ($t, $by, $note) {
            $this->approvals->decline(self::PROCESS, $t, $by, $note, 'rejected');
            $t->forceFill(['status' => 'rejected', 'rejection_reason' => $note])->save();
            $this->contracts->event($this->contract($t), 'termination_rejected', ['termination' => $t->termination_code, 'note' => $note], $by);
            $this->audit($t, $by, 'rejected', "Termination {$t->termination_code} rejected", ['note' => $note]);
        });

        return $t->refresh();
    }

    public function withdraw(Termination $t, User $by): Termination
    {
        $this->assertSubmitted($t);
        $c = $this->contract($t);
        (int) $t->requested_by_id === (int) $by->id || (int) $c->owner_id === (int) $by->id
            || $this->fail('Only the requester or the contract owner can withdraw a termination request.');
        DB::transaction(fn () => $this->close($t, $by));

        return $t->refresh();
    }

    /** Withdraws pending terminations because the contract is closing another way (renewal executed or auto-rolled). */
    public function withdrawForClosure(Contract $c, ?User $by): void
    {
        foreach (Termination::withoutGlobalScopes()->where('contract_id', $c->id)->where('status', 'submitted')->get() as $t) {
            $this->close($t, $by);
        }
    }

    private function close(Termination $t, ?User $by): void
    {
        $t->forceFill(['status' => 'withdrawn'])->save();
        $by ? $this->approvals->withdraw(self::PROCESS, $t, $by) : $this->approvals->cancelQuietly(self::PROCESS, $t, 'Withdrawn: the contract was closed');
        $this->contracts->event($this->contract($t), 'termination_withdrawn', ['termination' => $t->termination_code], $by);
        $this->audit($t, $by, 'withdrawn', "Termination {$t->termination_code} withdrawn");
    }

    private function contract(Termination $t): Contract
    {
        return $t->relationLoaded('contract') && $t->contract ? $t->contract : tap(Contract::withoutGlobalScopes()->findOrFail($t->contract_id), fn ($c) => $t->setRelation('contract', $c));
    }

    private function assertSubmitted(Termination $t): void
    {
        $t->status === 'submitted' || $this->fail("A {$t->status} termination request cannot be decided.");
    }

    private function assertApprover(Termination $t, User $by): void
    {
        (int) $by->organization_id === (int) $t->organization_id || $this->fail('This termination belongs to another bank.', 'approval');
        $by->can(self::PERMISSION) || $this->fail('You need the "'.self::PERMISSION.'" permission to decide a termination.', 'approval');
    }
}
