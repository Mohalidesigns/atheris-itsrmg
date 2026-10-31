<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Benefit;
use App\Models\Finance\Contract;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\ValueReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/**
 * Route permission sweep (P7 H1, FIN-07.06/.10, tenancy): every finance.* route carries `permission:view it-finance`
 * (bar the signed calendar feed), every write carries a second permission or is decided in the service/policy, and
 * unauthenticated, permissionless and foreign-tenant callers are refused. Same pattern as EaAuthorizationTest.
 */
class FinanceRoutePermissionSweepTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    /** Writes with no second route permission: the decision is made by a policy gate or the owning service. */
    private const GATED_IN_SERVICE = [
        'finance.benefits.readings.store', 'finance.business-cases.evaluate', 'finance.calendar.feed.rotate',
        'finance.expenses.approve', 'finance.expenses.reject', 'finance.expenses.return',
        'finance.investments.score',
        'finance.invoices.approve', 'finance.invoices.reject', 'finance.invoices.return',
        'finance.value-reviews.complete',
    ];

    private const UNAUTHENTICATED_BY_DESIGN = ['finance.calendar.ics']; // signed URL instead of a session

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
    }

    /** @return array<int,RouteDefinition> */
    private function financeRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'finance.')));
    }

    /** @return array<int,string> */
    private function permissions(RouteDefinition $r): array
    {
        return array_values(array_filter($r->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'permission:')));
    }

    /** A concrete URI with every parameter set to 1. */
    private function uri(RouteDefinition $r): string
    {
        $uri = str_replace('{report}', 'bva', $r->uri());

        return '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $uri), '/');
    }

    /** @return array<int,string> */
    private function methods(RouteDefinition $r): array
    {
        return array_values(array_diff($r->methods(), ['HEAD']));
    }

    /** H1: the finance module is wholly behind `auth` and `view it-finance`. */
    public function test_every_finance_route_is_authenticated_and_requires_view(): void
    {
        $routes = $this->financeRoutes();
        $this->assertGreaterThanOrEqual(281, count($routes));
        foreach ($routes as $r) {
            $name = $r->getName();
            if (in_array($name, self::UNAUTHENTICATED_BY_DESIGN, true)) {
                $this->assertContains('signed', $r->gatherMiddleware(), $name);

                continue;
            }
            $this->assertContains('auth', $r->gatherMiddleware(), "{$name} is not behind auth");
            $this->assertContains('permission:view it-finance', $this->permissions($r), "{$name} lacks view it-finance");
        }
    }

    /** H1: a write needs a second permission unless it is on the service-gated list; the list holds no stale entries. */
    public function test_every_write_carries_a_second_permission_or_is_service_gated(): void
    {
        $seen = [];
        $ungated = [];
        foreach ($this->financeRoutes() as $r) {
            if ($this->methods($r) === ['GET']) {
                continue;
            }
            if (count($this->permissions($r)) < 2) {
                $seen[] = $r->getName();
                in_array($r->getName(), self::GATED_IN_SERVICE, true) || $ungated[] = $r->getName();
            }
        }
        $this->assertSame([], $ungated, 'Writes with only view it-finance: '.implode(', ', $ungated));
        $this->assertSame([], array_values(array_diff(self::GATED_IN_SERVICE, $seen)), 'GATED_IN_SERVICE holds a route that now carries a permission, or no longer exists');
    }

    /** H1, FIN-07.10: exports and settings need their own permissions. */
    public function test_sensitive_routes_carry_the_specific_permission(): void
    {
        $expect = [
            'finance.reports.show' => 'export it-finance', 'finance.calendar.export' => 'export it-finance', 'finance.monitor.bva.export' => 'export it-finance',
            'finance.budgets.workbook' => 'export it-finance', 'finance.periods.close' => 'close it-finance-periods', 'finance.periods.lock' => 'close it-finance-periods',
            'finance.cycles.store' => 'manage it-finance-settings', 'finance.invoices.pay' => 'approve it-finance-invoices', 'finance.renewals.approve' => 'approve it-finance-contracts',
            'finance.budgets.approve' => 'approve it-finance', 'finance.actuals.import' => 'import it-finance',
        ];
        foreach ($expect as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains("permission:{$permission}", $this->permissions($route), $name);
        }
    }

    /** H1: nobody signed in is sent to the login page on any finance route (parameters are resolved after auth). */
    public function test_unauthenticated_requests_are_redirected_to_login(): void
    {
        auth()->logout(); // the fixture signs in the finance controller
        foreach ($this->financeRoutes() as $r) {
            if (in_array($r->getName(), self::UNAUTHENTICATED_BY_DESIGN, true)) {
                continue;
            }
            $method = $this->methods($r)[0];
            $this->call($method, $this->uri($r))->assertRedirect(route('login'), 'unauthenticated '.$r->getName());
        }
    }

    /** H1: signed-in but permissionless users get 403 on every parameterless finance route, reads and writes. */
    public function test_a_user_without_finance_permissions_is_forbidden(): void
    {
        $nobody = User::factory()->create(['organization_id' => $this->org->id]);
        $this->assertCount(0, $nobody->getAllPermissions());
        $this->actingAs($nobody);
        $checked = 0;
        foreach ($this->financeRoutes() as $r) {
            if (in_array($r->getName(), self::UNAUTHENTICATED_BY_DESIGN, true) || str_contains($r->uri(), '{')) {
                continue;
            }
            $this->call($this->methods($r)[0], '/'.$r->uri())->assertForbidden();
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);
    }

    /** H1: a view-only user is refused every parameterless write route (403) and the export reads. */
    public function test_a_view_only_user_cannot_write_or_export(): void
    {
        $viewer = User::factory()->create(['organization_id' => $this->org->id]);
        $viewer->givePermissionTo('view it-finance');
        $this->actingAs($viewer);
        $refused = 0;
        foreach ($this->financeRoutes() as $r) {
            $name = $r->getName();
            if (str_contains($r->uri(), '{') || in_array($name, self::GATED_IN_SERVICE, true) || in_array($name, self::UNAUTHENTICATED_BY_DESIGN, true)) {
                continue;
            }
            $isWrite = $this->methods($r) !== ['GET'];
            $isExport = collect($this->permissions($r))->contains(fn ($p) => str_contains($p, 'export') || str_contains($p, 'create'));
            if ($isWrite || $isExport) {
                $this->call($this->methods($r)[0], '/'.$r->uri())->assertForbidden();
                $refused++;
            }
        }
        $this->assertGreaterThan(20, $refused);
    }

    /** H1: the service-gated approval writes refuse a viewer with no stake in the record (403). */
    public function test_service_gated_decisions_refuse_a_stranger_with_only_view(): void
    {
        $stranger = User::factory()->create(['organization_id' => $this->org->id]);
        $stranger->givePermissionTo('view it-finance');

        $expense = app(ExpenseService::class)->submit(
            $this->expense(['budget_line_id' => $this->line->id, 'amount' => '900000.00']), $this->requester)->refresh();
        $invoice = $this->invoice();
        $inv = $this->approvedInvestment();
        $review = app(ValueReviewService::class)->adHoc($inv, '2027-09-01', $this->sponsor->id, $this->fin);

        $this->actingAs($stranger);
        foreach (['approve', 'reject', 'return'] as $action) {
            $this->post(route("finance.expenses.{$action}", $expense), ['note' => 'x'])->assertForbidden();
            $this->post(route("finance.invoices.{$action}", $invoice), ['note' => 'x'])->assertForbidden();
        }
        $this->post(route('finance.investments.score', $inv), ['scores' => ['strategic' => 3]])->assertForbidden();
        $this->post(route('finance.value-reviews.complete', $review), ['summary' => 'Done'])->assertForbidden();
        $this->assertNotSame('completed', $review->refresh()->status);
    }

    /** H1: benefit readings are refused to a stranger even with a valid payload. */
    public function test_benefit_readings_refuse_a_stranger(): void
    {
        $inv = $this->inDelivery($this->approvedInvestment());
        $benefit = app(BenefitsService::class)->store($inv, [
            'title' => 'Fees saved', 'type' => 'cost_saving', 'unit' => 'currency', 'target_value' => '1000', 'owner_id' => $this->manager->id, 'due_on' => '2027-12-31',
        ], $this->fin);
        $this->assertInstanceOf(Benefit::class, $benefit);

        $stranger = User::factory()->create(['organization_id' => $this->org->id]);
        $stranger->givePermissionTo('view it-finance');
        $this->actingAs($stranger)->post(route('finance.benefits.readings.store', $benefit), ['period_id' => $this->periodSeq(1)->id, 'value' => '100'])->assertForbidden();
        $this->assertSame(0, $benefit->readings()->count());
    }

    /** Tenancy: another bank's admin gets 404 on this bank's records, never the data. */
    public function test_foreign_tenant_records_are_not_found(): void
    {
        $inv = $this->approvedInvestment();
        $expense = $this->expense();
        $invoice = $this->invoice();
        $contract = $this->contract();
        $this->assertInstanceOf(Contract::class, $contract);
        $budget = $this->line->budget ?? null;

        $other = Organization::factory()->create();
        $foreign = User::factory()->create(['organization_id' => $other->id]);
        $foreign->assignRole('Organization Admin');
        $this->actingAs($foreign);

        $this->get(route('finance.investments.show', $inv))->assertNotFound();
        $this->get(route('finance.expenses.show', $expense))->assertNotFound();
        $this->get(route('finance.invoices.show', $invoice))->assertNotFound();
        $this->get(route('finance.contracts.show', $contract))->assertNotFound();
        $budget && $this->get(route('finance.budgets.show', $budget))->assertNotFound();
        $this->post(route('finance.expenses.approve', $expense), ['note' => 'x'])->assertNotFound();
        $this->post(route('finance.contracts.activate', $contract))->assertNotFound();
        $this->assertNotSame('approved', $expense->refresh()->status);
    }
}
