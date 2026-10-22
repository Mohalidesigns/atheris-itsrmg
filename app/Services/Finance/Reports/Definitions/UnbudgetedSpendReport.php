<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Actual;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Vendor;
use App\Services\Finance\Reports\FinanceReport;

/** Spend with no budget cover: unbudgeted or over-budget requests plus unbudgeted actuals (FIN-03.08). */
class UnbudgetedSpendReport extends FinanceReport
{
    public function key(): string
    {
        return 'unbudgeted';
    }

    public function title(): string
    {
        return 'Unbudgeted spend';
    }

    public function description(): string
    {
        return 'Requests with no budget line or beyond it, and posted actuals nobody budgeted for.';
    }

    public function filters(): array
    {
        return ['fiscal_year_id', 'cost_centre_id'];
    }

    public function columns(): array
    {
        return [
            'source' => ['label' => 'Source', 'type' => 'text'], 'reference' => ['label' => 'Reference', 'type' => 'text'], 'description' => ['label' => 'Description', 'type' => 'text'],
            'vendor' => ['label' => 'Vendor', 'type' => 'text'], 'cost_centre' => ['label' => 'Cost centre', 'type' => 'text'], 'reason' => ['label' => 'Reason', 'type' => 'text'],
            'date' => ['label' => 'Date', 'type' => 'date'], 'amount' => ['label' => 'Amount', 'type' => 'money'],
        ];
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $fy = ! empty($filters['fiscal_year_id']) ? (int) $filters['fiscal_year_id'] : null;
        $cc = ! empty($filters['cost_centre_id']) ? (int) $filters['cost_centre_id'] : null;
        $scope = fn ($q) => $q->where('organization_id', $orgId)->when($costCentreIds !== null, fn ($w) => $w->whereIn('cost_centre_id', $costCentreIds ?: [0]))
            ->when($cc, fn ($w) => $w->where('cost_centre_id', $cc))->when($fy, fn ($w) => $w->where('fiscal_year_id', $fy));
        $reqs = $scope(ExpenseRequest::withoutGlobalScopes())->whereNull('deleted_at')->whereNotIn('status', ExpenseRequest::TERMINAL)->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->whereNull('budget_line_id')->orWhere('over_budget', true))->orderBy('id')->get();
        $acts = $scope(Actual::withoutGlobalScopes())->where('match_status', 'unbudgeted')->orderBy('posted_on')->orderBy('id')->get();
        $vendors = Vendor::withoutGlobalScopes()->whereIn('id', $reqs->pluck('vendor_id')->merge($acts->pluck('vendor_id'))->filter()->unique())->pluck('name', 'id');
        $centres = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'code', 'name'])->keyBy('id');
        $ccOf = fn ($id) => trim(($centres[$id]->code ?? '').' '.($centres[$id]->name ?? ''));
        $rows = [];
        foreach ($reqs as $r) {
            $rows[] = ['source' => 'Expense request', 'reference' => (string) $r->request_code, 'description' => (string) $r->title, 'vendor' => (string) ($vendors[$r->vendor_id] ?? ''),
                'cost_centre' => $ccOf($r->cost_centre_id), 'reason' => $r->budget_line_id ? 'Over budget' : 'No budget line', 'date' => $r->created_at?->toDateString(), 'amount' => bcadd((string) $r->base_total_amount, '0', 2)];
        }
        foreach ($acts as $a) {
            $rows[] = ['source' => 'Actual', 'reference' => (string) $a->source_ref, 'description' => (string) $a->description, 'vendor' => (string) ($vendors[$a->vendor_id] ?? ''),
                'cost_centre' => $ccOf($a->cost_centre_id), 'reason' => (string) ($a->match_reason ?: 'Unbudgeted'), 'date' => $a->posted_on?->toDateString(), 'amount' => bcadd((string) $a->base_amount, '0', 2)];
        }

        return $rows;
    }
}
