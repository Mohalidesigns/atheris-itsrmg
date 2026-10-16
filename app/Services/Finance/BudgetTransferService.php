<?php

namespace App\Services\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Facades\DB;

/**
 * Budget transfers / virements (FIN-02.10): move approved budget between lines, cost centres or
 * periods. Nothing changes until the transfer is approved and applied; apply is net zero by assertion.
 */
class BudgetTransferService
{
    use LogsFinanceEvents;

    public const PROCESS = 'finance.budget_transfer';

    public const TRANSITIONS = [
        'submit' => ['from' => ['draft'], 'to' => 'submitted'],
        'withdraw' => ['from' => ['draft', 'submitted'], 'to' => 'withdrawn'],
        'approve' => ['from' => ['submitted'], 'to' => 'approved'],
        'reject' => ['from' => ['submitted'], 'to' => 'rejected'],
        'apply' => ['from' => ['approved'], 'to' => 'applied'],
    ];

    public function __construct(
        private readonly BudgetLedger $ledger,
        private readonly BudgetPhasing $phasing,
        private readonly BudgetService $budgets,
        private readonly FinanceApprovals $approvals,
        private readonly FinanceSettings $settings,
    ) {}

    public function create(array $data, User $by): BudgetTransfer
    {
        $orgId = (int) $by->organization_id;
        $clean = $this->validated($orgId, $data, $by);

        $t = BudgetTransfer::withoutGlobalScopes()->create($clean + [
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'transfer_code' => BudgetTransfer::generateNextCode($orgId),
            'requested_by_id' => $by->id, 'status' => 'draft',
        ]);
        $this->audit($t, $by, 'created', "Transfer {$t->transfer_code} drafted");

        return $t->refresh();
    }

    public function update(BudgetTransfer $t, array $data, User $by): BudgetTransfer
    {
        $t->status === 'draft' || $this->fail("A {$t->status} transfer cannot be edited.");
        $t->fill($this->validated((int) $t->organization_id, $data, $by, $t))->save();

        return $t->refresh();
    }

