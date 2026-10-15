<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Full-application smoke test: hits every parameterless authenticated GET
 * route as a seeded Organization Admin and fails on any 5xx response.
 *
 * Requires a SEEDED database (php artisan migrate:fresh --seed), so it is
 * skipped unless explicitly enabled:
 *
 *   SMOKE=1 php artisan test --filter RouteSmokeTest
 *
 * (Point DB_DATABASE at your seeded database when running it.)
 */
class RouteSmokeTest extends TestCase
{
    public function test_all_parameterless_get_routes_do_not_500(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $admin = User::where('email', 'admin@kanoheritage.ng')->first();
        $this->assertNotNull($admin, 'Seeded database required — run php artisan migrate:fresh --seed first.');

        $failures = [];
        $tested = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods())) {
                continue;
            }
            $uri = $route->uri();
            if (str_contains($uri, '{')) {
                continue; // skip parameterised routes
            }
            if (preg_match('#^(api|_|sanctum|up$|storage)#', $uri)) {
                continue;
            }
            $middleware = implode(',', $route->gatherMiddleware());
            if (! str_contains($middleware, 'auth')) {
                continue; // only authenticated app routes
            }

            $response = $this->actingAs($admin)->get('/'.ltrim($uri, '/'));
            $tested++;
            if ($response->getStatusCode() >= 500) {
                $failures[] = $uri.' => '.$response->getStatusCode();
            }
        }

        fwrite(STDERR, "\nTested {$tested} GET routes\n");
        $this->assertSame([], $failures, 'Routes returning 5xx: '.implode('; ', $failures));
    }

    /**
     * IT Financial Management (plan §5): every parameterless finance.* GET route, and each Settings
     * tab, must render 200 for every demo tenant, with the seeded estate the spec promises.
     */
    public function test_finance_pages_render_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $urls = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'finance.') && in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{'))
            ->map(fn (RouteDefinition $r) => route($r->getName()))->values()->all();
        $this->assertNotEmpty($urls);

        $tabs = ['general', 'calendar', 'cost-centres', 'categories', 'gl-accounts', 'currencies', 'taxes', 'drivers', 'doa', 'variance', 'renewal'];
        $expect = [
            'admin@kanoheritage.ng' => fn (Assert $p) => $p->has('costCentres', 10)->where('fiscalYears', fn ($y) => count($y) >= 2)
                ->where('costCentres', fn ($c) => collect($c)->contains(fn ($x) => $x['owner_id'] === null)),
            'admin@firstbanknigeria.ng' => fn (Assert $p) => $p->has('costCentres', 12)
                ->where('costCentres', fn ($c) => collect($c)->contains(fn ($x) => $x['legal_entity'] !== null) && collect($c)->contains(fn ($x) => $x['owner_id'] === null)),
            'admin@acme.ng' => fn (Assert $p) => $p->has('costCentres', 0)->has('fiscalYears', 0)->has('categories', 49),
        ];

        foreach ($expect as $email => $assertSeeded) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "{$email} missing: seed the database first.");

            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
            foreach ($tabs as $tab) {
                $this->actingAs($user)->get(route('finance.settings', ['tab' => $tab]))->assertOk()
                    ->assertInertia(fn (Assert $p) => $p->component('Finance/Settings')->where('tab', $tab));
            }
            $this->actingAs($user)->get(route('finance.settings'))->assertOk()->assertInertia($assertSeeded);
        }

        $finance = User::where('email', 'finance@kanoheritage.ng')->first();
        $this->assertNotNull($finance);
        $this->assertTrue($finance->hasRole('IT Finance Controller'));

        // Segregation of duties in the demo estate: no budget owner also approves (ATH-FIN-001 §6).
        $conflicts = User::withoutGlobalScopes()->role('Finance Approver')->get()
            ->filter(fn (User $u) => $u->hasRole('IT Budget Owner'))->pluck('email')->all();
        $this->assertSame([], $conflicts);
    }
}
