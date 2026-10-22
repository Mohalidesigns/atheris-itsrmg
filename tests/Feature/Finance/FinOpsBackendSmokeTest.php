<?php

namespace Tests\Feature\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Finance\Actual;
use App\Models\Finance\Allocation;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudAnomaly;
use App\Models\Finance\CloudCostLine;
use App\Models\Finance\CloudTagRule;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Driver;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Models\User;
use App\Services\Finance\AllocationService;
use App\Services\Finance\BudgetLineClassifier;
use App\Services\Finance\CloudAnomalyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/**
 * P6 backend slice smoke (FIN-03.09, .10, .12, .13, FIN-02.12, FIN-05.10, FIN-07.10): every new GET renders with the contract's prop keys,
 * amounts are masked and gated, other banks get a 404, the cloud import creates a batch and lines, classification and allocation rules are guarded.
 */
class FinOpsBackendSmokeTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    private Investment $inv;

    private Capitalisation $cap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
        $this->makeCloudAccount();
        $this->ingest($this->cloudRows('2027-03-10', 25, ['Amazon EC2', 'Amazon S3'], ['Service' => 'mobile'], 100));
        $this->ingest($this->spike('2027-04-03', 'Amazon EC2', 10));
        app(CloudAnomalyService::class)->detect($this->cloudAccount, CarbonImmutable::parse('2027-04-05'));
        $this->makeAllocation();
        $this->inv = $this->inDelivery($this->approvedInvestment());
        $this->flagCapitalisable($this->inv);
        $this->capexActual($this->inv, '900000.00', '2027-03-20');
        $this->cap = $this->makeCapitalisation($this->inv->refresh(), [], true);
    }

    private function props($response): array
    {
        return $response->original->getData()['page']['props'];
    }

    private function keys(array $props, array $keys): void
    {
        foreach ($keys as $k) {
            $this->assertArrayHasKey($k, $props, $k);
        }
    }

    private function viewerWithoutAmounts(): User
    {
        $viewer = $this->userWith('IT Budget Owner');
        $viewer->roles()->detach();
        $viewer->givePermissionTo(['view it-finance', 'edit it-finance', 'export it-finance', 'import it-finance']);
        $this->ccChild->forceFill(['owner_id' => $viewer->id])->save(); // a scoped viewer sees the cost centres he owns (and their parent, which holds the investment)
        $this->cc->forceFill(['owner_id' => $viewer->id])->save();

        return $viewer;
    }

    public function test_pages_render_with_the_contract_keys(): void // FIN-03.09, .10, .12, .13, FIN-05.10
    {
        $a = $this->props($this->actingAs($this->fin)->get(route('finance.cloud.accounts.index'))->assertOk());
        $this->keys($a, ['accounts', 'tagRules', 'batches', 'options', 'costCentres', 'applications', 'businessServices', 'contracts', 'users', 'can', 'baseCurrency']);
        $this->assertCount(1, $a['accounts']);
        $this->keys($a['accounts'][0], ['provider', 'account_identifier', 'default_cost_centre', 'tag_mapping', 'mtd', 'lines_count', 'untagged_pct', 'open_anomalies', 'last_batch', 'can']);
        $this->assertSame(['providers', 'formats', 'dimensions', 'currencies'], array_keys($a['options']));
        $this->assertArrayHasKey('columns', $a['options']['formats'][0]);

        $c = $this->props($this->actingAs($this->fin)->get(route('finance.cloud.costs', ['from' => '2027-03-10', 'to' => '2027-04-04']))->assertOk());
        $this->keys($c, ['tab', 'filters', 'summary', 'series', 'groups', 'untagged', 'ledger', 'accounts', 'tagKeys', 'options', 'applications', 'businessServices', 'costCentres', 'can']);
        $this->keys($c['summary'], ['total', 'untagged', 'untagged_pct', 'days', 'mtd', 'prior_mtd']);
        $this->assertSame(25, $c['summary']['days']);
        $this->assertNotEmpty($c['groups']);
        $this->keys($c['ledger'], ['period', 'finops_total', 'cloud_ledger_actual', 'gap', 'gap_pct']);
        $u = $this->props($this->actingAs($this->fin)->get(route('finance.cloud.costs', ['tab' => 'untagged']))->assertOk());
        $this->assertSame('untagged', $u['tab']);

        $n = $this->props($this->actingAs($this->fin)->get(route('finance.cloud.anomalies.index'))->assertOk());
        $this->keys($n, ['filters', 'counts', 'anomalies', 'thresholds', 'accounts', 'options', 'can']);
        $this->assertArrayHasKey('meta', $n['anomalies']);
        $this->assertGreaterThanOrEqual(1, $n['counts']['open']);
        $this->keys($n['anomalies']['data'][0], ['cloud_account', 'detected_on', 'scope_label', 'method', 'deviation_pct', 'status', 'can', 'baseline_cost', 'observed_cost']);

        $al = $this->props($this->actingAs($this->fin)->get(route('finance.allocations.index'))->assertOk());
        $this->keys($al, ['filters', 'rules', 'coverage', 'runs', 'sources', 'targets', 'drivers', 'fiscalYears', 'periods', 'firstOpenDate', 'options', 'can']);
        $this->assertCount(1, $al['rules']);
        $this->assertSame('40.000000', $al['rules'][0]['share_pct']);
        $this->keys($al['sources'], ['cost_centre', 'category', 'budget_line', 'contract']);
        $this->keys($al['targets'], ['business_unit', 'cost_centre', 'business_service', 'ea_application', 'investment', 'legal_entity']);

        $s = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.showback'))->assertOk());
        $this->keys($s, ['filters', 'enabled', 'statement', 'detail', 'cloudMemo', 'chargeback', 'fiscalYears', 'periods', 'options', 'can']);
        $this->keys($s['statement'], ['target_type', 'pools', 'rows', 'totals']);
        $this->keys($s['statement']['totals'], ['direct', 'allocated', 'total', 'retained', 'it_total', 'reconciles', 'difference']);
        $this->keys($s['chargeback'], ['periods', 'runs', 'preview']);

        $t = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.tco'))->assertOk());
        $this->keys($t, ['filters', 'subjectAvailable', 'rows', 'totals', 'rationalisation', 'fiscalYears', 'can']);
        $this->keys($t['totals'], ['tco_total', 'it_total', 'coverage_pct', 'unattributed']);
        $this->keys($t['subjectAvailable'], ['ea_application', 'business_service']);

        $i = $this->props($this->actingAs($this->fin)->get(route('finance.capitalisations.index'))->assertOk());
        $this->keys($i, ['filters', 'capitalisations', 'summary', 'candidates', 'assets', 'users', 'options', 'defaults', 'can']);
        $this->assertSame(1, $i['summary']['by_status']['draft']);
        $this->assertSame($this->inv->investment_code.' '.$this->inv->title, $i['candidates'][0]['label']);
        $this->assertSame(['ias_38', 'ias_16'], array_keys($i['options']['checklists']));

        $show = $this->props($this->actingAs($this->fin)->get(route('finance.capitalisations.show', $this->cap))->assertOk());
        $this->keys($show, ['capitalisation', 'schedule', 'scheduleByYear', 'eligibility', 'trail', 'documents', 'activity', 'assets', 'users', 'options', 'can']);
        $this->keys($show['capitalisation'], ['criteria_checklist', 'excluded_amount', 'booking_period', 'amount_band_label', 'nbv_today', 'source', 'asset']);
        $this->assertSame('900000.00', $show['eligibility']['pool']);
    }

    public function test_the_page_filters_and_tco_attribution_picker(): void // FIN-05.10
    {
        $t = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.tco', ['subject' => 'business_service', 'unattributed' => 1]))->assertOk());
        $this->assertNotEmpty($t['unattributed']);
        $this->keys($t['unattributed'][0], ['id', 'label', 'cost_centre', 'current_base_amount']);
        $this->assertSame([], $this->props($this->actingAs($this->fin)->get(route('finance.monitor.tco', ['subject' => 'business_service']))->assertOk())['unattributed']);
    }

    public function test_masking_and_amount_gates(): void // FIN-07.10
    {
        $viewer = $this->viewerWithoutAmounts();
        $costs = $this->props($this->actingAs($viewer)->get(route('finance.cloud.costs', ['from' => '2027-03-10', 'to' => '2027-04-04']))->assertOk());
        $this->assertFalse($costs['can']['viewAmounts']);
        foreach (['total', 'untagged', 'mtd', 'prior_mtd'] as $k) {
            $this->assertNull($costs['summary'][$k], $k);
        }
        $this->assertSame([], $costs['series']);
        $this->assertNull($costs['groups'][0]['cost']);
        $this->assertNotNull($costs['summary']['untagged_pct']);
        $this->assertNotNull($costs['groups'][0]['share_pct']);
        $this->assertIsInt($costs['groups'][0]['lines']);

        $an = $this->props($this->actingAs($viewer)->get(route('finance.cloud.anomalies.index'))->assertOk());
        $row = $an['anomalies']['data'][0];
        foreach (['baseline_cost', 'observed_cost', 'baseline_base_cost', 'observed_base_cost'] as $k) {
            $this->assertNull($row[$k], $k);
        }
        $this->assertNotNull($row['deviation_pct']);
        $this->assertNotNull($row['method']);

        $cap = $this->props($this->actingAs($viewer)->get(route('finance.capitalisations.index'))->assertOk());
        $this->assertNull($cap['capitalisations']['data'][0]['amount']);
        $this->assertNull($this->props($this->actingAs($viewer)->get(route('finance.capitalisations.show', $this->cap))->assertOk())['capitalisation']['amount']);

        $this->actingAs($viewer)->get(route('finance.monitor.tco.detail', ['subject' => 'business_service', 'id' => 1]))->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.monitor.showback.export'))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('finance.chargeback.store'), ['period_id' => $this->periodSeq(1)->id, 'target_type' => 'business_unit'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('finance.cloud.import'), [])->assertForbidden(); // import without amounts
    }

    public function test_other_banks_records_are_a_404(): void // tenancy
    {
        $bank = $this->otherBank();
        $them = $this->userWith('IT Finance Controller', [], $bank->id);
        $cc = CostCentre::withoutGlobalScopes()->create(['organization_id' => $bank->id, 'code' => 'X-1', 'name' => 'Other']);
        $acct = CloudAccount::withoutGlobalScopes()->create(['organization_id' => $bank->id, 'provider' => 'aws', 'account_identifier' => '999', 'name' => 'Theirs', 'default_format' => 'generic', 'currency' => 'USD', 'default_cost_centre_id' => $cc->id]);
        $anomaly = CloudAnomaly::withoutGlobalScopes()->create(['organization_id' => $bank->id, 'cloud_account_id' => $acct->id, 'detected_on' => '2027-04-01', 'scope' => 'account', 'scope_ref' => '*', 'method' => 'rolling_7d',
            'baseline_cost' => 1, 'observed_cost' => 5, 'baseline_base_cost' => 1, 'observed_base_cost' => 5, 'deviation_pct' => 400, 'status' => 'open']);
        $rule = CloudTagRule::withoutGlobalScopes()->create(['organization_id' => $bank->id, 'tag_key' => 'k', 'tag_value' => 'v', 'dimension' => 'cost_centre', 'target_id' => $cc->id]);
        $alloc = Allocation::withoutGlobalScopes()->create(['organization_id' => $bank->id, 'allocation_code' => 'ALC-0001', 'name' => 'x', 'source_type' => 'cost_centre', 'source_id' => $cc->id, 'target_type' => 'business_unit',
            'target_ref' => 'RET', 'method' => 'direct', 'percentage' => 100, 'effective_from' => '2027-01-01']);
        $run = ChargebackRun::withoutGlobalScopes()->create(['organization_id' => $bank->id, 'run_code' => 'CBK-0001', 'period_id' => $this->periodSeq(1)->id, 'target_type' => 'business_unit', 'status' => 'exported', 'currency' => 'NGN',
            'file_sha256' => str_repeat('a', 64), 'content' => 'x', 'exported_by_id' => $them->id, 'exported_at' => now()]);
        $cap = Capitalisation::withoutGlobalScopes()->create(['organization_id' => $bank->id, 'capitalisation_code' => 'CAP-0001', 'title' => 'x', 'source_type' => 'investment', 'standard' => 'ias_38',
            'available_for_use_on' => '2027-03-01', 'amount' => 1, 'useful_life_months' => 12, 'method' => 'straight_line', 'criteria_checklist' => '[]', 'status' => 'draft']);

        $me = $this->actingAs($this->fin);
        $me->put(route('finance.cloud.accounts.update', $acct), [])->assertNotFound();
        $me->delete(route('finance.cloud.accounts.destroy', $acct))->assertNotFound();
        $me->post(route('finance.cloud.accounts.reallocate', $acct))->assertNotFound();
        $me->post(route('finance.cloud.anomalies.acknowledge', $anomaly))->assertNotFound();
        $me->put(route('finance.cloud.tag-rules.update', $rule), [])->assertNotFound();
        $me->delete(route('finance.cloud.tag-rules.destroy', $rule))->assertNotFound();
        $me->put(route('finance.allocations.update', $alloc), [])->assertNotFound();
        $me->delete(route('finance.allocations.destroy', $alloc))->assertNotFound();
        $me->get(route('finance.chargeback.download', $run))->assertNotFound();
        $me->post(route('finance.chargeback.void', $run), ['reason' => 'Not my bank at all'])->assertNotFound();
        $me->get(route('finance.capitalisations.show', $cap))->assertNotFound();
        $me->post(route('finance.capitalisations.submit', $cap))->assertNotFound();
        $me->post(route('finance.cloud.import'), ['cloud_account_id' => $acct->id, 'file' => $this->fixtureUpload('aws_cur')])->assertNotFound();
    }

    private function fixtureUpload(string $name): UploadedFile
    {
        return new UploadedFile(base_path("tests/Fixtures/finance/cloud/{$name}.csv"), "{$name}.csv", 'text/csv', null, true);
    }

    public function test_cloud_import_creates_a_batch_and_lines_and_the_batch_page_renders(): void // FIN-03.09
    {
        $aws = $this->makeCloudAccount(['account_identifier' => '402112345688x', 'name' => 'Other AWS']); // a different identifier, so the fixture rows mismatch
        $this->assertNotSame($aws->id, 0);
        $account = CloudAccount::query()->where('account_identifier', '402112345688')->firstOrFail();

        $this->actingAs($this->fin)->post(route('finance.cloud.import'), ['cloud_account_id' => $account->id, 'format' => 'aws_cur', 'file' => $this->fixtureUpload('aws_cur')])->assertRedirect();
        $batch = ImportBatch::query()->where('type', 'cloud')->firstOrFail();
        $this->assertSame($account->id, (int) $batch->cloud_account_id);
        $this->assertSame('completed_with_errors', $batch->status);
        $this->assertSame(8, $batch->accepted_count);
        $this->assertSame(2, $batch->rejected_count); // other account, 1e5
        $this->assertSame(8, CloudCostLine::query()->where('import_batch_id', $batch->id)->count());
        $this->assertNotNull(session('import_report'));

        $show = $this->props($this->actingAs($this->fin)->get(route('finance.imports.show', $batch))->assertOk());
        $this->assertSame(8, $show['cloudSummary']['lines']);
        $this->assertSame('2026-08-03', $show['cloudSummary']['dates']['from']);
        $this->assertNotEmpty($show['cloudSummary']['by_service']);
        $this->assertSame([], $show['actuals']);
        $this->assertSame(0, Actual::query()->where('import_batch_id', $batch->id)->count()); // a FinOps sub-ledger: nothing is posted (P6-1)

        $this->actingAs($this->fin)->post(route('finance.cloud.import'), ['cloud_account_id' => $account->id, 'file' => $this->fixtureUpload('aws_cur')])->assertSessionHasErrors('file'); // same sha256
    }

    public function test_classify_refuses_another_banks_line_and_a_closed_fiscal_year(): void // P6-17
    {
        $bank = $this->otherBank();
        $them = $this->userWith('IT Finance Controller', [], $bank->id);
        $other = $this->actingAs($them);
        $this->assertNotNull($other);
        $line = $this->monLines[1];
        $svc = app(BudgetLineClassifier::class);

        $this->assertRefused(fn () => $svc->classify([$line->id], ['business_service_id' => 999999], $this->fin), 'business_service_id');
        $this->assertRefused(fn () => $svc->classify([$line->id], ['ea_application_id' => null, 'business_service_id' => null], $them), 'line_ids'); // not in their bank

        $this->actingAs($this->fin);
        $this->assertSame(0, $svc->classify([$line->id], ['business_service_id' => null], $this->fin)); // nothing to change
        $this->assertDatabaseHas('fin_budget_lines', ['id' => $line->id, 'business_service_id' => null]);

        FiscalYear::withoutGlobalScopes()->whereKey($this->fy->id)->update(['status' => 'closed']);
        $this->assertRefused(fn () => $svc->classify([$line->id], ['business_service_id' => null], $this->fin), 'line_ids', 'fiscal year closed');
    }

    public function test_allocation_store_refuses_a_group_over_100_and_mixed_methods(): void // P6-5
    {
        $this->makeAllocation(['name' => 'Second', 'target_ref' => 'CIB', 'percentage' => '60']);
        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'Over', 'target_ref' => 'TRS', 'percentage' => '1']), 'percentage', '100');
        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'Mixed', 'target_ref' => 'TRS', 'method' => 'direct']), 'method');
        $driver = Driver::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'HEAD', 'name' => 'Headcount', 'unit' => 'people', 'is_active' => true]);
        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'Driver', 'target_ref' => 'TRS', 'method' => 'driver', 'driver_id' => $driver->id, 'driver_quantity' => '10']), 'method');
        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'Bad unit', 'target_ref' => 'ZZZ', 'source_id' => $this->cc2->id]), 'target_ref');
    }

    public function test_chargeback_refuses_an_open_period(): void // P6-10
    {
        $p = $this->periodSeq(1);
        $this->actingAs($this->fin)->postJson(route('finance.chargeback.store'), ['period_id' => $p->id, 'target_type' => 'business_unit'])
            ->assertStatus(422)->assertJsonValidationErrors('period_id');
        $this->assertDatabaseCount('fin_chargeback_runs', 0);
    }

    public function test_settings_finops_section_saves_units_and_the_page_exposes_the_policy(): void // P6-9
    {
        $payload = ['section' => 'finops', 'cloud_anomaly_pct' => 40, 'cloud_anomaly_abs_floor' => 100000, 'chargeback_debit_gl' => '6955', 'chargeback_credit_gl' => '6956',
            'showback_units' => [['code' => 'ret', 'name' => 'Retail', 'erp_cost_centre_code' => '5100'], ['code' => 'OPS', 'name' => 'Operations', 'erp_cost_centre_code' => null]]];
        $this->actingAs($this->fin)->put(route('finance.settings.update'), $payload)->assertRedirect();
        $page = $this->props($this->actingAs($this->fin)->get(route('finance.settings', ['tab' => 'finops']))->assertOk());
        $this->assertSame('40.00', $page['finopsPolicy']['cloud_anomaly_pct']);
        $this->assertSame(['RET', 'OPS'], array_column($page['finopsPolicy']['showback_units'], 'code'));
        $this->assertSame('6955', $page['finopsPolicy']['chargeback_debit_gl']);

        $payload['showback_units'] = [['code' => 'OPS', 'name' => 'Operations']]; // RET is used by the active allocation rule
        $this->actingAs($this->fin)->put(route('finance.settings.update'), $payload)->assertSessionHasErrors('showback_units');
        $payload['showback_units'] = [['code' => 'RET', 'name' => 'A'], ['code' => 'RET', 'name' => 'B']];
        $this->actingAs($this->fin)->put(route('finance.settings.update'), $payload)->assertSessionHasErrors('showback_units');
    }

    public function test_anomaly_actions_over_http(): void // FIN-03.10
    {
        $x = CloudAnomaly::query()->where('scope', 'account')->firstOrFail();
        $this->actingAs($this->requester)->post(route('finance.cloud.anomalies.acknowledge', $x))->assertRedirect(); // the account owner
        $this->assertSame('acknowledged', $x->refresh()->status);
        $this->actingAs($this->requester)->post(route('finance.cloud.anomalies.resolve', $x), ['note' => 'short'])->assertSessionHasErrors('note');
        $this->actingAs($this->requester)->post(route('finance.cloud.anomalies.resolve', $x), ['note' => 'A runaway test job was stopped.'])->assertRedirect();
        $this->assertSame('resolved', $x->refresh()->status);
        $this->actingAs($this->requester)->post(route('finance.cloud.anomalies.dismiss', $x), ['note' => 'Already resolved, cannot dismiss'])->assertSessionHasErrors('workflow');
        $stranger = $this->userWith('IT Budget Owner');
        $other = CloudAnomaly::query()->where('scope', 'service')->firstOrFail();
        $this->actingAs($stranger)->post(route('finance.cloud.anomalies.acknowledge', $other))->assertForbidden(); // not the owner and cannot manage cloud
    }

    public function test_capitalisation_flows_over_http(): void // FIN-03.13
    {
        $c = $this->cap;
        $this->actingAs($this->fin)->post(route('finance.capitalisations.submit', $c))->assertRedirect();
        $this->assertSame('submitted', $c->refresh()->status);
        $this->actingAs($this->fin)->post(route('finance.capitalisations.approve', $c))->assertSessionHasErrors('approval'); // the preparer cannot approve his own (segregation of duties)
        $this->actingAs($this->cfo)->post(route('finance.capitalisations.approve', $c))->assertRedirect();
        $this->assertSame('capitalised', $c->refresh()->status);
        $this->assertNotNull($c->asset_id);
        $this->assertSame(60, $c->lines()->count());
    }

    public function test_the_new_pages_are_visible_but_actions_are_gated(): void // permissions
    {
        $viewer = $this->userWith('IT Budget Owner');
        foreach (['finance.cloud.accounts.index', 'finance.cloud.costs', 'finance.cloud.anomalies.index', 'finance.allocations.index', 'finance.monitor.showback', 'finance.monitor.tco', 'finance.capitalisations.index'] as $r) {
            $this->actingAs($viewer)->get(route($r))->assertOk();
        }
        $this->actingAs($viewer)->post(route('finance.allocations.run'), ['fiscal_year_id' => $this->fy->id, 'amount_types' => ['budget']])->assertForbidden();
        $this->actingAs($viewer)->post(route('finance.cloud.accounts.store'), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('finance.capitalisations.approve', $this->cap))->assertForbidden();
        $this->actingAs($this->fin)->post(route('finance.allocations.run'), ['fiscal_year_id' => $this->fy->id, 'amount_types' => ['budget', 'actual']])->assertRedirect();
        $this->assertGreaterThan(0, AllocationRun::query()->count());
    }

    public function test_a_run_stores_results_that_the_showback_statement_reconciles(): void // FIN-03.12, FIN-02.12
    {
        $p1 = $this->periodSeq(1);
        $this->postActual($this->monLines[0], $p1, '800000.00');
        $this->postActual($this->monLines[1], $p1, '300000.00', ['category_id' => $this->cat('TW-APP-SUP')]);
        $this->postActual($this->poolLines['CP-LAB'], $p1, '200000.00');
        app(AllocationService::class)->run($this->org->id, $this->fy->id, [$p1->id], ['budget', 'actual'], $this->fin);

        $query = ['to_period_id' => $p1->id, 'from_period_id' => $p1->id];
        $budget = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.showback', $query + ['basis' => 'budget']))->assertOk())['statement'];
        $this->assertSame('business_unit:RET', $budget['rows'][0]['target_key']);
        $this->assertSame('1200000.00', $budget['rows'][0]['total']); // 40 % of CC-1A's three 1,000,000 lines
        $this->assertSame('5500000.00', $budget['totals']['it_total']);
        $this->assertSame('4300000.00', $budget['totals']['retained']);
        $this->assertTrue($budget['totals']['reconciles']);
        $this->assertSame(['CP-SW'], array_column($budget['pools'], 'code'));

        $actual = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.showback', $query + ['basis' => 'actual']))->assertOk())['statement'];
        $this->assertSame('440000.00', $actual['rows'][0]['allocated']); // 40 % of 800,000 + 300,000
        $this->assertSame('1200000.00', $actual['rows'][0]['budget_total']);
        $this->assertSame('1300000.00', $actual['totals']['it_total']);
        $this->assertSame('860000.00', $actual['totals']['retained']);
        $this->assertTrue($actual['totals']['reconciles']);

        // The stored results freeze with the period: a later run leaves a closed period alone.
        $this->postActual($this->monLines[2], $p1, '100000.00');
        $this->closePeriodDirectly($p1);
        $out = app(AllocationService::class)->run($this->org->id, $this->fy->id, [$p1->id], ['actual'], $this->fin);
        $this->assertSame('frozen', collect($out)->firstWhere('target_type', 'business_unit')['status']);
        $this->assertSame('440000.00', (string) AllocationRun::query()->where('period_id', $p1->id)->where('amount_type', 'actual')->value('allocated_total'));
    }

    public function test_chargeback_exports_a_balanced_journal_once_until_it_is_voided(): void // FIN-03.12, P6-10
    {
        $p1 = $this->periodSeq(1);
        $this->postActual($this->monLines[0], $p1, '800000.00');
        app(AllocationService::class)->run($this->org->id, $this->fy->id, [$p1->id], ['actual'], $this->fin);
        $this->closePeriodDirectly($p1);
        $body = ['period_id' => $p1->id, 'target_type' => 'business_unit'];

        $r = $this->actingAs($this->fin)->post(route('finance.chargeback.store'), $body)->assertOk();
        $csv = $r->getContent();
        $this->assertStringStartsWith('journal_ref,posting_date,line_no,gl_code', $csv);
        $this->assertStringContainsString('5100-RET', $csv); // the receiving ERP cost centre of the RET unit
        $this->assertStringContainsString('CC-1A', $csv); // the IT cost centre credited
        $run = ChargebackRun::query()->firstOrFail();
        $this->assertSame('320000.00', (string) $run->total_debit); // 40 % of 800,000
        $this->assertSame((string) $run->total_debit, (string) $run->total_credit);
        $this->assertSame(hash('sha256', $csv), $run->file_sha256);
        $this->actingAs($this->fin)->get(route('finance.chargeback.download', $run))->assertOk()->assertHeader('X-Content-SHA256', $run->file_sha256);

        $this->actingAs($this->fin)->postJson(route('finance.chargeback.store'), $body)->assertStatus(422); // already exported
        $closer = $this->userWith('IT Finance Controller'); // a different closer voids (SoD)
        $this->actingAs($closer)->post(route('finance.chargeback.void', $run), ['reason' => 'short'])->assertSessionHasErrors();
        $this->actingAs($closer)->post(route('finance.chargeback.void', $run), ['reason' => 'Wrong ERP cost centre on the RET unit.'])->assertRedirect();
        $this->actingAs($this->fin)->post(route('finance.chargeback.store'), $body)->assertOk();
        $this->assertSame(['voided', 'exported'], ChargebackRun::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_tco_counts_cloud_once_and_keeps_ledger_cloud_on_the_budget_basis(): void // FIN-05.10, P6-15
    {
        $svc = BusinessService::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'BS-001', 'name' => 'Mobile Banking']);
        $this->assertSame(2, app(BudgetLineClassifier::class)->classify([$this->monLines[0]->id, $this->poolLines['CP-CLD']->id], ['business_service_id' => $svc->id], $this->fin));
        $this->actingAs($this->fin)->post(route('finance.budgets.lines.classify'), ['line_ids' => [$this->monLines[0]->id], 'business_service_id' => $svc->id])->assertRedirect(); // idempotent
        $this->postActual($this->monLines[0], $this->periodSeq(1), '800000.00');
        $this->postActual($this->poolLines['CP-CLD'], $this->periodSeq(1), '500000.00'); // the invoiced cloud spend in the ledger
        $second = $this->makeCloudAccount(['account_identifier' => '777', 'name' => 'Analytics']);
        $this->ingest($this->cloudRows('2027-02-01', 10, ['Compute'], ['Service' => 'BS-001'], 100), $second); // 10 days x 100 USD x 1500

        $actual = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.tco', ['subject' => 'business_service', 'basis' => 'actual']))->assertOk());
        $row = collect($actual['rows'])->firstWhere('id', $svc->id);
        $this->assertSame('1700000.00', $row['direct']); // 800,000 posted here and the 900,000 capex booked by setUp on the same line
        $this->assertSame('1500000.00', $row['cloud']);
        $this->assertSame('3200000.00', $row['total']); // the 500,000 ledger cloud is replaced by the FinOps 1,500,000
        $this->assertSame('1500000.00', $row['by_pool']['cloud']);

        $budget = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.tco', ['subject' => 'business_service', 'basis' => 'budget']))->assertOk());
        $this->assertSame('18000000.00', collect($budget['rows'])->firstWhere('id', $svc->id)['total']); // 12m software line + 6m cloud line

        $detail = $this->actingAs($this->fin)->getJson(route('finance.monitor.tco.detail', ['subject' => 'business_service', 'id' => $svc->id, 'basis' => 'actual']))->assertOk()->json();
        $this->assertSame('Mobile Banking', $detail['label']);
        $this->assertSame('Compute', $detail['components']['cloud'][0]['service']);
        $this->assertNotEmpty($detail['components']['direct']);
    }
}
