<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Accrual;
use App\Models\Finance\VarianceNote;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Services\Finance\AccrualService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceKriSource;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\ForecastService;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\PeriodCloseService;
use App\Services\Finance\VarianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/**
 * P4 review fixes (ATH-FIN-001 FIN-04.03, .05, .07, .08, .10, .11 and FIN-07.10). Fixture as MonitoringAndVarianceTest: today is
 * 2027-04-05, January to March have ended, three 12m lines.
 */
class MonitoringReviewRegressionTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
    }

    private function schedule(string $type, ?int $createdBy): ScheduledReport
    {
        return ScheduledReport::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Sched '.$type, 'report_type' => $type, 'frequency' => 'daily',
            'active' => true, 'created_by' => $createdBy, 'next_run_at' => now()->subHour()]);
    }

    private function redNote(): VarianceNote
    {
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);

        return VarianceNote::withoutGlobalScopes()->where('severity', 'red')->firstOrFail();
    }

    /** Sec H1: a scheduled finance run stores no money and runs as the user who scheduled it. */
    public function test_scheduled_finance_runs_store_no_money_and_run_as_the_creator(): void
    {
        $this->redNote();
        $s1 = $this->schedule('finance', $this->fin->id);
        $s2 = $this->schedule('finance.bva', $this->fin->id);
        $this->artisan('reports:run-due')->assertSuccessful();
        foreach ([$s1, $s2] as $s) {
            $run = ScheduledReportRun::withoutGlobalScopes()->where('scheduled_report_id', $s->id)->firstOrFail();
            $this->assertSame('recorded', $run->status);
            foreach (['budget_total', 'budget_ytd', 'actual_ytd', 'forecast_fy', 'totals', 'committed', 'unbudgeted_amount', 'top_variances'] as $k) {
                $this->assertArrayNotHasKey($k, $run->summary, $k);
            }
        }
        $this->assertNotNull(ScheduledReportRun::withoutGlobalScopes()->where('scheduled_report_id', $s2->id)->first()->summary['generated_at']);

        // the creator lost the finance permissions, or is gone: the run is recorded as failed and the schedule still advances (Sec M3)
        $weak = $this->userWith('Viewer');
        $s3 = $this->schedule('finance.bva', $weak->id);
        $s4 = $this->schedule('finance', 987654);
        $this->artisan('reports:run-due')->assertSuccessful();
        foreach ([$s3, $s4] as $s) {
            $this->assertSame(['failed'], ScheduledReportRun::withoutGlobalScopes()->where('scheduled_report_id', $s->id)->pluck('status')->all());
            $this->assertTrue($s->refresh()->next_run_at->isFuture());
        }
    }

    /** Sec L4: finance schedules can be changed only with the finance view and export permissions. */
    public function test_finance_schedules_are_gated_on_update_toggle_and_delete(): void
    {
        $s = $this->schedule('finance', $this->fin->id);
        $u = $this->userWith('Viewer');
        $u->givePermissionTo(['view reports', 'edit reports']);
        $body = ['name' => 'x', 'report_type' => 'executive', 'frequency' => 'weekly'];
        $this->actingAs($u)->put(route('reports.scheduled.update', $s->id), $body)->assertForbidden();
        $this->post(route('reports.scheduled.toggle', $s->id))->assertForbidden();
        $this->delete(route('reports.scheduled.destroy', $s->id))->assertForbidden();
        $this->assertTrue($s->refresh()->active);
        $this->actingAs($this->fin);
        $this->fin->givePermissionTo(['view reports', 'edit reports']);
        $this->post(route('reports.scheduled.toggle', $s->id))->assertRedirect();
        $this->assertFalse($s->refresh()->active);
    }

    /** Sec H2: the variance records of a closed or locked period cannot be changed, and the sweep leaves it alone. */
    public function test_closed_period_variances_are_immutable(): void
    {
        $note = $this->redNote();
        $vs = app(VarianceService::class);
        $p3 = $this->periodSeq(3);
        foreach (['closed', 'locked'] as $status) {
            $p3->forceFill(['status' => $status])->save();
            $this->assertRefused(fn () => $vs->explain($note->refresh(), 'Explained after the period was closed, which must not work.', null, $this->requester), null, 're-open');
            $this->assertRefused(fn () => $vs->acknowledge($note->refresh(), $this->fin), null, 're-open');
            $this->assertRefused(fn () => $vs->comment($note->refresh(), 'A late comment', $this->fin), null, 're-open');
            $this->assertRefused(fn () => $vs->raiseIssue($note->refresh(), $this->fin), null, 're-open');
            $this->assertRefused(fn () => $vs->evaluate($p3->refresh(), $this->fin), null, 're-open');
        }
        $before = $note->refresh()->evaluated_at;
        $vs->evaluateDue();
        $this->assertEquals($before, $note->refresh()->evaluated_at, 'a locked period is not re-evaluated by the sweep');
        $p3->forceFill(['status' => 'closed'])->save();
        $vs->evaluateDue();
        $this->assertEquals($before, $note->refresh()->evaluated_at, 'nor a closed one');

        $p3->forceFill(['status' => 'open'])->save(); // reopened: editable again
        $this->assertSame('explained', $vs->explain($note->refresh(), 'Explained once the period was re-opened by approval.', null, $this->requester)->status);
    }

    /** Dom H2: closing a period that has not ended is refused before anything is evaluated. */
    public function test_close_of_an_unended_period_evaluates_nothing(): void
    {
        Event::fake();
        $p4 = $this->periodSeq(4);
        $this->assertRefused(fn () => app(PeriodCloseService::class)->close($p4, $this->fin), 'checklist', 'Period has ended');
        $this->assertSame(0, VarianceNote::withoutGlobalScopes()->where('period_id', $p4->id)->count());
        $this->assertRefused(fn () => app(VarianceService::class)->evaluate($p4, $this->fin), null, 'not ended');
        $this->assertSame(0, VarianceNote::withoutGlobalScopes()->where('period_id', $p4->id)->count());
        $r = app(VarianceService::class)->evaluate($p4, $this->fin, true); // explicit force: seeders and tests only
        $this->assertGreaterThan(0, $r['created']);
    }

    /** Dom H1: dashboard, KRI and board figures tie to the BvA matrix and the ledger, unmatched postings included. */
    public function test_dashboard_kri_and_board_tie_to_bva_and_ledger(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1000000.00');
        }
        $this->postActual($this->monLines[1], $this->periodSeq(2), '300000.00', ['match_status' => 'unmatched', 'budget_line_id' => null]);
        $this->postActual($this->monLines[2], $this->periodSeq(3), '200000.00', ['match_status' => 'unbudgeted', 'budget_line_id' => null]);
        $m = app(MonitoringService::class)->bva($this->org->id, ['group_by' => 'cost_centre', 'view' => 'ytd'], $this->fin)['matrix'];
        $s = app(FinanceMetrics::class)->summary($this->org->id, $this->fy->id);
        $this->assertSame('3500000.00', $s['actual_ytd']);
        $this->assertSame('500000.00', $s['actual_unallocated']);
        $this->assertSame($m['reconciliation']['ledger_actual'], $s['actual_ytd']);
        $this->assertSame($m['totals']['actual'], $s['actual_ytd']);
        $this->assertSame($s['actual_ytd'], app(FinanceMetrics::class)->boardFigures($this->org->id)['actual_ytd']);
        [$unb] = app(FinanceKriSource::class)->unbudgetedSpendPct($this->org->id);
        $this->assertEqualsWithDelta(200000 / 3500000 * 100, $unb, 0.01);
        [$var] = app(FinanceKriSource::class)->budgetVariancePct($this->org->id);
        $this->assertEqualsWithDelta(abs((float) $s['variance_pct']), $var, 0.001);
        // a cost-centre restricted view never sees the unallocated postings
        $scoped = app(FinanceMetrics::class)->summary($this->org->id, $this->fy->id, [$this->ccChild->id]);
        $this->assertSame('0.00', $scoped['actual_unallocated']);
        // masked viewers get the new key nulled
        $viewer = $this->userWith('Viewer');
        $viewer->givePermissionTo('view it-finance');
        $p = $this->actingAs($viewer)->get(route('finance.dashboard'))->assertOk()->original->getData()['page']['props'];
        $this->assertFalse($p['can']['viewAmounts']);
        $this->assertNull($p['metrics']['actual_unallocated']);
    }

    /** Dom H4: a posted accrual due for reversal blocks the close; cancelling needs a reason, shown as a warning on the checklist. */
    public function test_accrual_reversal_blocks_close_and_cancel_needs_a_reason(): void
    {
        $svc = app(PeriodCloseService::class);
        $p1 = $this->periodSeq(1);
        $p2 = $this->periodSeq(2);
        foreach ([1, 2] as $n) {
            foreach ([0, 1, 2] as $i) {
                $this->postActual($this->monLines[$i], $this->periodSeq($n), '1000000.00');
            }
        }
        $svc->close($p1, $this->fin);
        $base = ['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'fiscal_year_id' => $this->fy->id, 'type' => 'accrual', 'source' => 'po', 'cost_centre_id' => $this->ccChild->id,
            'currency' => 'NGN', 'fx_rate' => 1, 'amount' => '1000.00', 'base_amount' => '1000.00'];
        $a = Accrual::withoutGlobalScopes()->create($base + ['period_id' => $p1->id, 'reversal_period_id' => $p2->id, 'status' => 'posted', 'description' => 'Posted in January']);
        $this->assertSame(1, app(AccrualService::class)->pendingFor($p2)['reversals']);
        $this->assertRefused(fn () => $svc->close($p2, $this->fin), 'checklist', 'awaiting reversal');
        $a->forceFill(['status' => 'reversed'])->save();
        $this->assertSame(0, app(AccrualService::class)->pendingFor($p2)['reversals']);

        $b = Accrual::withoutGlobalScopes()->create($base + ['period_id' => $p2->id, 'status' => 'proposed', 'description' => 'Proposed in February']);
        $this->actingAs($this->fin)->post(route('finance.accruals.cancel', $b->id))->assertSessionHasErrors('reason');
        $this->assertSame('proposed', $b->refresh()->status);
        $this->assertRefused(fn () => app(AccrualService::class)->cancel($b, $this->fin, 'short'), 'reason');
        $this->post(route('finance.accruals.cancel', $b->id), ['reason' => 'Goods were never received.'])->assertSessionDoesntHaveErrors();
        $this->assertSame(['cancelled', 'Goods were never received.'], [$b->refresh()->status, $b->cancel_reason]);
        $this->assertSame('Goods were never received.', Activity::where('subject_type', Accrual::class)->where('subject_id', $b->id)->where('event', 'cancelled')->first()->properties['reason']);
        $item = collect($svc->checklist($p2)['items'])->firstWhere('key', 'accruals_cancelled');
        $this->assertSame(['warn', 1], [$item['status'], $item['count']]);
        $this->assertStringContainsString('Goods were never received.', $item['detail']);
        $this->assertTrue($svc->checklist($p2)['can_close']);
    }

    /** Dom H5: the EAC never sits below booked actuals, and an override below that floor is refused. */
    public function test_eac_is_floored_at_actuals_and_override_below_is_refused(): void
    {
        $line = $this->monLines[1];
        $this->postActual($line, $this->periodSeq(1), '5000000.00');
        $this->postActual($line, $this->periodSeq(8), '9000000.00'); // booked ahead of its period
        $eac = app(ForecastService::class)->eac($line);
        $this->assertSame('14000000.00', $eac['eac_floor']);
        $this->assertTrue(bccomp($eac['eac'], '14000000.00', 2) >= 0);
        $this->assertTrue(bccomp($eac['eac_remaining_budget'], '14000000.00', 2) >= 0);
        $this->assertTrue(bccomp($eac['eac_run_rate'], '14000000.00', 2) >= 0);
        $e = $this->assertRefused(fn () => app(ForecastService::class)->override($line, '13999999.99', 'Trying to sit below the actuals', $this->fin), 'amount');
        $this->assertStringContainsString('14000000.00', $e->errors()['amount'][0]);
        $f = app(ForecastService::class)->override($line, '14000000.00', 'Exactly at the booked actuals', $this->fin);
        $this->assertSame('14000000.00', (string) $f->base_amount);
    }

    /** Sec M1: the dashboard lists only the approvals the viewer may open, decide or started. */
    public function test_pending_approvals_are_scoped_to_the_viewer(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $e = app(ExpenseService::class)->submit($this->expense(), $this->requester);
        $viewer = $this->userWith('Viewer');
        $viewer->givePermissionTo('view it-finance');
        $metrics = app(FinanceMetrics::class);
        $this->assertSame([], $metrics->pendingApprovals($viewer));
        $this->assertCount(1, $metrics->pendingApprovals($this->requester), 'the initiator sees their own');
        $this->assertCount(1, $metrics->pendingApprovals($this->fin));
        $this->assertSame('submitted', $e->refresh()->status);
    }

    /** Sec M4: a nightly re-evaluation with nothing changed writes no activity-log row. */
    public function test_unchanged_evaluation_logs_nothing(): void
    {
        $note = $this->redNote();
        $count = fn () => Activity::where('subject_type', VarianceNote::class)->where('subject_id', $note->id)->count();
        $before = $count();
        $this->travel(1)->hours();
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $this->assertSame($before, $count());
        $this->assertTrue($note->refresh()->evaluated_at->gt(now()->subMinutes(5)), 'evaluated_at is still written');
    }
}
