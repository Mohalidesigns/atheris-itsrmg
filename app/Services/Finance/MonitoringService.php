<?php

namespace App\Services\Finance;

use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\PurchaseOrder;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Budget monitoring (FIN-04.01, .06): the budget-vs-actual matrix over the current baseline lines, grouped and drillable,
 * with a reconciliation against the ledger. Everything is grouped queries (no per-line or per-period N+1) and in base
 * currency (bcmath strings). Committed is annual and as of today (plan P4-9).
 */
class MonitoringService
{
    public const GROUP_BY = ['cost_centre', 'tower', 'cost_pool', 'category', 'gl_account', 'legal_entity', 'vendor', 'rgt', 'capex', 'line'];

    public const DRILL = ['cost_centre' => 'tower', 'tower' => 'category', 'category' => 'line', 'cost_pool' => 'line', 'gl_account' => 'line',
        'legal_entity' => 'cost_centre', 'vendor' => 'line', 'rgt' => 'cost_centre', 'capex' => 'cost_centre', 'line' => null];

    /** group_by => the filter that narrows to one of its groups. */
    public const FILTER_OF = ['cost_centre' => 'cost_centre_id', 'tower' => 'tower_id', 'cost_pool' => 'cost_pool_id', 'category' => 'category_id',
        'gl_account' => 'gl_account_id', 'legal_entity' => 'legal_entity_id', 'vendor' => 'vendor_id', 'rgt' => 'rgt', 'capex' => 'capex'];

    public const VIEWS = ['period', 'ytd', 'fy'];

    private const GROUP_LABELS = ['cost_centre' => 'Cost centre', 'tower' => 'TBM tower', 'cost_pool' => 'Cost pool', 'category' => 'Category', 'gl_account' => 'GL account',
        'legal_entity' => 'Legal entity', 'vendor' => 'Vendor', 'rgt' => 'Run / Grow / Transform', 'capex' => 'CapEx / OpEx', 'line' => 'Budget line'];

    public function __construct(
        private readonly BudgetAvailability $availability,
        private readonly ForecastService $forecasts,
        private readonly VarianceService $variances,
        private readonly FinanceSettings $settings,
    ) {}

    /** The fiscal year a request means: the given one, else the bank's current, else its latest open one. */
    public function resolveFiscalYear(int $orgId, ?int $id): ?FiscalYear
    {
        $q = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at');
        if ($id) {
            return (clone $q)->find($id);
        }

        return (clone $q)->where('is_current', true)->first()
            ?? (clone $q)->where('status', 'open')->orderByDesc('starts_on')->first()
            ?? (clone $q)->orderByDesc('starts_on')->first();
    }

    /** @return Collection<int, Period> id => period, in sequence (the adjustment period last) */
    public function periodsOf(int $orgId, int $fiscalYearId): Collection
    {
        return Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->orderBy('sequence')->get()->keyBy('id');
    }

    /** The last regular period that ended before today (plan P4-7). */
    public function asOfPeriod(int $orgId, int $fiscalYearId, ?CarbonInterface $today = null): ?Period
    {
        $d = ($today ?? FinanceClock::today())->toDateString();

        return Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->where('is_adjustment', false)
            ->whereDate('ends_on', '<', $d)->orderByDesc('sequence')->first();
    }

