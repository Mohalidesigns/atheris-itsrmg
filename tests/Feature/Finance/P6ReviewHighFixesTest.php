<?php

namespace Tests\Feature\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Finance\Actual;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\Setting;
use App\Services\Finance\AllocationService;
use App\Services\Finance\AmortisationSchedule;
use App\Services\Finance\BudgetLineClassifier;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\ChargebackService;
use App\Services\Finance\FinanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/** P6 review regressions: domain H1, H2, M2, M5 and security H3. */
class P6ReviewHighFixesTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    private CapitalisationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
        $this->svc = app(CapitalisationService::class);
    }

    /** @return array{0:ExpenseRequest,1:Actual} */
    private function billedCapexExpense(string $amount): array
    {
        $x = $this->capexExpense($amount);
        ExpenseRequest::withoutGlobalScopes()->whereKey($x->id)->update(['status' => 'approved']);
        $a = $this->capexActual($x, $amount, '2027-03-20');
        ExpenseRequest::withoutGlobalScopes()->whereKey($x->id)->update(['status' => 'received']);

        return [$x->refresh(), $a];
    }

    private function inv()
    {
        $inv = $this->inDelivery($this->approvedInvestment());

        return $this->flagCapitalisable($inv);
    }

    /* ---- H1 */

    public function test_retagging_a_line_with_actuals_in_a_closed_period_is_refused_and_the_frozen_statement_does_not_move(): void // C150, C152, C162
    {
        $svc = BusinessService::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'BS-9', 'name' => 'Cards']);
        $p1 = $this->periodSeq(1);
        $this->postActual($this->monLines[0], $p1, '800000.00');
        $this->makeAllocation(['name' => 'ops-svc', 'target_type' => 'business_service', 'target_ref' => null, 'target_id' => $svc->id, 'percentage' => '50']);
        app(AllocationService::class)->run($this->org->id, $this->fy->id, [$p1->id], ['actual'], $this->fin);
        $this->closePeriodDirectly($p1);
        $before = app(AllocationService::class)->outcome($this->org->id, 'business_service', $p1, 'actual', null);

        $classifier = app(BudgetLineClassifier::class);
        $this->assertRefused(fn () => $classifier->classify([$this->monLines[0]->id], ['business_service_id' => $svc->id], $this->fin), 'line_ids', 'closed or locked period');
        $this->assertRefused(fn () => $classifier->classify([$this->monLines[0]->id], ['business_service_id' => null], $this->fin), 'line_ids', 'closed or locked period');
        $this->assertNull($this->monLines[0]->refresh()->business_service_id);
        $this->assertSame($before, app(AllocationService::class)->outcome($this->org->id, 'business_service', $p1, 'actual', null));
        $this->assertSame(1, $classifier->classify([$this->monLines[1]->id], ['business_service_id' => $svc->id], $this->fin)); // a line with no frozen spend is still taggable
    }

    /* ---- H2 */

    public function test_pool_excludes_recoverable_vat_only_when_the_bank_recovers_it(): void // C156, IAS 16.16
    {
        [$x, $a] = $this->billedCapexExpense('1000000.00');
        $inv = Invoice::withoutGlobalScopes()->findOrFail($a->invoice_id);
        Invoice::withoutGlobalScopes()->whereKey($inv->id)->update(['tax_amount' => '75000.00', 'total_amount' => '1075000.00', 'base_total_amount' => '1075000.00']);
        Actual::withoutGlobalScopes()->whereKey($a->id)->update(['amount' => '1075000.00', 'base_amount' => '1075000.00']);

        $this->assertSame('1075000.00', $this->svc->eligibility('expense', $x->id, '2027-03-31', $this->org->id)['pool']); // VAT irrecoverable is cost
        Setting::withoutGlobalScopes()->whereKey(app(FinanceSettings::class)->for($this->org->id)->id)->update(['capitalisation_vat_recoverable' => true]);
        app(FinanceSettings::class)->flush();
        $this->assertSame('1000000.00', $this->svc->eligibility('expense', $x->id, '2027-03-31', $this->org->id)['pool']);
    }

    public function test_excluded_amount_cannot_be_capitalised_by_a_second_capitalisation(): void // C156, IAS 38.54
    {
        $inv = $this->inv();
        $this->capexActual($inv, '1000000.00', '2027-03-20');
        $c = $this->makeCapitalisation($inv, ['amount' => '800000.00', 'excluded_amount' => '200000.00', 'excluded_reason' => 'Training and research phase costs'], true);
        $this->svc->submit($c, $this->manager);
        $e = $this->svc->eligibility('investment', $inv->id, '2027-03-31', $this->org->id);
        $this->assertSame('1000000.00', $e['already']);
        $this->assertSame('0.00', $e['remaining']);
        $this->assertRefused(fn () => $this->makeCapitalisation($inv, ['amount' => '200000.00']), 'amount');
    }

    public function test_lease_actuals_are_not_in_the_pool(): void // C156, IFRS 16
    {
        $inv = $this->inv();
        $this->capexActual($inv, '500000.00', '2027-03-20');
        $lease = $this->capexActual($inv, '300000.00', '2027-03-21');
        Actual::withoutGlobalScopes()->whereKey($lease->id)->update(['is_lease' => true]);

        $this->assertSame('500000.00', $this->svc->eligibility('investment', $inv->id, '2027-03-31', $this->org->id)['pool']);
    }

    public function test_costs_before_the_recognition_date_are_not_in_the_pool(): void // C156, IAS 38.65-71
    {
        $inv = $this->inv();
        $this->capexActual($inv, '400000.00', '2027-02-10'); // research phase, before the criteria were met
        $this->capexActual($inv, '600000.00', '2027-03-20');

        $this->assertSame('1000000.00', $this->svc->eligibility('investment', $inv->id, '2027-03-31', $this->org->id)['pool']);
        $this->assertSame('600000.00', $this->svc->eligibility('investment', $inv->id, '2027-03-31', $this->org->id, null, '2027-03-01')['pool']);
        $this->assertRefused(fn () => $this->makeCapitalisation($inv, ['amount' => '1000000.00', 'recognition_date' => '2027-03-01']), 'amount');
        $c = $this->makeCapitalisation($inv, ['amount' => '600000.00', 'recognition_date' => '2027-03-01']);
        $this->assertSame('2027-03-01', $c->recognition_date->toDateString());
    }

    public function test_an_actual_in_two_sources_is_capitalised_once(): void // C156, double counting
    {
        $inv = $this->inv();
        [$x, $a] = $this->billedCapexExpense('900000.00');
        Actual::withoutGlobalScopes()->whereKey($a->id)->update(['investment_id' => $inv->id]); // in the expense pool and the investment pool
        $this->assertSame('900000.00', $this->svc->eligibility('investment', $inv->id, '2027-03-31', $this->org->id)['pool']);

        $this->svc->submit($this->makeCapitalisation($x, ['amount' => '900000.00'], true), $this->fin);
        $this->assertSame('0.00', $this->svc->eligibility('investment', $inv->id, '2027-03-31', $this->org->id)['pool']);
    }

    /* ---- M5 */

    public function test_declining_balance_on_a_short_life_without_a_rate_is_validated_not_a_500(): void // C157
    {
        $inv = $this->inv();
        $this->capexActual($inv, '900000.00', '2027-03-20');
        $this->assertRefused(fn () => $this->makeCapitalisation($inv, ['method' => 'declining', 'useful_life_months' => 12]), 'declining_rate_pct');
        $lines = AmortisationSchedule::build('1200000.00', '0.00', 12, 'declining', null, '2027-01-01');
        $this->assertCount(12, $lines);
        $this->assertSame('1200000.00', array_reduce($lines, fn ($c, $l) => bcadd($c, $l['amount'], 2), '0.00'));
    }

    /* ---- H3 */

    private function exportedRun(): ChargebackRun
    {
        $p1 = $this->periodSeq(1);
        $this->postActual($this->monLines[0], $p1, '800000.00');
        $this->makeAllocation();
        app(AllocationService::class)->run($this->org->id, $this->fy->id, [$p1->id], ['actual'], $this->fin);
        $this->closePeriodDirectly($p1);

        return app(ChargebackService::class)->export($this->org->id, $p1->id, 'business_unit', $this->fin);
    }

    public function test_only_period_closers_can_create_download_or_list_chargeback_runs(): void // security H3
    {
        $run = $this->exportedRun();
        $body = ['period_id' => $this->periodSeq(2)->id, 'target_type' => 'business_unit'];
        $auditor = $this->userWith('Auditor');
        $roles = ['IT Budget Owner' => $this->owner2, 'Finance Approver' => $this->cfo, 'Auditor' => $auditor];
        foreach ($roles as $name => $user) {
            $this->actingAs($user)->post(route('finance.chargeback.store'), $body)->assertForbidden();
            $this->actingAs($user)->get(route('finance.chargeback.download', $run))->assertForbidden();
            $this->actingAs($user)->post(route('finance.chargeback.void', $run), ['reason' => 'Trying to void as '.$name])->assertForbidden();
        }
        $this->assertSame('exported', $run->refresh()->status);

        foreach ([$this->cfo, $this->owner2] as $viewer) {
            $page = $this->actingAs($viewer)->get(route('finance.monitor.showback'));
            if ($page->getStatusCode() === 200) {
                $props = $page->original->getData()['page']['props'];
                $this->assertSame([], array_values((array) ($props['chargeback']['runs'] ?? [])));
                $this->assertFalse($props['can']['run']);
            }
        }
        $this->actingAs($this->fin)->get(route('finance.chargeback.download', $run))->assertOk();
        $props = $this->actingAs($this->fin)->get(route('finance.monitor.showback'))->assertOk()->original->getData()['page']['props'];
        $this->assertCount(1, $props['chargeback']['runs']);
        $this->assertTrue($props['can']['run']);
    }

    public function test_the_exporter_cannot_void_their_own_run(): void // security H3, SoD
    {
        $run = $this->exportedRun();
        $reason = ['reason' => 'Wrong ERP cost centre loaded for RET.'];
        $this->actingAs($this->fin)->post(route('finance.chargeback.void', $run), $reason)->assertForbidden();
        $this->actingAs($this->userWith('IT Finance Controller'))->post(route('finance.chargeback.void', $run), $reason)->assertRedirect();
        $this->assertSame('voided', $run->refresh()->status);
    }
}
