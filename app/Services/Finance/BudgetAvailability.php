<?php

namespace App\Services\Finance;

use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\PurchaseOrder;
use Illuminate\Support\Collection;

/**
 * Budget availability (FIN-03.01, plan P3-1/P3-2): current budget of the line for the year, minus what is committed (approved
 * requests not yet invoiced, open purchase orders) and what is actually posted, over the line's whole lineage so a re-baseline
 * never hides earlier commitments. Everything is in base currency (bcmath strings).
 */
class BudgetAvailability
{
    /** Invoice states that have been approved (and so relieve a commitment and exist as an actual). */
    private const APPROVED = ['approved', 'scheduled', 'paid'];

    public function __construct(private readonly BudgetLedger $ledger, private readonly BudgetPhasing $phasing) {}

    /**
     * @return array{line_id:int,budget:string,committed:string,actual:string,available:string,used_pct:string,period:array{id:int,name:string,budget:string,actual:string}|null}
     */
    public function forLine(BudgetLine $line, ?Period $period = null, ?int $excludeExpenseId = null): array
    {
        $ids = $this->ledger->lineageIds($line, true);
        $committed = $this->committed($ids, $excludeExpenseId);
        $actual = $this->actual($ids, (int) $this->fiscalYearId($line));

        return $this->shape($line, $committed, $actual, $period, $ids);
    }

    /**
     * Bulk variant for index pages: two grouped queries for the whole collection.
     *
     * @param  Collection<int, BudgetLine>  $lines
     * @return array<int, array<string,mixed>> line_id => availability
     */
    public function forLines(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }
        $orgId = (int) $lines->first()->organization_id;
        $sources = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotNull('source_line_id')->pluck('source_line_id', 'id');
        $lineage = [];
        $all = [];
        $budgetFy = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('fiscal_year_id', 'id');
        $lineFy = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('budget_id', 'id')->map(fn ($b) => $budgetFy[$b] ?? null);
        foreach ($lines as $l) {
            $ids = [(int) $l->id];
            $src = $sources[$l->id] ?? null;
            for ($h = 0; $src && $h < 25 && ! in_array((int) $src, $ids, true) && ($lineFy[$src] ?? null) === ($lineFy[$l->id] ?? null); $h++) {
                $ids[] = (int) $src;
                $src = $sources[$src] ?? null;
            }
            $lineage[$l->id] = $ids;
            $all = array_merge($all, $ids);
        }
        $all = array_values(array_unique($all));
        $committedMap = $this->committedMap($all, null);
        $actualMap = Actual::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('budget_line_id', $all)->where('match_status', '!=', 'duplicate')
            ->selectRaw('budget_line_id, fiscal_year_id, sum(base_amount) as total')->groupBy('budget_line_id', 'fiscal_year_id')->get();
        $out = [];
        foreach ($lines as $l) {
            $fy = $this->fiscalYearId($l);
            $committed = '0.00';
            $actual = '0.00';
            foreach ($lineage[$l->id] as $id) {
                $committed = bcadd($committed, $committedMap[$id] ?? '0', 2);
            }
            foreach ($actualMap as $row) {
                in_array((int) $row->budget_line_id, $lineage[$l->id], true) && (int) $row->fiscal_year_id === (int) $fy && ($actual = bcadd($actual, (string) $row->total, 2));
            }
            $out[$l->id] = $this->shape($l, $committed, $actual, null, $lineage[$l->id]);
        }

