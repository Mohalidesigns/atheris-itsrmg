<?php

namespace Tests\Feature\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ATH-EAR-002 WS 2.2 and §10.
 *
 * §2.5 (RC-5): EA declared `tenant_id`, 33 older core tables declared
 * `organization_id`, both resolving to `users.organization_id`. "It works
 * today. It will break the first time someone writes a cross-module join or
 * extracts a module into a service." §7.1 makes resolving it a precondition for
 * the returns engine.
 *
 * §10 additionally requires: "Automated test proving no cross-organisation
 * leakage on every EA endpoint. `BelongsToTenant`'s `orWhereNull` shared-
 * reference-data semantics must be explicitly tested — it is a plausible leak
 * path."
 */
class EaTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_table_still_carries_the_old_tenancy_column(): void
    {
        $stragglers = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;
            if (Schema::hasColumn($table, 'tenant_id')) {
                $stragglers[] = $table;
            }
        }

        $this->assertSame([], $stragglers,
            'Tables still on tenant_id: '.implode(', ', $stragglers));
    }

    /**
     * The invariant that actually matters: a model applying BelongsToTenant
     * must have the column its global scope filters on.
     *
     * Asserting "every ea_ table has organization_id" would be wrong — child
     * tables such as ea_kri_values, ea_maturity_responses and
     * ea_initiative_dependencies legitimately inherit tenancy from their
     * parent and carry no column of their own. Deriving the list from the
     * models rather than maintaining an exclusion list means a new model that
     * claims tenancy without the column fails here instead of at runtime.
     */
    public function test_every_model_claiming_tenancy_has_the_column_to_back_it(): void
    {
        $broken = [];
        $checked = 0;

        foreach (glob(app_path('Models/Ea/*.php')) as $file) {
            $class = 'App\\Models\\Ea\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }
            if (! in_array(\App\Models\Concerns\BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }

            $checked++;
            $table = (new $class)->getTable();

            if (! Schema::hasColumn($table, 'organization_id')) {
                $broken[] = "{$class} ({$table})";
            }
        }

        $this->assertGreaterThan(20, $checked, 'Expected the EA models to apply BelongsToTenant.');
        $this->assertSame([], $broken,
            'Models scoping on a column their table lacks: '.implode(', ', $broken));
    }

    public function test_child_tables_without_their_own_tenancy_column_are_still_queryable(): void
    {
        // These inherit tenancy through their parent. If the rename had caught
        // them by accident, or a scope were added without a column, these
        // counts would throw rather than return.
        $this->assertIsInt(\App\Models\Ea\KriValue::count());
        $this->assertIsInt(\App\Models\Ea\MaturityResponse::count());
        $this->assertIsInt(\App\Models\Ea\InitiativeDependency::count());
    }

    public function test_the_compatibility_accessor_reads_and_writes_the_renamed_column(): void
    {
        // §2.5 asks for "a compatibility accessor during migration" so code and
        // seeders still speaking the old name keep working.
        $capability = new Capability(['code' => 'CAP-COMPAT', 'name' => 'Compatibility']);
        $capability->tenant_id = 77;
        $capability->save();

        $this->assertSame(77, (int) $capability->getAttributes()['organization_id']);
        $this->assertSame(77, (int) $capability->fresh()->tenant_id);
        $this->assertSame(77, (int) $capability->fresh()->organization_id);
    }

    public function test_a_user_cannot_read_another_organisations_architecture(): void
    {
        $ours = Organization::factory()->create();
        $theirs = Organization::factory()->create();

        EaApplication::withoutGlobalScopes()->forceCreate([
            'code' => 'APP-OURS', 'name' => 'Ours', 'organization_id' => $ours->id,
        ]);
        EaApplication::withoutGlobalScopes()->forceCreate([
            'code' => 'APP-THEIRS', 'name' => 'Theirs', 'organization_id' => $theirs->id,
        ]);

        $this->actingAs(User::factory()->create(['organization_id' => $ours->id]));

        $visible = EaApplication::pluck('code');

        $this->assertContains('APP-OURS', $visible->all());
        $this->assertNotContains('APP-THEIRS', $visible->all());
    }

    public function test_null_tenancy_rows_are_shared_reference_data_visible_to_every_organisation(): void
    {
        // This is the `orWhereNull` branch §10 calls "a plausible leak path".
        // It is intended behaviour for platform-shared reference data
        // (ea_principles, ea_standards, ea_patterns per ATH-GAP-EA-001 §3.1) —
        // the test pins that intent so a future change has to be deliberate.
        $ours = Organization::factory()->create();
        $theirs = Organization::factory()->create();

        Capability::withoutGlobalScopes()->forceCreate([
            'code' => 'CAP-BIAN', 'name' => 'BIAN reference', 'organization_id' => null,
        ]);
        Capability::withoutGlobalScopes()->forceCreate([
            'code' => 'CAP-THEIRS', 'name' => 'Their capability', 'organization_id' => $theirs->id,
        ]);

        $this->actingAs(User::factory()->create(['organization_id' => $ours->id]));

        $visible = Capability::pluck('code')->all();

        $this->assertContains('CAP-BIAN', $visible, 'Shared reference data should be visible.');
        $this->assertNotContains('CAP-THEIRS', $visible, 'Another tenant\'s data must never be.');
    }

    public function test_a_created_record_inherits_the_actors_organisation(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAs(User::factory()->create(['organization_id' => $organization->id]));

        $capability = Capability::create(['code' => 'CAP-AUTO', 'name' => 'Auto-scoped']);

        $this->assertSame($organization->id, (int) $capability->organization_id);
    }

    public function test_ea_index_endpoints_do_not_leak_across_organisations(): void
    {
        // §10: "no cross-organisation leakage on every EA endpoint".
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $ours = Organization::factory()->create();
        $theirs = Organization::factory()->create();

        EaApplication::withoutGlobalScopes()->forceCreate([
            'code' => 'APP-LEAKCHECK', 'name' => 'Their Core Banking', 'organization_id' => $theirs->id,
        ]);
        Capability::withoutGlobalScopes()->forceCreate([
            'code' => 'CAP-LEAKCHECK', 'name' => 'Their Payments', 'organization_id' => $theirs->id,
        ]);

        $user = User::factory()->create(['organization_id' => $ours->id]);
        $user->assignRole('Enterprise Architect');
        $this->actingAs($user);

        foreach (['/ea/applications', '/ea/capabilities', '/ea/technology-radar', '/ea/interfaces'] as $uri) {
            $response = $this->get($uri);
            $response->assertOk();
            $response->assertDontSee('Their Core Banking', false);
            $response->assertDontSee('Their Payments', false);
        }
    }
}
