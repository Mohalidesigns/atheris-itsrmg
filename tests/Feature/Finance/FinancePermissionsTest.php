<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\CostCentre;
use App\Models\Finance\Period;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * IT Financial Management permissions, roles and row restriction (ATH-FIN-001 §8).
 */
class FinancePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = [
        'view it-finance', 'create it-finance', 'edit it-finance', 'delete it-finance', 'approve it-finance', 'export it-finance',
        'approve it-finance-invoices', 'approve it-finance-investments', 'approve it-finance-contracts',
        'close it-finance-periods', 'manage it-finance-settings', 'view it-finance-amounts', 'import it-finance',
    ];

    private const GRANTS = [
        'IT Budget Owner' => ['view it-finance', 'create it-finance', 'edit it-finance', 'export it-finance', 'view it-finance-amounts'],
        'Procurement Officer' => ['view it-finance', 'create it-finance', 'edit it-finance', 'import it-finance', 'view it-finance-amounts'],
        'Investment Sponsor' => ['view it-finance', 'approve it-finance-investments', 'view it-finance-amounts'],
        'IT Steering Committee' => ['view it-finance', 'approve it-finance-investments', 'view it-finance-amounts'],
        'Finance Approver' => ['view it-finance', 'export it-finance', 'approve it-finance', 'approve it-finance-invoices', 'approve it-finance-contracts', 'approve it-finance-investments', 'view it-finance-amounts'],
        'Auditor' => ['view it-finance', 'export it-finance', 'view it-finance-amounts'],
        'Viewer' => ['view it-finance'],
    ];

    private Organization $org;

    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create(['currency' => 'NGN']);
        $this->other = Organization::factory()->create(['currency' => 'NGN']);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
    }

    private function user(string|array $roles = [], array $permissions = [], ?Organization $org = null): User
    {
        $u = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        foreach ((array) $roles as $role) {
            $u->assignRole($role);
        }
        if ($permissions) {
            $u->givePermissionTo($permissions);
        }

        return $u;
    }

    private function financePermissions(string $role): array
    {
        return Role::findByName($role)->permissions->pluck('name')->filter(fn ($n) => str_contains($n, 'it-finance'))->sort()->values()->all();
    }

    /** §8 */
    public function test_all_finance_permissions_and_roles_exist(): void
    {
        foreach (self::ALL as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name, 'guard_name' => 'web']);
        }
        $sorted = fn (array $a) => collect($a)->sort()->values()->all();

        foreach (['Organization Admin', 'IT Finance Controller', 'Super Admin'] as $role) {
            $this->assertSame($sorted(self::ALL), $this->financePermissions($role), $role);
        }
        foreach (self::GRANTS as $role => $expected) {
            $this->assertSame($sorted($expected), $this->financePermissions($role), $role);
        }

        $auditor = $this->financePermissions('Auditor');
        $this->assertContains('export it-finance', $auditor);
        $this->assertContains('view it-finance-amounts', $auditor);
        $this->assertNotContains('manage it-finance-settings', $auditor);
        $this->assertNotContains('approve it-finance', $auditor);
        $this->assertSame(['view it-finance'], $this->financePermissions('Viewer'));
    }

    /** §8, FIN-01.12 */
    public function test_settings_page_is_read_only_without_manage(): void
    {
        $owner = $this->user('IT Budget Owner');
        $this->actingAs($owner)->get(route('finance.settings'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('can.manage', false)->where('can.closePeriods', false)->where('can.import', false));
        $this->put(route('finance.settings.update'), ['section' => 'variance', 'variance_amber_pct' => 4, 'variance_red_pct' => 9, 'variance_abs_floor' => 0])->assertForbidden();
        $this->post(route('finance.cost-centres.store'), ['code' => 'NOPE', 'name' => 'Nope'])->assertForbidden();
        $this->assertSame(0, CostCentre::withoutGlobalScopes()->where('code', 'NOPE')->count());

        $controller = $this->user('IT Finance Controller');
        $this->actingAs($controller)->get(route('finance.settings'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('can.manage', true)->where('can.closePeriods', true)->where('can.import', true));
        $this->put(route('finance.settings.update'), ['section' => 'variance', 'variance_amber_pct' => 4, 'variance_red_pct' => 9, 'variance_abs_floor' => 0])
            ->assertRedirect(route('finance.settings', ['tab' => 'variance']))->assertSessionHas('success');
    }

    /** Super Admin bypasses every gate (Gate::before), including the route permission middleware. */
    public function test_super_admin_bypasses_permissions(): void
    {
        $super = $this->user();
        $super->assignRole('Super Admin');
        $super->syncPermissions([]);

        $this->actingAs($super)->get(route('finance.settings'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.manage', true));
        $this->post(route('finance.cost-centres.store'), ['code' => 'SA-1', 'name' => 'Super'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, CostCentre::withoutGlobalScopes()->where('code', 'SA-1')->count());
    }

    /** §8 */
    public function test_period_close_and_import_need_their_own_permissions(): void
    {
        $fy = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2027', 'starts_on' => '2027-01-01', 'period_scheme' => 'monthly', 'status' => 'open'], $this->user('Organization Admin'));
        $period = $fy->periods()->orderBy('sequence')->first();

        $manager = $this->user([], ['view it-finance', 'manage it-finance-settings']);
        $this->actingAs($manager);
        $this->post(route('finance.periods.close', $period))->assertForbidden();
        $this->post(route('finance.periods.lock', $period))->assertForbidden();
        $this->assertSame('open', Period::withoutGlobalScopes()->find($period->id)->status);

        $csv = fn () => UploadedFile::fake()->createWithContent('x.csv', "code,name,parent_code,owner_email,legal_entity_code,business_unit,is_active\n");
        $this->post(route('finance.cost-centres.import'), ['file' => $csv()])->assertForbidden();
        $this->post(route('finance.fx-rates.import'), ['file' => UploadedFile::fake()->createWithContent('x.csv', "from_currency,to_currency,rate,rate_date\n")])->assertForbidden();

        $closer = $this->user([], ['view it-finance', 'close it-finance-periods']);
        Carbon::setTestNow('2027-02-05 09:00:00'); // P4 checklist: the period must have ended (D10); the year has no baseline so actuals_posted is n/a
        $this->actingAs($closer)->post(route('finance.periods.close', $period))->assertRedirect()->assertSessionHasNoErrors();
        Carbon::setTestNow();
        $this->assertSame('closed', Period::withoutGlobalScopes()->find($period->id)->status);

        $importer = $this->user([], ['view it-finance', 'manage it-finance-settings', 'import it-finance']);
        $this->actingAs($importer)->post(route('finance.cost-centres.import'), ['file' => $csv()])->assertRedirect();
    }

    /** §8 row restriction */
    public function test_budget_owner_sees_only_owned_cost_centres_and_descendants(): void
    {
        $owner = $this->user('IT Budget Owner');
        $nobody = $this->user('IT Budget Owner');
        $make = fn (string $code, ?int $parent, ?int $ownerId) => CostCentre::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'code' => $code, 'name' => $code, 'parent_id' => $parent, 'owner_id' => $ownerId,
        ]);
        $root = $make('ROOT', null, $owner->id);
        $child = $make('CHILD', $root->id, null);
        $grand = $make('GRAND', $child->id, null);
        $make('SIBLING', null, null);

        $this->assertEqualsCanonicalizing([$root->id, $child->id, $grand->id], FinancePolicy::visibleCostCentreIds($owner));
        $this->assertSame([], FinancePolicy::visibleCostCentreIds($nobody));

        foreach (['IT Finance Controller', 'Finance Approver', 'Organization Admin', 'Auditor'] as $role) {
            $this->assertNull(FinancePolicy::visibleCostCentreIds($this->user($role)), $role);
        }
    }

    /** §8 */
    public function test_policy_is_mapped_for_finance_models(): void
    {
        $this->assertInstanceOf(FinancePolicy::class, Gate::getPolicyFor(CostCentre::class));
        $this->assertInstanceOf(FinancePolicy::class, Gate::getPolicyFor(Period::class));

        $foreign = CostCentre::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'code' => 'FOREIGN', 'name' => 'Other bank']);
        $mine = CostCentre::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'code' => 'MINE', 'name' => 'Mine']);
        $controller = $this->user('IT Finance Controller');

        $this->assertFalse($controller->can('update', $foreign));
        $this->assertFalse($controller->can('delete', $foreign));
        $this->assertTrue($controller->can('update', $mine));
        $this->assertFalse($this->user('IT Budget Owner')->can('update', $mine));
    }

    /** §8 */
    public function test_grant_migration_is_idempotent(): void
    {
        $count = fn () => DB::table('role_has_permissions')->count();
        $before = $count();

        $migration = require base_path('database/migrations/2027_01_30_000002_grant_it_finance_permissions.php');
        $migration->up();
        $migration->up();

        $this->assertSame($before, $count());
        $this->assertSame(0, DB::table('role_has_permissions')->select('role_id', 'permission_id')->groupBy('role_id', 'permission_id')->havingRaw('count(*) > 1')->get()->count());
        $this->assertSame(13, DB::table('permissions')->where('name', 'like', '%it-finance%')->count());
    }
}
