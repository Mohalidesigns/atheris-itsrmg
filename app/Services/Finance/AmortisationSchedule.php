<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The memo amortisation schedule of a capitalisation (plan P6-13). Straight line, or declining balance that switches to straight
 * line when that charges more; full-month convention; the last line absorbs rounding so the sum is exactly cost less residual.
 * Pure: no database.
 */
class AmortisationSchedule
{
    /** Mirrors config('finance.capitalisation.max_life_months'); the service validates against the config first. */
    public const MAX_MONTHS = 600;

    /**
     * @return list<array{line_no:int,month:string,opening_nbv:string,amount:string,closing_nbv:string,is_catch_up:bool}>
     */
    public static function build(string $amount, string $residual, int $months, string $method, ?string $decliningRatePct, string $startMonth, ?string $bookingMonth = null): array
    {
        if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $amount) || ! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $residual)) {
            throw new InvalidArgumentException('Amounts must be plain non-negative numbers with up to 2 decimals.');
        }
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw new InvalidArgumentException('The useful life must be between 1 and '.self::MAX_MONTHS.' months.');
        }
        if (bccomp($residual, $amount, 2) >= 0) {
            throw new InvalidArgumentException('The residual value must be less than the cost.');
        }
        if (! in_array($method, ['straight_line', 'declining'], true)) {
            throw new InvalidArgumentException('Unknown amortisation method.');
        }
        try {
            $start = CarbonImmutable::parse($startMonth)->startOfMonth();
            $booking = $bookingMonth ? CarbonImmutable::parse($bookingMonth)->startOfMonth() : null;
        } catch (\Throwable) {
            throw new InvalidArgumentException('Give valid months.');
        }
        $rate = null;
        if ($method === 'declining') {
            $rate = $decliningRatePct !== null && $decliningRatePct !== '' ? $decliningRatePct : bcdiv('2400', (string) $months, 6); // 2 / life years x 100
            if ($decliningRatePct === null || $decliningRatePct === '') {
                bccomp($rate, '100', 6) > 0 && $rate = '100'; // a short life would imply more than 100 percent a year
            }
            if (! preg_match('/^\d{1,3}(\.\d{1,6})?$/', $rate) || bccomp($rate, '0', 6) <= 0 || bccomp($rate, '100', 6) > 0) {
                throw new InvalidArgumentException('The declining rate must be above 0 and at most 100 percent a year.');
            }
        }

        $nbv = bcadd($amount, '0', 2);
        $floor = bcadd($residual, '0', 2);
        $lines = [];
        $fixed = self::round2(bcdiv(bcsub($nbv, $floor, 2), (string) $months, 8));
        for ($n = 1; $n <= $months; $n++) {
            $remaining = $months - $n + 1;
            $room = bcsub($nbv, $floor, 2);
            $sl = self::round2(bcdiv($room, (string) $remaining, 8));
            if ($method === 'straight_line') {
                $charge = $remaining === 1 ? $room : $fixed;
                bccomp($charge, $room, 2) > 0 && $charge = $room;
            } else {
                $db = self::round2(bcdiv(bcmul($nbv, $rate, 8), '1200', 8));
                $charge = $remaining === 1 ? $room : (bccomp($sl, $db, 2) >= 0 ? $sl : $db);
                bccomp($charge, $room, 2) > 0 && $charge = $room;
            }
            $closing = bcsub($nbv, $charge, 2);
            $month = $start->addMonths($n - 1);
            $lines[] = [
                'line_no' => $n, 'month' => $month->toDateString(), 'opening_nbv' => $nbv, 'amount' => $charge, 'closing_nbv' => $closing,
                'is_catch_up' => $booking !== null && $month->lessThan($booking),
            ];
            $nbv = $closing;
        }

        return $lines;
    }

    /** Round half up to 2 dp (bcmath truncates). */
    private static function round2(string $v): string
    {
        return bcadd($v, bccomp($v, '0', 8) >= 0 ? '0.005' : '-0.005', 2);
    }
}
