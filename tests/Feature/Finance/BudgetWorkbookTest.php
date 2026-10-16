<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FxRate;
use App\Models\Finance\GlAccount;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\BudgetPhasing;
use App\Services\Finance\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsBudgets;
use Tests\TestCase;

/** ATH-FIN-001 §12 FIN-02.03, .04, .07, .13 and FIN-01.05: lines, phasing, ceilings, FX and the CSV workbook. */
class BudgetWorkbookTest extends TestCase
{
    use BuildsBudgets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBudgets();
    }

    private function periods()
    {
        return app(BudgetPhasing::class)->periodsFor($this->fy);
    }

    private function sum(iterable $values): string
    {
        $t = '0';
        foreach ($values as $v) {
            $t = bcadd($t, (string) $v, 2);
        }

        return $t;
    }

    /** @return array{0: array<int,string>, 1: array<int, array<string,string>>} */
    private function parseCsv(string $csv): array
    {
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', trim($csv)))));
        $header = array_shift($rows);

        return [$header, array_map(fn ($r) => array_combine($header, $r), $rows)];
    }

    private function toCsv(array $header, array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($h) => $r[$h] ?? '', $header));
        }
        rewind($fh);

        return stream_get_contents($fh);
    }

    private function upload(Budget $b, string $csv, string $name = 'workbook.csv')
    {
        return $this->actingAs($this->owner)->post(route('finance.budgets.workbook.import', $b), ['file' => UploadedFile::fake()->createWithContent($name, $csv)]);
    }

    private function exported(Budget $b): string
    {
        return $this->actingAs($this->owner)->get(route('finance.budgets.workbook', $b))->assertOk()->streamedContent();
    }

    /** FIN-02.04: a line needs a TBM cost pool and tower; GL and CapEx default from the pool. */
    public function test_line_requires_tbm_pool_and_tower_and_defaults_gl_and_capex(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $base = ['description' => 'Test', 'annual_amount' => '1000'];

        $this->assertRefused(fn () => $svc->addLine($b, $base, $this->owner), 'cost_pool_id');
        $this->assertRefused(fn () => $svc->addLine($b, $base + ['cost_pool_id' => $this->cat('TW-APP'), 'tower_id' => $this->cat('TW-APP')], $this->owner), 'cost_pool_id');
        $this->assertRefused(fn () => $svc->addLine($b, $base + ['cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('CP-SW')], $this->owner), 'tower_id');
        $this->assertRefused(fn () => $svc->addLine($b, $base + ['cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('TW-APP'), 'category_id' => $this->cat('CP-LAB-INT')], $this->owner), 'category_id');
        $this->assertRefused(fn () => $svc->addLine($b, $this->lineData(['description' => '']), $this->owner), 'description');
        $this->assertRefused(fn () => $svc->addLine($b, $this->lineData(['priority' => 'whenever']), $this->owner), 'priority');
        $this->assertRefused(fn () => $svc->addLine($b, $this->lineData(['contract_id' => 1]), $this->owner), 'contract_id');
        $this->assertRefused(fn () => $svc->addLine($b, $this->lineData(['annual_amount' => '-5']), $this->owner), 'annual_amount');
        $this->assertRefused(fn () => $svc->addLine($b, $this->lineData(['currency' => 'JPY']), $this->owner), 'currency');

        $sw = $svc->addLine($b, $base + ['cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('TW-APP'), 'category_id' => $this->cat('TW-APP-DEV')], $this->owner);
        $this->assertSame(GlAccount::where('code', '6201')->value('id'), $sw->gl_account_id, 'GL defaults from the cost pool');
        $this->assertFalse($sw->is_capex);
        $this->assertSame('discretionary', $sw->priority);
        $this->assertSame('run', $sw->rgt);
        $this->assertSame($this->cc->id, $sw->cost_centre_id, 'a line always carries its budget\'s cost centre (C42)');

        $hw = $svc->addLine($b, ['description' => 'Servers', 'annual_amount' => '5000', 'cost_pool_id' => $this->cat('CP-HW'), 'tower_id' => $this->cat('TW-CMP')], $this->owner);
        $this->assertTrue($hw->is_capex, 'CapEx defaults from the pool');
        $this->assertSame(GlAccount::where('code', '1810')->value('id'), $hw->gl_account_id);
        $opexHw = $svc->addLine($b, ['description' => 'Maint', 'annual_amount' => '5000', 'cost_pool_id' => $this->cat('CP-HW'), 'tower_id' => $this->cat('TW-CMP'), 'is_capex' => false], $this->owner);
        $this->assertFalse($opexHw->is_capex);

        $b->refresh();
        $this->assertSame('5000.00', (string) $b->total_capex);
        $this->assertSame('1206000.00', (string) $b->total_opex);
        $this->assertSame('1211000.00', (string) $b->total_base_amount);
    }

    /** FIN-02.04: every phasing method sums exactly to the annual amount, in line and base currency. */
    public function test_every_phasing_method_sums_exactly_to_annual(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $n = $this->periods()->count();
        $this->assertSame(12, $n);

        foreach (['equal', 'front', 'back', 'seasonal'] as $method) {
            foreach ([['1000000.01', 'NGN'], ['1234567.89', 'USD'], ['0.07', 'NGN']] as [$annual, $ccy]) {
                $line = $svc->addLine($b, $this->lineData(['description' => "{$method} {$ccy}", 'annual_amount' => $annual, 'currency' => $ccy, 'phasing_method' => $method]), $this->owner);
                $rows = BudgetLinePeriod::where('budget_line_id', $line->id)->get();
                $this->assertCount(12, $rows);
                $this->assertSame($annual, $this->sum($rows->pluck('amount')), "{$method} {$ccy} amount");
                $this->assertSame((string) $line->base_annual_amount, $this->sum($rows->pluck('base_amount')), "{$method} {$ccy} base");
            }
        }

        // shape of each method on a clean total
        $shape = fn (string $m) => BudgetLinePeriod::where('budget_line_id', $svc->addLine($b, $this->lineData(['annual_amount' => '1200000', 'phasing_method' => $m]), $this->owner)->id)
            ->orderBy('period_id')->pluck('amount')->map(fn ($a) => (string) $a)->all();
        $this->assertSame(array_fill(0, 12, '100000.00'), $shape('equal'));
        $front = $shape('front');
        $this->assertTrue(bccomp($front[0], $front[11], 2) > 0);
        $back = $shape('back');
        $this->assertTrue(bccomp($back[0], $back[11], 2) < 0);
        $seasonal = $shape('seasonal');
        $this->assertSame('72000.00', $seasonal[0]); // 6 % of the year in January
        $this->assertSame('132000.00', $seasonal[11]); // 11 % in December

        $line = $svc->addLine($b, $this->lineData(['annual_amount' => '600000']), $this->owner);
        $svc->phase($line, 'front');
        $this->assertSame('600000.00', $this->sum(BudgetLinePeriod::where('budget_line_id', $line->id)->pluck('amount')));
        $this->assertRefused(fn () => $svc->phase($line, 'sideways'), 'phasing_method');
    }

    /** FIN-02.04: a custom phasing must total the annual amount, otherwise the save is refused. */
    public function test_custom_phasing_must_sum_to_annual(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $ids = $this->periods()->pluck('id');
        $even = $ids->mapWithKeys(fn ($id) => [$id => '1000.00'])->all();

        $line = $svc->addLine($b, $this->lineData(['annual_amount' => '12000', 'phasing_method' => 'custom', 'amounts' => $even]), $this->owner);
        $this->assertSame('12000.00', $this->sum(BudgetLinePeriod::where('budget_line_id', $line->id)->pluck('amount')));

        $short = $even;
        $short[$ids[0]] = '999.99';
        $e = $this->assertRefused(fn () => $svc->addLine($b, $this->lineData(['annual_amount' => '12000', 'phasing_method' => 'custom', 'amounts' => $short]), $this->owner), 'amounts');
        $this->assertStringContainsString('11999.99', $e->errors()['amounts'][0]);
        $this->assertRefused(fn () => $svc->phase($line, 'custom', $short), 'amounts');
        $this->assertRefused(fn () => $svc->phase($line, 'custom'), 'amounts');
        $this->assertRefused(fn () => $svc->addLine($b, $this->lineData(['annual_amount' => '12000', 'phasing_method' => 'custom', 'amounts' => [999999 => '12000']]), $this->owner), 'amounts');
        $this->assertSame(1, BudgetLine::where('budget_id', $b->id)->where('annual_amount', '12000.00')->count(), 'refused saves left no partial line');

        $this->actingAs($this->owner)->post(route('finance.budgets.phase', [$b, $line]), ['phasing_method' => 'custom', 'amounts' => $short])->assertSessionHasErrors('amounts');
        $this->post(route('finance.budgets.phase', [$b, $line]), ['phasing_method' => 'custom', 'amounts' => $even])->assertSessionHasNoErrors();

        // a budget with a mis-phased line cannot be submitted
        $line->periods()->first()->update(['amount' => '1.00']);
        $this->assertRefused(fn () => $svc->submit($b->refresh(), $this->owner), null, 'not fully phased');
    }

    /** FIN-01.05, D8: foreign-currency lines convert at the budget rate on the FY start, falling back to spot. */
    public function test_foreign_currency_line_stores_rate_type_and_date(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $line = $svc->addLine($b, $this->lineData(['annual_amount' => '10000', 'currency' => 'USD']), $this->owner);
        $this->assertSame('budget', $line->fx_rate_type);
        $this->assertSame('2026-12-31', $line->fx_rate_date->toDateString());
        $this->assertSame('1400.00000000', (string) $line->fx_rate);
        $this->assertSame('14000000.00', (string) $line->base_annual_amount);
        $this->assertSame('14000000.00', $this->sum(BudgetLinePeriod::where('budget_line_id', $line->id)->pluck('base_amount')));
        $this->assertSame('10000.00', $this->sum(BudgetLinePeriod::where('budget_line_id', $line->id)->pluck('amount')));

        // editing the amount keeps the struck rate; the base follows
        $svc->updateLine($line, ['annual_amount' => '20000'], $this->owner);
        $this->assertSame('1400.00000000', (string) $line->refresh()->fx_rate);
        $this->assertSame('28000000.00', (string) $line->base_annual_amount);

        // a rate struck after the FY start is ignored; without a budget rate the spot rate is used and reported as spot
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1700, 'rate_date' => '2027-06-01', 'rate_type' => 'budget']);
        FxRate::where('rate_type', 'budget')->delete();
        $fallback = $svc->addLine($b, $this->lineData(['annual_amount' => '100', 'currency' => 'USD']), $this->owner);
        $this->assertSame('spot', $fallback->fx_rate_type);
        $this->assertSame('1500.00000000', (string) $fallback->fx_rate);

        $this->assertRefused(fn () => $svc->addLine($b, $this->lineData(['currency' => 'EUR']), $this->owner), 'currency');
        $this->assertSame('NGN', $svc->addLine($b, $this->lineData(['description' => 'Naira']), $this->owner)->currency);
        $this->assertSame('1.00000000', (string) $b->lines()->where('description', 'Naira')->first()->fx_rate);
    }

    /** FIN-02.03: a ceiling breach flags the scope and the lowest-priority lines that cross it. */
    public function test_ceiling_breach_flags_scope_and_lines(): void
    {
        $svc = app(BudgetService::class);
        $cycles = app(BudgetCycleService::class);
        $cycle = $this->openCycle();
        $b = $svc->createVersion($cycle, $this->cc, 'zero_based', $this->owner);
        $discretionary = $svc->addLine($b, $this->lineData(['description' => 'Nice to have', 'annual_amount' => '600000', 'priority' => 'discretionary', 'sort_order' => 10]), $this->owner);
        $mandatory = $svc->addLine($b, $this->lineData(['description' => 'Must pay', 'annual_amount' => '700000', 'priority' => 'mandatory', 'sort_order' => 20]), $this->owner);
        $regulatory = $svc->addLine($b, $this->lineData(['description' => 'CBN return', 'annual_amount' => '200000', 'priority' => 'regulatory', 'sort_order' => 30]), $this->owner);

        $this->assertFalse($svc->checkCeiling($b->refresh())['over'], 'no target, no breach');
        $target = $cycles->saveTarget($cycle, ['cost_centre_id' => $this->cc->id, 'opex_ceiling' => '1000000', 'capex_ceiling' => '500000'], $this->fin);
        $this->assertRefused(fn () => $cycles->saveTarget($cycle, ['cost_centre_id' => $this->cc->id, 'opex_ceiling' => '1'], $this->fin), 'cost_centre_id');
        $this->assertRefused(fn () => $cycles->saveTarget($cycle, ['cost_centre_id' => $this->cc2->id], $this->fin), 'capex_ceiling');

        $c = $svc->checkCeiling($b->refresh());
        $this->assertTrue($c['over']);
        $this->assertSame([$discretionary->id], $c['over_line_ids'], 'mandatory and regulatory fit first; discretionary crosses the line');
        $row = $c['targets'][0];
        $this->assertSame('1500000.00', $row['opex_requested']);
        $this->assertTrue($row['opex_over']);
        $this->assertFalse($row['capex_over']);

        // a CapEx line over the CapEx ceiling is flagged on its own axis
        $capex = $svc->addLine($b, $this->lineData(['description' => 'Servers', 'annual_amount' => '600000', 'is_capex' => true, 'priority' => 'committed']), $this->owner);
        $c = $svc->checkCeiling($b->refresh());
        $this->assertEqualsCanonicalizing([$discretionary->id, $capex->id], $c['over_line_ids']);

        // within the ceiling again: no flag
        $svc->updateLine($discretionary, ['annual_amount' => '100000'], $this->owner);
        $svc->updateLine($capex, ['annual_amount' => '400000'], $this->owner);
        $c = $svc->checkCeiling($b->refresh());
        $this->assertFalse($c['over']);
        $this->assertSame([], $c['over_line_ids']);

        // the Show page exposes the breach to the UI
        $svc->updateLine($discretionary, ['annual_amount' => '900000'], $this->owner);
        $this->actingAs($this->owner)->get(route('finance.budgets.show', $b))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('ceiling.over', true)->where('ceiling.over_line_ids', [$discretionary->id])
            ->where('lines', fn ($lines) => collect($lines)->firstWhere('id', $discretionary->id)['over_ceiling'] === true
                && collect($lines)->firstWhere('id', $mandatory->id)['over_ceiling'] === false));
        $this->assertSame($target->id, $c['targets'][0]['id']);
        $this->assertNotNull($regulatory);
    }

    /** FIN-02.03: a parent cost-centre target sums every child's latest live version; withdrawn ones drop out. */
    public function test_ceiling_on_a_parent_cost_centre_covers_its_children(): void
    {
        $svc = app(BudgetService::class);
        $cycles = app(BudgetCycleService::class);
        $parent = CostCentre::create(['code' => 'IT', 'name' => 'IT Division', 'owner_id' => $this->owner->id]);
        $this->cc->forceFill(['parent_id' => $parent->id])->save();
        $this->cc2->forceFill(['parent_id' => $parent->id])->save();
        $cycle = $this->openCycle();
        $a = $this->draft($this->cc, '700000');
        $b = $this->draft($this->cc2, '600000');
        $cycles->saveTarget($cycle, ['cost_centre_id' => $parent->id, 'opex_ceiling' => '1000000'], $this->fin);
        $cycles->saveTarget($cycle, ['category_id' => $this->cat('TW-APP'), 'opex_ceiling' => '1200000'], $this->fin);

        $c = $svc->checkCeiling($b->refresh());
        $this->assertTrue($c['over']);
        $this->assertCount(2, $c['targets']);
        $this->assertSame('1300000.00', $c['targets'][0]['opex_requested']);
        $this->assertNotEmpty($c['over_line_ids']);
        $this->assertTrue($svc->checkCeiling($a->refresh())['over']);

        $usage = $cycles->targetUsage($cycle);
        $this->assertCount(2, $usage);
        $this->assertTrue((bool) collect($usage)->firstWhere('opex_requested', '1300000.00')['opex_over']);

        DB::table('fin_budgets')->where('id', $b->id)->update(['status' => 'withdrawn']);
        $this->assertFalse($svc->checkCeiling($a->refresh())['targets'][0]['opex_over'], 'a withdrawn version no longer counts');
        $this->assertSame(0, \count($svc->checkCeiling($a)['over_line_ids']));

        $this->actingAs($this->fin)->get(route('finance.targets.index', ['cycle_id' => $cycle->id]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Finance/Budgets/Targets')->has('targets', 2));
    }

    /** FIN-02.13: export, re-import the same file (nothing changes), then edit two rows and add one. */
    public function test_workbook_csv_round_trip(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $svc->addLine($b, $this->lineData(['description' => '=SUM(1+1)', 'annual_amount' => '1000.01', 'currency' => 'USD', 'phasing_method' => 'seasonal', 'priority' => 'mandatory']), $this->owner);
        $svc->addLine($b, $this->lineData(['description' => 'Servers', 'cost_pool_id' => $this->cat('CP-HW'), 'tower_id' => $this->cat('TW-CMP'), 'annual_amount' => '999999.99', 'phasing_method' => 'back', 'is_recurring' => true]), $this->owner);
        $b->refresh();

        $csv = $this->exported($b);
        [$header, $rows] = $this->parseCsv($csv);
        $this->assertSame(['line_no', 'description', 'cost_pool', 'tower', 'category', 'gl_account', 'is_capex', 'is_recurring', 'priority', 'rgt', 'vendor', 'currency', 'quantity', 'unit_cost', 'annual_amount', 'phasing_method', 'justification'], array_slice($header, 0, 17));
        $this->assertSame(array_map(fn ($i) => 'P'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 12)), array_slice($header, 17));
        $this->assertCount(3, $rows);
        $this->assertSame("'=SUM(1+1)", $rows[1]['description'], 'formula-looking text is neutralised on export');
        $this->assertSame('USD', $rows[1]['currency']);
        $this->assertSame('Y', $rows[2]['is_capex']);
        $this->assertSame(bcadd($rows[0]['annual_amount'], '0', 2), $this->sum(array_map(fn ($i) => $rows[0]['P'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)], range(1, 12))));
        $this->actingAs($this->owner)->get(route('finance.budgets.workbook', $b))->assertDownload("{$b->budget_code}-v1.csv");

        // untouched re-import: everything is unchanged and nothing is written
        $stamps = BudgetLine::where('budget_id', $b->id)->pluck('updated_at', 'id');
        $before = Activity::count();
        $this->travel(5)->minutes();
        $this->upload($b, $csv)->assertSessionHasNoErrors()->assertSessionHas('import_report', fn ($r) => $r['type'] === 'budget_workbook'
            && $r['row_count'] === 3 && $r['unchanged'] === 3 && $r['created'] === 0 && $r['updated'] === 0 && $r['rejected_count'] === 0 && $r['errors'] === []);
        $this->assertEquals($stamps->all(), BudgetLine::where('budget_id', $b->id)->pluck('updated_at', 'id')->all(), 'no line was rewritten');
        $this->assertSame($before, Activity::count(), 'an all-unchanged import leaves no audit rows');

        // edit two annual amounts (blank the period cells so they re-phase) and append one new row
        $blank = array_fill_keys(array_slice($header, 17), '');
        $rows[0] = array_merge($rows[0], $blank, ['annual_amount' => '1500000.00']);
        $rows[2] = array_merge($rows[2], $blank, ['annual_amount' => '777777.77']);
        $rows[] = array_merge(array_fill_keys($header, ''), ['description' => 'New SOC retainer', 'cost_pool' => 'CP-OS', 'tower' => 'TW-SEC', 'annual_amount' => '240000', 'priority' => 'committed']);
        $this->upload($b, $this->toCsv($header, $rows))->assertSessionHasNoErrors()->assertSessionHas('import_report', fn ($r) => $r['updated'] === 2 && $r['created'] === 1
            && $r['unchanged'] === 1 && $r['accepted_count'] === 4 && $r['rejected_count'] === 0);

        $b->refresh();
        $lines = $b->lines()->orderBy('line_no')->get();
        $this->assertCount(4, $lines);
        $this->assertSame('1500000.00', (string) $lines[0]->annual_amount);
        $this->assertSame('1500000.00', $this->sum($lines[0]->periods()->pluck('amount')));
        $this->assertSame('777777.77', (string) $lines[2]->annual_amount);
        $this->assertSame('New SOC retainer', $lines[3]->description);
        $this->assertSame('240000.00', (string) $lines[3]->annual_amount);
        $this->assertSame('committed', $lines[3]->priority);
        $this->assertSame('=SUM(1+1)', $lines[1]->description, 'the apostrophe guard is stripped on the way back in');
        $this->assertSame(1, Activity::where('event', 'workbook_imported')->count());
        $this->assertSame($this->sum([$lines[0]->base_annual_amount, $lines[1]->base_annual_amount, $lines[2]->base_annual_amount, $lines[3]->base_annual_amount]), (string) $b->total_base_amount);
    }

    /** FIN-02.13: any bad row rejects the whole file, the report names the rows, and nothing is written. */
    public function test_workbook_import_is_all_or_nothing_with_report(): void
    {
        $b = $this->draft();
        app(BudgetService::class)->addLine($b, $this->lineData(['description' => 'Second', 'annual_amount' => '50000']), $this->owner);
        [$header, $rows] = $this->parseCsv($this->exported($b));
        $blank = array_fill_keys(array_slice($header, 17), '');
        $snapshot = fn () => BudgetLine::where('budget_id', $b->id)->orderBy('line_no')->pluck('annual_amount', 'line_no')->map(fn ($a) => (string) $a)->all();
        $original = $snapshot();

        // (a) validation errors found before writing: unknown code (file row 3) and a bad number (file row 4)
        $bad = [array_merge($rows[0], $blank, ['annual_amount' => '9999999']),
            array_merge($rows[1], ['cost_pool' => 'CP-NOPE']),
            array_merge($rows[1], ['line_no' => '', 'description' => 'Another', 'annual_amount' => 'twelve'])];
        $this->upload($b, $this->toCsv($header, $bad), 'bad.csv')->assertSessionHas('error')->assertSessionHas('import_report', function ($r) {
            return $r['rejected_count'] === 2 && $r['accepted_count'] === 0 && $r['created'] === 0 && $r['updated'] === 0
                && collect($r['errors'])->pluck('row')->all() === [3, 4] && str_contains($r['errors'][0]['message'], 'CP-NOPE')
                && $r['file_name'] === 'bad.csv';
        });
        $this->assertSame($original, $snapshot());

        // (b) a refusal found while applying (custom phasing that does not add up) rolls back the earlier good row
        $bad = [array_merge($rows[0], $blank, ['annual_amount' => '9999999']),
            array_merge($rows[1], $blank, ['phasing_method' => 'custom', 'annual_amount' => '50000', 'P01' => '1'])];
        $this->upload($b, $this->toCsv($header, $bad))->assertSessionHas('import_report', fn ($r) => $r['rejected_count'] === 1
            && $r['updated'] === 0 && $r['created'] === 0 && $r['errors'][0]['row'] === 3);
        $this->assertSame($original, $snapshot(), 'the good first row was rolled back with the bad second row');

        // structural problems and unsupported shapes
        $this->upload($b, "foo,bar\n1,2\n")->assertSessionHasErrors('file');
        $this->upload($b, "line_no,description,nonsense\n1,x,y\n")->assertSessionHasErrors('file');
        $dup = $this->toCsv($header, [$rows[0], $rows[0]]);
        $this->upload($b, $dup)->assertSessionHas('import_report', fn ($r) => str_contains($r['errors'][0]['message'], 'Duplicate line_no'));

        // locked versions refuse the import; viewers without edit rights cannot upload
        $locked = $this->baselined($this->cc2);
        $this->actingAs($this->owner2)->post(route('finance.budgets.workbook.import', $locked), ['file' => UploadedFile::fake()->createWithContent('w.csv', $this->toCsv($header, [$rows[0]]))])->assertSessionHasErrors('workflow');
        $this->actingAs($this->cfo)->post(route('finance.budgets.workbook.import', $b), ['file' => UploadedFile::fake()->createWithContent('w.csv', $this->toCsv($header, [$rows[0]]))])->assertForbidden();
    }

    /** FIN-02.13: the grid save validates per cell and is all-or-nothing. */
    public function test_bulk_grid_save_validates_per_cell(): void
    {
        $b = $this->draft();
        $first = $this->firstLine($b);
        $ids = $this->periods()->pluck('id');
        $good = $this->lineData(['description' => 'Grid line', 'annual_amount' => '120000']);
        $mismatch = $this->lineData(['description' => 'Bad phasing', 'annual_amount' => '1000', 'phasing_method' => 'custom', 'amounts' => $ids->mapWithKeys(fn ($i) => [$i => '1'])->all()]);
        $badPool = $this->lineData(['description' => 'Bad pool', 'cost_pool_id' => $this->cat('TW-APP')]);

        $this->actingAs($this->owner)->put(route('finance.budgets.lines.bulk', $b), ['lines' => [$good, $mismatch, $badPool], 'deleted_ids' => [$first->id]])
            ->assertSessionHasErrors(['lines.1.amounts', 'lines.2.cost_pool_id']);
        $this->assertSame(1, $b->lines()->count(), 'nothing from the grid was saved');
        $this->assertNotNull(BudgetLine::find($first->id), 'and the delete was rolled back');

        $this->put(route('finance.budgets.lines.bulk', $b), ['lines' => [['id' => $first->id, 'annual_amount' => '2400000'], $good], 'deleted_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame(2, $b->lines()->count());
        $this->assertSame('2400000.00', (string) $first->refresh()->annual_amount);
        $this->assertSame('2520000.00', (string) $b->refresh()->total_base_amount);

        $this->put(route('finance.budgets.lines.bulk', $b), ['lines' => [], 'deleted_ids' => [$first->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, $b->lines()->count());
        $this->put(route('finance.budgets.lines.bulk', $b), ['lines' => [['id' => 999999, 'annual_amount' => '1']], 'deleted_ids' => []])->assertSessionHasErrors('lines.0.id');
    }

    /** FIN-02.07: scenarios compare totals without touching lines; promotion re-scales and re-phases exactly. */
    public function test_scenarios_compare_and_promote(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft(null, '1000000');
        $second = $svc->addLine($b, $this->lineData(['description' => 'Seasonal', 'annual_amount' => '500000', 'phasing_method' => 'seasonal']), $this->owner);
        $first = $this->firstLine($b);

        $this->actingAs($this->owner)->post(route('finance.budgets.scenarios.store', $b), ['name' => 'Growth +10 %', 'adjustment_pct' => 10, 'line_adjustments' => [$second->id => -50]])->assertSessionHasNoErrors();
        $this->post(route('finance.budgets.scenarios.store', $b), ['name' => 'Cut 10 %', 'adjustment_pct' => -10])->assertSessionHasNoErrors();
        $this->post(route('finance.budgets.scenarios.store', $b), ['name' => 'Absurd', 'adjustment_pct' => 900])->assertSessionHasErrors('adjustment_pct');
        $growth = $b->scenarios()->where('name', 'Growth +10 %')->first();
        $cut = $b->scenarios()->where('name', 'Cut 10 %')->first();
        $this->assertSame('1350000.00', $svc->scenarioTotal($b, $growth)); // 1,000,000 +10 % and 500,000 -50 %
        $this->assertSame('1350000.00', $svc->scenarioTotal($b, $cut));
        $this->assertSame('1500000.00', (string) $b->refresh()->total_base_amount, 'comparing scenarios changes nothing');

        $this->assertRefused(fn () => $svc->scenarioSave($b, ['name' => 'Outside', 'adjustment_pct' => 1, 'line_adjustments' => [999999 => 5]]), 'line_adjustments');

        $this->post(route('finance.budgets.scenarios.promote', [$b, $cut]))->assertSessionHasNoErrors();
        $this->assertTrue($cut->refresh()->is_promoted);
        $this->assertSame('900000.00', (string) $first->refresh()->annual_amount);
        $this->assertSame('450000.00', (string) $second->refresh()->annual_amount);
        foreach ([$first, $second] as $line) {
            $this->assertSame((string) $line->annual_amount, $this->sum(BudgetLinePeriod::where('budget_line_id', $line->id)->pluck('amount')));
            $this->assertSame((string) $line->base_annual_amount, $this->sum(BudgetLinePeriod::where('budget_line_id', $line->id)->pluck('base_amount')));
        }
        $this->assertSame('1350000.00', (string) $b->refresh()->total_base_amount);
        $this->assertRefused(fn () => $svc->scenarioSave($b, ['name' => 'x', 'adjustment_pct' => 1], $cut->refresh()), 'workflow', 'promoted');

        $svc->promoteScenario($growth->refresh(), $this->owner);
        $this->assertFalse($cut->refresh()->is_promoted, 'only one scenario is promoted at a time');
        $this->assertTrue($growth->refresh()->is_promoted);

        $svc->submit($b->refresh(), $this->owner);
        $this->assertRefused(fn () => $svc->scenarioSave($b->refresh(), ['name' => 'Late', 'adjustment_pct' => 1]), 'workflow');
    }

    /** Orchestrator decision: the workbook is CSV only; XLSX is refused with 422. */
    public function test_workbook_xlsx_is_refused_with_422(): void
    {
        $b = $this->draft();
        $this->actingAs($this->owner)->getJson(route('finance.budgets.workbook', [$b, 'format' => 'xlsx']))->assertStatus(422)->assertJsonValidationErrors('format');
        $this->get(route('finance.budgets.workbook', [$b, 'format' => 'xlsx']))->assertSessionHasErrors('format');
        $this->get(route('finance.budgets.workbook', [$b, 'format' => 'csv']))->assertOk();
        $this->get(route('finance.budgets.workbook', $b))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($this->owner2)->get(route('finance.budgets.workbook', $b))->assertForbidden();
    }
}
