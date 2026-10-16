<?php

namespace App\Services\Finance;

use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\Period;
use App\Models\User;

/** Current budget = baseline amounts + the signed adjustments of applied transfers and supplementary requests (plan C22). */
class BudgetLedger
{
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

    /** Current base amount available in a period, or the whole year. P3 subtracts committed and actual here. */
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
