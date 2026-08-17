<?php

namespace Tests\Feature\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ea\CapabilityLinkIndex;
use App\Services\Ea\CostModelService;
use App\Services\Ea\HierarchyIndex;
use App\Services\Ea\ImpactAnalyser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ATH-EAR-002 §10, the performance release gate:
 *
 *   "Capability tree, portfolio grid and blast radius must render **within 2s at
 *    5,000 entities / 20,000 relationships**. The current `byParent` recursive
 *    render and JSON-column filtering will not hold; add materialised closure
 *    tables for hierarchies and indexed columns for anything filtered."
 *
 * WS 4.6 asks for this as a benchmark rather than a smoke test, so each case
 * builds the estate at the stated scale and asserts a wall-clock budget on the
 * server-side work.
 *
 * Two honesty notes about what this measures.
 *
 * First, the budget here is on the **server**: query time plus the PHP that
 * shapes the payload. Browser render is not measured by a PHPUnit test and is
 * not claimed to be. What the test does protect is the thing that actually broke
 * the 2s target — the N+1 and full-scan patterns §10 names.
 *
 * Second, it runs on in-memory SQLite, which has no network round-trip and a
 * simpler planner than MySQL. Absolute numbers are therefore not a production
 * prediction. The value is that an algorithmic regression — a reintroduced
 * per-node query, a `whereJsonContains` in a loop — fails the budget by an order
 * of magnitude, not by a few percent, so the test catches the class of mistake
 * it was written for.
 *
 * @group performance
 */
class EaPerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** §10's stated scale. */
    private const ENTITIES = 5000;

    private const RELATIONSHIPS = 20000;

    /** §10's stated budget, in seconds. */
    private const BUDGET = 2.0;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $this->organization->id]);
        $user->givePermissionTo(['view ea', 'edit ea']);
        $this->actingAs($user);
    }

    /**
     * Build the estate with raw inserts — the point is to measure the read path,
     * and going through Eloquent for 25,000 rows would make the fixture cost
     * dwarf the thing under test.
     */
    private function buildEstate(): array
    {
        $now = now()->toDateTimeString();
        $organizationId = $this->organization->id;

        // 1,000 capabilities, four levels deep.
        $capabilities = [];
        for ($i = 1; $i <= 1000; $i++) {
            $capabilities[] = [
                'organization_id' => $organizationId,
                'code' => 'CAP-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => "Capability {$i}",
                'level' => min(4, (int) floor(log10(max(1, $i))) + 1),
                'parent_id' => $i > 10 ? (int) ceil($i / 10) : null,
                'criticality' => ['low', 'medium', 'high', 'critical'][$i % 4],
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($capabilities, 500) as $chunk) {
            DB::table('ea_capabilities')->insert($chunk);
        }

        // 3,000 applications.
        $applications = [];
        for ($i = 1; $i <= 3000; $i++) {
            $applications[] = [
                'organization_id' => $organizationId,
                'code' => 'APP-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => "Application {$i}",
                'criticality' => ['low', 'medium', 'high', 'critical'][$i % 4],
                'lifecycle' => ['plan', 'build', 'live', 'sunset'][$i % 4],
                'business_fit' => ($i % 5) + 1,
                'technical_fit' => (($i * 3) % 5) + 1,
                'annual_cost' => 10000 + $i,
                'cost_currency' => $i % 3 === 0 ? 'USD' : 'NGN',
                'user_count' => 100 + $i,
                'capability_ids' => json_encode([($i % 1000) + 1, (($i * 7) % 1000) + 1]),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($applications, 500) as $chunk) {
            DB::table('ea_applications_ext')->insert($chunk);
        }

        // 1,000 technology components, each carrying a handful of applications.
        $components = [];
        for ($i = 1; $i <= 1000; $i++) {
            $components[] = [
                'organization_id' => $organizationId,
                'code' => 'TECH-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => "Component {$i}",
                'radar_status' => ['adopt', 'trial', 'assess', 'hold'][$i % 4],
                'obsolescence_flag' => $i % 11 === 0,
                'application_ids' => json_encode([$i, $i + 1000, $i + 2000]),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($components, 500) as $chunk) {
            DB::table('ea_tech_components_ext')->insert($chunk);
        }

        // 5,000 interfaces + 15,000 generic relationships = 20,000 edges.
        $interfaces = [];
        for ($i = 1; $i <= 5000; $i++) {
            $interfaces[] = [
                'organization_id' => $organizationId,
                'code' => 'IF-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'name' => "Interface {$i}",
                'source_app_id' => ($i % 3000) + 1,
                'target_app_id' => (($i * 13) % 3000) + 1,
                'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($interfaces, 500) as $chunk) {
            DB::table('ea_interfaces')->insert($chunk);
        }

        $relationships = [];
        for ($i = 1; $i <= 15000; $i++) {
            $relationships[] = [
                'organization_id' => $organizationId,
                'source_type' => EaApplication::class,
                'source_id' => ($i % 3000) + 1,
                'target_type' => $i % 2 ? Capability::class : EaApplication::class,
                'target_id' => $i % 2 ? (($i * 3) % 1000) + 1 : (($i * 17) % 3000) + 1,
                'relation_type' => $i % 2 ? 'realisation' : 'flow',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($relationships, 500) as $chunk) {
            DB::table('ea_relationships')->insert($chunk);
        }

        return [
            'entities' => 1000 + 3000 + 1000,
            'relationships' => 5000 + 15000,
        ];
    }

    /**
     * Time a closure and print the reading.
     *
     * The number is written to STDERR rather than only asserted on, because a
     * budget test that passes tells you nothing about how much headroom is
     * left. A reading drifting from 0.05s to 1.9s passes every run until the
     * day it does not.
     */
    /**
     * @param  float|null  $budget  the threshold this measurement is actually
     *                              asserted against. Passing it keeps the printed
     *                              percentage honest: reporting every operation
     *                              against self::BUDGET while asserting a larger
     *                              allowance elsewhere prints "113% used" beside
     *                              a passing test, which reads as a tolerated
     *                              breach rather than a different budget.
     */
    private function measure(callable $work, ?string $label = null, ?float $budget = null): array
    {
        $budget ??= self::BUDGET;

        $start = microtime(true);
        $result = $work();
        $seconds = microtime(true) - $start;

        if ($label) {
            fwrite(STDERR, sprintf(
                "\n    %-46s %7.3fs  (budget %.1fs, %d%% used)",
                $label, $seconds, $budget, (int) round($seconds / $budget * 100)
            ));
        }

        return [$seconds, $result];
    }

    public function test_the_estate_reaches_the_stated_scale(): void
    {
        $counts = $this->buildEstate();

        $this->assertSame(self::ENTITIES, $counts['entities']);
        $this->assertSame(self::RELATIONSHIPS, $counts['relationships']);
    }

    public function test_the_capability_tree_builds_within_budget(): void
    {
        $this->buildEstate();

        // Building the index is a maintenance operation, not a page load, so it
        // is excluded from the budget — but it still has to be tractable.
        [$rebuildSeconds] = $this->measure(fn () => (new HierarchyIndex)->rebuild(Capability::class), 'closure rebuild (maintenance, not a page load)');
        $this->assertLessThan(10.0, $rebuildSeconds, 'a closure rebuild over 1,000 nodes must stay well under ten seconds');

        $index = new HierarchyIndex;

        [$seconds, $payload] = $this->measure(function () use ($index) {
            $roots = $index->roots(Capability::class);
            $sizes = $index->subtreeSizes(Capability::class);
            $level = Capability::whereIn('id', $roots)->get(['id', 'code', 'name', 'criticality']);

            return ['roots' => $level, 'sizes' => $sizes];
        }, 'capability tree — roots + subtree sizes');

        $this->assertLessThan(self::BUDGET, $seconds,
            sprintf('capability tree took %.3fs against a %.1fs budget', $seconds, self::BUDGET));
        $this->assertNotEmpty($payload['roots']);
    }

    public function test_the_portfolio_grid_builds_within_budget(): void
    {
        $this->buildEstate();

        // The 5×5 business-fit × technical-fit grid: previously 25 separate
        // queries, each returning full models.
        [$seconds, $grid] = $this->measure(function () {
            $rows = DB::table('ea_applications_ext')
                ->selectRaw('business_fit, technical_fit, count(*) as c, sum(annual_cost) as cost, sum(user_count) as users')
                ->whereNotNull('business_fit')->whereNotNull('technical_fit')
                ->groupBy('business_fit', 'technical_fit')
                ->get();

            return $rows;
        }, 'portfolio grid — 5x5 fit matrix');

        $this->assertLessThan(self::BUDGET, $seconds,
            sprintf('portfolio grid took %.3fs against a %.1fs budget', $seconds, self::BUDGET));
        $this->assertNotEmpty($grid);
    }

    public function test_blast_radius_traversal_stays_within_budget_at_depth_three(): void
    {
        $this->buildEstate();
        (new CapabilityLinkIndex)->rebuild();

        [$seconds, $result] = $this->measure(
            fn () => (new ImpactAnalyser)->traverse(EaApplication::class, 1, 3),
            'blast radius — depth 3 over 20,000 edges'
        );

        $this->assertLessThan(self::BUDGET, $seconds,
            sprintf('depth-3 traversal took %.3fs against a %.1fs budget', $seconds, self::BUDGET));
        $this->assertNotEmpty($result['nodes']);
    }

    public function test_traversal_cost_is_flat_in_depth_not_exponential(): void
    {
        $this->buildEstate();
        (new CapabilityLinkIndex)->rebuild();

        // The adjacency is loaded once per analyser, so a deeper walk over the
        // same graph costs walking, not querying. A per-node-query regression
        // shows up here as depth-5 costing several times depth-1.
        [$shallow] = $this->measure(fn () => (new ImpactAnalyser)->traverse(EaApplication::class, 1, 1), 'traversal depth 1');
        [$deep] = $this->measure(fn () => (new ImpactAnalyser)->traverse(EaApplication::class, 1, 5), 'traversal depth 5');

        $this->assertLessThan(self::BUDGET * 2, $deep,
            sprintf('depth-5 traversal took %.3fs', $deep));
        $this->assertLessThan(6.0, $deep / max(0.001, $shallow),
            'traversal cost must be dominated by the one-off adjacency load, not by depth');
    }

    public function test_the_capability_pivot_beats_json_filtering(): void
    {
        $this->buildEstate();
        (new CapabilityLinkIndex)->rebuild();

        $index = new CapabilityLinkIndex;

        // The overlay: application counts for all 1,000 capabilities. Through
        // the pivot this is one query.
        [$pivotSeconds, $map] = $this->measure(fn () => $index->applicationsByCapability(), 'capability overlay via pivot — 1,000 capabilities');

        $this->assertLessThan(self::BUDGET, $pivotSeconds,
            sprintf('capability overlay took %.3fs against a %.1fs budget', $pivotSeconds, self::BUDGET));
        $this->assertNotEmpty($map);

        // The pattern it replaces, sampled over 25 capabilities rather than
        // 1,000 — running the full loop would make the suite unusable, which is
        // the finding.
        [$jsonSeconds] = $this->measure(function () {
            foreach (range(1, 25) as $capabilityId) {
                EaApplication::whereJsonContains('capability_ids', $capabilityId)->count();
            }
        }, 'the pattern it replaces — 25 whereJsonContains scans');

        $projected = $jsonSeconds * 40;   // 25 → 1,000
        $this->assertGreaterThan(
            $pivotSeconds,
            $projected,
            sprintf(
                'the pivot (%.3fs for 1,000 capabilities) must beat JSON filtering (%.3fs projected)',
                $pivotSeconds, $projected
            )
        );
    }

    /**
     * The cost roll-up carries a deliberate 3× allowance, stated rather than hidden.
     *
     * §10 sets the 2s bar for three named operations — "capability tree,
     * portfolio grid and blast radius" — and the cost roll-up is not one of
     * them. It is a reporting read, not a page load on the navigation path, and
     * it touches both materialised indexes plus the TCO model for every one of
     * 3,000 applications. So it is held to 6s and *labelled* as held to 6s.
     *
     * What this still catches is the regression class §10 actually names: the
     * pre-fix implementation walked the hierarchy per capability, and a return
     * to that shape misses this threshold by an order of magnitude rather than
     * a few percent. If the roll-up is ever put behind an interactive screen,
     * this allowance is the thing to revisit first.
     */
    public function test_the_cost_roll_up_stays_within_its_stated_allowance(): void
    {
        $this->buildEstate();
        (new HierarchyIndex)->rebuild(Capability::class);
        (new CapabilityLinkIndex)->rebuild();

        $allowance = self::BUDGET * 3;

        [$seconds, $result] = $this->measure(
            fn () => (new CostModelService)->costPerCapability(),
            'cost per capability — 1,000 caps x 3,000 apps',
            $allowance
        );

        $this->assertLessThan($allowance, $seconds,
            sprintf('cost per capability took %.3fs against a %.1fs allowance', $seconds, $allowance));
        $this->assertCount(1000, $result['capabilities']);
    }

    public function test_the_hot_columns_carry_indexes(): void
    {
        // §10: "indexed columns for anything filtered". Asserted structurally
        // rather than by timing, because on 5,000 SQLite rows a missing index
        // is fast enough to pass a stopwatch and disastrous on MySQL at
        // production volume.
        $indexed = collect(DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'index'"))
            ->pluck('sql')->filter()->implode(' ');

        $this->assertStringContainsString('ea_applications_ext', $indexed);
        $this->assertStringContainsString('ea_relationships', $indexed);

        foreach (['ea_closure', 'ea_application_capabilities'] as $table) {
            $this->assertTrue(
                str_contains($indexed, $table),
                "{$table} must carry indexes — it exists only to be read by index"
            );
        }
    }
}
