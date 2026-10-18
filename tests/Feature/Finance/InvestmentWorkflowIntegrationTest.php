<?php

namespace Tests\Feature\Finance;

use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Finance\Benefit;
use App\Models\Finance\Investment;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
use App\Models\Organization;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Notifications\Finance\FinanceApprovalRequestedNotification;
use App\Notifications\Finance\ValueReviewDueNotification;
use App\Services\Finance\FinanceCalendarService;
use App\Services\Finance\FinanceMasking;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\Reports\FinanceReports;
use App\Services\Finance\Reports\ReportExporter;
use App\Services\Finance\StageGateService;
use App\Services\Finance\ValueReviewService;
use App\Services\Finance\ValueReviewSweeper;
use App\Services\Reports\BoardPackBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/** P5 WP-B wiring: the business-case gate through Workflow Studio, the value-review sweep, calendar, dashboard, board section and reports. */
class InvestmentWorkflowIntegrationTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    private User $steering;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
        $this->steering = $this->cio;
        $this->steering->assignRole('IT Steering Committee');
    }

    private function configure(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
    }

    /** Submits the `approved` gate of a business case costing $cost. */
    private function submitted(string $cost): StageGate
    {
        $i = $this->makeInvestment();
        $svc = app(StageGateService::class);
        $svc->advance($i, 'business_case', ['problem_agreed'], [], null, $this->fin);
        $this->makeBusinessCase($i->refresh(), [['year' => 0, 'capex' => $cost, 'opex' => '0', 'run' => '0']]);
        $this->scoreAll($i);

        return $svc->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin);
    }

    private function mine(User $u, StageGate $g): int
    {
        $u->givePermissionTo('view workflows');

        return collect($this->actingAs($u)->get(route('workflows.inbox'))->viewData('page')['props']['items'])
            ->where('process', 'finance.business_case_approval')->where('mine', true)->filter(fn ($i) => str_ends_with((string) $i['url'], '/stage-gates/'.$g->id))->count();
    }

    private function refused(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a refusal.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_default_stages_seed_four_stages_with_conditions(): void
    {
        $this->configure();
        $wf = ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', 'finance.business_case_approval')->with('stages')->firstOrFail();
        $this->assertSame(['Head of IT', 'Chief Financial Officer', 'IT Steering Committee', 'Managing Director'], $wf->stages->pluck('approver_ref')->all());
        $this->assertSame([null, 'requires_cfo', null, 'requires_board'], $wf->stages->pluck('condition_field')->all());
    }

    public function test_large_business_case_runs_head_cfo_committee_board_in_the_inbox(): void
    {
        $this->configure();
        Notification::fake();
        $g = $this->submitted('300000000');
        $this->assertSame('yes', $g->refresh()->requires_board);
        Notification::assertSentTo($this->headofit, FinanceApprovalRequestedNotification::class);
        $this->assertSame(1, $this->mine($this->headofit, $g));
        $this->assertSame(0, $this->mine($this->cfo, $g));

        app(StageGateService::class)->approve($g, $this->headofit);
        $this->assertSame(1, $this->mine($this->cfo, $g));
        app(StageGateService::class)->approve($g->refresh(), $this->cfo);
        $this->assertSame(1, $this->mine($this->steering, $g));
        app(StageGateService::class)->approve($g->refresh(), $this->steering);
        $this->assertSame('submitted', $g->refresh()->status);
        $this->assertSame(1, $this->mine($this->md, $g));
        app(StageGateService::class)->approve($g->refresh(), $this->md);
        $this->assertNotSame('submitted', $g->refresh()->status);
        $this->assertSame('approved', $g->investment->refresh()->stage);
    }

    public function test_small_business_case_skips_board_and_delivered_gate_skips_cfo_and_board(): void
    {
        $this->configure();
        $g = $this->submitted('1000000');
        $this->assertSame(['no', 'yes'], [$g->requires_board, $g->requires_cfo]);
        $svc = app(StageGateService::class);
        $svc->approve($g, $this->headofit);
        $svc->approve($g->refresh(), $this->cfo);
        $svc->approve($g->refresh(), $this->steering);
        $this->assertSame('approved', $g->investment->refresh()->stage);

        $i = $this->inDelivery($g->investment->refresh());
        $this->assertSame('in_delivery', $i->stage);
        $d = $svc->advance($i, 'delivered', ['acceptance', 'handover'], ['actual_ends_on' => '2027-04-01'], null, $this->fin);
        $this->assertSame(['delivered', 'no', 'no'], [$d->gate, $d->requires_cfo, $d->requires_board]);
        $this->assertSame(1, $this->mine($this->headofit, $d));
        $this->assertSame(0, $this->mine($this->cfo, $d));
        $svc->approve($d, $this->headofit);
        $svc->approve($d->refresh(), $this->steering);
        $this->assertSame('delivered', $d->investment->refresh()->stage);
        $this->assertSame(3, ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->count());
    }

    public function test_segregation_of_duties_and_one_stage_per_person(): void
    {
        $this->configure();
        $g = $this->submitted('1000000');
        $i = $g->investment;
        foreach ([$this->sponsor, $this->manager, $this->fin] as $u) { // sponsor, manager, requester/preparer
            $u->givePermissionTo('approve it-finance-investments');
            $this->assertContains($u->id, $g->refresh()->sodExcludedIds());
            $this->refused(fn () => app(StageGateService::class)->approve($g->refresh(), $u));
        }
        $this->assertSame('submitted', $g->refresh()->status);
        app(StageGateService::class)->approve($g, $this->headofit);
        $this->refused(fn () => app(StageGateService::class)->approve($g->refresh(), $this->headofit)); // already decided a stage
        $this->refused(fn () => app(StageGateService::class)->approve($g->refresh(), $this->md)); // CFO stage is next
        $this->assertSame($i->id, $g->investment_id);
    }

    public function test_unconfigured_bank_needs_one_non_excluded_approver(): void
    {
        ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', 'finance.business_case_approval')->delete();
        $g = $this->submitted('1000000');
        $this->refused(fn () => app(StageGateService::class)->approve($g, $this->fin));
        app(StageGateService::class)->approve($g->refresh(), $this->headofit);
        $this->assertSame('approved', $g->investment->refresh()->stage);
    }

    private function review(string $due, ?User $reviewer = null, string $status = 'scheduled'): ValueReview
    {
        $i = $this->approvedInvestment();

        return ValueReview::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'investment_id' => $i->id, 'review_type' => 'pir_3m', 'due_on' => $due,
            'reviewer_id' => ($reviewer ?? $this->manager)->id, 'status' => $status,
        ]);
    }

    public function test_value_review_sweep_sends_once_on_due_and_once_per_overdue_offset_and_rearms_on_reschedule(): void
    {
        Notification::fake();
        $r = $this->review('2027-04-15');
        $sweeper = app(ValueReviewSweeper::class);

        Carbon::setTestNow('2027-04-05 08:00:00'); // 10 days ahead: inside the 14 day lead
        $sweeper->sweep();
        $sweeper->sweep();
        $this->assertSame('due', $r->refresh()->status);
        foreach ([$this->manager, $this->sponsor] as $u) {
            $this->assertSame(1, Notification::sent($u, ValueReviewDueNotification::class)->count());
        }
        $this->assertSame(0, Notification::sent($this->fin, ValueReviewDueNotification::class)->count());

        foreach (['2027-04-16' => 1, '2027-04-22' => 2, '2027-04-30' => 3, '2027-05-15' => 4] as $day => $expected) {
            Carbon::setTestNow("{$day} 08:00:00");
            $sweeper->sweep();
            $sweeper->sweep();
            $this->assertSame($expected + 1, Notification::sent($this->manager, ValueReviewDueNotification::class)->count(), $day);
        }
        $this->assertSame('overdue', $r->refresh()->status);
        $kinds = Notification::sent($this->manager, ValueReviewDueNotification::class)->pluck('kind')->all();
        $this->assertSame(['due', 'overdue', 'overdue', 'overdue', 'overdue'], $kinds);

        // rescheduling the review re-arms the series
        app(ValueReviewService::class)->update($r, ['due_on' => '2027-06-20', 'reason' => 'Go-live slipped by five weeks.'], $this->fin);
        Carbon::setTestNow('2027-06-10 08:00:00');
        $sweeper->sweep();
        $this->assertSame(6, Notification::sent($this->manager, ValueReviewDueNotification::class)->count());
        $n = Notification::sent($this->manager, ValueReviewDueNotification::class)->last();
        $this->assertSame('finance.value_review_due', (new ValueReviewDueNotification('IVT-1', '3-month review', '20 Jun 2027', 'due', 0, '/x'))->toArray($this->manager)['type']);
        $this->assertStringContainsString('/investments/', ($n->toArray($this->manager)['action_url']));
    }

    public function test_sweep_skips_recipients_without_finance_access_and_closed_investments(): void
    {
        Notification::fake();
        $outsider = User::factory()->create(['organization_id' => $this->org->id]);
        $r = $this->review('2027-04-10', $outsider);
        Carbon::setTestNow('2027-04-05 08:00:00');
        app(ValueReviewSweeper::class)->sweep();
        $this->assertSame(0, Notification::sent($outsider, ValueReviewDueNotification::class)->count());
        $this->assertSame(1, Notification::sent($this->sponsor, ValueReviewDueNotification::class)->count());

        $r2 = $this->review('2027-04-10');
        $r2->investment->forceFill(['stage' => 'closed'])->save();
        app(ValueReviewSweeper::class)->sweep();
        $this->assertSame(0, Notification::sent($this->manager, ValueReviewDueNotification::class)->count(), 'the closed investment sends nothing');
        $this->assertSame(1, Notification::sent($this->sponsor, ValueReviewDueNotification::class)->where('investment', $r->investment->investment_code)->count());
        $this->assertSame(0, Notification::sent($this->sponsor, ValueReviewDueNotification::class)->where('investment', $r2->investment->investment_code)->count());
    }

    public function test_value_review_notification_renders_mail_and_database_without_amounts(): void
    {
        $n = new ValueReviewDueNotification('IVT-0001', '3-month review', '15 Apr 2027', 'overdue', 7, 'https://x.test/y');
        $this->assertContains('database', $n->via($this->manager));
        $this->assertContains('mail', $n->via($this->manager));
        $mail = $n->toMail($this->manager);
        $this->assertStringContainsString('overdue by 7 day', $mail->subject);
        $data = $n->toArray($this->manager);
        $this->assertSame('IT Financial Management', $data['module']);
        $this->assertSame('https://x.test/y', $data['action_url'] ?? $data['actionUrl']);
        $this->assertSame('finance.value_review_overdue', $data['type']);
    }

    public function test_calendar_returns_investment_events_passed_investments_and_ics(): void
    {
        $i = $this->approvedInvestment(['starts_on' => '2027-02-01', 'planned_ends_on' => '2027-03-20']); // late: approved/in_delivery past its planned end
        $r = ValueReview::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'investment_id' => $i->id, 'review_type' => 'pir_6m',
            'due_on' => '2027-04-20', 'reviewer_id' => $this->manager->id, 'status' => 'scheduled']);
        $cal = app(FinanceCalendarService::class);

        $events = collect($cal->events($this->org->id, Carbon::parse('2027-03-01'), Carbon::parse('2027-04-30'), [], $this->fin));
        $types = $events->pluck('type')->unique()->all();
        $this->assertContains('investment_planned_end', $types);
        $this->assertContains('value_review_due', $types);
        $this->assertContains('investment_gate_passed', $types);
        $this->assertSame('red', $events->firstWhere('type', 'investment_planned_end')['color']);
        $this->assertSame('amber', $events->firstWhere('type', 'value_review_due')['color']);

        $only = $cal->events($this->org->id, Carbon::parse('2027-03-01'), Carbon::parse('2027-04-30'), ['sources' => ['investments']], $this->fin);
        $this->assertNotEmpty($only);
        $this->assertSame(['investments'], collect($only)->pluck('meta.source')->unique()->all());
        $mine = $cal->events($this->org->id, Carbon::parse('2027-03-01'), Carbon::parse('2027-04-30'), ['member' => 'me'], $this->requester);
        $this->assertEmpty(collect($mine)->where('meta.source', 'investments')->all());

        $passed = $cal->passedInvestments($this->org->id, $this->fin);
        $this->assertSame(['planned_end'], collect($passed)->pluck('which')->unique()->all());
        $this->assertSame(['id', 'investment_code', 'title', 'stage', 'manager', 'which', 'date', 'days_overdue', 'href'], array_keys($passed[0]));
        $this->assertSame(16, $passed[0]['days_overdue']);

        $this->assertStringContainsString('Value review due', $cal->ics($this->fin));
        $this->assertStringContainsString($i->investment_code, $cal->ics($this->fin));
        $r->forceFill(['status' => 'overdue', 'due_on' => '2027-04-01'])->save();
        $this->assertContains('value_review', collect($cal->passedInvestments($this->org->id, $this->fin))->pluck('which')->all());

        $page = $this->actingAs($this->fin)->get(route('finance.calendar'))->assertOk()->viewData('page')['props'];
        $this->assertArrayHasKey('passedInvestments', $page);
    }

    public function test_dashboard_metrics_and_board_section_are_filled_and_masked(): void
    {
        $i = $this->approvedInvestment();
        Benefit::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'investment_id' => $i->id, 'benefit_code' => 'BEN-0001',
            'title' => 'Fewer branch visits', 'type' => 'cost_saving', 'owner_id' => $this->manager->id, 'target_value' => '500000', 'baseline_value' => '0', 'unit' => 'currency',
            'aggregation' => 'sum', 'currency' => 'NGN', 'status' => 'planned']);
        $m = app(FinanceMetrics::class);
        $s = $m->summary($this->org->id, $this->fy->id);
        $this->assertSame('approved', $s['investments_by_stage'][0]['stage']);
        $this->assertSame(1, $s['investments_by_stage'][0]['count']);

        $b = $m->boardInvestmentFigures($this->org->id);
        $this->assertSame(1, $b['count_open']);
        $this->assertSame('NGN', $b['currency']);
        $this->assertSame('500000.00', $b['benefits_planned']);
        $this->assertSame(['run', 'grow', 'transform'], array_keys($b['rgt_split']));
        $this->assertNull(app(FinanceMetrics::class)->boardInvestmentFigures(Organization::factory()->create()->id));

        $content = [['key' => 'it_investment_portfolio', 'title' => 'x', 'data' => $b]];
        $viewer = $this->userWith('Viewer');
        $masked = app(FinanceMasking::class)->boardContent($content, $viewer)[0]['data'];
        foreach (FinanceMetrics::BOARD_INVESTMENT_MONEY_KEYS as $k) {
            $this->assertNull($masked[$k], $k);
        }
        $this->assertNull($masked['by_stage'][0]['bac']);
        $this->assertSame(1, $masked['count_open']);
        $this->assertSame('NGN', $masked['currency']);
        $this->assertSame('500000.00', app(FinanceMasking::class)->boardContent($content, $this->fin)[0]['data']['benefits_planned']);
        $this->assertSame('IT investment portfolio and value', BoardPackBuilder::SECTIONS['it_investment_portfolio']);
    }

    public function test_both_reports_run_and_export_in_every_format(): void
    {
        $i = $this->approvedInvestment();
        Benefit::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'investment_id' => $i->id, 'benefit_code' => 'BEN-0001',
            'title' => 'Fewer branch visits', 'type' => 'cost_saving', 'owner_id' => $this->manager->id, 'target_value' => '500000', 'baseline_value' => '0', 'unit' => 'currency', 'aggregation' => 'sum', 'status' => 'planned']);
        Benefit::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'investment_id' => $i->id, 'benefit_code' => 'BEN-0002',
            'title' => 'Hours saved', 'type' => 'productivity', 'owner_id' => $this->manager->id, 'target_value' => '800', 'baseline_value' => '0', 'unit' => 'hours', 'aggregation' => 'sum', 'status' => 'planned']);
        $reports = app(FinanceReports::class);
        $ex = new ReportExporter;
        foreach (['investment_portfolio', 'benefits_register'] as $key) {
            $r = $reports->run($key, $this->org->id, [], $this->fin);
            $this->assertNotEmpty($r['rows'], $key);
            $this->assertStringContainsString($i->investment_code, $ex->csv($r, $key));
            $this->assertStringStartsWith('PK', $ex->xlsx($r, $key));
            $this->assertStringStartsWith('%PDF', $ex->pdf($r, $key));
            $this->assertArrayHasKey("finance.{$key}", ScheduledReport::TYPES);
        }
        $this->assertSame($i->bac === null ? null : (string) $i->bac, $reports->run('investment_portfolio', $this->org->id, [], $this->fin)['rows'][0]['bac']);

        $viewer = $this->userWith('Viewer');
        $viewer->givePermissionTo(['view it-finance', 'approve it-finance']);
        $rows = collect($reports->run('benefits_register', $this->org->id, [], $viewer)['rows'])->keyBy('code');
        $this->assertNull($rows['BEN-0001']['target'], 'a currency benefit is money');
        $this->assertSame('800.00', $rows['BEN-0002']['target'], 'hours are not money');
        $this->assertNull($reports->run('investment_portfolio', $this->org->id, [], $viewer)['rows'][0]['bac']);
    }

    /** P5 review H1: a decision that would leave a later applicable stage with nobody able to decide it is refused up front. */
    public function test_decision_that_would_strand_a_later_stage_is_refused(): void
    {
        $this->configure();
        $this->md->assignRole('IT Steering Committee'); // the deadlock the review found: the MD takes stage 3 and is then barred from the board stage
        $g = $this->submitted('300000000');
        $svc = app(StageGateService::class);
        $svc->approve($g, $this->headofit);
        $svc->approve($g->refresh(), $this->cfo);
        $this->assertRefused(fn () => $svc->approve($g->refresh(), $this->md), 'approval', 'without anyone who can still decide');
        $this->assertSame('submitted', $g->refresh()->status);
        $svc->approve($g, $this->steering);
        $svc->approve($g->refresh(), $this->md);
        $this->assertSame('approved', $g->investment->refresh()->stage);
    }

    /** P5 review security H2: the people excluded from deciding are frozen on the gate row when the request is made. */
    public function test_sod_exclusions_are_snapshotted_on_the_gate(): void
    {
        $this->configure();
        $g = $this->submitted('1000000');
        $i = $g->investment;
        $this->assertContains($this->sponsor->id, (array) $g->refresh()->sod_excluded_ids);
        $this->assertRefused(fn () => app(InvestmentService::class)->update($i, ['sponsor_id' => $this->cfo->id], $this->fin), 'sponsor_id', 'awaiting a decision');
        // even a direct reassignment cannot free the old sponsor or manager
        Investment::withoutGlobalScopes()->whereKey($i->id)->update(['sponsor_id' => $this->cfo->id, 'manager_id' => $this->cfo->id]);
        $this->assertContains($this->sponsor->id, $g->refresh()->sodExcludedIds());
        $this->assertContains($this->manager->id, $g->sodExcludedIds());
        $this->assertRefused(fn () => app(StageGateService::class)->approve($g, $this->sponsor));
    }

    /** Unconfigured default path uses the same snapshot. */
    public function test_unconfigured_default_uses_the_snapshot(): void
    {
        ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', 'finance.business_case_approval')->delete();
        $g = $this->submitted('1000000');
        $this->sponsor->givePermissionTo('approve it-finance-investments');
        Investment::withoutGlobalScopes()->whereKey($g->investment_id)->update(['sponsor_id' => $this->cfo->id]);
        $this->refused(fn () => app(StageGateService::class)->approve($g->refresh(), $this->sponsor));
        $this->assertSame('submitted', $g->refresh()->status);
    }
}
