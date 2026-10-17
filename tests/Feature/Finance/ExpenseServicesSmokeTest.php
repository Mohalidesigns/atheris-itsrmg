<?php

namespace Tests\Feature\Finance;

use App\Events\Finance\ExpenseFlagged;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\Setting;
use App\Services\Finance\AccrualService;
use App\Services\Finance\ActualsService;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\BudgetService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\RecurringExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Finance\Concerns\BuildsExpenses;
use Tests\TestCase;

/** P3 service-level smoke: the rules behind FIN-03.01 to .08 that the page-level smoke does not reach. The full acceptance suite is the QA work package. */
class ExpenseServicesSmokeTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootExpenses();
    }

    public function test_block_mode_refuses_and_warn_mode_flags_over_budget_requests(): void // FIN-03.01
    {
        $svc = app(ExpenseService::class);
        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['overbudget_mode' => 'block']);
        app(FinanceSettings::class)->flush();
        $big = $this->expense(['amount' => '13000000.00']);
        $this->assertRefused(fn () => $svc->submit($big, $this->requester), 'amount', 'block');

        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['overbudget_mode' => 'warn']);
        app(FinanceSettings::class)->flush();
        $this->assertRefused(fn () => $svc->submit($big->refresh(), $this->requester), 'overbudget_justification');
        Event::fake([ExpenseFlagged::class]);
        $big->update(['overbudget_justification' => 'Regulatory deadline forces the full replacement this year.']);
        $s = $svc->submit($big->refresh(), $this->requester);
        $this->assertTrue($s->over_budget);
        Event::assertDispatched(ExpenseFlagged::class, fn ($e) => $e->reason === 'over_budget');
    }

    public function test_unbudgeted_request_needs_justification_and_moves_one_band_up(): void // FIN-03.08
    {
        Event::fake([ExpenseFlagged::class]);
        $svc = app(ExpenseService::class);
        $e = $this->expense(['budget_line_id' => null, 'cost_centre_id' => $this->ccChild->id, 'category_id' => $this->cat('CP-SW'), 'amount' => '5000000.00']);
        $this->assertRefused(fn () => $svc->submit($e, $this->requester), 'justification');
        $e->update(['justification' => 'No budget was foreseen for this replacement.']);
        $s = $svc->submit($e->refresh(), $this->requester);
        $this->assertSame('1m-10m', $s->amount_band);
        $this->assertSame('10m-50m', $s->effective_band);
        $this->assertSame('yes', $s->is_unbudgeted);
        Event::assertDispatched(ExpenseFlagged::class, fn ($x) => $x->reason === 'unbudgeted');
    }

    public function test_owner_stage_walks_up_past_the_requester_and_needs_no_permission(): void // C74, FIN-03.02
    {
        $wf = ApprovalWorkflow::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'process' => ExpenseService::PROCESS, 'is_active' => true]);
        $wf->stages()->create(['organization_id' => $this->org->id, 'sequence' => 10, 'name' => 'Owner', 'approver_type' => 'owner', 'sla_hours' => 48, 'min_approvals' => 1, 'is_active' => true, 'condition_field' => 'has_endorser', 'condition_values' => ['yes']]);
        $wf->stages()->create(['organization_id' => $this->org->id, 'sequence' => 20, 'name' => 'CFO', 'approver_type' => 'job_title', 'approver_ref' => 'Chief Financial Officer', 'sla_hours' => 48, 'min_approvals' => 1, 'is_active' => true]);
        $svc = app(ExpenseService::class);
        $e = $svc->submit($this->expense(), $this->requester)->refresh();
        $this->assertSame($this->owner->id, $e->endorser_id); // the requester owns CC-1A, so the parent's owner endorses
        $this->assertFalse($this->owner->can('approve it-finance'));
        $this->assertRefused(fn () => $svc->approve($e, $this->requester), 'approval');
        $this->assertStringContainsString('Now awaiting "CFO"', $svc->approve($e, $this->owner));
        $this->assertRefused(fn () => $svc->approve($e->refresh(), $this->owner), 'approval'); // nobody decides two stages
        $svc->approve($e->refresh(), $this->cfo);
        $this->assertSame('approved', $e->refresh()->status);
    }

    public function test_variance_blocks_submission_until_overridden_and_adds_to_the_match_detail(): void // FIN-03.04
    {
        $po = $this->po();
        $this->grn($po);
        $inv = $this->invoice(['po_id' => $po->id, 'lines' => [['po_line_id' => $po->lines()->first()->id, 'description' => 'Core switch', 'quantity' => '2', 'unit_cost' => '600000.00']]]);
        $this->assertSame('variance', $inv->match_status);
        $this->assertContains('price_change', $inv->match_detail['lines'][0]['flags']);
        $svc = app(InvoiceService::class);
        $this->assertRefused(fn () => $svc->submit($inv, $this->procurement), 'match_status');
        $this->assertRefused(fn () => $svc->override($inv, 'short', $this->fin), 'reason');
        $svc->override($inv->refresh(), 'Vendor price escalation agreed with the account manager.', $this->fin);
        $this->assertSame('submitted', $svc->submit($inv->refresh(), $this->procurement)->status);
    }

    public function test_final_invoice_approver_cannot_be_a_po_approver_above_the_sod_band(): void // FIN-07.02
    {
        $e = $this->approvedExpense(['amount' => '12000000.00']); // the CFO approved it, so the CFO approved the PO
        $po = $this->po($e);
        $this->grn($po);
        $inv = $this->invoice(['po_id' => $po->id]);
        $svc = app(InvoiceService::class);
        $svc->submit($inv, $this->procurement);
        $this->assertSame('yes', $inv->refresh()->high_value);
        $this->assertRefused(fn () => $svc->approve($inv, $this->cfo), 'approval', 'purchase order');
        $this->assertStringContainsString('approved', $svc->approve($inv->refresh(), $this->md));
        $this->assertSame('paid', $svc->markPaid($svc->schedule($inv->refresh(), '2027-03-20', $this->cfo), '2027-03-01', 'PAY-1', $this->cfo)->status);
        $this->assertRefused(fn () => $svc->markPaid($inv->refresh(), '2027-03-01', 'X', $this->md), 'workflow');
    }

    public function test_gl_import_matches_flags_duplicates_and_is_idempotent_by_file(): void // FIN-03.05, FIN-03.15
    {
        $gl = GlAccount::firstOrCreate(['code' => '6202'], ['name' => 'AMC', 'type' => 'opex']);
        DB::table('fin_budget_lines')->where('id', $this->line->id)->update(['gl_account_id' => $gl->id]);
        $svc = app(ActualsService::class);
        $csv = $this->glCsv([
            ['2027-01-31', '6202', 'CC-1A', '250000.00', 'Finacle AMC', 'GL-1'],
            ['2027-01-31', '9999', 'CC-1A', '10.00', 'Bad GL', 'GL-2'],
            ['2027-01-31', '6202', 'CC-1A', '300000.00', 'AMC again', 'GL-3'],
        ]);
        $preview = $svc->previewGl($csv, $this->glMapping(), $this->org->id);
        $this->assertSame(['rows' => 3, 'accepted' => 2, 'rejected' => 1], array_intersect_key($preview, array_flip(['rows', 'accepted', 'rejected'])));
        $this->assertSame(0, Actual::count());
    }

    public function test_gl_import_apply_with_skip_invalid_and_same_file_refusal(): void // FIN-03.15
    {
        $gl = GlAccount::firstOrCreate(['code' => '6202'], ['name' => 'AMC', 'type' => 'opex']);
        DB::table('fin_budget_lines')->where('id', $this->line->id)->update(['gl_account_id' => $gl->id]);
        $svc = app(ActualsService::class);
        $csv = $this->glCsv([['2027-01-31', '6202', 'CC-1A', '250000.00', 'Finacle AMC', 'GL-1'], ['2027-01-31', '9999', 'CC-1A', '10.00', 'Bad GL', 'GL-2']]);
        $rejected = $svc->importGl($csv, $this->glMapping(), $this->fin);
        $this->assertSame('failed', $rejected->status);
        $this->assertSame(0, Actual::count());
        $batch = $svc->importGl($csv, $this->glMapping(), $this->fin, true);
        $this->assertSame('completed_with_errors', $batch->status);
        $this->assertSame(1, $batch->accepted_count);
        $a = Actual::sole();
        $this->assertSame('matched', $a->match_status);
        $this->assertSame($this->line->id, $a->budget_line_id);
        $this->assertRefused(fn () => $svc->importGl($csv, $this->glMapping(), $this->fin, true), 'file', 'already imported');
    }

    public function test_accrual_posts_and_reverses_and_prepayment_releases_by_period(): void // FIN-03.06
    {
        $po = $this->po();
        $this->grn($po);
        $svc = app(AccrualService::class);
        $feb = Period::where('fiscal_year_id', $this->fy->id)->where('sequence', 3)->first(); // March, the current period
        $this->assertSame(1, $svc->propose($feb, $this->fin));
        $a = Accrual::sole();
        $this->assertSame('1000000.00', (string) $a->base_amount);
        $svc->post($a, $this->fin);
        $this->assertSame('1000000.00', app(BudgetAvailability::class)->forLine($this->line)['actual']);
        $svc->reverse($a->refresh(), $this->fin);
        $this->assertSame('0.00', app(BudgetAvailability::class)->forLine($this->line)['actual']);

        $inv = $this->invoice(['vendor_invoice_no' => 'ANN-1', 'subtotal' => '1200000.00', 'prepayment_months' => 10]);
        $inv = app(InvoiceService::class)->submit($inv, $this->procurement);
        app(InvoiceService::class)->approve($inv, $this->cfo);
        $this->assertSame(0, Actual::where('invoice_id', $inv->id)->count());
        $rows = Accrual::where('invoice_id', $inv->id)->orderBy('period_id')->get();
        $this->assertCount(10, $rows);
        $this->assertSame(0, bccomp('1200000.00', (string) $rows->sum('base_amount'), 2));
    }

    public function test_recurring_expense_generates_one_expected_invoice_per_due_date(): void // FIN-03.07
    {
        $svc = app(RecurringExpenseService::class);
        $r = $svc->save(null, ['title' => 'MTN link', 'vendor_id' => $this->vendor->id, 'cost_centre_id' => $this->ccChild->id, 'budget_line_id' => $this->line->id, 'frequency' => 'monthly',
            'amount' => '200000.00', 'currency' => 'NGN', 'starts_on' => '2027-01-15', 'auto_generate' => 'expected_invoice'], $this->fin);
        $this->assertSame(2, $svc->generateDue(FinanceClock::today(), $this->org->id)); // Jan 15 and Feb 15 (Mar 15 is not yet due)
        $this->assertSame(0, $svc->generateDue(FinanceClock::today(), $this->org->id));
        $this->assertSame(2, Invoice::where('recurring_expense_id', $r->id)->count());
        $this->assertTrue(Invoice::where('recurring_expense_id', $r->id)->first()->is_expected);
        $this->assertSame('2027-03-15', $r->refresh()->next_due_on->toDateString());
    }

    public function test_budget_from_actuals_uses_the_prior_year_and_py_actual_follows(): void // C26
    {
        $inv = $this->invoice(['subtotal' => '400000.00']);
        $svc = app(InvoiceService::class);
        $svc->submit($inv, $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $fy2 = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2028', 'starts_on' => '2028-01-01', 'status' => 'planning'], $this->fin);
        Period::where('fiscal_year_id', $this->fy->id)->update(['status' => 'closed']); // the prior year is complete
        FiscalYear::whereKey($this->fy->id)->update(['status' => 'closed']);
        $cycle = $this->openCycle('annual', $fy2);
        $b = app(BudgetService::class)->createVersion($cycle, $this->ccChild, 'from_actuals', $this->requester);
        $l = $this->firstLine($b);
        $this->assertSame('400000.00', (string) $l->base_annual_amount);
        $this->assertSame('400000.00', (string) $l->py_actual);
    }
}
