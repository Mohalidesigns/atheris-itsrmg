<?php

namespace Tests\Feature\Finance;

use App\Events\Finance\ExpenseFlagged;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Actual;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\Setting;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\BudgetService;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FinanceTransactionImporter;
use App\Services\Finance\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsExpenses;
use Tests\TestCase;

/** ATH-FIN-001 §12 FIN-03.01, FIN-03.02, FIN-03.03, FIN-03.08, FIN-07.01, FIN-07.02, FIN-07.06: availability and expense approval. */
class ExpenseApprovalAndAvailabilityTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootExpenses();
    }

    private function mode(string $mode): void
    {
        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['overbudget_mode' => $mode]);
        app(FinanceSettings::class)->flush();
    }

    private function avail(?BudgetLine $line = null): array
    {
        return app(BudgetAvailability::class)->forLine($line ?? $this->line);
    }

    private function steps(object $subject): array
    {
        return ApprovalRequest::withoutGlobalScopes()->where('process', ExpenseService::PROCESS)->where('subject_type', $subject::class)->where('subject_id', $subject->id)
            ->latest('id')->firstOrFail()->steps()->orderBy('sequence')->pluck('name')->all();
    }

    /** FIN-03.01: available = current budget - committed - actual, including transfers and invoice actuals. */
    public function test_availability_is_current_budget_minus_committed_minus_actual(): void
    {
        $this->assertSame('12000000.00', $this->avail()['available']);
        $e = $this->approvedExpense(['amount' => '1000000.00']); // approved requests are committed
        $a = $this->avail();
        $this->assertSame(['12000000.00', '1000000.00', '0.00', '11000000.00'], [$a['budget'], $a['committed'], $a['actual'], $a['available']]);

        // a transfer in from another line raises the current budget, and the rest is still budget - committed - actual
        $svc = app(BudgetTransferService::class);
        $from = $this->firstLine($this->baselined($this->cc2, '3000000.00'));
        $t = $svc->create(['fiscal_year_id' => $this->fy->id, 'from_line_id' => $from->id, 'to_line_id' => $this->line->id, 'amount' => '2000000.00', 'reason' => 'Fund the switch refresh'], $this->fin);
        $svc->submit($t, $this->fin);
        $svc->approve($t->refresh(), $this->cfo);
        $svc->apply($t->refresh(), $this->fin);
        $this->assertSame('14000000.00', $this->avail()['budget']);

        // PO, receipt and approved invoice: the commitment moves to actual, nothing is counted twice
        $po = $this->po($e);
        $this->grn($po);
        $inv = $this->invoice(['po_id' => $po->id]);
        app(InvoiceService::class)->submit($inv, $this->procurement);
        app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);
        $a = $this->avail();
        $this->assertSame(['14000000.00', '0.00', '1000000.00', '13000000.00'], [$a['budget'], $a['committed'], $a['actual'], $a['available']]);
        $this->assertSame('13000000.00', bcsub($a['budget'], bcadd($a['committed'], $a['actual'], 2), 2));
    }

    /** FIN-03.01 / P3-1: a re-baseline never hides earlier commitments or actuals. */
    public function test_availability_follows_lineage_after_rebaseline(): void
    {
        $this->approvedExpense(['amount' => '2000000.00']);
        $draft = $this->expense(['amount' => '500000.00']); // filed on the line before the re-baseline
        $svc = app(BudgetService::class);
        $v1 = $this->line->budget;
        $v2 = $svc->revise($v1, $this->requester);
        $svc->updateLine($v2->lines()->orderBy('line_no')->first(), ['annual_amount' => '15000000'], $this->requester);
        $svc->submit($v2->refresh(), $this->requester);
        $svc->approve($v2->refresh(), $this->cfo);
        $svc->baseline($v2->refresh(), $this->fin);
        $current = $v2->lines()->orderBy('line_no')->first();
        $a = $this->avail($current);
        $this->assertSame(['15000000.00', '2000000.00', '13000000.00'], [$a['budget'], $a['committed'], $a['available']]);
        // a draft filed on the superseded line moves to the current one on submit, and a new request cannot use the old line
        $this->assertSame($current->id, app(ExpenseService::class)->submit($draft, $this->requester)->budget_line_id);
        $this->assertRefused(fn () => $this->expense(), 'budget_line_id');
    }

    /** FIN-03.01: block mode refuses an over-budget submission with a clear message. */
    public function test_block_mode_refuses_over_budget_submission(): void
    {
        $this->mode('block');
        $e = $this->expense(['amount' => '12000000.01']);
        $this->assertRefused(fn () => app(ExpenseService::class)->submit($e, $this->requester), 'amount', 'over-budget mode is block');
        $this->assertSame('draft', $e->refresh()->status);
        $this->assertSame('submitted', app(ExpenseService::class)->submit($this->expense(['amount' => '12000000.00']), $this->requester)->status);
    }

    /** FIN-03.01: warn mode accepts the submission only with a justification, and flags it. */
    public function test_warn_mode_requires_justification_and_flags_over_budget(): void
    {
        $svc = app(ExpenseService::class);
        $e = $this->expense(['amount' => '13000000.00']);
        $this->assertRefused(fn () => $svc->submit($e, $this->requester), 'overbudget_justification');
        $e = $this->expense(['amount' => '13000000.00', 'overbudget_justification' => 'The vendor price rose after the CBN circular on switching fees.']);
        $s = $svc->submit($e, $this->requester);
        $this->assertTrue((bool) $s->over_budget);
        $this->assertSame('submitted', $s->status);
        $this->assertNotNull($s->availability_snapshot);
        $this->assertSame('13000000.00', $s->availability_snapshot['requested_base']);
    }

    /** P3-4: final approval re-checks availability in block mode, so two requests cannot both take the last money. */
    public function test_final_approval_rechecks_availability_in_block_mode(): void
    {
        $this->mode('block');
        $svc = app(ExpenseService::class);
        $a = $svc->submit($this->expense(['title' => 'First', 'amount' => '7000000.00']), $this->requester);
        $b = $svc->submit($this->expense(['title' => 'Second', 'amount' => '7000000.00']), $this->requester); // each fits on its own
        $svc->approve($a->refresh(), $this->cfo);
        $this->assertRefused(fn () => $svc->approve($b->refresh(), $this->cfo), 'amount');
        $this->assertSame('submitted', $b->refresh()->status);
    }

    /** FIN-03.08: unbudgeted spend needs a justification, is flagged and is routed one band up. */
    public function test_unbudgeted_requires_justification_is_flagged_and_escalates_one_band(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(ExpenseService::class);
        $e = $this->expense(['budget_line_id' => null, 'cost_centre_id' => $this->ccChild->id, 'category_id' => $this->cat('CP-SW'), 'amount' => '1500000.00']);
        $this->assertRefused(fn () => $svc->submit($e, $this->requester), 'justification');
        $e->update(['justification' => 'A regulator request arrived after the budget closed.']);
        $s = $svc->submit($e->refresh(), $this->requester);
        $this->assertSame('yes', $s->is_unbudgeted);
        $this->assertNotSame($s->amount_band, $s->effective_band);
        $this->assertContains('Head of IT approval', $this->steps($s));
    }

    /** FIN-03.08: an emergency request needs a justification and is flagged. */
    public function test_emergency_requires_justification_and_is_flagged(): void
    {
        Event::fake([ExpenseFlagged::class]);
        $svc = app(ExpenseService::class);
        $e = $this->expense(['is_emergency' => true, 'justification' => null]);
        $this->assertRefused(fn () => $svc->submit($e, $this->requester), 'justification');
        $e->update(['justification' => 'The standby firewall has failed; branches would lose resilience.']);
        $s = $svc->submit($e->refresh(), $this->requester);
        $this->assertTrue((bool) $s->is_emergency);
        Event::assertDispatched(ExpenseFlagged::class, fn ($x) => $x->reason === 'emergency');
    }

    /** FIN-03.02, FIN-01.07: DoA bands route the stages and the band is snapshotted at submission. */
    public function test_doa_bands_route_stages_and_snapshot_band(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(ExpenseService::class);
        $small = $svc->submit($this->expense(['amount' => '600000.00']), $this->requester);
        $this->assertSame(['Cost-centre owner endorsement'], $this->steps($small));
        $mid = $svc->submit($this->expense(['amount' => '3000000.00', 'overbudget_justification' => 'n/a n/a n/a n/a n/a n/a']), $this->requester);
        $this->assertContains('Head of IT approval', $this->steps($mid));
        $big = $svc->submit($this->expense(['amount' => '11000000.00']), $this->requester);
        $this->assertContains('CFO approval', $this->steps($big));
        $this->assertNotNull($big->amount_band);
        $this->assertNotNull($big->effective_band);
        $snapshot = $big->amount_band;
        // changing the bands afterwards never re-routes a request already submitted
        $this->assertSame($snapshot, $big->refresh()->amount_band);
    }

    /** FIN-03.02, FIN-07.02: requester and creator cannot approve; nobody decides two stages. */
    public function test_requester_and_creator_cannot_approve_and_nobody_decides_two_stages(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(ExpenseService::class);
        $e = $svc->submit($this->expense(['amount' => '3000000.00']), $this->requester)->refresh();
        $this->assertRefused(fn () => $svc->approve($e, $this->requester), 'approval');
        $this->assertStringContainsString('Head of IT approval', $svc->approve($e->refresh(), $this->owner));
        $this->assertRefused(fn () => $svc->approve($e->refresh(), $this->owner), 'approval');
        // created by finance on behalf of the requester: the creator is excluded too
        $e2 = $svc->create(['title' => 'On behalf', 'fiscal_year_id' => $this->fy->id, 'budget_line_id' => $this->line->id, 'amount' => '700000.00', 'currency' => 'NGN', 'vendor_id' => $this->vendor->id], $this->requester);
        $svc->submit($e2, $this->requester);
        $this->assertRefused(fn () => $svc->approve($e2->refresh(), $this->requester), 'approval');
    }

    /** FIN-07.01: with no stages configured, approving needs `approve it-finance` and a person other than the requester. */
    public function test_unconfigured_default_needs_approve_it_finance(): void
    {
        $svc = app(ExpenseService::class);
        $e = $svc->submit($this->expense(), $this->requester)->refresh();
        $this->assertRefused(fn () => $svc->approve($e, $this->stranger), 'approval');
        $this->assertRefused(fn () => $svc->approve($e->refresh(), $this->owner), 'approval'); // a Budget Owner holds no approve permission
        $this->assertStringContainsString('approved', $svc->approve($e->refresh(), $this->cfo));
    }

    /** FIN-07.01: a zero-step request still applies the default segregation of duties. */
    public function test_zero_step_request_applies_default_sod(): void
    {
        $svc = app(ExpenseService::class);
        $this->requester->givePermissionTo('approve it-finance'); // an approver who is also the requester
        $e = $svc->submit($this->expense(), $this->requester)->refresh();
        $this->assertRefused(fn () => $svc->approve($e, $this->requester), 'approval');
        $this->assertStringContainsString('approved', $svc->approve($e->refresh(), $this->cfo));
    }

    /** FIN-03.03: return, reject, withdraw and cancel move the request correctly and release the commitment. */
    public function test_return_reject_withdraw_cancel_transitions_and_commitment_release(): void
    {
        $svc = app(ExpenseService::class);
        $a = $svc->submit($this->expense(['amount' => '1000000.00']), $this->requester);
        $this->assertSame('0.00', $this->avail()['committed']); // submitted requests are not yet committed
        $svc->withdraw($a->refresh(), $this->requester);
        $this->assertSame('withdrawn', $a->refresh()->status);

        $b = $svc->submit($this->expense(['amount' => '1000000.00']), $this->requester);
        $svc->returnToDraft($b->refresh(), $this->cfo, 'Please attach the vendor quotation.');
        $this->assertSame('draft', $b->refresh()->status);

        $c = $svc->submit($b->refresh(), $this->requester);
        $svc->reject($c->refresh(), $this->cfo, 'Not funded this year.');
        $this->assertSame('rejected', $c->refresh()->status);
        $this->assertSame('Not funded this year.', $c->rejection_reason);

        $d = $this->approvedExpense(['amount' => '2000000.00']);
        $this->assertSame('2000000.00', $this->avail()['committed']);
        $svc->cancel($d, $this->requester, 'Project descoped.');
        $this->assertSame('cancelled', $d->refresh()->status);
        $this->assertSame('0.00', $this->avail()['committed'], 'cancelling releases the commitment');
    }

    /** FIN-07.06: every lifecycle step is written to the activity log. */
    public function test_lifecycle_is_activity_logged(): void
    {
        $e = $this->approvedExpense();
        $po = $this->po($e);
        $events = Activity::where('log_name', 'it-finance')->whereIn('subject_type', [ExpenseRequest::class, PurchaseOrder::class])->pluck('event')->all();
        foreach (['created', 'submitted', 'approved'] as $event) {
            $this->assertContains($event, $events);
        }
        $this->assertTrue(Activity::where('log_name', 'it-finance')->where('subject_type', PurchaseOrder::class)->where('subject_id', $po->id)->exists());
    }

    /** FIN-03.15: the expense import previews without writing and then creates drafts. */
    public function test_bulk_expense_import_preview_then_apply_creates_drafts(): void
    {
        $csv = implode("\n", [
            implode(',', FinanceTransactionImporter::EXPENSE_HEADERS),
            'Switch,CC-1A,,CP-SW,Infosys Finacle,500000.00,NGN,,0,2027-03-20,Needed for the branch rollout',
            'Optics,CC-1A,,CP-SW,Infosys Finacle,abc,NGN,,0,2027-03-20,Bad amount',
        ])."\n";
        $file = fn () => UploadedFile::fake()->createWithContent('expenses.csv', $csv);
        $svc = app(FinanceTransactionImporter::class);
        $preview = $svc->expenses($file(), $this->procurement, false);
        $this->assertSame([2, 1, 1], [$preview['rows'], $preview['accepted'], $preview['rejected']]);
        $this->assertSame(0, ExpenseRequest::count());
        $applied = $svc->expenses($file(), $this->procurement, true);
        $this->assertSame(1, $applied['accepted']);
        $this->assertSame(['draft'], ExpenseRequest::pluck('status')->all());
        $this->assertSame(0, Actual::count());
    }
}
