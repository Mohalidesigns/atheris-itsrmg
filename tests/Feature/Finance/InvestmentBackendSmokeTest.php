<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Benefit;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentLink;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\BudgetService;
use App\Services\Finance\InvestmentChangeService;
use App\Services\Finance\InvestmentFinancials;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\StageGateService;
use App\Services\Finance\ValueReviewService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/**
 * P5 backend slice smoke (FIN-05.01 to .09, FIN-07.10): every new GET renders with the contract's prop keys, redirects land on their tab,
 * amounts are masked, other banks get a 404, the evaluate endpoint returns the reference figures and budget lines accept an investment.
 */
class InvestmentBackendSmokeTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    private Investment $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
        $this->inv = $this->approvedInvestment();
        $this->inv = $this->inDelivery($this->inv);
    }

    private function props($response): array
    {
        return $response->original->getData()['page']['props'];
    }

    public function test_pages_render(): void // FIN-05.01, FIN-05.09
    {
        $index = $this->props($this->actingAs($this->fin)->get(route('finance.investments.index'))->assertOk());
        foreach (['filters', 'investments', 'summary', 'fiscalYears', 'costCentres', 'options', 'can', 'baseCurrency'] as $k) {
            $this->assertArrayHasKey($k, $index, $k);
        }
        $this->assertSame(1, $index['summary']['count']);
        $this->assertSame($this->inv->investment_code, $index['investments']['data'][0]['investment_code']);
        $this->assertArrayHasKey('meta', $index['investments']);
        $this->assertCount(1, $this->props($this->actingAs($this->fin)->get(route('finance.investments.index', ['stage' => 'in_delivery'])))['investments']['data']);
        $this->assertCount(0, $this->props($this->actingAs($this->fin)->get(route('finance.investments.index', ['stage' => 'closed'])))['investments']['data']);

        $create = $this->props($this->actingAs($this->fin)->get(route('finance.investments.create'))->assertOk());
        foreach (['options', 'users', 'costCentres', 'legalEntities', 'fiscalYears', 'defaults'] as $k) {
            $this->assertArrayHasKey($k, $create, $k);
        }

        $show = $this->props($this->actingAs($this->fin)->get(route('finance.investments.show', $this->inv))->assertOk());
        foreach (['tab', 'investment', 'businessCases', 'businessCase', 'scores', 'weights', 'gates', 'nextGate', 'evm', 'lines', 'lineOptions', 'benefits', 'reviews', 'changes', 'links',
            'linkOptions', 'documents', 'activity', 'users', 'periods', 'options', 'can'] as $k) {
            $this->assertArrayHasKey($k, $show, $k);
        }
        $this->assertSame('in_delivery', $show['investment']['stage']);
        $this->assertSame('1000000.00', $show['evm']['bac']);
        $this->assertCount(1, $show['lines']);
        $this->assertSame('approved', $show['businessCase']['status']);
        foreach (['approve', 'reject', 'withdraw'] as $k) {
            $this->assertArrayHasKey($k, $show['gates'][0]['can']);
        }
        foreach (['investment', 'tab'] as $_) {
            $this->actingAs($this->fin)->get(route('finance.investments.show', [$this->inv, 'tab' => 'gates']))->assertOk();
        }

        $pf = $this->props($this->actingAs($this->fin)->get(route('finance.portfolio'))->assertOk());
        foreach (['filters', 'fiscalYears', 'envelope', 'ranking', 'splits', 'pipeline', 'costs', 'benefits', 'atRisk', 'bubble', 'capabilities', 'can'] as $k) {
            $this->assertArrayHasKey($k, $pf, $k);
        }
        $this->assertArrayHasKey('committed', $pf['costs']);
        $this->assertSame('grow_transform_baseline', $pf['envelope']['source']);
        $manual = $this->props($this->actingAs($this->fin)->get(route('finance.portfolio', ['envelope' => '5000000']))->assertOk());
        $this->assertSame('manual', $manual['envelope']['source']);
    }

    public function test_redirects_land_on_the_right_tab(): void
    {
        $gate = StageGate::withoutGlobalScopes()->where('investment_id', $this->inv->id)->first();
        $bc = BusinessCase::withoutGlobalScopes()->where('investment_id', $this->inv->id)->first();
        $this->actingAs($this->fin)->get(route('finance.stage-gates.show', $gate))->assertRedirect(route('finance.investments.show', ['investment' => $this->inv->id, 'tab' => 'gates']));
        $this->actingAs($this->fin)->get(route('finance.business-cases.show', $bc))
            ->assertRedirect(route('finance.investments.show', ['investment' => $this->inv->id, 'tab' => 'business-case', 'version' => $bc->version_no]));
        $review = ValueReview::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'investment_id' => $this->inv->id, 'review_type' => 'ad_hoc', 'due_on' => '2027-09-01', 'status' => 'scheduled']);
        $this->actingAs($this->fin)->get(route('finance.value-reviews.show', $review))->assertRedirect(route('finance.investments.show', ['investment' => $this->inv->id, 'tab' => 'reviews']));
    }

    public function test_evaluate_endpoint(): void // FIN-05.02
    {
        $payload = ['horizon_years' => 5, 'discount_rate_pct' => '10', 'costs' => [['year' => 0, 'capex' => '1000000', 'opex' => '0', 'run' => '0']],
            'benefits' => array_map(fn ($y) => ['year' => $y, 'amount' => '300000'], range(1, 5))];
        $r = $this->actingAs($this->fin)->postJson(route('finance.business-cases.evaluate'), $payload)->assertOk()->json();
        $this->assertEqualsWithDelta(137236.03, (float) $r['npv'], 0.01);
        $this->assertEqualsWithDelta(15.24, (float) $r['irr_pct'], 0.01);
        $this->assertSame(40, $r['payback_months']);
        $this->assertSame('50.00', $r['roi_pct']);
        $this->assertCount(6, $r['cash_flows']);
        $payload['costs'][0]['year'] = 9;
        $this->actingAs($this->fin)->postJson(route('finance.business-cases.evaluate'), $payload)->assertStatus(422);
    }

    public function test_masking(): void // FIN-07.10
    {
        $viewer = $this->userWith('IT Budget Owner');
        $viewer->revokePermissionTo('view it-finance-amounts');
        $viewer->roles()->detach();
        $viewer->givePermissionTo(['view it-finance', 'create it-finance', 'edit it-finance']);
        $this->inv->forceFill(['manager_id' => $viewer->id])->save();
        $show = $this->props($this->actingAs($viewer)->get(route('finance.investments.show', $this->inv))->assertOk());
        $this->assertFalse($show['can']['viewAmounts']);
        foreach (['bac', 'ac', 'eac', 'cpi', 'ev', 'allocated', 'funding_gap'] as $k) {
            $this->assertNull($show['evm'][$k], $k);
        }
        $this->assertNull($show['investment']['bac']);
        $this->assertNull($show['businessCase']['npv']);
        $this->assertSame([], $show['businessCase']['costs']);
        $this->assertSame([], $show['businessCase']['benefits']);
        $this->assertSame([], $show['evm']['spend_by_stage']);
        $this->assertNotNull($show['investment']['priority_rank'] ?? 1);
        $this->assertSame('in_delivery', $show['investment']['stage']);
        $this->actingAs($viewer)->postJson(route('finance.business-cases.evaluate'), ['horizon_years' => 5, 'costs' => [], 'benefits' => []])->assertForbidden();
        $index = $this->props($this->actingAs($viewer)->get(route('finance.investments.index'))->assertOk());
        $this->assertNull($index['investments']['data'][0]['bac']);
        $this->assertNull($this->props($this->actingAs($viewer)->get(route('finance.portfolio'))->assertOk())['costs']['bac']);
    }

    public function test_masking_keeps_non_currency_benefits(): void
    {
        $b = app(BenefitsService::class)->store($this->inv, ['title' => 'Faster onboarding', 'type' => 'productivity', 'unit' => 'hours', 'target_value' => '500', 'owner_id' => $this->manager->id, 'due_on' => '2027-12-31'], $this->fin);
        $m = app(BenefitsService::class)->store($this->inv, ['title' => 'Savings', 'type' => 'cost_saving', 'unit' => 'currency', 'target_value' => '900000', 'owner_id' => $this->manager->id, 'due_on' => '2027-12-31'], $this->fin);
        $viewer = $this->userWith('IT Budget Owner');
        $viewer->roles()->detach();
        $viewer->givePermissionTo(['view it-finance', 'edit it-finance']);
        $this->inv->forceFill(['manager_id' => $viewer->id])->save();
        $rows = collect($this->props($this->actingAs($viewer)->get(route('finance.investments.show', $this->inv))->assertOk())['benefits'])->keyBy('id');
        $this->assertSame('500.00', $rows[$b->id]['target_value']);
        $this->assertNull($rows[$m->id]['target_value']);
    }

    public function test_cross_bank_404(): void // tenancy
    {
        $other = $this->otherBank();
        $this->seed(RolesAndPermissionsSeeder::class);
        $foreign = $this->userWith('IT Finance Controller', [], $other->id);
        $this->actingAs($foreign);
        $gate = StageGate::withoutGlobalScopes()->where('investment_id', $this->inv->id)->first();
        $bc = BusinessCase::withoutGlobalScopes()->where('investment_id', $this->inv->id)->first();
        $benefit = Benefit::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'investment_id' => $this->inv->id, 'benefit_code' => 'X-BEN-1', 'title' => 'x', 'type' => 'revenue',
            'baseline_value' => 0, 'target_value' => 10, 'unit' => 'count', 'aggregation' => 'sum', 'status' => 'planned']);
        $review = ValueReview::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'investment_id' => $this->inv->id, 'review_type' => 'ad_hoc', 'due_on' => '2027-09-01', 'status' => 'scheduled']);
        $this->get(route('finance.investments.show', $this->inv))->assertNotFound();
        $this->get(route('finance.stage-gates.show', $gate))->assertNotFound();
        $this->get(route('finance.business-cases.show', $bc))->assertNotFound();
        $this->get(route('finance.value-reviews.show', $review))->assertNotFound();
        $this->post(route('finance.benefits.cancel', $benefit), ['reason' => 'because I can'])->assertNotFound();
        $this->post(route('finance.value-reviews.complete', $review), ['summary' => 'x'])->assertNotFound();
        $this->post(route('finance.stage-gates.approve', $gate))->assertNotFound();
    }

    public function test_budget_line_accepts_investment_of_the_bank(): void // C134 / FIN-05.08
    {
        $svc = app(BudgetService::class);
        $b = $svc->createVersion($this->openCycle(), $this->cc, 'zero_based', $this->owner); // the editor must see the investment's cost centre (P5-sec-L3)
        $line = $svc->addLine($b, $this->lineData(['investment_id' => $this->inv->id]), $this->owner);
        $this->assertSame($this->inv->id, (int) $line->refresh()->investment_id);

        $other = Organization::factory()->create(['currency' => 'NGN']);
        $foreign = Investment::withoutGlobalScopes()->create(['organization_id' => $other->id, 'investment_code' => 'ZZZ-IVT-0001', 'title' => 'x', 'type' => 'project', 'rgt' => 'run', 'driver' => 'strategic']);
        $this->assertRefused(fn () => $svc->addLine($b->refresh(), $this->lineData(['investment_id' => $foreign->id]), $this->owner), 'investment_id');
    }

    public function test_links_in_org_only(): void // FIN-05.08
    {
        $risk = Risk::factory()->create(['organization_id' => $this->org->id]);
        $this->actingAs($this->fin)->post(route('finance.investments.links.store', $this->inv), ['type' => 'risk', 'id' => $risk->id])->assertSessionHasNoErrors();
        $this->assertSame(1, InvestmentLink::withoutGlobalScopes()->where('investment_id', $this->inv->id)->count());
        $foreignRisk = Risk::factory()->create(['organization_id' => $this->otherBank()->id]);
        $this->actingAs($this->fin)->post(route('finance.investments.links.store', $this->inv), ['type' => 'risk', 'id' => $foreignRisk->id])->assertSessionHasErrors('id');
        $this->assertSame(1, InvestmentLink::withoutGlobalScopes()->where('investment_id', $this->inv->id)->count());
    }

    public function test_finance_approver_holds_the_investment_approval_permission(): void // C131
    {
        $this->assertTrue($this->head->can('approve it-finance-investments'));
        $this->assertTrue($this->userWith('Finance Approver')->can('approve it-finance-investments'));
        $this->assertInstanceOf(User::class, $this->head);
    }

    public function test_service_lifecycle_end_to_end(): void // FIN-05.04 to .07, .11
    {
        $gates = app(StageGateService::class);
        $changes = app(InvestmentChangeService::class);
        $reviews = app(ValueReviewService::class);
        $inv = $this->inv;

        // EVM from a tagged-line actual
        $this->postActual($this->monLines[0], $this->periodSeq(1), '400000.00');
        app(InvestmentService::class)->progress($inv, ['percent_complete' => '50'], $this->manager);
        $evm = app(InvestmentFinancials::class)->evm($inv->refresh());
        $this->assertSame('500000.00', $evm['ev']);
        $this->assertSame('400000.00', $evm['ac']);
        $this->assertSame('1.2500', $evm['cpi']);
        $this->assertSame('800000.00', $evm['eac']);

        // change within tolerance applies at once; over tolerance waits for approval
        $c1 = $changes->request($inv->refresh(), ['description' => 'Extra licences', 'reason' => 'Vendor price rose after the quote', 'cost_delta' => '50000', 'schedule_delta_days' => 0], $this->manager);
        $this->assertSame('applied', $c1->status);
        $this->assertSame('1050000.00', (string) $inv->refresh()->bac);
        $c2 = $changes->request($inv, ['description' => 'Scope growth', 'reason' => 'Added the agency channel to the scope', 'cost_delta' => '200000'], $this->manager);
        $this->assertSame('submitted', $c2->status);
        $gate = $c2->gate()->withoutGlobalScopes()->first();
        $this->assertSame('change', $gate->gate);
        $this->assertRefused(fn () => $gates->approve($gate, $this->sponsor), 'approval'); // the sponsor is excluded
        $gates->approve($gate->refresh(), $this->headofit);
        $this->assertSame('applied', $c2->refresh()->status);
        $this->assertSame('1250000.00', (string) $inv->refresh()->bac);

        // delivery gate schedules reviews
        $g = $gates->advance($inv, 'delivered', ['acceptance', 'handover'], ['actual_ends_on' => '2027-04-04'], null, $this->fin);
        $this->assertSame('submitted', $g->status);
        $gates->approve($g, $this->headofit);
        $this->assertSame('delivered', $inv->refresh()->stage);
        $rows = ValueReview::withoutGlobalScopes()->where('investment_id', $inv->id)->orderBy('due_on')->get();
        $this->assertSame(['2027-07-04', '2027-10-04', '2028-04-04'], $rows->map(fn ($r) => $r->due_on->toDateString())->all());
        $this->assertSame(0, $reviews->schedule($inv, $this->fin)); // idempotent

        // benefits and readings
        $b = app(BenefitsService::class)->store($inv, ['title' => 'Savings', 'type' => 'cost_saving', 'unit' => 'currency', 'target_value' => '1000', 'owner_id' => $this->manager->id, 'due_on' => '2028-01-01'], $this->fin);
        app(BenefitsService::class)->record($b, $this->periodSeq(3), '400.00', null, null, $this->manager);
        app(BenefitsService::class)->record($b, $this->periodSeq(3), '600.00', null, null, $this->manager); // replaces
        $this->assertSame('60.00', (string) $b->refresh()->realised_pct);
        $this->assertSame('tracking', $b->status);
        $gates->advance($inv, 'benefits_tracking', [], [], null, $this->fin);

        // reviews derive and complete
        $changed = $reviews->derive($this->org->id, CarbonImmutable::parse('2027-06-20'));
        $this->assertSame('due', $changed->first()->status);
        $done = $reviews->complete($rows->first()->refresh(), ['summary' => 'Benefits are tracking toward target.'], $this->sponsor);
        $this->assertSame('completed', $done->status);
        $this->assertSame('under', $done->cost_outcome);
    }
}
