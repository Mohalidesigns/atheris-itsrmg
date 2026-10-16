<?php

namespace App\Services\Finance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Today" for contract derivation, sweeps, the digest hour and the calendar. The app runs on UTC but
 * Nigerian banks run on WAT, so the day flips at the finance timezone (plan C73). Honours Carbon::setTestNow.
 */
class FinanceClock
{
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('finance.timezone'));
    }

    /** Whole days from the finance "today" (or $from) to a date; negative when the date is past. */
    public static function daysUntil(CarbonInterface|string|null $date, ?CarbonInterface $from = null): ?int
    {
        if ($date === null) {
            return null;
        }
        $ymd = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);
        $base = ($from ?? self::today())->toDateString();

        return (int) round((strtotime($ymd.' UTC') - strtotime($base.' UTC')) / 86400);
    }
}
