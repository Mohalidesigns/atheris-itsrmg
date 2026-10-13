<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetSyncJob;
use App\Models\Ea\BusinessService;
use App\Models\Ea\Relationship;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vulnerability;
use App\Services\Ea\AssetApplicationClient;
use App\Services\SecurityOps\VulnerabilityPrioritiser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Asset Management (ATH-VAL-007): tenant isolation of the register, discovery history and business
 * services; tenant-checked pickers and per-bank codes; criticality inherited from the business
 * services an asset supports (and read by the vulnerability prioritiser); sandbox connector runs
 * and CSV import through one reconciler; the discovery review queue; service dependencies with
 * cycle refusal; the export; and the dashboard feed.
 */
class AssetManagementModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin');
        $this->viewer = $this->user('Viewer');
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function asset(array $attrs = [], ?Organization $org = null): Asset
    {
        $org ??= $this->org;

        return Asset::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $org->id,
            'asset_id_code' => 'KHB-AST-SV-'.fake()->unique()->numerify('###'),
            'name' => 'App server '.fake()->unique()->numerify('###'),
            'asset_type' => 'hardware',
            'category' => 'Server',
            'criticality' => 'medium',
            'status' => 'active',
            'hostname' => 'srv-'.fake()->unique()->numerify('####'),
        ]);
    }

    private function service(array $attrs = [], ?Organization $org = null): BusinessService
    {
        $org ??= $this->org;

        return BusinessService::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $org->id,
            'code' => 'BS-'.fake()->unique()->numerify('###'),
            'name' => 'Service '.fake()->unique()->numerify('###'),
            'criticality' => 'critical',
            'status' => 'active',
            'rto_minutes' => 15,
        ]);
    }

    private function capability(?Organization $org = null): int
    {
        return DB::table('ea_capabilities')->insertGetId([
            'organization_id' => ($org ?? $this->org)->id, 'code' => 'CAP-'.fake()->unique()->numerify('###'), 'name' => 'Payments',
            'level' => 1, 'criticality' => 'high', 'maturity' => 2, 'source' => 'custom', 'version_no' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assetPayload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Ikeja branch file server', 'asset_type' => 'hardware', 'criticality' => 'medium', 'status' => 'active'];
    }

    public function test_register_and_records_are_isolated_per_bank(): void
    {
        $mine = $this->asset(['name' => 'Kano file server']);
        $theirs = $this->asset(['name' => 'Lagos file server'], $this->other);
        $theirService = $this->service([], $this->other);
        AssetSyncJob::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'source' => 'aws', 'status' => 'completed', 'finished_at' => now()]);

        $this->actingAs($this->admin)->get(route('assets.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('assets.total', 1)->where('assets.data.0.id', $mine->id));
        $this->get(route('assets.show', $theirs))->assertNotFound();
        $this->get(route('business-assets.show', $theirService->id))->assertNotFound();
        $this->put(route('assets.update', $theirs), $this->assetPayload())->assertNotFound();
        $this->get(route('asset-discovery.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('jobs', 0));
        $this->get(route('business-services.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('services', 0));
    }

    public function test_index_sort_is_whitelisted_and_retired_assets_are_hidden_by_default(): void
    {
        $this->asset(['name' => 'B server']);
        $this->asset(['name' => 'A server']);
        $this->asset(['name' => 'Old server', 'status' => 'decommissioned']);

        $this->actingAs($this->admin)->get(route('assets.index', ['sort' => 'name']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('assets.total', 2)->where('assets.data.0.name', 'A server'));
        $this->get(route('assets.index', ['sort' => 'password; drop table assets', 'direction' => 'sideways']))->assertOk();
        $this->get(route('assets.index', ['status' => 'decommissioned']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('assets.total', 1));
    }

    public function test_store_continues_the_bank_series_and_rejects_other_banks_pickers(): void
    {
        $this->asset(['asset_id_code' => 'KHB-AST-CB-001']);
        $outsider = User::factory()->create(['organization_id' => $this->other->id]);
        $theirService = $this->service([], $this->other);

        $this->actingAs($this->admin)->post(route('assets.store'), $this->assetPayload(['owner_id' => $outsider->id]))->assertSessionHasErrors('owner_id');
        $this->post(route('assets.store'), $this->assetPayload(['business_service_ids' => [$theirService->id]]))->assertSessionHasErrors('business_service_ids.0');
        $this->post(route('assets.store'), $this->assetPayload(['purchase_date' => '2025-01-01', 'end_of_life' => '2024-01-01']))->assertSessionHasErrors('end_of_life');
        $this->post(route('assets.store'), $this->assetPayload(['ip_address' => '10.0.0.999']))->assertSessionHasErrors('ip_address');

        $this->post(route('assets.store'), $this->assetPayload(['owner_id' => $this->admin->id]))->assertRedirect();
        $this->post(route('assets.store'), $this->assetPayload(['name' => 'Second']))->assertRedirect();

        $codes = Asset::withoutGlobalScopes()->where('organization_id', $this->org->id)->orderBy('id')->pluck('asset_id_code')->all();
        $this->assertSame(['KHB-AST-CB-001', 'KHB-AST-0001', 'KHB-AST-0002'], $codes);
        $this->assertSame('manual', Asset::withoutGlobalScopes()->where('asset_id_code', 'KHB-AST-0001')->value('source'));

        $this->actingAs($this->viewer)->post(route('assets.store'), $this->assetPayload())->assertForbidden();
    }

    public function test_assets_inherit_the_criticality_of_the_services_they_support(): void
    {
        $asset = $this->asset(['criticality' => 'medium']);
        $service = $this->service(['criticality' => 'high']);

        $this->actingAs($this->admin)->put(route('assets.services', $asset), ['business_service_ids' => [$service->id]])->assertRedirect();
        $asset->refresh();
        $this->assertSame('high', $asset->criticality_inherited);
        $this->assertSame('high', $asset->effective_criticality);
        $this->assertSame('medium', $asset->criticality, "The owner's own rating is never overwritten.");

        // Raising the service's criticality re-derives every supporting asset.
        $this->put(route('business-assets.update', $service->id), ['name' => $service->name, 'criticality' => 'critical'])->assertRedirect();
        $this->assertSame('critical', $asset->fresh()->effective_criticality);

        // The vulnerability prioritiser weighs the effective rating.
        $vuln = Vulnerability::factory()->create(['organization_id' => $this->org->id, 'severity' => 'high', 'status' => 'open', 'cve_id' => null]);
        $vuln->assets()->attach($asset->id);
        $ranked = app(VulnerabilityPrioritiser::class)->rank(collect([$vuln->load('assets')]))->first();
        $this->assertSame('critical', $ranked['asset']['criticality']);

        // Unlinking drops the inheritance.
        $this->put(route('assets.services', $asset), ['business_service_ids' => []])->assertRedirect();
        $this->assertNull($asset->fresh()->criticality_inherited);
        $this->assertSame('medium', $asset->fresh()->effective_criticality);
    }

    public function test_connector_sync_reconciles_into_the_register_and_queues_new_finds(): void
    {
        $known = $this->asset(['category' => 'Server', 'hostname' => 'dc-01']);

        $this->actingAs($this->admin)->post(route('asset-discovery.sync'), ['source' => 'ad'])->assertRedirect(route('asset-discovery.index'));

        $job = AssetSyncJob::withoutGlobalScopes()->sole();
        $this->assertSame($this->org->id, (int) $job->organization_id);
        $this->assertSame($this->admin->id, (int) $job->triggered_by);
        $this->assertSame('sandbox', $job->mode);
        $this->assertSame(1, $job->records_updated);
        $this->assertSame(2, $job->records_created);
        $this->assertSame(3, $job->records_imported);

        $known->refresh();
        $this->assertSame('ad', $known->source);
        $this->assertNotNull($known->external_id);
        $this->assertNotNull($known->last_seen_at);

        $finds = Asset::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('status', 'under_review')->get();
        $this->assertCount(2, $finds);
        $this->assertTrue($finds->every(fn ($a) => $a->owner_id === null && $a->source === 'ad'));

        // A second run re-confirms everything and creates nothing.
        $this->post(route('asset-discovery.sync'), ['source' => 'ad']);
        $second = AssetSyncJob::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(0, $second->records_created);
        $this->assertSame(3, $second->records_updated);

        $this->post(route('asset-discovery.sync'), ['source' => 'servicenow'])->assertSessionHasErrors('source');
        $this->actingAs($this->viewer)->post(route('asset-discovery.sync'), ['source' => 'ad'])->assertForbidden();
        $this->actingAs($this->viewer)->get(route('asset-discovery.index'))->assertOk();
    }

    public function test_csv_import_creates_valid_rows_and_reports_rejected_ones(): void
    {
        $existing = $this->asset(['name' => 'Branch router Ikeja', 'hostname' => 'rtr-ikj-01']);
        $outsider = User::factory()->create(['organization_id' => $this->other->id, 'email' => 'someone@otherbank.ng']);

        $csv = implode("\n", [
            'name,asset_type,criticality,owner_email,hostname,ip_address,end_of_life',
            "Branch file server,hardware,high,{$this->admin->email},fs-01,10.1.1.20,2030-01-01",
            'Branch router Ikeja (refresh),Hardware,low,,rtr-ikj-01,,',
            'Teller laptops,laptop,low,,,,',
            "CCTV recorder,hardware,low,{$outsider->email},,,",
            'Badly addressed host,hardware,low,,,10.0.0.999,',
        ]);
        $file = UploadedFile::fake()->createWithContent('inventory.csv', $csv);

        $this->actingAs($this->admin)->post(route('asset-discovery.import'), ['file' => $file])->assertRedirect();

        $job = AssetSyncJob::withoutGlobalScopes()->sole();
        $this->assertSame('csv', $job->source);
        $this->assertSame('inventory.csv', $job->file_name);
        $this->assertSame(1, $job->records_created);
        $this->assertSame(1, $job->records_updated);
        $this->assertSame(3, $job->records_skipped);
        $this->assertCount(3, $job->errors);
        $this->assertStringContainsString('laptop', $job->errors[0]);
        $this->assertStringContainsString('not a user of this bank', $job->errors[1]);

        $created = Asset::withoutGlobalScopes()->where('name', 'Branch file server')->sole();
        $this->assertSame([$this->org->id, $this->admin->id, 'active', 'high', 'csv'],
            [(int) $created->organization_id, (int) $created->owner_id, $created->status, $created->criticality, $created->source]);
        $this->assertSame('low', $existing->fresh()->criticality, 'A hostname match updates the existing asset instead of duplicating it.');

        $this->post(route('asset-discovery.import'), ['file' => UploadedFile::fake()->createWithContent('bad.csv', "hostname,ip\nx,y")])->assertRedirect();
        $this->assertStringContainsString('Missing required column', AssetSyncJob::withoutGlobalScopes()->latest('id')->first()->errors[0]);
    }

    public function test_review_queue_accepts_with_an_owner_or_rejects_with_a_reason(): void
    {
        $find = $this->asset(['status' => 'under_review', 'source' => 'tenable', 'owner_id' => null]);
        $junk = $this->asset(['status' => 'under_review', 'source' => 'entra', 'owner_id' => null]);
        $live = $this->asset();

        $this->actingAs($this->admin)->post(route('assets.review', $find), ['decision' => 'accept'])->assertSessionHasErrors('owner_id');
        $this->post(route('assets.review', $find), ['decision' => 'accept', 'owner_id' => $this->admin->id, 'criticality' => 'high'])->assertRedirect();
        $find->refresh();
        $this->assertSame(['active', $this->admin->id, 'high', $this->admin->id], [$find->status, (int) $find->owner_id, $find->criticality, (int) $find->reviewed_by]);

        $this->post(route('assets.review', $junk), ['decision' => 'reject'])->assertSessionHasErrors('reason');
        $this->post(route('assets.review', $junk), ['decision' => 'reject', 'reason' => 'Personal test app'])->assertRedirect();
        $this->assertSame('decommissioned', $junk->fresh()->status);
        $this->assertStringContainsString('Personal test app', $junk->fresh()->description);

        $this->post(route('assets.review', $live), ['decision' => 'reject', 'reason' => 'x'])->assertStatus(422);
        $this->actingAs($this->viewer)->post(route('assets.review', $find), ['decision' => 'reject', 'reason' => 'x'])->assertForbidden();
    }

    public function test_business_services_validate_against_the_banks_canonical_capabilities(): void
    {
        $mine = $this->capability();
        $theirs = $this->capability($this->other);

        $this->actingAs($this->admin)->post(route('business-assets.store'), ['name' => 'NIP', 'criticality' => 'critical', 'capability_id' => $theirs])
            ->assertSessionHasErrors('capability_id');
        $this->post(route('business-assets.store'), ['name' => 'NIP', 'criticality' => 'critical', 'capability_id' => $mine, 'recovery_time_objective_min' => 15])
            ->assertRedirect();

        $service = BusinessService::withoutGlobalScopes()->where('name', 'NIP')->sole();
        $this->assertSame([$this->org->id, $mine, 15, 'BS-001'], [(int) $service->organization_id, (int) $service->capability_id, (int) $service->rto_minutes, $service->code]);
    }

    public function test_service_dependencies_are_canonical_edges_and_cycles_are_refused(): void
    {
        $core = $this->service(['name' => 'Core banking', 'rto_minutes' => 60]);
        $mobile = $this->service(['name' => 'Mobile banking', 'rto_minutes' => 15]);
        $theirs = $this->service([], $this->other);

        $this->actingAs($this->admin)->post(route('business-assets.dependencies.store', $mobile->id), ['provider_id' => $core->id])->assertRedirect();
        $edges = fn () => Relationship::withoutGlobalScopes()->where('source_type', BusinessService::class);
        $edge = $edges()->sole();
        $this->assertSame([BusinessService::class, $core->id, BusinessService::class, $mobile->id, 'serving'],
            [$edge->source_type, (int) $edge->source_id, $edge->target_type, (int) $edge->target_id, $edge->relation_type]);

        // Core already serves mobile, so core cannot depend on mobile.
        $this->post(route('business-assets.dependencies.store', $core->id), ['provider_id' => $mobile->id])->assertSessionHas('error');
        $this->post(route('business-assets.dependencies.store', $mobile->id), ['provider_id' => $theirs->id])->assertSessionHasErrors('provider_id');
        $this->assertSame(1, $edges()->count());

        $this->get(route('business-assets.show', $mobile->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('upstream', 1)->where('rtoConflicts.0', 'Core banking'));
        $this->get(route('business-services.graph'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('services', 2)->has('dependencies', 1)->where('dependencies.0.provider_id', $core->id));

        // Deleting a service removes its edges and re-derives its assets' inherited rating.
        $asset = $this->asset();
        $this->put(route('business-assets.assets', $core->id), ['asset_ids' => [$asset->id]])->assertRedirect();
        $this->assertSame('critical', $asset->fresh()->criticality_inherited);
        $this->delete(route('business-assets.dependencies.destroy', [$mobile->id, $edge->id]))->assertRedirect();
        $this->assertSame(0, $edges()->count());
        $this->delete(route('business-assets.destroy', $core->id))->assertRedirect();
        $this->assertNull($asset->fresh()->criticality_inherited);
    }

    public function test_export_is_gated_and_tenant_scoped(): void
    {
        $this->asset(['asset_id_code' => 'KHB-AST-SV-901', 'name' => 'Kano export row']);
        $this->asset(['asset_id_code' => 'FBN-AST-SV-901', 'name' => 'Other bank row'], $this->other);

        $csv = $this->actingAs($this->admin)->get(route('assets.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('KHB-AST-SV-901', $csv);
        $this->assertStringNotContainsString('FBN-AST-SV-901', $csv);

        $this->actingAs($this->viewer)->get(route('assets.export'))->assertForbidden();
    }

    public function test_ea_asset_catalogue_cache_is_per_bank(): void
    {
        $this->asset(['asset_type' => 'software', 'name' => 'Kano Finacle']);
        $this->asset(['asset_type' => 'software', 'name' => 'Lagos Finacle'], $this->other);
        $client = new AssetApplicationClient;

        $this->actingAs($this->admin);
        $this->assertSame(['Kano Finacle'], collect($client->catalogue()['rows'])->pluck('name')->all());

        $this->actingAs($this->user('Organization Admin', $this->other));
        $this->assertSame(['Lagos Finacle'], collect($client->catalogue()['rows'])->pluck('name')->all());
    }

    public function test_dashboard_and_register_share_asset_metrics(): void
    {
        $this->asset(['criticality' => 'critical', 'owner_id' => null]);
        $this->asset(['criticality' => 'low', 'end_of_life' => now()->subDays(10)->toDateString(), 'owner_id' => $this->admin->id]);
        $this->asset(['status' => 'under_review', 'source' => 'aws', 'owner_id' => null]);
        $this->asset(['criticality' => 'critical'], $this->other);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('assets.stats.total', 3)->where('assets.stats.by_criticality.critical', 1)
                ->where('assets.stats.unowned', 2)->where('assets.stats.eol_passed', 1)->where('assets.stats.pending_review', 1)
                ->where('assets.stats.important_unmapped', 1)->has('assets.eol', 1));

        $this->get(route('assets.index', ['flag' => 'eol_passed']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('assets.total', 1)->where('metrics.eol_passed', 1));
    }
}
