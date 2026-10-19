<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\CloudAnomalyService as S;
use PHPUnit\Framework\TestCase;

/** FIN-03.10 (S): the pure anomaly test (rolling mean and week-over-week, percentage and absolute floor). */
class CloudAnomalyEvaluateTest extends TestCase
{
    private const T = ['pct' => '30', 'abs_floor' => '100', 'baseline_days' => 7, 'min_baseline_days' => 5];

    /** @return array<string,string> ten flat days of 1,000 ending the day before $to */
    private function flat(string $to = '2026-08-10', string $cost = '1000'): array
    {
        $out = [];
        for ($i = 10; $i >= 1; $i--) {
            $out[date('Y-m-d', strtotime("$to -$i days"))] = $cost;
        }

        return $out;
    }

    public function test_rolling_mean_flags_a_spike(): void
    {
        $d = $this->flat() + ['2026-08-10' => '3400'];
        $r = S::evaluate($d, '2026-08-10', self::T);

        $this->assertNotNull($r);
        $this->assertSame('1000.00', $r['baseline']);
        $this->assertSame('3400.00', $r['observed']);
        $this->assertSame('240.00', $r['deviation_pct']);
    }

    public function test_week_over_week_catches_what_the_rolling_mean_dilutes(): void
    {
        // Mid-week days are high every week: rolling mean is high, but the same weekday last week is low.
        $d = $this->flat('2026-08-10', '2000');
        $d['2026-08-03'] = '1000'; // same weekday a week earlier
        $d['2026-08-10'] = '2500';
        $r = S::evaluate($d, '2026-08-10', self::T);

        $this->assertNotNull($r);
        $this->assertSame('week_over_week', $r['method']);
        $this->assertSame('150.00', $r['deviation_pct']);
    }

    public function test_rolling_wins_when_it_is_the_larger_deviation(): void
    {
        $d = $this->flat('2026-08-10', '1000');
        $d['2026-08-03'] = '2800'; // the week-earlier day was itself high
        $d['2026-08-10'] = '3000';
        $r = S::evaluate($d, '2026-08-10', self::T);

        $this->assertSame('rolling_7d', $r['method']);
    }

    public function test_fewer_than_five_baseline_days_is_not_tested_by_the_rolling_mean(): void
    {
        $d = ['2026-08-08' => '1000', '2026-08-09' => '1000', '2026-08-06' => '1000', '2026-08-05' => '1000', '2026-08-10' => '9000'];

        $this->assertNull(S::evaluate($d, '2026-08-10', self::T)); // four baseline days and nothing a week earlier
    }

    public function test_the_absolute_floor_must_also_be_met(): void
    {
        $d = $this->flat('2026-08-10', '100') + ['2026-08-10' => '160']; // +60 %, but only +60 in cost

        $this->assertNull(S::evaluate($d, '2026-08-10', self::T));
    }

    public function test_below_the_percentage_is_not_an_anomaly(): void
    {
        $d = $this->flat() + ['2026-08-10' => '1250'];

        $this->assertNull(S::evaluate($d, '2026-08-10', self::T));
    }

    public function test_a_day_with_no_data_is_not_tested(): void
    {
        $this->assertNull(S::evaluate($this->flat(), '2026-08-12', self::T));
    }

    public function test_billing_currency_inputs_make_a_pure_fx_move_invisible(): void
    {
        // The cost in USD is flat; only the base-currency figure would have jumped. The test sees USD only.
        $d = $this->flat('2026-08-10', '1000') + ['2026-08-10' => '1000'];

        $this->assertNull(S::evaluate($d, '2026-08-10', ['pct' => '30', 'abs_floor' => '0.01'] + self::T));
    }
}
