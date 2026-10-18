<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\Reports\FinanceReport;

/** Open commitments as of today: approved expense requests without a PO and open purchase orders with what is left to invoice. */
class CommitmentsReport extends FinanceReport
{
    public function key(): string
    {
        return 'commitments';
    }

    public function title(): string
    {
        return 'Commitments';
    }

    public function description(): string
    {
        return 'Approved requests and open purchase orders with the commitment still open against the budget.';
    }

    public function filters(): array
    {
        return ['fiscal_year_id', 'cost_centre_id'];
    }

    public function columns(): array
    {
        return [
            'document' => ['label' => 'Document', 'type' => 'text'], 'kind' => ['label' => 'Type', 'type' => 'text'], 'vendor' => ['label' => 'Vendor', 'type' => 'text'], 'line' => ['label' => 'Budget line', 'type' => 'text'],
            'cost_centre' => ['label' => 'Cost centre', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'remaining' => ['label' => 'Remaining commitment', 'type' => 'money'],
        ];
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $ccFilter = ! empty($filters['cost_centre_id']) ? (int) $filters['cost_centre_id'] : null;
        $fy = ! empty($filters['fiscal_year_id']) ? (int) $filters['fiscal_year_id'] : null;
        $scope = fn ($q) => $q->where('organization_id', $orgId)->whereNull('deleted_at')
            ->when($costCentreIds !== null, fn ($w) => $w->whereIn('cost_centre_id', $costCentreIds ?: [0]))->when($ccFilter, fn ($w) => $w->where('cost_centre_id', $ccFilter))
            ->when($fy, fn ($w) => $w->where('fiscal_year_id', $fy));
        $pos = $scope(PurchaseOrder::withoutGlobalScopes())->whereIn('status', PurchaseOrder::OPEN)->orderBy('po_date')->orderBy('id')->get();
        $poExpenseIds = $pos->pluck('expense_request_id')->filter()->all();
        $reqs = $scope(ExpenseRequest::withoutGlobalScopes())->whereIn('status', ExpenseRequest::OPEN_COMMITMENT)->whereNotIn('id', $poExpenseIds)
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('fin_purchase_orders')->whereColumn('fin_purchase_orders.expense_request_id', 'fin_expense_requests.id')->whereNull('fin_purchase_orders.deleted_at'))
            ->orderBy('id')->get();
        $vendors = Vendor::withoutGlobalScopes()->whereIn('id', $pos->pluck('vendor_id')->merge($reqs->pluck('vendor_id'))->filter()->unique())->pluck('name', 'id');
        $lines = BudgetLine::withoutGlobalScopes()->whereIn('id', $pos->pluck('budget_line_id')->merge($reqs->pluck('budget_line_id'))->filter()->unique())->get(['id', 'budget_id', 'line_no', 'description'])->keyBy('id');
        $codes = Budget::withoutGlobalScopes()->whereIn('id', $lines->pluck('budget_id'))->pluck('budget_code', 'id');
        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'code', 'name'])->keyBy('id');
        $avail = app(BudgetAvailability::class);
        $lineOf = fn ($id) => ($l = $lines[$id] ?? null) ? trim(($codes[$l->budget_id] ?? '')." #{$l->line_no} {$l->description}") : 'Unbudgeted';
        $ccOf = fn ($id) => trim(($cc[$id]->code ?? '').' '.($cc[$id]->name ?? ''));
        $rows = [];
        foreach ($reqs as $r) {
            $rows[] = ['document' => (string) $r->request_code, 'kind' => 'Expense request', 'vendor' => (string) ($vendors[$r->vendor_id] ?? ''), 'line' => $lineOf($r->budget_line_id), 'cost_centre' => $ccOf($r->cost_centre_id),
                'date' => $r->approved_at?->toDateString() ?? $r->created_at?->toDateString(), 'remaining' => bcadd((string) $r->base_total_amount, '0', 2)];
        }
        foreach ($pos as $p) {
            $rows[] = ['document' => (string) $p->po_code, 'kind' => 'Purchase order', 'vendor' => (string) ($vendors[$p->vendor_id] ?? ''), 'line' => $lineOf($p->budget_line_id), 'cost_centre' => $ccOf($p->cost_centre_id),
                'date' => $p->po_date?->toDateString(), 'remaining' => $avail->remainingForPo($p)];
        }

        return $rows;
    }
}
