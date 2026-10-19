<?php

namespace Tests\Feature\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Finance\CloudAnomaly;
use App\Models\Finance\CloudCostLine;
use App\Models\Finance\CloudTagRule;
use App\Models\Finance\FxRate;
use App\Models\Finance\ImportBatch;
use App\Services\Finance\CloudAnomalyService;
use App\Services\Finance\CloudCostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/**
 * FIN-03.09 (cloud cost import, tag allocation, untagged queue) and FIN-03.10 (anomaly detection and lifecycle), plan P6-1 to P6-4, on the P6 fixture bank
 * (NGN, FY2027, today 2027-04-05, USD at 1,500, anomaly policy 30 % and 250,000 NGN).
 */
class CloudCostTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    private CloudCostService $cloud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
        $this->cloud = app(CloudCostService::class);
        $this->makeCloudAccount();
    }

    private function fixture(string $name): UploadedFile
    {
        return new UploadedFile(base_path("tests/Fixtures/finance/cloud/{$name}.csv"), "{$name}.csv", 'text/csv', null, true);
    }

    private function service(): BusinessService
    {
        return BusinessService::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'BS-001', 'name' => 'Mobile Banking']);
    }

    public function test_every_export_format_imports(): void // FIN-03.09
    {
        $cases = ['aws_cur' => ['aws', '402112345688', 8], 'azure' => ['azure', '11111111-2222-3333-4444-555555555555', 5], 'gcp' => ['gcp', 'khb-analytics-1', 4], 'generic' => ['other', 'ACC-001', 3]];
        foreach ($cases as $format => [$provider, $identifier, $minLines]) {
            $account = $format === 'aws_cur' ? $this->cloudAccount : $this->makeCloudAccount(['provider' => $provider, 'account_identifier' => $identifier, 'name' => "{$format} account", 'default_format' => $format]);
            $batch = $this->cloud->import($account, $this->fixture($format), null, $this->fin);
            $this->assertContains($batch->status, ['completed', 'completed_with_errors'], $format);
            $this->assertGreaterThanOrEqual($minLines, $batch->accepted_count, "{$format} accepted rows");
            $this->assertSame($batch->accepted_count, CloudCostLine::query()->where('import_batch_id', $batch->id)->count(), "{$format} lines");
            $this->assertSame('cloud', $batch->type);
        }
        $this->assertSame(4, ImportBatch::query()->where('type', 'cloud')->count());
    }

    public function test_attribution_precedence_rule_then_builtin_then_default_and_the_untagged_flag(): void // FIN-03.09, P6-3
    {
        $svc = $this->service();
        $account = $this->cloud->updateAccount($this->cloudAccount, ['tag_mapping' => ['CostCentre' => 'cost_centre', 'Service' => 'business_service']], $this->fin);
        $this->cloud->storeRule(['tag_key' => 'Service', 'tag_value' => 'mobile', 'dimension' => 'business_service', 'target_id' => $svc->id], $this->fin);

        $builtin = $this->cloud->attribute($account, ['CostCentre' => $this->cc2->code]);
        $this->assertSame($this->cc2->id, $builtin['cost_centre_id']);
        $this->assertFalse($builtin['is_untagged']);
        $this->assertSame('builtin', $builtin['attributed_by']);

        $rule = $this->cloud->attribute($account, ['Service' => 'MOBILE', 'CostCentre' => $this->cc2->code]); // a rule wins and matches case-insensitively
        $this->assertSame($svc->id, $rule['business_service_id']);
        $this->assertSame($this->cc2->id, $rule['cost_centre_id']);
        $this->assertSame('rule', $rule['attributed_by']);
        $this->assertCount(1, $rule['rule_ids']);

        $none = $this->cloud->attribute($account, ['Owner' => 'someone']); // an unmapped tag resolves nothing
        $this->assertTrue($none['is_untagged']);
        $this->assertSame('default', $none['attributed_by']);
        $this->assertSame($account->default_cost_centre_id, $none['cost_centre_id']);
        $this->assertNull($none['business_service_id']);
    }

    public function test_a_file_cannot_be_imported_twice_and_a_restatement_replaces_open_dates_but_never_closed_ones(): void // FIN-03.09, P6-2
    {
        $this->cloud->import($this->cloudAccount, $this->fixture('aws_cur'), 'aws_cur', $this->fin);
        $this->assertRefused(fn () => $this->cloud->import($this->cloudAccount, $this->fixture('aws_cur'), 'aws_cur', $this->fin), 'file', 'already imported');

        $rows = $this->cloudRows('2027-03-10', 5, ['Amazon EC2'], ['Service' => 'mobile'], 100);
        $first = $this->ingest($rows);
        $this->assertSame(5, $first['inserted']);
        $this->assertSame([], $first['replaced_dates']);
        $restated = $this->ingest($this->cloudRows('2027-03-10', 5, ['Amazon EC2'], ['Service' => 'mobile'], 120));
        $this->assertCount(5, $restated['replaced_dates']); // open dates are replaced, never duplicated
        $this->assertSame(5, CloudCostLine::query()->where('service', 'Amazon EC2')->whereBetween('usage_date', ['2027-03-10', '2027-03-14'])->count());
        $this->assertSame('120.000000', (string) CloudCostLine::query()->where('service', 'Amazon EC2')->whereDate('usage_date', '2027-03-10')->value('cost'));

        $this->closePeriodDirectly($this->periodSeq(3)); // March is closed
        $refused = $this->ingest($this->cloudRows('2027-03-10', 5, ['Amazon EC2'], ['Service' => 'mobile'], 999));
        $this->assertCount(5, $refused['refused_dates']);
        $this->assertSame(0, $refused['inserted']);
        $this->assertSame('120.000000', (string) CloudCostLine::query()->where('service', 'Amazon EC2')->whereDate('usage_date', '2027-03-10')->value('cost'));
        $late = $this->ingest($this->cloudRows('2027-03-20', 2, ['Amazon EC2'], ['Service' => 'mobile'], 50)); // a closed date with no lines yet is appended
        $this->assertCount(2, $late['appended_dates']);
        $this->assertSame(2, $late['inserted']);
    }

    public function test_fx_uses_the_usage_date_rate_and_a_missing_rate_rejects_the_row(): void // FIN-03.09, P6-2
    {
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1600, 'rate_date' => '2027-03-20', 'rate_type' => 'spot']);
        $this->ingest($this->cloudRows('2027-03-18', 4, ['Amazon EC2'], [], 10)); // 18, 19 at the old rate, 20, 21 at the new one
        $rates = CloudCostLine::query()->orderBy('usage_date')->get()->mapWithKeys(fn ($l) => [$l->usage_date->toDateString() => (float) $l->fx_rate]);
        $this->assertEquals(1500.0, $rates['2027-03-19']);
        $this->assertEquals(1600.0, $rates['2027-03-20']);
        $this->assertEquals(16000.0, (float) CloudCostLine::query()->whereDate('usage_date', '2027-03-21')->value('base_cost'));

        $eur = $this->makeCloudAccount(['account_identifier' => '777', 'name' => 'Euro account', 'currency' => 'EUR']);
        $rows = array_map(fn ($r) => ['currency' => 'EUR'] + $r, $this->cloudRows('2027-03-18', 2, ['Compute'], [], 10));
        $r = $this->ingest($rows, $eur);
        $this->assertSame(0, $r['inserted']);
        $this->assertCount(2, $r['rejected']);
        $this->assertStringContainsString('no EUR', $r['rejected'][0]['reason']);
    }

    public function test_assigning_untagged_cost_and_remembering_a_rule_touches_open_periods_only(): void // FIN-03.09, P6-3
    {
        $tags = ['Owner' => 'platform-team'];
        $this->ingest($this->cloudRows('2027-02-20', 3, ['Amazon EC2'], $tags, 10));
        $this->ingest($this->cloudRows('2027-03-20', 3, ['Amazon EC2'], $tags, 10));
        $this->closePeriodDirectly($this->periodSeq(2)); // February is closed
        $this->assertSame(6, CloudCostLine::query()->where('is_untagged', true)->count());

        $n = $this->cloud->assignUntagged($this->cloudAccount, 'res-amazon-ec2', 'Amazon EC2', 'cost_centre', $this->cc2->id, true, 'Owner', $this->fin);
        $this->assertSame(3, $n); // March only
        $this->assertSame(3, CloudCostLine::query()->where('is_untagged', true)->count()); // February keeps its flag
        $this->assertSame(3, CloudCostLine::query()->where('cost_centre_id', $this->cc2->id)->where('attributed_by', 'manual')->count());
        $rule = CloudTagRule::query()->where('tag_key', 'Owner')->firstOrFail();
        $this->assertSame('platform-team', $rule->tag_value);

        // later cost carrying the tag follows the rule, and a rule created afterwards re-attributes open lines on demand
        $this->ingest($this->cloudRows('2027-04-01', 2, ['Amazon S3'], $tags, 10));
        $this->assertSame(2, CloudCostLine::query()->where('service', 'Amazon S3')->where('cost_centre_id', $this->cc2->id)->where('attributed_by', 'rule')->count());
        $this->cloud->storeRule(['cloud_account_id' => $this->cloudAccount->id, 'tag_key' => 'Owner', 'tag_value' => 'platform-team', 'dimension' => 'business_service', 'target_id' => $this->service()->id], $this->fin);
        $changed = $this->cloud->allocateByTags($this->cloudAccount, '2027-03-01', null, $this->fin);
        $this->assertSame(2, $changed); // the two April lines; March was assigned by hand and February is closed
        $this->assertSame(2, CloudCostLine::query()->whereNotNull('business_service_id')->count());
    }

    public function test_the_seeded_style_spike_is_detected_by_rolling_average_and_week_over_week_and_detection_is_idempotent(): void // FIN-03.10, P6-4
    {
        $this->ingest($this->cloudRows('2027-03-10', 25, ['Amazon EC2', 'Amazon S3'], ['Service' => 'mobile'], 100));
        $this->ingest($this->spike('2027-04-03', 'Amazon EC2', 10));
        $anomalies = app(CloudAnomalyService::class);

        $found = $anomalies->detect($this->cloudAccount, CarbonImmutable::parse('2027-04-05'));
        $this->assertSame(['account', 'service'], $found->pluck('scope')->sort()->values()->all());
        $service = $found->firstWhere('scope', 'service');
        $this->assertSame('Amazon EC2', $service->scope_ref);
        $this->assertSame('2027-04-03', $service->detected_on->toDateString());
        $this->assertSame('900.00', (string) $service->deviation_pct);
        $this->assertSame('100.00', (string) $service->baseline_cost);
        $this->assertSame('1000.00', (string) $service->observed_cost);
        $this->assertSame('open', $service->status);
        $this->assertSame($this->cloudAccount->owner_id, $service->owner_id);
        $this->assertCount(0, $anomalies->detect($this->cloudAccount, CarbonImmutable::parse('2027-04-05'))); // idempotent
        $this->assertSame(2, CloudAnomaly::query()->count());

        // the same day, tested a week-over-week way when the rolling window is short
        $methods = $anomalies::evaluate(['2027-03-27' => '100', '2027-04-03' => '1000'], '2027-04-03', ['pct' => 30, 'abs_floor' => 100]);
        $this->assertSame('week_over_week', $methods['method']);
        $rolling = $anomalies::evaluate(array_fill_keys(['2027-03-27', '2027-03-28', '2027-03-29', '2027-03-30', '2027-03-31', '2027-04-01', '2027-04-02'], '100') + ['2027-04-03' => '400'], '2027-04-03', ['pct' => 30, 'abs_floor' => 100]);
        $this->assertSame('300.00', $rolling['deviation_pct']);
    }

    public function test_the_floor_is_respected_and_a_rate_move_alone_is_not_an_anomaly(): void // FIN-03.10, P6-4
    {
        $this->ingest($this->cloudRows('2027-03-10', 25, ['Amazon EC2'], [], 100));
        $this->ingest($this->spike('2027-04-03', 'Amazon EC2', 1.6)); // +60 USD x 1,500 = 90,000 NGN: over 30 % but under the 250,000 floor
        $this->assertCount(0, app(CloudAnomalyService::class)->detect($this->cloudAccount, CarbonImmutable::parse('2027-04-05')));

        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 3000, 'rate_date' => '2027-04-01', 'rate_type' => 'spot']); // the naira halves; billing-currency cost is unchanged
        $this->ingest($this->cloudRows('2027-04-01', 3, ['Amazon EC2'], [], 100));
        $this->assertCount(0, app(CloudAnomalyService::class)->detect($this->cloudAccount, CarbonImmutable::parse('2027-04-05')));
        $this->assertSame(0, CloudAnomaly::query()->count());
    }

    public function test_anomalies_are_acknowledged_resolved_or_dismissed_with_a_reason(): void // FIN-03.10
    {
        $this->ingest($this->cloudRows('2027-03-10', 25, ['Amazon EC2'], [], 100));
        $this->ingest($this->spike('2027-04-03', 'Amazon EC2', 10));
        $svc = app(CloudAnomalyService::class);
        [$a, $b] = $svc->detect($this->cloudAccount, CarbonImmutable::parse('2027-04-05'))->all();

        $a = $svc->acknowledge($a, null, $this->requester);
        $this->assertSame('acknowledged', $a->status);
        $this->assertSame($this->requester->id, $a->acknowledged_by_id);
        $this->assertRefused(fn () => $svc->resolve($a, 'short', $this->requester), 'note');
        $a = $svc->resolve($a, 'A runaway test cluster was shut down and credited.', $this->requester);
        $this->assertSame('resolved', $a->status);
        $this->assertRefused(fn () => $svc->acknowledge($a, null, $this->requester)); // a resolved anomaly stays resolved
        $this->assertRefused(fn () => $svc->dismiss($a, 'Not a real anomaly at all.', $this->requester));

        $b = $svc->dismiss($b, 'Planned load test approved by the change board.', $this->fin);
        $this->assertSame('false_positive', $b->status);
        $other = $this->userWith('IT Finance Controller', [], $this->otherBank()->id);
        $this->assertRefused(fn () => $svc->acknowledge($b->refresh(), null, $other));
    }

    public function test_the_ledger_reconciliation_shows_the_gap_between_finops_and_the_invoiced_cloud_line(): void // FIN-03.10, P6-1
    {
        $this->ingest($this->cloudRows('2027-03-01', 10, ['Amazon EC2'], [], 100)); // 10 x 100 USD x 1,500 = 1,500,000
        $this->postActual($this->poolLines['CP-CLD'], $this->periodSeq(3), '1200000.00', ['category_id' => $this->cat('CP-CLD')]);

        $r = $this->cloud->ledgerReconciliation($this->org->id, $this->periodSeq(3)->id, null);
        $this->assertSame('1500000.00', $r['finops_total']);
        $this->assertSame('1200000.00', $r['cloud_ledger_actual']);
        $this->assertSame('-300000.00', $r['gap']); // the ledger is 300,000 behind what FinOps sees
        $this->assertSame('-25.00', $r['gap_pct']);
        $this->assertNull($this->cloud->ledgerReconciliation($this->org->id, 999999, null)['period']);
    }

    public function test_a_scoped_viewer_sees_only_cost_centres_and_accounts_he_may_see(): void // FIN-03.09, FIN-07.10
    {
        $first = $this->cloudAccount;
        $this->ingest($this->cloudRows('2027-03-10', 5, ['Amazon EC2'], [], 100));
        $other = $this->makeCloudAccount(['account_identifier' => '888', 'name' => 'Security tooling', 'default_cost_centre_id' => $this->cc2->id, 'owner_id' => $this->owner2->id]);
        $this->ingest($this->cloudRows('2027-03-10', 5, ['Amazon S3'], [], 10), $other);

        $costs = fn ($user) => $this->actingAs($user)->get(route('finance.cloud.costs', ['from' => '2027-03-10', 'to' => '2027-03-14']))->assertOk()->original->getData()['page']['props'];
        $this->assertSame('825000.00', bcadd($costs($this->fin)['summary']['total'], '0', 2));
        $mine = $costs($this->requester); // owns CC-1A only
        $this->assertSame('750000.00', bcadd($mine['summary']['total'], '0', 2));
        $theirs = $costs($this->owner2); // owns CC-2 only
        $this->assertSame('75000.00', bcadd($theirs['summary']['total'], '0', 2));
        $this->assertCount(1, $mine['accounts']);
        $this->assertSame($first->id, $mine['accounts'][0]['id']);

        $nobody = $this->userWith('IT Budget Owner');
        $this->assertSame('0.00', bcadd($costs($nobody)['summary']['total'], '0', 2));
    }
}
