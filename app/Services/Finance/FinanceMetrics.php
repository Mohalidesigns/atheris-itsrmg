<?php

namespace App\Services\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Actual;
use App\Models\Finance\Benefit;
use App\Models\Finance\Category;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudAnomaly;
use App\Models\Finance\Contract;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\Finance\Invoice;
use App\Models\Finance\VarianceNote;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Approvals\ApprovalProcesses;
use Illuminate\Support\Facades\Gate;

/**
 * The figures behind the finance dashboard, the board-pack section and the KRIs (spec §5.7). Everything is base currency, bcmath
 * strings, and honours an optional cost-centre restriction. Masking is the controller's job.
 */
class FinanceMetrics
{
    /** Keys of boardFigures() that are money or derived from money; nulled for a viewer without `view it-finance-amounts` (plan P4-11). */
    public const BOARD_MONEY_KEYS = ['budget_total', 'budget_ytd', 'actual_ytd', 'variance_pct', 'forecast_fy', 'forecast_variance_pct', 'committed', 'available',
        'unbudgeted_amount', 'capex_opex_split', 'rgt_split', 'actual_unallocated', 'cloud_mtd'];

    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly ForecastService $forecasts,
        private readonly BudgetAvailability $availability,
        private readonly BudgetPhasing $phasing,
        private readonly ApprovalEngine $engine,
        private readonly PortfolioService $portfolio,
        private readonly InvestmentFinancials $investmentFinancials,
    ) {}

    /** Money-bearing keys of boardInvestmentFigures(), plus `by_stage.*.bac`; nulled for a viewer without `view it-finance-amounts` (security M2). */
    public const BOARD_INVESTMENT_MONEY_KEYS = ['rgt_split', 'regulatory_share_pct', 'bac_total', 'ac_total', 'eac_total', 'cpi_portfolio', 'benefits_planned', 'benefits_realised'];

    private function fy(int $orgId, ?int $id): ?FiscalYear
    {
        return $this->monitoring->resolveFiscalYear($orgId, $id);
    }

    /** @param array<int,int>|null $costCentreIds @return array<string,mixed> */
    public function summary(int $orgId, ?int $fiscalYearId = null, ?array $costCentreIds = null): array
    {
        $fy = $this->fy($orgId, $fiscalYearId);
        $z = '0.00';
        $out = [
            'budget_total' => $z, 'budget_ytd' => $z, 'committed' => $z, 'actual_ytd' => $z, 'actual_unallocated' => $z, 'forecast_fy' => $z, 'variance_ytd' => $z, 'variance_pct' => null, 'available' => $z,
            'unbudgeted_count' => 0, 'unbudgeted_amount' => $z, 'capex_opex_split' => ['capex' => $z, 'opex' => $z], 'rgt_split' => ['run' => $z, 'grow' => $z, 'transform' => $z],
            'burn' => ['monthly' => $z, 'months_remaining' => null, 'exhaustion_on' => null], 'variances' => ['red' => 0, 'amber' => 0, 'unexplained_red' => 0],
            'investments_by_stage' => [], 'benefits_realised_pct' => null, 'cloud_mtd' => null, 'cloud_anomalies_open' => null,
        ] + $this->contractFigures($orgId, $costCentreIds);
        $inv = $this->portfolio->summary($orgId, $costCentreIds);
        $out['investments_by_stage'] = $inv['by_stage'];
        if (CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->exists()) {
            $out['cloud_mtd'] = app(CloudCostService::class)->mtd($orgId, $costCentreIds)['mtd'];
            $out['cloud_anomalies_open'] = CloudAnomaly::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', CloudAnomaly::OPEN)
                ->when($costCentreIds !== null, fn ($q) => $q->whereIn('cloud_account_id', CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('default_cost_centre_id', $costCentreIds ?: [0])->select('id')))->count();
        }
        $out['benefits_realised_pct'] = $inv['benefits_realised_pct'];
        if (! $fy) {
            return $out;
        }
        $asOf = $this->monitoring->asOfPeriod($orgId, $fy->id);
        $periods = $this->monitoring->periodsOf($orgId, $fy->id);
        $lines = $this->monitoring->baselineLines($orgId, $fy->id, $costCentreIds);
        $figs = $this->monitoring->lineFigures($lines, $fy->id);
        $avail = $this->availability->forLines($lines);
        $eac = $this->forecasts->eacForLines($lines, $fy->id, $asOf);
        $elapsed = 0;
        if ($asOf) {
            $elapsed = $periods->where('is_adjustment', false)->filter(fn ($p) => $p->sequence <= $asOf->sequence)->count();
        }
        foreach ($lines as $l) {
            $f = $figs[$l->id];
            $out['budget_total'] = bcadd($out['budget_total'], $f['budget_fy'], 2);
            foreach ($f['by_period'] as $pid => $v) {
                if ($asOf && $periods[$pid]->sequence <= $asOf->sequence) {
                    $out['budget_ytd'] = bcadd($out['budget_ytd'], $v['budget'], 2);
                    $out['actual_ytd'] = bcadd($out['actual_ytd'], $v['actual'], 2);
                }
            }
            $out['committed'] = bcadd($out['committed'], (string) $avail[$l->id]['committed'], 2);
            $out['available'] = bcadd($out['available'], (string) $avail[$l->id]['available'], 2);
            $out['forecast_fy'] = bcadd($out['forecast_fy'], $eac[$l->id]['eac'], 2);
            $out['capex_opex_split'][$l->is_capex ? 'capex' : 'opex'] = bcadd($out['capex_opex_split'][$l->is_capex ? 'capex' : 'opex'], $f['budget_fy'], 2);
            isset($out['rgt_split'][$l->rgt]) && $out['rgt_split'][$l->rgt] = bcadd($out['rgt_split'][$l->rgt], $f['budget_fy'], 2);
        }
        if ($asOf && $costCentreIds === null) {
            // Unmatched and unbudgeted postings count too, so the dashboard ties to the BvA matrix and the ledger.
            $out['actual_unallocated'] = $this->unallocatedActual($orgId, $fy->id, $periods->filter(fn ($p) => $p->sequence <= $asOf->sequence)->keys()->all(), $lines);
            $out['actual_ytd'] = bcadd($out['actual_ytd'], $out['actual_unallocated'], 2);
        }
        $out['variance_ytd'] = bcsub($out['actual_ytd'], $out['budget_ytd'], 2);
        $out['variance_pct'] = bccomp($out['budget_ytd'], '0', 2) !== 0 ? $this->phasing->round(bcmul(bcdiv($out['variance_ytd'], $out['budget_ytd'], 8), '100', 8), 2) : null;
        if ($elapsed > 0) {
            $burn = $this->phasing->round(bcdiv($out['actual_ytd'], (string) $elapsed, 8), 2);
            $out['burn']['monthly'] = $burn;
            if (bccomp($burn, '0', 2) > 0) {
                $m = bccomp($out['available'], '0', 2) > 0 ? $this->phasing->round(bcdiv($out['available'], $burn, 8), 2) : '0.00';
                $out['burn']['months_remaining'] = $m;
                $out['burn']['exhaustion_on'] = $asOf->ends_on->copy()->addDays((int) round((float) $m * 30.4375))->toDateString();
            }
        }
        if ($asOf) {
            $notes = VarianceNote::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $asOf->id)->where('is_breaching', true)
                ->when($costCentreIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $costCentreIds ?: [0]));
            $out['variances'] = [
                'red' => (clone $notes)->where('severity', 'red')->count(), 'amber' => (clone $notes)->where('severity', 'amber')->count(),
                'unexplained_red' => (clone $notes)->where('severity', 'red')->where('status', 'open')->count(),
            ];
        }
        [$count, $amount] = $this->unbudgeted($orgId, $fy->id, $costCentreIds);
        $out['unbudgeted_count'] = $count;
        $out['unbudgeted_amount'] = $amount;

        return $out;
    }

    /** Counted actuals of the periods that sit on no current baseline line (same definition as the BvA matrix). */
    private function unallocatedActual(int $orgId, int $fyId, array $periodIds, $lines): string
    {
        $known = $this->monitoring->allLineageIds($orgId, $lines);

        return bcadd((string) Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fyId)->where('match_status', '!=', 'duplicate')
            ->whereIn('period_id', $periodIds ?: [0])->where(fn ($q) => $q->whereNull('budget_line_id')->orWhereNotIn('budget_line_id', $known ?: [0]))->sum('base_amount'), '0', 2);
    }

    /** @return array{0:int,1:string} */
    private function unbudgeted(int $orgId, int $fyId, ?array $ccIds): array
    {
        $req = ExpenseRequest::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotIn('status', ExpenseRequest::TERMINAL)->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->whereNull('budget_line_id')->orWhere('over_budget', true))
            ->when($ccIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $ccIds ?: [0]));
        $act = Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fyId)->where('match_status', 'unbudgeted')
            ->when($ccIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $ccIds ?: [0]));

        return [(clone $req)->count() + (clone $act)->count(), bcadd(bcadd((string) (clone $req)->sum('base_total_amount'), (string) (clone $act)->sum('base_amount'), 2), '0', 2)];
    }

    /** @return array<string,int> */
    private function contractFigures(int $orgId, ?array $ccIds): array
    {
        $q = fn () => Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
            ->when($ccIds !== null, fn ($w) => $w->whereIn('cost_centre_id', $ccIds ?: [0]));
        $today = FinanceClock::today();

        return [
            'contracts_active' => $q()->live()->count(), 'renewals_due_30' => $q()->dueWithin(30)->count(), 'renewals_due_60' => $q()->dueWithin(60)->count(),
            'renewals_due_90' => $q()->dueWithin(90)->count(), 'contracts_passed' => $q()->passed($today)->count(), 'invoices_overdue' => $this->overdueInvoices($orgId, $ccIds),
        ];
    }

    /** @param array<int,int>|null $ccIds */
    public function overdueInvoices(int $orgId, ?array $ccIds = null): int
    {
        return Invoice::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('status', array_diff(Invoice::UNPAID, ['disputed']))
            ->whereDate('due_on', '<', FinanceClock::today()->toDateString())->when($ccIds !== null, fn ($w) => $w->whereIn('cost_centre_id', $ccIds ?: [0]))->count();
    }

    /** @return list<array<string,mixed>> */
    public function monthly(int $orgId, int $fiscalYearId, ?array $costCentreIds = null): array
    {
        $periods = $this->monitoring->periodsOf($orgId, $fiscalYearId)->where('is_adjustment', false);
        $asOf = $this->monitoring->asOfPeriod($orgId, $fiscalYearId);
        $lines = $this->monitoring->baselineLines($orgId, $fiscalYearId, $costCentreIds);
        $figs = $this->monitoring->lineFigures($lines, $fiscalYearId);
        $out = [];
        $cb = '0.00';
        $ca = '0.00';
        foreach ($periods as $pid => $p) {
            $b = '0.00';
            $a = '0.00';
            foreach ($figs as $f) {
                $b = bcadd($b, $f['by_period'][$pid]['budget'], 2);
                $a = bcadd($a, $f['by_period'][$pid]['actual'], 2);
            }
            $ended = $asOf && $p->sequence <= $asOf->sequence;
            $cb = bcadd($cb, $b, 2);
            $ended && $ca = bcadd($ca, $a, 2);
            $out[] = ['period_id' => (int) $pid, 'name' => $p->name, 'budget' => $b, 'actual' => $a, 'forecast' => $ended ? $a : $b, 'cumulative_budget' => $cb, 'cumulative_actual' => $ended ? $ca : null];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function spendByTower(int $orgId, int $fiscalYearId, ?array $costCentreIds = null): array
    {
        $lines = $this->monitoring->baselineLines($orgId, $fiscalYearId, $costCentreIds);
        $figs = $this->monitoring->lineFigures($lines, $fiscalYearId);
        $by = [];
        foreach ($lines as $l) {
            $r = &$by[(int) $l->tower_id];
            $r = ['budget' => bcadd($r['budget'] ?? '0.00', $figs[$l->id]['budget_fy'], 2), 'actual' => bcadd($r['actual'] ?? '0.00', $figs[$l->id]['actual_fy'], 2)];
            unset($r);
        }
        $cats = Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', array_keys($by))->get(['id', 'code', 'name'])->keyBy('id');
        $out = [];
        foreach ($by as $id => $v) {
            $out[] = ['id' => (int) $id, 'code' => $cats[$id]->code ?? null, 'name' => $cats[$id]->name ?? 'Not set'] + $v;
        }
        usort($out, fn ($a, $b) => bccomp($b['actual'], $a['actual'], 2));

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function topVariances(int $orgId, int $fiscalYearId, ?array $costCentreIds = null, int $n = 10): array
    {
        $asOf = $this->monitoring->asOfPeriod($orgId, $fiscalYearId);
        if (! $asOf) {
            return [];
        }
        $notes = VarianceNote::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $asOf->id)->where('is_breaching', true)
            ->when($costCentreIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $costCentreIds ?: [0]))->with(['period', 'line', 'costCentre:id,code,name'])->get()
            ->sortByDesc(fn ($x) => (float) ltrim((string) $x->variance_amount, '-'))->take($n)->values();

        return $notes->map(fn (VarianceNote $x) => [
            'note_id' => $x->id, 'line_id' => $x->budget_line_id, 'label' => $x->label, 'cost_centre' => $x->costCentre?->code, 'severity' => $x->severity, 'direction' => $x->direction,
            'variance' => (string) $x->variance_amount, 'variance_pct' => $x->variance_pct === null ? null : (string) $x->variance_pct,
            'explained' => in_array($x->status, ['explained', 'acknowledged'], true),
            'href' => route('finance.monitor.variances', ['fiscal_year_id' => $fiscalYearId, 'period_id' => $asOf->id], false),
        ])->all();
    }

    /** @return list<array<string,mixed>> */
    public function pendingApprovals(User $viewer, int $limit = 10): array
    {
        $rows = ApprovalRequest::withoutGlobalScopes()->where('organization_id', $viewer->organization_id)->where('status', 'pending')->where('process', 'like', 'finance.%')
            ->with(['steps', 'subject'])->latest('id')->limit(max(100, $limit * 10))->get();
        $gate = Gate::forUser($viewer);
        $out = $rows->map(function (ApprovalRequest $r) use ($viewer, $gate) {
            $step = $r->currentStep();
            $def = ApprovalProcesses::PROCESSES[$r->process] ?? [];
            $params = isset($def['route']) ? (app('router')->getRoutes()->getByName($def['route'])?->parameterNames() ?? []) : [];
            $mine = $step && $this->engine->canDecide($step, $r, $r->subject, $viewer)
                && ($viewer->can($def['permission'] ?? '') || (! empty($def['owner_stage_without_permission']) && $step->approver_type === 'owner'));

            // Cost-centre scoping: only requests the viewer may open, may decide, or started themselves.
            $visible = $mine || (int) $r->initiated_by === (int) $viewer->id || ($r->subject && $gate->allows('view', $r->subject));
            if (! $visible) {
                return null;
            }

            return ['id' => $r->id, 'process' => $r->process, 'process_label' => $def['label'] ?? $r->process, 'subject' => $r->subject_label,
                'url' => isset($def['route']) ? ($params ? route($def['route'], $r->subject_id, false) : route($def['route'], [], false)) : null,
                'step' => $step?->name, 'due_at' => $step?->due_at?->toIso8601String(), 'mine' => (bool) $mine];
        });

        return $out->filter()->sortBy(fn ($r) => $r['mine'] ? 0 : 1)->take($limit)->values()->all();
    }

    /** @return array<string,mixed> the board-pack finance section (plan §7.1) */
    public function boardFigures(int $orgId): array
    {
        $fy = $this->fy($orgId, null);
        if (! $fy) {
            return [];
        }
        $s = $this->summary($orgId, $fy->id);
        $asOf = $this->monitoring->asOfPeriod($orgId, $fy->id);
        $fcVar = bccomp($s['budget_total'], '0', 2) !== 0
            ? $this->phasing->round(bcmul(bcdiv(bcsub($s['forecast_fy'], $s['budget_total'], 2), $s['budget_total'], 8), '100', 8), 2) : null;
        $top = array_map(fn ($t) => ['label' => $t['label'], 'cost_centre' => $t['cost_centre'], 'severity' => $t['severity'], 'variance_pct' => $t['variance_pct'], 'explained' => $t['explained']],
            $this->topVariances($orgId, $fy->id, null, (int) config('finance.monitoring.top_variances', 10)));

        return [
            'fiscal_year' => $fy->year_label, 'as_of_period' => $asOf?->name, 'currency' => app(FinanceSettings::class)->baseCurrency($orgId),
            'budget_total' => $s['budget_total'], 'budget_ytd' => $s['budget_ytd'], 'actual_ytd' => $s['actual_ytd'], 'actual_unallocated' => $s['actual_unallocated'], 'variance_pct' => $s['variance_pct'], 'forecast_fy' => $s['forecast_fy'],
            'forecast_variance_pct' => $fcVar, 'committed' => $s['committed'], 'available' => $s['available'], 'unbudgeted_count' => $s['unbudgeted_count'],
            'unbudgeted_amount' => $s['unbudgeted_amount'], 'capex_opex_split' => $s['capex_opex_split'], 'rgt_split' => $s['rgt_split'], 'red' => $s['variances']['red'],
            'amber' => $s['variances']['amber'], 'unexplained_red' => $s['variances']['unexplained_red'], 'top_variances' => $top, 'renewals_due_90' => $s['renewals_due_90'],
            'contracts_passed' => $s['contracts_passed'], 'invoices_overdue' => $s['invoices_overdue'],
        ];
    }

    /** @return array<string,mixed>|null the board-pack investment section (plan §3.7); null when the bank has no investments */
    public function boardInvestmentFigures(int $orgId): ?array
    {
        $open = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('stage', Investment::OPEN_STAGES)->get();
        if ($open->isEmpty()) {
            return null;
        }
        $s = $this->portfolio->summary($orgId);
        $fin = $this->investmentFinancials->forInvestments($open);
        $ev = '0.00';
        $ac = '0.00';
        foreach ($open->filter(fn ($i) => $i->bac !== null && $i->percent_complete !== null) as $i) {
            $ev = bcadd($ev, bcmul((string) $i->bac, bcdiv((string) $i->percent_complete, '100', 8), 4), 4);
            $ac = bcadd($ac, $fin[$i->id]['ac'], 2);
        }
        $ben = Benefit::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('investment_id', $open->pluck('id'))->where('status', '!=', 'cancelled')->where('unit', 'currency')->get();
        $risk = $open->filter(fn ($i) => in_array($fin[$i->id]['health'] ?? null, ['at_risk', 'off_track'], true) || $i->is_late)
            ->map(fn ($i) => ['code' => $i->investment_code, 'title' => $i->title, 'status' => $i->status, 'health' => $fin[$i->id]['health'] ?? 'at_risk'])->values()->all();

        return [
            'as_of' => $s['as_of'], 'currency' => app(FinanceSettings::class)->baseCurrency($orgId), 'count_open' => $open->count(), 'by_stage' => $s['by_stage'],
            'rgt_split' => $s['rgt_split'], 'regulatory_share_pct' => $s['regulatory_share_pct'], 'bac_total' => $s['bac_total'], 'ac_total' => $s['ac_total'], 'eac_total' => $s['eac_total'],
            'cpi_portfolio' => bccomp($ac, '0', 2) > 0 ? bcadd(bcdiv($ev, $ac, 6), '0.005', 2) : null,
            'benefits_planned' => $ben->reduce(fn ($t, $b) => bcadd($t, (string) $b->target_value, 2), '0.00'),
            'benefits_realised' => $ben->reduce(fn ($t, $b) => bcadd($t, (string) ($b->realised_value ?? '0'), 2), '0.00'),
            'benefits_realised_pct' => $s['benefits_realised_pct'], 'at_risk' => $risk, 'reviews_due' => $s['reviews_due'], 'reviews_overdue' => $s['reviews_overdue'],
        ];
    }

    /** Counted actual-year-to-date total (used by the KRI source). */
    public function actualYtd(int $orgId): string
    {
        $fy = $this->fy($orgId, null);

        return $fy ? $this->summary($orgId, $fy->id)['actual_ytd'] : '0.00';
    }
}
