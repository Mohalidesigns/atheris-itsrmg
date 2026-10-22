<?php

namespace App\Services\Finance;

use InvalidArgumentException;

/**
 * The business-case arithmetic (plan P5-9): NPV, IRR, payback, ROI and TCO. Pure and static: no database, no container, so it is
 * testable on its own. Money is bcmath strings; floats are confined to {@see irr()} (plan R5).
 *
 * Year 0 is today and is not discounted. All amounts are base currency.
 */
class BusinessCaseCalculator
{
    private const SCALE = 12;

    /**
     * @param  list<array{year:int,capex?:mixed,opex?:mixed,run?:mixed}>  $costs
     * @param  list<array{year:int,amount?:mixed}>  $benefits
     * @return array{cash_flows:list<array<string,mixed>>,npv:string,irr_pct:?string,irr_multiple:bool,payback_months:?int,discounted_payback_months:?int,roi_pct:?string,tco:string,one_off_cost:string,total_cost:string,total_benefit:string}
     */
    public static function evaluate(array $costs, array $benefits, string|int|float $ratePct, int $horizon): array
    {
        $max = (int) self::cfg('finance.investments.max_horizon_years', 15);
        if ($horizon < 1 || $horizon > $max) {
            throw new InvalidArgumentException("The horizon must be between 1 and {$max} years.");
        }
        $rate = self::decimal($ratePct, 'The discount rate', 6);
        if (bccomp($rate, '0', 6) < 0 || bccomp($rate, '100', 6) > 0) {
            throw new InvalidArgumentException('The discount rate must be between 0 and 100 percent.');
        }

        $cost = array_fill(0, $horizon + 1, '0.00');
        $benefit = array_fill(0, $horizon + 1, '0.00');
        $oneOff = '0.00';
        foreach ($costs as $row) {
            $y = self::year($row['year'] ?? null, $horizon);
            foreach (['capex', 'opex', 'run'] as $k) {
                $v = self::amount($row[$k] ?? '0', "The {$k} cost of year {$y}");
                $cost[$y] = bcadd($cost[$y], $v, 2);
                $k !== 'run' && $oneOff = bcadd($oneOff, $v, 2);
            }
        }
        foreach ($benefits as $row) {
            $y = self::year($row['year'] ?? null, $horizon);
            $benefit[$y] = bcadd($benefit[$y], self::amount($row['amount'] ?? '0', "The benefit of year {$y}"), 2);
        }

        $net = [];
        for ($t = 0; $t <= $horizon; $t++) {
            $net[$t] = bcsub($benefit[$t], $cost[$t], 2);
        }

        $factor = bcadd('1', bcdiv($rate, '100', self::SCALE), self::SCALE);
        $rows = [];
        $cum = '0';
        $dcum = '0';
        $discounted = [];
        for ($t = 0; $t <= $horizon; $t++) {
            $d = $t === 0 ? $net[$t] : bcdiv($net[$t], bcpow($factor, (string) $t, self::SCALE), self::SCALE);
            $discounted[$t] = $d;
            $cum = bcadd($cum, $net[$t], 2);
            $dcum = bcadd($dcum, $d, self::SCALE);
            $rows[] = [
                'year' => $t, 'cost' => $cost[$t], 'benefit' => $benefit[$t], 'net' => $net[$t], 'discounted' => self::round($d),
                'cumulative' => $cum, 'discounted_cumulative' => self::round($dcum),
            ];
        }

        $totalCost = array_reduce($cost, fn ($c, $v) => bcadd($c, $v, 2), '0.00');
        $totalBenefit = array_reduce($benefit, fn ($c, $v) => bcadd($c, $v, 2), '0.00');
        $irr = self::irr(array_values($net));

        return [
            'cash_flows' => $rows,
            'npv' => self::round($dcum),
            'irr_pct' => $irr === null ? null : number_format($irr * 100, 4, '.', ''),
            'irr_multiple' => self::signChanges($net) > 1,
            'payback_months' => self::paybackMonths(array_values($net)),
            'discounted_payback_months' => self::paybackMonths(array_values($discounted)),
            'roi_pct' => bccomp($totalCost, '0', 2) > 0 ? self::round(bcmul(bcdiv(bcsub($totalBenefit, $totalCost, 2), $totalCost, self::SCALE), '100', self::SCALE)) : null,
            'tco' => $totalCost, 'one_off_cost' => $oneOff, 'total_cost' => $totalCost, 'total_benefit' => $totalBenefit,
        ];
    }

    /** @param list<string> $netFlows year 0 first */
    public static function npv(array $netFlows, string $ratePct): string
    {
        $factor = bcadd('1', bcdiv(self::decimal($ratePct, 'The discount rate', 6), '100', self::SCALE), self::SCALE);
        $sum = '0';
        foreach (array_values($netFlows) as $t => $v) {
            $sum = bcadd($sum, $t === 0 ? (string) $v : bcdiv((string) $v, bcpow($factor, (string) $t, self::SCALE), self::SCALE), self::SCALE);
        }

        return self::round($sum);
    }

