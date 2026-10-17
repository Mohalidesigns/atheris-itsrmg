<?php

namespace Tests\Feature\Finance;

use App\Http\Requests\Finance\ImportActualsRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Approvals\ApprovalWorkflowStage;
use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\Period;
use App\Models\User;
use App\Notifications\Finance\FinanceApprovalRequestedNotification;
use App\Services\Finance\AccrualService;
use App\Services\Finance\ActualsService;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\BudgetService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Finance\Concerns\BuildsExpenses;
use Tests\TestCase;

/** Regression tests for the P3 (Expenses) security and domain review findings. */
class ExpenseReviewRegressionTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootExpenses();
    }

    private function avail($line = null): array
    {
        return app(BudgetAvailability::class)->forLine($line ?? $this->line);
    }

    /** FIN-07.02 (S-H1, S-M5): the variance overrider and the expense requester are excluded from deciding the invoice. */
    public function test_s_h1_override_author_and_requester_are_sod_excluded(): void
    {
        $po = $this->po($this->approvedExpense());
        $this->grn($po);
        $inv = $this->invoice(['po_id' => $po->id, 'lines' => [['po_line_id' => $po->lines()->first()->id, 'description' => 'x', 'quantity' => '2', 'unit_cost' => '560000.00']]]);
        app(InvoiceService::class)->override($inv->refresh(), 'Vendor passed on a currency-driven price rise, agreed.', $this->fin);
        $ids = $inv->refresh()->sodExcludedIds();
        $this->assertContains($this->fin->id, $ids);
        $this->assertContains($this->requester->id, $ids);
        // the capturer/submitter list used by submit/withdraw is not widened
        app(InvoiceService::class)->submit($inv->refresh(), $this->procurement);
        $this->assertRefused(fn () => app(InvoiceService::class)->approve($inv->refresh(), $this->fin));
    }

    /** FIN-07.02 (S-H2): switching the workflow on after submit cannot let the requester approve their own request. */
    public function test_s_h2_late_started_workflow_keeps_segregation_of_duties(): void
    {
        $svc = app(ExpenseService::class);
        $e = $svc->submit($this->expense([], $this->cfo), $this->cfo);
        $wf = ApprovalWorkflow::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'process' => ExpenseService::PROCESS, 'is_active' => true]);
        ApprovalWorkflowStage::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'workflow_id' => $wf->id, 'sequence' => 10, 'name' => 'MD', 'approver_type' => 'job_title',
            'approver_ref' => 'Managing Director', 'min_approvals' => 1, 'sla_hours' => 48, 'is_active' => true, 'condition_field' => 'emergency', 'condition_values' => ['yes']]);
        $this->assertRefused(fn () => $svc->approve($e->refresh(), $this->cfo));
        $this->assertNotSame('approved', $e->refresh()->status);
    }

    /** FIN-07.02 (S-M1): whoever captured the invoice cannot pay it. */
    public function test_s_m1_capturer_cannot_pay(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $this->invoice([], $this->fin);
        $svc->submit($inv, $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $this->assertInstanceOf(ValidationException::class, $this->assertRefused(fn () => $svc->markPaid($inv->refresh(), '2027-03-01', 'PAY-1', $this->fin)));
    }

    /** FIN-08 (S-M2): an approver without the amounts permission gets no amount in the notification. */
    public function test_s_m2_notification_hides_amount_without_permission(): void
    {
        Notification::fake();
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $plain = User::factory()->create(['organization_id' => $this->org->id]);
        $plain->givePermissionTo('view it-finance');
        $this->cc->forceFill(['owner_id' => $plain->id])->save();
        app(ExpenseService::class)->submit($this->expense(), $this->requester);
        $sent = Notification::sent($plain, FinanceApprovalRequestedNotification::class)->first();
        $this->assertNotNull($sent);
        $this->assertFalse($plain->can('view it-finance-amounts'));
        $this->assertSame('', $sent->amountText);
        $this->assertStringNotContainsString('NGN', $sent->toArray($plain)['message'] ?? json_encode($sent->toArray($plain)));
    }

    /** FIN-03.14 (S-M3): the document whitelist refuses active content. */
    public function test_s_m3_html_upload_is_refused(): void
    {
        $e = $this->expense();
        $this->actingAs($this->requester)->post(route('finance.documents.store'), ['subject' => 'expense', 'subject_id' => $e->id, 'kind' => FinanceDocument::KINDS[0], 'title' => 'x',
            'file' => UploadedFile::fake()->createWithContent('evil.html', '<script>alert(1)</script>')])->assertSessionHasErrors('file');
        $this->assertSame(0, FinanceDocument::count());
    }

    /** FIN-03.09 (S-M5): the requester cannot confirm receipt of their own purchase. */
    public function test_s_m5_requester_cannot_receive_goods(): void
    {
        $po = $this->po($this->approvedExpense());
        $this->assertInstanceOf(ValidationException::class, $this->assertRefused(fn () => $this->grn($po, null, $this->requester)));
    }

    /** FIN-03.15 (S-L3): the CSV delimiter is a single character. */
    public function test_s_l3_delimiter_is_one_character(): void
    {
        $rules = ['delimiter' => (new ImportActualsRequest)->rules()['delimiter']];
        $this->assertTrue(Validator::make(['delimiter' => ';'], $rules)->passes());
        $this->assertTrue(Validator::make(['delimiter' => ',,'], $rules)->fails());
    }

    /** FIN-03.03 (D-H1): a prior year's purchase orders do not count against the next year's line. */
    public function test_d_h1_commitments_do_not_leak_across_fiscal_years(): void
    {
        $this->po($this->approvedExpense());
        $this->assertSame('1000000.00', $this->avail()['committed']);
        $fy2 = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2028', 'starts_on' => '2028-01-01', 'status' => 'planning'], $this->fin);
        $b = app(BudgetService::class)->createVersion($this->openCycle('annual', $fy2), $this->ccChild, 'incremental', $this->requester, ['increment_pct' => 5]);
        $next = $this->firstLine($b);
        $this->assertSame($this->line->id, $next->source_line_id);
        $this->assertSame('0.00', $this->avail($next)['committed']);
        $bulk = app(BudgetAvailability::class)->forLines(collect([$next]));
        $this->assertSame('0.00', $bulk[$next->id]['committed']);
    }

    /** FIN-03.06 (D-H2): an unreleased prepayment stays committed. */
    public function test_d_h2_unreleased_prepayment_stays_committed(): void
    {
        $svc = app(InvoiceService::class);
        $inv = $svc->submit($this->invoice(['vendor_invoice_no' => 'PRE-9', 'subtotal' => '300000.00', 'prepayment_months' => 3]), $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $total = (string) Accrual::where('invoice_id', $inv->id)->sum('base_amount');
        $this->assertSame(bcadd($total, '0', 2), $this->avail()['committed']);
        $this->assertSame('0.00', $this->avail()['actual']);
        app(AccrualService::class)->post(Accrual::where('invoice_id', $inv->id)->orderBy('period_id')->first(), $this->fin);
        $a = $this->avail();
        $this->assertSame(bcadd($total, '0', 2), bcadd($a['committed'], $a['actual'], 2), 'posting a release moves money from committed to actual, never drops it');
    }

    /** FIN-03.06 (D-H3): a posted accrual is not counted twice (as actual and as PO commitment). */
    public function test_d_h3_posted_accrual_is_not_double_counted(): void
    {
        $po = $this->po();
        $this->grn($po);
        $svc = app(AccrualService::class);
        $march = Period::where('fiscal_year_id', $this->fy->id)->where('sequence', 3)->firstOrFail();
        $svc->propose($march, $this->fin);
        $before = $this->avail();
        $svc->post(Accrual::sole(), $this->fin);
        $after = $this->avail();
        $this->assertSame(bcadd($before['committed'], $before['actual'], 2), bcadd($after['committed'], $after['actual'], 2));
        $this->assertSame('1000000.00', $after['actual']);
        $this->assertSame('0.00', $after['committed']);
    }

    /** FIN-01.01 (D-H4): locked-period actuals cannot be matched, unmatched or acknowledged, and runQueue skips them. */
    public function test_d_h4_locked_period_actuals_are_frozen(): void
    {
        $svc = app(ActualsService::class);
        $svc->importGl($this->glCsv([['2027-01-31', '6202', 'CC-1A', '120000.00', 'Maintenance', 'GL-L1']]), $this->glMapping(), $this->fin);
        $a = Actual::sole();
        $a = $svc->unmatch($a, $this->fin);
        Period::whereKey($a->period_id)->update(['status' => 'locked']);
        $this->assertRefused(fn () => $svc->match($a->refresh(), $this->line, $this->fin));
        $this->assertRefused(fn () => $svc->acknowledgeUnbudgeted($a->refresh(), $this->fin, 'Known one-off'));
        $this->assertSame(0, app(ReconciliationService::class)->runQueue($this->org->id, $this->fin));
        $this->assertSame('unmatched', $a->refresh()->match_status);
        Period::whereKey($a->period_id)->update(['status' => 'open']);
        $this->assertRefused(fn () => $svc->match(tap($a->refresh(), fn ($x) => $x->forceFill(['source' => 'invoice'])->save()), $this->line, $this->fin));
    }
}
