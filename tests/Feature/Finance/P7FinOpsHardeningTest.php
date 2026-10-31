<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Allocation;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\CloudAnomaly;
use App\Models\Finance\CloudCostLine;
use App\Models\Finance\Investment;
use App\Models\Kri;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\AllocationService;
use App\Services\Finance\AmortisationSchedule;
use App\Services\Finance\ApplicationTcoService;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\ChargebackService;
use App\Services\Finance\CloudAnomalySweeper;
use App\Services\Finance\CloudCostService;
use App\Services\Finance\FinanceKriSource;
use App\Services\Finance\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/**
 * P7 WP-D hardening of FinOps, allocation, capitalisation and the finance KRIs (ATH-FIN-PLAN-p7 §3 WP-D): FIN-02.12, FIN-03.09, FIN-03.10, FIN-03.12,
 * FIN-03.13, FIN-04.08, FIN-05.10.
 */
class P7FinOpsHardeningTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
    }

    private function anomaly(string $date, string $scope = 'service', string $ref = 'Amazon EC2'): CloudAnomaly
    {
        return CloudAnomaly::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'cloud_account_id' => $this->cloudAccount->id, 'detected_on' => $date, 'scope' => $scope, 'scope_ref' => $ref,
            'method' => 'rolling_average', 'baseline_cost' => '100.00', 'observed_cost' => '1000.00', 'baseline_base_cost' => '150000.00', 'observed_base_cost' => '1500000.00', 'deviation_pct' => '900.00',
            'status' => 'open', 'owner_id' => $this->cloudAccount->owner_id,
        ]);
    }

    public function test_a_rule_that_started_before_the_open_floor_can_still_be_edited_and_ended(): void // FIN-02.12, P6-M6
    {
        $alloc = app(AllocationService::class);
        $rule = $this->makeAllocation(['name' => 'ops-ret', 'target_ref' => 'RET', 'percentage' => '40', 'effective_from' => '2027-01-01']);
        $this->closePeriodDirectly($this->periodSeq(1));
        $this->closePeriodDirectly($this->periodSeq(2)); // the floor is now 2027-03-01

        $edited = $alloc->update($rule, ['name' => 'ops-ret renamed', 'percentage' => '45'], $this->fin); // unchanged start date is not re-validated
        $this->assertSame('ops-ret renamed', $edited->name);
        $this->assertRefused(fn () => $alloc->update($rule, ['effective_from' => '2027-01-15'], $this->fin), 'effective_from'); // a changed date still is
        $this->assertRefused(fn () => $alloc->end($rule, '2027-02-28', $this->fin), 'effective_to');
        $this->assertSame('2027-03-31', $alloc->end($rule, '2027-03-31', $this->fin)->effective_to->toDateString());
    }

    public function test_a_restatement_keeps_manual_attributions_and_is_audited_with_before_and_after(): void // FIN-03.09, P6-M8
    {
        $this->makeCloudAccount();
        $this->ingest($this->cloudRows('2027-03-20', 2, ['Amazon EC2'], ['Owner' => 'x'], 100));
        app(CloudCostService::class)->assignUntagged($this->cloudAccount, 'res-amazon-ec2', 'Amazon EC2', 'cost_centre', $this->cc2->id, false, null, $this->fin);
        $this->assertSame(2, CloudCostLine::query()->where('attributed_by', 'manual')->count());

        $r = $this->ingest($this->cloudRows('2027-03-20', 2, ['Amazon EC2'], ['Owner' => 'x'], 120));
        $this->assertCount(2, $r['replaced_dates']);
        $this->assertSame(2, CloudCostLine::query()->where('cost_centre_id', $this->cc2->id)->where('attributed_by', 'manual')->count(), 'the manual attribution survives');
        $log = Activity::query()->where('event', 'restated')->latest('id')->firstOrFail();
        $this->assertSame(2, $log->properties['before_lines']);
        $this->assertSame(2, $log->properties['after_lines']);
        $this->assertSame('300000.00', $log->properties['before_base_total']);
        $this->assertSame('360000.00', $log->properties['after_base_total']);
        $this->assertSame(2, $log->properties['manual_kept']);
    }

    public function test_the_cloud_kri_counts_distinct_account_and_day_events(): void // FIN-04.08, P6-M9
    {
        $this->makeCloudAccount();
        $this->anomaly('2027-04-03', 'account', 'Total');
        $this->anomaly('2027-04-03', 'service', 'Amazon EC2');
        $this->anomaly('2027-04-03', 'service', 'Amazon S3'); // one spike, three anomalies
        $this->anomaly('2027-04-02', 'account', 'Total');

        [$n] = app(FinanceKriSource::class)->cloudAnomalies($this->org->id);
        $this->assertSame(2.0, $n);
    }

    public function test_a_cost_centre_scoped_viewer_cannot_manage_tag_rules_accounts_or_foreign_anomalies(): void // FIN-03.09, FIN-07.10, P6-M11
    {
        $this->makeCloudAccount();
        $cloud = app(CloudCostService::class);
        $payload = ['cloud_account_id' => $this->cloudAccount->id, 'tag_key' => 'Team', 'tag_value' => 'net', 'dimension' => 'cost_centre', 'target_id' => $this->cc2->id];
        $scoped = $this->requester;
        $this->assertNotNull(FinancePolicy::visibleCostCentreIds($scoped));
        foreach ([
            fn () => $cloud->storeRule($payload, $scoped),
            fn () => $cloud->storeAccount(['provider' => 'aws', 'account_identifier' => '9', 'name' => 'x', 'default_format' => 'aws_cur', 'currency' => 'USD', 'default_cost_centre_id' => $this->cc2->id], $scoped),
        ] as $call) {
            try {
                $call();
                $this->fail('a scoped viewer must not manage bank-wide attribution');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $rule = $cloud->storeRule($payload, $this->fin);
        $this->assertNotNull($rule->id);

        // an anomaly of an account the viewer neither owns nor has a cost centre for
        $other = $this->makeCloudAccount(['account_identifier' => '888', 'name' => 'Security tooling', 'default_cost_centre_id' => $this->cc2->id, 'owner_id' => $this->owner2->id]);
        $x = $this->anomaly('2027-04-03');
        CloudAnomaly::withoutGlobalScopes()->whereKey($x->id)->update(['cloud_account_id' => $other->id]);
        $this->actingAs($scoped)->post(route('finance.cloud.anomalies.acknowledge', $x->id), [])->assertForbidden();
        $this->assertSame('open', $x->refresh()->status);
        $this->actingAs($this->fin)->post(route('finance.cloud.anomalies.acknowledge', $x->id), [])->assertRedirect();
    }

    public function test_the_sweeper_notifies_every_open_unclaimed_anomaly_once(): void // FIN-03.10, P6-follow-up
    {
        Notification::fake();
        $this->makeCloudAccount();
        $x = $this->anomaly('2027-04-03'); // created outside the sweep (an earlier run that failed to send)
        $sweeper = app(CloudAnomalySweeper::class);
        $first = $sweeper->sweep(CarbonImmutable::parse('2027-04-05'));
        $this->assertGreaterThanOrEqual(1, $first);
        $this->assertGreaterThan(0, DB::table('fin_notification_logs')->where('notifiable_id', $x->id)->where('notification_key', 'cloud_anomaly')->count());
        $this->assertSame(0, $sweeper->sweep(CarbonImmutable::parse('2027-04-05')), 'idempotent');
    }

    public function test_straight_line_never_charges_more_than_is_left(): void // FIN-03.13, P6-L2
    {
        $lines = AmortisationSchedule::build('0.01', '0.00', 3, 'straight_line', null, '2027-01-01');
        $this->assertSame('0.01', array_reduce($lines, fn ($c, $l) => bcadd($c, $l['amount'], 2), '0.00'));
        foreach ($lines as $l) {
            $this->assertTrue(bccomp($l['closing_nbv'], '0', 2) >= 0, 'NBV never goes negative');
            $this->assertTrue(bccomp($l['amount'], '0', 2) >= 0);
        }
        $this->assertSame('0.00', end($lines)['closing_nbv']);
    }

    public function test_capitalisation_text_carries_no_amounts_and_deciding_needs_amount_visibility(): void // FIN-03.13, P6-M15, M16
    {
        $inv = $this->inDelivery($this->approvedInvestment());
        $this->flagCapitalisable($inv);
        $this->capexActual($inv, '777777.00', '2027-03-20');
        $svc = app(CapitalisationService::class);
        $c = $this->makeCapitalisation($inv, ['amount' => '777777.00'], true);
        $this->manager->givePermissionTo('view it-finance-amounts');
        $svc->submit($c, $this->manager);

        $blind = User::factory()->create(['organization_id' => $this->org->id]);
        $blind->givePermissionTo(['view it-finance', 'approve it-finance']); // no amounts permission
        $this->assertFalse($blind->fresh()->can('view it-finance-amounts'));
        $this->assertRefused(fn () => $svc->approve($c->refresh(), $blind->fresh()), 'approval');

        $svc->approve($c->refresh(), $this->userWith('Finance Approver'));
        foreach (Activity::query()->where('subject_type', Capitalisation::class)->where('subject_id', $c->id)->get() as $a) {
            $this->assertStringNotContainsString('777777', (string) $a->description);
            $this->assertStringNotContainsString('777,777', (string) $a->description);
        }
        $approved = Activity::query()->where('subject_type', Capitalisation::class)->where('subject_id', $c->id)->where('event', 'approved')->firstOrFail();
        $this->assertSame('777777.00', $approved->properties['amount']);

        // the error text of an over-claim names no figure
        $e = $this->assertRefused(fn () => $svc->store([
            'source_type' => 'investment', 'source_id' => $inv->id, 'title' => 'Too much', 'standard' => 'ias_38', 'available_for_use_on' => '2027-03-31', 'amount' => '999999999.00', 'useful_life_months' => 60,
        ], $this->manager), 'amount');
        $this->assertStringNotContainsString('999999999', implode(' ', $e->errors()['amount']));
    }

    public function test_the_capitalisation_sod_set_includes_the_sponsor_and_follows_a_reassignment(): void // FIN-03.13, P6-sec-L4
    {
        $inv = $this->inDelivery($this->approvedInvestment());
        $this->flagCapitalisable($inv);
        $this->capexActual($inv, '500000.00', '2027-03-20');
        $c = $this->makeCapitalisation($inv, ['amount' => '500000.00'], true);
        $this->manager->givePermissionTo('view it-finance-amounts');
        $c = app(CapitalisationService::class)->submit($c, $this->manager);
        $this->assertContains((int) $inv->sponsor_id, $c->sodExcludedIds());
        $newManager = $this->userWith('IT Budget Owner');
        Investment::withoutGlobalScopes()->whereKey($inv->id)->update(['manager_id' => $newManager->id]);
        $this->assertContains($newManager->id, $c->refresh()->sodExcludedIds(), 'a reassigned manager cannot approve');
    }

    public function test_the_capitalisation_index_is_paginated_with_sql_status_counts_and_masks_activity_changes(): void // FIN-03.13, P6-sec-M10
    {
        $inv = $this->inDelivery($this->approvedInvestment());
        $this->flagCapitalisable($inv);
        $this->capexActual($inv, '900000.00', '2027-03-20');
        for ($i = 1; $i <= 27; $i++) {
            $this->makeCapitalisation($inv, ['amount' => '10000.00', 'title' => "Release {$i}"]);
        }
        $props = $this->actingAs($this->fin)->get(route('finance.capitalisations.index'))->assertOk()->original->getData()['page']['props'];
        $this->assertCount(25, $props['capitalisations']['data']);
        $this->assertSame(27, $props['capitalisations']['meta']['total']);
        $this->assertSame(27, $props['summary']['by_status']['draft']);
        $page2 = $this->actingAs($this->fin)->get(route('finance.capitalisations.index', ['page' => 2, 'search' => 'release']))->assertOk()->original->getData()['page']['props'];
        $this->assertCount(2, $page2['capitalisations']['data']);
    }

    public function test_tco_never_shows_a_negative_unattributed_bucket(): void // FIN-05.10, P7-17
    {
        $this->makeCloudAccount();
        $tco = app(ApplicationTcoService::class);
        $t = $tco->portfolio($this->org->id, $this->fy->id, 'business_service', 'actual', null, $this->fin)['totals'];
        $this->assertTrue(bccomp($t['unattributed'], '0', 2) >= 0);
        $this->assertTrue(bccomp($t['finops_excess'], '0', 2) >= 0);
        $this->assertSame($t['it_total'], bcsub(bcadd($t['tco_total'], $t['unattributed'], 2), $t['finops_excess'], 2));
    }

    public function test_finance_kri_definitions_are_provisioned_for_every_bank(): void // FIN-04.08, P6-L8, P7-18
    {
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $codes = Kri::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('category', 'finance')->pluck('code')->sort()->values()->all();
        $this->assertSame(['KRI-FIN-CLD', 'KRI-FIN-INV', 'KRI-FIN-NOT', 'KRI-FIN-UNB', 'KRI-FIN-VAR'], $codes);
        Kri::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', 'KRI-FIN-VAR')->update(['threshold_red' => 99]);
        FinanceKriSource::ensureDefinitions($this->org->id);
        $this->assertSame(5, Kri::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('category', 'finance')->count());
        $this->assertEquals(99, Kri::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', 'KRI-FIN-VAR')->value('threshold_red'), 'a tuned threshold is not overwritten');
    }

    public function test_a_chargeback_download_is_audited(): void // FIN-03.12, P6-L10
    {
        $p1 = $this->periodSeq(1);
        $this->makeAllocation(['name' => 'ops-ret', 'target_ref' => 'RET', 'percentage' => '40']);
        $this->postActual($this->monLines[0], $p1, '800000.00');
        $this->closePeriodDirectly($p1);
        app(AllocationService::class)->run($this->org->id, $this->fy->id, [$p1->id], ['actual'], $this->fin);
        $run = app(ChargebackService::class)->export($this->org->id, $p1->id, 'business_unit', $this->fin);
        $this->assertInstanceOf(ChargebackRun::class, $run);
        $this->actingAs($this->fin)->get(route('finance.chargeback.download', $run))->assertOk();
        $log = Activity::query()->where('event', 'downloaded')->latest('id')->firstOrFail();
        $this->assertSame($this->fin->id, $log->causer_id);
        $this->assertSame($run->run_code, $log->properties['run_code']);
        $this->assertSame($run->file_sha256, $log->properties['file_sha256']);
    }
}
