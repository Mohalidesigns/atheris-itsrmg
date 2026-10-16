<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Driver;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Period;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\TbmTaxonomy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * IT Financial Management foundation routes (ATH-FIN-001 §3.1, §12 tenancy): the settings page
 * renders for an Organization Admin, is forbidden without `view it-finance`, another bank's
 * records 404, and a bank with no finance data renders defaults rather than errors (FIN-01.03).
 */
class FinanceTenancyAndRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $otherAdmin;

    private CostCentre $costCentre;

    private FiscalYear $fy;

    private FxRate $fx;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'job_title' => 'CISO']);
        $this->admin->assignRole('Organization Admin');
        $this->otherAdmin = User::factory()->create(['organization_id' => $this->other->id, 'job_title' => 'CISO']);
        $this->otherAdmin->assignRole('Organization Admin');

        $settings = app(FinanceSettings::class);
        $settings->ensureDefaults($this->org->id);
        $this->actingAs($this->admin);
        $this->fy = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2027', 'starts_on' => '2027-01-01', 'period_scheme' => 'monthly', 'status' => 'open', 'is_current' => true], $this->admin);
        $this->costCentre = CostCentre::create(['code' => 'CC-1', 'name' => 'IT']);
        $this->driver = Driver::create(['code' => 'HC', 'name' => 'Headcount', 'unit' => 'FTE']);
        $this->fx = FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1500, 'rate_date' => '2027-01-01']);
        auth()->logout();
    }

    /** @return array<string, string> */
    private function getRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'finance.') && in_array('GET', $r->methods(), true))
            ->mapWithKeys(fn (RouteDefinition $r) => [$r->getName() => route($r->getName())])->sortKeys()->all();
    }

    /** @return array<string, string> parameterless GET routes only */
    private function pageRoutes(): array
    {
        return array_filter($this->getRoutes(), fn ($url, $name) => ! str_contains($url, '{'), ARRAY_FILTER_USE_BOTH);
    }

    public function test_every_finance_page_renders_for_an_organization_admin(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'finance.') && in_array('GET', $r->methods(), true))
            ->map(fn (RouteDefinition $r) => $r->getName())->sort()->values()->all();
        $this->assertSame([
            'finance.budgets.create', 'finance.budgets.diff', 'finance.budgets.edit', 'finance.budgets.index', 'finance.budgets.show', 'finance.budgets.workbook',
            'finance.cycles.index', 'finance.settings', 'finance.supplementary.index', 'finance.targets.index', 'finance.transfers.index',
        ], $names);

        $components = [
            'finance.settings' => 'Finance/Settings', 'finance.budgets.index' => 'Finance/Budgets/Index', 'finance.budgets.create' => 'Finance/Budgets/Create',
            'finance.cycles.index' => 'Finance/Budgets/Cycles', 'finance.targets.index' => 'Finance/Budgets/Targets',
            'finance.transfers.index' => 'Finance/Budgets/Transfers', 'finance.supplementary.index' => 'Finance/Budgets/Supplementary',
        ];
        foreach ($components as $name => $component) {
            $this->actingAs($this->admin)->get(route($name))->assertOk()->assertInertia(fn (Assert $p) => $p->component($component, false)->has('auth.user'));
        }
        $this->actingAs($this->admin)->get(route('finance.settings', ['tab' => 'categories']))->assertInertia(fn (Assert $p) => $p->where('tab', 'categories'));
        $this->actingAs($this->admin)->get(route('finance.settings', ['tab' => 'nope']))->assertInertia(fn (Assert $p) => $p->where('tab', 'general'));
    }

    public function test_every_finance_page_is_forbidden_without_view_it_finance(): void
    {
        $nobody = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($nobody)->get(route('finance.settings'))->assertForbidden();
        $this->actingAs($nobody)->put(route('finance.settings.update'), ['section' => 'variance'])->assertForbidden();
        $this->actingAs($nobody)->post(route('finance.cost-centres.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }

    public function test_records_404_for_another_banks_records(): void
    {
        $this->actingAs($this->admin);
        $category = Category::where('code', 'TW-SEC')->first();
        $gl = GlAccount::where('code', '6201')->first();
        $period = $this->fy->periods()->first();
        auth()->logout();

        $this->actingAs($this->otherAdmin);
        $this->put(route('finance.cost-centres.update', $this->costCentre), ['code' => 'CC-1', 'name' => 'x'])->assertNotFound();
        $this->delete(route('finance.cost-centres.destroy', $this->costCentre))->assertNotFound();
        $this->put(route('finance.categories.update', $category), ['name' => 'x'])->assertNotFound();
        $this->delete(route('finance.categories.destroy', $category))->assertNotFound();
        $this->put(route('finance.gl-accounts.update', $gl), ['code' => '1', 'name' => 'x', 'type' => 'opex'])->assertNotFound();
        $this->delete(route('finance.gl-accounts.destroy', $gl))->assertNotFound();
        $this->put(route('finance.fiscal-years.update', $this->fy), ['year_label' => 'FYX', 'status' => 'open'])->assertNotFound();
        $this->delete(route('finance.fiscal-years.destroy', $this->fy))->assertNotFound();
        $this->post(route('finance.periods.close', $period))->assertNotFound();
        $this->put(route('finance.drivers.values.update', $this->driver), ['values' => []])->assertNotFound();
        $this->delete(route('finance.fx-rates.destroy', $this->fx))->assertNotFound();

        $this->get(route('finance.settings'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('costCentres', 0)->has('fiscalYears', 0)->has('drivers', 0)->has('fxRates', 0)
            ->has('categories', TbmTaxonomy::count()));
    }

    public function test_a_new_bank_with_no_finance_data_renders_empty_not_errors(): void
    {
        $fresh = Organization::factory()->create(['currency' => 'NGN']);
        $user = User::factory()->create(['organization_id' => $fresh->id]);
        $user->assignRole('Organization Admin');

        $this->actingAs($user)->get(route('finance.settings'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('fiscalYears', 0)->has('costCentres', 0)->has('categories', TbmTaxonomy::count())
            ->has('currencies', 4)->has('taxTypes', 4)->where('baseCurrency', 'NGN')
            ->where('currencies', fn ($c) => collect($c)->where('is_base', true)->count() === 1 && collect($c)->firstWhere('is_base', true)['code'] === 'NGN'));
    }

    public function test_periods_are_scoped_to_their_fiscal_year(): void
    {
        $this->assertSame(12, Period::withoutGlobalScopes()->where('fiscal_year_id', $this->fy->id)->count());
    }

    /** SoD: no demo user may hold both IT Budget Owner and Finance Approver. */
    public function test_seeded_head_of_it_is_not_also_a_finance_approver(): void
    {
        $head = User::factory()->create(['organization_id' => $this->org->id, 'job_title' => 'Head of IT Operations']);
        $head->assignRole(['IT Budget Owner', 'Finance Approver']);
        $source = file_get_contents(base_path('database/seeders/FinanceFoundationSeeder.php'));
        $this->assertStringContainsString("removeRole('Finance Approver')", $source);
        $this->assertStringNotContainsString("assignRole(['IT Budget Owner', 'Finance Approver'])", $source);
    }
}