    public function submit(BudgetTransfer $t, User $by): BudgetTransfer
    {
        $this->assertTransition($t, 'submit');
        $this->assertRequester($t, $by);
        $this->validated((int) $t->organization_id, $t->only(['fiscal_year_id', 'from_line_id', 'to_line_id', 'to_cost_centre_id', 'to_cost_pool_id', 'to_tower_id', 'to_category_id', 'to_description', 'from_period_id', 'to_period_id', 'amount', 'reason']), $by, $t);

        return DB::transaction(function () use ($t, $by) {
            $t->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'rejection_reason' => null])->save();
            $this->approvals->start(self::PROCESS, $t, $by);
            $this->audit($t, $by, 'submitted', "Transfer {$t->transfer_code} submitted", ['base_amount' => (string) $t->base_amount]);

            return $t->refresh();
        });
    }

    /** Returns the message to show: the next stage awaited, or the final approval. */
    public function approve(BudgetTransfer $t, User $by, ?string $note = null): string
    {
        $this->assertTransition($t, 'approve');
        $this->assertApprover($t, $by);

        return DB::transaction(function () use ($t, $by, $note) {
            $this->approvals->assertZeroStepSoD(self::PROCESS, $t, $by, 'requested_by_id');
            if (! $this->approvals->configured(self::PROCESS, (int) $t->organization_id)) {
                $this->approvals->assertDefaultApprover($t, $by, 'requested_by_id');
            } elseif ($message = $this->approvals->approve(self::PROCESS, $t, $by, $note)) {
                $this->audit($t, $by, 'stage_approved', $message);

                return $message;
            }
            $t->forceFill(['status' => 'approved', 'approved_by_id' => $by->id, 'approved_at' => now()])->save();
            $this->approvals->finalised(self::PROCESS, $t, $by, $note);
            $this->audit($t, $by, 'approved', "Transfer {$t->transfer_code} approved");

            return "Transfer {$t->transfer_code} approved. It takes effect when it is applied.";
        });
    }

    public function reject(BudgetTransfer $t, User $by, string $note): BudgetTransfer
    {
        $this->assertTransition($t, 'reject');
        blank($note) && $this->fail('Give a reason.', 'note');
        $this->assertApprover($t, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $t->organization_id)) {
            $this->approvals->assertDefaultApprover($t, $by, 'requested_by_id');
        }
        DB::transaction(function () use ($t, $by, $note) {
            $this->approvals->decline(self::PROCESS, $t, $by, $note, 'rejected');
            $t->forceFill(['status' => 'rejected', 'rejection_reason' => $note])->save();
            $this->audit($t, $by, 'rejected', "Transfer {$t->transfer_code} rejected", ['note' => $note]);
        });

        return $t->refresh();
    }

    public function withdraw(BudgetTransfer $t, User $by): BudgetTransfer
    {
        $this->assertTransition($t, 'withdraw');
        $this->assertRequester($t, $by);
        DB::transaction(function () use ($t, $by) {
            $wasSubmitted = $t->status === 'submitted';
            $t->forceFill(['status' => 'withdrawn'])->save();
            $wasSubmitted && $this->approvals->withdraw(self::PROCESS, $t, $by);
            $this->audit($t, $by, 'withdrawn', "Transfer {$t->transfer_code} withdrawn");
        });

        return $t->refresh();
    }

    public function apply(BudgetTransfer $t, User $by): BudgetTransfer
    {
        $this->assertTransition($t, 'apply');
        $by->can('approve it-finance') || $this->fail('You need the "approve it-finance" permission to apply a transfer.');

        return DB::transaction(function () use ($t, $by) {
            $t = BudgetTransfer::withoutGlobalScopes()->lockForUpdate()->findOrFail($t->id);
            $this->assertTransition($t, 'apply');
            $orgId = (int) $t->organization_id;
            // The budget may have been re-baselined since approval: follow the lineage to the current baseline line.
            $from = BudgetLine::withoutGlobalScopes()->lockForUpdate()->findOrFail($t->from_line_id);
            $from = $this->budgets->resolveBaselineLine($from) ?? $this->fail('The source line no longer belongs to a baseline budget; raise a new transfer.');
            $toLine = $t->to_line_id ? BudgetLine::withoutGlobalScopes()->findOrFail($t->to_line_id) : null;
            $toLine = $toLine ? ($this->budgets->resolveBaselineLine($toLine) ?? $this->fail('The target line no longer belongs to a baseline budget; raise a new transfer.')) : null;
            foreach (['from_period_id', 'to_period_id'] as $col) {
                $p = $t->{$col} ? Period::withoutGlobalScopes()->find($t->{$col}) : null;
                if ($t->{$col} && (! $p || $p->status !== 'open')) {
                    $this->fail('A period of this transfer is no longer open; raise a new transfer.');
                }
            }
            $t->forceFill(['from_line_id' => $from->id, 'to_line_id' => $toLine?->id]);
            $this->assertAvailable($from, $t, (string) $t->base_amount);

            $to = $toLine ?? $this->appendLine($t, $from, $by);
            [$fromShares, $toShares] = $this->shares($t, $from);

            foreach ($fromShares as $periodId => $amount) {
                $this->ledger->post($from, Period::withoutGlobalScopes()->findOrFail($periodId), bcsub('0', $amount, 2), 'transfer', $t->id, $by);
            }
            foreach ($toShares as $periodId => $amount) {
                $this->ledger->post($to, Period::withoutGlobalScopes()->findOrFail($periodId), $amount, 'transfer', $t->id, $by);
            }

            $net = (string) BudgetAdjustment::withoutGlobalScopes()->where('organization_id', $orgId)->where('source_type', 'transfer')->where('source_id', $t->id)->sum('base_amount');
            if (bccomp($net, '0', 2) !== 0) {
                $this->fail('The transfer does not net to zero; it was not applied.');
            }
            $t->forceFill(['status' => 'applied', 'applied_by_id' => $by->id, 'applied_at' => now(), 'to_line_id' => $to->id])->save();
            $this->audit($t, $by, 'applied', "Transfer {$t->transfer_code} applied", ['from_line_id' => $from->id, 'to_line_id' => $to->id, 'base_amount' => (string) $t->base_amount]);

            return $t->refresh();
        });
    }

    /** @return array{0: array<int,string>, 1: array<int,string>} period_id => positive base amount drawn / credited */
    private function shares(BudgetTransfer $t, BudgetLine $from): array
    {
        $amount = bcadd((string) $t->base_amount, '0', 2);
        $fromP = $t->from_period_id ?? $t->to_period_id;
        $toP = $t->to_period_id ?? $t->from_period_id;
        if ($fromP) {
            return [[$fromP => $amount], [$toP => $amount]];
        }
        $open = $this->ledger->openPeriods((int) $t->organization_id, (int) $t->fiscal_year_id);
        $open->isEmpty() && $this->fail('The fiscal year has no open period to post to.');
        $current = $this->ledger->currentByPeriod($from);
        $weights = $open->map(fn ($p) => max('0', $current[$p->id] ?? '0'))->values()->all();
        $spread = $this->phasing->spread($amount, $weights, 2);
        $out = $open->values()->mapWithKeys(fn ($p, $i) => [$p->id => $spread[$i]])->all();

        return [$out, $out];
    }

    private function appendLine(BudgetTransfer $t, BudgetLine $from, User $by): BudgetLine
    {
        $orgId = (int) $t->organization_id;
        $budget = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('baseline_slot', "{$t->fiscal_year_id}:{$t->to_cost_centre_id}")->first()
            ?? $this->fail('The target cost centre has no baseline budget for this fiscal year.');
        $pool = Category::withoutGlobalScopes()->findOrFail($t->to_cost_pool_id);
        BudgetLine::$allowLockedAppend = true;
        try {
            return $this->budgets->saveLine($budget, null, [
                'description' => $t->to_description, 'cost_pool_id' => $t->to_cost_pool_id, 'tower_id' => $t->to_tower_id, 'category_id' => $t->to_category_id,
                'is_capex' => (bool) $pool->default_capex, 'priority' => $from->priority, 'rgt' => $from->rgt, 'annual_amount' => '0',
                'currency' => $this->settings->baseCurrency($orgId), 'phasing_method' => 'equal', 'origin' => 'transfer',
                'justification' => "Created by transfer {$t->transfer_code}",
            ], $by);
        } finally {
            BudgetLine::$allowLockedAppend = false;
        }
    }

    /** @return array<string,mixed> persistable attributes */
    private function validated(int $orgId, array $d, User $by, ?BudgetTransfer $existing = null): array
    {
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) ($d['fiscal_year_id'] ?? 0));
        $fy || $this->fail('Pick a fiscal year of this bank.', 'fiscal_year_id');
        $from = $this->baselineLine($orgId, (int) ($d['from_line_id'] ?? 0), $fy, 'from_line_id');
        $this->assertVisible($by, (int) $from->cost_centre_id, 'from_line_id');

        $to = null;
        $new = ['to_cost_centre_id' => null, 'to_cost_pool_id' => null, 'to_tower_id' => null, 'to_category_id' => null, 'to_description' => null];
        if (! empty($d['to_line_id'])) {
            $to = $this->baselineLine($orgId, (int) $d['to_line_id'], $fy, 'to_line_id');
        } else {
            foreach (['to_cost_centre_id' => 'Pick the cost centre of the new line.', 'to_cost_pool_id' => 'Pick a cost pool for the new line.', 'to_tower_id' => 'Pick a tower for the new line.', 'to_description' => 'Describe the new line.'] as $k => $msg) {
                empty($d[$k]) && $this->fail($msg, $k);
            }
            CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($d['to_cost_centre_id'])->exists() || $this->fail('Unknown cost centre.', 'to_cost_centre_id');
            $pool = Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($d['to_cost_pool_id'])->where('kind', 'cost_pool')->first() ?? $this->fail('Pick a cost pool.', 'to_cost_pool_id');
            Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($d['to_tower_id'])->where('kind', 'tower')->exists() || $this->fail('Pick a tower.', 'to_tower_id');
            ! empty($d['to_category_id']) && ! Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($d['to_category_id'])->exists() && $this->fail('Unknown category.', 'to_category_id');
            Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('baseline_slot', "{$fy->id}:{$d['to_cost_centre_id']}")->exists()
                || $this->fail('The target cost centre has no baseline budget for this fiscal year.', 'to_cost_centre_id');
            $new = ['to_cost_centre_id' => (int) $d['to_cost_centre_id'], 'to_cost_pool_id' => (int) $d['to_cost_pool_id'], 'to_tower_id' => (int) $d['to_tower_id'],
                'to_category_id' => $d['to_category_id'] ?? null, 'to_description' => mb_substr(trim((string) $d['to_description']), 0, 255)];
        }

        $fromP = $this->period($orgId, $d['from_period_id'] ?? null, $fy, 'from_period_id');
        $toP = $this->period($orgId, $d['to_period_id'] ?? null, $fy, 'to_period_id');
        if ($to && (int) $to->id === (int) $from->id && ! ($fromP && $toP && $fromP->id !== $toP->id)) {
            $this->fail('A transfer needs a different target line, or two different periods of the same line.', 'to_line_id');
        }

        $amount = (string) ($d['amount'] ?? '');
        if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            $this->fail('The amount must be greater than 0 (base currency, up to 2 decimals).', 'amount');
        }
        $amount = bcadd($amount, '0', 2);
        blank($d['reason'] ?? null) && $this->fail('Give the reason for the transfer.', 'reason');

        $targetCapex = $to ? (bool) $to->is_capex : (bool) $pool->default_capex;
        if ((bool) $from->is_capex !== $targetCapex && ! config('finance.transfers.allow_capex_opex')) {
            $this->fail('Transfers between CapEx and OpEx are not allowed.', 'to_line_id');
        }

        $probe = new BudgetTransfer(['from_period_id' => $fromP?->id]);
        $this->assertAvailable($from, $probe, $amount, $fy->id);

        return ['fiscal_year_id' => $fy->id, 'from_line_id' => $from->id, 'to_line_id' => $to?->id, 'from_period_id' => $fromP?->id, 'to_period_id' => $toP?->id,
            'amount' => $amount, 'currency' => $this->settings->baseCurrency($orgId), 'base_amount' => $amount, 'reason' => trim((string) $d['reason'])] + $new;
    }

    private function assertAvailable(BudgetLine $from, BudgetTransfer $t, string $amount, ?int $fyId = null): void
    {
        $fyId ??= (int) $t->fiscal_year_id;
        $period = $t->from_period_id ? Period::withoutGlobalScopes()->find($t->from_period_id) : null;
        $available = $period ? $this->ledger->available($from, $period) : $this->ledger->availableInOpenPeriods($from, $fyId);
        if (bccomp($amount, $available, 2) > 0) {
            $this->fail("The amount exceeds the {$available} available on line {$from->line_no}.", 'amount');
        }
    }

    private function baselineLine(int $orgId, int $id, FiscalYear $fy, string $key): BudgetLine
    {
        $line = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->find($id);
        $budget = $line ? Budget::withoutGlobalScopes()->find($line->budget_id) : null;
        if (! $line || ! $budget || $budget->status !== 'baseline' || (int) $budget->fiscal_year_id !== (int) $fy->id) {
            $this->fail('Pick a line of a baseline budget in this fiscal year.', $key);
        }

        return $line;
    }

    private function period(int $orgId, $id, FiscalYear $fy, string $key): ?Period
    {
        if (! $id) {
            return null;
        }
        $p = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fy->id)->where('is_adjustment', false)->find($id);
        $p || $this->fail('That period is not in the fiscal year.', $key);
        $p->status === 'open' || $this->fail("{$p->name} is {$p->status}; pick an open period.", $key);

        return $p;
    }

    private function assertVisible(User $by, int $ccId, string $key): void
    {
        $ids = FinancePolicy::visibleCostCentreIds($by);
        if ($ids !== null && ! in_array($ccId, $ids, true)) {
            $this->fail('You can only transfer from cost centres you own.', $key);
        }
    }

    private function assertTransition(BudgetTransfer $t, string $action): void
    {
        if (! in_array($t->status, self::TRANSITIONS[$action]['from'], true)) {
            $this->fail("A {$t->status} transfer cannot be ".['submit' => 'submitted', 'withdraw' => 'withdrawn', 'approve' => 'approved', 'reject' => 'rejected', 'apply' => 'applied'][$action].'.');
        }
    }

    private function assertRequester(BudgetTransfer $t, User $by): void
    {
        if ((int) $t->requested_by_id !== (int) $by->id) {
            $this->fail('Only the person who requested the transfer can do that.');
        }
    }

    private function assertApprover(BudgetTransfer $t, User $by): void
    {
        (int) $by->organization_id === (int) $t->organization_id || $this->fail('This transfer belongs to another bank.');
        $by->can('approve it-finance') || $this->fail('You need the "approve it-finance" permission to decide a transfer.', 'approval');
    }
}
