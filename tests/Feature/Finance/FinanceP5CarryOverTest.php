<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentChange;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
use App\Models\User;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\InvestmentChangeService;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\StageGateService;
use App\Services\Finance\ValueReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/** Plan P6-22 / slice B1: investment reassignment, domain L2 and L6, security M1, M2 and M5 (the P5 carry-overs). */
class FinanceP5CarryOverTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
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

    private function reassign(Investment $i, array $fields, ?User $by = null, string $reason = 'Sponsor has left the business unit'): InvestmentChange
    {
        return app(InvestmentChangeService::class)->request($i, ['kind' => 'reassign', 'reason' => $reason, 'field_changes' => $fields], $by ?? $this->fin);
    }

    private function newOwners(): array
    {
        $s = $this->userWith('Investment Sponsor', ['job_title' => 'Head of Retail']);
        $m = $this->userWith('IT Budget Owner');

        return [$s, $m];
    }

    public function test_reassignment_goes_through_the_change_gate_and_applies_on_final_approval(): void
    {
        $i = $this->inDelivery($this->approvedInvestment());
        [$s2, $m2] = $this->newOwners();
        $bacBefore = (string) $i->bac;

        $c = $this->reassign($i, ['sponsor_id' => $s2->id, 'manager_id' => $m2->id, 'rgt' => 'run', 'is_regulatory' => true]);
        $this->assertSame(['reassign', 'submitted', true], [$c->kind, $c->status, (bool) $c->requires_approval]);
        $gate = $c->gate()->withoutGlobalScopes()->first();
        $this->assertSame('change', $gate->gate);
        $this->assertSame($this->sponsor->id, $i->refresh()->sponsor_id, 'nothing moves before approval');
        $this->assertSame($this->sponsor->id, (int) $c->field_changes['sponsor_id']['from']);

        $this->approveGate($gate, $this->headofit);
        $i->refresh();
        $this->assertSame([$s2->id, $m2->id, 'run', true], [$i->sponsor_id, $i->manager_id, $i->rgt, (bool) $i->is_regulatory]);
        $this->assertSame($bacBefore, (string) $i->bac, 'a reassignment never touches the budget');
        $this->assertSame('applied', $c->refresh()->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'reassigned', 'subject_id' => $i->id]);

        // frozen control fields stay frozen for a plain edit
        $this->refused(fn () => app(InvestmentService::class)->update($i, ['sponsor_id' => $this->sponsor->id], $this->fin), 'frozen');
    }

    public function test_reassignment_request_rules(): void
    {
        $i = $this->approvedInvestment();
        [$s2, $m2] = $this->newOwners();
        $stranger = User::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
        $svc = app(InvestmentChangeService::class);

        $this->refused(fn () => $this->reassign($i, ['sponsor_id' => $s2->id], null, 'too short'), 'at least 20');
        $this->refused(fn () => $this->reassign($i, []), 'at least one');
        $this->refused(fn () => $this->reassign($i, ['sponsor_id' => $this->sponsor->id]), 'nothing differs');
        $this->refused(fn () => $this->reassign($i, ['manager_id' => $s2->id, 'sponsor_id' => $s2->id]), 'manager cannot also be the sponsor');
        $this->refused(fn () => $this->reassign($i, ['sponsor_id' => $this->fin->id]), 'yourself');
        $this->refused(fn () => $this->reassign($i, ['sponsor_id' => $stranger->id]), 'no access');
        $this->refused(fn () => $this->reassign($i, ['sponsor_id' => 999999]), 'user of this bank');
        $this->refused(fn () => $this->reassign($i, ['cost_centre_id' => 999999]), 'cost centre of this bank');
        $this->refused(fn () => $this->reassign($i, ['rgt' => 'bogus']), 'listed values');
        $this->refused(fn () => $svc->request($i, ['kind' => 'reassign', 'reason' => 'Sponsor has left the business unit', 'cost_delta' => '5000', 'field_changes' => ['rgt' => 'run']], $this->fin), 'cannot also change cost');

        $idea = $this->makeInvestment();
        $this->refused(fn () => $this->reassign($idea, ['rgt' => 'run']), 'once the business case is approved');
        $this->assertSame(0, InvestmentChange::withoutGlobalScopes()->where('investment_id', $i->id)->count());
    }

    public function test_old_and_new_sponsor_and_manager_cannot_decide(): void
    {
        $i = $this->inDelivery($this->approvedInvestment());
        FinancePolicySeeder::ensureDefaults($this->org->id);
        [$s2, $m2] = $this->newOwners();
        $gate = $this->reassign($i, ['sponsor_id' => $s2->id, 'manager_id' => $m2->id])->gate()->withoutGlobalScopes()->first();
        $this->assertEqualsCanonicalizing(
            [$this->fin->id, $this->sponsor->id, $this->manager->id, $s2->id, $m2->id],
            array_intersect($gate->sodExcludedIds(), [$this->fin->id, $this->sponsor->id, $this->manager->id, $s2->id, $m2->id]),
        );
        foreach ([$this->sponsor, $this->manager, $s2, $m2, $this->fin] as $u) {
            $u->givePermissionTo('approve it-finance-investments');
            $this->refused(fn () => app(StageGateService::class)->approve($gate->refresh(), $u), '');
            $this->assertSame('submitted', $gate->refresh()->status);
        }
        $this->assertFalse($gate->approvalRequest->steps->isEmpty());
    }

    public function test_open_value_reviews_move_to_the_new_sponsor(): void
    {
        $i = $this->inDelivery($this->approvedInvestment());
        $review = app(ValueReviewService::class)->adHoc($i, '2027-09-01', $this->sponsor->id, $this->fin);
        $other = app(ValueReviewService::class)->adHoc($i, '2027-10-01', $this->cfo->id, $this->fin);
        [$s2, $m2] = $this->newOwners();

        $gate = $this->reassign($i, ['sponsor_id' => $s2->id])->gate()->withoutGlobalScopes()->first();
        $this->assertSame($this->sponsor->id, $review->refresh()->reviewer_id);
        $this->approveGate($gate, $this->headofit);

        $this->assertSame($s2->id, $review->refresh()->reviewer_id);
        $this->assertSame($this->cfo->id, $other->refresh()->reviewer_id, 'a review owned by someone else stays put');
        $this->assertSame(2, ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->whereIn('status', ValueReview::OPEN)->count());
    }

    public function test_tolerance_is_measured_on_the_baselined_bac_and_a_negative_bac_is_refused(): void
    {
        $i = $this->approvedInvestment(); // BAC 1,000,000
        $this->assertSame('1000000.00', (string) $i->bac_baseline_amount);
        $tol = (string) $i->bac_baselined_tolerance_pct;
        $limit = bcmul('1000000', bcdiv($tol, '100', 6), 2);
        $svc = app(InvestmentChangeService::class);
        $step1 = bcsub($limit, '1000', 2);
        $c1 = $svc->request($i, ['description' => 'Licences', 'reason' => 'More licences are needed for go-live', 'cost_delta' => $step1], $this->manager);
        $this->assertSame('applied', $c1->status);
        $this->assertSame(bcadd('1000000', $step1, 2), (string) $i->refresh()->bac);
        $this->assertSame('1000000.00', (string) $i->bac_baseline_amount, 'drift does not move the baseline');

        $this->refused(fn () => $svc->request($i->refresh(), ['description' => 'Cut', 'reason' => 'Cutting more than the whole budget', 'cost_delta' => '-99000000'], $this->manager), 'below zero');

        // 1,000 more crosses the limit of the baseline; on the drifted BAC (1,0xx,xxx) the limit would be a little higher
        $over = bcadd(bcmul($limit, '1', 2), '0', 2);
        $c2 = $svc->request($i, ['description' => 'More', 'reason' => 'A further small increase on top', 'cost_delta' => bcsub($over, $step1, 2) > 0 ? bcadd(bcsub($over, $step1, 2), '5000', 2) : '5000'], $this->manager);
        $this->assertSame('submitted', $c2->status, 'cumulative movement is compared with 10 % of the baselined BAC');
        $this->assertSame('1000000.00', (string) json_decode(json_encode($c2->tolerance_snapshot), true)['baseline_bac']);

    }

    public function test_apply_refuses_instead_of_clamping_a_negative_bac(): void
    {
        $i = $this->approvedInvestment();
        $c = InvestmentChange::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'investment_id' => $i->id, 'change_code' => 'ICR-TEST', 'description' => 'x', 'reason' => 'x', 'cost_delta' => '-5000000.00',
            'schedule_delta_days' => 0, 'status' => 'submitted', 'requires_approval' => true, 'kind' => 'scope', 'requested_at' => now(),
        ]);
        $this->refused(fn () => app(InvestmentChangeService::class)->apply($c, $this->fin, true), 'below zero');
        $this->assertSame('1000000.00', (string) $i->refresh()->bac);
    }

    public function test_capitalisable_flag_is_editable_after_approval(): void
    {
        $i = $this->approvedInvestment();
        $svc = app(InvestmentService::class);
        $svc->update($i, ['is_capitalisable' => true, 'capitalisation_standard' => 'ias_38'], $this->fin);
        $i->refresh();
        $this->assertSame([true, 'ias_38'], [$i->is_capitalisable, $i->capitalisation_standard]);
        $this->refused(fn () => $svc->update($i, ['capitalisation_standard' => 'gaap'], $this->fin), 'IAS 38 or IAS 16');
        $svc->update($i, ['is_capitalisable' => false], $this->fin);
        $this->assertSame([false, null], [$i->refresh()->is_capitalisable, $i->capitalisation_standard]);

        $row = $this->actingAs($this->fin)->get(route('finance.investments.show', $i->id))->viewData('page')['props']['investment'];
        $this->assertArrayHasKey('is_capitalisable', $row);
        $this->assertArrayHasKey('capitalisation_standard', $row);
    }

    public function test_one_pending_request_per_investment(): void
    {
        $i = $this->inDelivery($this->approvedInvestment());
        [$s2] = $this->newOwners();
        $first = $this->reassign($i, ['sponsor_id' => $s2->id]);
        $this->refused(fn () => $this->reassign($i->refresh(), ['rgt' => 'run']), 'already awaiting approval');
        $this->refused(fn () => app(InvestmentChangeService::class)->request($i->refresh(), ['description' => 'Scope', 'reason' => 'Scope grew after the baseline', 'cost_delta' => '10000'], $this->manager), 'already awaiting approval');
        $this->refused(fn () => app(StageGateService::class)->advance($i->refresh(), 'delivered', ['acceptance', 'handover'], ['actual_ends_on' => '2027-04-01'], null, $this->fin), 'already awaiting approval');
        $this->assertSame(1, StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'submitted')->count());
        $this->assertSame('submitted', $first->refresh()->status);
    }

    public function test_line_tagging_is_scoped_to_the_editors_cost_centres_and_open_budgets(): void
    {
        $i = $this->approvedInvestment();
        $line = $this->monLines[0]; // belongs to ccChild, owned by $this->requester

        $this->refused(fn () => app(InvestmentService::class)->linkLines($i, [$line->id], $this->manager), 'cost centre outside your access');
        $this->assertNull($line->refresh()->investment_id);

        $res = app(InvestmentService::class)->linkLines($i, [$line->id], $this->requester);
        $this->assertSame([$line->id], $res['tagged']);

        // a scoped editor cannot see the tagged line, so a save that omits it leaves it alone
        $this->assertSame(['tagged' => [], 'untagged' => []], app(InvestmentService::class)->linkLines($i, [], $this->manager));
        $this->assertSame($i->id, (int) $line->refresh()->investment_id);

        // the owner may untag it, and the refusal names the line when the budget is closed to tagging
        $budget = $line->budget()->withoutGlobalScopes()->first();
        $budget->newQuery()->whereKey($budget->id)->toBase()->update(['status' => 'rejected']);
        $this->refused(fn () => app(InvestmentService::class)->linkLines($i, [], $this->requester), $budget->budget_code.' line '.$line->line_no);
        $budget->newQuery()->whereKey($budget->id)->toBase()->update(['status' => 'baseline']);
        $this->assertSame([$line->id], app(InvestmentService::class)->linkLines($i, [], $this->requester)['untagged']);
    }

    public function test_approval_trail_hides_the_amount_band_from_viewers_without_amounts(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $i = $this->makeInvestment();
        $gates = app(StageGateService::class);
        $gates->advance($i, 'business_case', ['problem_agreed'], [], null, $this->fin);
        $this->makeBusinessCase($i->refresh(), [['year' => 0, 'capex' => '300000000', 'opex' => '0', 'run' => '0']]);
        $this->scoreAll($i);
        $gate = $gates->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin);

        $blind = User::factory()->create(['organization_id' => $this->org->id]);
        $blind->givePermissionTo('view it-finance');
        $this->assertFalse($blind->can('view it-finance-amounts'));
        $this->fin->givePermissionTo('view it-finance-amounts');

        $approvals = app(FinanceApprovals::class);
        $seen = $approvals->trail(StageGateService::PROCESS, $gate, $this->fin)['context'];
        $this->assertNotNull($seen['amount_band']);
        $this->assertNotNull($seen['base_amount']);

        $hidden = $approvals->trail(StageGateService::PROCESS, $gate, $blind)['context'];
        foreach (['amount_band', 'amount_band_label', 'approval_base_amount', 'base_amount', 'band'] as $k) {
            $this->assertNull($hidden[$k] ?? null, $k);
        }
        $this->assertNull($hidden['conditions']['amount_band'] ?? null);
        $this->assertStringNotContainsString('amount_band', json_encode($approvals->trail(StageGateService::PROCESS, $gate, $blind)['stages']));
    }
}
