<?php

namespace Tests\Feature\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\FxRate;
use App\Models\Finance\Period;
use App\Models\Finance\Setting;
use App\Models\Finance\TaxType;
use App\Services\Finance\ActualsService;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\ProcurementService;
use App\Services\Finance\ThreeWayMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsExpenses;
use Tests\TestCase;

/** ATH-FIN-001 §12 FIN-03.03 to FIN-03.06 and FIN-07.02: purchase orders, receipts, the three-way match and invoice approval. */
class ProcurementThreeWayMatchTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootExpenses();
    }

    private function tolerance(string $pct, string $abs): void
    {
        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['match_tolerance_pct' => $pct, 'match_tolerance_abs' => $abs]);
        app(FinanceSettings::class)->flush();
    }

    /** A received PO of 2 units at 500,000 each (1,000,000). */
    private function receivedPo(string $amount = '1000000.00', string $title = 'Core switch refresh')
    {
        $po = $this->po($this->approvedExpense(['amount' => $amount, 'title' => $title]));
        $this->grn($po);

        return $po->refresh();
    }

    /** An invoice whose single line bills $qty units at $unit against the PO's first line. */
    private function billing($po, string $qty, string $unit, array $over = [])
    {
        return $this->invoice($over + ['po_id' => $po->id, 'lines' => [['po_line_id' => $po->lines()->first()->id, 'description' => 'Core switch', 'quantity' => $qty, 'unit_cost' => $unit]]]);
    }

    private function avail(): array
    {
        return app(BudgetAvailability::class)->forLine($this->line);
    }

    private function stepNames(object $subject): array
    {
        return ApprovalRequest::withoutGlobalScopes()->where('process', InvoiceService::PROCESS)->where('subject_id', $subject->id)->latest('id')->firstOrFail()->steps()->orderBy('sequence')->pluck('name')->all();
    }

    /** FIN-03.03: the PO is struck at the PO-date rate, and the requester cannot commit their own request. */
    public function test_commit_creates_po_with_fx_at_po_date_and_refuses_requester(): void
    {
        $e = $this->approvedExpense(['currency' => 'USD', 'amount' => '1000.00']);
        $this->assertRefused(fn () => $this->po($e, null, $this->requester), null, 'segregation of duties');
        $po = $this->po($e->refresh());
        $this->assertSame('1500.00000000', (string) $po->fx_rate);
        $this->assertSame('1500000.00', (string) $po->base_total_amount);
        $this->assertSame('committed', $e->refresh()->status);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1700, 'rate_date' => '2027-03-01', 'rate_type' => 'spot']);
        $this->assertSame('1500.00000000', (string) $po->refresh()->fx_rate, 'a later rate never re-strikes an issued PO');
    }

    /** P3-11: a PO may exceed the approved total only within the match tolerance. */
    public function test_po_above_approved_total_beyond_tolerance_is_refused(): void
    {
        $e = $this->approvedExpense(['amount' => '1000000.00']);
        $svc = app(ProcurementService::class);
        $this->assertRefused(fn () => $svc->commit($e, ['po_date' => '2027-03-01', 'lines' => [['description' => 'Switch', 'quantity' => '1', 'unit_cost' => '1100000.00']]], $this->procurement), 'lines', 'beyond the match tolerance');
        $po = $svc->commit($e->refresh(), ['po_date' => '2027-03-01', 'lines' => [['description' => 'Switch', 'quantity' => '1', 'unit_cost' => '1015000.00']]], $this->procurement);
        $this->assertSame('1015000.00', (string) $po->total_amount);
    }

    /** FIN-03.03: receipts derive the PO and request statuses and refuse over-receipt. */
    public function test_partial_and_full_receipt_derive_statuses_and_refuse_over_receipt(): void
    {
        $po = $this->po();
        $line = $po->lines()->first();
        $svc = app(ProcurementService::class);
        $svc->receive($po, ['received_on' => '2027-03-01', 'lines' => [['po_line_id' => $line->id, 'quantity' => '1', 'accepted' => true]]], $this->procurement);
        $this->assertSame('partially_received', $po->refresh()->status);
        $this->assertRefused(fn () => $svc->receive($po, ['received_on' => '2027-03-01', 'lines' => [['po_line_id' => $line->id, 'quantity' => '2', 'accepted' => true]]], $this->procurement), null, 'exceed');
        $svc->receive($po->refresh(), ['received_on' => '2027-03-01', 'lines' => [['po_line_id' => $line->id, 'quantity' => '1', 'accepted' => true]]], $this->procurement);
        $this->assertSame('received', $po->refresh()->status);
        $this->assertSame('received', $po->expenseRequest->refresh()->status);
    }

    /** FIN-03.04: a variance inside the tolerance auto-matches. */
    public function test_within_tolerance_auto_matches(): void
    {
        $inv = $this->billing($this->receivedPo(), '2', '505000.00'); // 10,000 over on 1,000,000; the limit is min(2% = 20,000, 50,000)
        $this->assertSame('matched', $inv->match_status);
        $this->assertSame('10000.00', (string) $inv->match_variance_amount);
    }

    /** FIN-03.04: a variance beyond the tolerance blocks submission. */
    public function test_outside_tolerance_is_variance_and_cannot_submit(): void
    {
        $inv = $this->billing($this->receivedPo(), '2', '520000.00'); // 40,000 over
        $this->assertSame('variance', $inv->match_status);
        $this->assertSame('1000000.00', $inv->match_detail['expected']);
        $this->assertRefused(fn () => app(InvoiceService::class)->submit($inv, $this->procurement), 'match_status');
    }

    /** P3-10 / D12: the tolerance is the stricter of the percentage and the absolute amount; over-quantity is always a variance. */
    public function test_tolerance_uses_stricter_of_pct_and_abs_and_flags_over_quantity(): void
    {
        $m = app(ThreeWayMatcher::class);
        $this->assertSame('20000.00', $m->limit('1000000.00', '1', '2', '50000'));
        $this->assertSame('50000.00', $m->limit('10000000.00', '1', '2', '50000')); // 2% would be 200,000
        $this->assertTrue($m->within('50000.00', '10000000.00', '1', '2', '50000'));
        $this->assertFalse($m->within('50000.01', '10000000.00', '1', '2', '50000'));

        $this->tolerance('5', '10000');
        $po = $this->receivedPo();
        $this->assertSame('variance', $this->billing($po, '2', '510000.00')->match_status, '20,000 is within 5% but not within the 10,000 absolute limit');
        $this->assertSame('matched', $this->billing($this->receivedPo('1000000.00', 'Second'), '2', '504000.00', ['vendor_invoice_no' => 'OK-1'])->match_status);

        $this->tolerance('2', '50000');
        $over = $this->billing($this->receivedPo('1000000.00', 'Third'), '3', '500000.00', ['vendor_invoice_no' => 'OVER-1']);
        $this->assertSame('variance', $over->match_status);
        $this->assertContains('over_quantity', $over->match_detail['lines'][0]['flags']);
    }

    /** FIN-03.04: an override needs a reason and adds the Finance Controller stage. */
    public function test_override_needs_reason_and_adds_finance_controller_stage(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(InvoiceService::class);
        $inv = $this->billing($this->receivedPo(), '2', '560000.00');
        $this->assertSame('variance', $inv->match_status);
        $this->assertRefused(fn () => $svc->override($inv, 'short', $this->fin), 'reason');
        $svc->override($inv->refresh(), 'Vendor passed on a currency-driven price rise, agreed with the account manager.', $this->fin);
        $this->assertSame('override', $inv->refresh()->match_status);
        $this->assertSame($this->fin->id, $inv->match_override_by_id);
        $svc->submit($inv, $this->procurement);
        $this->assertContains('Finance Controller review', $this->stepNames($inv));
        $clean = $svc->submit($this->billing($this->receivedPo('1000000.00', 'Second'), '2', '500000.00', ['vendor_invoice_no' => 'CLEAN-1']), $this->procurement);
        $this->assertNotContains('Finance Controller review', $this->stepNames($clean));
    }

    /** FIN-03.04, FIN-03.05: approval posts one actual in the invoice's own period; a closed period refuses it. */
    public function test_invoice_approval_posts_actual_in_invoice_period_once(): void
    {
        $svc = app(InvoiceService::class);
        $april = Period::where('fiscal_year_id', $this->fy->id)->where('sequence', 4)->firstOrFail();
        $inv = $this->billing($this->receivedPo(), '2', '500000.00', ['period_id' => $april->id]);
        $this->assertSame($april->id, $inv->period_id);
        $svc->submit($inv, $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $actual = Actual::where('invoice_id', $inv->id)->sole();
        $this->assertSame($april->id, $actual->period_id, 'the actual lands in invoice.period_id, not in the approval date\'s period');
        $this->assertSame('invoice:'.$inv->id, $actual->posting_key);
        $this->assertSame($actual->id, app(ActualsService::class)->postInvoice($inv->refresh(), $this->cfo)->id);
        $this->assertSame(1, Actual::where('invoice_id', $inv->id)->count());

        $jan = Period::where('fiscal_year_id', $this->fy->id)->where('sequence', 1)->firstOrFail();
        $late = $this->invoice(['vendor_invoice_no' => 'LATE-1', 'period_id' => $jan->id]);
        $svc->submit($late, $this->procurement);
        $jan->forceFill(['status' => 'closed'])->save();
        $this->assertRefused(fn () => $svc->approve($late->refresh(), $this->cfo), 'period_id', 'closed');
        $this->assertSame(0, Actual::where('invoice_id', $late->id)->count());
        $this->assertSame('submitted', $late->refresh()->status);
    }

    /** FIN-07.02: above the band the final approver cannot be a PO approver; below it they can. */
    public function test_invoice_final_approver_cannot_be_po_approver_above_band(): void
    {
        $svc = app(InvoiceService::class);
        $big = $this->po($this->approvedExpense(['amount' => '10500000.00'])); // the CFO approved the request, so the CFO approved the PO
        $this->grn($big);
        $inv = $this->invoice(['po_id' => $big->id]);
        $svc->submit($inv, $this->procurement);
        $this->assertSame('yes', $inv->refresh()->high_value);
        $this->assertRefused(fn () => $svc->approve($inv, $this->cfo), 'approval', 'purchase order');
        $this->assertStringContainsString('approved', $svc->approve($inv->refresh(), $this->md));

        $small = $this->po($this->approvedExpense(['amount' => '1000000.00', 'title' => 'Small']));
        $this->grn($small);
        $inv2 = $this->invoice(['po_id' => $small->id, 'vendor_invoice_no' => 'SMALL-1']);
        $svc->submit($inv2, $this->procurement);
        $this->assertSame('no', $inv2->refresh()->high_value);
        $this->assertStringContainsString('approved', $svc->approve($inv2, $this->cfo));
    }

    /** FIN-03.04: the person who approved or submitted an invoice cannot be the one who pays it. */
    public function test_payer_cannot_be_approver_or_submitter(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $svc->submit($this->invoice(), $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $this->assertRefused(fn () => $svc->schedule($inv->refresh(), '2027-03-10', $this->cfo), 'approval', 'cannot also pay');
        $this->assertRefused(fn () => $svc->schedule($inv->refresh(), '2027-03-10', $this->procurement), 'approval');
        $svc->schedule($inv->refresh(), '2027-03-10', $this->fin);
        $this->assertRefused(fn () => $svc->markPaid($inv->refresh(), '2027-03-01', 'NIP-1', $this->cfo), 'approval');
        $this->assertSame('paid', $svc->markPaid($inv->refresh(), '2027-03-01', 'NIP-1', $this->fin)->status);
    }

    /** FIN-03.04: dispute, resolve, schedule and pay move the invoice and never post a second actual. */
    public function test_schedule_pay_dispute_resolve(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $this->invoice();
        $svc->dispute($inv, 'Delivery note does not match the goods received.', $this->procurement);
        $this->assertSame('disputed', $inv->refresh()->status);
        $this->assertRefused(fn () => $svc->submit($inv, $this->procurement), 'workflow');
        $svc->resolveDispute($inv->refresh(), $this->procurement, 'Vendor reissued the delivery note.');
        $this->assertSame('draft', $inv->refresh()->status);
        $svc->submit($inv, $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $svc->schedule($inv->refresh(), '2027-03-10', $this->fin);
        $this->assertSame('scheduled', $inv->refresh()->status);
        $svc->markPaid($inv->refresh(), '2027-03-01', 'NIP-9', $this->fin);
        $this->assertSame(1, Actual::where('invoice_id', $inv->id)->count(), 'payment never posts');
    }

    /** Control: the same vendor invoice number cannot be captured twice for a vendor. */
    public function test_duplicate_vendor_invoice_number_refused(): void
    {
        $this->invoice(['vendor_invoice_no' => 'DUP-7']);
        $this->assertRefused(fn () => $this->invoice(['vendor_invoice_no' => 'DUP-7']), 'vendor_invoice_no');
    }

    /** FIN-01.06 / P3-3: VAT is part of the budget cost, withholding tax is not, and the payable is net of it. */
    public function test_vat_and_wht_computation_and_budget_cost(): void
    {
        $vat = TaxType::where('code', 'VAT')->firstOrFail();
        $wht = TaxType::where('code', 'WHT-5')->firstOrFail();
        $svc = app(InvoiceService::class);
        $inv = $this->invoice(['subtotal' => '1000000.00', 'tax_type_id' => $vat->id, 'wht_tax_type_id' => $wht->id]);
        $this->assertSame(['1000000.00', '75000.00', '50000.00', '1075000.00'], [(string) $inv->subtotal, (string) $inv->tax_amount, (string) $inv->wht_amount, (string) $inv->total_amount]);
        $svc->submit($inv, $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $this->assertSame('1075000.00', (string) Actual::where('invoice_id', $inv->id)->sole()->base_amount, 'the actual is subtotal plus VAT');
        $this->assertSame('1075000.00', $this->avail()['actual']);
    }

    /** L4 / P3-15: the IFRS 16 indicator flows from the contract to the invoice and its actual. */
    public function test_lease_contract_flows_ifrs16_flag_to_invoice_and_actual(): void
    {
        $lease = $this->contract(['title' => 'Colocation lease', 'type' => 'lease', 'lease_assessment' => 'ifrs16_lease']);
        $this->assertTrue($lease->is_ifrs16_lease);
        $inv = $this->invoice(['contract_id' => $lease->id, 'vendor_invoice_no' => 'LEASE-1']);
        $this->assertTrue((bool) $inv->is_lease);
        app(InvoiceService::class)->submit($inv, $this->procurement);
        app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);
        $this->assertTrue((bool) Actual::where('invoice_id', $inv->id)->sole()->is_lease);
    }

    /** FIN-03.06: a prepaid invoice schedules period releases instead of one actual. */
    public function test_prepaid_invoice_schedules_releases_instead_of_actual(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $svc->submit($this->invoice(['vendor_invoice_no' => 'ANN-2', 'subtotal' => '1200000.00', 'prepayment_months' => 6]), $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $this->assertSame(0, Actual::where('invoice_id', $inv->id)->count());
        $rows = Accrual::where('invoice_id', $inv->id)->get();
        $this->assertCount(6, $rows);
        $this->assertSame('1200000.00', bcadd((string) $rows->sum('base_amount'), '0', 2));
        $this->assertSame('0.00', $this->avail()['actual'], 'nothing is actual until a release is posted');
    }

    /** P3-2: invoices relieve the commitment by what they bill, and closing the request releases the remainder. */
    public function test_commitment_relieved_by_invoices_and_released_on_close(): void
    {
        $svc = app(InvoiceService::class);
        $e = $this->approvedExpense(['amount' => '1000000.00']);
        $po = $this->po($e);
        $this->grn($po);
        $this->assertSame('1000000.00', $this->avail()['committed']);
        $inv = $this->billing($po, '1', '500000.00');
        $svc->submit($inv, $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $a = $this->avail();
        $this->assertSame(['500000.00', '500000.00'], [$a['committed'], $a['actual']]);
        $this->assertNotSame('invoiced', $e->refresh()->status, 'half billed is not invoiced');
        app(ExpenseService::class)->close($e, $this->fin, 'Balance will not be billed.');
        $a = $this->avail();
        $this->assertSame(['0.00', '500000.00', '11500000.00'], [$a['committed'], $a['actual'], $a['available']]);
        $this->assertSame('closed', $po->refresh()->status);
    }
}
