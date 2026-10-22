<?php

namespace Tests\Feature\Finance;

use App\Events\Finance\ImportBatchCompleted;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Finance\Contract;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\Invoice;
use App\Models\User;
use App\Notifications\Finance\FinanceApprovalRequestedNotification;
use App\Notifications\Finance\FinanceDailyDigestNotification;
use App\Notifications\Finance\ImportCompletedNotification;
use App\Notifications\Finance\InvoiceDueNotification;
use App\Notifications\Finance\UnbudgetedSpendNotification;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceCalendarService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceDigest;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\RecurringExpenseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Finance\Concerns\BuildsExpenses;
use Tests\TestCase;

/** P3 WP-B: default stages, owner stage, notifications, invoice sweep, recurring generation, calendar, digest, renewal expense. */
class ExpenseWorkflowIntegrationTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootExpenses();
        FinancePolicySeeder::ensureDefaults($this->org->id);
    }

    /** @return array<int,string> */
    private function steps(object $subject, string $process): array
    {
        $r = ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_type', $subject::class)->where('subject_id', $subject->id)->latest('id')->first();

        return $r->steps()->orderBy('sequence')->pluck('name')->all();
    }

    private function unbudgeted(string $amount): ExpenseRequest
    {
        return $this->expense(['budget_line_id' => null, 'cost_centre_id' => $this->ccChild->id, 'category_id' => $this->cat('CP-SW'), 'amount' => $amount,
            'justification' => 'No budget was foreseen for this purchase.']);
    }

    public function test_defaults_are_seeded_once_with_the_plan_stages(): void
    {
        $this->assertSame(['Cost-centre owner endorsement', 'Head of IT approval', 'CFO approval', 'MD/CEO approval'],
            ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', 'finance.expense_approval')->first()->stages()->orderBy('sequence')->pluck('name')->all());
        $this->assertSame(3, ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', 'finance.invoice_approval')->first()->stages()->count());
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $this->assertSame(2, ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->whereIn('process', ['finance.expense_approval', 'finance.invoice_approval'])->count());
    }

    public function test_expense_routing_follows_the_effective_band(): void
    {
        $svc = app(ExpenseService::class);
        $owner = 'Cost-centre owner endorsement';
        $this->assertSame([$owner], $this->steps($svc->submit($this->expense(['amount' => '900000.00']), $this->requester), 'finance.expense_approval'));
        $this->assertSame([$owner, 'Head of IT approval'], $this->steps($svc->submit($this->expense(['amount' => '5000000.00']), $this->requester), 'finance.expense_approval'));
        $this->assertSame([$owner, 'Head of IT approval', 'CFO approval'], $this->steps($svc->submit($this->unbudgeted('5000000.00'), $this->requester), 'finance.expense_approval'));
        $this->assertSame([$owner, 'Head of IT approval', 'CFO approval', 'MD/CEO approval'], $this->steps($svc->submit($this->unbudgeted('60000000.00'), $this->requester), 'finance.expense_approval'));
    }

    public function test_owner_stage_skips_the_requester_owner_and_the_owner_decides_without_the_permission(): void
    {
        Notification::fake();
        $svc = app(ExpenseService::class);
        $e = $svc->submit($this->expense(['amount' => '900000.00']), $this->requester)->refresh();
        $this->assertSame($this->owner->id, $e->endorser_id); // the requester owns CC-1A, the parent's owner endorses
        $this->assertFalse($this->owner->can('approve it-finance'));
        Notification::assertSentTo($this->owner, FinanceApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->stranger, FinanceApprovalRequestedNotification::class);

        $this->owner->givePermissionTo('view workflows'); // the inbox itself is gated; the owner flag only lifts the process permission
        $this->stranger->givePermissionTo('view workflows');
        $mine = fn (User $u) => collect($this->actingAs($u)->get(route('workflows.inbox'))->viewData('page')['props']['items'])->where('process', 'finance.expense_approval')->where('mine', true)->count();
        $this->assertSame(1, $mine($this->owner));
        $this->assertSame(0, $mine($this->stranger)); // a Budget Owner who is not this endorser cannot decide
        $this->assertStringContainsString('approved', $svc->approve($e, $this->owner));
    }

    public function test_invoice_routing(): void
    {
        $svc = app(InvoiceService::class);
        $none = $svc->submit($this->invoice(), $this->procurement);
        $this->assertSame(['Cost-centre owner confirmation', 'Finance Controller review'], $this->steps($none, 'finance.invoice_approval'));

        $e = app(ExpenseService::class)->submit($this->expense(['amount' => '12000000.00']), $this->requester);
        foreach (['owner', 'headOfIt', 'cfo'] as $who) {
            app(ExpenseService::class)->approve($e->refresh(), $this->approverFor($who));
        }
        $po = $this->po($e->refresh());
        $this->grn($po);
        $big = $svc->submit($this->invoice(['po_id' => $po->id]), $this->procurement);
        $this->assertSame('matched', $big->refresh()->match_status);
        $this->assertSame(['Cost-centre owner confirmation', 'Finance Approver (high value)'], $this->steps($big, 'finance.invoice_approval'));
    }

    public function test_notifications_render_without_amounts(): void
    {
        $u = $this->fin;
        foreach ([
            new UnbudgetedSpendNotification('EXP-1', 'Switch', 'unbudgeted', 'CC-1A', 'Ada', 'https://x.test/e'),
            new InvoiceDueNotification('INV-1', 'Acme', '08 Mar 2027', 7, 'https://x.test/i'),
            new InvoiceDueNotification('INV-1', 'Acme', '22 Feb 2027', -7, 'https://x.test/i'),
            new ImportCompletedNotification('actuals', 'gl.csv', 10, 2, 1, 'https://x.test/b'),
        ] as $n) {
            $this->assertSame(['database', 'mail'], $n->via($u));
            $arr = $n->toArray($u);
            $this->assertSame('IT Financial Management', $arr['module']);
            $this->assertNotEmpty($arr['action_url']);
            $this->assertStringStartsWith('finance.', $arr['type']);
            $this->assertNotEmpty($n->toMail($u)->subject);
            $this->assertDoesNotMatchRegularExpression('/NGN|₦|\$/', $arr['message']);
        }
        $this->assertSame('finance.invoice_overdue', (new InvoiceDueNotification('I', 'V', 'd', -1, 'u'))->toArray($u)['type']);
    }

    public function test_unbudgeted_submit_notifies_finance_and_head_of_it_once(): void
    {
        Notification::fake();
        $svc = app(ExpenseService::class);
        $e = $this->unbudgeted('5000000.00');
        $svc->submit($e, $this->requester);
        $svc->returnToDraft($e->refresh(), $this->owner, 'Add the quote.');
        $svc->submit($e->refresh(), $this->requester);
        Notification::assertSentToTimes($this->fin, UnbudgetedSpendNotification::class, 1);
        Notification::assertSentToTimes($this->head, UnbudgetedSpendNotification::class, 1);
        Notification::assertNotSentTo($this->requester, UnbudgetedSpendNotification::class);
    }

    public function test_import_completed_goes_to_the_importer(): void
    {
        Notification::fake();
        $b = ImportBatch::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'imported_by_id' => $this->fin->id, 'type' => 'actuals',
            'file_name' => 'gl.csv', 'file_sha256' => str_repeat('a', 64), 'row_count' => 3, 'accepted_count' => 2, 'rejected_count' => 1, 'status' => 'completed_with_errors', 'completed_at' => now()]);
        ImportBatchCompleted::dispatch($b);
        Notification::assertSentToTimes($this->fin, ImportCompletedNotification::class, 1);
    }

    private function dueInvoice(string $dueOn, string $status = 'submitted'): Invoice
    {
        $i = app(InvoiceService::class)->submit($this->invoice(), $this->procurement);
        $i->forceFill(['due_on' => $dueOn, 'status' => $status])->save();

        return $i->refresh();
    }

    public function test_invoice_sweep_sends_once_per_offset_and_skips_disputed(): void
    {
        Notification::fake();
        $i = $this->dueInvoice('2027-03-08');
        $disputed = $this->dueInvoice('2027-03-08', 'disputed');
        $this->artisan('fin:invoices-sweep')->assertSuccessful();
        $this->artisan('fin:invoices-sweep')->assertSuccessful();
        Notification::assertSentToTimes($this->fin, InvoiceDueNotification::class, 1); // the disputed one is skipped
        Notification::assertSentToTimes($this->owner, InvoiceDueNotification::class, 0);
        Notification::assertSentToTimes($this->requester, InvoiceDueNotification::class, 1); // owner of CC-1A
        $this->assertSame(0, DB::table('fin_notification_logs')->where('notifiable_id', $disputed->id)->where('notifiable_type', Invoice::class)->count());

        Carbon::setTestNow('2027-03-09 09:00:00'); // one day overdue
        $this->artisan('fin:invoices-sweep')->assertSuccessful();
        $this->artisan('fin:invoices-sweep --date=2027-03-09')->assertSuccessful();
        Notification::assertSentToTimes($this->procurement, InvoiceDueNotification::class, 1); // submitter hears of overdue only
        $this->assertSame(1, DB::table('fin_notification_logs')->where('notifiable_id', $i->id)->where('notifiable_type', Invoice::class)->where('offset_days', -1)->where('sent_to_user_id', $this->fin->id)->count());
        $this->assertTrue(DB::table('fin_notification_logs')->where('notifiable_id', $i->id)->where('offset_days', 7)->exists());
    }

    public function test_recurring_generate_is_idempotent(): void
    {
        $r = app(RecurringExpenseService::class)->save(null, ['title' => 'MTN link', 'vendor_id' => $this->vendor->id, 'cost_centre_id' => $this->ccChild->id, 'budget_line_id' => $this->line->id,
            'frequency' => 'monthly', 'amount' => '200000.00', 'currency' => 'NGN', 'starts_on' => '2027-01-15', 'auto_generate' => 'expected_invoice'], $this->fin);
        $this->artisan('fin:recurring-generate --date=2027-02-20')->expectsOutput('2 generated')->assertSuccessful();
        $this->artisan('fin:recurring-generate --date=2027-02-20')->expectsOutput('0 generated')->assertSuccessful();
        $this->assertSame(2, Invoice::where('recurring_expense_id', $r->id)->count());
    }

    public function test_calendar_and_digest_carry_invoices(): void
    {
        $this->dueInvoice('2027-03-05');
        $this->dueInvoice('2027-02-20');
        $this->dueInvoice('2027-04-20');
        $cal = app(FinanceCalendarService::class);
        $ev = collect($cal->events($this->org->id, Carbon::parse('2027-02-01'), Carbon::parse('2027-04-30'), ['sources' => ['invoices']], $this->fin))->sortBy('start')->values();
        $this->assertSame(['red', 'amber', 'blue'], $ev->pluck('color')->all());
        $this->assertSame(['invoice_due'], $ev->pluck('type')->unique()->all());
        $this->assertStringContainsString('SUMMARY:INV-', $cal->ics($this->fin));

        $this->fin->forceFill(['notification_preferences' => ['finance_daily_digest' => true]])->save();
        Notification::fake();
        $hour = (int) app(FinanceSettings::class)->for($this->org->id)->digest_hour;
        $this->assertSame(1, app(FinanceDigest::class)->send(FinanceClock::now()->setTime($hour, 0)));
        Notification::assertSentTo($this->fin, FinanceDailyDigestNotification::class,
            fn ($n) => collect($n->sections)->contains(fn ($s) => $s['title'] === 'Invoices due and overdue' && str_contains($s['lines'][0], '1 overdue')));
    }

    public function test_an_auto_rolled_renewal_raises_one_draft_expense_request(): void
    {
        Notification::fake();
        $c = $this->contract(['commenced_on' => '2026-03-01', 'ends_on' => '2027-02-28', 'renewal_type' => 'auto', 'auto_renew' => true, 'owner_id' => $this->requester->id,
            'category_id' => $this->cat('CP-SW')]); // a draft with no budget line needs a category (plan P3-4)
        Artisan::call('fin:contracts-sweep');
        Artisan::call('fin:contracts-sweep');
        $successor = Contract::where('renewed_from_id', $c->id)->firstOrFail();
        $drafts = ExpenseRequest::where('contract_id', $successor->id)->get();
        $this->assertCount(1, $drafts);
        $this->assertSame('draft', $drafts->first()->status);
        $this->assertSame($drafts->first()->id, $drafts->first()->renewal->expense_request_id);
    }
}
