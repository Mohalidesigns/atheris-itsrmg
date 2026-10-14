<?php

namespace Tests\Feature\Pci;

use App\Models\Asset;
use App\Models\ComplianceAssessment;
use App\Models\ComplianceResult;
use App\Models\Control;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\User;
use App\Services\Pci\PciPosture;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PCI Management (ATH-VAL-011): the PCI DSS v4.0.1 framework, posture read from the bank's own
 * assessment, CDE scoping with rationale, control mapping, evidence, and tenant isolation. Nothing
 * on these pages is generated any more.
 */
class PciModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private int $fw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin');
        $this->fw = app(PciPosture::class)->frameworkId();
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function sub(string $code): int
    {
        return DB::table('framework_requirements')->where('framework_id', $this->fw)->where('requirement_code', $code)->value('id');
    }

    private function control(string $effectiveness = 'effective', ?Organization $org = null): Control
    {
        return Control::withoutGlobalScopes()->create([
            'organization_id' => ($org ?? $this->org)->id, 'control_code' => 'CTL-'.fake()->unique()->numerify('###'),
            'title' => 'Firewall rule review', 'domain' => 'Governance', 'status' => 'active', 'effectiveness' => $effectiveness,
        ]);
    }

    /** A completed PCI assessment with the given results (code => status); everything else not tested. */
    private function assessment(array $results, ?Organization $org = null): ComplianceAssessment
    {
        $org ??= $this->org;
        $assessment = ComplianceAssessment::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'framework_id' => $this->fw, 'title' => 'ROC', 'status' => 'completed',
            'start_date' => now()->subMonths(5), 'end_date' => now()->subMonths(4),
            'total_requirements' => 0, 'compliant_count' => 0, 'partial_count' => 0, 'non_compliant_count' => 0, 'not_applicable_count' => 0,
        ]);
        foreach (ComplianceAssessment::assessableRequirements($this->fw) as $req) {
            ComplianceResult::create(['assessment_id' => $assessment->id, 'requirement_id' => $req->id, 'status' => $results[$req->requirement_code] ?? 'not_assessed']);
        }

        return $assessment;
    }

    public function test_the_pci_dss_framework_is_installed_with_its_sub_requirements(): void
    {
        $this->assertNotNull($this->fw);
        $this->assertSame(12, DB::table('framework_requirements')->where('framework_id', $this->fw)->whereNull('parent_id')->count());
        $this->assertSame(63, ComplianceAssessment::assessableRequirements($this->fw)->count());
        $this->assertNotNull($this->sub('12.10'));
    }

    public function test_dashboard_reads_the_banks_own_assessment(): void
    {
        $this->assessment(['1.1' => 'compliant', '1.2' => 'compliant', '1.3' => 'non_compliant', '2.3' => 'not_applicable']);
        $this->assessment(array_fill_keys(['1.1', '1.2', '1.3'], 'non_compliant'), $this->other);

        $this->actingAs($this->admin)->get(route('pci.dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('overall', 67)
            ->where('requirements.0.code', '1')->where('requirements.0.in_place', 2)->where('requirements.0.tested', 3)->where('requirements.0.not_in_place', 1)
            ->where('requirements.1.pct', null)
            ->where('nextValidationDue', now()->subMonths(4)->addMonths(12)->toDateString()));

        // The page is stable: nothing random between loads.
        $first = $this->get(route('pci.matrix'))->viewData('page')['props']['requirements'];
        $second = $this->get(route('pci.matrix'))->viewData('page')['props']['requirements'];
        $this->assertEquals($first, $second);
    }

    public function test_cde_scoping_needs_a_rationale_and_is_tenant_scoped(): void
    {
        $atm = Asset::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'asset_id_code' => 'AST-1', 'name' => 'ATM fleet', 'asset_type' => 'hardware', 'criticality' => 'critical', 'status' => 'active']);
        $theirs = Asset::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'asset_id_code' => 'AST-1', 'name' => 'Their ATM', 'asset_type' => 'hardware', 'criticality' => 'critical', 'status' => 'active']);

        $this->actingAs($this->admin)->put(route('pci.cde.scope', $atm), ['pci_scope' => 'cde'])->assertSessionHasErrors('pci_scope_note');
        $this->put(route('pci.cde.scope', $atm), ['pci_scope' => 'card_data', 'pci_scope_note' => 'Reads card data at the terminal.'])->assertSessionHasErrors('pci_scope');
        $this->put(route('pci.cde.scope', $atm), ['pci_scope' => 'cde', 'pci_scope_note' => 'Reads card data and PINs at the terminal.'])->assertRedirect();
        $this->assertSame('cde', $atm->fresh()->pci_scope);
        $this->put(route('pci.cde.scope', $theirs), ['pci_scope' => 'cde', 'pci_scope_note' => 'xxxxxxxxxxxx'])->assertNotFound();

        $this->get(route('pci.cde', ['scope' => 'cde']))->assertOk()->assertInertia(fn (Assert $p) => $p->has('assets', 1)->where('scoping.cde', 1));
        $this->actingAs($this->user('Viewer'))->put(route('pci.cde.scope', $atm), ['pci_scope' => 'out_of_scope', 'pci_scope_note' => 'xxxxxxxxxxxx'])->assertForbidden();
    }

    public function test_controls_map_to_sub_requirements_within_the_bank(): void
    {
        $mine = $this->control('ineffective');
        $theirs = $this->control('effective', $this->other);
        $isoReq = DB::table('framework_requirements')->insertGetId(['framework_id' => DB::table('control_frameworks')->insertGetId([
            'name' => 'Other', 'slug' => 'other-fw', 'short_name' => 'O', 'category' => 'security', 'is_system' => true, 'is_active' => true, 'sort_order' => 0,
        ]), 'requirement_code' => 'X.1', 'title' => 'x', 'level' => 1, 'is_mandatory' => true, 'sort_order' => 0]);

        $this->actingAs($this->admin)->post(route('pci.controls.map'), ['requirement_id' => $this->sub('1.2'), 'control_id' => $theirs->id])->assertSessionHasErrors('control_id');
        $this->post(route('pci.controls.map'), ['requirement_id' => $isoReq, 'control_id' => $mine->id])->assertSessionHasErrors('requirement_id');
        $this->post(route('pci.controls.map'), ['requirement_id' => $this->sub('1.2'), 'control_id' => $mine->id])->assertRedirect();
        $this->post(route('pci.controls.map'), ['requirement_id' => $this->sub('1.2'), 'control_id' => $mine->id])->assertRedirect();
        $this->assertSame(1, DB::table('control_framework_mappings')->where('control_id', $mine->id)->count(), 'Mapping twice does not duplicate.');

        $this->get(route('pci.dashboard'))->assertInertia(fn (Assert $p) => $p->where('controls.mapped', 1)->where('controls.ineffective', 1)->where('controls.unmapped_subs', 62));

        $mapping = DB::table('control_framework_mappings')->where('control_id', $mine->id)->value('id');
        $theirMapping = DB::table('control_framework_mappings')->insertGetId(['control_id' => $theirs->id, 'requirement_id' => $this->sub('1.2'), 'coverage' => 'full']);
        $this->delete(route('pci.controls.unmap', $theirMapping))->assertNotFound();
        $this->delete(route('pci.controls.unmap', $mapping))->assertRedirect();
        $this->assertSame(0, DB::table('control_framework_mappings')->where('control_id', $mine->id)->count());
    }

    public function test_evidence_is_the_banks_own_and_tied_to_requirements(): void
    {
        $assessment = $this->assessment(['11.4' => 'compliant']);
        $result = ComplianceResult::where('assessment_id', $assessment->id)->where('requirement_id', $this->sub('11.4'))->sole();
        Evidence::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'evidenceable_type' => ComplianceResult::class, 'evidenceable_id' => $result->id,
            'title' => 'Pen test report', 'type' => 'document', 'status' => 'approved', 'valid_until' => now()->subDay(), 'uploaded_by' => $this->admin->id]);
        $theirs = $this->assessment(['11.4' => 'compliant'], $this->other);
        $theirResult = ComplianceResult::where('assessment_id', $theirs->id)->where('requirement_id', $this->sub('11.4'))->sole();
        Evidence::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'evidenceable_type' => ComplianceResult::class, 'evidenceable_id' => $theirResult->id,
            'title' => 'Their pen test', 'type' => 'document', 'status' => 'approved', 'uploaded_by' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('pci.evidence'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('items', 1)->where('items.0.title', 'Pen test report')->where('items.0.requirements', ['11.4'])->where('items.0.expired', true));
    }

    public function test_assessment_page_without_an_assessment_and_role_access(): void
    {
        $this->actingAs($this->admin)->get(route('pci.saq'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('assessment', null)->where('frameworkId', $this->fw)->where('summary.total', 63)->where('summary.not_assessed', 63));

        foreach (['pci.dashboard', 'pci.cde', 'pci.controls', 'pci.saq', 'pci.matrix', 'pci.evidence'] as $page) {
            $this->get(route($page))->assertOk();
        }
        $this->actingAs($this->user('Security Analyst'))->get(route('pci.dashboard'))->assertForbidden();
    }
}
