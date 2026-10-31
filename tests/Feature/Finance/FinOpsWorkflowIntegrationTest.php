<?php

namespace Tests\Feature\Finance;

use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Ea\EaApplication;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\Investment;
use App\Models\Finance\VarianceNote;
use App\Models\Kri;
use App\Models\KriReading;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Notifications\Finance\CloudAnomalyNotification;
use App\Notifications\Finance\ImportCompletedNotification;
use App\Services\Ea\CostModelService;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\CloudAnomalyService;
use App\Services\Finance\CloudAnomalySweeper;
use App\Services\Finance\FinanceImportNotifier;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\PeriodCloseService;
use App\Services\Finance\Reports\FinanceReports;
use App\Services\Finance\Reports\ReportExporter;
use App\Services\Finance\VarianceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/** P6 Task B2 wiring: capitalisation through Workflow Studio, the cloud anomaly sweep, KRI, dashboard, period close, reports and the EA hook. */
class FinOpsWorkflowIntegrationTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    private Investment $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
        $this->cfo->givePermissionTo('view it-finance');
    }

    private function seedCloud(): void
    {
        $this->makeCloudAccount();
        $this->ingest($this->cloudRows('2027-03-10', 25, ['Amazon EC2', 'Amazon S3'], ['Service' => 'mobile'], 100));
        $this->ingest($this->spike('2027-04-03', 'Amazon EC2', 10));
    }

    private function submittedCap(string $amount = '900000.00', bool $defaults = true)
    {
        $this->inv = $this->inDelivery($this->approvedInvestment());
        $this->flagCapitalisable($this->inv);
        $this->capexActual($this->inv, $amount, '2027-03-20');
        $c = $this->makeCapitalisation($this->inv->refresh(), [], true);
        $defaults && FinancePolicySeeder::ensureDefaults($this->org->id);

        return app(CapitalisationService::class)->submit($c, $this->fin);
    }

    private function refused(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a refusal.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_capitalisation_defaults_seed_two_stages_and_cfo_stage_is_conditional(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $wf = ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', 'finance.capitalisation_approval')->with('stages')->get();
        $this->assertCount(1, $wf);
        $stages = $wf[0]->stages->sortBy('sequence')->values();
        $this->assertSame(['IT Finance Controller', 'Chief Financial Officer'], $stages->pluck('approver_ref')->all());
        $this->assertSame(['requires_cfo'], $stages->pluck('condition_field')->filter()->values()->all());
    }

    public function test_below_the_cfo_threshold_finance_alone_capitalises_and_sod_holds(): void
    {
        $c = $this->submittedCap();
        $this->assertSame('no', $c->requires_cfo);
        $this->refused(fn () => app(CapitalisationService::class)->approve($c, $this->fin)); // the preparer
        $second = $this->userWith('IT Finance Controller', ['job_title' => 'IT Finance Controller']);
        $second->givePermissionTo('approve it-finance');
        $this->refused(fn () => app(CapitalisationService::class)->approve($c, $this->manager)); // the investment manager
        $this->decide($c, 'finance.capitalisation_approval', $second);
        $this->assertSame('capitalised', $c->refresh()->status);
    }

    public function test_cfo_stage_applies_at_the_threshold(): void
    {
        config(['finance.approval_defaults.capitalisation_cfo_from' => 500000]);
        $c = $this->submittedCap();
        $this->assertSame('yes', $c->requires_cfo);
        $second = $this->userWith('IT Finance Controller', ['job_title' => 'IT Finance Controller']);
        $second->givePermissionTo('approve it-finance');
        $svc = app(CapitalisationService::class);
        $this->assertNotSame('', $svc->approve($c, $second));
        $this->assertSame('submitted', $c->refresh()->status, 'the CFO stage remains');
        $this->refused(fn () => $svc->approve($c, $second)); // nobody decides two stages
        $svc->approve($c->refresh(), $this->cfo);
        $this->assertSame('capitalised', $c->refresh()->status);
    }

    public function test_unconfigured_default_approver_is_anyone_with_the_permission_but_the_preparer(): void
    {
        $c = $this->submittedCap(defaults: false);
        $this->refused(fn () => app(CapitalisationService::class)->approve($c, $this->fin));
        app(CapitalisationService::class)->approve($c, $this->cfo);
        $this->assertSame('capitalised', $c->refresh()->status);
    }

    public function test_cloud_anomaly_sweep_notifies_once_owner_and_controller_only_with_view_permission(): void
    {
        $this->makeCloudAccount();
        $this->ingest($this->cloudRows('2027-03-10', 25, ['Amazon EC2', 'Amazon S3'], ['Service' => 'mobile'], 100));
        $this->ingest($this->spike('2027-04-03', 'Amazon EC2', 10));
        $noView = User::factory()->create(['organization_id' => $this->org->id]);
        $this->cloudAccount->forceFill(['owner_id' => $noView->id])->save();
        Notification::fake();
        $this->assertGreaterThan(0, app(CloudAnomalySweeper::class)->sweep(CarbonImmutable::parse('2027-04-05')));
        Notification::assertSentTo($this->fin, CloudAnomalyNotification::class);
        Notification::assertNotSentTo($noView, CloudAnomalyNotification::class);
        $n = Notification::sent($this->fin, CloudAnomalyNotification::class)->count();
        $this->assertSame(0, app(CloudAnomalySweeper::class)->sweep(CarbonImmutable::parse('2027-04-05')), 'second run sends nothing');
        $this->assertSame($n, Notification::sent($this->fin, CloudAnomalyNotification::class)->count());
    }

    public function test_cloud_anomaly_command_and_schedule(): void
    {
        $this->seedCloud();
        Notification::fake();
        $this->artisan('fin:cloud-anomalies', ['--date' => '2027-04-05'])->assertExitCode(0);
        Notification::assertSentTo($this->requester, CloudAnomalyNotification::class); // the account owner
        $this->artisan('fin:cloud-anomalies', ['--date' => '2027-04-05'])->expectsOutput('0 cloud anomaly notice(s) sent.')->assertExitCode(0);
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'fin:cloud-anomalies'));
        $this->assertNotNull($event);
        $this->assertSame('45 5 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_cloud_anomaly_notification_renders_without_amounts(): void
    {
        $n = new CloudAnomalyNotification('Prod AWS', 'Amazon EC2', '03 Apr 2027', '850.00', 'rolling_7d', 'https://x.test/finance/cloud/anomalies');
        $arr = $n->toArray($this->fin);
        $this->assertSame('finance.cloud_anomaly', $arr['type']);
        $this->assertSame('IT Financial Management', $arr['module']);
        $this->assertSame('https://x.test/finance/cloud/anomalies', $arr['action_url']);
        $this->assertStringContainsString('Cloud cost anomaly', $n->toMail($this->fin)->subject);
        $this->assertDoesNotMatchRegularExpression('/NGN|USD|₦|\$/', $arr['message']);
    }

    public function test_kri_fin_cld_is_measured(): void
    {
        $this->seedCloud();
        app(CloudAnomalyService::class)->detect($this->cloudAccount, CarbonImmutable::parse('2027-04-05'));
        Kri::withoutGlobalScopes()->updateOrCreate(['organization_id' => $this->org->id, 'code' => 'KRI-FIN-CLD'], [
            'name' => 'Cloud anomalies', 'category' => 'finance', 'threshold_green' => 0, 'threshold_amber' => 2, 'threshold_red' => 5,
            'direction' => 'higher_worse', 'unit' => 'count', 'frequency' => 'monthly', 'source' => 'platform', 'owner_id' => $this->fin->id,
        ]);
        Artisan::call('kri:measure');
        $id = Kri::withoutGlobalScopes()->where('code', 'KRI-FIN-CLD')->value('id');
        $this->assertGreaterThan(0, (float) KriReading::withoutGlobalScopes()->where('kri_id', $id)->value('value'));
    }

    public function test_dashboard_cloud_metrics_and_masking(): void
    {
        $m = app(FinanceMetrics::class)->summary($this->org->id, $this->fy->id);
        $this->assertNull($m['cloud_mtd'], 'no cloud account');
        $this->actingAs($this->fin)->get(route('finance.dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('metrics.cloud_mtd', null)->where('cloudTrend', []));

        $this->seedCloud();
        app(CloudAnomalyService::class)->detect($this->cloudAccount, CarbonImmutable::parse('2027-04-05'));
        $this->actingAs($this->fin)->get(route('finance.dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->whereNot('metrics.cloud_mtd', null)->where('cloudTrendMasked', false)->has('cloudTrend')->where('metrics.cloud_anomalies_open', fn ($v) => $v >= 1));

        $viewer = $this->userWith('IT Budget Owner');
        $viewer->roles()->detach();
        $viewer->givePermissionTo(['view it-finance']);
        $this->actingAs($viewer)->get(route('finance.dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('metrics.cloud_mtd', null)->where('cloudTrend', [])->where('cloudTrendMasked', true));
    }

    public function test_period_close_cloud_item_and_close_freezes_allocations(): void
    {
        $svc = app(PeriodCloseService::class);
        $p1 = $this->periodSeq(1);
        $item = fn () => collect($svc->checklist($p1->refresh())['items'])->firstWhere('key', 'cloud_imported');
        $this->assertSame('n_a', $item()['status']);

        $this->makeCloudAccount();
        $this->ingest($this->cloudRows('2027-01-01', 20));
        $this->assertSame('warn', $item()['status']);
        config(['finance.cloud.close_requires_import' => true]);
        $this->assertSame('fail', $item()['status']);
        config(['finance.cloud.close_requires_import' => false]);
        $this->ingest($this->cloudRows('2027-01-21', 12));
        $this->assertSame('pass', $item()['status']);
        $this->assertStringContainsString('/cloud/costs?to=2027-01-31', $item()['href']);

        $this->makeAllocation();
        foreach ([0, 1, 2] as $i) {
            $this->postActual($this->monLines[$i], $p1, '1000000.00');
        }
        app(VarianceService::class)->evaluate($p1, $this->fin);
        $this->explainAll($p1);
        foreach (VarianceNote::withoutGlobalScopes()->where('period_id', $p1->id)->where('status', 'explained')->get() as $__n) {
            app(VarianceService::class)->acknowledge($__n, $this->fin); // P7-5
        }
        $svc->close($p1, $this->fin);
        $this->assertSame('closed', $p1->refresh()->status);
        $runs = AllocationRun::withoutGlobalScopes()->where('period_id', $p1->id)->get();
        $this->assertNotEmpty($runs);
        $this->assertTrue($runs->every(fn ($r) => $r->is_frozen));
    }

    public function test_the_four_reports_export_in_every_format(): void
    {
        $this->seedCloud();
        $c = $this->submittedCap(defaults: false);
        app(CapitalisationService::class)->approve($c, $this->cfo);
        $this->makeAllocation();
        $reports = app(FinanceReports::class);
        $ex = new ReportExporter;
        $this->assertCount(14, $reports->available());
        foreach (['cloud_spend', 'showback_statement', 'capitalisation_register', 'application_tco'] as $key) {
            $r = $reports->run($key, $this->org->id, [], $this->fin);
            $this->assertNotEmpty($r['columns'], $key);
            $this->assertStringStartsWith('PK', $ex->xlsx($r, $key));
            $this->assertStringStartsWith('%PDF', $ex->pdf($r, $key));
            $this->assertNotSame('', $ex->csv($r, $key));
            $this->assertArrayHasKey('finance.'.$key, ScheduledReport::TYPES);
            $this->actingAs($this->fin)->get(route('finance.reports.show', [$key, 'format' => 'csv']))->assertOk();
        }
        $cloud = $reports->run('cloud_spend', $this->org->id, [], $this->fin);
        $this->assertNotEmpty($cloud['rows']);
        $this->assertCount(1, $reports->run('capitalisation_register', $this->org->id, [], $this->fin)['rows']);
        $viewer = $this->userWith('IT Budget Owner');
        $viewer->roles()->detach();
        $viewer->givePermissionTo(['view it-finance', 'export it-finance']);
        $this->cc->forceFill(['owner_id' => $viewer->id])->save();
        $this->ccChild->forceFill(['owner_id' => $viewer->id])->save();
        $masked = $reports->run('cloud_spend', $this->org->id, [], $viewer);
        $this->assertNotEmpty($masked['rows']);
        $this->assertNull($masked['rows'][0]['base_cost']);
    }

    public function test_tco_carries_measured_ngn_additively_and_flag_off_is_null(): void
    {
        $app = EaApplication::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Mobile app', 'code' => 'APP-M', 'tco_annual_ngn' => 1000000, 'criticality' => 'high', 'lifecycle' => 'active']);
        $this->assertArrayNotHasKey('measured_ngn', app(CostModelService::class)->tco($app)); // tco() is pure EA
        $tco = app(CostModelService::class)->tcoWithFinance($app, $this->fin);
        $this->assertArrayHasKey('measured_ngn', $tco);
        $this->assertSame(1000000.0, $tco['total_ngn']);
        $this->assertNull($tco['measured_basis'] ?? null);
        config(['ea.cost.finance_tco' => false]);
        $off = app(CostModelService::class)->tcoWithFinance($app, $this->fin);
        $this->assertNull($off['measured_ngn']);
        $this->assertSame($tco['total_ngn'], $off['total_ngn']);
    }

    public function test_import_completion_notification_goes_to_the_importer(): void
    {
        $b = ImportBatch::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'type' => 'cloud', 'file_name' => 'cur.csv', 'imported_by_id' => $this->fin->id,
            'file_sha256' => str_repeat('a', 64), 'status' => 'completed', 'accepted_count' => 10, 'rejected_count' => 1, 'unmatched_count' => 0]);
        Notification::fake();
        app(FinanceImportNotifier::class)->completed($b);
        Notification::assertSentTo($this->fin, ImportCompletedNotification::class);
    }

    public function test_tco_page_returns_every_subject_for_the_picker(): void
    {
        EaApplication::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Zero-cost app', 'code' => 'APP-Z', 'criticality' => 'low', 'lifecycle' => 'active']);
        $this->actingAs($this->fin)->get(route('finance.monitor.tco'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('subjects')->where('subjects', fn ($s) => collect($s)->contains(fn ($r) => str_contains($r['label'], 'Zero-cost app'))));
    }
}
