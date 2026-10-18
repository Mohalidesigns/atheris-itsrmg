<?php

namespace Tests\Feature\Finance;

use App\Events\Finance\VarianceBreached;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\VarianceNote;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\ForecastService;
use App\Services\Finance\PeriodCloseService;
use App\Services\Finance\VarianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/**
 * P4 backend slice smoke (FIN-04.01 to .07, .10, FIN-07.07, FIN-07.10): every new GET renders with the contract's prop keys,
 * amounts are masked, other banks get a 404 and the export copes with a bank that has no fiscal year.
 */
class MonitoringBackendSmokeTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
        // March: 1.5m over a 1m month on line 1 gives a red YTD variance (Jan-Mar budget 3m, actual 3.6m = +20%).
        $this->postActual($this->monLines[0], $this->periodSeq(1), '1200000.00');
        $this->postActual($this->monLines[0], $this->periodSeq(2), '1200000.00');
        $this->postActual($this->monLines[0], $this->periodSeq(3), '1200000.00');
        $this->postActual($this->monLines[1], $this->periodSeq(3), '1000000.00', ['match_status' => 'unmatched', 'budget_line_id' => null]);
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
    }

    private function props($response): array
    {
        return $response->original->getData()['page']['props'];
    }

    public function test_dashboard_props(): void // FIN-04.07
    {
        $p = $this->props($this->actingAs($this->fin)->get(route('finance.dashboard'))->assertOk());
        foreach (['fiscalYears', 'selectedFiscalYear', 'asOfPeriod', 'audience', 'costCentres', 'filters', 'metrics', 'spendByCategory', 'monthlyBvA', 'topVariances', 'renewalsDue', 'pendingApprovals', 'can', 'baseCurrency'] as $k) {
            $this->assertArrayHasKey($k, $p, $k);
        }
        $this->assertSame('finance', $p['audience']);
        $this->assertSame('4600000.00', $p['metrics']['actual_ytd'], 'includes the 1m unmatched posting, as the BvA matrix does');
        $this->assertSame('1000000.00', $p['metrics']['actual_unallocated']);
        $this->assertSame(3, $p['metrics']['variances']['red']); // line 1 over by 20 %, lines 2 and 3 untouched (100 % under, above the floor)
        $owner = $this->props($this->actingAs($this->requester)->get(route('finance.dashboard'))->assertOk());
        $this->assertSame('owner', $owner['audience']);
    }

    public function test_bva_matrix_reconciles_and_has_contract_keys(): void // FIN-04.01
    {
        $p = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.bva'))->assertOk());
        foreach (['filters', 'groupings', 'breadcrumbs', 'fiscalYears', 'periods', 'dimensions', 'thresholds', 'matrix', 'can'] as $k) {
            $this->assertArrayHasKey($k, $p, $k);
        }
        $m = $p['matrix'];
        $this->assertSame('0.00', $m['reconciliation']['difference']);
        $this->assertSame('4600000.00', $m['totals']['actual']);
        $this->assertSame('1000000.00', $m['unallocated']['actual']);
        $this->assertSame('ytd', $p['filters']['view']);
        $drill = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.bva', ['group_by' => 'line', 'view' => 'period']))->assertOk());
        $this->assertNotNull($drill['matrix']['rows'][0]['line_id']);
        $this->assertNotEmpty($drill['matrix']['rows'][0]['by_period']);
        $this->actingAs($this->fin)->getJson(route('finance.monitor.transactions', $this->monLines[0]))->assertOk()->assertJsonStructure(['line', 'actuals', 'commitments', 'totals']);
    }

    public function test_forecast_variances_and_period_close_pages_render(): void // FIN-04.02, .03, .05
    {
        $f = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.forecast'))->assertOk());
        foreach (['filters', 'fiscalYears', 'costCentres', 'settingsMethod', 'asOfPeriod', 'periodsElapsed', 'periodsTotal', 'lines', 'totals', 'history'] as $k) {
            $this->assertArrayHasKey($k, $f, $k);
        }
        $v = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.variances'))->assertOk());
        foreach (['filters', 'fiscalYears', 'periods', 'costCentres', 'statuses', 'thresholds', 'summary', 'notes'] as $k) {
            $this->assertArrayHasKey($k, $v, $k);
        }
        $this->assertCount(3, $v['notes']['data']);
        $c = $this->props($this->actingAs($this->fin)->get(route('finance.monitor.period-close'))->assertOk());
        foreach (['fiscalYears', 'filters', 'periods', 'selected', 'can'] as $k) {
            $this->assertArrayHasKey($k, $c, $k);
        }
        $this->actingAs($this->fin)->getJson(route('finance.periods.close-checklist', $this->periodSeq(3)))->assertOk()->assertJsonStructure(['period', 'items', 'can_close', 'blockers']);
    }

    public function test_reports_index_and_downloads(): void // FIN-07.07
    {
        $p = $this->props($this->actingAs($this->fin)->get(route('finance.reports.index', ['report' => 'bva']))->assertOk());
        foreach (['reports', 'filters', 'fiscalYears', 'periods', 'costCentres', 'schedules', 'can'] as $k) {
            $this->assertArrayHasKey($k, $p, $k);
        }
        $this->assertSame('bva', $p['filters']['report']);
        foreach (['csv' => 'text/csv', 'xlsx' => 'spreadsheetml', 'pdf' => 'application/pdf'] as $format => $type) {
            $r = $this->actingAs($this->fin)->get(route('finance.reports.show', ['report' => 'bva', 'format' => $format]))->assertOk();
            $this->assertStringContainsString($type, (string) $r->headers->get('Content-Type'));
        }
        $this->actingAs($this->fin)->get(route('finance.reports.show', ['report' => 'nope']))->assertNotFound();
    }

    public function test_masking_nulls_money_keys_but_keeps_severity(): void // FIN-07.10
    {
        $noAmounts = User::factory()->create(['organization_id' => $this->org->id]);
        $noAmounts->givePermissionTo(['view it-finance', 'export it-finance', 'close it-finance-periods']);
        $b = $this->props($this->actingAs($noAmounts)->get(route('finance.monitor.bva'))->assertOk());
        $this->assertFalse($b['can']['viewAmounts']);
        $this->assertNull($b['matrix']['totals']['budget']);
        $this->assertNull($b['matrix']['reconciliation']['ledger_actual']);
        $v = $this->props($this->actingAs($noAmounts)->get(route('finance.monitor.variances'))->assertOk());
        $note = $v['notes']['data'][0];
        $this->assertNull($note['variance']);
        $this->assertSame('red', $note['severity']);
        $d = $this->props($this->actingAs($noAmounts)->get(route('finance.dashboard'))->assertOk());
        $this->assertNull($d['metrics']['actual_ytd']);
        $this->assertSame(3, $d['metrics']['variances']['red']);
        $csv = $this->actingAs($noAmounts)->get(route('finance.reports.show', ['report' => 'bva']))->assertOk()->getContent();
        $this->assertStringNotContainsString('3600000', $csv);
    }

    public function test_other_bank_gets_404_and_empty_bank_exports(): void // tenancy
    {
        $note = VarianceNote::withoutGlobalScopes()->firstOrFail();
        $other = Organization::factory()->create(['currency' => 'NGN']);
        $stranger = $this->userWith('IT Finance Controller', [], $other->id);
        $this->actingAs($stranger);
        $this->post(route('finance.monitor.variances.explain', $note), ['explanation' => str_repeat('x', 30)])->assertNotFound();
        $this->get(route('finance.monitor.transactions', $this->monLines[0]))->assertNotFound();
        $this->post(route('finance.periods.evaluate-variances', $this->periodSeq(3)))->assertNotFound();
        $this->get(route('finance.periods.close-checklist', $this->periodSeq(3)))->assertNotFound();
        $close = PeriodClose::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'fiscal_year_id' => $this->fy->id, 'period_id' => $this->periodSeq(1)->id, 'status' => 'closed', 'checklist' => []]);
        $this->get(route('finance.period-closes.show', $close))->assertNotFound();
        $this->get(route('finance.monitor.bva.export'))->assertOk();
        $this->get(route('finance.monitor.bva'))->assertOk();
        $this->get(route('finance.dashboard'))->assertOk();
    }

    public function test_explain_acknowledge_comment_and_raise_issue_over_http(): void // FIN-04.10
    {
        $note = VarianceNote::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->requester)->post(route('finance.monitor.variances.explain', $note), ['explanation' => 'Quarter-end licence true-up agreed with the vendor.', 'corrective_action' => 'Renegotiate'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->requester)->post(route('finance.monitor.variances.comment', $note), ['body' => 'Noted'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->requester)->post(route('finance.monitor.variances.acknowledge', $note))->assertForbidden(); // needs close it-finance-periods
        $this->actingAs($this->fin)->post(route('finance.monitor.variances.acknowledge', $note))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('acknowledged', $note->refresh()->status);
        $this->actingAs($this->fin)->post(route('finance.monitor.variances.raise-issue', $note), ['title' => 'Fix the licence spend'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('finance_variance', $note->refresh()->issue->source_type);
    }

    public function test_close_checklist_blocks_then_reopen_flows(): void // FIN-04.05
    {
        $svc = app(PeriodCloseService::class);
        $p1 = $this->periodSeq(1);
        $this->postActual($this->monLines[1], $p1, '1000000.00');
        $this->postActual($this->monLines[0], $p1, '1.00'); // keep it simple: period 1 has postings and no unmatched rows
        $c = $this->assertRefused(fn () => $svc->close($this->periodSeq(3), $this->fin), 'checklist');
        $this->assertNotEmpty($c->errors()['checklist']);
        app(VarianceService::class)->evaluate($p1, $this->fin);
        $this->explainAll($p1);
        $closed = $svc->close($p1, $this->fin);
        $this->assertSame('closed', $closed->status);
        $this->assertSame('closed', Period::withoutGlobalScopes()->find($p1->id)->status);
        $req = $svc->requestReopen($p1, 'Late settlement posting needs to be added.', $this->fin);
        $this->assertSame('submitted', $req->reopen_status);
        $svc->approveReopen($req->refresh(), $this->cfo);
        $this->assertSame('open', Period::withoutGlobalScopes()->find($p1->id)->status);
        $this->assertSame('reopened', $closed->refresh()->status);
    }

    public function test_classify_boundaries_eac_and_event_once(): void // FIN-04.02, .03
    {
        $c = fn (string $b, string $a) => VarianceService::classify($b, $a, '5.00', '10.00', '100000.00');
        $this->assertSame('red', $c('1000000.00', '1100000.00')['severity']); // exactly 10 %
        $this->assertSame('amber', $c('2000000.00', '2100000.00')['severity']); // exactly 5 % and exactly the floor
        $this->assertNull($c('2000000.00', '2099999.00')['severity']);
        $this->assertNull($c('100000.00', '150000.00')['severity']); // 50 % but under the 100,000 floor
        $this->assertSame('under', $c('1000000.00', '800000.00')['direction']);
        $this->assertSame('red', $c('0.00', '100000.00')['severity']);
        $this->assertNull($c('0.00', '99999.00')['severity']);

        // As-of April 5 is March (3 elapsed): line 1 spent 3.6m of 12m.
        $eac = app(ForecastService::class)->eac($this->monLines[0]);
        $this->assertSame('3600000.00', $eac['actual_ytd']);
        $this->assertSame('12600000.00', $eac['eac_remaining_budget']); // 3.6m + 9 x 1m
        $this->assertSame('14400000.00', $eac['eac_run_rate']); // 3.6m / 3 x 12
        $this->assertSame(3, $eac['periods_elapsed']);

        Event::fake([VarianceBreached::class]);
        $r = app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $this->assertSame(0, $r['created']);
        $this->assertSame(3, $r['updated']);
        Event::assertNotDispatched(VarianceBreached::class);
    }
}