    /** Internal rate of return as a fraction (0.1524), or null when the flows never change sign or no root is found. */
    public static function irr(array $netFlows): ?float
    {
        $flows = array_map('floatval', array_values($netFlows));
        if (self::signChanges($flows) < 1) {
            return null;
        }
        $cfg = (array) self::cfg('finance.investments.irr', []) + ['guess' => 0.10, 'max_iterations' => 100, 'tolerance' => 1e-10, 'low' => -0.99, 'high' => 10.0];
        $f = function (float $r) use ($flows): float {
            $s = 0.0;
            foreach ($flows as $t => $v) {
                $s += $v / ((1 + $r) ** $t);
            }

            return $s;
        };
        $df = function (float $r) use ($flows): float {
            $s = 0.0;
            foreach ($flows as $t => $v) {
                $t > 0 && $s -= $t * $v / ((1 + $r) ** ($t + 1));
            }

            return $s;
        };

        $r = (float) $cfg['guess'];
        for ($i = 0; $i < (int) $cfg['max_iterations']; $i++) {
            $d = $df($r);
            if (abs($d) < 1e-14) {
                break;
            }
            $next = $r - $f($r) / $d;
            if (! is_finite($next) || $next <= -0.999999) {
                break;
            }
            if (abs($next - $r) < (float) $cfg['tolerance']) {
                return abs($f($next)) < 1e-4 * max(1.0, array_sum(array_map('abs', $flows))) ? $next : self::bisect($f, $cfg);
            }
            $r = $next;
        }

        return self::bisect($f, $cfg);
    }

    /** Whole months to pay back, rounded up, linear within the year; 0 when year 0 already pays back; null when never. */
    public static function paybackMonths(array $netFlows): ?int
    {
        $cum = '0';
        $flows = array_values($netFlows);
        foreach ($flows as $t => $v) {
            $prev = $cum;
            $cum = bcadd($cum, (string) $v, self::SCALE);
            if (bccomp($cum, '0', self::SCALE) >= 0) {
                if ($t === 0 || bccomp($prev, '0', self::SCALE) >= 0) {
                    return 0;
                }
                $months = bcdiv(bcmul(bcsub('0', $prev, self::SCALE), '12', self::SCALE), (string) $v, self::SCALE);
                $whole = (int) bcadd($months, '0', 0);

                return ($t - 1) * 12 + $whole + (bccomp($months, (string) $whole, self::SCALE) > 0 ? 1 : 0);
            }
        }

        return null;
    }

    /** @param callable(float):float $f */
    private static function bisect(callable $f, array $cfg): ?float
    {
        $lo = (float) $cfg['low'];
        $hi = (float) $cfg['high'];
        $flo = $f($lo);
        $fhi = $f($hi);
        if (! is_finite($flo) || ! is_finite($fhi) || $flo * $fhi > 0) {
            return null;
        }
        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            $fm = $f($mid);
            if (abs($fm) < 1e-9 || ($hi - $lo) < (float) $cfg['tolerance']) {
                return $mid;
            }
            if ($flo * $fm < 0) {
                $hi = $mid;
            } else {
                $lo = $mid;
                $flo = $fm;
            }
        }

        return ($lo + $hi) / 2;
    }

    /** @param array<int,mixed> $flows */
    private static function signChanges(array $flows): int
    {
        $last = 0;
        $changes = 0;
        foreach ($flows as $v) {
            $s = (float) $v <=> 0.0;
            if ($s === 0) {
                continue;
            }
            $last !== 0 && $s !== $last && $changes++;
            $last = $s;
        }

        return $changes;
    }

    /** Config with a default, so the calculator also runs outside a booted application (unit tests). */
    private static function cfg(string $key, mixed $default): mixed
    {
        return function_exists('app') && app()->bound('config') ? config($key, $default) : $default;
    }

    private static function year(mixed $y, int $horizon): int
    {
        if (! is_int($y) && ! (is_string($y) && ctype_digit($y))) {
            throw new InvalidArgumentException('Every cost and benefit row needs a whole-number year.');
        }
        $y = (int) $y;
        if ($y < 0 || $y > $horizon) {
            throw new InvalidArgumentException("Year {$y} is outside the horizon (0 to {$horizon}).");
        }

        return $y;
    }

    private static function amount(mixed $v, string $label): string
    {
        $s = self::decimal($v === null || $v === '' ? '0' : $v, $label, 2);
        if (bccomp($s, '0', 2) < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }

        return bcadd($s, '0', 2);
    }

    private static function decimal(mixed $v, string $label, int $scale): string
    {
        $s = is_int($v) ? (string) $v : (is_float($v) ? rtrim(rtrim(number_format($v, $scale, '.', ''), '0'), '.') : (is_string($v) ? trim($v) : ''));
        if (! preg_match('/^-?\d{1,16}(\.\d{1,'.$scale.'})?$/', $s)) {
            throw new InvalidArgumentException("{$label} must be a plain number with up to {$scale} decimals.");
        }

        return $s === '' ? '0' : $s;
    }

    /** Half-up rounding to 2 places of a bcmath string. */
    private static function round(string $v): string
    {
        $neg = str_starts_with($v, '-');
        $abs = ltrim($v, '-');
        $r = bcadd($abs, '0.005', self::SCALE);
        $r = bcadd($r, '0', 2);

        return ($neg && bccomp($r, '0', 2) > 0 ? '-' : '').$r;
    }
}
