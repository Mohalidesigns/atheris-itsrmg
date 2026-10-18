<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Actual;
use App\Models\Finance\Contract;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\PurchaseOrder;
use App\Models\Vendor;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReport;

/** Spend per vendor for a fiscal year: actual year to date, open commitments, live contracts and share of the total. */
class VendorSpendReport extends FinanceReport
{
    public function key(): string
    {
        return 'vendor_spend';
    }

    public function title(): string
    {
        return 'Vendor spend';
    }

    public function description(): string
    {
        return 'Actual spend, open commitments and live contract value per vendor, with each vendor\'s share of total spend.';
    }

    public function group(): string
    {
        return 'vendors';
    }

    public function filters(): array
    {
        return ['fiscal_year_id', 'cost_centre_id'];
    }

    public function columns(): array
    {
        return [
            'vendor' => ['label' => 'Vendor', 'type' => 'text'], 'actual_ytd' => ['label' => 'Actual YTD', 'type' => 'money'], 'committed' => ['label' => 'Open commitments', 'type' => 'money'],
            'live_contracts' => ['label' => 'Live contracts', 'type' => 'int'], 'live_contract_value' => ['label' => 'Live contract value', 'type' => 'money'], 'share_pct' => ['label' => 'Share of spend %', 'type' => 'pct'],
        ];
    }

    public function totals(array $rows): ?array
    {
        $t = parent::totals($rows);
        if ($t) {
            $t['share_pct'] = $rows ? '100.00' : null;
        }

        return $t;
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $mon = app(MonitoringService::class);
        $fy = $mon->resolveFiscalYear($orgId, ! empty($filters['fiscal_year_id']) ? (int) $filters['fiscal_year_id'] : null);
        if (! $fy) {
            return [];
        }
        $cc = ! empty($filters['cost_centre_id']) ? array_merge([(int) $filters['cost_centre_id']], $mon->descendants($orgId, (int) $filters['cost_centre_id'])) : null;
        $scope = fn ($q) => $q->where('organization_id', $orgId)->when($costCentreIds !== null, fn ($w) => $w->whereIn('cost_centre_id', $costCentreIds ?: [0]))->when($cc, fn ($w) => $w->whereIn('cost_centre_id', $cc));
        $actual = $scope(Actual::withoutGlobalScopes())->where('fiscal_year_id', $fy->id)->where('match_status', '!=', 'duplicate')->whereNotNull('vendor_id')
            ->selectRaw('vendor_id, sum(base_amount) as s')->groupBy('vendor_id')->pluck('s', 'vendor_id');
        $avail = app(BudgetAvailability::class);
        $committed = [];
        foreach ($scope(PurchaseOrder::withoutGlobalScopes())->whereNull('deleted_at')->where('fiscal_year_id', $fy->id)->whereIn('status', PurchaseOrder::OPEN)->whereNotNull('vendor_id')->get() as $po) {
            $committed[$po->vendor_id] = bcadd($committed[$po->vendor_id] ?? '0.00', $avail->remainingForPo($po), 2);
        }
        $reqs = $scope(ExpenseRequest::withoutGlobalScopes())->whereNull('deleted_at')->where('fiscal_year_id', $fy->id)->whereIn('status', ExpenseRequest::OPEN_COMMITMENT)->whereNotNull('vendor_id')
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('fin_purchase_orders')->whereColumn('fin_purchase_orders.expense_request_id', 'fin_expense_requests.id')->whereNull('fin_purchase_orders.deleted_at'))
            ->selectRaw('vendor_id, sum(base_total_amount) as s')->groupBy('vendor_id')->pluck('s', 'vendor_id');
        foreach ($reqs as $vid => $s) {
            $committed[$vid] = bcadd($committed[$vid] ?? '0.00', (string) $s, 2);
        }
        $contracts = $scope(Contract::withoutGlobalScopes())->whereNull('deleted_at')->live()->whereNotNull('vendor_id')
            ->selectRaw('vendor_id, count(*) as n, sum(base_contract_value) as v')->groupBy('vendor_id')->get()->keyBy('vendor_id');
        $ids = collect(array_keys($committed))->merge($actual->keys())->merge($contracts->keys())->unique()->values();
        $names = Vendor::withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id');
        $total = (string) $actual->sum();
        $rows = $ids->map(function ($id) use ($actual, $committed, $contracts, $names, $total) {
            $a = bcadd((string) ($actual[$id] ?? '0'), '0', 2);

            return [
                'vendor' => (string) ($names[$id] ?? "Vendor #{$id}"), 'actual_ytd' => $a, 'committed' => $committed[$id] ?? '0.00', 'live_contracts' => (int) ($contracts[$id]->n ?? 0),
                'live_contract_value' => bcadd((string) ($contracts[$id]->v ?? '0'), '0', 2),
                'share_pct' => bccomp($total, '0', 2) > 0 ? bcadd(bcmul(bcdiv($a, $total, 8), '100', 8), '0', 2) : '0.00',
            ];
        })->sortByDesc(fn ($r) => (float) $r['actual_ytd'])->values()->all();

        return $rows;
    }
}
