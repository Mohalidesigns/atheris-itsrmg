<?php

namespace Tests\Feature\Finance;

use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\VarianceNote;
use App\Models\Kri;
use App\Models\KriReading;
use App\Models\Organization;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
use App\Notifications\Finance\BudgetVarianceNotification;
use App\Notifications\Finance\FinanceApprovalRequestedNotification;
use App\Notifications\Finance\PeriodCloseReminderNotification;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\PeriodCloseReminderSweeper;
use App\Services\Finance\PeriodCloseService;
use App\Services\Finance\Reports\FinanceReports;
use App\Services\Finance\VarianceService;
use App\Services\Reports\BoardPackBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/** WP-C wiring of P4: reopen through Workflow Studio, variance and close notifications, reminders, KRIs, board pack and scheduled reports. */
class MonitoringWorkflowIntegrationTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
    }

    /** Line 0 is +20 % year to date at March (red); line 1 is +6.7 % (amber). */
    private function breach(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        $this->postActual($this->monLines[1], $this->periodSeq(1), '1100000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(2), '1100000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(3), '1000000.00');
    }

    private function sentFor(User $u, string $class, ?string $needle = null): int
    {
        return Notification::sent($u, $class)->filter(fn ($n) => $needle === null || str_contains($n->line ?? $n->period, $needle))->count();
    }

    public function test_reopen_through_workflow_studio(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $p = $this->periodSeq(1);
        $p->forceFill(['status' => 'closed'])->save();
        Notification::fake();
        $svc = app(PeriodCloseService::class);
        $c = $svc->requestReopen($p, 'The January accrual was posted to the wrong cost centre.', $this->fin);

        $this->assertSame('submitted', $c->reopen_status);
        Notification::assertSentTo($this->cfo, FinanceApprovalRequestedNotification::class);
        $this->cfo->givePermissionTo('view workflows');
        $this->fin->givePermissionTo('view workflows');
        $items = fn (User $u) => collect($this->actingAs($u)->get(route('workflows.inbox'))->viewData('page')['props']['items'])->where('process', 'finance.period_reopen')->where('mine', true)->count();
        $this->assertSame(1, $items($this->cfo));
        $this->assertSame(0, $items($this->fin));

        // segregation of duties: the requester and a non-CFO cannot decide; the CFO can
        foreach ([$this->fin, $this->head] as $wrong) {
            try {
                $svc->approveReopen($c->refresh(), $wrong);
                $this->fail('Only the CFO stage may approve.');
            } catch (ValidationException) {
                $this->assertSame('closed', $p->refresh()->status);
            }
        }
        $svc->approveReopen($c->refresh(), $this->cfo);
        $this->assertSame('open', $p->refresh()->status);
        $this->assertSame('approved', PeriodClose::withoutGlobalScopes()->find($c->id)->reopen_status);
    }

    public function test_variance_listener_sends_once_and_escalation_sends_red_once(): void
    {
        Notification::fake();
        $this->breach();
        $vs = app(VarianceService::class);
        $p3 = $this->periodSeq(3);
        $vs->evaluate($p3, $this->fin);
        $vs->evaluate($p3, $this->fin);

        $this->assertSame(1, $this->sentFor($this->requester, BudgetVarianceNotification::class, 'Core banking AMC'));
        $this->assertSame(1, $this->sentFor($this->fin, BudgetVarianceNotification::class, 'Core banking AMC'));
        $this->assertSame(1, $this->sentFor($this->requester, BudgetVarianceNotification::class, 'Network support'));
        $this->assertSame(0, $this->sentFor($this->stranger, BudgetVarianceNotification::class));

        // line 1 escalates from amber to red: red goes out once, a repeat evaluation sends nothing more
        $this->postActual($this->monLines[1], $p3, '1000000.00');
        $vs->evaluate($p3, $this->fin);
        $vs->evaluate($p3, $this->fin);
        $net = Notification::sent($this->requester, BudgetVarianceNotification::class)->filter(fn ($n) => $n->line === 'Network support');
        $this->assertSame(['amber', 'red'], $net->map(fn ($n) => $n->severity)->values()->all());
    }

    public function test_due_and_overdue_reminders_send_once_skip_weekends_and_stay_silent_after_close(): void
    {
        Notification::fake();
        $sweeper = app(PeriodCloseReminderSweeper::class);

        // March ended on Wednesday 31: two working days have passed by Sunday 4 April, three on Monday 5
        $this->assertSame(6, PeriodCloseReminderSweeper::workingDaysBetween(Carbon::parse('2027-03-31'), Carbon::parse('2027-04-08')));
        $this->periodSeq(2)->forceFill(['status' => 'closed'])->save();
        Carbon::setTestNow('2027-04-04 08:00:00');
        $sweeper->sweep();
        $this->assertSame(0, $this->sentFor($this->fin, PeriodCloseReminderNotification::class, 'Mar'));
        $this->assertSame(1, $this->sentFor($this->fin, PeriodCloseReminderNotification::class, 'Jan'), 'January is long overdue');
        Carbon::setTestNow('2027-04-05 08:00:00');
        $sweeper->sweep();
        $sweeper->sweep();
        $this->assertSame(1, $this->sentFor($this->fin, PeriodCloseReminderNotification::class, 'Mar'));
        $this->assertSame(1, $this->sentFor($this->fin, PeriodCloseReminderNotification::class, 'Jan'));
        $this->assertSame(0, $this->sentFor($this->fin, PeriodCloseReminderNotification::class, 'Feb'), 'a closed period gets no reminder');

        // Friday 23 April: April ends on Friday 30, five working days away
        Carbon::setTestNow('2027-04-23 08:00:00');
        $this->assertGreaterThan(0, $sweeper->sweep());
        $this->assertSame(1, $this->sentFor($this->fin, PeriodCloseReminderNotification::class, 'Apr'));
        $this->assertSame(1, $this->sentFor($this->requester, PeriodCloseReminderNotification::class, 'Apr')); // cost-centre owner
        Carbon::setTestNow('2027-04-26 08:00:00');
        $sweeper->sweep();
        $sweeper->sweep();
        $this->assertSame(1, $this->sentFor($this->fin, PeriodCloseReminderNotification::class, 'Apr'));
        $this->assertSame(0, $this->sentFor($this->stranger, PeriodCloseReminderNotification::class));

        // the command is wired to the same sweeper
        $this->artisan('fin:period-reminders', ['--date' => '2027-04-05'])->assertSuccessful();
        $this->assertSame(1, $this->sentFor($this->fin, PeriodCloseReminderNotification::class, 'Mar'));
    }

    public function test_blocked_close_notifies_once_per_day(): void
    {
        Notification::fake();
        $p = $this->periodSeq(4); // has not ended yet
        $try = function () use ($p) {
            try {
                app(PeriodCloseService::class)->close($p, $this->fin);
                $this->fail('The close must be refused.');
            } catch (ValidationException) {
            }
        };
        $try();
        $try();
        $this->assertSame(1, $this->sentFor($this->fin, PeriodCloseReminderNotification::class));
        $this->assertSame('finance.period_close_blocked', Notification::sent($this->fin, PeriodCloseReminderNotification::class)->first()->toArray($this->fin)['type']);
        Carbon::setTestNow('2027-04-06 09:00:00');
        $try();
        $this->assertSame(2, $this->sentFor($this->fin, PeriodCloseReminderNotification::class));
    }

    public function test_notifications_render(): void
    {
        $v = new BudgetVarianceNotification('Core banking AMC', 'CC-1A', 'Mar 2027', 'red', 'over', 'https://x.test/v');
        $r = new PeriodCloseReminderNotification('FY2027 Mar 2027', 'overdue', 3, [['label' => 'Variances are explained', 'count' => 2]], 'https://x.test/p');
        foreach ([$v, $r] as $n) {
            $this->assertSame(['database', 'mail'], $n->via($this->fin));
            $this->assertNotSame('', (string) $n->toMail($this->fin)->subject);
            $arr = $n->toArray($this->fin);
            $this->assertSame('IT Financial Management', $arr['module']);
            $this->assertStringStartsWith('https://x.test/', $arr['action_url']);
            $this->assertDoesNotMatchRegularExpression('/\d+(\.\d+)?\s?%|NGN/', $arr['message'], 'no amounts or percentages');
        }
        $this->assertSame('finance.budget_variance_red', $v->toArray($this->fin)['type']);
        $this->assertSame('Mar 2027: red overspend on Core banking AMC (CC-1A)', $v->toMail($this->fin)->subject);
        $this->assertSame('finance.period_close_overdue', $r->toArray($this->fin)['type']);
    }

    public function test_kri_measure_records_the_four_finance_kris(): void
    {
        $this->breach();
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $codes = ['KRI-FIN-VAR', 'KRI-FIN-UNB', 'KRI-FIN-INV', 'KRI-FIN-NOT'];
        foreach ($codes as $code) {
            Kri::withoutGlobalScopes()->updateOrCreate(['organization_id' => $this->org->id, 'code' => $code], [
                'name' => $code, 'category' => 'financial', 'threshold_green' => 0, 'threshold_amber' => 5, 'threshold_red' => 20,
                'direction' => 'higher_worse', 'unit' => 'count', 'frequency' => 'monthly', 'source' => 'platform', 'owner_id' => $this->fin->id,
            ]);
        }
        Artisan::call('kri:measure');
        $ids = Kri::withoutGlobalScopes()->whereIn('code', $codes)->pluck('id', 'code');
        $this->assertCount(4, KriReading::withoutGlobalScopes()->whereIn('kri_id', $ids->values())->get());
        $this->assertGreaterThan(0, (float) KriReading::withoutGlobalScopes()->where('kri_id', $ids['KRI-FIN-VAR'])->value('value'));
    }

    public function test_board_pack_section_and_masking(): void
    {
        $this->breach();
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $template = BoardPackTemplate::create(['name' => 'Finance pack', 'sections' => ['it_financial_position']]);
        $content = app(BoardPackBuilder::class)->snapshot($template, $this->org->id);
        $this->assertSame('IT financial position', $content[0]['title']);
        $this->assertSame('FY2027', $content[0]['data']['fiscal_year']);
        $run = BoardPackRun::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'template_id' => $template->id, 'period' => '2027-04', 'status' => 'draft', 'content' => $content, 'prepared_by' => $this->fin->id]);

        $viewer = $this->userWith('Viewer');
        $viewer->givePermissionTo('view reports');
        $this->assertFalse($viewer->can('view it-finance-amounts'));
        $this->actingAs($viewer)->get(route('board-packs.show', $run))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('run.content.0.data.budget_total', null)->where('run.content.0.data.actual_ytd', null)->where('run.content.0.data.variance_pct', null)
            ->where('run.content.0.data.fiscal_year', 'FY2027')->where('run.content.0.data.red', 2));

        $this->fin->givePermissionTo('view reports');
        $this->actingAs($this->fin)->get(route('board-packs.show', $run))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('run.content.0.data.budget_total', '36000000.00'));

        // a bank with no fiscal year gets "No data"
        $empty = Organization::factory()->create();
        $this->assertSame([['key' => 'it_financial_position', 'title' => 'IT financial position', 'data' => null]], app(BoardPackBuilder::class)->snapshot($template, $empty->id));
    }

    public function test_finance_schedules_need_export_and_run_due_records_a_summary(): void
    {
        $this->breach();
        $this->assertSame(['budget_book', 'bva', 'variance_commentary', 'commitments', 'invoice_ageing', 'unbudgeted', 'contract_register', 'vendor_spend', 'investment_portfolio', 'benefits_register', 'cloud_spend', 'showback_statement', 'capitalisation_register', 'application_tco'], array_keys(app(FinanceReports::class)->available()));
        foreach (array_keys(FinanceReports::DEFINITIONS) as $k) {
            $this->assertArrayHasKey("finance.{$k}", ScheduledReport::TYPES);
        }

        $noExport = $this->userWith('Viewer');
        $noExport->givePermissionTo(['create reports', 'view reports', 'view it-finance']);
        $body = ['name' => 'BvA', 'report_type' => 'finance.bva', 'frequency' => 'weekly'];
        $this->actingAs($noExport)->post(route('reports.scheduled.store'), $body)->assertSessionHasErrors('report_type');
        $this->post(route('reports.scheduled.store'), ['report_type' => 'finance'] + $body)->assertSessionHasErrors('report_type');
        $this->post(route('reports.scheduled.store'), ['report_type' => 'executive'] + $body)->assertSessionDoesntHaveErrors();

        $this->fin->givePermissionTo(['create reports', 'view reports']);
        $this->actingAs($this->fin)->post(route('reports.scheduled.store'), $body)->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post(route('reports.scheduled.store'), ['report_type' => 'finance', 'name' => 'Pos'] + $body)->assertRedirect()->assertSessionDoesntHaveErrors();
        ScheduledReport::withoutGlobalScopes()->update(['next_run_at' => now()->subHour()]);
        $this->artisan('reports:run-due')->assertSuccessful();

        $this->assertCount(3, ScheduledReportRun::withoutGlobalScopes()->get());
        $runOf = fn (string $type) => ScheduledReportRun::withoutGlobalScopes()->where('scheduled_report_id', ScheduledReport::withoutGlobalScopes()->where('report_type', $type)->value('id'))->first();
        $this->assertSame('bva', $runOf('finance.bva')->summary['report']);
        $this->assertArrayHasKey('rows', $runOf('finance.bva')->summary);
        $this->assertArrayHasKey('fiscal_year', $runOf('finance')->summary);
        $this->assertArrayNotHasKey('budget_total', $runOf('finance')->summary, 'run summaries carry no money');
    }

    public function test_every_registered_report_runs(): void
    {
        $this->breach();
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $reports = app(FinanceReports::class);
        foreach (array_keys($reports->available()) as $key) {
            $r = $reports->run($key, $this->org->id, [], $this->fin);
            $this->assertNotEmpty($r['columns'], $key);
            $this->assertSame(count($r['rows']), $r['row_count'], $key);
        }
        $book = $reports->run('budget_book', $this->org->id, [], $this->fin);
        $this->assertCount(4, $book['rows']); // three lines and one subtotal
        $this->assertSame('36000000.00', $book['totals']['current']);
        $this->assertNotNull(VarianceNote::withoutGlobalScopes()->where('severity', 'red')->first());
        $this->assertGreaterThanOrEqual(2, count($reports->run('variance_commentary', $this->org->id, [], $this->fin)['rows']));
    }

    public function test_contract_commitment_and_ageing_reports_carry_rows(): void
    {
        $reports = app(FinanceReports::class);
        $this->contract(['ends_on' => '2027-05-15', 'title' => 'Switch AMC']); // ends in 40 days
        $this->contract(['ends_on' => '2027-12-31', 'title' => 'Far contract']);
        $reg = $reports->run('contract_register', $this->org->id, ['window' => '60'], $this->fin);
        $this->assertSame(['Switch AMC'], array_column($reg['rows'], 'title'));
        $this->assertCount(2, $reports->run('contract_register', $this->org->id, [], $this->fin)['rows']);

        $po = $this->po();
        $commit = $reports->run('commitments', $this->org->id, [], $this->fin);
        $this->assertContains($po->po_code, array_column($commit['rows'], 'document'));
        $this->assertSame(bcadd((string) $po->base_total_amount, '0', 2), $commit['totals']['remaining']);

        $ageing = $reports->run('invoice_ageing', $this->org->id, ['as_of' => '2027-06-30'], $this->fin);
        $this->assertSame(['vendor', 'invoices', 'current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total'], array_column($ageing['columns'], 'key'));
    }
}
