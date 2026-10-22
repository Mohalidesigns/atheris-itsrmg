<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CostCentre;
use App\Services\Finance\Reports\FinanceReport;
use Illuminate\Support\Facades\DB;

/** Cloud cost per account, service and attribution over a date range (FinOps sub-ledger; never part of the ledger actuals). */
class CloudSpendReport extends FinanceReport
{
    public function key(): string
    {
        return 'cloud_spend';
    }

    public function title(): string
    {
        return 'Cloud spend';
    }

    public function description(): string
    {
        return 'Cloud cost per account, service, cost centre and application or service, in billing and base currency, with the untagged share flagged.';
    }

    public function group(): string
    {
        return 'Cloud & FinOps';
    }

    public function filters(): array
    {
        return ['account_id', 'from', 'to'];
    }

    public function columns(): array
    {
        return [
            'account' => ['label' => 'Account', 'type' => 'text'], 'provider' => ['label' => 'Provider', 'type' => 'text'], 'service' => ['label' => 'Service', 'type' => 'text'],
            'cost_centre' => ['label' => 'Cost centre', 'type' => 'text'], 'attributed_to' => ['label' => 'Application / service', 'type' => 'text'], 'days' => ['label' => 'Days', 'type' => 'int'],
            'currency' => ['label' => 'Billing currency', 'type' => 'text'], 'billing_cost' => ['label' => 'Billing cost', 'type' => 'money'], 'base_cost' => ['label' => 'Base cost', 'type' => 'money'],
            'untagged' => ['label' => 'Untagged', 'type' => 'text'],
        ];
    }

    /** Billing cost is in mixed currencies, so it is excluded from the totals row. */
    public function totals(array $rows): ?array
    {
        $t = parent::totals($rows);
        if ($t) {
            $t['billing_cost'] = null;
            $t['days'] = null;
        }

        return $t;
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $q = DB::table('fin_cloud_cost_lines as l')->where('l.organization_id', $orgId);
        if ($costCentreIds !== null) {
            $owned = []; // the report is cost-centre scoped; an owner's own accounts are not added here
            $q->where(fn ($b) => $b->whereIn('l.cost_centre_id', $costCentreIds ?: [0])->orWhereIn('l.cloud_account_id', $owned ?: [0]));
        }
        ! empty($filters['account_id']) && $q->where('l.cloud_account_id', (int) $filters['account_id']);
        ! empty($filters['from']) && $q->whereDate('l.usage_date', '>=', $filters['from']);
        ! empty($filters['to']) && $q->whereDate('l.usage_date', '<=', $filters['to']);
        $data = $q->selectRaw('l.cloud_account_id, l.service, l.cost_centre_id, l.ea_application_id, l.business_service_id, l.currency, l.is_untagged, COUNT(DISTINCT l.usage_date) as days, SUM(l.cost) as cost, SUM(l.base_cost) as base')
            ->groupBy('l.cloud_account_id', 'l.service', 'l.cost_centre_id', 'l.ea_application_id', 'l.business_service_id', 'l.currency', 'l.is_untagged')
            ->orderBy('l.cloud_account_id')->orderBy('l.service')->get();
        $accounts = CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'name', 'provider'])->keyBy('id');
        $ccs = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'code', 'name'])->keyBy('id');
        $apps = EaApplication::withoutGlobalScopes()->whereIn('id', $data->pluck('ea_application_id')->filter()->unique())->pluck('name', 'id');
        $svcs = BusinessService::withoutGlobalScopes()->whereIn('id', $data->pluck('business_service_id')->filter()->unique())->pluck('name', 'id');

        return $data->map(fn ($r) => [
            'account' => (string) ($accounts[$r->cloud_account_id]->name ?? '-'), 'provider' => strtoupper((string) ($accounts[$r->cloud_account_id]->provider ?? '')), 'service' => (string) $r->service,
            'cost_centre' => isset($ccs[$r->cost_centre_id]) ? $ccs[$r->cost_centre_id]->code.' '.$ccs[$r->cost_centre_id]->name : '-',
            'attributed_to' => (string) ($apps[$r->ea_application_id] ?? $svcs[$r->business_service_id] ?? '-'), 'days' => (int) $r->days, 'currency' => (string) $r->currency,
            'billing_cost' => bcadd((string) $r->cost, '0', 2), 'base_cost' => bcadd((string) $r->base, '0', 2), 'untagged' => $r->is_untagged ? 'Yes' : 'No',
        ])->all();
    }
}
