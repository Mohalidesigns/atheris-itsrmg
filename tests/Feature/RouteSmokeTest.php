<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
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
            if ($response->status() >= 500) {
                $failures[] = $uri.' => '.$response->status();
            }
        }

        fwrite(STDERR, "\nTested {$tested} GET routes\n");
        $this->assertSame([], $failures, 'Routes returning 5xx: '.implode('; ', $failures));
    }
}