    /**
     * Current baseline lines of the fiscal year, narrowed to visible cost centres and the dimension filters.
     *
     * @param  array<int,int>|null  $costCentreIds
     * @param  array<string,mixed>  $filters
     * @return Collection<int, BudgetLine>
     */
    public function baselineLines(int $orgId, int $fiscalYearId, ?array $costCentreIds = null, array $filters = []): Collection
    {
        $budgets = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->where('status', 'baseline')->whereNull('deleted_at')->select('id');
        $q = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('budget_id', $budgets);
        if ($costCentreIds !== null) {
            $q->whereIn('cost_centre_id', $costCentreIds ?: [0]);
        }
        if (! empty($filters['cost_centre_id'])) {
            $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) $filters['cost_centre_id']);
            $ids = $cc ? array_merge([(int) $cc->id], $this->descendants($orgId, (int) $cc->id)) : [0];
            $q->whereIn('cost_centre_id', $ids);
        }
        foreach (['tower_id', 'cost_pool_id', 'category_id', 'gl_account_id', 'legal_entity_id', 'vendor_id'] as $f) {
            ! empty($filters[$f]) && $q->where($f, (int) $filters[$f]);
        }
        ! empty($filters['rgt']) && $q->where('rgt', $filters['rgt']);
        if (isset($filters['capex']) && $filters['capex'] !== '' && $filters['capex'] !== null) {
            $q->where('is_capex', in_array($filters['capex'], [true, 1, '1', 'capex', 'true'], true));
        }

        return $q->orderBy('budget_id')->orderBy('line_no')->get();
    }

    /** @return list<int> */
    public function descendants(int $orgId, int $id): array
    {
        $byParent = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotNull('parent_id')->get(['id', 'parent_id'])->groupBy('parent_id');
        $found = [];
        $queue = [$id];
        while ($queue) {
            foreach ($byParent->get(array_shift($queue), collect()) as $child) {
                if (! in_array((int) $child->id, $found, true)) {
                    $found[] = (int) $child->id;
                    $queue[] = (int) $child->id;
                }
            }
        }

        return $found;
    }

    /**
     * Same-fiscal-year lineage of each line (a re-baseline re-creates lines): line id => [id, source, ..., root].
     *
     * @param  Collection<int, BudgetLine>  $lines
     * @return array<int, list<int>>
     */
    public function lineage(int $orgId, Collection $lines): array
    {
        $sources = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotNull('source_line_id')->pluck('source_line_id', 'id');
        $budgetFy = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('fiscal_year_id', 'id');
        $lineFy = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('budget_id', 'id')->map(fn ($b) => $budgetFy[$b] ?? null);
        $out = [];
        foreach ($lines as $l) {
            $ids = [(int) $l->id];
            $src = $sources[$l->id] ?? null;
            for ($h = 0; $src && $h < 25 && ! in_array((int) $src, $ids, true) && ($lineFy[$src] ?? null) === ($lineFy[$l->id] ?? null); $h++) {
                $ids[] = (int) $src;
                $src = $sources[$src] ?? null;
            }
            $out[(int) $l->id] = $ids;
        }

        return $out;
    }

    /**
     * Budget and counted actuals per line and period (bulk), plus the sums for the sequence range and the whole year.
     *
     * @param  Collection<int, BudgetLine>  $lines
     * @return array<int, array{by_period:array<int,array{budget:string,actual:string}>,budget:string,actual:string,budget_fy:string,actual_fy:string}>
     */
    public function lineFigures(Collection $lines, int $fiscalYearId, ?int $fromSeq = null, ?int $toSeq = null): array
    {
        if ($lines->isEmpty()) {
            return [];
        }
        $orgId = (int) $lines->first()->organization_id;
        $periods = $this->periodsOf($orgId, $fiscalYearId);
        $lineIds = $lines->pluck('id')->map(fn ($i) => (int) $i)->all();
        $lineage = $this->lineage($orgId, $lines);
        $owner = [];
        foreach ($lineage as $lineId => $ids) {
            foreach ($ids as $id) {
                $owner[$id] ??= $lineId;
            }
        }

        $out = [];
        foreach ($lineIds as $id) {
            $out[$id] = ['by_period' => [], 'budget' => '0.00', 'actual' => '0.00', 'budget_fy' => '0.00', 'actual_fy' => '0.00'];
            foreach ($periods as $pid => $p) {
                $out[$id]['by_period'][$pid] = ['budget' => '0.00', 'actual' => '0.00'];
            }
        }
        $add = function (int $lineId, int $periodId, string $key, string $v) use (&$out) {
            if (isset($out[$lineId]['by_period'][$periodId])) {
                $out[$lineId]['by_period'][$periodId][$key] = bcadd($out[$lineId]['by_period'][$periodId][$key], $v, 2);
            }
        };
        foreach (BudgetLinePeriod::withoutGlobalScopes()->whereIn('budget_line_id', $lineIds)->selectRaw('budget_line_id, period_id, sum(base_amount) as t')->groupBy('budget_line_id', 'period_id')->get() as $r) {
            $add((int) $r->budget_line_id, (int) $r->period_id, 'budget', (string) $r->t);
        }
        foreach (BudgetAdjustment::withoutGlobalScopes()->whereIn('budget_line_id', $lineIds)->selectRaw('budget_line_id, period_id, sum(base_amount) as t')->groupBy('budget_line_id', 'period_id')->get() as $r) {
            $add((int) $r->budget_line_id, (int) $r->period_id, 'budget', (string) $r->t);
        }
        $all = array_keys($owner);
        foreach (Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->whereIn('budget_line_id', $all)
            ->where('match_status', '!=', 'duplicate')->selectRaw('budget_line_id, period_id, sum(base_amount) as t')->groupBy('budget_line_id', 'period_id')->get() as $r) {
            $add($owner[(int) $r->budget_line_id], (int) $r->period_id, 'actual', (string) $r->t);
        }
        foreach ($out as $id => $row) {
            foreach ($row['by_period'] as $pid => $v) {
                $seq = (int) $periods[$pid]->sequence;
                foreach (['budget', 'actual'] as $k) {
                    $out[$id][$k.'_fy'] = bcadd($out[$id][$k.'_fy'], $v[$k], 2);
                    if (($fromSeq === null || $seq >= $fromSeq) && ($toSeq === null || $seq <= $toSeq)) {
                        $out[$id][$k] = bcadd($out[$id][$k], $v[$k], 2);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Fills in defaults: fiscal year, view, group-by and period range.
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function normalise(int $orgId, array $filters): array
    {
        $fy = $this->resolveFiscalYear($orgId, ! empty($filters['fiscal_year_id']) ? (int) $filters['fiscal_year_id'] : null);
        $view = in_array($filters['view'] ?? null, self::VIEWS, true) ? $filters['view'] : 'ytd';
        $group = in_array($filters['group_by'] ?? null, self::GROUP_BY, true) ? $filters['group_by'] : 'cost_centre';
        $out = ['fiscal_year_id' => $fy?->id, 'from_period_id' => null, 'to_period_id' => null, 'view' => $view, 'group_by' => $group];
        foreach (['cost_centre_id', 'tower_id', 'cost_pool_id', 'category_id', 'gl_account_id', 'legal_entity_id', 'vendor_id', 'rgt', 'capex'] as $f) {
            $out[$f] = isset($filters[$f]) && $filters[$f] !== '' ? $filters[$f] : null;
        }
        if (! $fy) {
            return $out;
        }
        $periods = $this->periodsOf($orgId, $fy->id);
        $regular = $periods->where('is_adjustment', false);
        $asOf = $this->asOfPeriod($orgId, $fy->id);
        $first = $periods->first();
        $last = $asOf ?? $regular->last() ?? $periods->last();
        $from = ! empty($filters['from_period_id']) ? $periods->get((int) $filters['from_period_id']) : null;
        $to = ! empty($filters['to_period_id']) ? $periods->get((int) $filters['to_period_id']) : null;
        if ($view === 'fy') {
            $from = $first;
            $to = $periods->last();
        } elseif ($view === 'ytd') {
            $from = $first;
            $to ??= $last;
        } else {
            $from ??= $first;
            $to ??= $last;
        }
        if ($from && $to && $from->sequence > $to->sequence) {
            [$from, $to] = [$to, $from];
        }
        $out['from_period_id'] = $from?->id;
        $out['to_period_id'] = $to?->id;

        return $out;
    }

    /**
     * The budget-vs-actual matrix (plan §7.2). The caller supplies raw filters; cost-centre visibility is applied here.
     *
     * @param  array<string,mixed>  $filters
     * @return array{filters:array<string,mixed>,breadcrumbs:array,thresholds:array<string,string>,matrix:array<string,mixed>,fiscal_year:?FiscalYear}
     */
    public function bva(int $orgId, array $filters, User $viewer): array
    {
        $f = $this->normalise($orgId, $filters);
        $thresholds = $this->variances->thresholds($orgId);
        $fy = $f['fiscal_year_id'] ? FiscalYear::withoutGlobalScopes()->find($f['fiscal_year_id']) : null;
        if (! $fy) {
            $zero = $this->emptyRow('total', 'Total');

            return ['filters' => $f, 'breadcrumbs' => $this->breadcrumbs($orgId, $f), 'thresholds' => $this->thresholdsOut($thresholds), 'fiscal_year' => null,
                'matrix' => ['group_by' => $f['group_by'], 'periods' => [], 'rows' => [], 'unallocated' => null, 'totals' => $zero,
                    'reconciliation' => ['ledger_actual' => '0.00', 'matrix_actual' => '0.00', 'difference' => '0.00']]];
        }
        $periods = $this->periodsOf($orgId, $fy->id);
        $from = $periods->get((int) $f['from_period_id']);
        $to = $periods->get((int) $f['to_period_id']);
        $fromSeq = $from?->sequence ?? 1;
        $toSeq = $to?->sequence ?? 99;
        $rangePeriods = $periods->filter(fn ($p) => $p->sequence >= $fromSeq && $p->sequence <= $toSeq);
        $today = FinanceClock::today()->toDateString();

        $visible = FinancePolicy::visibleCostCentreIds($viewer);
        $dimFilters = array_filter(Arr::only($f, ['cost_centre_id', 'tower_id', 'cost_pool_id', 'category_id', 'gl_account_id', 'legal_entity_id', 'vendor_id', 'rgt', 'capex']), fn ($v) => $v !== null && $v !== '');
        $lines = $this->baselineLines($orgId, $fy->id, $visible, $f);
        $scoped = $visible !== null || $dimFilters !== [];
        $figures = $this->lineFigures($lines, $fy->id, $fromSeq, $toSeq);
        $avail = $this->availability->forLines($lines);
        $eac = $this->forecasts->eacForLines($lines, $fy->id);
        $labels = $this->labelsFor($orgId, $f['group_by'], $lines);

        $rows = [];
        foreach ($lines as $l) {
            $key = $this->groupKey($f['group_by'], $l);
            $row = $rows[$key] ??= $this->groupRow($f['group_by'], $key, $labels, $l, $rangePeriods);
            $fg = $figures[$l->id];
            $row['budget'] = bcadd($row['budget'], $fg['budget'], 2);
            $row['actual'] = bcadd($row['actual'], $fg['actual'], 2);
            $row['committed'] = bcadd($row['committed'], (string) ($avail[$l->id]['committed'] ?? '0'), 2);
            $row['available'] = bcadd($row['available'], (string) ($avail[$l->id]['available'] ?? '0'), 2);
            $row['forecast'] = bcadd($row['forecast'], (string) ($eac[$l->id]['eac'] ?? '0'), 2);
            foreach ($rangePeriods as $pid => $p) {
                $cell = &$row['by_period'][$pid];
                $cell['budget'] = bcadd($cell['budget'], $fg['by_period'][$pid]['budget'], 2);
                $cell['actual'] = bcadd($cell['actual'], $fg['by_period'][$pid]['actual'], 2);
                unset($cell);
            }
            if ($f['group_by'] === 'line') {
                $row['line_thresholds'] = $this->variances->thresholds($orgId, $l);
            }
            $rows[$key] = $row;
        }
        $rows = array_values(array_map(fn ($r) => $this->finish($r, $thresholds), $rows));
        usort($rows, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));

        $unallocated = null;
        $matrixActual = array_reduce($rows, fn ($c, $r) => bcadd($c, $r['actual'], 2), '0.00');
        $rangeIds = $rangePeriods->keys()->all();
        if (! $scoped) {
            $knownIds = $this->allLineageIds($orgId, $lines);
            $un = (string) Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fy->id)->where('match_status', '!=', 'duplicate')
                ->whereIn('period_id', $rangeIds ?: [0])->where(fn ($q) => $q->whereNull('budget_line_id')->orWhereNotIn('budget_line_id', $knownIds ?: [0]))->sum('base_amount');
            $unallocated = $this->emptyRow('unallocated', 'Unallocated actuals', 'Unmatched, unbudgeted and out-of-scope postings');
            $unallocated['actual'] = bcadd($un, '0', 2);
            $unallocated = $this->finish($unallocated, $thresholds, false);
            $matrixActual = bcadd($matrixActual, $unallocated['actual'], 2);
            $ledger = bcadd((string) Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fy->id)->where('match_status', '!=', 'duplicate')
                ->whereIn('period_id', $rangeIds ?: [0])->sum('base_amount'), '0', 2);
        } else {
            $knownIds = $this->allLineageIds($orgId, $lines);
            $ledger = bcadd((string) Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fy->id)->where('match_status', '!=', 'duplicate')
                ->whereIn('period_id', $rangeIds ?: [0])->whereIn('budget_line_id', $knownIds ?: [0])->sum('base_amount'), '0', 2);
        }

        $totals = $this->emptyRow('total', 'Total');
        foreach (array_merge($rows, $unallocated ? [$unallocated] : []) as $r) {
            foreach (['budget', 'committed', 'actual', 'available', 'forecast'] as $k) {
                $totals[$k] = bcadd($totals[$k], $r[$k], 2);
            }
            foreach ($r['by_period'] as $pid => $c) {
                $t = &$totals['by_period'][$pid];
                $t = ['budget' => bcadd($t['budget'] ?? '0.00', $c['budget'], 2), 'actual' => bcadd($t['actual'] ?? '0.00', $c['actual'], 2)];
                unset($t);
            }
        }
        foreach ($rangePeriods as $pid => $p) {
            $totals['by_period'][$pid] ??= ['budget' => '0.00', 'actual' => '0.00'];
        }
        $totals = $this->finish($totals, $thresholds);

        return [
            'filters' => $f, 'breadcrumbs' => $this->breadcrumbs($orgId, $f), 'thresholds' => $this->thresholdsOut($thresholds), 'fiscal_year' => $fy,
            'matrix' => [
                'group_by' => $f['group_by'],
                'periods' => $rangePeriods->map(fn (Period $p) => ['id' => $p->id, 'name' => $p->name, 'sequence' => $p->sequence, 'status' => $p->status,
                    'elapsed' => $p->ends_on->toDateString() < $today])->values()->all(),
                'rows' => $rows, 'unallocated' => $unallocated, 'totals' => $totals,
                'reconciliation' => ['ledger_actual' => $ledger, 'matrix_actual' => $matrixActual, 'difference' => bcsub($ledger, $matrixActual, 2)],
            ],
        ];
    }

    /** @return list<int> */
    public function allLineageIds(int $orgId, Collection $lines): array
    {
        $ids = [];
        foreach ($this->lineage($orgId, $lines) as $chain) {
            array_push($ids, ...$chain);
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string,mixed> */
    private function emptyRow(string $key, string $label, ?string $sublabel = null): array
    {
        return ['key' => $key, 'label' => $label, 'sublabel' => $sublabel, 'drill' => null, 'line_id' => null, 'budget' => '0.00', 'committed' => '0.00', 'actual' => '0.00',
            'available' => '0.00', 'forecast' => '0.00', 'variance' => '0.00', 'variance_pct' => null, 'severity' => null, 'by_period' => []];
    }

    /** @param array<string,mixed> $row @param array<string,string> $thresholds */
    private function finish(array $row, array $thresholds, bool $classify = true): array
    {
        $t = $row['line_thresholds'] ?? $thresholds;
        unset($row['line_thresholds']);
        $c = VarianceService::classify($row['budget'], $row['actual'], $t['amber_pct'], $t['red_pct'], $t['abs_floor']);
        $row['variance'] = $c['variance'];
        $row['variance_pct'] = $c['pct'];
        $row['severity'] = $classify ? $c['severity'] : null;
        foreach ($row['by_period'] as $pid => $cell) {
            $row['by_period'][$pid] = ['budget' => $cell['budget'], 'actual' => $cell['actual'], 'variance' => bcsub($cell['actual'], $cell['budget'], 2)];
        }

        return $row;
    }

    /** @param Collection<int, Period> $rangePeriods */
    private function groupRow(string $groupBy, string $key, array $labels, BudgetLine $l, Collection $rangePeriods): array
    {
        [$label, $sub] = $labels[$key] ?? ['—', null];
        $row = $this->emptyRow($key, $label, $sub);
        foreach ($rangePeriods as $pid => $p) {
            $row['by_period'][$pid] = ['budget' => '0.00', 'actual' => '0.00'];
        }
        if ($groupBy === 'line') {
            $row['line_id'] = (int) $l->id;
        } elseif (($next = self::DRILL[$groupBy] ?? null) && $key !== 'none') {
            $value = match ($groupBy) {
                'capex' => $key, 'rgt' => $key, default => (int) $key,
            };
            $row['drill'] = ['param' => self::FILTER_OF[$groupBy], 'value' => $value, 'next_group_by' => $next];
        }

        return $row;
    }

    private function groupKey(string $groupBy, BudgetLine $l): string
    {
        return match ($groupBy) {
            'cost_centre' => (string) ($l->cost_centre_id ?? 'none'), 'tower' => (string) ($l->tower_id ?? 'none'), 'cost_pool' => (string) ($l->cost_pool_id ?? 'none'),
            'category' => (string) ($l->category_id ?? 'none'), 'gl_account' => (string) ($l->gl_account_id ?? 'none'), 'legal_entity' => (string) ($l->legal_entity_id ?? 'none'),
            'vendor' => (string) ($l->vendor_id ?? 'none'), 'rgt' => (string) $l->rgt, 'capex' => $l->is_capex ? 'capex' : 'opex', default => (string) $l->id,
        };
    }

    /** @return array<string, array{0:string,1:?string}> key => [label, sublabel] */
    private function labelsFor(int $orgId, string $groupBy, Collection $lines): array
    {
        $scope = fn (string $class, array $ids, array $cols) => $class::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', array_values(array_unique(array_filter($ids))))->get($cols)->keyBy('id');
        $out = [];
        switch ($groupBy) {
            case 'cost_centre':
                foreach ($scope(CostCentre::class, $lines->pluck('cost_centre_id')->all(), ['id', 'code', 'name']) as $id => $c) {
                    $out[(string) $id] = [$c->code.' '.$c->name, null];
                }
                break;
            case 'tower': case 'cost_pool': case 'category':
                $col = $groupBy.'_id';
                foreach ($scope(Category::class, $lines->pluck($col)->all(), ['id', 'code', 'name']) as $id => $c) {
                    $out[(string) $id] = [$c->name, $c->code];
                }
                break;
            case 'gl_account':
                foreach ($scope(GlAccount::class, $lines->pluck('gl_account_id')->all(), ['id', 'code', 'name']) as $id => $c) {
                    $out[(string) $id] = [$c->code.' '.$c->name, null];
                }
                break;
            case 'legal_entity':
                foreach (LegalEntity::withoutGlobalScopes()->whereIn('id', array_filter($lines->pluck('legal_entity_id')->all()))->get(['id', 'name']) as $c) {
                    $out[(string) $c->id] = [$c->name, null];
                }
                break;
            case 'vendor':
                foreach (Vendor::withoutGlobalScopes()->whereIn('id', array_filter($lines->pluck('vendor_id')->all()))->get(['id', 'name']) as $c) {
                    $out[(string) $c->id] = [$c->name, null];
                }
                break;
            case 'rgt':
                foreach (BudgetLine::RGT as $r) {
                    $out[$r] = [ucfirst($r), null];
                }
                break;
            case 'capex':
                $out = ['capex' => ['CapEx', null], 'opex' => ['OpEx', null]];
                break;
            default:
                $codes = Budget::withoutGlobalScopes()->whereIn('id', $lines->pluck('budget_id')->unique())->pluck('budget_code', 'id');
                $cc = $scope(CostCentre::class, $lines->pluck('cost_centre_id')->all(), ['id', 'code']);
                foreach ($lines as $l) {
                    $out[(string) $l->id] = ["#{$l->line_no} {$l->description}", ($codes[$l->budget_id] ?? '').' · '.($cc[$l->cost_centre_id]->code ?? '')];
                }
        }
        $out['none'] = ['Not set', null];

        return $out;
    }

    /** @param array<string,string> $t @return array<string,string> */
    private function thresholdsOut(array $t): array
    {
        return ['amber_pct' => $t['amber_pct'], 'red_pct' => $t['red_pct'], 'abs_floor' => $t['abs_floor']];
    }

    /** @return list<array{label:string,params:array<string,mixed>}> */
    private function breadcrumbs(int $orgId, array $f): array
    {
        $base = ['fiscal_year_id' => $f['fiscal_year_id'], 'from_period_id' => $f['from_period_id'], 'to_period_id' => $f['to_period_id'], 'view' => $f['view']];
        $crumbs = [['label' => 'All', 'params' => $base + ['group_by' => 'cost_centre']]];
        $params = $base;
        foreach (array_flip(self::FILTER_OF) as $filter => $group) {
            if (($f[$filter] ?? null) === null || $f[$filter] === '') {
                continue;
            }
            $params[$filter] = $f[$filter];
            $crumbs[] = ['label' => self::GROUP_LABELS[$group].': '.$this->labelOf($orgId, $group, $f[$filter]), 'params' => $params + ['group_by' => self::DRILL[$group] ?? 'line']];
        }

        return $crumbs;
    }

    private function labelOf(int $orgId, string $group, mixed $value): string
    {
        return match ($group) {
            'cost_centre' => (string) (($c = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) $value)) ? $c->code.' '.$c->name : $value),
            'tower', 'cost_pool', 'category' => (string) (Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey((int) $value)->value('name') ?? $value),
            'gl_account' => (string) (($g = GlAccount::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) $value)) ? $g->code.' '.$g->name : $value),
            'legal_entity' => (string) (LegalEntity::withoutGlobalScopes()->whereKey((int) $value)->value('name') ?? $value),
            'vendor' => (string) (Vendor::withoutGlobalScopes()->whereKey((int) $value)->value('name') ?? $value),
            'rgt' => ucfirst((string) $value),
            'capex' => in_array($value, [true, 1, '1', 'capex', 'true'], true) ? 'CapEx' : 'OpEx',
            default => (string) $value,
        };
    }

    /** @return list<array{key:string,label:string}> */
    public function groupings(): array
    {
        return array_map(fn ($k) => ['key' => $k, 'label' => self::GROUP_LABELS[$k]], self::GROUP_BY);
    }

    /** One line's postings and open commitments (plan §7.2 transactions JSON). */
    public function transactions(BudgetLine $line, ?int $fromPeriodId, ?int $toPeriodId): array
    {
        $orgId = (int) $line->organization_id;
        $fyId = (int) Budget::withoutGlobalScopes()->whereKey($line->budget_id)->value('fiscal_year_id');
        $periods = $this->periodsOf($orgId, $fyId);
        $from = $fromPeriodId ? ($periods->get($fromPeriodId)?->sequence ?? 1) : 1;
        $to = $toPeriodId ? ($periods->get($toPeriodId)?->sequence ?? 99) : 99;
        $ids = $periods->filter(fn ($p) => $p->sequence >= $from && $p->sequence <= $to)->keys()->all();
        $lineage = $this->lineage($orgId, collect([$line]))[(int) $line->id] ?? [(int) $line->id];
        $codes = Budget::withoutGlobalScopes()->whereKey($line->budget_id)->value('budget_code');

        $actuals = Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fyId)->whereIn('budget_line_id', $lineage)->where('match_status', '!=', 'duplicate')
            ->whereIn('period_id', $ids ?: [0])->with(['vendor:id,name'])->orderBy('posted_on')->orderBy('id')->get();
        $rows = $actuals->map(fn (Actual $a) => [
            'id' => $a->id, 'posted_on' => $a->posted_on->toDateString(), 'period' => $periods[$a->period_id]->name ?? null, 'source' => $a->source, 'source_ref' => $a->source_ref,
            'description' => $a->description, 'vendor' => $a->vendor?->name, 'base_amount' => (string) $a->base_amount,
            'href' => $a->invoice_id ? route('finance.invoices.show', $a->invoice_id, false) : null,
        ])->all();
        $total = $actuals->reduce(fn ($c, $a) => bcadd($c, (string) $a->base_amount, 2), '0.00');

        $commitments = [];
        $committed = '0.00';
        $requests = ExpenseRequest::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('budget_line_id', $lineage)->where('status', 'approved')->get(['id', 'request_code', 'title', 'base_total_amount']);
        $invoiced = $requests->isEmpty() ? collect() : Invoice::withoutGlobalScopes()->whereIn('expense_request_id', $requests->pluck('id'))->whereNull('po_id')->whereIn('status', ['approved', 'scheduled', 'paid'])
            ->selectRaw('expense_request_id, sum(base_total_amount) as t')->groupBy('expense_request_id')->pluck('t', 'expense_request_id');
        foreach ($requests as $r) {
            $left = bcsub((string) $r->base_total_amount, (string) ($invoiced[$r->id] ?? '0'), 2);
            if (bccomp($left, '0', 2) > 0) {
                $commitments[] = ['type' => 'expense', 'code' => $r->request_code, 'title' => $r->title, 'remaining_base' => $left, 'href' => route('finance.expenses.show', $r->id, false)];
                $committed = bcadd($committed, $left, 2);
            }
        }
        foreach (PurchaseOrder::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('budget_line_id', $lineage)->whereIn('status', PurchaseOrder::OPEN)->get() as $po) {
            $left = $this->availability->remainingForPo($po);
            if (bccomp($left, '0', 2) > 0) {
                $commitments[] = ['type' => 'po', 'code' => $po->po_code, 'title' => (string) ($po->expenseRequest?->title ?? $po->po_code), 'remaining_base' => $left, 'href' => route('finance.purchase-orders.show', $po->id, false)];
                $committed = bcadd($committed, $left, 2);
            }
        }

        return [
            'line' => ['id' => (int) $line->id, 'label' => trim("{$codes} #{$line->line_no} {$line->description}")],
            'actuals' => $rows, 'commitments' => $commitments, 'totals' => ['actual' => $total, 'committed' => $committed],
        ];
    }

    /** The EAC of one line (spec §5.6 name). @return array<string,mixed> */
    public function forecast(BudgetLine $line): array
    {
        return $this->forecasts->eac($line);
    }
}
