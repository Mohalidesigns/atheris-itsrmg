<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Notifications\Finance\BudgetVarianceNotification;
use App\Notifications\Finance\CloudAnomalyNotification;
use App\Notifications\Finance\ContractApprovedNotExecutedNotification;
use App\Notifications\Finance\ContractDeadlinePassedNotification;
use App\Notifications\Finance\ContractRenewalDueNotification;
use App\Notifications\Finance\ContractRenewalEscalationNotification;
use App\Notifications\Finance\ContractRenewedNotification;
use App\Notifications\Finance\FinanceApprovalDecisionNotification;
use App\Notifications\Finance\FinanceApprovalOverdueNotification;
use App\Notifications\Finance\FinanceApprovalRequestedNotification;
use App\Notifications\Finance\FinanceDailyDigestNotification;
use App\Notifications\Finance\ImportCompletedNotification;
use App\Notifications\Finance\InvoiceDueNotification;
use App\Notifications\Finance\PeriodCloseReminderNotification;
use App\Notifications\Finance\UnbudgetedSpendNotification;
use App\Notifications\Finance\ValueReviewDueNotification;
use App\Services\Finance\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Finance\Concerns\BuildsExpenses;
use Tests\TestCase;

/**
 * Notification catalogue (ATH-FIN-001 §7, FIN-07.03): every class renders mail and database payloads, carries a
 * `finance.*` type and a link, and shows no money. The approval-request amount is the one exception, and only for
 * recipients holding `view it-finance-amounts` (FIN-07.10).
 */
class FinanceNotificationsTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    private const MONEY = '/(₦|NGN|USD|GBP|EUR)\s?[\d,]{4,}/u';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootExpenses();
    }

    /** @return array<string,object> one instance of every class in app/Notifications/Finance */
    private function catalogue(): array
    {
        $u = 'https://example.test/it-finance';

        return [
            BudgetVarianceNotification::class => new BudgetVarianceNotification('Finacle AMC', 'CC-1', 'Mar 2027', 'red', 'over', $u),
            CloudAnomalyNotification::class => new CloudAnomalyNotification('AWS prod', 'EC2', '2027-03-01', '42%', 'rolling mean', $u),
            ContractApprovedNotExecutedNotification::class => new ContractApprovedNotExecutedNotification('CON-1 Finacle AMC', 'Infosys', 'REN-1', '2027-03-31', 10, $u),
            ContractDeadlinePassedNotification::class => new ContractDeadlinePassedNotification('CON-1 Finacle AMC', 'Infosys', 'notice', '2027-02-01', 28, false, $u),
            ContractRenewalDueNotification::class => new ContractRenewalDueNotification('CON-1 Finacle AMC', 'Infosys', 'renewal', 90, 90, '2027-06-01', $u),
            ContractRenewalEscalationNotification::class => new ContractRenewalEscalationNotification('CON-1 Finacle AMC', 'Infosys', '2027-03-31', 10, 'A. Owner', $u),
            ContractRenewedNotification::class => new ContractRenewedNotification('CON-1 Finacle AMC', 'CON-2 Finacle AMC', 'renewed', '2028-12-31', $u),
            FinanceApprovalDecisionNotification::class => new FinanceApprovalDecisionNotification('finance.expense_approval', 'EXP-1', 'approved', 'The CFO', 'ok', $u),
            FinanceApprovalOverdueNotification::class => new FinanceApprovalOverdueNotification('finance.expense_approval', 'EXP-1', 'CFO approval', '2027-03-01', $u),
            FinanceApprovalRequestedNotification::class => new FinanceApprovalRequestedNotification('finance.expense_approval', 'EXP-1', 'CFO approval', '', $u),
            FinanceDailyDigestNotification::class => new FinanceDailyDigestNotification([['title' => 'Invoices due', 'lines' => ['INV-1 due 2027-03-05']]], $u, '01 Mar 2027'),
            ImportCompletedNotification::class => new ImportCompletedNotification('gl_actuals', 'gl.csv', 10, 1, 2, $u),
            InvoiceDueNotification::class => new InvoiceDueNotification('INV-1', 'Infosys', '2027-03-05', 4, $u),
            PeriodCloseReminderNotification::class => new PeriodCloseReminderNotification('Feb 2027', 'close', 3, [['label' => 'Invoices unmatched', 'count' => 2]], $u),
            UnbudgetedSpendNotification::class => new UnbudgetedSpendNotification('EXP-1', 'Core switch', 'no_budget_line', 'CC-1', 'A. Requester', $u),
            ValueReviewDueNotification::class => new ValueReviewDueNotification('Core upgrade', '6-month', '2027-03-10', 'due', 0, $u),
        ];
    }

    /** FIN-07.03: the catalogue covers every class on disk. */
    public function test_catalogue_lists_every_notification_class(): void
    {
        $onDisk = collect(glob(app_path('Notifications/Finance/*.php')))->map(fn ($f) => 'App\\Notifications\\Finance\\'.basename($f, '.php'))->sort()->values()->all();
        $this->assertCount(16, $onDisk);
        $this->assertSame($onDisk, collect(array_keys($this->catalogue()))->sort()->values()->all());
    }

    /** FIN-07.03, FIN-07.10: mail and database payloads render for every class, with a link and no money. */
    public function test_every_notification_renders_mail_and_database_payloads_without_amounts(): void
    {
        foreach ($this->catalogue() as $class => $n) {
            $this->assertSame(['database', 'mail'], $n->via($this->fin), $class);
            $mail = $n->toMail($this->fin);
            $this->assertInstanceOf(MailMessage::class, $mail, $class);
            $this->assertNotSame('', (string) $mail->subject, $class);
            $this->assertSame('https://example.test/it-finance', $mail->actionUrl, $class);

            $data = $n->toArray($this->fin);
            $this->assertStringStartsWith('finance.', $data['type'], $class);
            $this->assertSame('IT Financial Management', $data['module'], $class);
            $this->assertNotSame('', $data['message'], $class);
            $this->assertSame('https://example.test/it-finance', $data['action_url'], $class);

            $text = $mail->subject.' '.implode(' ', $mail->introLines).' '.json_encode($data, JSON_UNESCAPED_UNICODE);
            $this->assertDoesNotMatchRegularExpression(self::MONEY, $text, "{$class} leaks an amount");
        }
    }

    /** FIN-07.03: each class declares a distinct type. */
    public function test_types_are_unique(): void
    {
        $types = collect($this->catalogue())->map(fn ($n) => $n->toArray($this->fin)['type']);
        $this->assertSame($types->count(), $types->unique()->count());
    }

    /** FIN-07.03, FIN-07.10: the request names the amount only to a recipient holding `view it-finance-amounts`. */
    public function test_approval_requested_shows_the_amount_only_with_the_permission(): void
    {
        $blind = User::factory()->create(['organization_id' => $this->org->id]);
        $blind->givePermissionTo(['view it-finance', 'approve it-finance']);
        $this->assertFalse($blind->can('view it-finance-amounts'));

        Notification::fake();
        $svc = app(ExpenseService::class);
        $svc->submit($this->expense(['amount' => '2500000.00']), $this->requester);

        $sentTo = fn (User $u) => Notification::sent($u, FinanceApprovalRequestedNotification::class);
        $this->assertCount(1, $sentTo($blind));
        $this->assertCount(1, $sentTo($this->cfo));
        $this->assertDoesNotMatchRegularExpression(self::MONEY, $sentTo($blind)->first()->toArray($blind)['message']);
        $this->assertMatchesRegularExpression('/NGN\s?2,500,000/', $sentTo($this->cfo)->first()->toArray($this->cfo)['message']);
        Notification::assertNotSentTo($this->requester, FinanceApprovalRequestedNotification::class);
    }
}
