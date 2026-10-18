<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\BusinessCaseCalculator as Calc;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** FIN-05.02: NPV, IRR, payback, ROI and TCO match the reference values (plan P5-9). Pure: no database, no container. */
class BusinessCaseCalculatorTest extends TestCase
{
    private function reference(): array
    {
        return Calc::evaluate(
            [['year' => 0, 'capex' => '1000000']],
            array_map(fn ($y) => ['year' => $y, 'amount' => '300000'], [1, 2, 3, 4, 5]),
            10,
            5,
        );
    }

    public function test_reference_case(): void // FIN-05.02
    {
        $r = $this->reference();
        $this->assertEqualsWithDelta(137236.03, (float) $r['npv'], 0.01);
        $this->assertEqualsWithDelta(15.24, (float) $r['irr_pct'], 0.01);
        $this->assertSame(40, $r['payback_months']);
        $this->assertSame('50.00', $r['roi_pct']);
        $this->assertSame('1000000.00', $r['tco']);
        $this->assertSame('1000000.00', $r['one_off_cost']);
        $this->assertSame('1500000.00', $r['total_benefit']);
        $this->assertCount(6, $r['cash_flows']);
        $this->assertFalse($r['irr_multiple']);
    }

    public function test_irr_null_without_sign_change(): void // FIN-05.02
    {
        $r = Calc::evaluate([['year' => 0, 'capex' => '100'], ['year' => 1, 'opex' => '50']], [], 10, 3);
        $this->assertNull($r['irr_pct']);
        $this->assertNull($r['payback_months']);
        $this->assertSame('-100.00', $r['roi_pct']);
        $this->assertTrue(bccomp($r['npv'], '0', 2) < 0);
    }

    public function test_all_positive_flows_have_no_irr(): void
    {
        $this->assertNull(Calc::irr(['100', '200', '300']));
    }

    public function test_multiple_sign_changes_flagged(): void
    {
        $r = Calc::evaluate(
            [['year' => 0, 'capex' => '1000'], ['year' => 2, 'capex' => '1500']],
            [['year' => 1, 'amount' => '2500'], ['year' => 3, 'amount' => '1000']],
            10,
            3,
        );
        $this->assertTrue($r['irr_multiple']);
    }

    public function test_payback_beyond_horizon_is_null(): void
    {
        $r = Calc::evaluate([['year' => 0, 'capex' => '1000']], [['year' => 1, 'amount' => '100']], 10, 3);
        $this->assertNull($r['payback_months']);
        $this->assertNull($r['discounted_payback_months']);
    }

    public function test_mid_year_interpolation_rounds_up(): void
    {
        // -1000 at year 0, +600 a year: -400 left after year 1, 400/600 of year 2 = 8 months -> 20.
        $this->assertSame(20, Calc::paybackMonths(['-1000', '600', '600']));
        $this->assertSame(20, Calc::paybackMonths(['-1000', '0', '1500']));
        // 1000/2500 of a year is 4.8 months, rounded up to 5 -> 12 + 5.
        $this->assertSame(17, Calc::paybackMonths(['-1000', '0', '2500']));
        $this->assertSame(0, Calc::paybackMonths(['100', '-50']));
    }

    public function test_discounted_payback_is_longer(): void
    {
        $r = $this->reference();
        $this->assertNotNull($r['discounted_payback_months']);
        $this->assertGreaterThan($r['payback_months'], $r['discounted_payback_months']);
    }

    public function test_zero_cost_gives_no_roi(): void
    {
        $r = Calc::evaluate([], [['year' => 1, 'amount' => '500']], 10, 2);
        $this->assertNull($r['roi_pct']);
    }

    public function test_rate_zero_npv_is_the_sum_of_net_flows(): void
    {
        $r = Calc::evaluate([['year' => 0, 'capex' => '1000']], [['year' => 1, 'amount' => '600'], ['year' => 2, 'amount' => '600']], 0, 2);
        $this->assertSame('200.00', $r['npv']);
        $this->assertSame('200.00', Calc::npv(['-1000', '600', '600'], '0'));
    }

    public function test_validation(): void
    {
        foreach ([
            fn () => Calc::evaluate([['year' => 6, 'capex' => '1']], [], 10, 5),
            fn () => Calc::evaluate([['year' => 1, 'capex' => '-5']], [], 10, 5),
            fn () => Calc::evaluate([['year' => 1, 'capex' => '1e5']], [], 10, 5),
            fn () => Calc::evaluate([], [['year' => 1, 'amount' => '1.234']], 10, 5),
            fn () => Calc::evaluate([], [], 101, 5),
            fn () => Calc::evaluate([], [], 10, 0),
            fn () => Calc::evaluate([], [], 10, 99),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("Case {$i} should have been refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
