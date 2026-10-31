<?php

namespace Tests\Feature\Finance;

use App\Http\Requests\Finance\BusinessCaseFormRequest;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\InvestmentFinancials;
use App\Services\Finance\InvestmentScoring;
use App\Services\Finance\StageGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/** P7 WP-C (P5 carry-overs): funded, decision re-check, delivered payload, benefit freeze, EVM, double-counted actuals, scoring SoD, input rules, audit causer. */
class P7InvestmentHardeningTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
        $this->head->update(['job_title' => 'Head of IT']);
    }

    private function refused(callable $fn, string $text): void
    {
        try {
            $fn();
            $this->fail("Expected a refusal containing \"{$text}\".");
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase($text, implode(' | ', array_merge(...array_values($e->errors()))));
        }
    }

    private function benefitData(array $over = []): array
    {
        return $over + ['title' => 'Fewer branch visits', 'type' => 'cost_saving', 'unit' => 'currency', 'aggregation' => 'sum', 'owner_id' => $this->sponsor->id,
            'baseline_value' => '0', 'target_value' => '500000', 'due_on' => '2027-12-31'];
    }

    // FIN-05 / P5-M4: one token line must not satisfy "funded"
    public function test_funded_needs_tagged_lines_to_cover_bac(): void
    {
        $i = $this->approvedInvestment([], '900000000000');
        $this->tagLines($i->refresh(), $this->monLines[0]);
        $funded = collect(app(StageGateService::class)->criteria($i->refresh(), 'in_delivery'))->firstWhere('key', 'funded');
        $this->assertFalse($funded['met']);
        $this->assertStringContainsString('gap', $funded['detail']);
        $this->refused(fn () => app(StageGateService::class)->advance($i->refresh(), 'in_delivery', ['delivery_plan'], [], null, $this->fin), 'Funded');
    }

    public function test_funded_passes_when_lines_cover_bac(): void
    {
        $this->assertSame('in_delivery', $this->inDelivery($this->approvedInvestment())->stage);
    }

    // FIN-05 / P5-M6, L9: the decision re-runs the auto criteria; the delivered payload reads actual_end
    public function test_decision_rechecks_auto_criteria_and_payload_reads_actual_end(): void
    {
        $i = $this->inDelivery($this->approvedInvestment());
        $gate = app(StageGateService::class)->advance($i->refresh(), 'delivered', ['acceptance', 'handover'], ['actual_ends_on' => '2027-04-03'], null, $this->fin);
        $this->assertSame('2027-04-03', $gate->payload['actual_ends_on']);

        $i->refresh()->forceFill(['starts_on' => '2027-04-04'])->save(); // the end date now precedes the start
        $this->refused(fn () => app(StageGateService::class)->approve($gate->refresh(), $this->headofit), 'no longer met');
        $this->assertSame('submitted', $gate->refresh()->status);
    }

    // FIN-05 / P5-M7, sec-M4, P7-14
    public function test_benefit_control_fields_freeze_after_approval_and_closed_takes_no_readings(): void
    {
        $svc = app(BenefitsService::class);
        $i = $this->inDelivery($this->approvedInvestment());
        $b = $svc->store($i, $this->benefitData(), $this->fin);

        $this->refused(fn () => $svc->update($b, ['target_value' => '900000'], $this->fin), 'frozen');
        $this->refused(fn () => $svc->update($b->refresh(), ['due_on' => '2028-01-31'], $this->fin), 'frozen');
        $this->refused(fn () => $svc->update($b->refresh(), ['unit' => 'count'], $this->fin), 'frozen');
        $this->assertSame('Fewer visits', $svc->update($b->refresh(), ['title' => 'Fewer visits', 'target_value' => '500000'], $this->fin)->title);

        $i->refresh()->forceFill(['stage' => 'closed'])->save();
        $this->refused(fn () => $svc->record($b->refresh(), Period::withoutGlobalScopes()->where('organization_id', $this->org->id)->firstOrFail(), '10', null, null, $this->fin), 'closed');
    }

    // FIN-05 / P5-M8, P7-12
    public function test_evm_edge_cases(): void
    {
        $m = new ReflectionMethod(InvestmentFinancials::class, 'measures');
        $call = fn (Investment $i, string $ac) => $m->invoke(app(InvestmentFinancials::class), $i, $ac);

        $i = $this->makeInvestment();
        $i->forceFill(['stage' => 'in_delivery', 'bac' => '1000.00', 'percent_complete' => '0', 'eac' => null]);
        $r = $call($i, '100.00');
        $this->assertSame(['0.00', '0.0000', '1100.00', 'off_track'], [$r['ev'], $r['cpi'], $r['eac'], $r['health']]);

        $i->forceFill(['stage' => 'delivered', 'percent_complete' => '40']);
        $r = $call($i, '500.00');
        $this->assertSame('1000.00', $r['ev'], 'once delivered, EV = BAC');
    }

    // P5-L4: an actual tagged to two investments is counted once
    public function test_actual_with_an_investment_is_not_counted_via_another_investments_line(): void
    {
        $a = $this->inDelivery($this->approvedInvestment());
        $b = $this->makeInvestment(['title' => 'Other']);
        $p = $this->periodSeq(1);
        $this->postActual($this->monLines[0], $p, '7000.00', ['investment_id' => $b->id]);
        $this->postActual($this->monLines[0], $p, '3000.00');
        $fin = app(InvestmentFinancials::class);

        $this->assertSame('3000.00', $fin->evm($a->refresh())['ac']);
        $this->assertSame('7000.00', $fin->evm($b->refresh())['ac']);
        $this->assertSame('3000.00', $fin->forInvestments(collect([$a->refresh()]))[$a->id]['ac']);
    }

    // FIN-05 / P5-M14, P7-13
    public function test_sponsor_and_manager_cannot_score_their_own_investment(): void
    {
        $i = $this->makeInvestment();
        $scores = array_fill_keys(Investment::CRITERIA, 3);
        $this->refused(fn () => app(InvestmentScoring::class)->score($i, $scores, $this->sponsor), 'sponsor');
        $this->refused(fn () => app(InvestmentScoring::class)->score($i, $scores, $this->manager), 'sponsor');
        $this->assertNotNull(app(InvestmentScoring::class)->score($i, $scores, $this->fin)->priority_score);
    }

    // P5-sec-L5
    public function test_business_case_inputs_are_typed_and_bounded(): void
    {
        $rules = (new BusinessCaseFormRequest)->rules();
        $bad = ['costs' => [['year' => 99, 'capex' => 'abc']], 'benefits' => [['year' => 1, 'amount' => '1e20']]];
        $errors = Validator::make($bad, $rules)->errors()->keys();
        $this->assertEqualsCanonicalizing(['costs.0.year', 'costs.0.capex', 'benefits.0.amount'], $errors);
        $this->assertTrue(Validator::make(['costs' => [['year' => 0, 'capex' => '1000.50', 'opex' => null]], 'benefits' => [['year' => 1, 'amount' => 20]]], $rules)->passes());
    }

    // FIN-07 / P5-sec-L6: rejection and withdrawal carry the actor
    public function test_business_case_rejection_audit_has_a_causer(): void
    {
        $i = $this->makeInvestment();
        $gates = app(StageGateService::class);
        $gates->advance($i, 'business_case', ['problem_agreed'], [], null, $this->fin);
        $this->makeBusinessCase($i->refresh());
        $this->scoreAll($i);
        $gate = $gates->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin);
        $gates->withdraw($gate, $this->fin);
        $gate = $gates->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin);
        $gates->reject($gate, $this->headofit, 'Benefits are not evidenced enough.');

        $bc = BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->firstOrFail();
        foreach (['withdrawn' => $this->fin, 'rejected' => $this->headofit] as $event => $who) {
            $row = Activity::query()->where('subject_type', BusinessCase::class)->where('subject_id', $bc->id)->where('event', $event)->first();
            $this->assertNotNull($row, $event);
            $this->assertSame($who->id, (int) $row->causer_id);
        }
    }
}
