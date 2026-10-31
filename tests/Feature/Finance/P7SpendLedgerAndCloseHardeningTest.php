<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\Category;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Invoice;
use App\Models\Finance\Setting;
use App\Models\Finance\VarianceNote;
use App\Services\Finance\AccrualService;
use App\Services\Finance\ActualsService;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\BudgetService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\PeriodCloseService;
use App\Services\Finance\ProcurementService;
use App\Services\Finance\RecurringExpenseService;
use App\Services\Finance\VarianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/**
 * P7 WP-B: spend, ledger, monitoring and period close hardening (P3-sec-L1/L2, P3-M10/M11/M12/L14/L15, P4-M1/M2/M3/M6,
 * P6-M7/sec-M2/M3; decisions P7-5..P7-10). Runs on the P4 monitoring bank (today 2027-04-05).
 */
class P7SpendLedgerAndCloseHardeningTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
    }

    private function svc(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    private function foreignCategory(): Category
    {
        return Category::withoutGlobalScopes()->create(['organization_id' => $this->otherBank()->id, 'code' => 'ZZ-1', 'name' => 'Foreign', 'kind' => 'tower']);
    }

    private function tolerance(string $pct, string $abs): void
    {
        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['match_tolerance_pct' => $pct, 'match_tolerance_abs' => $abs]);
        app(FinanceSettings::class)->flush();
    }

    public function test_po_and_invoice_line_category_and_asset_must_belong_to_the_bank(): void // P3-sec-L1
    {
        $foreign = $this->foreignCategory();
        $e = $this->approvedExpense(['amount' => '1000000.00']);
        $this->assertRefused(fn () => app(ProcurementService::class)->commit($e, ['po_date' => '2027-03-01',
            'lines' => [['description' => 'Switch', 'quantity' => '1', 'unit_cost' => '1000000.00', 'category_id' => $foreign->id]]], $this->procurement), 'lines.0.category_id', 'does not belong');
        $this->assertRefused(fn () => app(ProcurementService::class)->commit($e->refresh(), ['po_date' => '2027-03-01',
            'lines' => [['description' => 'Switch', 'quantity' => '1', 'unit_cost' => '1000000.00', 'asset_id' => 999999]]], $this->procurement), 'lines.0.asset_id');

        $this->assertRefused(fn () => $this->invoice(['lines' => [['description' => 'Fee', 'quantity' => '1', 'unit_cost' => '1000.00', 'category_id' => $foreign->id]]]), 'lines.0.category_id');
    }

    public function test_recurring_expense_refuses_a_cost_centre_outside_the_editors_scope(): void // P3-sec-L2
    {
        $svc = app(RecurringExpenseService::class);
        $data = ['title' => 'Link', 'vendor_id' => $this->vendor->id, 'frequency' => 'monthly', 'amount' => '1000.00', 'currency' => 'NGN', 'starts_on' => '2027-04-01', 'auto_generate' => 'expected_invoice'];
        $this->assertRefused(fn () => $svc->save(null, $data + ['cost_centre_id' => $this->cc2->id], $this->requester), 'cost_centre_id', 'outside your scope');
        $this->assertSame('Link', $svc->save(null, $data + ['cost_centre_id' => $this->ccChild->id], $this->requester)->title);
        $this->assertSame('Link', $svc->save(null, $data + ['cost_centre_id' => $this->cc2->id], $this->fin)->title, 'bank-wide editors are not narrowed');
    }

    public function test_prepayment_releases_in_a_later_year_post_to_the_successor_line_or_unbudgeted(): void // P3-M10 / P7-8
    {
        $fy2 = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2028', 'starts_on' => '2028-01-01', 'status' => 'planning'], $this->fin);
        $dec = $this->periodSeq(12);
        $prepay = function (string $no) use ($dec) {
            $inv = app(InvoiceService::class)->submit($this->invoice(['vendor_invoice_no' => $no, 'subtotal' => '300000.00', 'prepayment_months' => 3, 'period_id' => $dec->id]), $this->procurement);
            app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);

            return Accrual::where('invoice_id', $inv->id)->orderBy('id')->get();
        };

        $rel = $prepay('PRE-A');
        $this->assertSame($this->line->id, (int) $rel[0]->budget_line_id);
        $this->assertNull($rel[1]->budget_line_id, 'no FY2028 baseline: unbudgeted, not the prior-year line');
        $this->assertNull($rel[2]->budget_line_id);

        $svc = app(BudgetService::class);
        $b = $svc->createVersion($this->openCycle('annual', $fy2), $this->ccChild, 'zero_based', $this->requester);
        $svc->addLine($b, $this->lineData(['annual_amount' => '1200000.00']), $this->requester);
        $svc->submit($b->refresh(), $this->requester);
        $svc->approve($b->refresh(), $this->cfo);
        $new = $svc->baseline($b->refresh(), $this->fin)->refresh()->lines()->first();
        DB::table('fin_budget_lines')->where('id', $new->id)->update(['source_line_id' => $this->line->id]);

        $rel = $prepay('PRE-B');
        $this->assertSame($this->line->id, (int) $rel[0]->budget_line_id);
        $this->assertSame([$new->id, $new->id], [(int) $rel[1]->budget_line_id, (int) $rel[2]->budget_line_id], 'the successor in the FY2028 baseline');
        $this->assertInstanceOf(FiscalYear::class, $fy2);
    }

    public function test_gl_duplicate_key_includes_amount_and_date_and_flags_reference_only_matches(): void // P3-M11 / L15 / P7-9
    {
        $inv = app(InvoiceService::class)->submit($this->invoice(['subtotal' => '400000.00']), $this->procurement);
        app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);
        $gl = GlAccount::firstOrCreate(['code' => '6202'], ['name' => 'Maintenance', 'type' => 'opex']);
        $row = fn ($ref, $amount, $date = '2027-03-01') => [$date, '6202', 'CC-1A', $amount, 'Memo', $ref];

        $batch = app(ActualsService::class)->importGl($this->glCsv([
            $row($inv->invoice_code, '400000.00'),   // exact key: excluded
            $row($inv->invoice_code, '123000.00'),   // same reference, other amount: kept, flagged
            $row('GL-5', '7000.00'), $row('GL-5', '7000.00'), // repeated in the file: the second is a duplicate
        ]), $this->glMapping(), $this->fin);
        $this->assertNotNull($gl->id);

        $byRef = Actual::where('source', 'gl_import')->where('import_batch_id', $batch->id)->orderBy('id')->get();
        $this->assertSame(['duplicate', 'invoice_posting'], [$byRef[0]->match_status, $byRef[0]->match_reason]);
        $this->assertNotSame('duplicate', $byRef[1]->match_status);
        $this->assertSame('possible_duplicate', $byRef[1]->match_reason);
        $this->assertNotSame('duplicate', $byRef[2]->match_status);
        $this->assertSame(['duplicate', 'file_duplicate', $byRef[2]->id], [$byRef[3]->match_status, $byRef[3]->match_reason, (int) $byRef[3]->duplicate_of_id]);
    }

    public function test_direct_invoices_cannot_exceed_the_request_beyond_tolerance(): void // P3-M12 / P7-10
    {
        $this->tolerance('0', '0');
        $e = $this->approvedExpense(['amount' => '1000000.00']);
        $this->invoice(['expense_request_id' => $e->id, 'subtotal' => '700000.00', 'budget_line_id' => null]);
        $this->assertRefused(fn () => $this->invoice(['expense_request_id' => $e->id, 'subtotal' => '400000.00', 'budget_line_id' => null]), 'subtotal', 'more than the approved amount');
        $this->assertSame('300000.00', (string) $this->invoice(['expense_request_id' => $e->id, 'subtotal' => '300000.00', 'budget_line_id' => null])->subtotal);
    }

    public function test_a_failed_recurring_generation_keeps_the_due_date_and_is_audited(): void // P3-L14
    {
        $svc = app(RecurringExpenseService::class);
        $r = $svc->save(null, ['title' => 'EUR licence', 'vendor_id' => $this->vendor->id, 'cost_centre_id' => $this->ccChild->id, 'budget_line_id' => $this->line->id, 'frequency' => 'monthly',
            'amount' => '1000.00', 'currency' => 'EUR', 'starts_on' => '2027-03-01', 'auto_generate' => 'expected_invoice'], $this->fin);
        $this->assertSame(0, $svc->generateFor($r, FinanceClock::today()));
        $this->assertSame('2027-03-01', $r->refresh()->next_due_on->toDateString(), 'the next run retries');
        $this->assertTrue(Activity::where('log_name', 'it-finance')->where('event', 'generation_failed')->where('subject_id', $r->id)->exists());

        FxRate::create(['from_currency' => 'EUR', 'to_currency' => 'NGN', 'rate' => 1800, 'rate_date' => '2027-01-01', 'rate_type' => 'spot']);
        $this->assertSame(2, $svc->generateFor($r->refresh(), FinanceClock::today()), 'March and April are caught up');
    }

    public function test_received_not_invoiced_accrual_uses_the_closing_rate(): void // P4-M6 / P7-7
    {
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1500, 'rate_date' => '2027-03-01', 'rate_type' => 'spot']);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1650, 'rate_date' => '2027-03-31', 'rate_type' => 'closing']);
        $e = $this->approvedExpense(['amount' => '1000.00', 'currency' => 'USD']);
        $po = app(ProcurementService::class)->commit($e, ['po_date' => '2027-03-01', 'lines' => [['description' => 'Licence', 'quantity' => '1', 'unit_cost' => '1000.00']]], $this->procurement);
        $this->grn($po);
        $this->assertSame('1500.00000000', (string) $po->fresh()->fx_rate);

        app(AccrualService::class)->propose($this->periodSeq(3), $this->fin);
        $a = Accrual::where('po_id', $po->id)->sole();
        $this->assertSame(['1000.00', '1650000.00'], [(string) $a->amount, (string) $a->base_amount], 'closing rate, not the PO rate');
        $this->assertSame('1650.00000000', (string) $a->fx_rate);
    }

    public function test_commitment_items_match_the_committed_total_and_posted_accruals_relieve_a_po(): void // P4-M3
    {
        $avail = app(BudgetAvailability::class);
        $req = $this->approvedExpense(['amount' => '600000.00']);
        $po = $this->po($this->approvedExpense(['amount' => '1000000.00']));
        $this->grn($po);

        $items = $avail->commitmentItems([$this->line->id]);
        $this->assertEqualsCanonicalizing(['expense', 'po'], array_column($items, 'type'));
        $this->assertSame([$req->request_code, $po->po_code], [collect($items)->firstWhere('type', 'expense')['code'], collect($items)->firstWhere('type', 'po')['code']]);
        $this->assertSame(['line_id', 'remaining_base', 'href', 'title'], array_values(array_intersect(['line_id', 'remaining_base', 'href', 'title'], array_keys($items[0]))));
        $this->assertSame($avail->forLine($this->line)['committed'], array_reduce($items, fn ($c, $i) => bcadd($c, $i['remaining_base'], 2), '0.00'));

        app(AccrualService::class)->propose($this->periodSeq(3), $this->fin);
        app(AccrualService::class)->postAll($this->periodSeq(3), $this->fin);
        $this->assertSame('0.00', $avail->remainingForPo($po->refresh()), 'a posted accrual is already an actual');
        $this->assertNotContains('po', array_column($avail->commitmentItems([$this->line->id]), 'type'));
    }

    public function test_bva_severity_ignores_periods_that_have_not_ended(): void // P4-M1
    {
        $mon = app(MonitoringService::class);
        $this->postActual($this->monLines[0], $this->periodSeq(1), '4000000.00'); // ended budget to March is 3m: +33 %, red
        $f = fn (array $over) => $mon->bva($this->org->id, ['fiscal_year_id' => $this->fy->id, 'view' => 'period', 'group_by' => 'line'] + $over, $this->fin)['matrix'];

        $wide = $f(['from_period_id' => $this->periodSeq(1)->id, 'to_period_id' => $this->periodSeq(6)->id]);
        $row = collect($wide['rows'])->firstWhere('line_id', $this->monLines[0]->id);
        $this->assertSame('red', $row['severity'], 'future budget does not dilute the verdict');
        $this->assertSame('6000000.00', $row['budget'], 'the amounts still span the whole range');

        $future = $f(['from_period_id' => $this->periodSeq(5)->id, 'to_period_id' => $this->periodSeq(6)->id]);
        $this->assertNull($future['totals']['severity']);
        $this->assertSame([null, null, null], array_column($future['rows'], 'severity'));
        $this->assertNotSame('0.00', $future['totals']['budget']);
    }

    public function test_close_needs_red_variances_acknowledged_by_a_second_person(): void // P4-M2 / P7-5
    {
        $p1 = $this->periodSeq(1);
        foreach ([1, 2] as $i) {
            $this->postActual($this->monLines[$i], $p1, '1000000.00');
        }
        $this->postActual($this->monLines[0], $p1, '1300000.00'); // +30 %: red
        app(VarianceService::class)->evaluate($p1, $this->fin, true);
        $item = fn () => collect($this->svc()->checklist($p1->refresh())['items'])->firstWhere('key', 'variances_explained');
        $this->assertSame('fail', $item()['status']);

        $this->explainAll($p1);
        $this->assertSame('fail', $item()['status'], 'explained is not enough');
        $this->assertStringContainsString('1 red variance(s) await acknowledgement', $item()['detail']);

        $note = VarianceNote::withoutGlobalScopes()->where('period_id', $p1->id)->where('severity', 'red')->sole();
        $this->assertRefused(fn () => app(VarianceService::class)->acknowledge($note->refresh(), $this->requester), 'approval', 'segregation');
        app(VarianceService::class)->acknowledge($note->refresh(), $this->fin);
        $this->assertSame('pass', $item()['status']);
    }

    public function test_reopen_is_refused_while_a_chargeback_is_exported_and_clears_frozen_results(): void // P6-M7 / sec-M2 / M3 / P7-6
    {
        $p1 = $this->periodSeq(1);
        foreach ($this->monLines as $l) {
            $this->postActual($l, $p1, '1000000.00');
        }
        $this->svc()->close($p1, $this->fin);
        AllocationRun::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'fiscal_year_id' => $this->fy->id, 'period_id' => $p1->id, 'amount_type' => 'actual', 'target_type' => 'business_unit',
            'computed_at' => now(), 'is_frozen' => true]);
        $run = ChargebackRun::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'run_code' => 'CBK-0001', 'period_id' => $p1->id, 'target_type' => 'business_unit', 'status' => 'exported',
            'currency' => 'NGN', 'file_sha256' => str_repeat('a', 64), 'content' => 'x', 'exported_by_id' => $this->fin->id, 'exported_at' => now()]);

        $this->assertRefused(fn () => $this->svc()->requestReopen($p1, 'A January invoice was posted to the wrong line.', $this->cfo), 'reason', 'void it');

        // an approved capitalisation that consumed this period's actuals is a warning on the request
        $actual = Actual::withoutGlobalScopes()->where('period_id', $p1->id)->first();
        Capitalisation::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'capitalisation_code' => 'CAP-0001', 'title' => 'x', 'source_type' => 'investment', 'standard' => 'ias_38',
            'available_for_use_on' => '2027-03-01', 'amount' => 1, 'useful_life_months' => 12, 'method' => 'straight_line', 'criteria_checklist' => '[]', 'status' => 'capitalised', 'consumed_actual_ids' => [$actual->id]]);
        $this->assertSame(['CAP-0001'], $this->svc()->capitalisationWarnings($p1));

        $run->forceFill(['status' => 'voided'])->save();
        $this->fin->givePermissionTo('approve it-finance');
        $req = $this->svc()->requestReopen($p1, 'A January invoice was posted to the wrong line.', $this->cfo);
        $this->assertTrue(Activity::where('event', 'reopen_requested')->get()->contains(fn ($a) => ($a->properties['capitalisations'] ?? null) === ['CAP-0001']));
        $this->svc()->approveReopen($req->refresh(), $this->head);

        $this->assertSame('open', $p1->refresh()->status);
        $this->assertSame(0, AllocationRun::withoutGlobalScopes()->where('period_id', $p1->id)->where('is_frozen', true)->count(), 'the re-close recomputes');
    }

    public function test_default_tolerance_lets_a_small_overrun_through(): void // P7-10 (sanity: existing tolerance, no new setting)
    {
        $this->tolerance('2', '100000');
        $e = $this->approvedExpense(['amount' => '1000000.00']);
        $this->assertSame('1015000.00', (string) $this->invoice(['expense_request_id' => $e->id, 'subtotal' => '1015000.00', 'budget_line_id' => null])->subtotal);
        $this->assertInstanceOf(Invoice::class, Invoice::where('expense_request_id', $e->id)->first());
        $this->assertNotNull(CostCentre::first());
    }
}