        return $out;
    }

    /** @param list<int> $lineIds */
    public function committed(array $lineIds, ?int $excludeExpenseId = null): string
    {
        $sum = '0.00';
        foreach ($this->committedMap($lineIds, $excludeExpenseId) as $v) {
            $sum = bcadd($sum, $v, 2);
        }

        return $sum;
    }

    /** @param list<int> $lineIds */
    public function actual(array $lineIds, int $fiscalYearId, ?int $periodId = null): string
    {
        return bcadd((string) Actual::withoutGlobalScopes()->whereIn('budget_line_id', $lineIds ?: [0])->where('fiscal_year_id', $fiscalYearId)
            ->when($periodId, fn ($q) => $q->where('period_id', $periodId))->where('match_status', '!=', 'duplicate')->sum('base_amount'), '0', 2);
    }

    /**
     * Committed base amount per budget line id (plan P3-2): approved requests without a PO (less invoices booked straight against
     * them) plus open purchase orders (less their approved invoices at the PO rate); never below zero per document.
     *
     * @param  list<int>  $lineIds
     * @return array<int, string>
     */
    private function committedMap(array $lineIds, ?int $excludeExpenseId): array
    {
        $out = [];
        $add = function (?int $lineId, string $amount) use (&$out) {
            $lineId && ($out[$lineId] = bcadd($out[$lineId] ?? '0', $amount, 2));
        };
        $requests = ExpenseRequest::withoutGlobalScopes()->whereIn('budget_line_id', $lineIds ?: [0])->where('status', 'approved')
            ->when($excludeExpenseId, fn ($q) => $q->where('id', '!=', $excludeExpenseId))->get(['id', 'budget_line_id', 'base_total_amount']);
        if ($requests->isNotEmpty()) {
            $invoiced = Invoice::withoutGlobalScopes()->whereIn('expense_request_id', $requests->pluck('id'))->whereNull('po_id')->whereIn('status', self::APPROVED)
                ->selectRaw('expense_request_id, sum(base_total_amount) as total')->groupBy('expense_request_id')->pluck('total', 'expense_request_id');
            foreach ($requests as $r) {
                $left = bcsub((string) $r->base_total_amount, (string) ($invoiced[$r->id] ?? '0'), 2);
                $add($r->budget_line_id, bccomp($left, '0', 2) > 0 ? $left : '0');
            }
        }
        $pos = PurchaseOrder::withoutGlobalScopes()->whereIn('budget_line_id', $lineIds ?: [0])->whereIn('status', PurchaseOrder::OPEN)
            ->when($excludeExpenseId, fn ($q) => $q->where('expense_request_id', '!=', $excludeExpenseId))->get(['id', 'budget_line_id', 'base_total_amount', 'fx_rate']);
        if ($pos->isNotEmpty()) {
            $invoiced = Invoice::withoutGlobalScopes()->whereIn('po_id', $pos->pluck('id'))->whereIn('status', self::APPROVED)
                ->selectRaw('po_id, sum(total_amount) as total')->groupBy('po_id')->pluck('total', 'po_id');
            $accrued = Accrual::withoutGlobalScopes()->whereIn('po_id', $pos->pluck('id'))->where('type', 'accrual')->where('status', 'posted')
                ->selectRaw('po_id, sum(base_amount) as total')->groupBy('po_id')->pluck('total', 'po_id');
            foreach ($pos as $po) {
                $relief = $this->phasing->round(bcmul((string) ($invoiced[$po->id] ?? '0'), (string) $po->fx_rate, 8), 2);
                $left = bcsub(bcsub((string) $po->base_total_amount, $relief, 2), (string) ($accrued[$po->id] ?? '0'), 2); // a posted accrual is already an actual
                $add($po->budget_line_id, bccomp($left, '0', 2) > 0 ? $left : '0');
            }
        }

        // Approved prepaid invoices relieve the PO but only post actuals as each release is posted: unreleased amounts stay committed.
        foreach (Accrual::withoutGlobalScopes()->whereIn('budget_line_id', $lineIds ?: [0])->where('type', 'prepayment_release')->where('status', 'proposed')->get(['budget_line_id', 'base_amount']) as $rel) {
            $add($rel->budget_line_id, (string) $rel->base_amount);
        }

        return $out;
    }

    /** The remaining commitment of one purchase order in base currency (also used on PO pages). */
    public function remainingForPo(PurchaseOrder $po): string
    {
        if (! in_array($po->status, PurchaseOrder::OPEN, true)) {
            return '0.00';
        }
        $invoiced = (string) Invoice::withoutGlobalScopes()->where('po_id', $po->id)->whereIn('status', self::APPROVED)->sum('total_amount');
        $left = bcsub((string) $po->base_total_amount, $this->phasing->round(bcmul($invoiced, (string) $po->fx_rate, 8), 2), 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    private function fiscalYearId(BudgetLine $line): ?int
    {
        return Budget::withoutGlobalScopes()->whereKey($line->budget_id)->value('fiscal_year_id');
    }

    private function shape(BudgetLine $line, string $committed, string $actual, ?Period $period, array $lineIds): array
    {
        $budget = bcadd((string) $line->current_base_amount, '0', 2);
        $available = bcsub(bcsub($budget, $committed, 2), $actual, 2);
        $used = bccomp($budget, '0', 2) > 0 ? bcmul(bcdiv(bcadd($committed, $actual, 2), $budget, 6), '100', 2) : (bccomp(bcadd($committed, $actual, 2), '0', 2) > 0 ? '100.00' : '0.00');
        $per = null;
        if ($period) {
            $by = $this->ledger->currentByPeriod($line);
            $per = ['id' => $period->id, 'name' => $period->name, 'budget' => $by[$period->id] ?? '0.00', 'actual' => $this->actual($lineIds, (int) $period->fiscal_year_id, $period->id)];
        }

        return ['line_id' => (int) $line->id, 'budget' => $budget, 'committed' => $committed, 'actual' => $actual, 'available' => $available, 'used_pct' => $used, 'period' => $per];
    }
}
