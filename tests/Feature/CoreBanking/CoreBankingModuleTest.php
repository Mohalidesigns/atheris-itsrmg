<?php

namespace Tests\Feature\CoreBanking;

use App\Models\Asset;
use App\Models\CoreBankingIntegration;
use App\Models\FeatureFlag;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingConnector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Core Banking (ATH-VAL-017): per-bank isolation, sandbox sync through an artefact allowlist with
 * the customer-data embargo, refusal when the adapter is switched off, unapproved platform changes
 * raising one issue, and the permission gate on sync.
 */
class CoreBankingModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin');
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function integration(string $product = 'bankone', ?Organization $org = null, array $attrs = []): CoreBankingIntegration
    {
        return CoreBankingIntegration::withoutGlobalScopes()->create($attrs + [
            'organization_id' => ($org ?? $this->org)->id, 'product' => $product, 'name' => strtoupper($product).' test',
            'environment' => 'production', 'mode' => 'sandbox', 'status' => 'connected', 'owner_id' => $this->admin->id,
        ]);
    }

    public function test_integrations_and_snapshots_are_isolated_per_bank(): void
    {
        $mine = $this->integration('finacle');
        $theirs = $this->integration('finacle', $this->other);
        $this->actingAs($this->admin);
        app(CoreBankingConnector::class)->sync($mine);

        $this->get(route('core-banking.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('integrations', 1)->where('integrations.0.id', $mine->id));
        $this->get(route('core-banking.show', $theirs->id))->assertNotFound();
        $this->post(route('core-banking.sync', $theirs->id))->assertNotFound();
        $this->get(route('core-banking.snapshots'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('snapshots', 1));
    }

    public function test_sync_stores_only_allowlisted_artefacts_and_records_the_embargo(): void
    {
        $this->actingAs($this->admin);
        $integration = $this->integration('bankone');

        $snapshot = app(CoreBankingConnector::class)->sync($integration, $this->admin);

        $this->assertArrayNotHasKey('customer_records', $snapshot->artefacts);
        $this->assertContains('customer_records', $snapshot->embargoed_keys);
        $this->assertEmpty(array_diff(array_keys($snapshot->artefacts), CoreBankingConnector::ALLOWED));
        $this->assertArrayHasKey('interfaces', $snapshot->artefacts);
        $this->assertSame('sandbox', $snapshot->mode);
        $integration->refresh();
        $this->assertNotNull($integration->last_sync_at);
        $this->assertSame(count($snapshot->findings), $integration->findings_count);
    }

    public function test_sync_is_refused_when_the_adapter_flag_is_off_or_the_integration_is_disabled(): void
    {
        $this->actingAs($this->admin);
        FeatureFlag::create(['key' => 'core_banking.t24', 'organization_id' => null, 'enabled' => false, 'rollout_percent' => 0]);
        $t24 = $this->integration('t24');
        $off = $this->integration('flexcube', null, ['status' => 'disabled']);

        foreach ([$t24, $off] as $i) {
            try {
                app(CoreBankingConnector::class)->sync($i);
                $this->fail('Sync should have been refused.');
            } catch (ValidationException) {
                $this->assertSame('failed', $i->fresh()->last_status);
                $this->assertNotEmpty($i->fresh()->last_error);
                $this->assertSame(0, $i->snapshots()->count());
            }
        }
    }

    public function test_unapproved_platform_change_raises_one_issue(): void
    {
        $this->actingAs($this->admin);
        $asset = Asset::factory()->create(['organization_id' => $this->org->id, 'owner_id' => $this->admin->id, 'status' => 'active']);
        $integration = $this->integration('interswitch', null, ['asset_id' => $asset->id]);
        $connector = app(CoreBankingConnector::class);

        $this->travel(-10)->days();
        $connector->sync($integration);
        $this->travelBack();
        $this->assertSame(0, Issue::where('source_type', 'core_banking')->count());

        $integration->update(['config' => ['sandbox' => ['platform' => ['patch_level' => 'p10']]]]);
        $snapshot = $connector->sync($integration->fresh());
        $this->assertContains('Unapproved platform change', array_column($snapshot->findings, 'title'));
        $this->assertSame('platform.patch_level', $snapshot->changes[0]['key']);

        $connector->sync($integration->fresh());
        $connector->sync($integration->fresh());
        $this->assertSame(1, Issue::where('source_type', 'core_banking')->where('source_id', $integration->id)->count());
        $this->assertNotNull($integration->fresh()->issue_id);
    }

    public function test_a_viewer_can_see_but_not_sync_or_add(): void
    {
        $integration = $this->integration('finacle');
        $viewer = $this->user('Viewer');

        $this->actingAs($viewer)->get(route('core-banking.index'))->assertOk();
        $this->actingAs($viewer)->post(route('core-banking.sync', $integration->id))->assertForbidden();
        $this->actingAs($viewer)->post(route('core-banking.store'), ['product' => 'finacle', 'name' => 'X', 'environment' => 'dr', 'status' => 'pending'])->assertForbidden();
        $this->assertNull($integration->fresh()->last_sync_at);

        $this->actingAs($this->admin)->post(route('core-banking.store'), ['product' => 'finacle', 'name' => 'New', 'environment' => 'dr', 'status' => 'pending'])->assertRedirect();
        $this->assertSame(1, CoreBankingIntegration::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'New')->count());
    }
}
