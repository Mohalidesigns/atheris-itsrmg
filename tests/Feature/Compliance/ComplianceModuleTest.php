<?php

namespace Tests\Feature\Compliance;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceResult;
use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\Evidence;
use App\Models\FrameworkRequirement;
use App\Models\Gap;
use App\Models\Obligation;
use App\Models\Organization;
use App\Models\User;
use App\Services\ComplianceScoreService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Compliance module: assessment lifecycle and scoring, result → gap automation, tenant isolation
 * of results / evidence / obligations, evidence upload + segregated review, control mappings and
 * the dashboard posture feed.
 */
class ComplianceModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $officer;

    private ControlFramework $framework;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->officer = $this->user('Compliance Officer');

        // A small framework: one domain heading with three assessable requirements.
        $this->framework = ControlFramework::create([
            'name' => 'Test Framework', 'slug' => 'test-fw', 'short_name' => 'TFW', 'category' => 'regulatory',
            'jurisdiction' => 'NG', 'is_active' => true, 'sort_order' => 1,
        ]);
        $domain = FrameworkRequirement::create(['framework_id' => $this->framework->id, 'requirement_code' => 'T-1', 'title' => 'Domain', 'level' => 'domain', 'sort_order' => 1]);
        foreach ([1, 2, 3] as $i) {
            FrameworkRequirement::create(['framework_id' => $this->framework->id, 'parent_id' => $domain->id, 'requirement_code' => "T-1.{$i}", 'title' => "Requirement {$i}", 'level' => 'requirement', 'sort_order' => $i]);
        }
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function startAssessment(): ComplianceAssessment
    {
        $this->actingAs($this->officer)->post(route('compliance-assessments.store'), [
            'framework_id' => $this->framework->id, 'title' => 'TFW assessment',
        ])->assertRedirect();

        return ComplianceAssessment::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function resultFor(ComplianceAssessment $a, string $code): ComplianceResult
    {
        return ComplianceResult::where('assessment_id', $a->id)
            ->whereHas('requirement', fn ($q) => $q->where('requirement_code', $code))->firstOrFail();
    }

    public function test_assessment_creates_results_only_for_assessable_requirements(): void
    {
        $a = $this->startAssessment();

        $this->assertSame('in_progress', $a->status);
        $this->assertSame(3, $a->results()->count(), 'Domain headings must not become assessable results.');
        $this->assertSame(3, $a->fresh()->total_requirements);
        $this->assertNull($a->fresh()->overall_score, 'Nothing assessed yet — no score.');
    }

    public function test_scoring_excludes_not_assessed_and_not_applicable(): void
    {
        $a = $this->startAssessment();
        $this->actingAs($this->officer)->patch(route('compliance-results.update', $this->resultFor($a, 'T-1.1')), ['status' => 'compliant']);
        $this->actingAs($this->officer)->patch(route('compliance-results.update', $this->resultFor($a, 'T-1.2')), ['status' => 'partially_compliant', 'findings' => 'Half done.']);

        // (1 + 0.5) / 2 assessed applicable = 75%; T-1.3 is still not assessed.
        $this->assertEquals(75.0, (float) $a->fresh()->overall_score);

        $this->actingAs($this->officer)->patch(route('compliance-results.update', $this->resultFor($a, 'T-1.3')), ['status' => 'not_applicable']);
        $this->assertEquals(75.0, (float) $a->fresh()->overall_score);
        $this->assertSame(1, $a->fresh()->not_applicable_count);
    }

    public function test_non_compliant_result_requires_a_finding_and_raises_then_resolves_a_gap(): void
    {
        $a = $this->startAssessment();
        $r = $this->resultFor($a, 'T-1.1');

        $this->actingAs($this->officer)->patch(route('compliance-results.update', $r), ['status' => 'non_compliant'])
            ->assertSessionHasErrors('findings');

        $this->actingAs($this->officer)->patch(route('compliance-results.update', $r), [
            'status' => 'non_compliant', 'findings' => 'No board reporting.', 'recommendations' => 'Report quarterly.',
        ])->assertSessionHasNoErrors();

        $gap = Gap::withoutGlobalScopes()->where('assessment_id', $a->id)->where('requirement_id', $r->requirement_id)->firstOrFail();
        $this->assertSame('high', $gap->severity);
        $this->assertSame('identified', $gap->status);
        $this->assertSame('No board reporting.', $gap->description);
        $this->assertSame('Report quarterly.', $gap->remediation_plan);

        // Downgraded to partial → same gap, re-graded.
        $this->actingAs($this->officer)->patch(route('compliance-results.update', $r), ['status' => 'partially_compliant', 'findings' => 'Some reporting.']);
        $this->assertSame(1, Gap::withoutGlobalScopes()->where('assessment_id', $a->id)->count());
        $this->assertSame('medium', $gap->fresh()->severity);

        // Re-assessed compliant → gap remediated.
        $this->actingAs($this->officer)->patch(route('compliance-results.update', $r), ['status' => 'compliant']);
        $this->assertSame('remediated', $gap->fresh()->status);
        $this->assertNotNull($gap->fresh()->completed_at);
    }

    public function test_lifecycle_complete_requires_everything_assessed_and_locks_results(): void
    {
        $a = $this->startAssessment();
        $this->actingAs($this->officer)->post(route('compliance-assessments.transition', $a), ['action' => 'complete'])
            ->assertSessionHasErrors('status');

        foreach (['T-1.1', 'T-1.2', 'T-1.3'] as $code) {
            $this->actingAs($this->officer)->patch(route('compliance-results.update', $this->resultFor($a, $code)), ['status' => 'compliant']);
        }
        $this->actingAs($this->officer)->post(route('compliance-assessments.transition', $a), ['action' => 'complete', 'summary' => 'All good.'])
            ->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame('completed', $a->status);
        $this->assertNotNull($a->end_date);
        $this->assertEquals(100.0, (float) $a->overall_score);

        // Locked: results cannot change, the assessment cannot be deleted.
        $this->actingAs($this->officer)->patch(route('compliance-results.update', $this->resultFor($a, 'T-1.1')), ['status' => 'non_compliant', 'findings' => 'x'])
            ->assertSessionHasErrors('status');
        $this->actingAs($this->officer)->delete(route('compliance-assessments.destroy', $a))->assertSessionHasErrors('status');

        // The completed result now drives the framework posture.
        $posture = collect(app(ComplianceScoreService::class)->getFrameworkPosture($this->org->id))->firstWhere('framework_id', $this->framework->id);
        $this->assertEquals(100.0, $posture['score']);
        $this->assertSame($a->id, $posture['assessment_id']);

        // Reopen unlocks it.
        $this->actingAs($this->officer)->post(route('compliance-assessments.transition', $a), ['action' => 'reopen']);
        $this->assertSame('in_progress', $a->fresh()->status);
    }

    public function test_results_and_assessments_are_tenant_isolated(): void
    {
        $other = Organization::factory()->create();
        $otherOfficer = $this->user('Compliance Officer', $other);
        $a = $this->startAssessment();
        $r = $this->resultFor($a, 'T-1.1');

        $this->actingAs($otherOfficer)->get(route('compliance-assessments.show', $a))->assertNotFound();
        $this->actingAs($otherOfficer)->patch(route('compliance-results.update', $r), ['status' => 'compliant'])->assertNotFound();
        $this->assertSame('not_assessed', $r->fresh()->status);

        // Controls, owners and assessors from another bank are rejected.
        $foreignControl = Control::withoutGlobalScopes()->create(['organization_id' => $other->id, 'control_code' => 'X-001', 'title' => 'Foreign']);
        $this->actingAs($this->officer)->patch(route('compliance-results.update', $r), ['status' => 'compliant', 'control_id' => $foreignControl->id])
            ->assertSessionHasErrors('control_id');
        $this->actingAs($this->officer)->post(route('controls.store'), ['title' => 'Mine', 'owner_id' => $otherOfficer->id])
            ->assertSessionHasErrors('owner_id');
    }

    public function test_viewer_cannot_record_results(): void
    {
        $a = $this->startAssessment();
        $viewer = $this->user('Viewer');

        $this->actingAs($viewer)->patch(route('compliance-results.update', $this->resultFor($a, 'T-1.1')), ['status' => 'compliant'])->assertForbidden();
        $this->actingAs($viewer)->post(route('compliance-assessments.transition', $a), ['action' => 'cancel'])->assertForbidden();
    }

    public function test_evidence_upload_review_and_download_are_secured(): void
    {
        Storage::fake('local');
        $control = Control::create(['organization_id' => $this->org->id, 'control_code' => 'GOV-001', 'title' => 'Board oversight']);

        // Arbitrary morph types are refused.
        $this->actingAs($this->officer)->post(route('evidence.store'), [
            'title' => 'x', 'type' => 'url', 'url' => 'https://example.ng', 'subject' => 'App\\Models\\User', 'subject_id' => $this->officer->id,
        ])->assertSessionHasErrors('subject');

        $this->actingAs($this->officer)->post(route('evidence.store'), [
            'title' => 'Board minutes', 'type' => 'document', 'subject' => 'control', 'subject_id' => $control->id,
            'file' => UploadedFile::fake()->create('minutes.pdf', 40, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $evidence = Evidence::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('pending', $evidence->status);
        $this->assertStringStartsWith("evidence/{$this->org->id}/", $evidence->file_path);
        Storage::disk('local')->assertExists($evidence->file_path);

        // The uploader cannot approve their own evidence; another officer can.
        $this->actingAs($this->officer)->patch(route('evidence.review', $evidence), ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $reviewer = $this->user('Compliance Officer');
        $this->actingAs($reviewer)->patch(route('evidence.review', $evidence), ['decision' => 'rejected'])->assertSessionHasErrors('review_notes');
        $this->actingAs($reviewer)->patch(route('evidence.review', $evidence), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $evidence->fresh()->status);
        $this->assertSame($reviewer->id, $evidence->fresh()->reviewed_by);

        $this->actingAs($this->officer)->get(route('evidence.download', $evidence))->assertOk()->assertDownload('minutes.pdf');

        // Another bank can neither attach to this control nor download the file.
        $other = Organization::factory()->create();
        $outsider = $this->user('Compliance Officer', $other);
        $this->actingAs($outsider)->get(route('evidence.download', $evidence))->assertNotFound();
        $this->actingAs($outsider)->post(route('evidence.store'), [
            'title' => 'x', 'type' => 'url', 'url' => 'https://example.ng', 'subject' => 'control', 'subject_id' => $control->id,
        ])->assertSessionHasErrors('subject_id');
    }

    public function test_gap_workflow_requires_plan_and_acceptance_rationale(): void
    {
        $this->actingAs($this->officer)->post(route('gap-analysis.store'), ['title' => 'Manual gap', 'severity' => 'critical'])->assertSessionHasNoErrors();
        $gap = Gap::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, $gap->priority);
        $this->assertSame('identified', $gap->status);

        $base = ['title' => 'Manual gap', 'severity' => 'critical'];
        $this->actingAs($this->officer)->put(route('gap-analysis.update', $gap), $base + ['status' => 'in_progress'])->assertSessionHasErrors('remediation_plan');
        $this->actingAs($this->officer)->put(route('gap-analysis.update', $gap), $base + ['status' => 'accepted'])->assertSessionHasErrors('notes');
        $this->actingAs($this->officer)->put(route('gap-analysis.update', $gap), $base + ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertNotNull($gap->fresh()->completed_at);
        $this->assertSame(0, Gap::open()->count());
    }

    public function test_gap_codes_keep_the_organisation_prefix(): void
    {
        Gap::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'gap_code' => 'KHB-GAP-015', 'title' => 'Seeded', 'severity' => 'low']);

        $this->assertSame('KHB-GAP-016', Gap::generateNextCode($this->org->id));
        $this->assertSame('GAP-0001', Gap::generateNextCode(Organization::factory()->create()->id));
    }

    public function test_control_mapping_feeds_framework_coverage(): void
    {
        $control = Control::create(['organization_id' => $this->org->id, 'control_code' => 'GOV-001', 'title' => 'Board oversight', 'status' => 'active']);
        $domain = FrameworkRequirement::where('requirement_code', 'T-1')->first();
        $leaf = FrameworkRequirement::where('requirement_code', 'T-1.1')->first();

        $this->actingAs($this->officer)->post(route('controls.mappings.store', $control), ['requirement_id' => $domain->id, 'coverage' => 'full'])
            ->assertSessionHasErrors('requirement_id');
        $this->actingAs($this->officer)->post(route('controls.mappings.store', $control), ['requirement_id' => $leaf->id, 'coverage' => 'full'])
            ->assertSessionHasNoErrors();

        $posture = collect(app(ComplianceScoreService::class)->getFrameworkPosture($this->org->id))->firstWhere('framework_id', $this->framework->id);
        $this->assertSame(1, $posture['covered_requirements']);
        $this->assertSame(33, $posture['coverage_percent']);

        // New assessments pre-link the mapped control as the tested control.
        $a = $this->startAssessment();
        $this->assertSame($control->id, $this->resultFor($a, 'T-1.1')->control_id);

        $this->actingAs($this->officer)->delete(route('controls.mappings.destroy', [$control, $leaf]));
        $this->assertSame(0, DB::table('control_framework_mappings')->count());
    }

    public function test_control_cannot_be_its_own_ancestor(): void
    {
        $parent = Control::create(['organization_id' => $this->org->id, 'control_code' => 'GOV-001', 'title' => 'Parent']);
        $child = Control::create(['organization_id' => $this->org->id, 'control_code' => 'GOV-002', 'title' => 'Child', 'parent_id' => $parent->id]);

        $this->actingAs($this->officer)->put(route('controls.update', $parent), ['title' => 'Parent', 'parent_id' => $child->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_obligations_and_vault_are_tenant_scoped(): void
    {
        $other = Organization::factory()->create();
        Obligation::create(['organization_id' => $other->id, 'regulator_code' => 'CBN', 'reference_code' => 'X', 'title' => 'Other bank obligation', 'review_cycle_days' => 90]);
        Obligation::create(['organization_id' => $this->org->id, 'regulator_code' => 'NDPC', 'reference_code' => 'Y', 'title' => 'Our obligation', 'review_cycle_days' => 365, 'effective_date' => now()->subYears(2)]);

        $admin = $this->user('Organization Admin');
        $this->actingAs($admin)->get(route('obligations.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('obligations', 1)
                ->where('obligations.0.title', 'Our obligation')
                ->where('obligations.0.next_due', fn ($d) => $d >= today()->toDateString()));
    }
}
