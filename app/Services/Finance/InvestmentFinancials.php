<?php

namespace App\Services\Finance;

use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Models\Finance\StageGate;
use Illuminate\Support\Collection;

/**
 * Earned-value figures of an investment (plan P5-1 to P5-3). BAC is the approved one-off cost; allocated is what the tagged baseline
 * lines hold; AC is the life-to-date counted actuals tagged to the investment or posted against a tagged line's lineage; EV = BAC x
 * the manager's percent complete; CPI = EV / AC; EAC = BAC / CPI unless a manual override is set. Money is bcmath strings.
 */
class InvestmentFinancials
{
    public function __construct(private readonly BudgetLedger $ledger, private readonly BudgetAvailability $availability) {}

    /** Tagged lines plus every earlier line in their `source_line_id` chain. @return list<int> */
    public function lineIds(Investment $i): array
    {
        $ids = [];
        foreach (BudgetLine::withoutGlobalScopes()->where('organization_id', $i->organization_id)->where('investment_id', $i->id)->get() as $line) {
            $ids = array_merge($ids, $this->ledger->lineageIds($line));
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string,mixed> */
    public function evm(Investment $i): array
    {
        $tagged = BudgetLine::withoutGlobalScopes()->where('organization_id', $i->organization_id)->where('investment_id', $i->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $lineIds = $this->lineIds($i);
        $actuals = $this->actualsQuery((int) $i->organization_id, (int) $i->id, $lineIds)->get(['posted_on', 'base_amount']);
        $ac = $actuals->reduce(fn ($c, $a) => bcadd($c, (string) $a->base_amount, 2), '0.00');
        $allocated = $this->allocated($tagged);
        $committed = $lineIds ? $this->availability->committed($lineIds) : '0.00';

        $m = $this->measures($i, $ac);

        return [
            'bac' => $m['bac'], 'planned_cost' => $i->planned_cost === null ? null : bcadd((string) $i->planned_cost, '0', 2),
            'allocated' => $allocated, 'funding_gap' => $m['bac'] === null ? null : bcsub($m['bac'], $allocated, 2),
            'ac' => $ac, 'committed' => $committed, 'ev' => $m['ev'], 'percent_complete' => bcadd((string) $i->percent_complete, '0', 2),
            'cv' => $m['cv'], 'cpi' => $m['cpi'], 'eac' => $m['eac'], 'eac_method' => $m['eac_method'], 'vac' => $m['vac'], 'health' => $m['health'],
            'spend_by_stage' => $this->spendByStage($i, $actuals), 'spend_by_year' => $this->spendByYear($i, $actuals),
        ];
    }

    /**
     * Compact figures for many investments in a handful of grouped queries (plan R6).
     *
     * @param  Collection<int,Investment>  $investments
     * @return array<int,array{bac:?string,ac:string,eac:?string,cpi:?string,health:?string}>
     */
    public function forInvestments(Collection $investments): array
    {
        if ($investments->isEmpty()) {
            return [];
        }
        $map = $this->lineMap($investments); // investment id => list<int> lineage ids
        $lineOwner = [];
        foreach ($map as $invId => $ids) {
            foreach ($ids as $lid) {
                $lineOwner[$lid][] = $invId;
            }
        }
        $invIds = $investments->pluck('id')->map(fn ($v) => (int) $v)->all();
        $orgIds = $investments->pluck('organization_id')->unique()->all();
        $rows = Actual::withoutGlobalScopes()->whereIn('organization_id', $orgIds)->where('match_status', '!=', 'duplicate')
            ->where(function ($q) use ($invIds, $lineOwner) {
                $q->whereIn('investment_id', $invIds);
                $lineOwner && $q->orWhereIn('budget_line_id', array_keys($lineOwner));
            })->get(['investment_id', 'budget_line_id', 'base_amount']);
        $ac = array_fill_keys($invIds, '0.00');
        foreach ($rows as $a) {
            $targets = array_unique(array_filter(array_merge([$a->investment_id ? (int) $a->investment_id : null], $lineOwner[(int) $a->budget_line_id] ?? [])));
            foreach ($targets as $t) {
                isset($ac[$t]) && $ac[$t] = bcadd($ac[$t], (string) $a->base_amount, 2);
            }
        }
        $out = [];
        foreach ($investments as $i) {
            $m = $this->measures($i, $ac[(int) $i->id] ?? '0.00');
            $out[(int) $i->id] = ['bac' => $m['bac'], 'ac' => $ac[(int) $i->id] ?? '0.00', 'eac' => $m['eac'], 'cpi' => $m['cpi'], 'health' => $m['health']];
        }

        return $out;
    }

    /**
     * Lineage line ids per investment, resolved in a few queries.
     *
     * @param  Collection<int,Investment>  $investments
     * @return array<int,list<int>>
     */
    public function lineMap(Collection $investments): array
    {
        $tagged = BudgetLine::withoutGlobalScopes()->whereIn('investment_id', $investments->pluck('id'))->get(['id', 'investment_id', 'source_line_id']);
        $map = [];
        $frontier = [];
        foreach ($tagged as $l) {
            $map[(int) $l->investment_id][] = (int) $l->id;
            $l->source_line_id && $frontier[] = [(int) $l->investment_id, (int) $l->source_line_id];
        }
        for ($hop = 0; $hop < 25 && $frontier; $hop++) {
            $sources = BudgetLine::withoutGlobalScopes()->whereIn('id', array_unique(array_column($frontier, 1)))->pluck('source_line_id', 'id');
            $next = [];
            foreach ($frontier as [$invId, $lid]) {
                if (in_array($lid, $map[$invId] ?? [], true)) {
                    continue;
                }
                $map[$invId][] = $lid;
                $up = $sources[$lid] ?? null;
                $up && $next[] = [$invId, (int) $up];
            }
            $frontier = $next;
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $map);
    }

    public static function health(?string $cpi, bool $late): ?string
    {
        $cfg = config('finance.investments.health');
        if ($cpi !== null) {
            if (bccomp($cpi, (string) $cfg['cpi_off_track'], 4) < 0) {
                return 'off_track';
            }
            if (bccomp($cpi, (string) $cfg['cpi_at_risk'], 4) < 0) {
                return 'at_risk';
            }
        }

        return $late ? 'at_risk' : ($cpi === null ? null : 'on_track');
    }

    /** Sum of the current (baseline plus applied adjustments) base amounts of lines that sit in a baseline budget. @param list<int> $lineIds */
    public function allocated(array $lineIds): string
    {
        if (! $lineIds) {
            return '0.00';
        }
        $baselined = BudgetLine::withoutGlobalScopes()->whereIn('id', $lineIds)
            ->whereIn('budget_id', Budget::withoutGlobalScopes()->where('status', 'baseline')->select('id'))->pluck('id')->all();
        if (! $baselined) {
            return '0.00';
        }
        $base = (string) BudgetLinePeriod::withoutGlobalScopes()->whereIn('budget_line_id', $baselined)->sum('base_amount');
        $adj = (string) BudgetAdjustment::withoutGlobalScopes()->whereIn('budget_line_id', $baselined)->sum('base_amount');

        return bcadd(bcadd($base, $adj, 2), '0', 2);
    }

    /** @return array{bac:?string,ev:?string,cv:?string,cpi:?string,eac:?string,eac_method:string,vac:?string,health:?string} */
    private function measures(Investment $i, string $ac): array
    {
        $bac = $i->bac === null ? null : bcadd((string) $i->bac, '0', 2);
        $ev = $bac === null ? null : bcadd(bcdiv(bcmul($bac, (string) $i->percent_complete, 8), '100', 8), '0.005', 2);
        $cv = $ev === null ? null : bcsub($ev, $ac, 2);
        $cpi = $ev !== null && bccomp($ac, '0', 2) > 0 ? bcadd(bcdiv($ev, $ac, 6), '0', 4) : null;
        $manual = $i->eac !== null;
        $eac = $manual ? bcadd((string) $i->eac, '0', 2) : ($bac === null ? null : ($cpi !== null && bccomp($cpi, '0', 4) > 0 ? bcadd(bcdiv($bac, $cpi, 6), '0.005', 2) : $bac));
        $vac = $bac !== null && $eac !== null ? bcsub($bac, $eac, 2) : null;
        $started = in_array($i->stage, ['in_delivery', 'delivered', 'benefits_tracking'], true);

        return [
            'bac' => $bac, 'ev' => $ev, 'cv' => $cv, 'cpi' => $cpi, 'eac' => $eac, 'eac_method' => $manual ? 'manual' : 'computed', 'vac' => $vac,
            'health' => $started || $cpi !== null ? self::health($cpi, $i->is_late) : null,
        ];
    }

    /** @param list<int> $lineIds */
    private function actualsQuery(int $orgId, int $investmentId, array $lineIds)
    {
        return Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('match_status', '!=', 'duplicate')
            ->where(function ($q) use ($investmentId, $lineIds) {
                $q->where('investment_id', $investmentId);
                $lineIds && $q->orWhereIn('budget_line_id', $lineIds);
            });
    }

    /** Actuals bucketed by the stage the investment was in on the posting date, from the gates that passed. @return list<array{stage:string,amount:string}> */
    private function spendByStage(Investment $i, Collection $actuals): array
    {
        $gates = StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->whereIn('status', ['approved', 'passed'])->where('gate', '!=', 'change')
            ->whereNotNull('passed_at')->orderBy('passed_at')->get(['gate', 'passed_at']);
        $buckets = [];
        foreach ($actuals as $a) {
            $on = $a->posted_on->toDateString();
            $stage = 'idea';
            foreach ($gates as $g) {
                if ($g->passed_at->toDateString() <= $on) {
                    $stage = $g->gate;
                }
            }
            $buckets[$stage] = bcadd($buckets[$stage] ?? '0.00', (string) $a->base_amount, 2);
        }
        $out = [];
        foreach (Investment::STAGES as $stage) {
            isset($buckets[$stage]) && $out[] = ['stage' => $stage, 'amount' => $buckets[$stage]];
        }

        return $out;
    }

    /** Business-case cost per year against the actuals of the calendar year, the years counted from the start (or approval) year. @return list<array{year:int,planned:string,actual:string}> */
    private function spendByYear(Investment $i, Collection $actuals): array
    {
        $bc = BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->whereIn('status', ['approved', 'submitted', 'draft'])->orderByRaw("case status when 'approved' then 0 else 1 end")->orderByDesc('version_no')->first();
        $base = (int) ($i->starts_on?->year ?? $i->bac_baselined_at?->year ?? $i->created_at?->year ?? FinanceClock::today()->year);
        $planned = [];
        foreach ((array) ($bc?->costs ?? []) as $row) {
            $y = $base + (int) $row['year'];
            $planned[$y] = bcadd($planned[$y] ?? '0.00', bcadd(bcadd((string) ($row['capex'] ?? '0'), (string) ($row['opex'] ?? '0'), 2), (string) ($row['run'] ?? '0'), 2), 2);
        }
        $actual = [];
        foreach ($actuals as $a) {
            $y = (int) $a->posted_on->year;
            $actual[$y] = bcadd($actual[$y] ?? '0.00', (string) $a->base_amount, 2);
        }
        $years = array_unique(array_merge(array_keys($planned), array_keys($actual)));
        sort($years);

        return array_map(fn ($y) => ['year' => $y, 'planned' => $planned[$y] ?? '0.00', 'actual' => $actual[$y] ?? '0.00'], $years);
    }
}
