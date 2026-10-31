<?php

namespace App\Services\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\Period;
use App\Models\User;
use Illuminate\Support\Collection;

/** Current budget = baseline amounts + the signed adjustments of applied transfers and supplementary requests (plan C22). */
class BudgetLedger
{
    /**
     * Source-line and fiscal-year maps of a bank's budget lines, for lineage walks (read once per request).
     *
     * @return array{sources: Collection, lineFy: Collection}
     */
    public static function lineageMaps(int $orgId): array
    {
        return RequestMemo::remember("lineageMaps:{$orgId}", function () use ($orgId) {
            $budgetFy = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('fiscal_year_id', 'id');

            return [
                'sources' => BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotNull('source_line_id')->pluck('source_line_id', 'id'),
                'lineFy' => BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('budget_id', 'id')->map(fn ($b) => $budgetFy[$b] ?? null),
            ];
        });
    }

    /** @return array<int, string> period_id => current base amount */
    public function currentByPeriod(BudgetLine $line): array
    {
        $out = [];
        foreach (BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $line->id)->get() as $p) {
            $out[$p->period_id] = bcadd((string) $p->base_amount, '0', 2);
        }
        $rows = BudgetAdjustment::withoutGlobalScopes()->where('budget_line_id', $line->id)->get();
        foreach ($rows as $a) {
            $out[$a->period_id] = bcadd($out[$a->period_id] ?? '0', (string) $a->base_amount, 2);
        }

        return $out;
    }

    /**
     * currentByPeriod for many lines in two queries.
     *
     * @param  iterable<int|BudgetLine>  $lines  lines or line ids
     * @return array<int, array<int, string>> line_id => [period_id => current base amount]
     */
    public function currentByPeriodMany(iterable $lines): array
    {
        $ids = collect($lines)->map(fn ($l) => (int) ($l instanceof BudgetLine ? $l->id : $l))->unique()->values();
        $out = $ids->mapWithKeys(fn ($id) => [$id => []])->all();
        if ($ids->isEmpty()) {
            return $out;
        }
        foreach (BudgetLinePeriod::withoutGlobalScopes()->whereIn('budget_line_id', $ids)->get() as $p) {
            $out[$p->budget_line_id][$p->period_id] = bcadd((string) $p->base_amount, '0', 2);
        }
        foreach (BudgetAdjustment::withoutGlobalScopes()->whereIn('budget_line_id', $ids)->get() as $a) {
            $out[$a->budget_line_id][$a->period_id] = bcadd($out[$a->budget_line_id][$a->period_id] ?? '0', (string) $a->base_amount, 2);
        }

        return $out;
    }

    /**
     * BudgetLine::current_base_amount for many lines in one query.
     *
     * @param  iterable<BudgetLine>  $lines
     * @return array<int, string> line_id => base annual amount + adjustments
     */
    public function currentBaseAmounts(iterable $lines): array
    {
        $lines = collect($lines);
        $adj = $lines->isEmpty() ? collect() : BudgetAdjustment::withoutGlobalScopes()->whereIn('budget_line_id', $lines->pluck('id'))
            ->selectRaw('budget_line_id, sum(base_amount) as total')->groupBy('budget_line_id')->pluck('total', 'budget_line_id');

        return $lines->mapWithKeys(fn (BudgetLine $l) => [$l->id => bcadd((string) $l->base_annual_amount, bcadd((string) ($adj[$l->id] ?? '0'), '0', 2), 2)])->all();
    }

    /** @return array<int, string> period_id => current amount in the line's own currency (period amount + adjustment amount) */
    public function currentByPeriodLine(BudgetLine $line): array
    {
        $out = [];
        foreach (BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $line->id)->get() as $p) {
            $out[$p->period_id] = bcadd((string) $p->amount, '0', 2);
        }
        foreach (BudgetAdjustment::withoutGlobalScopes()->where('budget_line_id', $line->id)->get() as $a) {
            $out[$a->period_id] = bcadd($out[$a->period_id] ?? '0', (string) $a->amount, 2);
        }

        return $out;
    }

    /**
     * The line's id plus every earlier line in its `source_line_id` chain (a re-baseline re-creates lines), so commitments and
     * actuals made against an earlier baseline still count against the current one (plan P3-1).
     *
     * @return list<int>
     */
    public function lineageIds(BudgetLine $line, bool $sameYear = false): array
    {
        $ids = [(int) $line->id];
        $source = $line->source_line_id;
        $fy = $sameYear ? Budget::withoutGlobalScopes()->whereKey($line->budget_id)->value('fiscal_year_id') : null;
        for ($hops = 0; $source && $hops < 25 && ! in_array((int) $source, $ids, true); $hops++) {
            $src = BudgetLine::withoutGlobalScopes()->whereKey($source)->first(['id', 'budget_id', 'source_line_id']);
            if (! $src || ($sameYear && (int) Budget::withoutGlobalScopes()->whereKey($src->budget_id)->value('fiscal_year_id') !== (int) $fy)) {
                break; // a prior fiscal year's copy never feeds this year's commitments
            }
            $ids[] = (int) $source;
            $source = $src->source_line_id;
        }

        return $ids;
    }

    /** Current budget (baseline plus applied adjustments) of a period, or the whole year. Committed and actual spend are BudgetAvailability. */
    public function available(BudgetLine $line, ?Period $period = null): string
    {
        $by = $this->currentByPeriod($line);
        if ($period) {
            return $by[$period->id] ?? '0.00';
        }
        $sum = '0';
        foreach ($by as $v) {
            $sum = bcadd($sum, $v, 2);
        }

        return bcadd($sum, '0', 2);
    }

    /** Regular periods of a fiscal year that are still open for posting, in order. */
    public function openPeriods(int $orgId, int $fiscalYearId)
    {
        return Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)
            ->where('is_adjustment', false)->where('status', 'open')->orderBy('sequence')->get();
    }

    /** Current base amount drawable from a line: the sum over its open periods. */
    public function availableInOpenPeriods(BudgetLine $line, int $fiscalYearId): string
    {
        $by = $this->currentByPeriod($line);
        $sum = '0';
        foreach ($this->openPeriods((int) $line->organization_id, $fiscalYearId) as $p) {
            $sum = bcadd($sum, $by[$p->id] ?? '0', 2);
        }

        return bcadd($sum, '0', 2);
    }

    public function post(BudgetLine $line, Period $period, string $baseAmount, string $sourceType, int $sourceId, User $by, ?string $lineAmount = null): BudgetAdjustment
    {
        $rate = (string) $line->fx_rate;
        $amount = $lineAmount ?? (bccomp($rate, '0', 8) > 0 ? app(BudgetPhasing::class)->round(bcdiv($baseAmount, $rate, 12), 2) : $baseAmount);

        return BudgetAdjustment::withoutGlobalScopes()->create([
            'organization_id' => $line->organization_id, 'created_by_id' => $by->id, 'budget_line_id' => $line->id, 'period_id' => $period->id,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'amount' => $amount, 'base_amount' => $baseAmount,
            'applied_by_id' => $by->id, 'applied_at' => now(),
        ]);
    }
}
