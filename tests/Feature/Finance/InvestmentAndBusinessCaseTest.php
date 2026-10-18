<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentChange;
use App\Models\Finance\InvestmentScore;
use App\Models\Finance\Setting;
use App\Models\Finance\ValueReview;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\BusinessCaseService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\InvestmentChangeService;
use App\Services\Finance\InvestmentFinancials;
use App\Services\Finance\InvestmentScoring;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\StageGateService;
use App\Services\Finance\ValueReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/**
 * Plan P5 §5.2: the investment lifecycle, business cases, scoring and ranking, earned value, change control, hold and cancel, and who may
 * see an investment. Everything runs through the services on the P4 bank ("today" 2027-04-05, NGN, FY2027).
 */
class InvestmentAndBusinessCaseTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
    }

    private function gates(): StageGateService
    {
        return app(StageGateService::class);
    }

    /** An idea that has reached the business-case stage with a complete draft case and all five scores, ready to ask for approval. */
    private function readyForApproval(array $over = [], string $cost = '1000000'): Investment
    {
        $i = $this->makeInvestment($over);
        $this->gates()->advance($i, 'business_case', ['problem_agreed'], [], null, $this->fin);
        $this->makeBusinessCase($i->refresh(), [['year' => 0, 'capex' => $cost, 'opex' => '0', 'run' => '0']]);
        $this->scoreAll($i);

        return $i->refresh();
    }

    /** FIN-05.04: the stage moves one gate at a time and a gate whose criteria are unmet is refused with the reason. */
    public function test_gate_criteria_enforced(): void
    {
        $i = $this->makeInvestment();
        $this->assertRefused(fn () => $this->gates()->advance($i, 'approved', [], [], null, $this->fin), 'gate', 'business_case'); // cannot skip a gate
        $this->assertRefused(fn () => $this->gates()->advance($i, 'business_case', [], [], null, $this->fin), 'criteria', 'Problem statement'); // manual criterion not ticked
        $gate = $this->gates()->advance($i, 'business_case', ['problem_agreed'], [], null, $this->fin);
        $this->assertSame('passed', $gate->status);
        $this->assertSame('business_case', $i->refresh()->stage);

        $this->assertRefused(fn () => $this->gates()->advance($i, 'approved', ['options_considered'], [], null, $this->fin), 'criteria', 'Prepare a business case'); // no case yet
        $this->makeBusinessCase($i);
        $this->assertRefused(fn () => $this->gates()->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin), 'criteria', 'criteria scored'); // an auto criterion unmet
        $this->scoreAll($i);
        $this->assertRefused(fn () => $this->gates()->advance($i->refresh(), 'approved', [], [], null, $this->fin), 'criteria', 'Options considered');
        $this->assertSame('business_case', $i->refresh()->stage);
    }

    /** FIN-05.04: a regulatory investment needs its regulatory reference before the approval gate. */
    public function test_regulatory_investment_needs_its_reference(): void
    {
        $i = $this->makeInvestment(['driver' => 'regulatory', 'is_regulatory' => true]);
        $this->gates()->advance($i, 'business_case', ['problem_agreed'], [], null, $this->fin);
        $this->makeBusinessCase($i->refresh());
        $this->scoreAll($i);
        $this->assertRefused(fn () => $this->gates()->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin), 'criteria', 'Regulatory reference');
        app(InvestmentService::class)->update($i->refresh(), ['regulatory_reference' => 'CBN Risk-Based Cybersecurity Framework'], $this->fin);
        $this->assertSame('submitted', $this->gates()->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin)->status);
    }

    /** FIN-05.04: the whole lifecycle idea to closed, each later gate enforcing its own criteria. */
    public function test_lifecycle_idea_to_closed(): void
    {
        $i = $this->approvedInvestment();
        $this->assertSame('approved', $i->stage);
        $this->assertRefused(fn () => $this->gates()->advance($i, 'in_delivery', ['delivery_plan'], [], null, $this->fin), 'criteria', 'Funded');
        $this->tagLines($i, $this->monLines[0]);
        $this->assertRefused(fn () => $this->gates()->advance($i->refresh(), 'in_delivery', [], [], null, $this->fin), 'criteria', 'Delivery plan');
        $this->assertSame('in_delivery', $this->inDelivery($i->refresh())->stage);

        $this->assertRefused(fn () => $this->gates()->advance($i->refresh(), 'delivered', ['acceptance', 'handover'], [], null, $this->fin), 'criteria', 'actual end date');
        $this->assertRefused(fn () => $this->gates()->advance($i, 'delivered', ['acceptance', 'handover'], ['actual_ends_on' => '2027-06-01'], null, $this->fin), 'criteria', 'future');
        $gate = $this->gates()->advance($i, 'delivered', ['acceptance', 'handover'], ['actual_ends_on' => '2027-04-04'], null, $this->fin);
        $this->assertSame('in_delivery', $i->refresh()->stage); // waits for the approval
        $this->approveGate($gate, $this->headofit);
        $this->assertSame('delivered', $i->refresh()->stage);
        $this->assertSame(3, ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->count());

        $this->assertRefused(fn () => $this->gates()->advance($i, 'benefits_tracking', [], [], null, $this->fin), 'criteria', 'benefit');
        app(BenefitsService::class)->store($i, ['title' => 'Fewer failed payments', 'type' => 'customer', 'unit' => 'count', 'target_value' => '100', 'owner_id' => $this->manager->id, 'due_on' => '2027-03-01'], $this->fin);
        $this->assertSame('passed', $this->gates()->advance($i->refresh(), 'benefits_tracking', [], [], null, $this->fin)->status);

        $this->assertRefused(fn () => $this->gates()->advance($i->refresh(), 'closed', ['lessons'], [], null, $this->fin), 'criteria', 'value review'); // reviews still open
        foreach (ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->get() as $r) {
            app(ValueReviewService::class)->complete($r, ['summary' => 'Reviewed with the benefit owner; the benefit was not delivered.'], $this->sponsor);
        }
        $this->assertRefused(fn () => $this->gates()->advance($i->refresh(), 'closed', ['lessons'], [], null, $this->fin), 'criteria', 'final status'); // the benefit is past due but not yet derived
        app(BenefitsService::class)->deriveAll($this->org->id);
        $this->assertRefused(fn () => $this->gates()->advance($i->refresh(), 'closed', [], [], null, $this->fin), 'criteria', 'Lessons');
        $this->gates()->advance($i->refresh(), 'closed', ['lessons'], [], null, $this->fin);
        $this->assertSame('closed', $i->refresh()->stage);
        $this->assertRefused(fn () => $this->gates()->advance($i, 'closed', ['lessons'], [], null, $this->fin), 'gate'); // no gate after closed
    }

    /** FIN-05.04: with configured stages the stage does not move until the last stage approves. */
    public function test_gate_advance_requires_approval(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $this->cio->assignRole('IT Steering Committee');
        $i = $this->readyForApproval();
        $gate = $this->gates()->advance($i, 'approved', ['options_considered'], [], null, $this->fin);
        $this->assertSame('business_case', $i->refresh()->stage);
        $this->assertSame('submitted', BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->first()->status);

        $msg = $this->gates()->approve($gate, $this->headofit);
        $this->assertStringContainsString('awaiting', $msg);
        $this->assertSame('business_case', $i->refresh()->stage);
        $this->assertNull($i->bac);
        $this->gates()->approve($gate->refresh(), $this->cfo);
        $this->assertSame('business_case', $i->refresh()->stage);
        $this->gates()->approve($gate->refresh(), $this->cio); // the steering stage is last for a case under the board threshold
        $this->assertSame('approved', $i->refresh()->stage);
        $this->assertSame('approved', $gate->refresh()->status);
        $this->assertSame('1000000.00', (string) $i->bac);
    }

    /** FIN-05.04: a rejection keeps the stage, rejects the case, and a second version can be approved. */
    public function test_reject_keeps_stage_and_a_second_version_can_be_approved(): void
    {
        $i = $this->readyForApproval();
        $gate = $this->gates()->advance($i, 'approved', ['options_considered'], [], null, $this->fin);
        $this->assertRefused(fn () => $this->gates()->reject($gate, $this->headofit, ' '), 'note');
        $this->gates()->reject($gate, $this->headofit, 'Benefits are not evidenced.');
        $this->assertSame('business_case', $i->refresh()->stage);
        $v1 = BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->where('version_no', 1)->first();
        $this->assertSame('rejected', $v1->status);
        $this->assertSame('Benefits are not evidenced.', $v1->rejection_reason);
        $this->assertSame('rejected', $gate->refresh()->status);

        $v2 = app(BusinessCaseService::class)->createVersion($i, $this->fin);
        $this->assertSame(2, $v2->version_no);
        $this->assertSame($v1->costs, $v2->costs); // copied from the latest version
        $gate2 = $this->gates()->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin);
        $this->approveGate($gate2, $this->headofit);
        $this->assertSame('approved', $i->refresh()->stage);
        $this->assertSame(['rejected', 'approved'], BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->orderBy('version_no')->pluck('status')->all());
    }

    /** FIN-05.04: a withdrawn request returns the case to draft; only the requester or the manager may withdraw. */
    public function test_withdraw_returns_business_case_to_draft(): void
    {
        $i = $this->readyForApproval();
        $gate = $this->gates()->advance($i, 'approved', ['options_considered'], [], null, $this->fin);
        $this->assertRefused(fn () => $this->gates()->withdraw($gate, $this->sponsor));
        $this->gates()->withdraw($gate, $this->manager);
        $this->assertSame('withdrawn', $gate->refresh()->status);
        $this->assertSame('draft', BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->first()->status);
        $this->assertSame('business_case', $i->refresh()->stage);
        $this->assertRefused(fn () => $this->gates()->approve($gate, $this->headofit)); // a withdrawn request cannot be decided
        $this->assertSame('submitted', $this->gates()->advance($i, 'approved', ['options_considered'], [], null, $this->fin)->status); // and can be asked again
    }

    /** FIN-05.02: one draft or submitted business case at a time. */
    public function test_one_open_business_case_at_a_time(): void
    {
        $i = $this->readyForApproval();
        $svc = app(BusinessCaseService::class);
        $this->assertRefused(fn () => $svc->createVersion($i, $this->fin), 'business_case', 'draft or awaiting');
        $this->gates()->advance($i, 'approved', ['options_considered'], [], null, $this->fin);
        $this->assertRefused(fn () => $svc->createVersion($i->refresh(), $this->fin), 'business_case', 'draft or awaiting');
        $this->assertRefused(fn () => $svc->update(BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->first(), ['recommended_option' => 'x'], $this->fin), 'business_case', 'cannot be edited');
    }

    /** FIN-05.02: an approved case is immutable and a later version needs an investment change, not a new case. */
    public function test_approved_business_case_is_immutable(): void
    {
        $i = $this->approvedInvestment();
        $bc = BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'approved')->first();
        $svc = app(BusinessCaseService::class);
        $this->assertRefused(fn () => $svc->update($bc, ['recommended_option' => 'Changed'], $this->fin), 'business_case', 'cannot be edited');
        $this->assertRefused(fn () => $svc->createVersion($i, $this->fin), 'business_case', 'investment changes');
        $this->assertSame($bc->costs, $bc->refresh()->costs);
    }

    /** P5-1: the BAC is the one-off cost of the approved case (capex and opex over the horizon, not the run cost). */
    public function test_bac_set_on_approval_is_the_one_off_cost(): void
    {
        $i = $this->makeInvestment();
        $this->gates()->advance($i, 'business_case', ['problem_agreed'], [], null, $this->fin);
        $this->makeBusinessCase($i->refresh(), [
            ['year' => 0, 'capex' => '600000', 'opex' => '100000', 'run' => '0'],
            ['year' => 1, 'capex' => '200000', 'opex' => '50000', 'run' => '120000'],
            ['year' => 2, 'capex' => '0', 'opex' => '0', 'run' => '120000'],
        ], array_map(fn ($y) => ['year' => $y, 'amount' => '500000'], range(1, 5)));
        $this->scoreAll($i);
        $this->assertSame('950000.00', (string) $i->refresh()->planned_cost); // saved with the draft
        $this->assertNull($i->bac);
        $this->approveGate($this->gates()->advance($i, 'approved', ['options_considered'], [], null, $this->fin), $this->headofit);
        $i->refresh();
        $this->assertSame('950000.00', (string) $i->bac);
        $this->assertNotNull($i->bac_baselined_at);
        $this->assertSame('950000.00', (string) BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->first()->one_off_cost);
    }

    /** FIN-05.03: the rank is recomputed when a score changes, so two investments can swap. */
    public function test_rank_recomputed_on_score_change(): void
    {
        $a = $this->makeInvestment(['title' => 'Alpha']);
        $b = $this->makeInvestment(['title' => 'Bravo']);
        $this->scoreAll($a, 4);
        $this->scoreAll($b, 2);
        $this->assertSame([1, 2], [$a->refresh()->priority_rank, $b->refresh()->priority_rank]);
        $this->assertSame('76.00', (string) $a->priority_score); // delivery risk counts inverted
        $this->assertSame('44.00', (string) $b->priority_score);

        $this->scoreAll($b, 5);
        $this->assertSame([2, 1], [$a->refresh()->priority_rank, $b->refresh()->priority_rank]);
        $this->assertSame('92.00', (string) $b->priority_score);
        // a closed or cancelled investment loses its rank
        app(InvestmentService::class)->cancel($b, 'The sponsor withdrew the idea after the budget review.', $this->fin);
        $this->assertNull($b->refresh()->priority_rank);
        $this->assertSame(1, $a->refresh()->priority_rank);
    }

    /** FIN-05.03: delivery risk counts the other way round: a risky delivery scores lower. */
    public function test_delivery_risk_is_inverted(): void
    {
        $safe = $this->makeInvestment(['title' => 'Safe']);
        $risky = $this->makeInvestment(['title' => 'Risky']);
        $base = array_fill_keys(Investment::CRITERIA, 3);
        app(InvestmentScoring::class)->score($safe, ['delivery_risk' => 1] + $base, $this->fin);
        app(InvestmentScoring::class)->score($risky, ['delivery_risk' => 5] + $base, $this->fin);
        $this->assertSame('64.00', (string) $safe->refresh()->priority_score); // 54 for the four others, plus a full 10 for low risk
        $this->assertSame('56.00', (string) $risky->refresh()->priority_score);
        $this->assertSame(1, $safe->priority_rank);
        $this->assertRefused(fn () => app(InvestmentScoring::class)->score($safe, ['delivery_risk' => 6] + $base, $this->fin), 'scores');
        $this->assertRefused(fn () => app(InvestmentScoring::class)->score($safe, ['delivery_risk' => 1], $this->fin), 'scores'); // all five are required
    }

    /** FIN-05.03: the weights must add up to 100 and a change rescores every open investment. */
    public function test_weights_sum_and_rescore(): void
    {
        $i = $this->makeInvestment();
        app(InvestmentScoring::class)->score($i, ['strategic_alignment' => 5, 'regulatory' => 1, 'risk_reduction' => 1, 'financial_return' => 1, 'delivery_risk' => 1], $this->fin);
        $this->assertSame('48.00', (string) $i->refresh()->priority_score);

        $setting = Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->first() ?? app(FinanceSettings::class)->for($this->org->id);
        $weights = ['strategic_alignment' => 40, 'regulatory' => 10, 'risk_reduction' => 10, 'financial_return' => 30, 'delivery_risk' => 10];
        $this->assertRefused(fn () => app(FinanceSettings::class)->update($setting, 'investments', ['investment_score_weights' => array_merge($weights, ['delivery_risk' => 20])]), 'investment_score_weights', '100');
        $this->assertSame('48.00', (string) $i->refresh()->priority_score);

        app(FinanceSettings::class)->update($setting, 'investments', ['investment_score_weights' => $weights]);
        $this->assertSame('60.00', (string) $i->refresh()->priority_score); // 40 + 2 + 2 + 6 + 10
        $this->assertSame('40.00', (string) InvestmentScore::withoutGlobalScopes()->where('investment_id', $i->id)->where('criterion', 'strategic_alignment')->value('weight_pct'));
    }

    /** FIN-05.05: AC from the tagged line's lineage plus direct actuals, counted once, duplicates excluded; EV, CV, CPI and EAC computed or manual. */
    public function test_evm_from_actuals(): void
    {
        $i = $this->inDelivery($this->approvedInvestment()); // BAC 1,000,000, line 0 tagged
        $evm = fn () => app(InvestmentFinancials::class)->evm($i->refresh());

        $e = $evm(); // no cost booked: CPI is undefined and the forecast falls back to the BAC
        $this->assertSame('0.00', $e['ac']);
        $this->assertNull($e['cpi']);
        $this->assertSame('1000000.00', $e['eac']);

        $this->postActual($this->monLines[0], $this->periodSeq(1), '300000.00'); // via the tagged line
        $direct = $this->postActual($this->monLines[1], $this->periodSeq(1), '100000.00', ['investment_id' => $i->id]); // direct tag on another line
        $this->postActual($this->monLines[0], $this->periodSeq(2), '100000.00', ['investment_id' => $i->id]); // both ways: counted once
        $this->postActual($this->monLines[0], $this->periodSeq(2), '50000.00', ['match_status' => 'duplicate']); // excluded
        $this->postActual($this->monLines[2], $this->periodSeq(1), '70000.00'); // an unrelated line
        app(InvestmentService::class)->progress($i->refresh(), ['percent_complete' => '50'], $this->manager);

        $e = $evm();
        $this->assertSame('500000.00', $e['ac']);
        $this->assertSame('500000.00', $e['ev']);
        $this->assertSame('0.00', $e['cv']);
        $this->assertSame('1.0000', $e['cpi']);
        $this->assertSame('1000000.00', $e['eac']);
        $this->assertSame('computed', $e['eac_method']);
        $this->assertSame('on_track', $e['health']);

        $direct->forceFill(['match_status' => 'duplicate'])->save(); // dropping one duplicate moves AC
        $this->assertSame('400000.00', $evm()['ac']);

        app(InvestmentService::class)->progress($i->refresh(), ['percent_complete' => '40'], $this->manager);
        $e = $evm(); // EV 400,000 over AC 400,000
        $this->assertSame('1.0000', $e['cpi']);
        app(InvestmentService::class)->progress($i->refresh(), ['percent_complete' => '30'], $this->manager);
        $e = $evm();
        $this->assertSame('0.7500', $e['cpi']); // 300,000 / 400,000
        $this->assertSame('1333333.33', $e['eac']); // BAC / CPI
        $this->assertSame('-333333.33', $e['vac']);
        $this->assertSame('off_track', $e['health']);
        $this->assertSame('off_track', $i->refresh()->status);

        $this->assertRefused(fn () => app(InvestmentService::class)->progress($i, ['percent_complete' => '30', 'eac' => '1500000'], $this->manager), 'eac_reason');
        app(InvestmentService::class)->progress($i, ['percent_complete' => '30', 'eac' => '1500000', 'eac_reason' => 'Vendor re-quoted the second phase after the design review.'], $this->manager);
        $e = $evm();
        $this->assertSame('manual', $e['eac_method']);
        $this->assertSame('1500000.00', $e['eac']);
        $this->assertSame('-500000.00', $e['vac']);
    }

    /** FIN-05.11: a change within tolerance applies at once; the cumulative movement past tolerance needs approval and re-baselines on approval. */
    public function test_change_within_and_over_tolerance(): void
    {
        $i = $this->approvedInvestment(); // BAC 1,000,000, tolerance 10 %
        $svc = app(InvestmentChangeService::class);
        $c1 = $svc->request($i, ['description' => 'Extra licences', 'reason' => 'Vendor price rose after the quote was issued', 'cost_delta' => '60000'], $this->manager);
        $this->assertSame('applied', $c1->status);
        $this->assertFalse((bool) $c1->requires_approval);
        $this->assertSame('1060000.00', (string) $i->refresh()->bac);

        // 60,000 + 60,000 is over 10 % of the BAC: the second change is cumulative and waits
        $c2 = $svc->request($i, ['description' => 'Agency channel', 'reason' => 'Scope grew to include the agency channel', 'cost_delta' => '60000', 'schedule_delta_days' => 5], $this->manager);
        $this->assertSame('submitted', $c2->status);
        $this->assertTrue((bool) $c2->requires_approval);
        $this->assertSame('1060000.00', (string) $i->refresh()->bac); // not yet
        $gate = $c2->gate()->withoutGlobalScopes()->first();
        $this->assertSame('change', $gate->gate);
        $this->assertRefused(fn () => $svc->request($i, ['description' => 'Another', 'reason' => 'Second request while one waits', 'cost_delta' => '1000'], $this->manager), 'description', 'awaiting approval');
        $this->assertRefused(fn () => $this->gates()->approve($gate, $this->manager), 'approval'); // the manager took part
        $this->gates()->approve($gate->refresh(), $this->headofit);
        $this->assertSame('applied', $c2->refresh()->status);
        $this->assertSame('1120000.00', (string) $i->refresh()->bac);
        $this->assertNotNull($i->bac_baselined_at);

        // a schedule move past 30 days also needs approval, and a reject leaves the BAC as it was
        $c3 = $svc->request($i, ['description' => 'Go-live moved', 'reason' => 'Vendor needs six more weeks for the release', 'schedule_delta_days' => 45], $this->manager);
        $this->assertSame('submitted', $c3->status);
        $this->gates()->reject($c3->gate()->withoutGlobalScopes()->first(), $this->headofit, 'Not justified.');
        $this->assertSame('rejected', $c3->refresh()->status);
        $this->assertSame('1120000.00', (string) $i->refresh()->bac);
        $this->assertRefused(fn () => $svc->request($i, ['description' => 'Nothing', 'reason' => 'No cost or schedule movement given', 'cost_delta' => '0'], $this->manager), 'cost_delta');
        $this->assertSame(3, InvestmentChange::withoutGlobalScopes()->where('investment_id', $i->id)->count());
    }

    /** A held investment takes no gates or changes until it is resumed. */
    public function test_hold_blocks_gates_and_changes(): void
    {
        $i = $this->approvedInvestment();
        $svc = app(InvestmentService::class);
        $this->assertRefused(fn () => $svc->hold($i, ' ', $this->fin), 'reason');
        $svc->hold($i, 'Waiting for the land title.', $this->fin);
        $this->assertSame('on_hold', $i->refresh()->status);
        $this->tagLines($i, $this->monLines[0]);
        $this->assertRefused(fn () => $this->gates()->advance($i, 'in_delivery', ['delivery_plan'], [], null, $this->fin), 'gate', 'on hold');
        $this->assertRefused(fn () => app(InvestmentChangeService::class)->request($i, ['description' => 'x', 'reason' => 'A reason that is long enough', 'cost_delta' => '1000'], $this->manager), 'description', 'on hold');
        $svc->resume($i->refresh(), $this->fin);
        $this->assertSame('on_track', $i->refresh()->status);
        $this->assertSame('in_delivery', $this->inDelivery($i)->stage);
    }

    /** Cancelling withdraws the pending gate and cancels the open reviews and benefits. */
    public function test_cancel_withdraws_pending_gate_and_cancels_reviews_and_benefits(): void
    {
        $i = $this->inDelivery($this->approvedInvestment());
        $benefit = app(BenefitsService::class)->store($i, ['title' => 'Savings', 'type' => 'cost_saving', 'unit' => 'currency', 'target_value' => '1000', 'owner_id' => $this->manager->id, 'due_on' => '2027-12-01'], $this->fin);
        $review = app(ValueReviewService::class)->adHoc($i, '2027-09-01', $this->sponsor->id, $this->fin);
        $change = app(InvestmentChangeService::class)->request($i, ['description' => 'Scope growth', 'reason' => 'The regulator widened the requirement', 'cost_delta' => '500000'], $this->manager);
        $gate = $change->gate()->withoutGlobalScopes()->first();
        $this->assertSame('submitted', $gate->status);

        $this->assertRefused(fn () => app(InvestmentService::class)->cancel($i, 'too short', $this->fin), 'reason');
        app(InvestmentService::class)->cancel($i, 'The programme was stopped by the steering committee.', $this->fin);
        $this->assertSame('cancelled', $i->refresh()->stage);
        $this->assertSame('withdrawn', $gate->refresh()->status);
        $this->assertSame('withdrawn', $change->refresh()->status);
        $this->assertSame('cancelled', $review->refresh()->status);
        $this->assertSame('cancelled', $benefit->refresh()->status);
        $this->assertNull($i->priority_rank);
        $this->assertNotNull($i->cancelled_at);
        $this->assertNull(app(ApprovalEngine::class)->current(StageGateService::PROCESS, $gate)); // no live request left
        $this->assertRefused(fn () => app(InvestmentService::class)->cancel($i, 'Cancelling a second time over again.', $this->fin));
    }

    /** Who may see an investment: the cost-centre owner, the manager, the sponsor and approvers; others get nothing. */
    public function test_visibility_by_cost_centre_manager_and_sponsor(): void
    {
        $i = $this->makeInvestment();
        $viewer = fn () => tap($this->userWith('Viewer'), function (User $u) {
            $u->roles()->detach();
            $u->givePermissionTo('view it-finance');
        });
        $count = fn (User $u) => count($this->actingAs($u)->get(route('finance.investments.index'))->assertOk()->original->getData()['page']['props']['investments']['data']);

        $stranger = $viewer();
        $this->assertSame(0, $count($stranger));
        $this->assertContains($this->actingAs($stranger)->get(route('finance.investments.show', $i))->status(), [403, 404]);

        $owner = User::find($this->cc->owner_id); // owner of the investment's cost centre
        $owner->givePermissionTo('view it-finance');
        $this->assertSame(1, $count($owner));
        $this->actingAs($owner)->get(route('finance.investments.show', $i))->assertOk();

        foreach ([$this->manager, $this->sponsor] as $u) {
            $this->assertSame(1, $count($u), 'manager or sponsor');
        }
        $other = $this->makeInvestment(['title' => 'Elsewhere', 'cost_centre_id' => $this->cc2->id, 'manager_id' => $this->owner2->id, 'sponsor_id' => $this->owner2->id]);
        $this->assertSame(1, $count($this->manager)); // not the other cost centre's investment
        $this->assertContains($this->actingAs($this->manager)->get(route('finance.investments.show', $other))->status(), [403, 404]);
        $this->assertSame(2, $count($this->cfo)); // approvers see all
        $this->assertSame(2, $count($this->fin));

        // a benefit owner or review reviewer sees the investment too
        $reviewer = $viewer();
        app(ValueReviewService::class)->adHoc($other->refresh(), '2027-09-01', $reviewer->id, $this->fin);
        $this->assertSame(1, $count($reviewer));
    }

    /** Amounts are hidden from a viewer without "view it-finance-amounts" on the register and detail pages. */
    public function test_amounts_are_masked_on_the_register(): void
    {
        $i = $this->approvedInvestment();
        $viewer = $this->userWith('Viewer');
        $viewer->roles()->detach();
        $viewer->givePermissionTo(['view it-finance']);
        $i->forceFill(['manager_id' => $viewer->id])->save();
        $row = $this->actingAs($viewer)->get(route('finance.investments.index'))->assertOk()->original->getData()['page']['props']['investments']['data'][0];
        $this->assertNull($row['bac']);
        $this->assertNotNull($row['investment_code']);
        $row = $this->actingAs($this->fin)->get(route('finance.investments.index'))->assertOk()->original->getData()['page']['props']['investments']['data'][0];
        $this->assertSame('1000000.00', $row['bac']);
    }

    /** P5 review domain H2 / security H1: the tolerance approved with the baseline is the one change control uses, and cannot be edited. */
    public function test_cost_tolerance_is_frozen_and_snapshotted_at_baseline(): void
    {
        $i = $this->approvedInvestment(); // BAC 1,000,000, tolerance 10 %
        $this->assertSame('10.00', (string) $i->bac_baselined_tolerance_pct);
        $this->assertRefused(fn () => app(InvestmentService::class)->update($i, ['cost_tolerance_pct' => '100'], $this->manager), 'cost_tolerance_pct', 'frozen');
        // even if the column were changed behind the service, the snapshot rules
        Investment::withoutGlobalScopes()->whereKey($i->id)->update(['cost_tolerance_pct' => '100']);
        $c = app(InvestmentChangeService::class)->request($i->refresh(), ['description' => 'Big scope', 'reason' => 'Scope grew a lot after the baseline', 'cost_delta' => '900000'], $this->manager);
        $this->assertSame('submitted', $c->status);
        $this->assertTrue((bool) $c->requires_approval);
    }

    /** P5 review domain H2 / security H2: sponsor, manager, cost centre, regulatory flag and rgt are frozen after approval and while a gate waits. */
    public function test_control_fields_frozen_after_approval_and_while_a_gate_is_pending(): void
    {
        $svc = app(InvestmentService::class);
        $i = $this->readyForApproval();
        $other = $this->userWith('Investment Sponsor');
        // free before any request: unchanged values pass, changes apply
        $svc->update($i, ['sponsor_id' => $i->sponsor_id, 'rgt' => $i->rgt, 'is_regulatory' => false], $this->fin);
        $gate = $this->gates()->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin);
        foreach (['sponsor_id' => $other->id, 'manager_id' => $other->id, 'rgt' => 'run', 'cost_tolerance_pct' => '50', 'is_regulatory' => true] as $k => $v) {
            $this->assertRefused(fn () => $svc->update($i->refresh(), [$k => $v], $this->fin), $k, 'awaiting a decision');
        }
        $this->gates()->approve($gate, $this->headofit);
        foreach (['sponsor_id' => $other->id, 'manager_id' => $other->id, 'rgt' => 'run', 'is_regulatory' => true] as $k => $v) {
            $this->assertRefused(fn () => $svc->update($i->refresh(), [$k => $v], $this->fin), $k, 'frozen');
        }
        $svc->update($i->refresh(), ['title' => 'Mobile app re-platform (phase 1)'], $this->fin); // other fields stay editable
        $this->assertSame('Mobile app re-platform (phase 1)', $i->refresh()->title);
    }
}
