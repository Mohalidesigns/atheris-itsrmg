<?php

namespace Tests\Feature\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\ContractLink;
use App\Models\Finance\FiscalYear;
use App\Models\User;
use App\Services\Ea\CostModelService;
use App\Services\Finance\ApplicationTcoService;
use App\Services\Finance\BudgetLineClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/**
 * FIN-05.10 (Application TCO), plan P6-15 and P6-16, on the P6 fixture bank: a 12,000,000 software line and a 6,000,000 cloud line tagged to one
 * service, a 24,000,000 labour line in CC-2 allocated to it at 50 %, and 1,500,000 of FinOps cloud cost tagged to it.
 */
class ApplicationTcoTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    private BusinessService $svc;

    private ApplicationTcoService $tco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
        $this->tco = app(ApplicationTcoService::class);
        $this->svc = BusinessService::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'BS-001', 'name' => 'Mobile Banking']);
    }

    /** Tags the software and cloud lines, posts the actuals and the FinOps cloud cost, and allocates half of labour to the service. */
    private function estate(): void
    {
        $classifier = app(BudgetLineClassifier::class);
        $classifier->classify([$this->monLines[0]->id, $this->poolLines['CP-CLD']->id], ['business_service_id' => $this->svc->id], $this->fin);
        $p1 = $this->periodSeq(1);
        $this->postActual($this->monLines[0], $p1, '800000.00');
        $this->postActual($this->poolLines['CP-CLD'], $p1, '500000.00'); // the invoiced cloud spend in the ledger
        $this->postActual($this->poolLines['CP-LAB'], $p1, '200000.00');
        $this->makeAllocation(['name' => 'labour to service', 'source_id' => $this->cc2->id, 'target_type' => 'business_service', 'target_ref' => null, 'target_id' => $this->svc->id, 'percentage' => '50']);
        $this->makeCloudAccount(['account_identifier' => '777', 'name' => 'Analytics']);
        $this->ingest($this->cloudRows('2027-02-01', 10, ['Compute'], ['Service' => 'BS-001'], 100)); // 10 days x 100 USD x 1,500
    }

    private function row(string $subject, string $basis, int $id): array
    {
        $p = $this->tco->portfolio($this->org->id, $this->fy->id, $subject, $basis, null, $this->fin);

        return collect($p['rows'])->firstWhere('id', $id) ?? [];
    }

    public function test_actual_tco_adds_direct_allocated_and_finops_cloud_without_counting_ledger_cloud_twice(): void // FIN-05.10, P6-15
    {
        $this->estate();
        $row = $this->row('business_service', 'actual', $this->svc->id);

        $this->assertSame('800000.00', $row['direct']); // the software line; the 500,000 ledger cloud is replaced by FinOps
        $this->assertSame('100000.00', $row['allocated']); // half of the 200,000 labour actual
        $this->assertSame('1500000.00', $row['cloud']);
        $this->assertSame('2400000.00', $row['total']);
        $this->assertSame('100000.00', $row['labour']); // the labour breakdown
        $this->assertSame('1500000.00', $row['by_pool']['cloud']);
        $this->assertSame('800000.00', $row['by_pool']['software']);
        $this->assertSame('0.00', $row['by_pool']['unclassified']);
    }

    public function test_budget_tco_keeps_the_ledger_cloud_line(): void // FIN-05.10, P6-15
    {
        $this->estate();
        $row = $this->row('business_service', 'budget', $this->svc->id);

        $this->assertSame('18000000.00', $row['direct']); // 12,000,000 software and 6,000,000 cloud
        $this->assertSame('12000000.00', $row['allocated']); // half of 24,000,000 labour
        $this->assertSame('30000000.00', $row['total']);
        $this->assertSame('6000000.00', $row['cloud']); // the cloud budget line, not the FinOps feed
    }

    public function test_contracts_are_a_memo_and_never_added(): void // FIN-05.10, P6-15
    {
        $this->estate();
        $contract = $this->contract(['title' => 'Mobile platform licence', 'contract_value' => '12000000']);
        ContractLink::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'contract_id' => $contract->id, 'linkable_type' => BusinessService::class, 'linkable_id' => $this->svc->id]);
        $this->tco->flush();
        $row = $this->row('business_service', 'actual', $this->svc->id);

        $this->assertSame('2400000.00', $row['total']);
        $this->assertSame(1, $row['contracts_count']);
        $this->assertSame('12000000.00', $row['contracts_memo']); // annualised from the base value over the term
        $detail = $this->tco->detail($this->org->id, $this->fy->id, 'business_service', $this->svc->id, 'actual', null);
        $this->assertSame('Mobile platform licence', $detail['components']['contracts'][0]['title']);
        $this->assertSame('Compute', $detail['components']['cloud'][0]['service']);
        $this->assertSame('100000.00', $detail['components']['allocated'][0]['amount']);
        $this->assertNotEmpty($detail['components']['direct']);
    }

    public function test_coverage_is_the_attributed_share_of_it_spend(): void // FIN-05.10
    {
        $this->estate();
        $totals = $this->tco->portfolio($this->org->id, $this->fy->id, 'business_service', 'actual', null, $this->fin)['totals'];

        $this->assertSame('1500000.00', $totals['it_total']); // 800,000 + 500,000 + 200,000 of ledger actuals
        $this->assertSame('2400000.00', $totals['tco_total']);
        $this->assertSame('100.00', $totals['coverage_pct']); // capped: FinOps cloud replacing the ledger cloud exceeds it in this fixture
        // 1,400,000 attributed of 1,500,000 in the ledger; FinOps (1,500,000) replaces the 500,000 ledger cloud, so the remainder is by definition it_total - tco_total
        $this->assertSame('0.00', $totals['unattributed']); // P7-17: never a negative bucket
        $this->assertSame('900000.00', $totals['finops_excess']); // the reconciling line; tco_total + unattributed - finops_excess == it_total
    }

    public function test_an_application_subject_sits_beside_the_ea_model_and_feeds_it_the_measured_figure(): void // FIN-05.10, P6-16
    {
        $before = $this->tco->portfolio($this->org->id, $this->fy->id, 'ea_application', 'actual', null, $this->fin);
        $this->assertFalse($before['subjectAvailable']['ea_application']); // a bank with no EA applications
        $this->assertTrue($before['subjectAvailable']['business_service']);

        $app = EaApplication::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'APP-1', 'name' => 'Finacle Core', 'tco_annual_ngn' => 12_000_000]);
        app(BudgetLineClassifier::class)->classify([$this->monLines[0]->id], ['ea_application_id' => $app->id], $this->fin);
        $this->postActual($this->monLines[0], $this->periodSeq(1), '800000.00');
        $this->tco->flush();
        $row = $this->row('ea_application', 'actual', $app->id);

        $this->assertSame('800000.00', $row['total']);
        $this->assertSame('12000000.00', $row['ea_modelled']); // the EA model's own figure sits beside it
        $this->assertSame('-93.33', $row['delta_vs_model_pct']);
        $this->assertTrue($this->tco->portfolio($this->org->id, $this->fy->id, 'ea_application', 'actual', null, $this->fin)['subjectAvailable']['ea_application']);

        $this->assertArrayNotHasKey('measured_ngn', app(CostModelService::class)->tco($app->refresh())); // tco() stays pure EA
        $cost = app(CostModelService::class)->tcoWithFinance($app->refresh(), $this->fin);
        $this->assertEquals(12_000_000.0, $cost['total_ngn']); // the EA figure is untouched
        $this->assertEquals(3_200_000.0, $cost['measured_ngn']); // 800,000 over the 3 elapsed periods, annualised
        $this->assertSame('it_finance_ytd_annualised', $cost['measured_basis']);
    }

    public function test_classifying_lines_is_scoped_audited_and_can_be_cleared(): void // FIN-05.10, P6-17
    {
        $classifier = app(BudgetLineClassifier::class);
        $stranger = $this->userWith('IT Budget Owner');
        $this->assertRefused(fn () => $classifier->classify([$this->monLines[0]->id], ['business_service_id' => $this->svc->id], $stranger), 'line_ids', 'outside your cost centres');
        $this->assertSame(0, $this->monLines[0]->refresh()->business_service_id ?? 0);

        $this->assertSame(1, $classifier->classify([$this->monLines[0]->id], ['business_service_id' => $this->svc->id], $this->requester)); // the line's cost-centre owner
        $this->assertSame($this->svc->id, (int) $this->monLines[0]->refresh()->business_service_id);
        $this->assertTrue(Activity::query()->where('log_name', 'it-finance')->where('event', 'classified')->where('subject_id', $this->monLines[0]->id)->exists());
        $this->assertSame(0, $classifier->classify([$this->monLines[0]->id], ['business_service_id' => $this->svc->id], $this->requester)); // idempotent
        $this->assertSame(1, $classifier->classify([$this->monLines[0]->id], ['business_service_id' => null], $this->requester));
        $this->assertNull($this->monLines[0]->refresh()->business_service_id);

        $this->assertRefused(fn () => $classifier->classify([$this->monLines[0]->id], [], $this->requester), 'ea_application_id');
        FiscalYear::withoutGlobalScopes()->whereKey($this->fy->id)->update(['status' => 'closed']);
        $this->assertRefused(fn () => $classifier->classify([$this->monLines[0]->id], ['business_service_id' => $this->svc->id], $this->requester), 'line_ids', 'fiscal year closed');
    }

    public function test_the_tco_page_masks_amounts_and_hides_the_detail_from_viewers_without_them(): void // FIN-07.10
    {
        $this->estate();
        $viewer = $this->userWith('IT Budget Owner');
        $viewer->roles()->detach();
        $viewer->givePermissionTo(['view it-finance']);
        $this->cc->forceFill(['owner_id' => $viewer->id])->save();
        $this->ccChild->forceFill(['owner_id' => $viewer->id])->save();
        $this->cc2->forceFill(['owner_id' => $viewer->id])->save();

        $props = $this->actingAs($viewer)->get(route('finance.monitor.tco', ['subject' => 'business_service']))->assertOk()->original->getData()['page']['props'];
        $this->assertNull(collect($props['rows'])->firstWhere('id', $this->svc->id)['total']);
        $this->assertNull($props['totals']['tco_total']);
        $this->actingAs($viewer)->getJson(route('finance.monitor.tco.detail', ['subject' => 'business_service', 'id' => $this->svc->id]))->assertForbidden();

        $full = $this->actingAs($this->fin)->getJson(route('finance.monitor.tco.detail', ['subject' => 'business_service', 'id' => $this->svc->id]))->assertOk()->json();
        $this->assertSame('Mobile Banking', $full['label']);
    }

    /** The identity the page must hold: tco_total + unattributed == it_total, with and without FinOps accounts. */
    public function test_tco_total_plus_unattributed_equals_it_total_with_and_without_finops(): void // FIN-05.10, C159
    {
        $classifier = app(BudgetLineClassifier::class);
        $classifier->classify([$this->monLines[0]->id, $this->poolLines['CP-CLD']->id], ['business_service_id' => $this->svc->id], $this->fin);
        $p1 = $this->periodSeq(1);
        $this->postActual($this->monLines[0], $p1, '800000.01');
        $this->postActual($this->poolLines['CP-CLD'], $p1, '500000.00');
        $this->postActual($this->poolLines['CP-LAB'], $p1, '200000.33');
        $this->makeAllocation(['name' => 'labour to service', 'source_id' => $this->cc2->id, 'target_type' => 'business_service', 'target_ref' => null, 'target_id' => $this->svc->id, 'percentage' => '50']);

        // No FinOps account: the ledger cloud stays in the TCO.
        $t = $this->tco->portfolio($this->org->id, $this->fy->id, 'business_service', 'actual', null, $this->fin);
        $row = collect($t['rows'])->firstWhere('id', $this->svc->id);
        $this->assertSame('500000.00', $row['cloud']);
        $this->assertSame($t['totals']['it_total'], bcadd($t['totals']['tco_total'], $t['totals']['unattributed'], 2));
        $this->assertSame($row['total'], $this->detailSum('business_service', $this->svc->id));

        // With FinOps covering the service: ledger cloud replaced once, identity and drill-down still tie.
        $this->makeCloudAccount(['account_identifier' => '777', 'name' => 'Analytics']);
        $this->ingest($this->cloudRows('2027-02-01', 3, ['Compute'], ['Service' => 'BS-001'], 100));
        $this->tco->flush();
        $t = $this->tco->portfolio($this->org->id, $this->fy->id, 'business_service', 'actual', null, $this->fin);
        $row = collect($t['rows'])->firstWhere('id', $this->svc->id);
        $this->assertSame('450000.00', $row['cloud']); // 3 days x 100 USD x 1,500
        $this->assertSame($t['totals']['it_total'], bcadd($t['totals']['tco_total'], $t['totals']['unattributed'], 2));
        $this->assertSame($row['total'], $this->detailSum('business_service', $this->svc->id));
    }

    private function detailSum(string $subject, int $id): string
    {
        $d = $this->tco->detail($this->org->id, $this->fy->id, $subject, $id, 'actual', null)['components'];
        $sum = '0.00';
        foreach (['direct', 'allocated'] as $k) {
            foreach ($d[$k] as $r) {
                $sum = bcadd($sum, $r['amount'], 2);
            }
        }
        foreach ($d['cloud'] as $r) {
            $sum = bcadd($sum, $r['cost'], 2);
        }

        return $sum;
    }

    /** Security H1: an EA-only user gets no finance keys from the EA surfaces; the opt-in path is gated. */
    public function test_ea_tco_is_pure_and_the_finance_feed_is_gated(): void // FIN-05.10, P6-16
    {
        $app = EaApplication::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'APP-9', 'name' => 'Leaky', 'tco_annual_ngn' => 1_000_000]);
        app(BudgetLineClassifier::class)->classify([$this->monLines[0]->id], ['ea_application_id' => $app->id], $this->fin);
        $this->postActual($this->monLines[0], $this->periodSeq(1), '800000.00');
        $this->tco->flush();

        $this->assertArrayNotHasKey('measured_ngn', app(CostModelService::class)->tco($app));
        $eaOnly = User::factory()->create(['organization_id' => $this->org->id]);
        $eaOnly->givePermissionTo('view ea');
        $none = app(CostModelService::class)->tcoWithFinance($app, $eaOnly);
        $this->assertNull($none['measured_ngn']);
        $this->assertNull(app(CostModelService::class)->tcoWithFinance($app, null)['measured_ngn']);
        $this->assertNotNull(app(CostModelService::class)->tcoWithFinance($app, $this->fin)['measured_ngn']);

        $json = $this->actingAs($eaOnly)->postJson('ea/graphql', ['query' => '{ applications { id cost { total_ngn } } }']);
        $this->assertStringNotContainsString('measured_', $json->getContent());
    }

    /** Security H2 / domain H4: the portfolio and measured paths are batch computations, not per-application. */
    public function test_measured_tco_query_count_is_bounded_across_many_applications(): void // FIN-05.10, P6-16
    {
        foreach (range(1, 12) as $i) {
            EaApplication::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => "APP-{$i}", 'name' => "App {$i}", 'tco_annual_ngn' => 1000 * $i]);
        }
        $first = EaApplication::withoutGlobalScopes()->where('organization_id', $this->org->id)->first();
        app(BudgetLineClassifier::class)->classify([$this->monLines[0]->id], ['ea_application_id' => $first->id], $this->fin);
        $this->postActual($this->monLines[0], $this->periodSeq(1), '800000.00');
        $this->tco->flush();
        $svc = app(CostModelService::class);
        $apps = EaApplication::withoutGlobalScopes()->where('organization_id', $this->org->id)->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach ($apps as $a) {
            $svc->tcoWithFinance($a, $this->fin);
        }
        $with = count(DB::getQueryLog());
        DB::flushQueryLog();
        $one = EaApplication::withoutGlobalScopes()->where('organization_id', $this->org->id)->first();
        $svc->tcoWithFinance($one, $this->fin); // memo warm: a single further call is cheap
        $again = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(12 * 12 + 60, $with);
        $this->assertLessThan(20, $again);
        $per = ($with - 60) / 12;
        $this->assertLessThan(12, $per, "finance feed costs {$per} queries per application; it must be one batch per org and year");
    }
}
