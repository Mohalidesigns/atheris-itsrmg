<?php

namespace Tests\Feature\Finance;

use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\Finance\Forecast;
use App\Models\Finance\PeriodClose;
use App\Models\Issue;
use App\Models\Kri;
use App\Models\KriBreach;
use App\Models\KriReading;
use App\Models\User;
use App\Services\Finance\ForecastService;
use App\Services\Finance\PeriodCloseService;
use App\Services\Kri\KriService;
use App\Services\Reports\BoardPackBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/** P4 carry-overs adopted in P5: domain M5 (forecast snapshots keep overrides and history) and security M2 (finance KRIs and stored board packs masked). */
class FinanceP4CarryOverTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
    }

    private function svc(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    private function fill(int $seq): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->postActual($this->monLines[$i], $this->periodSeq($seq), '1000000.00');
        }
    }

    private function snaps(bool $active)
    {
        $q = Forecast::withoutGlobalScopes()->where('period_id', $this->periodSeq(1)->id)->where('is_snapshot', true);

        return $active ? $q->whereNull('superseded_at')->orderBy('budget_line_id')->get() : $q->whereNotNull('superseded_at')->get();
    }

    public function test_snapshot_keeps_a_manual_override_and_the_history_across_reopen_and_reclose(): void
    {
        $this->fill(1);
        $this->svc()->close($this->periodSeq(1), $this->fin);
        $first = $this->snaps(true);
        $this->assertCount(3, $first);
        $this->assertSame(['remaining_budget'], $first->pluck('method')->unique()->values()->all());

        app(ForecastService::class)->override($this->monLines[0], '14000000.00', 'Vendor repriced the AMC from February.', $this->fin);
        $this->fin->givePermissionTo('approve it-finance');
        $req = $this->svc()->requestReopen($this->periodSeq(1), 'A January invoice was posted to the wrong line.', $this->cfo);
        $this->svc()->approveReopen($req->refresh(), $this->head);
        $second = $this->svc()->close($this->periodSeq(1), $this->fin);

        $active = $this->snaps(true);
        $this->assertCount(3, $active);
        $line0 = $active->firstWhere('budget_line_id', $this->monLines[0]->id);
        $this->assertSame(['manual', '14000000.00'], [$line0->method, (string) $line0->base_amount], 'the override is snapshotted');
        $this->assertSame('Vendor repriced the AMC from February.', $line0->reason);
        $this->assertSame($second->id, (int) $line0->period_close_id);
        $this->assertCount(3, $this->snaps(false), 'the first snapshot is kept as history');
        $this->assertSame(0, Forecast::withoutGlobalScopes()->activeOverride()->where('is_snapshot', true)->count(), 'snapshots are never overrides');
        $this->assertSame(2, PeriodClose::withoutGlobalScopes()->where('period_id', $this->periodSeq(1)->id)->count());

        // the forecast page's EAC history reads only the live snapshot
        $page = $this->actingAs($this->fin)->get(route('finance.monitor.forecast', ['fiscal_year_id' => $this->fy->id]))->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $page['history']);
        $this->assertSame('38000000.00', $page['history'][0]['eac_total']); // 14m + 12m + 12m
    }

    private function kri(string $code, string $value = '18.5000'): Kri
    {
        $k = Kri::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'code' => $code, 'name' => $code.' name', 'category' => 'financial', 'threshold_green' => 5, 'threshold_amber' => 10, 'threshold_red' => 15,
            'direction' => 'higher_worse', 'unit' => 'pct', 'frequency' => 'monthly', 'source' => 'platform', 'owner_id' => $this->fin->id,
        ]);
        $r = KriReading::withoutGlobalScopes()->create(['kri_id' => $k->id, 'period' => '2027-04', 'value' => $value, 'status' => 'red', 'recorded_at' => now()]);
        KriBreach::withoutGlobalScopes()->create(['kri_id' => $k->id, 'reading_id' => $r->id, 'level' => 'red', 'notes' => "Red in 2027-04: {$value}%.", 'occurred_at' => now()]);

        return $k;
    }

    private function viewer(bool $amounts): User
    {
        $u = $this->userWith('Viewer');
        $u->givePermissionTo(['view risks', 'view reports']);
        $amounts && $u->givePermissionTo('view it-finance-amounts');

        return $u;
    }

    public function test_kri_index_show_and_dashboard_mask_finance_kri_values(): void
    {
        $fin = $this->kri('KRI-FIN-VAR');
        $other = $this->kri('KRI-OTHER', '7.0000');
        $without = $this->viewer(false);
        $with = $this->viewer(true);

        $row = fn (User $u, string $code) => collect($this->actingAs($u)->get(route('kri.index'))->assertOk()->viewData('page')['props']['kris'])->firstWhere('code', $code);
        $masked = $row($without, 'KRI-FIN-VAR');
        $this->assertNull($masked['latest']['value']);
        $this->assertSame('red', $masked['latest']['status'], 'status stays visible');
        $this->assertSame([null], collect($masked['trend'])->pluck('value')->unique()->all());
        $this->assertEquals(15, $masked['threshold_red']);
        $this->assertEquals('7.0000', $row($without, 'KRI-OTHER')['latest']['value'], 'other KRIs are untouched');
        $this->assertEquals('18.5000', $row($with, 'KRI-FIN-VAR')['latest']['value']);

        $show = fn (User $u, Kri $k) => $this->actingAs($u)->get(route('kri.show', $k))->assertOk()->viewData('page')['props'];
        $p = $show($without, $fin);
        $this->assertNull($p['readings'][0]['value']);
        $this->assertNull($p['breaches'][0]['notes'], 'breach notes quote the value');
        $this->assertSame('red', $p['breaches'][0]['level']);
        $this->assertEquals('18.5000', $show($with, $fin)['readings'][0]['value']);
        $this->assertEquals('7.0000', $show($without, $other)['readings'][0]['value']);

        $dash = fn (User $u) => collect($this->actingAs($u)->get(route('dashboard'))->assertOk()->viewData('page')['props']['kris'])->firstWhere('code', 'KRI-FIN-VAR');
        $this->assertNull($dash($without)['readings'][0]['value']);
        $this->assertEquals('18.5000', $dash($with)['readings'][0]['value']);
        $this->assertEquals('18.5000', KriReading::withoutGlobalScopes()->where('kri_id', $fin->id)->value('value'), 'nothing was changed in the database');
    }

    public function test_stored_board_pack_masks_finance_and_investment_sections_and_the_kri_panel(): void
    {
        $this->kri('KRI-FIN-VAR');
        $this->kri('KRI-OTHER', '7.0000');
        $template = BoardPackTemplate::create(['name' => 'Mixed', 'sections' => ['kri_panel', 'it_financial_position', 'it_investment_portfolio']]);
        $content = app(BoardPackBuilder::class)->snapshot($template, $this->org->id);
        $keys = array_column($content, 'key');
        $this->assertSame(['kri_panel', 'it_financial_position', 'it_investment_portfolio'], $keys);
        $run = BoardPackRun::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'template_id' => $template->id, 'period' => '2027-04', 'status' => 'draft', 'content' => $content, 'prepared_by' => $this->fin->id]);

        $panel = fn (User $u) => collect($this->actingAs($u)->get(route('board-packs.show', $run))->assertOk()->viewData('page')['props']['run']['content'])->firstWhere('key', 'kri_panel')['data']['kris'];
        $byCode = fn (array $rows, string $c) => collect($rows)->firstWhere('code', $c);
        $without = $panel($this->viewer(false));
        $this->assertNull($byCode($without, 'KRI-FIN-VAR')['value']);
        $this->assertSame('red', $byCode($without, 'KRI-FIN-VAR')['status']);
        $this->assertEquals('7.0000', $byCode($without, 'KRI-OTHER')['value']);
        $this->assertEquals('18.5000', $byCode($panel($this->viewer(true)), 'KRI-FIN-VAR')['value']);

        $this->actingAs($this->viewer(false))->get(route('board-packs.show', $run))->assertInertia(fn (Assert $p) => $p->where('run.content.1.data.budget_total', null));
    }

    /** P5 review security M3: a masked finance KRI puts no amount in its breach notes or auto-raised Issue; other KRIs keep theirs. */
    public function test_masked_kri_breach_notes_and_issue_carry_no_amount(): void
    {
        $svc = app(KriService::class);
        $make = fn (string $code) => Kri::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'code' => $code, 'name' => $code.' name', 'category' => 'financial', 'threshold_green' => 5, 'threshold_amber' => 10, 'threshold_red' => 15,
            'direction' => 'higher_worse', 'unit' => 'pct', 'frequency' => 'monthly', 'source' => 'manual', 'owner_id' => $this->fin->id,
        ]);
        $masked = $make('KRI-FIN-UNB');
        $svc->record($masked, 18.5, $this->fin);
        $svc->record($masked, 12.5, $this->fin, null, now()->addMonth()); // escalation path stays value-free too
        $breach = KriBreach::withoutGlobalScopes()->where('kri_id', $masked->id)->firstOrFail();
        $issue = Issue::withoutGlobalScopes()->where('source_type', 'kri')->where('source_id', $masked->id)->firstOrFail();
        foreach ([$breach->notes, $issue->description] as $text) {
            $this->assertStringNotContainsString('18.5', $text);
            $this->assertStringNotContainsString('10', str_replace('KRI-FIN-UNB', '', $text), 'the amber limit is value-free too');
        }
        $this->assertStringContainsString('KRI-FIN-UNB breached its red limit', $issue->description);

        $open = $make('KRI-OTHER');
        $svc->record($open, 18.5, $this->fin);
        $this->assertStringContainsString('18.5', Issue::withoutGlobalScopes()->where('source_type', 'kri')->where('source_id', $open->id)->firstOrFail()->description);
    }
}
