<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Forecast;
use App\Models\Finance\Setting;
use App\Models\Finance\VarianceNote;
use App\Models\Issue;
use App\Models\Kri;
use App\Models\KriReading;
use App\Models\User;
use App\Notifications\Finance\BudgetVarianceNotification;
use App\Services\Finance\BudgetService;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\ForecastService;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\PeriodCloseService;
use App\Services\Finance\VarianceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/**
 * ATH-FIN-001 §12 FIN-04.01 to .04, .06, .07, .08, .10 and FIN-07.10: the budget-vs-actual matrix, the estimate at completion,
 * variance classification, the notification sweep, commentary, issues, masking and cost-centre visibility.
 *
 * Fixture (BuildsMonitoring): "today" is 2027-04-05, so January to March have ended; three NGN lines of 12m, 1m a month each.
 */
class MonitoringAndVarianceTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
    }

    private function props($response): array
    {
        return $response->original->getData()['page']['props'];
    }

    /** Spends the budget exactly on lines 0 to 2 for the given periods, so nothing breaches. */
    private function onBudget(array $seqs = [1, 2, 3], array $lines = [0, 1, 2]): void
    {
        foreach ($seqs as $n) {
            foreach ($lines as $i) {
                $this->postActual($this->monLines[$i], $this->periodSeq($n), '1000000.00');
            }
        }
    }

    private function sendsFor(User $u, string $line): array
    {
        return Notification::sent($u, BudgetVarianceNotification::class)->filter(fn ($n) => $n->line === $line)->map(fn ($n) => $n->period.':'.$n->severity)->values()->all();
    }

    /** @return list<array<string,string>> parsed rows (header keys) of a UTF-8 CSV with BOM */
    private function csvRows(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $head = array_shift($lines);

        return array_map(fn ($l) => array_combine($head, $l), $lines);
    }

    /** FIN-04.01, FIN-04.06: the matrix reconciles to the ledger to the cent across FX lines, adjustments, duplicates and unallocated postings, for every grouping. */
    public function test_bva_reconciles_to_the_cent_for_every_grouping(): void
    {
        $usd = $this->firstLine($this->baselined($this->cc2, '10000.00', 'USD')); // a USD line: 14,000,000 at the budget rate
        $this->postActual($this->monLines[0], $this->periodSeq(1), '1200000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(2), '900000.33');
        $this->postActual($usd, $this->periodSeq(2), '1500000.00', ['currency' => 'USD', 'amount' => '1000.00', 'fx_rate' => 1500]);
        $this->postActual($this->monLines[0], $this->periodSeq(2), '500000.00', ['match_status' => 'duplicate']); // excluded
        $this->postActual($this->monLines[0], $this->periodSeq(3), '300000.17', ['match_status' => 'unmatched', 'budget_line_id' => null]); // unallocated
        $this->postActual($this->monLines[2], $this->periodSeq(3), '250000.00', ['match_status' => 'unbudgeted', 'budget_line_id' => null]); // unallocated
        BudgetAdjustment::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'budget_line_id' => $this->monLines[0]->id,
            'period_id' => $this->periodSeq(2)->id, 'source_type' => 'transfer', 'source_id' => 9001, 'amount' => '250000.00', 'base_amount' => '250000.00', 'applied_by_id' => $this->fin->id, 'applied_at' => now()]);

        $expectedActual = '4150000.50'; // 1,200,000.00 + 900,000.33 + 1,500,000.00 + 300,000.17 + 250,000.00
        $mon = app(MonitoringService::class);
        foreach (MonitoringService::GROUP_BY as $group) {
            $m = $mon->bva($this->org->id, ['group_by' => $group, 'view' => 'fy'], $this->fin)['matrix'];
            $this->assertSame('0.00', $m['reconciliation']['difference'], "difference for {$group}");
            $this->assertSame($expectedActual, $m['reconciliation']['ledger_actual'], $group);
            $this->assertSame($expectedActual, $m['totals']['actual'], "totals for {$group}");
            $this->assertSame('550000.17', $m['unallocated']['actual'], "unallocated for {$group}");
            $sum = array_reduce($m['rows'], fn ($c, $r) => bcadd($c, $r['actual'], 2), '0.00');
            $this->assertSame($expectedActual, bcadd($sum, $m['unallocated']['actual'], 2), "rows + unallocated for {$group}");
            $budget = array_reduce($m['rows'], fn ($c, $r) => bcadd($c, $r['budget'], 2), '0.00');
            $this->assertSame($budget, $m['totals']['budget'], "budget total for {$group}");
        }
        // 3 x 12m, one USD line of 10,000 at 1,400, and the 250,000 adjustment
        $this->assertSame('50250000.00', $mon->bva($this->org->id, ['group_by' => 'line', 'view' => 'fy'], $this->fin)['matrix']['totals']['budget']);
        // a period range reconciles too
        $range = $mon->bva($this->org->id, ['group_by' => 'cost_centre', 'view' => 'period', 'from_period_id' => $this->periodSeq(2)->id, 'to_period_id' => $this->periodSeq(2)->id], $this->fin)['matrix'];
        $this->assertSame('0.00', $range['reconciliation']['difference']);
        $this->assertSame('2400000.33', $range['totals']['actual']);
    }

    /** FIN-04.06: cost centre to tower to category to line to transactions, and each level exports a CSV whose total equals the matrix. */
    public function test_drill_path_and_export_totals_match(): void
    {
        $this->postActual($this->monLines[0], $this->periodSeq(1), '1200000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(2), '800000.00');
        $this->postActual($this->monLines[2], $this->periodSeq(3), '700000.00');
        $this->postActual($this->monLines[2], $this->periodSeq(3), '111.00', ['match_status' => 'unmatched', 'budget_line_id' => null]);
        BudgetLine::withoutGlobalScopes()->whereIn('id', collect($this->monLines)->pluck('id'))->update(['category_id' => $this->cat('TW-DC-ENT')]); // lines carry a category to drill through
        $filters = ['group_by' => 'cost_centre'];
        $seen = [];
        for ($level = 0; $level < 6; $level++) {
            $m = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.bva', $filters))->assertOk())['matrix'];
            $seen[] = $m['group_by'];
            $this->assertSame('0.00', $m['reconciliation']['difference']);
            $csv = $this->actingAs($this->fin)->get(route('finance.monitor.bva.export', $filters + ['format' => 'csv']))->assertOk()->getContent();
            $rows = $this->csvRows($csv);
            $total = collect($rows)->last();
            $this->assertSame('Total', $total['Cost centre']);
            // the export is the line report: its total is the matrix minus the unallocated row, which only the all-lines level carries
            $expected = $m['unallocated'] ? bcsub($m['totals']['actual'], $m['unallocated']['actual'], 2) : $m['totals']['actual'];
            $this->assertSame($expected, $total['Actual'], 'export total at '.$m['group_by']);
            $drill = $m['rows'][0]['drill'] ?? null;
            if (! $drill) {
                $this->assertSame('line', $m['group_by']);
                $line = $m['rows'][0]['line_id'];
                $tx = $this->actingAs($this->fin)->getJson(route('finance.monitor.transactions', $line))->assertOk()->json();
                $this->assertSame($m['rows'][0]['actual'], $tx['totals']['actual']);

                break;
            }
            $filters[$drill['param']] = $drill['value'];
            $filters['group_by'] = $drill['next_group_by'];
        }
        $this->assertSame(['cost_centre', 'tower', 'category', 'line'], $seen);
    }

    /** FIN-04.02: EAC by remaining budget and by run-rate against hand-computed values, and the method setting chooses. */
    public function test_eac_remaining_budget_and_run_rate(): void
    {
        $this->postActual($this->monLines[1], $this->periodSeq(1), '800000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(2), '700000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(3), '900000.00'); // 2.4m after three months
        $svc = app(ForecastService::class);
        $e = $svc->eac($this->monLines[1]);
        $this->assertSame('2400000.00', $e['actual_ytd']);
        $this->assertSame('11400000.00', $e['eac_remaining_budget']); // 2.4m + 9 x 1m
        $this->assertSame('9600000.00', $e['eac_run_rate']); // 2.4m / 3 x 12
        $this->assertSame('11400000.00', $e['eac']);
        $this->assertSame('remaining_budget', $e['method']);
        $this->assertSame('-600000.00', $e['variance']);
        $this->assertSame('-5.00', $e['variance_pct']);
        $this->assertSame([3, 12], [$e['periods_elapsed'], $e['periods_total']]);

        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['eac_method' => 'run_rate']);
        app(FinanceSettings::class)->flush();
        $e = $svc->eac($this->monLines[1]);
        $this->assertSame('run_rate', $e['method']);
        $this->assertSame('9600000.00', $e['eac']);
        $this->assertSame('-2400000.00', $e['variance']);
        $this->assertSame('-20.00', $e['variance_pct']);

        // nothing has elapsed: the EAC is the current budget under both methods
        Carbon::setTestNow('2027-01-15 09:00:00');
        $e = $svc->eac($this->monLines[1]);
        $this->assertSame(0, $e['periods_elapsed']);
        $this->assertSame('12000000.00', $e['eac']);
        $this->assertSame('12000000.00', $e['eac_remaining_budget']);
        $this->assertSame('12000000.00', $e['eac_run_rate']);
        $this->assertNull($e['exhaustion_on']);
    }

    /** FIN-04.02: a manual override needs a reason, replaces the computed EAC until cleared, and is refused for a non-baseline line. */
    public function test_manual_override_and_clear(): void
    {
        $this->onBudget();
        $svc = app(ForecastService::class);
        $line = $this->monLines[0];
        $this->assertRefused(fn () => $svc->override($line, '13000000.00', 'short', $this->fin), 'reason');
        $this->assertRefused(fn () => $svc->override($line, '-5', 'Vendor re-quote expected in Q3', $this->fin), 'amount');
        $f = $svc->override($line, '13000000.00', 'Vendor re-quote expected in Q3', $this->fin);
        $this->assertSame('manual', $f->method);
        $e = $svc->eac($line);
        $this->assertSame('manual', $e['method']);
        $this->assertSame('13000000.00', $e['eac']);
        $this->assertSame('1000000.00', $e['variance']);
        $this->assertSame('8.33', $e['variance_pct']);
        $this->assertSame('Vendor re-quote expected in Q3', $e['manual']['reason']);
        $this->assertSame('12000000.00', $e['eac_remaining_budget'], 'the computed figures are still shown');

        // a second override replaces the first; only one is active
        $svc->override($line, '12500000.00', 'Revised after the vendor call', $this->fin);
        $this->assertSame(1, Forecast::withoutGlobalScopes()->activeOverride()->count());
        $this->assertSame('12500000.00', $svc->eac($line)['eac']);

        $this->assertRefused(fn () => $svc->clear($line, $this->fin, ''), 'reason');
        $svc->clear($line, $this->fin, 'Quote received, no change');
        $this->assertSame('remaining_budget', $svc->eac($line)['method']);
        $this->assertSame('12000000.00', $svc->eac($line)['eac']);
        $this->assertRefused(fn () => $svc->clear($line, $this->fin, 'again'), null, 'no manual forecast');

        // over HTTP: the Finance Controller sets it, a user who cannot see amounts may not
        $this->actingAs($this->fin)->post(route('finance.monitor.forecast.override', $line), ['amount' => '12100000.00', 'reason' => 'Agreed with the CIO'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('12100000.00', $svc->eac($line)['eac']);
        $noAmounts = User::factory()->create(['organization_id' => $this->org->id]);
        $noAmounts->givePermissionTo(['view it-finance', 'edit it-finance']);
        $this->actingAs($noAmounts)->post(route('finance.monitor.forecast.override', $line), ['amount' => '1.00', 'reason' => 'Cannot see the numbers'])->assertForbidden();
        $this->actingAs($this->fin)->delete(route('finance.monitor.forecast.clear', $line), ['reason' => 'Reset'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('remaining_budget', $svc->eac($line)['method']);
    }

    /** FIN-04.02, FIN-04.05: closing a period stores one forecast snapshot per baseline line, as of that period. */
    public function test_snapshot_is_taken_at_close(): void
    {
        $this->onBudget([1]);
        $p1 = $this->periodSeq(1);
        app(PeriodCloseService::class)->close($p1, $this->fin);
        $snaps = Forecast::withoutGlobalScopes()->where('period_id', $p1->id)->where('is_snapshot', true)->get();
        $this->assertCount(3, $snaps);
        $this->assertSame(['12000000.00'], $snaps->pluck('base_amount')->map(fn ($v) => (string) $v)->unique()->values()->all());
        $this->assertSame(0, Forecast::withoutGlobalScopes()->activeOverride()->count(), 'snapshots are not overrides');
        $this->assertSame(3, app(ForecastService::class)->snapshot($p1, $this->fin));
        $this->assertCount(3, Forecast::withoutGlobalScopes()->where('period_id', $p1->id)->where('is_snapshot', true)->get(), 'a second snapshot replaces the first');
    }

    /** FIN-04.04: monthly burn, months remaining and the exhaustion date. */
    public function test_burn_and_exhaustion(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        $e = app(ForecastService::class)->eac($this->monLines[0]);
        $this->assertSame('1200000.00', $e['burn_monthly']); // 3.6m over 3 elapsed months
        $this->assertSame('7.00', $e['months_remaining']); // 8.4m available
        $this->assertSame('2027-10-30', $e['exhaustion_on']); // 31 March + 7 x 30.4375 days
        // no spend, no burn, no exhaustion date
        $none = app(ForecastService::class)->eac($this->monLines[1]);
        $this->assertSame('0.00', $none['burn_monthly']);
        $this->assertNull($none['months_remaining']);
        $this->assertNull($none['exhaustion_on']);
    }

    /** FIN-04.03: the amber/red boundary table. */
    public function test_classify_thresholds(): void
    {
        $c = fn (string $b, string $a, string $amber = '5.00', string $red = '10.00', string $floor = '100000.00') => VarianceService::classify($b, $a, $amber, $red, $floor);
        $this->assertSame('red', $c('1000000.00', '1100000.00')['severity'], 'exactly the red percentage');
        $this->assertSame('amber', $c('2000000.00', '2199999.00')['severity']);
        $this->assertSame('amber', $c('2000000.00', '2100000.00')['severity'], 'exactly the amber percentage and exactly the floor');
        $this->assertNull($c('2000000.00', '2099999.99')['severity'], 'one kobo under the floor');
        $this->assertNull($c('1000000.00', '1049999.00')['severity'], 'below amber');
        $this->assertNull($c('100000.00', '150000.00')['severity'], '50 % but under the absolute floor');
        $under = $c('1000000.00', '800000.00');
        $this->assertSame(['red', 'under', '-200000.00', '-20.00'], [$under['severity'], $under['direction'], $under['variance'], $under['pct']]);
        $this->assertSame('amber', $c('2000000.00', '1860000.00')['severity'], 'under-spend flags too');
        $zero = $c('0.00', '100000.00');
        $this->assertSame(['red', null], [$zero['severity'], $zero['pct']]);
        $this->assertNull($c('0.00', '99999.99')['severity']);
        $this->assertNull($c('0.00', '0.00')['severity']);
        $this->assertSame('amber', $c('1000000.00', '1120000.00', '5.00', '15.00')['severity'], 'a wider red threshold');
        $this->assertSame('red', $c('1000000.00', '1100000.00', '5.00', '10.00', '0.00')['severity']);

        // a line override beats the bank setting
        $line = $this->monLines[0];
        $this->assertSame(['amber_pct' => '5.00', 'red_pct' => '10.00', 'abs_floor' => '100000.00'], app(VarianceService::class)->thresholds($this->org->id, $line));
        BudgetLine::withoutGlobalScopes()->whereKey($line->id)->update(['variance_amber_pct' => 8, 'variance_red_pct' => 20]);
        $this->assertSame(['amber_pct' => '8.00', 'red_pct' => '20.00', 'abs_floor' => '100000.00'], app(VarianceService::class)->thresholds($this->org->id, $line->refresh()));
        foreach ([1, 2, 3] as $n) {
            $this->postActual($line, $this->periodSeq($n), '1150000.00'); // +15 %
        }
        $this->onBudget([1, 2, 3], [1, 2]);
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $this->assertSame('amber', VarianceNote::withoutGlobalScopes()->where('budget_line_id', $line->id)->sole()->severity, '+15 % is amber under a 20 % red override');
    }

    /** FIN-04.03: the nightly sweep notifies once per (line, period, severity) and the history is not resent. */
    public function test_variance_sweep_notifies_once_per_line_period_severity(): void
    {
        Notification::fake();
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00'); // red from January on
        }
        $this->postActual($this->monLines[1], $this->periodSeq(1), '1000000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(2), '1000000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(3), '1200000.00'); // +6.7 % at March: amber
        foreach ([1, 2] as $n) {
            $this->postActual($this->monLines[2], $this->periodSeq($n), '1000000.00');
        }
        $this->postActual($this->monLines[2], $this->periodSeq(3), '1500000.00', ['source_ref' => 'ESCALATION']); // +16.7 % at March: red

        $this->artisan('fin:variances-sweep')->assertSuccessful();
        $this->artisan('fin:variances-sweep')->assertSuccessful();
        $core = $this->sendsFor($this->requester, 'Core banking AMC');
        $this->assertCount(3, $core, 'red in each of January, February and March, once each');
        $this->assertCount(3, array_unique($core));
        $this->assertSame(['Mar 2027:amber'], $this->sendsFor($this->requester, 'Network support'));
        $this->assertSame(['Mar 2027:red'], $this->sendsFor($this->requester, 'Cloud hosting'));

        // amber then red: red goes out once more, then nothing
        $this->postActual($this->monLines[1], $this->periodSeq(3), '600000.00');
        $this->artisan('fin:variances-sweep')->assertSuccessful();
        $this->artisan('fin:variances-sweep')->assertSuccessful();
        $this->assertSame(['Mar 2027:amber', 'Mar 2027:red'], $this->sendsFor($this->requester, 'Network support'));

        // red then amber: nothing is sent when it eases, and the peak stays red
        Actual::withoutGlobalScopes()->where('source_ref', 'ESCALATION')->update(['base_amount' => '1200000.00', 'amount' => '1200000.00']);
        $this->artisan('fin:variances-sweep')->assertSuccessful();
        $this->assertSame(['Mar 2027:red'], $this->sendsFor($this->requester, 'Cloud hosting'));
        $note = VarianceNote::withoutGlobalScopes()->where('budget_line_id', $this->monLines[2]->id)->whereHas('period', fn ($q) => $q->where('sequence', 3))->sole();
        $this->assertSame(['amber', 'red'], [$note->severity, $note->peak_severity]);

        // the mail carries no amounts or percentages
        $n = Notification::sent($this->requester, BudgetVarianceNotification::class)->first();
        $this->assertDoesNotMatchRegularExpression('/\d+(\.\d+)?\s?%|NGN|\d{3},\d{3}/', $n->toArray($this->requester)['message']);
    }

    /** FIN-04.03: a re-baseline moves the note onto the new line without a duplicate note or a second notification. */
    public function test_rebaseline_keeps_the_same_note_and_does_not_renotify(): void
    {
        Notification::fake();
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        $this->onBudget([1, 2, 3], [1, 2]);
        $this->artisan('fin:variances-sweep')->assertSuccessful();
        $before = VarianceNote::withoutGlobalScopes()->count();
        $sent = count($this->sendsFor($this->requester, 'Core banking AMC'));
        $this->assertSame(3, $before);
        $this->assertSame(3, $sent);

        $svc = app(BudgetService::class);
        $old = $this->monLines[0]->budget_id;
        $rev = $svc->revise(Budget::withoutGlobalScopes()->findOrFail($old), $this->requester);
        $svc->submit($rev->refresh(), $this->requester);
        $svc->approve($rev->refresh(), $this->cfo);
        $svc->baseline($rev->refresh(), $this->fin);
        $this->artisan('fin:variances-sweep')->assertSuccessful();

        $this->assertSame($before, VarianceNote::withoutGlobalScopes()->count(), 'no duplicate notes');
        $newLine = $rev->lines()->orderBy('line_no')->first();
        $this->assertNotSame($this->monLines[0]->id, $newLine->id);
        $this->assertSame([$newLine->id], VarianceNote::withoutGlobalScopes()->whereIn('budget_line_id', [$this->monLines[0]->id, $newLine->id])->pluck('budget_line_id')->unique()->values()->all());
        $this->assertCount($sent, $this->sendsFor($this->requester, 'Core banking AMC'), 'no second notification');
        // the matrix still reconciles over the lineage
        $m = app(MonitoringService::class)->bva($this->org->id, ['group_by' => 'line'], $this->fin)['matrix'];
        $this->assertSame('0.00', $m['reconciliation']['difference']);
        $this->assertSame('3600000.00', collect($m['rows'])->firstWhere('line_id', $newLine->id)['actual']);
    }

    /** FIN-04.03, FIN-07.03: recipients are the budget owner, the cost-centre owner and Finance, nobody else. */
    public function test_variance_recipients(): void
    {
        Notification::fake();
        CostCentre::whereKey($this->ccChild->id)->update(['owner_id' => $this->owner->id]); // the line owner stays the requester
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        $this->onBudget([1, 2, 3], [1, 2]);
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);

        foreach ([['budget owner', $this->requester], ['cost-centre owner', $this->owner], ['Finance', $this->fin]] as [$who, $user]) {
            $this->assertCount(1, $this->sendsFor($user, 'Core banking AMC'), $who);
        }
        foreach ([$this->cfo, $this->stranger, $this->owner2] as $nobody) {
            $this->assertSame(0, Notification::sent($nobody, BudgetVarianceNotification::class)->count());
        }
    }

    /** FIN-04.03 (C124): periods that are locked are frozen, so the sweep skips them. */
    public function test_locked_periods_are_skipped_by_the_sweep(): void
    {
        Notification::fake();
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        $this->periodSeq(1)->forceFill(['status' => 'locked'])->save();
        $this->assertSame(2, app(VarianceService::class)->evaluateDue(), 'February and March only');
        $this->assertSame(0, VarianceNote::withoutGlobalScopes()->where('period_id', $this->periodSeq(1)->id)->count());
        $this->assertGreaterThan(0, VarianceNote::withoutGlobalScopes()->where('period_id', $this->periodSeq(3)->id)->count());
        $this->assertRefused(fn () => app(VarianceService::class)->evaluate($this->periodSeq(1), $this->fin), null, 're-open it');
    }

    /** FIN-04.10: explain (min length), acknowledge (not the author), re-explaining resets it, and the comment thread. */
    public function test_explain_acknowledge_comment_and_raise_issue(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        $this->onBudget([1, 2, 3], [1, 2]);
        $vs = app(VarianceService::class);
        $vs->evaluate($this->periodSeq(3), $this->fin);
        $note = VarianceNote::withoutGlobalScopes()->where('severity', 'red')->firstOrFail();

        $this->assertRefused(fn () => $vs->acknowledge($note, $this->fin), null, 'cannot be acknowledged'); // open
        $this->assertRefused(fn () => $vs->explain($note, 'too short', null, $this->requester), 'explanation');
        $n = $vs->explain($note, 'Licence true-up agreed with the vendor in February.', 'Renegotiate the cap', $this->requester);
        $this->assertSame(['explained', $this->requester->id], [$n->status, $n->author_id]);

        $this->assertRefused(fn () => $vs->acknowledge($n, $this->requester), 'approval', 'segregation of duties');
        $n = $vs->acknowledge($n, $this->fin);
        $this->assertSame(['acknowledged', $this->fin->id], [$n->status, $n->acknowledged_by_id]);

        $n = $vs->explain($n, 'Updated: the true-up is now billed quarterly.', null, $this->requester);
        $this->assertSame('explained', $n->status);
        $this->assertNull($n->acknowledged_by_id, 're-explaining resets the acknowledgement');

        $vs->comment($n, 'Please attach the vendor letter.', $this->fin);
        $vs->comment($n, 'Attached to the contract record.', $this->requester);
        $this->assertSame(['Please attach the vendor letter.', 'Attached to the contract record.'], $n->refresh()->comments->pluck('body')->all());
        $this->assertRefused(fn () => $vs->comment($n, '   ', $this->fin), 'body');

        // another bank cannot touch it
        $stranger = $this->userWith('IT Finance Controller', [], $this->otherBank()->id);
        $this->assertRefused(fn () => $vs->explain($n, str_repeat('x', 30), null, $stranger));

        // corrective action as an Issue (system-raised, source finance_variance), once while it is open
        $issue = $vs->raiseIssue($n, $this->fin, 'Cap the licence true-up');
        $this->assertSame(['finance_variance', 'high', $n->id], [$issue->source_type, $issue->severity, (int) $issue->source_id]);
        $this->assertSame($this->requester->id, $issue->owner_id, 'the cost-centre owner');
        $this->assertSame($issue->id, $n->refresh()->issue_id);
        $this->assertRefused(fn () => $vs->raiseIssue($n, $this->fin), 'issue', 'already open');
        $this->assertSame(1, Issue::withoutGlobalScopes()->where('source_type', 'finance_variance')->count());
        $this->assertArrayNotHasKey('finance_variance', collect(Issue::SOURCES)->filter(fn ($s) => $s['model'] !== null)->all());
    }

    /** FIN-07.10: without "view it-finance-amounts" the monitoring props carry no money, variance % or EAC; severity and counts stay. */
    public function test_masking(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $viewer = User::factory()->create(['organization_id' => $this->org->id]);
        $viewer->givePermissionTo(['view it-finance', 'export it-finance', 'close it-finance-periods']); // sees every cost centre, but no amounts
        $this->assertFalse($viewer->can('view it-finance-amounts'));

        $f = $this->props($this->actingAs($viewer)->get(route('finance.monitor.forecast'))->assertOk());
        $this->assertFalse($f['can']['viewAmounts']);
        $this->assertNotEmpty($f['lines']);
        foreach (['baseline', 'budget', 'actual_ytd', 'eac_remaining_budget', 'eac_run_rate', 'eac', 'variance', 'variance_pct', 'burn_monthly', 'exhaustion_on'] as $k) {
            $this->assertNull($f['lines'][0][$k], "forecast line {$k}");
        }
        $this->assertNull($f['totals']['eac']);

        $b = $this->props($this->actingAs($viewer)->get(route('finance.monitor.bva', ['group_by' => 'line']))->assertOk());
        foreach (['budget', 'actual', 'variance', 'variance_pct', 'forecast', 'available', 'committed'] as $k) {
            $this->assertNull($b['matrix']['rows'][0][$k], "bva row {$k}");
        }
        $this->assertNotNull($b['matrix']['rows'][0]['label']);

        $v = $this->props($this->actingAs($viewer)->get(route('finance.monitor.variances'))->assertOk());
        $note = collect($v['notes']['data'])->first(fn ($n) => $n['severity'] === 'red' && $n['direction'] === 'over');
        foreach (['budget', 'actual', 'variance', 'variance_pct', 'period_variance'] as $k) {
            $this->assertNull($note[$k], "note {$k}");
        }
        $this->assertNotNull($note);
        $this->assertGreaterThan(0, $v['summary']['red']);

        $d = $this->props($this->actingAs($viewer)->get(route('finance.dashboard'))->assertOk());
        $this->assertNull($d['metrics']['budget_total']);
        $this->assertNull($d['monthlyBvA'][0]['budget']);
        $this->assertGreaterThan(0, $d['metrics']['variances']['red']);

        $tx = $this->actingAs($viewer)->getJson(route('finance.monitor.transactions', $this->monLines[0]))->assertOk()->json();
        $this->assertNull($tx['totals']['actual']);
    }

    /** FIN-04.07, FIN-07.10 (cost-centre visibility): an owner sees only their own cost centre, and never the unallocated row. */
    public function test_owner_sees_own_cost_centres(): void
    {
        $other = $this->firstLine($this->baselined($this->cc2, '6000000.00')); // owned by owner2
        $this->postActual($this->monLines[0], $this->periodSeq(1), '1100000.00');
        $this->postActual($other, $this->periodSeq(1), '999999.00');
        $this->postActual($this->monLines[0], $this->periodSeq(2), '222.00', ['match_status' => 'unmatched', 'budget_line_id' => null]);
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);

        $mine = $this->props($this->actingAs($this->requester)->get(route('finance.monitor.bva', ['group_by' => 'line']))->assertOk())['matrix'];
        $this->assertNull($mine['unallocated']);
        $this->assertCount(3, $mine['rows']);
        $this->assertSame('0.00', $mine['reconciliation']['difference']);
        $this->assertSame('1100000.00', $mine['totals']['actual']);
        $this->actingAs($this->requester)->getJson(route('finance.monitor.transactions', $other))->assertForbidden();

        $theirs = $this->props($this->actingAs($this->owner2)->get(route('finance.monitor.bva', ['group_by' => 'line']))->assertOk())['matrix'];
        $this->assertCount(1, $theirs['rows']);
        $this->assertSame('999999.00', $theirs['totals']['actual']);

        $all = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.bva', ['group_by' => 'line']))->assertOk())['matrix'];
        $this->assertCount(4, $all['rows']);
        $this->assertNotNull($all['unallocated']);

        $vNotes = $this->props($this->actingAs($this->requester)->get(route('finance.monitor.variances'))->assertOk())['notes']['data'];
        $this->assertSame([], collect($vNotes)->pluck('cost_centre.code')->reject(fn ($c) => $c === 'CC-1A')->all());
        $dash = $this->props($this->actingAs($this->requester)->get(route('finance.dashboard'))->assertOk());
        $this->assertSame('owner', $dash['audience']);
        $this->assertSame(['CC-1A'], collect($dash['costCentres'])->pluck('code')->all());
    }

    /** FIN-04.08: `kri:measure` records a reading for each of the four finance KRIs. */
    public function test_kri_measure_produces_finance_readings(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        $this->postActual($this->monLines[1], $this->periodSeq(1), '5000000.00', ['match_status' => 'unbudgeted', 'budget_line_id' => null]);
        $this->contract(['ends_on' => '2027-04-02', 'notice_period_days' => 30, 'title' => 'Passed notice']);
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $codes = ['KRI-FIN-VAR', 'KRI-FIN-UNB', 'KRI-FIN-INV', 'KRI-FIN-NOT'];
        foreach ($codes as $code) {
            Kri::withoutGlobalScopes()->create([
                'organization_id' => $this->org->id, 'code' => $code, 'name' => $code, 'category' => 'financial', 'threshold_green' => 0, 'threshold_amber' => 5, 'threshold_red' => 20,
                'direction' => 'higher_worse', 'unit' => 'count', 'frequency' => 'monthly', 'source' => 'platform', 'owner_id' => $this->fin->id,
            ]);
        }
        Artisan::call('kri:measure');
        $ids = Kri::withoutGlobalScopes()->whereIn('code', $codes)->pluck('id', 'code');
        $value = fn (string $code) => (float) KriReading::withoutGlobalScopes()->where('kri_id', $ids[$code])->orderByDesc('id')->value('value');
        $this->assertCount(4, KriReading::withoutGlobalScopes()->whereIn('kri_id', $ids->values())->get());
        $this->assertGreaterThan(0, $value('KRI-FIN-VAR'));
        $this->assertGreaterThan(0, $value('KRI-FIN-UNB'));
        $this->assertSame(0.0, $value('KRI-FIN-INV'));
        $this->assertGreaterThanOrEqual(1.0, $value('KRI-FIN-NOT'));
    }
}
