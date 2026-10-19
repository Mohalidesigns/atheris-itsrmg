<?php

namespace Tests\Feature\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Finance\Actual;
use App\Models\Finance\Allocation;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\Driver;
use App\Models\Finance\Setting;
use App\Services\Finance\AllocationService;
use App\Services\Finance\BudgetLineClassifier;
use App\Services\Finance\ChargebackService;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\ShowbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/**
 * FIN-02.12 (allocation execution) and FIN-03.12 (showback and chargeback), plan P6-5 to P6-10, on the P6 fixture bank: three lines of 1,000,000 a month
 * in CC-1A (one of them in the software pool), a 500,000 a month cloud line in CC-1 (its parent) and a 2,000,000 a month labour line in CC-2.
 */
class ShowbackAndAllocationTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    private AllocationService $alloc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
        $this->alloc = app(AllocationService::class);
    }

    /** Per rule name, the allocated total of an evaluation. @return array<string,string> */
    private function byRule(array $evaluation): array
    {
        $names = Allocation::query()->pluck('name', 'id');
        $out = [];
        foreach ($evaluation['rows'] as $r) {
            $out[$names[$r['allocation_id']]] = bcadd($out[$names[$r['allocation_id']]] ?? '0.00', $r['base_amount'], 2);
        }
        ksort($out);

        return $out;
    }

    public function test_percentage_driver_and_direct_shares_and_the_most_specific_source_wins(): void // FIN-02.12
    {
        $this->makeAllocation(['name' => 'ops-ret', 'target_ref' => 'RET', 'percentage' => '40']);
        $this->makeAllocation(['name' => 'ops-cib', 'target_ref' => 'CIB', 'percentage' => '25']);
        $driver = Driver::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'HEAD', 'name' => 'Headcount', 'unit' => 'people', 'is_active' => true]);
        foreach (['RET' => '300', 'CIB' => '100'] as $unit => $qty) {
            $this->makeAllocation(['name' => "lab-{$unit}", 'source_id' => $this->cc2->id, 'target_ref' => $unit, 'method' => 'driver', 'driver_id' => $driver->id, 'driver_quantity' => $qty, 'percentage' => null]);
        }
        $this->makeAllocation(['name' => 'cloud-trs', 'source_id' => $this->cc->id, 'target_ref' => 'TRS', 'method' => 'direct', 'percentage' => null]);

        $e = $this->alloc->evaluate($this->org->id, 'business_unit', $this->periodSeq(1), 'budget');
        $this->assertSame([
            'cloud-trs' => '500000.00', // the whole of CC-1's own line; CC-1A (its child) belongs to the more specific rules, so it is not allocated twice
            'lab-CIB' => '500000.00', 'lab-RET' => '1500000.00', // 2,000,000 by headcount 100 : 300
            'ops-cib' => '750000.00', 'ops-ret' => '1200000.00', // 25 % and 40 % of CC-1A's 3,000,000
        ], $this->byRule($e));
        $this->assertSame('5500000.00', $e['it_total']);
        $this->assertSame('4450000.00', $e['allocated_total']);

        // a percentage rule shares by its own percentage, a driver rule by its quantity share
        $shares = $this->alloc->shares($this->org->id, 'business_unit', $this->periodSeq(1));
        $this->assertEqualsCanonicalizing(['75.000000', '25.000000'], array_values($shares['cost_centre:'.$this->cc2->id]));
    }

    public function test_the_stored_run_is_split_by_cost_pool(): void // FIN-02.12, P6-6
    {
        $this->makeAllocation(['name' => 'ops-ret', 'source_id' => $this->cc->id, 'target_ref' => 'RET', 'percentage' => '40']); // CC-1 and its child CC-1A
        $this->alloc->run($this->org->id, $this->fy->id, [$this->periodSeq(1)->id], ['budget'], $this->fin);

        $run = AllocationRun::query()->where('period_id', $this->periodSeq(1)->id)->where('target_type', 'business_unit')->firstOrFail();
        $byPool = $run->results()->get()->mapWithKeys(fn ($r) => [(int) $r->cost_pool_id => (string) $r->base_amount])->all();
        $this->assertSame('1200000.00', $byPool[$this->cat('CP-SW')]); // 40 % of CC-1A's three software lines
        $this->assertSame('200000.00', $byPool[$this->cat('CP-CLD')]); // 40 % of CC-1's cloud line
        $this->assertCount(2, $byPool);
    }

    public function test_the_percentage_group_cannot_exceed_100_and_a_direct_rule_stands_alone(): void // FIN-02.12, P6-5
    {
        $this->makeAllocation(['name' => 'a', 'target_ref' => 'RET', 'percentage' => '60']);
        $this->makeAllocation(['name' => 'b', 'target_ref' => 'CIB', 'percentage' => '40']);
        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'c', 'target_ref' => 'TRS', 'percentage' => '0.0001']), 'percentage', '100');
        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'd', 'target_ref' => 'TRS', 'method' => 'direct']), 'method'); // mixed methods
        $this->makeAllocation(['name' => 'e', 'source_id' => $this->cc2->id, 'target_ref' => 'TRS', 'method' => 'direct', 'percentage' => null]);
        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'f', 'source_id' => $this->cc2->id, 'target_ref' => 'RET', 'method' => 'direct', 'percentage' => null]), 'method');
        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'g', 'source_id' => 999999, 'target_ref' => 'RET']), 'source_id'); // another bank's or missing source
    }

    public function test_direct_attribution_is_excluded_from_the_pool_a_rule_allocates(): void // FIN-05.10, P6-6
    {
        $svc = BusinessService::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'BS-001', 'name' => 'Mobile Banking']);
        $this->assertSame(1, app(BudgetLineClassifier::class)->classify([$this->monLines[1]->id], ['business_service_id' => $svc->id], $this->fin));
        $this->makeAllocation(['name' => 'ops-svc', 'target_type' => 'business_service', 'target_ref' => null, 'target_id' => $svc->id, 'percentage' => '50']);

        $pools = $this->alloc->pools($this->org->id, 'business_service', $this->periodSeq(1), 'budget');
        $this->assertSame('business_service:'.$svc->id, $pools['direct'][0]['target_key']);
        $this->assertSame(['1000000.00'], array_values($pools['direct'][0]['by_pool']));
        $e = $this->alloc->evaluate($this->org->id, 'business_service', $this->periodSeq(1), 'budget');
        $this->assertSame('2000000.00', $e['pool_total']); // CC-1A's other two lines; the tagged one is the service's own cost
        $this->assertSame('1000000.00', $e['allocated_total']);
        $this->assertSame('1000000.00', bcadd($e['direct_total'], '0', 2));
        $this->assertSame('5500000.00', $e['it_total']);
    }

    public function test_largest_remainder_rounding_sums_to_the_cent(): void // FIN-02.12
    {
        $third = ['1' => '33.333333', '2' => '33.333333', '3' => '33.333333'];
        $split = $this->alloc->largestRemainder('100.00', $third);
        $this->assertSame('100.00', array_reduce($split, fn ($c, $v) => bcadd($c, $v, 2), '0.00'));
        $this->assertEqualsCanonicalizing(['33.33', '33.33', '33.34'], array_values($split));

        $split = $this->alloc->largestRemainder('0.05', ['1' => '50.000000', '2' => '50.000000']);
        $this->assertSame('0.05', array_reduce($split, fn ($c, $v) => bcadd($c, $v, 2), '0.00'));
        $weights = $this->alloc->splitByWeights('1000.00', ['1' => '3', '2' => '3', '3' => '3']);
        $this->assertSame('1000.00', array_reduce($weights, fn ($c, $v) => bcadd($c, $v, 2), '0.00'));
    }

    public function test_the_statement_reconciles_to_the_it_total_and_the_ledger(): void // FIN-03.12
    {
        $p1 = $this->periodSeq(1);
        $this->makeAllocation(['name' => 'ops-ret', 'target_ref' => 'RET', 'percentage' => '40']);
        $this->makeAllocation(['name' => 'lab-cib', 'source_id' => $this->cc2->id, 'target_ref' => 'CIB', 'percentage' => '50']);
        $this->postActual($this->monLines[0], $p1, '800000.00');
        $this->postActual($this->poolLines['CP-LAB'], $p1, '200000.00');
        $this->postActual($this->monLines[1], $p1, '50000.00', ['budget_line_id' => null, 'match_status' => 'unbudgeted']); // spend the budget did not foresee is still IT spend
        $this->alloc->run($this->org->id, $this->fy->id, [$p1->id], ['budget', 'actual'], $this->fin);

        foreach (['budget', 'actual'] as $basis) {
            $s = app(ShowbackService::class)->statement($this->org->id, 'business_unit', $this->fy->id, $p1->id, $p1->id, $basis, null, $this->fin);
            $this->assertTrue($s['totals']['reconciles'], "{$basis} reconciles");
            $this->assertSame('0.00', bcadd($s['totals']['difference'], '0', 2));
            $this->assertSame(bcadd($s['totals']['total'], $s['totals']['retained'], 2), bcadd($s['totals']['it_total'], '0', 2), 'rows plus retained equal the IT total');
        }
        $actual = app(ShowbackService::class)->statement($this->org->id, 'business_unit', $this->fy->id, $p1->id, $p1->id, 'actual', null, $this->fin);
        $ledger = bcadd((string) Actual::query()->where('period_id', $p1->id)->sum('base_amount'), '0', 2);
        $this->assertSame('1050000.00', $ledger);
        $this->assertSame($ledger, bcadd($actual['totals']['it_total'], '0', 2), 'the statement ties to the BvA ledger total, unbudgeted spend included');
    }

    public function test_a_closed_period_is_computed_once_and_frozen_and_the_past_is_protected(): void // FIN-02.12, P6-8
    {
        $p1 = $this->periodSeq(1);
        $this->makeAllocation(['name' => 'ops-ret', 'target_ref' => 'RET', 'percentage' => '40']);
        $this->postActual($this->monLines[0], $p1, '800000.00');
        $this->closePeriodDirectly($p1);
        $first = $this->alloc->run($this->org->id, $this->fy->id, [$p1->id], ['actual'], $this->fin);
        $this->assertSame('computed', collect($first)->firstWhere('target_type', 'business_unit')['status']); // a closed period with no results is computed once
        $this->postActual($this->monLines[0], $p1, '100000.00', ['posting_key' => 'late']);
        $second = $this->alloc->run($this->org->id, $this->fy->id, [$p1->id], ['actual'], $this->fin);
        $this->assertSame('frozen', collect($second)->firstWhere('target_type', 'business_unit')['status']);
        $this->assertSame('320000.00', (string) AllocationRun::query()->where('period_id', $p1->id)->where('amount_type', 'actual')->value('allocated_total'));

        $this->alloc->freeze($p1, $this->fin); // the close path computes the final figure and flags the run
        $run = AllocationRun::query()->where('period_id', $p1->id)->where('amount_type', 'actual')->where('target_type', 'business_unit')->firstOrFail();
        $this->assertTrue((bool) $run->is_frozen);
        $this->assertSame('360000.00', (string) $run->allocated_total);

        $this->assertRefused(fn () => $this->makeAllocation(['name' => 'old', 'target_ref' => 'CIB', 'percentage' => '10', 'effective_from' => '2027-01-01']), 'effective_from', '2027-02-01');
        $rule = $this->makeAllocation(['name' => 'later', 'target_ref' => 'CIB', 'percentage' => '10', 'effective_from' => '2027-02-01']);
        $this->assertRefused(fn () => $this->alloc->end($rule, '2027-01-31', $this->fin), 'effective_to');
    }

    public function test_chargeback_is_balanced_once_per_closed_period_and_voided_only_by_the_period_closer(): void // FIN-03.12, P6-10
    {
        $p1 = $this->periodSeq(1);
        $this->makeAllocation(['name' => 'ops-ret', 'target_ref' => 'RET', 'percentage' => '40']);
        $this->makeAllocation(['name' => 'ops-cib', 'target_ref' => 'CIB', 'percentage' => '25']); // two rules: the journal must resolve both
        $this->postActual($this->monLines[0], $p1, '800000.00');
        $this->alloc->run($this->org->id, $this->fy->id, [$p1->id], ['actual'], $this->fin);
        $cb = app(ChargebackService::class);

        $this->assertNotNull($cb->preview($this->org->id, $p1->id, 'business_unit')['blocked_reason']); // open period
        $this->closePeriodDirectly($p1);
        $run = $cb->export($this->org->id, $p1->id, 'business_unit', $this->fin);
        $this->assertSame('exported', $run->status);
        $this->assertSame('520000.00', (string) $run->total_debit); // 65 % of 800,000
        $this->assertSame((string) $run->total_debit, (string) $run->total_credit);
        $this->assertNotNull($cb->preview($this->org->id, $p1->id, 'business_unit')['blocked_reason']); // exported once
        $this->assertRefused(fn () => $cb->export($this->org->id, $p1->id, 'business_unit', $this->fin), 'period_id');

        $this->actingAs($this->cfo)->post(route('finance.chargeback.void', $run), ['reason' => 'Wrong ERP cost centre loaded for RET.'])->assertForbidden(); // approving is not closing
        $this->actingAs($this->fin)->post(route('finance.chargeback.void', $run), ['reason' => 'Wrong ERP cost centre loaded for RET.'])->assertForbidden(); // the exporter cannot void their own run (SoD)
        $this->actingAs($this->userWith('IT Finance Controller'))->post(route('finance.chargeback.void', $run), ['reason' => 'Wrong ERP cost centre loaded for RET.'])->assertRedirect();
        $this->assertSame('voided', $run->refresh()->status);
        $again = $cb->export($this->org->id, $p1->id, 'business_unit', $this->fin);
        $this->assertNotSame($run->run_code, $again->run_code);
        $this->assertSame(2, ChargebackRun::query()->count());
    }

    public function test_the_journal_neutralises_formulas_and_showback_can_be_switched_off(): void // FIN-03.12, FIN-07.10
    {
        $csv = ChargebackService::csv([
            ['line_no' => 1, 'gl_code' => '6950', 'cost_centre_code' => '=HYPERLINK("http://x")', 'debit' => '10.00', 'credit' => '0.00', 'description' => '+cmd', 'target_ref' => 'business_unit:@RET', 'rule_code' => '-ALC'],
        ], 'CBK-0001', '2027-01-31', 'NGN');
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv);
        $this->assertStringContainsString("'+cmd", $csv);
        $this->assertStringContainsString("'@RET", $csv);
        $this->assertStringContainsString("'-ALC", $csv);

        $p1 = $this->periodSeq(1);
        $this->makeAllocation(['name' => 'ops-ret', 'target_ref' => 'RET', 'percentage' => '40']);
        $this->alloc->run($this->org->id, $this->fy->id, [$p1->id], ['actual', 'budget'], $this->fin);
        $this->closePeriodDirectly($p1);
        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['showback_enabled' => false]);
        app(FinanceSettings::class)->flush();
        $this->assertStringContainsString('switched off', (string) app(ChargebackService::class)->preview($this->org->id, $p1->id, 'business_unit')['blocked_reason']);
        $this->actingAs($this->fin)->postJson(route('finance.chargeback.store'), ['period_id' => $p1->id, 'target_type' => 'business_unit'])->assertStatus(422);
    }

    public function test_a_rule_is_edited_ended_and_deleted_until_it_has_results(): void // FIN-02.12 (regression: the edit paths called a missing same-bank guard)
    {
        $rule = $this->makeAllocation(['name' => 'ops-ret', 'target_ref' => 'RET', 'percentage' => '40']);
        $edited = $this->alloc->update($rule, ['percentage' => '45', 'name' => 'ops-ret v2'], $this->fin);
        $this->assertSame('45.0000', (string) $edited->percentage);
        $this->alloc->run($this->org->id, $this->fy->id, [$this->periodSeq(1)->id], ['budget'], $this->fin);
        $this->assertRefused(fn () => $this->alloc->update($edited, ['target_ref' => 'CIB'], $this->fin), 'target_ref'); // results exist: end it and make a new rule
        $this->assertRefused(fn () => $this->alloc->destroy($edited, $this->fin));
        $ended = $this->alloc->end($edited, '2027-06-30', $this->fin);
        $this->assertSame('2027-06-30', $ended->effective_to->toDateString());

        $other = $this->userWith('IT Finance Controller', [], $this->otherBank()->id);
        $this->assertRefused(fn () => $this->alloc->update($ended, ['name' => 'x'], $other));
        $fresh = $this->makeAllocation(['name' => 'unused', 'target_ref' => 'CIB', 'percentage' => '10']);
        $this->alloc->destroy($fresh, $this->fin);
        $this->assertSoftDeleted('fin_allocations', ['id' => $fresh->id]);
    }
}
