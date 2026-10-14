<?php

namespace Tests\Feature\Isms;

use App\Models\Control;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\SoaVersion;
use App\Models\StatementOfApplicability;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ISMS (ATH-VAL-010): the Statement of Applicability read against its evidence (mapped control
 * effectiveness and the latest completed ISO 27001 assessment), SoA editing with justification,
 * versioned approval by someone other than the preparer, the CSV export, the ISMS risk scope,
 * and tenant isolation.
 */
class IsmsModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $lead;

    private User $ciso;

    private int $fw;

    /** @var array<string, int> requirement code => id */
    private array $req = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->lead = $this->user('Compliance Officer');
        $this->ciso = $this->user('Organization Admin');

        $this->fw = DB::table('control_frameworks')->where('slug', 'iso-27001-2022')->value('id') ?? DB::table('control_frameworks')->insertGetId([
            'name' => 'ISO/IEC 27001:2022', 'slug' => 'iso-27001-2022', 'short_name' => 'ISO 27001', 'category' => 'security',
            'is_system' => true, 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('framework_requirements')->where('framework_id', $this->fw)->delete();
        foreach (['A.5.1' => 'Policies for information security', 'A.8.5' => 'Secure authentication', 'A.8.30' => 'Outsourced development'] as $code => $title) {
            $this->req[$code] = DB::table('framework_requirements')->insertGetId([
                'framework_id' => $this->fw, 'requirement_code' => $code, 'title' => $title, 'level' => 1,
                'is_mandatory' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function control(string $effectiveness, ?Organization $org = null): Control
    {
        return Control::withoutGlobalScopes()->create([
            'organization_id' => ($org ?? $this->org)->id, 'control_code' => 'CTL-'.fake()->unique()->numerify('###'),
            'title' => 'Control', 'domain' => 'Governance', 'status' => 'active', 'effectiveness' => $effectiveness,
        ]);
    }

    private function soa(string $code, array $attrs, ?Organization $org = null): void
    {
        StatementOfApplicability::withoutGlobalScopes()->create($attrs + [
            'organization_id' => ($org ?? $this->org)->id, 'requirement_id' => $this->req[$code],
            'is_applicable' => true, 'justification' => 'Required by the CBN framework.', 'implementation_status' => 'implemented',
        ]);
    }

    private function assessment(array $results, ?Organization $org = null): void
    {
        $id = DB::table('compliance_assessments')->insertGetId([
            'organization_id' => ($org ?? $this->org)->id, 'framework_id' => $this->fw, 'title' => 'Internal audit', 'status' => 'completed',
            'end_date' => now()->subMonth(), 'total_requirements' => 3, 'compliant_count' => 0, 'partial_count' => 0,
            'non_compliant_count' => 0, 'not_applicable_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($results as $code => $status) {
            DB::table('compliance_results')->insert(['assessment_id' => $id, 'requirement_id' => $this->req[$code], 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_soa_flags_claims_the_evidence_contradicts(): void
    {
        $failing = $this->control('ineffective');
        $this->soa('A.5.1', ['control_id' => $failing->id]);
        $this->soa('A.8.5', ['implementation_status' => 'planned']);
        $this->assessment(['A.5.1' => 'non_compliant', 'A.8.5' => 'compliant']);
        $this->assessment(['A.5.1' => 'compliant'], $this->other);

        $this->actingAs($this->ciso)->get(route('isms.soa'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('summary.total', 3)->where('summary.pending', 1)->where('summary.flagged', 3)
            ->where('rows.0.requirement_code', 'A.5.1')->where('rows.0.assessment_result', 'non_compliant')
            ->where('rows.0.flags', ['Marked implemented, but a control implementing it tested ineffective.', 'Marked implemented, but the latest ISO assessment found it non-compliant.'])
            ->where('rows.1.flags', ['Applicable but no control implements it.', 'Assessed compliant, but the SoA still says planned.'])
            ->where('rows.2.flags', ['No SoA decision recorded.']));
    }

    public function test_editing_an_entry_needs_a_justification_and_keeps_status_consistent(): void
    {
        $theirs = $this->control('effective', $this->other);

        $this->actingAs($this->lead)->put(route('isms.soa.update', $this->req['A.8.30']), ['is_applicable' => false, 'implementation_status' => 'planned'])
            ->assertSessionHasErrors('justification');
        $this->put(route('isms.soa.update', $this->req['A.8.30']), ['is_applicable' => true, 'justification' => 'Development is outsourced to Infosys.',
            'implementation_status' => 'implemented', 'control_id' => $theirs->id])->assertSessionHasErrors('control_id');
        $this->put(route('isms.soa.update', $this->req['A.8.30']), ['is_applicable' => true, 'justification' => 'Development is outsourced to Infosys.',
            'implementation_status' => 'not_applicable'])->assertSessionHasErrors('implementation_status');

        $this->put(route('isms.soa.update', $this->req['A.8.30']), ['is_applicable' => false, 'justification' => 'All development is in-house.',
            'implementation_status' => 'implemented'])->assertRedirect();
        $entry = StatementOfApplicability::withoutGlobalScopes()->where('requirement_id', $this->req['A.8.30'])->sole();
        $this->assertSame([false, 'not_applicable', $this->lead->id, $this->org->id], [$entry->is_applicable, $entry->implementation_status, (int) $entry->updated_by, (int) $entry->organization_id]);

        $this->put(route('isms.soa.update', 999999), ['is_applicable' => true, 'justification' => 'xxxxxxxxxxxx', 'implementation_status' => 'planned'])->assertNotFound();
        $this->actingAs($this->user('Viewer'))->put(route('isms.soa.update', $this->req['A.8.30']), ['is_applicable' => true])->assertForbidden();
    }

    public function test_a_version_is_approved_by_someone_other_than_its_preparer(): void
    {
        $this->soa('A.5.1', []);
        $this->soa('A.8.5', []);

        $this->actingAs($this->lead)->post(route('isms.soa.submit'))->assertSessionHasErrors('soa');
        $this->soa('A.8.30', ['is_applicable' => false, 'implementation_status' => 'not_applicable', 'justification' => 'All development in-house.']);

        $this->post(route('isms.soa.submit'), ['notes' => 'Annual review'])->assertRedirect();
        $version = SoaVersion::withoutGlobalScopes()->sole();
        $this->assertSame([1, 'submitted', 3, 2], [$version->version_no, $version->status, count($version->snapshot), $version->summary['applicable']]);
        $this->post(route('isms.soa.submit'))->assertStatus(422);

        $this->post(route('isms.soa.decide', $version), ['decision' => 'approve'])->assertSessionHasErrors('decision');
        $this->actingAs($this->ciso)->post(route('isms.soa.decide', $version), ['decision' => 'return'])->assertSessionHasErrors('notes');
        $this->post(route('isms.soa.decide', $version), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame(['approved', $this->ciso->id], [$version->fresh()->status, (int) $version->fresh()->decided_by]);

        // A later change is reported against the approved version.
        $this->actingAs($this->lead)->put(route('isms.soa.update', $this->req['A.8.5']), ['is_applicable' => true, 'justification' => 'Required for all channels.', 'implementation_status' => 'partial']);
        $this->get(route('isms.soa'))->assertInertia(fn (Assert $p) => $p->where('changes', ['A.8.5' => 'implementation, justification']));
        $this->get(route('isms.index'))->assertInertia(fn (Assert $p) => $p->where('soaVersion.version_no', 1)->where('soaVersion.changes', 1));
    }

    public function test_export_is_gated_and_carries_the_evidence(): void
    {
        $this->soa('A.5.1', ['control_id' => $this->control('ineffective')->id]);
        $this->soa('A.5.1', [], $this->other);

        $csv = $this->actingAs($this->ciso)->get(route('isms.soa.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('A.5.1,"Policies for information security",yes', $csv);
        $this->assertStringContainsString('tested ineffective', $csv);
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1, 'Header plus the three Annex A rows of this bank.');

        $this->actingAs($this->user('Viewer'))->get(route('isms.soa.export'))->assertForbidden();
    }

    public function test_isms_risks_are_the_in_scope_categories_with_their_treating_controls(): void
    {
        $cyber = DB::table('risk_categories')->insertGetId(['organization_id' => $this->org->id, 'name' => 'Cyber', 'slug' => 'cyber', 'created_at' => now(), 'updated_at' => now()]);
        $fraud = DB::table('risk_categories')->insertGetId(['organization_id' => $this->org->id, 'name' => 'Fraud', 'slug' => 'fraud', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn ($cat, $title) => Risk::factory()->create(['organization_id' => $this->org->id, 'category_id' => $cat, 'title' => $title, 'status' => 'assessed',
            'risk_owner_id' => $this->ciso->id, 'created_by' => $this->ciso->id]);
        $inScope = $make($cyber, 'Phishing');
        $make($fraud, 'Card fraud');
        $inScope->controls()->attach($this->control('effective')->id);
        $make($cyber, 'Ransomware');

        $this->actingAs($this->ciso)->get(route('isms.risks'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('risks', 2)->where('untreated', 1)->where('scope', ['Cyber']));
    }

    public function test_pages_are_isolated_per_bank(): void
    {
        $this->soa('A.5.1', ['justification' => 'Other bank text'], $this->other);
        SoaVersion::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'version_no' => 1, 'status' => 'submitted', 'snapshot' => [], 'summary' => []]);
        $theirVersion = SoaVersion::withoutGlobalScopes()->sole();

        $this->actingAs($this->ciso)->get(route('isms.soa'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('summary.pending', 3)->has('versions', 0));
        $this->post(route('isms.soa.decide', $theirVersion), ['decision' => 'approve'])->assertNotFound();
        foreach (['isms.index', 'isms.risks', 'isms.controls', 'isms.audit', 'isms.gap-analysis'] as $page) {
            $this->get(route($page))->assertOk();
        }
    }
}
