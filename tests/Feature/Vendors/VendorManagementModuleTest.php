<?php

namespace Tests\Feature\Vendors;

use App\Models\Ea\Relationship;
use App\Models\Organization;
use App\Models\SharedVendorDirectory;
use App\Models\TprmBreachEvent;
use App\Models\TprmSecurityRating;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Services\Vendors\SecurityRatingService;
use App\Services\Vendors\VendorTiering;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Vendor Management (ATH-VAL-008): tenant isolation of the register, ratings and breach watch;
 * per-bank codes and tenant-checked pickers; computed tiering and review cadence; assessment
 * completion resetting the review clock; sandbox rating refresh; breach triage opening an
 * incident-driven reassessment; the Shared Directory; role access; and the dashboard feed.
 */
class VendorManagementModuleTest extends TestCase
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

    private function vendor(array $attrs = [], ?Organization $org = null): Vendor
    {
        $org ??= $this->org;

        return Vendor::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $org->id,
            'vendor_code' => 'KHB-VEN-'.fake()->unique()->numerify('###'),
            'name' => 'Vendor '.fake()->unique()->numerify('###'),
            'category' => 'Payments',
            'risk_level' => 'medium',
            'status' => 'active',
            'data_access_level' => 'none',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Paystack Payments Ltd', 'risk_level' => 'medium', 'status' => 'active',
            'processes_personal_data' => false, 'material_outsourcing' => false];
    }

    public function test_register_ratings_and_breach_watch_are_isolated_per_bank(): void
    {
        $mine = $this->vendor(['name' => 'Kano vendor', 'domain' => 'kano-vendor.com']);
        $theirs = $this->vendor(['name' => 'Lagos vendor', 'domain' => 'lagos-vendor.com'], $this->other);
        app(SecurityRatingService::class)->capture($theirs, now());
        $event = TprmBreachEvent::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'vendor_id' => $theirs->id,
            'event_type' => 'breach', 'headline' => 'Other bank event', 'discovered_at' => now()]);

        $this->actingAs($this->admin)->get(route('vendors.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('vendors.total', 1)->where('vendors.data.0.id', $mine->id));
        $this->get(route('vendors.show', $theirs))->assertNotFound();
        $this->get(route('security-ratings.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('vendors', 1)->where('vendors.0.rating', null)->has('breaches', 0));
        $this->post(route('security-ratings.triage', $event), ['decision' => 'acknowledged'])->assertNotFound();
    }

    public function test_store_continues_the_bank_series_and_rejects_other_banks_pickers(): void
    {
        $this->vendor(['vendor_code' => 'KHB-VEN-035']);
        $this->vendor(['vendor_code' => 'REF-GLOMOBIL']);
        $outsider = User::factory()->create(['organization_id' => $this->other->id]);

        $this->actingAs($this->admin)->post(route('vendors.store'), $this->payload(['owner_id' => $outsider->id]))->assertSessionHasErrors('owner_id');
        $this->post(route('vendors.store'), $this->payload(['domain' => 'https://paystack.com/']))->assertSessionHasErrors('domain');
        $this->post(route('vendors.store'), $this->payload(['contract_start' => '2026-01-01', 'contract_end' => '2025-01-01']))->assertSessionHasErrors('contract_end');

        $this->post(route('vendors.store'), $this->payload(['owner_id' => $this->admin->id, 'domain' => 'paystack.com']))->assertRedirect();
        $this->assertSame('KHB-VEN-036', Vendor::withoutGlobalScopes()->where('name', 'Paystack Payments Ltd')->value('vendor_code'));

        $this->get(route('vendors.index', ['sort' => 'name; drop table vendors', 'direction' => 'up']))->assertOk();
        $this->actingAs($this->viewer)->post(route('vendors.store'), $this->payload())->assertForbidden();
    }

    public function test_tier_is_computed_from_the_record_and_sets_the_review_cadence(): void
    {
        $this->actingAs($this->admin)->post(route('vendors.store'), $this->payload([
            'name' => 'Core outsourcer', 'risk_level' => 'high', 'data_access_level' => 'full',
            'processes_personal_data' => true, 'material_outsourcing' => true,
        ]))->assertRedirect();
        $vendor = Vendor::withoutGlobalScopes()->where('name', 'Core outsourcer')->sole();
        $this->assertSame(1, $vendor->tier);
        // Never assessed: the onboarding assessment is due within the onboarding window.
        $this->assertSame(now()->addDays(30)->toDateString(), $vendor->next_review_date->toDateString());

        $this->post(route('vendors.store'), $this->payload(['name' => 'Stationery supplier', 'risk_level' => 'low']))->assertRedirect();
        $this->assertSame(3, Vendor::withoutGlobalScopes()->where('name', 'Stationery supplier')->value('tier'));

        // A critical application supplied in the EA repository raises the tier.
        $supplier = $this->vendor(['data_access_level' => 'limited', 'risk_level' => 'medium']);
        $this->assertSame(3, app(VendorTiering::class)->evaluate($supplier)['tier']);
        DB::table('ea_applications_ext')->insert(['organization_id' => $this->org->id, 'code' => 'APP-1', 'name' => 'Core', 'criticality' => 'critical',
            'vendor_id' => $supplier->id, 'created_at' => now(), 'updated_at' => now()]);
        $evaluation = app(VendorTiering::class)->evaluate($supplier);
        $this->assertSame(2, $evaluation['tier']);
        $this->assertContains('+2 supplies a critical application', $evaluation['reasons']);

        $this->get(route('vendors.show', $vendor))->assertOk()->assertInertia(fn (Assert $p) => $p->where('tiering.tier', 1)->has('tiering.reasons', 4));
    }

    public function test_completing_an_assessment_needs_evidence_and_resets_the_review_clock(): void
    {
        $vendor = $this->vendor(['tier' => 1, 'last_assessed' => now()->subMonths(14), 'next_review_date' => now()->subMonths(2)]);
        $theirs = $this->vendor([], $this->other);

        $this->actingAs($this->admin)->post(route('vendor-assessments.store'), [
            'vendor_id' => $theirs->id, 'title' => 'DDQ', 'assessment_type' => 'periodic',
        ])->assertSessionHasErrors('vendor_id');
        $this->post(route('vendor-assessments.store'), [
            'vendor_id' => $vendor->id, 'title' => 'DDQ', 'assessment_type' => 'periodic', 'status' => 'completed',
        ])->assertSessionHasErrors(['overall_score', 'findings']);

        $this->post(route('vendor-assessments.store'), ['vendor_id' => $vendor->id, 'title' => 'Annual DDQ', 'assessment_type' => 'periodic', 'status' => 'in_progress'])->assertRedirect();
        $assessment = VendorAssessment::withoutGlobalScopes()->where('title', 'Annual DDQ')->sole();

        $this->post(route('vendor-assessments.complete', $assessment), ['overall_score' => 72])->assertSessionHasErrors('findings');
        $this->post(route('vendor-assessments.complete', $assessment), ['overall_score' => 72, 'findings' => 'Patch cadence weak; MFA in place.'])->assertRedirect();

        $assessment->refresh();
        $vendor->refresh();
        $this->assertSame(['completed', 'medium'], [$assessment->status, $assessment->residual_rating]);
        $this->assertNotNull($assessment->completed_at);
        $this->assertSame(now()->toDateString(), $vendor->last_assessed->toDateString());
        $this->assertSame(now()->addMonths(12)->toDateString(), $vendor->next_review_date->toDateString());
        $this->assertSame($vendor->next_review_date->toDateString(), $assessment->next_review_date->toDateString());

        $this->post(route('vendor-assessments.complete', $assessment), ['overall_score' => 90, 'findings' => 'Again, later.'])->assertStatus(422);
        $this->actingAs($this->viewer)->post(route('vendor-assessments.complete', $assessment), ['overall_score' => 1, 'findings' => 'xxxxxxxxxxxx'])->assertForbidden();
    }

    public function test_rating_refresh_captures_once_a_day_with_deltas(): void
    {
        $rated = $this->vendor(['domain' => 'interswitch-limited.com']);
        $this->vendor(['domain' => null]);
        $this->vendor(['domain' => 'lagos-vendor.com'], $this->other);
        $service = app(SecurityRatingService::class);
        $service->capture($rated, now()->subMonth());

        $this->actingAs($this->admin)->post(route('security-ratings.refresh'))->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 captured, 1 skipped'));
        $latest = TprmSecurityRating::withoutGlobalScopes()->where('vendor_id', $rated->id)->latest('captured_at')->first();
        $previous = TprmSecurityRating::withoutGlobalScopes()->where('vendor_id', $rated->id)->oldest('captured_at')->first();

        $this->assertSame($this->org->id, (int) $latest->organization_id);
        $this->assertSame($latest->rating_value - $previous->rating_value, $latest->delta_from_previous);
        $this->assertSame(TprmSecurityRating::gradeFor($latest->rating_value), $latest->grade);
        $this->assertSame(0, TprmSecurityRating::withoutGlobalScopes()->where('organization_id', $this->other->id)->count());

        // A second refresh the same day captures nothing new.
        $this->post(route('security-ratings.refresh'))->assertSessionHas('success', fn ($m) => str_contains($m, '0 captured'));
        $this->actingAs($this->viewer)->post(route('security-ratings.refresh'))->assertForbidden();
    }

    public function test_breach_triage_can_open_an_incident_driven_reassessment(): void
    {
        $vendor = $this->vendor(['tier' => 1]);
        $make = fn () => TprmBreachEvent::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'vendor_id' => $vendor->id,
            'event_type' => 'breach', 'severity' => 'critical', 'headline' => 'Simulated credential dump', 'discovered_at' => now()]);
        $first = $make();
        $second = $make();

        $this->actingAs($this->admin)->post(route('security-ratings.triage', $first), ['decision' => 'reassessment', 'notes' => 'Ask for incident report'])->assertRedirect();
        $first->refresh();
        $this->assertSame(['reassessment', $this->admin->id], [$first->status, (int) $first->reviewed_by]);
        $reassessment = VendorAssessment::withoutGlobalScopes()->where('breach_event_id', $first->id)->sole();
        $this->assertSame(['incident_driven', 'pending', $vendor->id], [$reassessment->assessment_type, $reassessment->status, (int) $reassessment->vendor_id]);

        $this->post(route('security-ratings.triage', $second), ['decision' => 'dismissed'])->assertSessionHasErrors('notes');
        $this->post(route('security-ratings.triage', $second), ['decision' => 'dismissed', 'notes' => 'Defensive registration'])->assertRedirect();
        $this->post(route('security-ratings.triage', $second), ['decision' => 'acknowledged'])->assertStatus(422);
    }

    public function test_shared_directory_links_and_prefills_without_writing_bank_data(): void
    {
        $entry = SharedVendorDirectory::create(['slug' => 'nibss', 'legal_name' => 'NIBSS', 'category' => 'Payments Infrastructure', 'country' => 'NG',
            'baseline_ddq_version' => 'v1.0', 'description' => 'Owner of NIP.']);
        $mine = $this->vendor(['name' => 'NIBSS Plc']);
        $theirs = $this->vendor(['name' => 'NIBSS (other bank)', 'shared_directory_id' => $entry->id], $this->other);

        $this->actingAs($this->admin)->get(route('shared-vendors.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('entries', 1)->has('entries.0.linked', 0)->has('unlinked', 1));
        $this->post(route('shared-vendors.link', $entry), ['vendor_id' => $theirs->id])->assertSessionHasErrors('vendor_id');
        $this->post(route('shared-vendors.link', $entry), ['vendor_id' => $mine->id])->assertRedirect();
        $this->assertSame($entry->id, (int) $mine->fresh()->shared_directory_id);

        $this->get(route('vendors.create', ['directory' => $entry->id]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('prefill.name', 'NIBSS')->where('prefill.shared_directory_id', $entry->id));
        $this->assertSame('NIBSS', $entry->fresh()->legal_name);
    }

    public function test_vendor_risk_manager_and_dpco_roles_can_open_the_module(): void
    {
        $tprm = $this->user('Risk Manager');
        $dpco = $this->user('Compliance Officer');
        $vendor = $this->vendor();

        $this->actingAs($tprm)->get(route('vendors.index'))->assertOk();
        $this->post(route('vendors.store'), $this->payload())->assertRedirect();
        $this->get(route('security-ratings.index'))->assertOk();
        $this->delete(route('vendors.destroy', $vendor))->assertForbidden();

        $this->actingAs($dpco)->get(route('vendors.show', $vendor))->assertOk();
        $this->get(route('vendor-assessments.index'))->assertOk();
        $this->post(route('vendors.store'), $this->payload())->assertForbidden();
    }

    public function test_vendor_edges_without_an_organisation_are_not_platform_shared(): void
    {
        $vendor = $this->vendor([], $this->other);
        DB::table('ea_relationships')->insert(['organization_id' => null, 'source_type' => Vendor::class, 'source_id' => $vendor->id,
            'target_type' => 'App\\Models\\Ea\\EaApplication', 'target_id' => 1, 'relation_type' => 'serving', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin);
        $this->assertSame(1, Relationship::query()->where('source_type', Vendor::class)->count(), 'A NULL-organisation edge reads as platform-shared.');

        $migration = require database_path('migrations/2027_01_17_000001_vendor_management_module.php');
        $this->assertSame(1, $migration->repairVendorEdges());

        $this->assertSame(0, Relationship::query()->where('source_type', Vendor::class)->count(), 'Another bank\'s vendor edge must not be visible.');
    }

    public function test_dashboard_feeds_vendor_metrics(): void
    {
        $this->vendor(['tier' => 1, 'next_review_date' => now()->subDay(), 'processes_personal_data' => true, 'domain' => 'a-vendor.com']);
        $this->vendor(['tier' => 2, 'next_review_date' => now()->addYear(), 'contract_end' => now()->addDays(30), 'material_outsourcing' => true, 'regulator_notified_at' => now()]);
        $this->vendor(['tier' => 1, 'status' => 'terminated', 'next_review_date' => now()->subYear()]);
        $this->vendor(['tier' => 1, 'next_review_date' => now()->subDay()], $this->other);
        app(SecurityRatingService::class)->refresh($this->admin);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('vendors.total', 2)->where('vendors.by_tier.1', 1)->where('vendors.review_overdue', 1)
                ->where('vendors.missing_dpa', 1)->where('vendors.contracts_expiring', 1)->where('vendors.material_not_notified', 0)
                ->where('vendors.rated', 1)->has('vendorScoreboard', 2));
    }
}
