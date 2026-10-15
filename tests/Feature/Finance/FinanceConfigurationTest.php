<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\Driver;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Period;
use App\Models\Finance\Setting;
use App\Models\Finance\TaxType;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\TbmTaxonomy;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * IT Financial Management foundation (ATH-FIN-001 §12 FIN-01.xx): fiscal calendar, cost centres,
 * TBM taxonomy, GL codes, multi-currency, Nigerian tax defaults, DoA bands, variance and renewal
 * policy, dimension sources and the audit trail.
 */
class FinanceConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create(['currency' => 'NGN']);
        $this->other = Organization::factory()->create(['currency' => 'NGN']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'job_title' => 'CISO']);
        $this->admin->assignRole('Organization Admin');
        $this->otherAdmin = User::factory()->create(['organization_id' => $this->other->id]);
        $this->otherAdmin->assignRole('Organization Admin');
    }

    private function calendar(): FiscalCalendarService
    {
        return app(FiscalCalendarService::class);
    }

    private function year(string $label, string $start, string $scheme = 'monthly', string $status = 'open', bool $current = false): FiscalYear
    {
        return $this->calendar()->createYear($this->org->id, [
            'year_label' => $label, 'starts_on' => $start, 'period_scheme' => $scheme, 'status' => $status, 'is_current' => $current,
        ], $this->admin);
    }

    private function refused(callable $fn, string $key = 'workflow'): void
    {
        try {
            $fn();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    private function csv(string $name, string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** FIN-01.01 */
    public function test_monthly_fiscal_year_has_twelve_contiguous_periods(): void
    {
        $fy = $this->year('FY2027', '2027-01-01');
        $periods = $fy->periods()->orderBy('sequence')->get();

        $this->assertCount(12, $periods);
        $this->assertSame(range(1, 12), $periods->pluck('sequence')->all());
        for ($i = 1; $i < 12; $i++) {
            $this->assertSame($periods[$i - 1]->ends_on->addDay()->toDateString(), $periods[$i]->starts_on->toDateString());
        }
        $this->assertSame('2027-12-31', $periods[11]->ends_on->toDateString());
        $this->assertSame('2027-12-31', $fy->ends_on->toDateString());

        $thirteen = $this->year('FY2028-13', '2028-01-01', 'monthly_13');
        $p = $thirteen->periods()->orderBy('sequence')->get();
        $this->assertCount(13, $p);
        $this->assertSame($thirteen->ends_on->toDateString(), $p[12]->starts_on->toDateString());
        $this->assertSame($thirteen->ends_on->toDateString(), $p[12]->ends_on->toDateString());

        $retail = $this->year('FY2029-445', '2029-01-01', '4-4-5');
        $r = $retail->periods()->orderBy('sequence')->get();
        $this->assertCount(12, $r);
        $this->assertSame([28, 28, 35, 28, 28, 35, 28, 28, 35, 28, 28, 35], $r->map(fn (Period $x) => (int) $x->starts_on->diffInDays($x->ends_on) + 1)->all());
        $this->assertSame(364, (int) $r->first()->starts_on->diffInDays($r->last()->ends_on) + 1);
        $this->assertSame($r->last()->ends_on->toDateString(), $retail->ends_on->toDateString());
    }

    /** FIN-01.01 */
    public function test_fiscal_years_cannot_overlap_and_only_one_is_current(): void
    {
        $fy1 = $this->year('FY2027', '2027-01-01', current: true);
        $this->refused(fn () => $this->year('FY-OVER', '2027-07-01'));

        $fy2 = $this->year('FY2028', '2028-01-01', current: true);
        $this->assertFalse($fy1->refresh()->is_current);
        $this->assertTrue($fy2->refresh()->is_current);
        $this->assertSame(1, FiscalYear::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('is_current', true)->count());

        $this->refused(fn () => $this->year('FY2029', '2029-01-01', status: 'planning', current: true));
        $planning = $this->year('FY2030', '2030-01-01', status: 'planning');
        $this->refused(fn () => $this->calendar()->updateYear($planning, ['status' => 'planning', 'is_current' => true]));
    }

    /** FIN-01.01 */
    public function test_periods_close_in_order_and_lock_only_after_close(): void
    {
        $fy = $this->year('FY2027', '2027-01-01');
        [$p1, $p2] = [$fy->periods()->where('sequence', 1)->first(), $fy->periods()->where('sequence', 2)->first()];

        $this->refused(fn () => $this->calendar()->closePeriod($p2, $this->admin));
        $this->refused(fn () => $this->calendar()->lockPeriod($p1, $this->admin));

        $closed = $this->calendar()->closePeriod($p1, $this->admin);
        $this->assertSame('closed', $closed->status);
        $this->assertSame($this->admin->id, $closed->closed_by_id);
        $this->assertNotNull($closed->closed_at);

        $locked = $this->calendar()->lockPeriod($p1, $this->admin);
        $this->assertSame('locked', $locked->status);
        $this->assertSame($this->admin->id, $locked->locked_by_id);

        $planning = $this->year('FY2028', '2028-01-01', status: 'planning');
        $this->refused(fn () => $this->calendar()->closePeriod($planning->periods()->first(), $this->admin));

        $this->refused(fn () => $this->calendar()->updateYear($fy, ['status' => 'closed']));
        foreach ($fy->periods()->orderBy('sequence')->get() as $p) {
            if ($p->status === 'open') {
                $this->calendar()->closePeriod($p, $this->admin);
            }
        }
        $this->assertSame('closed', $this->calendar()->updateYear($fy->refresh(), ['status' => 'closed'])->status);
    }

    /** FIN-01.02 */
    public function test_cost_centre_hierarchy_and_csv_import(): void
    {
        $this->actingAs($this->admin);
        $foreign = CostCentre::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'code' => 'FOREIGN', 'name' => 'Other bank']);
        $this->post(route('finance.cost-centres.store'), ['code' => 'BAD', 'name' => 'Bad', 'parent_id' => $foreign->id])->assertSessionHasErrors('parent_id');

        $root = CostCentre::create(['code' => 'ROOT', 'name' => 'Root']);
        $child = CostCentre::create(['code' => 'CHILD', 'name' => 'Child', 'parent_id' => $root->id]);
        $grand = CostCentre::create(['code' => 'GRAND', 'name' => 'Grandchild', 'parent_id' => $child->id]);

        $this->put(route('finance.cost-centres.update', $root), ['code' => 'ROOT', 'name' => 'Root', 'parent_id' => $root->id])->assertSessionHasErrors('workflow');
        $this->put(route('finance.cost-centres.update', $root), ['code' => 'ROOT', 'name' => 'Root', 'parent_id' => $grand->id])->assertSessionHasErrors('workflow');
        $this->assertNull($root->refresh()->parent_id);
        $this->delete(route('finance.cost-centres.destroy', $root))->assertSessionHasErrors('workflow');
        $this->assertNotNull(CostCentre::find($root->id));

        $owner = User::factory()->create(['organization_id' => $this->org->id, 'email' => 'owner@example.test']);
        $body = "code,name,parent_code,owner_email,legal_entity_code,business_unit,is_active\n"
            ."CC-A,Alpha,ROOT,owner@example.test,,Tech,true\n"
            ."CC-B,Beta,,,,Ops,true\n"
            ."CC-C,Gamma,CC-A,,,Ops,true\n"
            ."CC-D,Delta,NOPE,,,Ops,true\n"
            ."CC-E,Epsilon,,ghost@example.test,,Ops,true\n";
        $this->post(route('finance.cost-centres.import'), ['file' => $this->csv('cc.csv', $body)])
            ->assertRedirect(route('finance.settings', ['tab' => 'cost-centres']))
            ->assertSessionHas('import_report', fn ($r) => $r['type'] === 'cost_centres' && $r['row_count'] === 5 && $r['accepted_count'] === 3
                && $r['rejected_count'] === 2 && collect($r['errors'])->pluck('row')->all() === [5, 6]);

        $this->assertSame($owner->id, CostCentre::where('code', 'CC-A')->value('owner_id'));
        $before = CostCentre::count();
        $again = str_replace('Alpha', 'Alpha renamed', $body);
        $this->post(route('finance.cost-centres.import'), ['file' => $this->csv('cc.csv', $again)])
            ->assertSessionHas('import_report', fn ($r) => $r['accepted_count'] === 3);
        $this->assertSame($before, CostCentre::count());
        $this->assertSame('Alpha renamed', CostCentre::where('code', 'CC-A')->value('name'));
    }

    /** FIN-01.03 */
    public function test_tbm_taxonomy_is_seeded_for_every_new_org(): void
    {
        $fresh = Organization::factory()->create(['currency' => 'NGN']);
        $user = User::factory()->create(['organization_id' => $fresh->id]);
        $user->assignRole('Organization Admin');

        $this->actingAs($user)->get(route('finance.settings'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('categories', TbmTaxonomy::count()));
        $this->assertSame(49, TbmTaxonomy::count());

        $rows = Category::withoutGlobalScopes()->where('organization_id', $fresh->id);
        $this->assertSame(8, (clone $rows)->where('kind', 'cost_pool')->count());
        $this->assertSame(11, (clone $rows)->where('kind', 'tower')->count());
        $hw = (clone $rows)->where('code', 'CP-HW')->first();
        $this->assertTrue($hw->default_capex);
        $this->assertSame('1810', GlAccount::withoutGlobalScopes()->find($hw->default_gl_account_id)->code);

        app(FinanceSettings::class)->ensureDefaults($fresh->id);
        app(FinanceSettings::class)->ensureDefaults($fresh->id);
        $this->assertSame(TbmTaxonomy::count(), Category::withoutGlobalScopes()->where('organization_id', $fresh->id)->count());

        $this->delete(route('finance.categories.destroy', $hw))->assertSessionHasErrors('workflow');
        $this->put(route('finance.categories.update', $hw), ['name' => 'Hardware renamed', 'code' => 'HACKED'])->assertRedirect();
        $this->assertSame('CP-HW', $hw->refresh()->code);
        $this->assertSame('Hardware renamed', $hw->name);

        $tower = (clone $rows)->where('code', 'TW-SEC')->first();
        $this->post(route('finance.categories.store'), ['code' => 'TW-SEC-CUST', 'name' => 'Custom security', 'parent_id' => $tower->id])->assertRedirect();
        $custom = Category::withoutGlobalScopes()->where('organization_id', $fresh->id)->where('code', 'TW-SEC-CUST')->first();
        $this->assertSame('sub_category', $custom->kind);
        $this->assertFalse((bool) $custom->is_system);
        $this->delete(route('finance.categories.destroy', $custom))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Category::withoutGlobalScopes()->whereNull('deleted_at')->find($custom->id));
    }

    /** FIN-01.04 */
    public function test_gl_codes_are_unique_per_org_only(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('finance.gl-accounts.store'), ['code' => '7001', 'name' => 'Custom', 'type' => 'opex'])->assertSessionHasNoErrors();
        $this->post(route('finance.gl-accounts.store'), ['code' => '7001', 'name' => 'Dup', 'type' => 'opex'])->assertSessionHasErrors('code');

        $this->actingAs($this->otherAdmin)->post(route('finance.gl-accounts.store'), ['code' => '7001', 'name' => 'Other bank', 'type' => 'opex'])->assertSessionHasNoErrors();
        $this->assertSame(2, GlAccount::withoutGlobalScopes()->where('code', '7001')->count());

        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $gl = GlAccount::where('code', '1810')->first();
        $this->delete(route('finance.gl-accounts.destroy', $gl))->assertSessionHasErrors('workflow');
        $this->assertNotNull(GlAccount::find($gl->id));
    }

    /** FIN-01.05 */
    public function test_fx_conversion_stores_rate_and_base_amount(): void
    {
        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $d = CarbonImmutable::parse('2027-03-15');
        foreach ([['USD', 'NGN', 1500, '2027-03-05'], ['USD', 'NGN', 1550, '2027-03-14'], ['GBP', 'NGN', 2000, '2027-03-14']] as [$f, $t, $r, $date]) {
            FxRate::create(['from_currency' => $f, 'to_currency' => $t, 'rate' => $r, 'rate_date' => $date]);
        }
        $svc = app(CurrencyService::class);

        $c = $svc->convert($this->org->id, '100.00', 'USD', $d);
        $this->assertSame('1550.00000000', $c['fx_rate']);
        $this->assertSame('155000.00', $c['base_amount']);
        $this->assertSame('100.00', $c['amount']);
        $this->assertSame('USD', $c['currency']);

        $this->refused(fn () => $svc->convert($this->org->id, '100.00', 'USD', CarbonImmutable::parse('2027-02-01')), 'currency');

        $this->assertSame('0.00064516', $svc->rate($this->org->id, 'NGN', 'USD', $d));
        $gbpUsd = $svc->rate($this->org->id, 'GBP', 'USD', $d);
        $this->assertEqualsWithDelta(2000 / 1550, (float) $gbpUsd, 0.0001);
        $this->assertSame('1.00000000', $svc->rate($this->org->id, 'USD', 'USD', $d));
        $this->assertSame('1.00000000', $svc->convert($this->org->id, '10', 'NGN', $d)['fx_rate']);
    }

    /** FIN-01.05 */
    public function test_fx_rates_import_and_single_base_currency(): void
    {
        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $yesterday = now()->subDay()->toDateString();
        $future = now()->addDays(5)->toDateString();
        $body = "from_currency,to_currency,rate,rate_date\nUSD,NGN,1500,{$yesterday}\nGBP,NGN,1900,{$yesterday}\nUSD,USD,1,{$yesterday}\nEUR,NGN,0,{$yesterday}\nUSD,NGN,1600,{$future}\n";

        $this->post(route('finance.fx-rates.import'), ['file' => $this->csv('fx.csv', $body)])
            ->assertSessionHas('import_report', fn ($r) => $r['type'] === 'fx_rates' && $r['row_count'] === 5 && $r['accepted_count'] === 2 && $r['rejected_count'] === 3);
        $count = FxRate::count();
        $this->post(route('finance.fx-rates.import'), ['file' => $this->csv('fx.csv', $body)])
            ->assertSessionHas('import_report', fn ($r) => $r['accepted_count'] === 2);
        $this->assertSame($count, FxRate::count());
        $this->assertSame('import', FxRate::first()->source);

        $usd = Currency::where('code', 'USD')->first();
        $this->put(route('finance.currencies.update', $usd), ['name' => 'US dollar', 'decimals' => 2, 'is_base' => true])->assertSessionHasNoErrors();
        $bases = Currency::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('is_base', true)->get();
        $this->assertSame(['USD'], $bases->pluck('code')->all());

        $this->put(route('finance.currencies.update', $usd->refresh()), ['name' => 'US dollar', 'decimals' => 2, 'is_active' => false])->assertSessionHasErrors('workflow');
        $this->assertTrue($usd->refresh()->is_active);
    }

    /** FIN-01.06 */
    public function test_nigeria_tax_defaults_are_seeded_and_editable(): void
    {
        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $tax = fn (string $code) => TaxType::where('code', $code)->first();

        $vat = $tax('VAT');
        $this->assertSame('vat', $vat->kind);
        $this->assertEquals(7.5, (float) $vat->rate);
        $this->assertFalse($vat->is_inclusive);
        $this->assertEqualsCanonicalizing(['expense', 'invoice'], $vat->applies_to);
        foreach (['WHT-5' => 5, 'WHT-10' => 10] as $code => $rate) {
            $this->assertSame('wht', $tax($code)->kind);
            $this->assertEquals($rate, (float) $tax($code)->rate);
            $this->assertSame(['invoice'], $tax($code)->applies_to);
        }
        $this->assertEquals(0, (float) $tax('NONE')->rate);

        $this->put(route('finance.tax-types.update', $vat), ['code' => 'VAT', 'name' => 'VAT', 'kind' => 'vat', 'rate' => 10, 'applies_to' => ['expense', 'invoice']])
            ->assertRedirect(route('finance.settings', ['tab' => 'taxes']));
        $this->assertEquals(10, (float) $vat->refresh()->rate);
        $this->assertTrue(Activity::where('log_name', 'it-finance')->where('subject_type', $vat->getMorphClass())->where('subject_id', $vat->id)->exists());
    }

    /** FIN-01.07 (Workflow Studio reflection is in P1) */
    public function test_doa_bands_are_editable_and_banding_is_correct(): void
    {
        $settings = app(FinanceSettings::class);
        $setting = $settings->for($this->org->id);
        $this->assertSame(['0-1m', '1m-10m', '10m-50m', '50m-250m', '250m+'], collect($setting->doa_bands)->pluck('key')->all());
        $this->assertSame('0-1m', $settings->bandFor($this->org->id, 999999.99));
        $this->assertSame('1m-10m', $settings->bandFor($this->org->id, 1000000));
        $this->assertSame('250m+', $settings->bandFor($this->org->id, 300000000));

        $this->actingAs($this->admin);
        $put = fn (array $bands) => $this->put(route('finance.settings.update'), ['section' => 'doa', 'doa_bands' => $bands]);

        $put([
            ['label' => 'Small', 'min' => 0, 'max' => 5000000],
            ['label' => 'Medium', 'min' => 5000000, 'max' => 20000000],
            ['label' => 'Large', 'min' => 20000000, 'max' => null],
        ])->assertRedirect(route('finance.settings', ['tab' => 'doa']))->assertSessionHasNoErrors();
        $this->assertSame(['0-5m', '5m-20m', '20m+'], collect($setting->refresh()->doa_bands)->pluck('key')->all());
        $this->assertSame('5m-20m', $settings->bandFor($this->org->id, 5000000));

        $put([['label' => 'A', 'min' => 0, 'max' => 5000000], ['label' => 'B', 'min' => 6000000, 'max' => null]])->assertSessionHasErrors('doa_bands');
        $put([['label' => 'A', 'min' => 0, 'max' => 5000000], ['label' => 'B', 'min' => 5000000, 'max' => 2000000], ['label' => 'C', 'min' => 2000000, 'max' => null]])->assertSessionHasErrors('doa_bands');
        $put([['label' => 'A', 'min' => 1000, 'max' => 5000000], ['label' => 'B', 'min' => 5000000, 'max' => null]])->assertSessionHasErrors('doa_bands');
        $this->assertSame(['0-5m', '5m-20m', '20m+'], collect($setting->refresh()->doa_bands)->pluck('key')->all());
    }

    /** FIN-01.08 */
    public function test_variance_thresholds_validate(): void
    {
        $setting = app(FinanceSettings::class)->for($this->org->id);
        $this->assertEquals(5, (float) $setting->variance_amber_pct);
        $this->assertEquals(10, (float) $setting->variance_red_pct);

        $this->actingAs($this->admin);
        $this->put(route('finance.settings.update'), ['section' => 'variance', 'variance_amber_pct' => 10, 'variance_red_pct' => 10, 'variance_abs_floor' => 0])->assertSessionHasErrors('variance_red_pct');
        $this->put(route('finance.settings.update'), ['section' => 'variance', 'variance_amber_pct' => 8, 'variance_red_pct' => 4, 'variance_abs_floor' => 0])->assertSessionHasErrors('variance_red_pct');
        $this->assertEquals(5, (float) $setting->refresh()->variance_amber_pct);

        $this->put(route('finance.settings.update'), ['section' => 'variance', 'variance_amber_pct' => 3, 'variance_red_pct' => 12, 'variance_abs_floor' => 250000])
            ->assertRedirect(route('finance.settings', ['tab' => 'variance']))->assertSessionHasNoErrors();
        $setting->refresh();
        $this->assertEquals(3, (float) $setting->variance_amber_pct);
        $this->assertEquals(12, (float) $setting->variance_red_pct);
        $this->assertEquals(250000, (float) $setting->variance_abs_floor);
    }

    /** FIN-01.09 */
    public function test_renewal_policy_offsets_normalised(): void
    {
        $setting = app(FinanceSettings::class)->for($this->org->id);
        $this->assertSame([180, 90, 60, 30, 14, 7, 0], $setting->renewal_notice_offsets);
        $this->assertSame(90, $setting->renewal_lead_days);

        $this->actingAs($this->admin);
        $body = fn (array $offsets) => ['section' => 'renewal', 'renewal_lead_days' => 60, 'renewal_notice_offsets' => $offsets, 'escalation_role' => 'Head of IT'];
        $this->put(route('finance.settings.update'), $body([7, 30, 30, 0]))->assertSessionHasErrors('renewal_notice_offsets.1');
        $this->assertSame([180, 90, 60, 30, 14, 7, 0], $setting->refresh()->renewal_notice_offsets);

        $this->put(route('finance.settings.update'), $body([7, 30, 0]))->assertSessionHasNoErrors();
        $this->assertSame([30, 7, 0], $setting->refresh()->renewal_notice_offsets);
        $this->assertSame(60, $setting->renewal_lead_days);
    }

    /** FIN-01.10 */
    public function test_settings_props_expose_every_dimension_source(): void
    {
        DB::table('legal_entities')->insert([
            ['organization_id' => null, 'code' => 'PLATFORM-LE', 'name' => 'Platform entity', 'jurisdiction' => 'NG', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->org->id, 'code' => 'OWN-LE', 'name' => 'Own entity', 'jurisdiction' => 'NG', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['organization_id' => $this->other->id, 'code' => 'FOREIGN-LE', 'name' => 'Other bank entity', 'jurisdiction' => 'NG', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->admin)->get(route('finance.settings'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('costCentres')->has('categories', TbmTaxonomy::count())->has('glAccounts')->has('users')->has('drivers')
            ->has('currencies', 4)->has('taxTypes', 4)
            ->where('legalEntities', fn ($e) => collect($e)->pluck('code')->sort()->values()->all() === ['OWN-LE', 'PLATFORM-LE']));
    }

    /** FIN-01.12, FIN-07.06 */
    public function test_settings_changes_are_audited(): void
    {
        $this->actingAs($this->admin);
        $setting = app(FinanceSettings::class)->for($this->org->id);

        $this->put(route('finance.settings.update'), ['section' => 'variance', 'variance_amber_pct' => 4, 'variance_red_pct' => 9, 'variance_abs_floor' => 500000])->assertSessionHasNoErrors();

        $log = Activity::where('log_name', 'it-finance')->where('subject_type', $setting->getMorphClass())->where('subject_id', $setting->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals(4, (float) $log->attribute_changes['attributes']['variance_amber_pct']);
        $this->assertEquals(5, (float) $log->attribute_changes['old']['variance_amber_pct']);
        $this->assertSame(Setting::class, $setting::class);

        $this->post(route('finance.cost-centres.store'), ['code' => 'AUDIT-1', 'name' => 'Audited'])->assertSessionHasNoErrors();
        $cc = CostCentre::where('code', 'AUDIT-1')->first();
        $this->assertSame($this->admin->id, $cc->created_by_id);
        $this->assertTrue(Activity::where('log_name', 'it-finance')->where('subject_type', $cc->getMorphClass())->where('subject_id', $cc->id)->exists());
    }

    /** Review fixes: locked periods, bcmath-safe input, importer atomicity, cycles, period lookup, close order, taxonomy, rounding. */
    public function test_locked_periods_refuse_driver_values_and_fx_rate_changes(): void
    {
        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $fy = $this->year('FY2020', '2020-01-01');
        $p1 = $fy->periods()->where('sequence', 1)->first();
        $p2 = $fy->periods()->where('sequence', 2)->first();
        $driver = Driver::create(['code' => 'HC', 'name' => 'Headcount', 'unit' => 'FTE']);
        $rate = FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1500, 'rate_date' => '2020-01-10']);
        $this->calendar()->closePeriod($p1, $this->admin);
        $this->calendar()->lockPeriod($p1, $this->admin);

        $this->put(route('finance.drivers.values.update', $driver), ['values' => [['period_id' => $p1->id, 'value' => 5]]])->assertSessionHasErrors('workflow');
        $this->put(route('finance.drivers.values.update', $driver), ['values' => [['period_id' => $p2->id, 'value' => '100000000000000']]])->assertSessionHasErrors('values.0.value');
        $this->put(route('finance.drivers.values.update', $driver), ['values' => [['period_id' => $p2->id, 'value' => 5]]])->assertSessionHasNoErrors();
        $this->assertSame(0, $driver->values()->where('period_id', $p1->id)->count());

        $this->post(route('finance.fx-rates.store'), ['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1600, 'rate_date' => '2020-01-20'])->assertSessionHasErrors('workflow');
        $this->delete(route('finance.fx-rates.destroy', $rate))->assertSessionHasErrors('workflow');
        $this->assertNotNull(FxRate::find($rate->id));
    }

    public function test_scientific_notation_is_rejected_before_bcmath(): void
    {
        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $this->put(route('finance.settings.update'), ['section' => 'doa', 'doa_bands' => [['label' => 'A', 'min' => '0', 'max' => '1e5'], ['label' => 'B', 'min' => '1e5', 'max' => null]]])->assertSessionHasErrors();
        $this->refused(fn () => app(FinanceSettings::class)->validateBands([['label' => 'A', 'min' => '0', 'max' => '1e5'], ['label' => 'B', 'min' => '1e5', 'max' => null]]), 'doa_bands');
        $this->post(route('finance.fx-rates.store'), ['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => '1e3', 'rate_date' => '2027-01-02'])->assertSessionHasErrors('rate');

        $yesterday = now()->subDay()->toDateString();
        $this->post(route('finance.fx-rates.import'), ['file' => $this->csv('fx.csv', "from_currency,to_currency,rate,rate_date\nUSD,NGN,1e3,{$yesterday}\nUSD,NGN,1500,{$yesterday}\n")])
            ->assertSessionHas('import_report', fn ($r) => $r['accepted_count'] === 1 && $r['rejected_count'] === 1);
        $this->refused(fn () => app(CurrencyService::class)->convert($this->org->id, '1e3', 'NGN', CarbonImmutable::parse('2027-01-02')), 'amount');
    }

    public function test_csv_import_rejects_oversize_files_without_applying_and_caps_errors(): void
    {
        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        config(['finance.import_max_rows' => 3]);
        $yesterday = now()->subDay()->toDateString();
        $rows = "from_currency,to_currency,rate,rate_date\nUSD,NGN,1500,{$yesterday}\nGBP,NGN,1900,{$yesterday}\nEUR,NGN,1700,{$yesterday}\nUSD,NGN,1600,{$yesterday}\n";
        $this->post(route('finance.fx-rates.import'), ['file' => $this->csv('fx.csv', $rows)])->assertSessionHasErrors('file');
        $this->assertSame(0, FxRate::count());

        config(['finance.import_max_rows' => 5000]);
        $bad = "code,name,parent_code,owner_email,legal_entity_code,business_unit,is_active\n".str_repeat("bad code!,X,,,,,\n", 250);
        $this->post(route('finance.cost-centres.import'), ['file' => $this->csv('cc.csv', $bad)])
            ->assertSessionHas('import_report', fn ($r) => $r['rejected_count'] === 250 && count($r['errors']) === 200);
    }

    public function test_cost_centre_parent_id_string_cannot_create_a_cycle(): void
    {
        $this->actingAs($this->admin);
        $root = CostCentre::create(['code' => 'ROOT', 'name' => 'Root']);
        $child = CostCentre::create(['code' => 'CHILD', 'name' => 'Child', 'parent_id' => $root->id]);
        $this->put(route('finance.cost-centres.update', $root), ['code' => 'ROOT', 'name' => 'Root', 'parent_id' => (string) $root->id])->assertSessionHasErrors('workflow');
        $this->put(route('finance.cost-centres.update', $root), ['code' => 'ROOT', 'name' => 'Root', 'parent_id' => (string) $child->id])->assertSessionHasErrors('workflow');
        $this->assertNull($root->refresh()->parent_id);
    }

    public function test_period_lookup_skips_the_adjustment_period_and_close_order_spans_years(): void
    {
        $fy = $this->year('FY2027', '2027-01-01', 'monthly_13');
        $adj = $fy->periods()->where('sequence', 13)->first();
        $this->assertTrue($adj->is_adjustment);
        $found = $this->calendar()->periodFor($this->org->id, CarbonImmutable::parse('2027-12-31'));
        $this->assertSame(12, $found->sequence);
        $this->assertFalse($found->is_adjustment);

        $next = $this->year('FY2028', '2028-01-01');
        $this->refused(fn () => $this->calendar()->closePeriod($next->periods()->where('sequence', 1)->first(), $this->admin));
        foreach ($fy->periods()->orderBy('sequence')->get() as $p) {
            $this->calendar()->closePeriod($p, $this->admin);
        }
        $this->calendar()->closePeriod($next->periods()->where('sequence', 1)->first(), $this->admin);

        $this->refused(fn () => $this->calendar()->lockPeriod($fy->periods()->where('sequence', 2)->first(), $this->admin));
    }

    public function test_only_cost_pools_carry_capex_and_gl_defaults(): void
    {
        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $towers = Category::whereIn('kind', ['tower', 'sub_category'])->where('code', 'like', 'TW-%')->get();
        $this->assertNotEmpty($towers);
        $this->assertSame(0, $towers->filter(fn ($c) => $c->default_capex || $c->default_gl_account_id)->count());
        $this->assertFalse((bool) $towers->firstWhere('code', 'TW-DC-OTH')->default_capex);
        $this->assertTrue((bool) Category::where('code', 'CP-HW')->first()->default_capex);
    }

    public function test_fx_inverse_cross_rates_and_amount_rounding(): void
    {
        $this->actingAs($this->admin);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $d = CarbonImmutable::parse('2027-03-15');
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => '3.00000000', 'rate_date' => '2027-03-01']);
        FxRate::create(['from_currency' => 'GBP', 'to_currency' => 'NGN', 'rate' => '2.00000000', 'rate_date' => '2027-03-01']);
        $svc = app(CurrencyService::class);
        $this->assertSame('0.33333333', $svc->rate($this->org->id, 'NGN', 'USD', $d));
        $this->assertSame('0.66666667', $svc->rate($this->org->id, 'GBP', 'USD', $d));
        $c = $svc->convert($this->org->id, '0.005', 'USD', $d);
        $this->assertSame('0.01', $c['amount']);
        $this->assertSame('0.03', $c['base_amount']);
    }
}
