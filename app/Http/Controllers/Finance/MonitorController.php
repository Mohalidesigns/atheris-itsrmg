<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsMonitoring;
use App\Http\Requests\Finance\ForecastOverrideRequest;
use App\Http\Requests\Finance\MonitorFilterRequest;
use App\Http\Requests\Finance\VarianceExplainRequest;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Forecast;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Period;
use App\Models\Finance\VarianceNote;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\ForecastService;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReports;
use App\Services\Finance\Reports\ReportExporter;
use App\Services\Finance\VarianceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Budget monitoring (FIN-04.01 to .04, .06, .10): budget vs actual, forecast (EAC), variance notes and their commentary. */
class MonitorController extends Controller
{
    use PresentsMonitoring;

    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly ForecastService $forecasts,
        private readonly VarianceService $variances,
        private readonly FinanceSettings $settings,
    ) {}

    public function bva(MonitorFilterRequest $request)
    {
        Gate::authorize('viewAny', Period::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $r = $this->monitoring->bva($orgId, $request->validated(), $user);
        $filters = $r['filters'];
        $filters['capex'] = $filters['capex'] === null ? null : (in_array($filters['capex'], [true, 1, '1', 'capex', 'true'], true) ? 1 : 0);
        $pick = fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name];
        $cats = Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('code')->get();

        return $this->renderMonitoring('Finance/Monitor/BvA', [
            'filters' => $filters, 'groupings' => $this->monitoring->groupings(), 'breadcrumbs' => $r['breadcrumbs'],
            'fiscalYears' => $this->fiscalYearsMini($orgId), 'periods' => $this->periodsMini($orgId, $filters['fiscal_year_id']),
            'dimensions' => [
                'costCentres' => $this->visibleCostCentresMini($user),
                'towers' => $cats->where('kind', 'tower')->map($pick)->values()->all(), 'costPools' => $cats->where('kind', 'cost_pool')->map($pick)->values()->all(),
                'categories' => $cats->where('kind', 'sub_category')->map($pick)->values()->all(),
                'glAccounts' => GlAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('code')->get()->map($pick)->values()->all(),
                'legalEntities' => LegalEntity::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('name')->get(['id', 'code', 'name'])->map($pick)->values()->all(),
                'vendors' => Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values()->all(),
            ],
            'thresholds' => $r['thresholds'], 'matrix' => $r['matrix'], 'can' => ['export' => $user->can('export it-finance')],
        ]);
    }

    /** The BvA report at the current drill level, as csv, xlsx or pdf. An organization with no fiscal year gets an empty CSV. */
    public function bvaExport(MonitorFilterRequest $request, FinanceReports $reports, ReportExporter $exporter)
    {
        Gate::authorize('exportFinance', Period::class);
        $user = $request->user();
        $format = strtolower((string) $request->validated('format', 'csv'));
        in_array($format, ['csv', 'xlsx', 'pdf'], true) || $format = 'csv';
        $filters = $request->validated();
        $result = $reports->run('bva', (int) $user->organization_id, $filters, $user);
        $title = 'Budget vs actual';

        return app(FinanceReportController::class)->download($result, $title, 'it-finance-bva', $format);
    }

    public function transactions(Request $request, BudgetLine $line)
    {
        Gate::authorize('view', $line);
        $data = $request->validate(['from_period_id' => ['nullable', 'integer'], 'to_period_id' => ['nullable', 'integer']]);
        $t = $this->monitoring->transactions($line, $data['from_period_id'] ?? null, $data['to_period_id'] ?? null);
        if (! $request->user()->can('view it-finance-amounts')) {
            $t['actuals'] = array_map(fn ($a) => array_merge($a, ['base_amount' => null]), $t['actuals']);
            $t['commitments'] = array_map(fn ($c) => array_merge($c, ['remaining_base' => null]), $t['commitments']);
            $t['totals'] = ['actual' => null, 'committed' => null];
        }

        return response()->json($t);
    }

    public function forecast(MonitorFilterRequest $request)
    {
        Gate::authorize('viewAny', Period::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $fy = $this->monitoring->resolveFiscalYear($orgId, $request->validated('fiscal_year_id'));
        $visible = FinancePolicy::visibleCostCentreIds($user);
        $ccId = $request->validated('cost_centre_id');
        $lines = $fy ? $this->monitoring->baselineLines($orgId, $fy->id, $visible, $ccId ? ['cost_centre_id' => $ccId] : []) : collect();
        $eac = $fy ? $this->forecasts->eacForLines($lines, $fy->id) : [];
        $asOf = $fy ? $this->monitoring->asOfPeriod($orgId, $fy->id) : null;
        $codes = Budget::withoutGlobalScopes()->whereIn('id', $lines->pluck('budget_id')->unique())->pluck('budget_code', 'id');
        $ccs = collect($this->visibleCostCentresMini($user))->keyBy('id');
        $thr = $this->variances->thresholds($orgId);
        $rows = [];
        $totals = ['baseline' => '0.00', 'budget' => '0.00', 'actual_ytd' => '0.00', 'eac_remaining_budget' => '0.00', 'eac_run_rate' => '0.00', 'eac' => '0.00'];
        foreach ($lines as $l) {
            $e = $eac[$l->id];
            $t = $this->variances->thresholds($orgId, $l);
            $c = VarianceService::classify($e['budget'], $e['eac'], $t['amber_pct'], $t['red_pct'], $thr['abs_floor']);
            $rows[] = [
                'line_id' => $l->id, 'label' => "#{$l->line_no} {$l->description}", 'budget_code' => $codes[$l->budget_id] ?? null, 'cost_centre' => $ccs[$l->cost_centre_id]['code'] ?? null,
                'baseline' => $e['baseline'], 'budget' => $e['budget'], 'actual_ytd' => $e['actual_ytd'], 'eac_remaining_budget' => $e['eac_remaining_budget'], 'eac_run_rate' => $e['eac_run_rate'],
                'manual' => $e['manual'], 'eac' => $e['eac'], 'method' => $e['method'], 'variance' => $e['variance'], 'variance_pct' => $e['variance_pct'], 'severity' => $c['severity'],
                'burn_monthly' => $e['burn_monthly'], 'exhaustion_on' => $e['exhaustion_on'], 'can' => ['override' => $user->can('overrideForecast', $l)],
            ];
            foreach (array_keys($totals) as $k) {
                $totals[$k] = bcadd($totals[$k], $e[$k], 2);
            }
        }
        $totals['variance'] = bcsub($totals['eac'], $totals['budget'], 2);
        $totals['variance_pct'] = bccomp($totals['budget'], '0', 2) !== 0 ? bcadd(bcmul(bcdiv($totals['variance'], $totals['budget'], 8), '100', 8), '0', 2) : null;
        $history = [];
        if ($fy) {
            $lineIds = $lines->pluck('id')->all();
            $periodNames = $this->monitoring->periodsOf($orgId, $fy->id);
            $snap = Forecast::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fy->id)->where('is_snapshot', true)->whereIn('budget_line_id', $lineIds ?: [0])
                ->selectRaw('period_id, sum(base_amount) as t')->groupBy('period_id')->pluck('t', 'period_id');
            foreach ($periodNames as $pid => $p) {
                isset($snap[$pid]) && $history[] = ['period_id' => (int) $pid, 'name' => $p->name, 'eac_total' => bcadd((string) $snap[$pid], '0', 2)];
            }
        }
        $first = $eac ? reset($eac) : null;

        return $this->renderMonitoring('Finance/Monitor/Forecast', [
            'filters' => ['fiscal_year_id' => $fy?->id, 'cost_centre_id' => $ccId ? (int) $ccId : null], 'fiscalYears' => $this->fiscalYearsMini($orgId), 'costCentres' => array_values($ccs->all()),
            'settingsMethod' => $this->settings->for($orgId)->eac_method, 'asOfPeriod' => $asOf ? ['id' => $asOf->id, 'name' => $asOf->name, 'sequence' => $asOf->sequence] : null,
            'periodsElapsed' => $first['periods_elapsed'] ?? 0, 'periodsTotal' => $first['periods_total'] ?? 0, 'lines' => $rows, 'totals' => $totals, 'history' => $history,
        ]);
    }

    public function forecastOverride(ForecastOverrideRequest $request, BudgetLine $line)
    {
        Gate::authorize('overrideForecast', $line);
        $this->forecasts->override($line, $request->validated('amount'), $request->validated('reason'), $request->user());

        return back()->with('success', 'Manual forecast saved.');
    }

    public function forecastClear(Request $request, BudgetLine $line)
    {
        Gate::authorize('overrideForecast', $line);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
        $this->forecasts->clear($line, $request->user(), $reason);

        return back()->with('success', 'Manual forecast cleared.');
    }

    public function variances(MonitorFilterRequest $request)
    {
        Gate::authorize('viewAny', Period::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $v = $request->validated();
        $fy = $this->monitoring->resolveFiscalYear($orgId, $v['fiscal_year_id'] ?? null);
        $periodId = $v['period_id'] ?? ($fy ? $this->monitoring->asOfPeriod($orgId, $fy->id)?->id : null);
        $base = VarianceNote::withoutGlobalScopes()->where('organization_id', $orgId)->visibleTo($user)
            ->when($fy, fn ($q) => $q->where('fiscal_year_id', $fy->id), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($periodId, fn ($q) => $q->where('period_id', $periodId))->when(! empty($v['cost_centre_id']), fn ($q) => $q->where('cost_centre_id', $v['cost_centre_id']));
        $count = fn (array $w) => (clone $base)->where($w)->count();
        $summary = [
            'red' => $count(['severity' => 'red', 'is_breaching' => true]), 'amber' => $count(['severity' => 'amber', 'is_breaching' => true]),
            'unexplained_red' => $count(['severity' => 'red', 'is_breaching' => true, 'status' => 'open']), 'explained' => $count(['status' => 'explained']), 'acknowledged' => $count(['status' => 'acknowledged']),
        ];
        $list = (clone $base)->when(! empty($v['severity']), fn ($q) => $q->where('severity', $v['severity']))->when(! empty($v['status']), fn ($q) => $q->where('status', $v['status']))
            ->when($request->boolean('needs_explanation'), fn ($q) => $q->where('status', 'open')->where('is_breaching', true))
            ->with(['period:id,name,status', 'line.budget:id,budget_code,owner_id', 'costCentre.owner:id,name', 'author:id,name', 'acknowledgedBy:id,name', 'issue:id,issue_code', 'comments.author:id,name'])
            ->orderByRaw("case severity when 'red' then 0 else 1 end")->orderByRaw('abs(variance_amount) desc')->orderBy('id')->paginate(25)->withQueryString();
        $list->through(fn (VarianceNote $n) => $this->noteRow($n, $user));

        return $this->renderMonitoring('Finance/Monitor/Variances', [
            'filters' => ['fiscal_year_id' => $fy?->id, 'period_id' => $periodId ? (int) $periodId : null, 'severity' => $v['severity'] ?? null, 'status' => $v['status'] ?? null,
                'cost_centre_id' => $v['cost_centre_id'] ?? null, 'needs_explanation' => $request->boolean('needs_explanation')],
            'fiscalYears' => $this->fiscalYearsMini($orgId), 'periods' => $this->periodsMini($orgId, $fy?->id), 'costCentres' => $this->visibleCostCentresMini($user),
            'statuses' => VarianceNote::STATUSES, 'thresholds' => $this->variances->thresholds($orgId), 'summary' => $summary, 'notes' => $list,
        ]);
    }

    /** @return array<string,mixed> */
    private function noteRow(VarianceNote $n, $user): array
    {
        $line = $n->line;
        $owner = $n->costCentre?->owner ?? ($line?->budget?->owner_id ? User::find($line->budget->owner_id) : null);
        $canExplain = $user->can('explainVariance', $n);

        return [
            'id' => $n->id, 'period' => ['id' => $n->period?->id, 'name' => $n->period?->name, 'status' => $n->period?->status],
            'line' => ['id' => $n->budget_line_id, 'label' => "#{$line?->line_no} {$line?->description}", 'budget_code' => $line?->budget?->budget_code],
            'cost_centre' => ['id' => $n->costCentre?->id, 'code' => $n->costCentre?->code, 'name' => $n->costCentre?->name], 'owner' => $this->userMini($owner),
            'severity' => $n->severity, 'peak_severity' => $n->peak_severity, 'direction' => $n->direction, 'status' => $n->status, 'is_breaching' => $n->is_breaching,
            'budget' => (string) $n->budget_amount, 'actual' => (string) $n->actual_amount, 'variance' => (string) $n->variance_amount, 'variance_pct' => $n->variance_pct === null ? null : (string) $n->variance_pct,
            'period_variance' => $n->period_variance_amount === null ? null : (string) $n->period_variance_amount, 'explanation' => $n->explanation, 'corrective_action' => $n->corrective_action,
            'author' => $n->author?->name, 'explained_at' => $n->explained_at?->toIso8601String(), 'acknowledged_by' => $n->acknowledgedBy?->name, 'acknowledged_at' => $n->acknowledged_at?->toIso8601String(),
            'issue' => $n->issue ? ['id' => $n->issue->id, 'code' => $n->issue->issue_code, 'url' => route('issues.show', $n->issue->id, false)] : null,
            'comments' => $n->comments->map(fn ($c) => ['id' => $c->id, 'author' => $c->author?->name, 'body' => $c->body, 'at' => $c->created_at?->toIso8601String()])->values()->all(),
            'can' => [
                'explain' => $canExplain, 'acknowledge' => $n->status === 'explained' && $user->can('acknowledgeVariance', $n) && (int) $n->author_id !== (int) $user->id,
                'raiseIssue' => $canExplain && ! ($n->issue && $n->issue->isOpen()), 'comment' => $canExplain,
            ],
        ];
    }

    public function explain(VarianceExplainRequest $request, VarianceNote $note)
    {
        Gate::authorize('explainVariance', $note);
        $this->variances->explain($note, $request->validated('explanation'), $request->validated('corrective_action'), $request->user());

        return back()->with('success', 'Explanation saved.');
    }

    public function comment(Request $request, VarianceNote $note)
    {
        Gate::authorize('explainVariance', $note);
        $body = $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];
        $this->variances->comment($note, $body, $request->user());

        return back()->with('success', 'Comment added.');
    }

    public function acknowledge(Request $request, VarianceNote $note)
    {
        Gate::authorize('acknowledgeVariance', $note);
        $this->variances->acknowledge($note, $request->user());

        return back()->with('success', 'Explanation acknowledged.');
    }

    public function raiseIssue(Request $request, VarianceNote $note)
    {
        Gate::authorize('explainVariance', $note);
        $title = $request->validate(['title' => ['nullable', 'string', 'max:200']])['title'] ?? null;
        $issue = $this->variances->raiseIssue($note, $request->user(), $title);

        return back()->with('success', "Issue {$issue->issue_code} raised.");
    }

    public function evaluate(Request $request, Period $period)
    {
        Gate::authorize('closePeriod', $period);
        $r = $this->variances->evaluate($period, $request->user());

        return back()->with('success', "{$period->name} evaluated: {$r['created']} new, {$r['updated']} updated, {$r['cleared']} cleared.");
    }
}
