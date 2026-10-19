<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsMonitoring;
use App\Http\Requests\Finance\MonitorFilterRequest;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\Contract;
use App\Models\Finance\Period;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\CloudCostService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\MonitoringService;
use Illuminate\Support\Facades\Gate;

/** The IT finance dashboard (FIN-04.07): one page, scoped to the viewer's cost centres (plan P4-13). */
class FinanceDashboardController extends Controller
{
    use PresentsMonitoring;

    public function __construct(private readonly FinanceMetrics $metrics, private readonly MonitoringService $monitoring) {}

    public function index(MonitorFilterRequest $request)
    {
        Gate::authorize('viewAny', Period::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $fy = $this->monitoring->resolveFiscalYear($orgId, $request->validated('fiscal_year_id'));
        $visible = FinancePolicy::visibleCostCentreIds($user);
        $ccId = $request->validated('cost_centre_id');
        $ids = $visible;
        if ($ccId) {
            $scope = array_merge([(int) $ccId], $this->monitoring->descendants($orgId, (int) $ccId));
            $ids = $visible === null ? $scope : array_values(array_intersect($scope, $visible));
        }
        $asOf = $fy ? $this->monitoring->asOfPeriod($orgId, $fy->id) : null;
        $renewals = Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->dueWithin(90)
            ->when($ids !== null, fn ($q) => $q->whereIn('cost_centre_id', $ids ?: [0]))->with('vendor:id,name')->orderByRaw('coalesce(notice_deadline_on, ends_on)')->limit(10)->get();

        $cloudTrend = [];
        $canAmounts = $user->can('view it-finance-amounts');
        if ($canAmounts && CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->exists()) {
            $cloudTrend = app(CloudCostService::class)->mtd($orgId, $ids)['trend'];
        }

        return $this->renderMonitoring('Finance/Dashboard', [
            'cloudTrend' => $cloudTrend, 'cloudTrendMasked' => ! $canAmounts,
            'fiscalYears' => $this->fiscalYearsMini($orgId),
            'selectedFiscalYear' => $fy ? ['id' => $fy->id, 'year_label' => $fy->year_label, 'starts_on' => $fy->starts_on->toDateString(), 'ends_on' => $fy->ends_on->toDateString()] : null,
            'asOfPeriod' => $asOf ? ['id' => $asOf->id, 'name' => $asOf->name, 'sequence' => $asOf->sequence] : null,
            'audience' => $visible === null ? 'finance' : 'owner',
            'costCentres' => $this->visibleCostCentresMini($user),
            'filters' => ['fiscal_year_id' => $fy?->id, 'cost_centre_id' => $ccId ? (int) $ccId : null],
            'metrics' => $this->metrics->summary($orgId, $fy?->id, $ids),
            'spendByCategory' => $fy ? $this->metrics->spendByTower($orgId, $fy->id, $ids) : [],
            'monthlyBvA' => $fy ? $this->metrics->monthly($orgId, $fy->id, $ids) : [],
            'topVariances' => $fy ? $this->metrics->topVariances($orgId, $fy->id, $ids, (int) config('finance.monitoring.top_variances', 10)) : [],
            'renewalsDue' => $renewals->map(fn (Contract $c) => [
                'id' => $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title, 'vendor' => $c->vendor?->name, 'ends_on' => $c->ends_on?->toDateString(),
                'notice_deadline_on' => $c->notice_deadline_on?->toDateString(), 'days_to_notice' => FinanceClock::daysUntil($c->notice_deadline_on), 'status' => $c->status,
                'href' => route('finance.contracts.show', $c->id, false),
            ])->values()->all(),
            'pendingApprovals' => $this->metrics->pendingApprovals($user),
            'can' => ['export' => $user->can('export it-finance'), 'closePeriods' => $user->can('close it-finance-periods')],
        ]);
    }
}
