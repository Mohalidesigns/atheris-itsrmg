<?php

namespace Tests\Feature\Finance;

use App\Models\Approvals\ApprovalWorkflowStage;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\DoaBandVersion;
use App\Models\Finance\FxRate;
use App\Models\Finance\Setting;
use App\Models\Finance\SupplementaryRequest;
use App\Models\Organization;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\SupplementaryBudgetService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsBudgets;
use Tests\TestCase;

/** Deferred P0 items closed in P1: DoA maker-checker and versions (S7/D5/C8/C29), FX rate types (D8), base-currency guard (S8/C13). */
class FinanceDoaFxAndCurrencyGuardTest extends TestCase
{
    use BuildsBudgets, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBudgets();
        $this->admin = $this->userWith('Organization Admin');
    }

    private function bands(array $thresholds): array
    {
        $out = [];
        foreach ($thresholds as $i => $min) {
            $out[] = ['label' => 'Band '.($i + 1), 'min' => $min, 'max' => $thresholds[$i + 1] ?? null];
        }

        return $out;
    }

    private function activityFor(string $event)
    {
        return Activity::where('log_name', 'it-finance')->where('event', $event)->orderBy('id')->get();
    }

    /** S7/D5: the person who proposes new bands cannot approve them; every step is on the audit log with old and new bands. */
    public function test_band_change_needs_a_second_person_and_is_audited(): void
    {
        $svc = app(DoaBandService::class);
        $new = $this->bands([0, 5000000, 20000000]);
        $old = $svc->active($this->org->id);
        $this->assertSame(1, $old->version_no);

        $this->assertRefused(fn () => $svc->propose($this->org->id, $new, today()->subDay()->toDateString(), null, $this->fin), 'effective_from');
        $this->assertRefused(fn () => $svc->propose($this->org->id, [['label' => 'A', 'min' => 0, 'max' => 5000000], ['label' => 'B', 'min' => 6000000, 'max' => null]], today()->toDateString(), null, $this->fin), 'doa_bands');

        $v = $svc->propose($this->org->id, $new, today()->toDateString(), 'Board resolution 14', $this->fin);
        $this->assertSame('pending', $v->status);
        $this->assertSame(['0-5m', '5m-20m', '20m+'], array_column($v->bands, 'key'));
        $this->assertSame(['0-1m', '1m-10m', '10m-50m', '50m-250m', '250m+'], array_column($svc->active($this->org->id)->bands, 'key'), 'still the old bands while pending');
        $this->assertRefused(fn () => $svc->propose($this->org->id, $new, today()->toDateString(), null, $this->fin), 'workflow', 'already waiting');

        $this->assertRefused(fn () => $svc->approve($v, $this->fin), 'workflow', 'Maker-checker');
        $this->assertRefused(fn () => $svc->reject($v, $this->fin, 'no'), 'workflow', 'Maker-checker');
        $foreign = $this->userWith('Finance Approver', [], Organization::factory()->create()->id);
        $this->assertRefused(fn () => $svc->approve($v, $foreign), 'workflow', 'another bank');
        $this->assertRefused(fn () => $svc->reject($v, $this->cfo, ''), 'note');
        $this->assertRefused(fn () => $svc->withdraw($v, $this->cfo), 'workflow', 'proposed the bands');
        $this->actingAs($this->fin)->post(route('finance.doa-bands.approve', $v))->assertForbidden();
        $this->actingAs($this->owner)->post(route('finance.doa-bands.store'), ['bands' => $new, 'effective_from' => today()->toDateString()])->assertForbidden();

        $svc->approve($v, $this->cfo, 'Agreed');
        $v->refresh();
        $this->assertSame('active', $v->status);
        $this->assertSame($this->cfo->id, $v->decided_by_id);
        $this->assertSame('superseded', $old->refresh()->status);
        $this->assertSame(['0-5m', '5m-20m', '20m+'], array_column(Setting::where('organization_id', $this->org->id)->first()->doa_bands, 'key'));
        $this->assertSame('5m-20m', app(FinanceSettings::class)->bandFor($this->org->id, 5000000));
        $this->assertRefused(fn () => $svc->approve($v, $this->cfo), 'workflow', 'active band version cannot be decided');

        $this->assertCount(1, $this->activityFor('doa_bands.proposed')->where('causer_id', $this->fin->id));
        $approved = $this->activityFor('doa_bands.approved')->first();
        $this->assertSame($this->fin->id, $approved->properties['maker_id']);
        $this->assertSame($this->cfo->id, $approved->properties['checker_id']);
        $activated = $this->activityFor('doa_bands.activated')->last();
        $this->assertSame(['0-1m', '1m-10m', '10m-50m', '50m-250m', '250m+'], array_column($activated->properties['old_bands'], 'key'));
        $this->assertSame(['0-5m', '5m-20m', '20m+'], array_column($activated->properties['new_bands'], 'key'));
        $this->assertSame($this->fin->id, $activated->properties['maker_id']);
        $this->assertSame($this->cfo->id, $activated->properties['checker_id']);

        // through the screens: settings can no longer overwrite bands, proposals come from settings managers, rejection needs a note
        $this->actingAs($this->admin)->put(route('finance.settings.update'), ['section' => 'doa', 'doa_bands' => $new])->assertSessionHasErrors('workflow');
        $this->post(route('finance.doa-bands.store'), ['bands' => $this->bands([0, 1000000]), 'effective_from' => today()->toDateString(), 'reason' => 'Tighten'])->assertSessionHasNoErrors();
        $second = DoaBandVersion::where('status', 'pending')->firstOrFail();
        $this->assertSame(3, $second->version_no);
        $this->actingAs($this->cfo)->post(route('finance.doa-bands.reject', $second), ['note' => ''])->assertSessionHasErrors('note');
        $this->post(route('finance.doa-bands.reject', $second), ['note' => 'Not now'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $second->refresh()->status);
        $this->assertSame('Not now', $second->decision_note);
        $this->assertNotEmpty($this->activityFor('doa_bands.rejected'));

        // the maker can withdraw their own proposal and then propose again
        $third = $svc->propose($this->org->id, $this->bands([0, 2000000]), today()->toDateString(), null, $this->fin);
        $svc->withdraw($third, $this->fin);
        $this->assertSame('withdrawn', $third->refresh()->status);
        $this->assertSame('pending', $svc->propose($this->org->id, $this->bands([0, 3000000]), today()->toDateString(), null, $this->fin)->status);
    }

    /** C8/C29: a future-dated version waits for its date, then activates and re-maps stage conditions by band position. */
    public function test_future_effective_version_activates_on_its_date_and_remaps_stage_conditions(): void
    {
        $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(DoaBandService::class);
        $md = ApprovalWorkflowStage::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'MD/CEO approval')->firstOrFail();
        $cio = ApprovalWorkflowStage::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'CIO approval')->where('condition_field', 'amount_band')->firstOrFail();
        $this->assertSame(['250m+'], $md->condition_values);
        $this->assertSame(['50m-250m', '250m+'], $cio->condition_values);

        $new = $this->bands([0, 2000000, 20000000, 100000000, 400000000]);
        $v = $svc->propose($this->org->id, $new, '2027-01-01', 'FY2027 limits', $this->fin);
        $svc->approve($v, $this->cfo);
        $this->assertSame('scheduled', $v->refresh()->status);
        $this->assertSame('1m-10m', $svc->bandFor($this->org->id, 5000000)['key'], 'old bands until the date');
        $this->assertSame(['250m+'], $md->refresh()->condition_values);

        $this->assertSame(0, $svc->activateDue());
        $this->assertSame(0, Artisan::call('fin:doa-activate'));
        $this->assertStringContainsString('0 DoA band version(s) activated', Artisan::output());
        $this->assertSame('scheduled', $v->refresh()->status);

        $this->travelTo(Carbon::parse('2027-01-01 00:10:00'));
        $this->assertSame(0, Artisan::call('fin:doa-activate'));
        $this->assertStringContainsString('1 DoA band version(s) activated', Artisan::output());
        $v->refresh();
        $this->assertSame('active', $v->status);
        $this->assertNotNull($v->activated_at);
        $this->assertSame(1, DoaBandVersion::where('status', 'superseded')->count());
        $this->assertSame('2m-20m', $svc->bandFor($this->org->id, 5000000)['key']);
        $this->assertSame('400m+', $svc->bandFor($this->org->id, 500000000)['key']);
        $this->assertSame(0, Artisan::call('fin:doa-activate'), 'running again activates nothing');
        $this->assertStringContainsString('0 DoA band version(s)', Artisan::output());

        // stage conditions follow the position: 5th band 250m+ became 400m+, 4th band 50m-250m became 100m-400m
        $this->assertSame(['400m+'], $md->refresh()->condition_values);
        $this->assertSame(['100m-400m', '400m+'], $cio->refresh()->condition_values);
        $remaps = $this->activityFor('doa_bands.activated')->last()->properties['remapped_stages'];
        $this->assertContains(['stage_id' => $md->id, 'from' => '250m+', 'to' => '400m+'], $remaps);
        $this->assertContains(['stage_id' => $cio->id, 'from' => '50m-250m', 'to' => '100m-400m'], $remaps);
    }

    /** C29: approval is refused while a stage is limited to a band the new bands remove. */
    public function test_approval_refused_when_a_stage_uses_a_removed_band(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(DoaBandService::class);
        $v = $svc->propose($this->org->id, $this->bands([0, 5000000, 20000000]), today()->toDateString(), 'Fewer bands', $this->fin);

        $e = $this->assertRefused(fn () => $svc->approve($v, $this->cfo), 'workflow', 'MD/CEO approval');
        $this->assertStringContainsString('250m+', $e->errors()['workflow'][0]);
        $this->assertSame('pending', $v->refresh()->status);
        $this->assertSame(1, $svc->active($this->org->id)->version_no);

        ApprovalWorkflowStage::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('condition_field', 'amount_band')->get()
            ->each(fn ($s) => $s->forceFill(['condition_values' => ['1m-10m']])->save());
        $svc->approve($v->refresh(), $this->cfo);
        $this->assertSame('active', $v->refresh()->status);
    }

    /** D8: convert() returns the rate type and rate date it used, with a reported fallback to spot. */
    public function test_convert_returns_rate_type_and_rate_date_with_fallback(): void
    {
        $fx = app(CurrencyService::class);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1450, 'rate_date' => '2026-09-30', 'rate_type' => 'closing']);
        FxRate::create(['from_currency' => 'EUR', 'to_currency' => 'NGN', 'rate' => 1700, 'rate_date' => '2026-03-01', 'rate_type' => 'spot']);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1700, 'rate_date' => '2027-06-01', 'rate_type' => 'budget']);
        $on = Carbon::parse('2027-01-01');

        $c = $fx->convert($this->org->id, '100', 'USD', $on, 'budget');
        $this->assertSame(['amount' => '100.00', 'currency' => 'USD', 'fx_rate' => '1400.00000000', 'base_amount' => '140000.00', 'rate_type' => 'budget', 'rate_date' => '2026-12-31'], $c);

        $closing = $fx->convert($this->org->id, '100', 'USD', $on, 'closing');
        $this->assertSame(['closing', '2026-09-30', '1450.00000000'], [$closing['rate_type'], $closing['rate_date'], $closing['fx_rate']]);

        $spotDefault = $fx->convert($this->org->id, '100', 'USD', $on);
        $this->assertSame(['spot', '2026-01-01', '1500.00000000'], [$spotDefault['rate_type'], $spotDefault['rate_date'], $spotDefault['fx_rate']]);

        // fallbacks are reported: no average rate exists, so the spot rate answers and says so
        $average = $fx->convert($this->org->id, '100', 'USD', $on, 'average');
        $this->assertSame(['spot', '1500.00000000'], [$average['rate_type'], $average['fx_rate']]);
        // a budget rate struck before the date is preferred; before any budget rate exists spot is used
        $early = $fx->convert($this->org->id, '100', 'USD', Carbon::parse('2026-12-30'), 'budget');
        $this->assertSame(['spot', '2026-01-01'], [$early['rate_type'], $early['rate_date']]);
        // rates struck after the date are never used
        $this->assertSame('1400.00000000', $fx->convert($this->org->id, '1', 'USD', Carbon::parse('2027-05-31'), 'budget')['fx_rate']);
        $this->assertSame('1700.00000000', $fx->convert($this->org->id, '1', 'USD', Carbon::parse('2027-06-01'), 'budget')['fx_rate']);

        // same currency, inverse and triangulated quotes
        $this->assertSame(['rate' => '1.00000000', 'rate_date' => '2027-01-01', 'rate_type' => 'budget'], $fx->quote($this->org->id, 'NGN', 'NGN', $on, 'budget'));
        $inverse = $fx->quote($this->org->id, 'NGN', 'USD', $on, 'budget');
        $this->assertSame(['0.00071429', '2026-12-31', 'budget'], [$inverse['rate'], $inverse['rate_date'], $inverse['rate_type']]);
        $tri = $fx->quote($this->org->id, 'USD', 'EUR', $on, 'spot');
        $this->assertSame('2026-01-01', $tri['rate_date'], 'a triangulated rate carries the older leg\'s date');
        $this->assertSame(bcdiv('1500', '1700', 8), $tri['rate']);

        $this->assertRefused(fn () => $fx->convert($this->org->id, '1', 'GBP', $on, 'budget'), 'currency');
        $this->assertNull($fx->quote($this->org->id, 'GBP', 'NGN', $on, 'budget'));
        $this->assertRefused(fn () => $fx->convert($this->org->id, '1e3', 'USD', $on), 'amount');
    }

    /** D8: one rate per pair, date and type; the import accepts a rate_type column. */
    public function test_fx_rates_unique_per_type_and_import_accepts_rate_type(): void
    {
        $this->assertSame(2, FxRate::where('from_currency', 'USD')->where('rate_date', '<=', '2026-12-31')->count(), 'spot and budget rates coexist for the same pair');
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1450, 'rate_date' => '2026-01-01', 'rate_type' => 'closing']);
        $this->assertSame(3, FxRate::where('from_currency', 'USD')->count());
        try {
            DB::transaction(fn () => FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1, 'rate_date' => '2026-01-01', 'rate_type' => 'closing']));
            $this->fail('the same pair, date and type must be unique');
        } catch (QueryException) {
            $this->assertSame(3, FxRate::where('from_currency', 'USD')->count());
        }

        // manual entry upserts on pair + date + type
        $this->actingAs($this->admin);
        $row = ['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate_date' => '2026-02-01'];
        $this->post(route('finance.fx-rates.store'), $row + ['rate' => 1510, 'rate_type' => 'average'])->assertSessionHasNoErrors();
        $this->post(route('finance.fx-rates.store'), $row + ['rate' => 1520, 'rate_type' => 'average'])->assertSessionHasNoErrors();
        $this->post(route('finance.fx-rates.store'), $row + ['rate' => 1530])->assertSessionHasNoErrors();
        $this->assertSame(1, FxRate::where('rate_date', '2026-02-01')->where('rate_type', 'average')->count());
        $this->assertSame('1520.00000000', (string) FxRate::where('rate_date', '2026-02-01')->where('rate_type', 'average')->value('rate'));
        $this->assertSame('spot', FxRate::where('rate_date', '2026-02-01')->where('rate_type', '!=', 'average')->value('rate_type'), 'omitting the type means spot');
        $this->post(route('finance.fx-rates.store'), $row + ['rate' => 1540, 'rate_type' => 'forward'])->assertSessionHasErrors('rate_type');

        // import: types per row, default spot, unknown type rejected, re-import is idempotent
        $csv = "from_currency,to_currency,rate,rate_date,rate_type\nUSD,NGN,1405,2026-03-01,budget\nUSD,NGN,1455,2026-03-01,closing\nUSD,NGN,1500,2026-03-01,\nUSD,NGN,1600,2026-03-01,forward\n";
        $import = fn () => $this->post(route('finance.fx-rates.import'), ['file' => UploadedFile::fake()->createWithContent('fx.csv', $csv)]);
        $import()->assertSessionHas('import_report', fn ($r) => $r['row_count'] === 4 && $r['accepted_count'] === 3 && $r['rejected_count'] === 1
            && str_contains($r['errors'][0]['message'], 'rate_type'));
        $this->assertEqualsCanonicalizing(['budget', 'closing', 'spot'], FxRate::where('rate_date', '2026-03-01')->pluck('rate_type')->all());
        $before = FxRate::count();
        $import();
        $this->assertSame($before, FxRate::count());
        $this->assertSame('1405.00000000', (string) FxRate::where('rate_date', '2026-03-01')->where('rate_type', 'budget')->value('rate'));
        $old = "from_currency,to_currency,rate,rate_date\nUSD,NGN,1511,2026-04-01\n";
        $this->post(route('finance.fx-rates.import'), ['file' => UploadedFile::fake()->createWithContent('fx.csv', $old)])->assertSessionHas('import_report', fn ($r) => $r['accepted_count'] === 1);
        $this->assertSame('spot', FxRate::where('rate_date', '2026-04-01')->value('rate_type'), 'files without the column still import as spot');
    }

    /** S8/C13: the base currency can change only while no budget line, transfer or supplementary request exists. */
    public function test_base_currency_cannot_change_once_money_rows_exist(): void
    {
        $fx = app(CurrencyService::class);
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $ngn = Currency::where('code', 'NGN')->firstOrFail();

        // empty bank: free to change, and to change back
        $this->assertNull($fx->baseLockedReason($this->org->id));
        $fx->setBase($usd);
        $this->assertSame('USD', app(FinanceSettings::class)->baseCurrency($this->org->id));
        $fx->setBase($ngn->refresh());
        $this->assertSame('NGN', app(FinanceSettings::class)->baseCurrency($this->org->id));

        // a budget line locks it
        $this->draft();
        $this->assertStringContainsString('cannot change', $fx->baseLockedReason($this->org->id));
        $this->assertRefused(fn () => $fx->setBase($usd->refresh()), 'workflow', 'base currency cannot change');
        $this->actingAs($this->admin)->put(route('finance.currencies.update', $usd), ['name' => 'US dollar', 'decimals' => 2, 'is_base' => true])->assertSessionHasErrors('workflow');
        $this->assertSame(['NGN'], Currency::where('is_base', true)->pluck('code')->all());
        $this->actingAs($this->admin)->get(route('finance.settings', ['tab' => 'currencies']))->assertInertia(fn ($p) => $p->where('baseLockedReason', fn ($r) => str_contains((string) $r, 'cannot change'))->where('can.changeBase', false));

        // a soft-deleted supplementary request alone also counts: the money rows still exist
        $other = Organization::factory()->create(['currency' => 'NGN']);
        app(FinanceSettings::class)->ensureDefaults($other->id);
        $maker = $this->userWith('IT Finance Controller', [], $other->id);
        $this->actingAs($maker);
        $fy = app(FiscalCalendarService::class)->createYear($other->id, ['year_label' => 'FY2027', 'starts_on' => '2027-01-01', 'status' => 'open', 'is_current' => true], $maker);
        $cc = CostCentre::create(['code' => 'X-1', 'name' => 'Other bank', 'owner_id' => $maker->id]);
        $pool = Category::where('code', 'CP-SW')->value('id');
        $tower = Category::where('code', 'TW-SEC')->value('id');
        $otherUsd = Currency::where('code', 'USD')->firstOrFail();
        $this->assertNull($fx->baseLockedReason($other->id));
        $req = app(SupplementaryBudgetService::class)->create(['fiscal_year_id' => $fy->id, 'cost_centre_id' => $cc->id, 'cost_pool_id' => $pool, 'tower_id' => $tower,
            'category_id' => $tower, 'title' => 'Temp', 'currency' => 'NGN', 'amount' => '100', 'justification' => 'x'], $maker);
        $req->delete();
        $this->assertSame(0, SupplementaryRequest::count());
        $this->assertNotNull($fx->baseLockedReason($other->id), 'soft-deleted rows still lock the base currency');
        $this->assertRefused(fn () => $fx->setBase($otherUsd), 'workflow', 'base currency cannot change');

        // the first bank's lock does not leak to the second and vice versa
        $this->assertNull($fx->baseLockedReason(Organization::factory()->create()->id));
    }

    /** C29: stages that reached the old top band also reach every band added above it (5 to 6 bands). */
    public function test_remap_extends_from_x_upward_stages_to_new_top_bands(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(DoaBandService::class);
        $md = ApprovalWorkflowStage::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'MD/CEO approval')->firstOrFail();
        $cio = ApprovalWorkflowStage::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'CIO approval')->where('condition_field', 'amount_band')->firstOrFail();

        $v = $svc->propose($this->org->id, $this->bands([0, 2000000, 20000000, 100000000, 400000000, 900000000]), today()->toDateString(), 'Six bands', $this->fin);
        $svc->approve($v, $this->cfo);
        $this->assertSame('active', $v->refresh()->status);
        $keys = array_column($v->bands, 'key');
        $this->assertCount(6, $keys);
        $this->assertSame([$keys[4], $keys[5]], $md->refresh()->condition_values, 'MD/CEO now covers both top bands');
        $this->assertSame([$keys[3], $keys[4], $keys[5]], $cio->refresh()->condition_values);
        $this->assertSame($keys[5], $svc->bandFor($this->org->id, 950000000)['key']);
    }
}
