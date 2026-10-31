<?php

namespace App\Services\Finance;

use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Pure bcmath phasing of an annual amount over a fiscal year's periods (FIN-02.04). */
class BudgetPhasing
{
    /** Regular (non-adjustment) periods of a fiscal year in order. */
    public function periodsFor(FiscalYear $fy): Collection
    {
        return Period::withoutGlobalScopes()->where('organization_id', $fy->organization_id)->where('fiscal_year_id', $fy->id)
            ->where('is_adjustment', false)->orderBy('sequence')->get();
    }

    /** @return array<int, string> weights by position 0..n-1 */
    public function weights(string $method, int $n, ?array $custom = null): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = match ($method) {
                'front' => (string) ($n - $i),
                'back' => (string) ($i + 1),
                'seasonal' => (string) (config('finance.phasing.seasonal')[$i] ?? 1),
                'custom' => (string) ($custom[$i] ?? 0),
                default => '1',
            };
        }

        return $out;
    }

    /**
     * Split $total by weights; each share is rounded half-up to $dp and the last period takes the
     * remainder so the shares add up to the total exactly.
     *
     * @param  array<int, string>  $weights
     * @return array<int, string>
     */
    public function spread(string $total, array $weights, int $dp = 2): array
    {
        $sum = '0';
        foreach ($weights as $w) {
            $sum = bcadd($sum, (string) $w, 8);
        }
        $n = count($weights);
        if ($n === 0) {
            return [];
        }
        if (bccomp($sum, '0', 8) <= 0) {
            $weights = array_fill(0, $n, '1');
            $sum = (string) $n;
        }

        $out = [];
        $running = '0';
        foreach (array_values($weights) as $i => $w) {
            if ($i === $n - 1) {
                $out[] = bcsub($total, $running, $dp);
                break;
            }
            $share = $this->round(bcdiv(bcmul($total, (string) $w, 18), $sum, 18), $dp);
            if (bccomp($total, '0', $dp) >= 0) { // rounding up must never overshoot what is left (P1 L2)
                $left = bcsub($total, $running, $dp);
                bccomp($share, $left, $dp) > 0 && $share = $left;
                bccomp($share, '0', $dp) < 0 && $share = bcadd('0', '0', $dp);
            }
            $out[] = $share;
            $running = bcadd($running, $share, $dp);
        }

        return $out;
    }

    /** @param array<int|string, string> $amounts */
    public function assertSums(string $annual, array $amounts, int $dp = 2): void
    {
        $sum = '0';
        foreach ($amounts as $a) {
            $sum = bcadd($sum, (string) $a, $dp);
        }
        if (bccomp($sum, bcadd($annual, '0', $dp), $dp) !== 0) {
            throw ValidationException::withMessages(['amounts' => "Phasing totals {$sum} but the annual amount is ".bcadd($annual, '0', $dp).'.']);
        }
    }

    public function round(string $value, int $dp): string
    {
        $half = $dp === 0 ? '0.5' : '0.'.str_repeat('0', $dp).'5';

        return str_starts_with($value, '-') ? bcsub($value, $half, $dp) : bcadd($value, $half, $dp);
    }
}
