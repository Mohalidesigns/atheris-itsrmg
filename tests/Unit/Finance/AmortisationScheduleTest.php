<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\AmortisationSchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** FIN-03.13 (S): amortisation lines are straight-line or declining and always sum to cost less residual. */
class AmortisationScheduleTest extends TestCase
{
    private function sum(array $lines): string
    {
        return array_reduce($lines, fn ($c, $l) => bcadd($c, $l['amount'], 2), '0.00');
    }

    public function test_straight_line_last_line_absorbs_rounding(): void
    {
        $l = AmortisationSchedule::build('12000000.00', '0.00', 36, 'straight_line', null, '2027-01-01');

        $this->assertCount(36, $l);
        $this->assertSame('333333.33', $l[0]['amount']);
        $this->assertSame('333333.33', $l[34]['amount']);
        $this->assertSame('333333.45', $l[35]['amount']);
        $this->assertSame('12000000.00', $this->sum($l));
        $this->assertSame('0.00', $l[35]['closing_nbv']);
        $this->assertSame('12000000.00', $l[0]['opening_nbv']);
        $this->assertSame('2027-01-01', $l[0]['month']);
        $this->assertSame('2029-12-01', $l[35]['month']);
    }

    public function test_straight_line_with_residual(): void
    {
        $l = AmortisationSchedule::build('1000000.00', '100000.00', 24, 'straight_line', null, '2027-03-01');

        $this->assertSame('900000.00', $this->sum($l));
        $this->assertSame('100000.00', $l[23]['closing_nbv']);
    }

    public function test_declining_switches_to_straight_line_and_ends_at_residual(): void
    {
        $l = AmortisationSchedule::build('10000000.00', '0.00', 60, 'declining', '40', '2027-01-01');

        $this->assertSame('333333.33', $l[0]['amount']);
        $this->assertSame('322222.22', $l[1]['amount']); // 9,666,666.67 x 40% / 12
        $this->assertSame('10000000.00', $this->sum($l));
        $this->assertSame('0.00', $l[59]['closing_nbv']);
        $this->assertTrue(bccomp($l[0]['amount'], $l[59]['amount'], 2) > 0); // the charge falls over the life
        // After the switch the charge is level (straight line on what remains), not falling any more.
        $this->assertTrue(bccomp($l[40]['amount'], $l[45]['amount'], 0) <= 0 || $l[40]['amount'] === $l[45]['amount'] || bccomp(bcsub($l[40]['amount'], $l[45]['amount'], 2), '1', 2) < 0);
    }

    public function test_declining_default_rate_is_two_over_the_life(): void
    {
        $l = AmortisationSchedule::build('1200000.00', '0.00', 60, 'declining', null, '2027-01-01');

        $this->assertSame('40000.00', $l[0]['amount']); // 40 % a year = 2 / 5 years x 100
        $this->assertSame('1200000.00', $this->sum($l));
    }

    public function test_months_before_the_booking_month_are_catch_up(): void
    {
        $l = AmortisationSchedule::build('600000.00', '0.00', 12, 'straight_line', null, '2027-01-01', '2027-04-01');

        $this->assertSame([true, true, true, false], array_slice(array_column($l, 'is_catch_up'), 0, 4));
        $this->assertFalse($l[11]['is_catch_up']);
    }

    public function test_one_month_life(): void
    {
        $l = AmortisationSchedule::build('500.00', '0.00', 1, 'straight_line', null, '2027-05-17');

        $this->assertCount(1, $l);
        $this->assertSame('500.00', $l[0]['amount']);
        $this->assertSame('2027-05-01', $l[0]['month']);
    }

    public function test_invalid_inputs_are_refused(): void
    {
        foreach ([
            ['100.00', '0.00', 0, 'straight_line', null],
            ['100.00', '0.00', 601, 'straight_line', null],
            ['100.00', '100.00', 12, 'straight_line', null],
            ['abc', '0.00', 12, 'straight_line', null],
            ['100.00', '0.00', 12, 'sum_of_years', null],
            ['100.00', '0.00', 12, 'declining', '0'],
            ['100.00', '0.00', 12, 'declining', '250'],
        ] as [$amount, $residual, $months, $method, $rate]) {
            try {
                AmortisationSchedule::build($amount, $residual, $months, $method, $rate, '2027-01-01');
                $this->fail("Accepted {$amount} {$residual} {$months} {$method} {$rate}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
