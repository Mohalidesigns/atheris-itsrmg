<?php

namespace Tests\Feature\RegulatoryIntelligence;

use App\Models\CircularAssessment;
use App\Models\CircularImpact;
use App\Models\DocIntelligenceJob;
use App\Models\LegalEntity;
use App\Models\Obligation;
use App\Models\Organization;
use App\Models\RegulatoryCircular;
use App\Models\ReturnRun;
use App\Models\Returns\RegulatoryReturn;
use App\Models\ReturnTemplate;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vendor;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Services\Returns\CsatMapper;
use App\Services\ReturnsCentre\ReturnRunPopulator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Regulatory Intelligence (ATH-VAL-004): circular visibility and per-bank triage, Document
 * Intelligence upload → extraction → segregated review, the Returns Centre run lifecycle, the
 * Architecture Returns calendar / entity scoping / CSAT section, tenant isolation throughout, and
 * the executive dashboard feed.
 */
class RegulatoryIntelligenceModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $officer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->officer = $this->user('Compliance Officer');
        $this->admin = $this->user('Organization Admin');
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function circular(array $attributes = []): RegulatoryCircular
    {
        return RegulatoryCircular::withoutGlobalScopes()->create($attributes + [
            'regulator_code' => 'CBN',
            'circular_number' => 'BSD/DIR/'.fake()->unique()->numerify('###'),
            'title' => 'Test circular',
            'issued_at' => now()->subDays(3),
            'status' => 'published',
        ]);
    }

    private function template(string $code = 'cbn_crms_it'): ReturnTemplate
    {
        return ReturnTemplate::create([
            'regulator_code' => 'CBN', 'return_code' => $code, 'name' => 'CBN CRMS IT Return',
            'schema' => ['fields' => ReturnRunPopulator::CATALOGUE[$code]['fields']], 'version' => '1.0',
        ]);
    }

    /* ------------------------------------------------------------ circulars */

    public function test_compliance_officer_can_open_the_module_and_editorial_circulars_stay_hidden(): void
    {
        $published = $this->circular(['title' => 'Published one']);
        $draft = $this->circular(['title' => 'Still in editorial', 'status' => 'editorial_review']);

        $this->actingAs($this->officer)->get(route('reg-intel.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('RegulatoryIntel/Index')
                ->has('circulars', 1)
                ->where('circulars.0.id', $published->id)
                ->where('circulars.0.issued_at', $published->issued_at->toDateString()));

        $this->actingAs($this->officer)->get(route('reg-intel.show', $draft))->assertNotFound();

        $super = $this->user('Super Admin');
        $this->actingAs($super)->get(route('reg-intel.index'))
            ->assertInertia(fn (Assert $page) => $page->has('circulars', 2));
        $this->actingAs($super)->post(route('reg-intel.editorial', $draft), ['status' => 'published'])->assertRedirect();
        $this->assertSame('published', $draft->fresh()->status);

        // A bank admin cannot run Content-Ops editorial.
        $this->actingAs($this->admin)->post(route('reg-intel.editorial', $published), ['status' => 'superseded'])->assertForbidden();
    }

    public function test_a_banks_private_circular_is_invisible_to_other_banks(): void
    {
        $private = $this->circular(['organization_id' => $this->org->id, 'title' => 'Our uploaded circular', 'status' => 'published']);
        $outsider = $this->user('Organization Admin', $this->other);

        $this->actingAs($outsider)->get(route('reg-intel.index'))
            ->assertInertia(fn (Assert $page) => $page->has('circulars', 0));
        $this->actingAs($outsider)->get(route('reg-intel.show', $private))->assertNotFound();
        $this->actingAs($this->officer)->get(route('reg-intel.show', $private))->assertOk();
    }

    public function test_triage_rules_and_per_bank_isolation(): void
    {
        $circular = $this->circular();

        // Actioned needs a linked record; assessed needs applicability and an owner.
        $this->actingAs($this->officer)->post(route('reg-intel.assess', $circular), [
            'status' => 'actioned', 'applicability' => 'applicable', 'owner_id' => $this->officer->id,
        ])->assertSessionHasErrors('status');
        $this->actingAs($this->officer)->post(route('reg-intel.assess', $circular), ['status' => 'assessed'])
            ->assertSessionHasErrors(['applicability', 'owner_id']);
        $this->actingAs($this->officer)->post(route('reg-intel.assess', $circular), ['status' => 'not_applicable'])
            ->assertSessionHasErrors('notes');

        // Owner must belong to the bank.
        $foreigner = $this->user('Compliance Officer', $this->other);
        $this->actingAs($this->officer)->post(route('reg-intel.assess', $circular), [
            'status' => 'assessed', 'applicability' => 'applicable', 'owner_id' => $foreigner->id,
        ])->assertSessionHasErrors('owner_id');

        $this->actingAs($this->officer)->post(route('reg-intel.assess', $circular), [
            'status' => 'assessed', 'applicability' => 'applicable', 'owner_id' => $this->officer->id,
            'due_date' => now()->subDay()->toDateString(), 'severity' => 'high',
        ])->assertSessionHasNoErrors();

        $assessment = CircularAssessment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->org->id, $assessment->organization_id);
        $this->assertSame($this->officer->id, $assessment->assessed_by_id);

        // The bank sees its triage (and the overdue action); another bank still sees it unread.
        $this->actingAs($this->officer)->get(route('reg-intel.index'))
            ->assertInertia(fn (Assert $page) => $page->where('circulars.0.triage', 'assessed')
                ->where('circulars.0.is_overdue', true)->where('triage.overdue', 1)->where('triage.unread', 0));
        $this->actingAs($foreigner)->get(route('reg-intel.index'))
            ->assertInertia(fn (Assert $page) => $page->where('circulars.0.triage', 'unread')->where('triage.unread', 1));
    }

    public function test_impacts_link_only_the_banks_own_records_and_unlinking_reopens_an_actioned_circular(): void
    {
        $circular = $this->circular();
        $ours = Obligation::create(['organization_id' => $this->org->id, 'regulator_code' => 'CBN', 'reference_code' => 'OBL-1', 'title' => 'Ours']);
        $theirs = Obligation::create(['organization_id' => $this->other->id, 'regulator_code' => 'CBN', 'reference_code' => 'OBL-2', 'title' => 'Theirs']);

        $this->actingAs($this->officer)->post(route('reg-intel.impacts.store', $circular), [
            'impacted_type' => 'obligation', 'impacted_id' => $theirs->id, 'rationale' => 'x',
        ])->assertSessionHasErrors('impacted_id');

        $this->actingAs($this->officer)->post(route('reg-intel.impacts.store', $circular), [
            'impacted_type' => 'obligation', 'impacted_id' => $ours->id, 'rationale' => 'Changes the filing', 'impact_score' => 4,
        ])->assertSessionHasNoErrors();
        $impact = CircularImpact::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->org->id, $impact->organization_id);

        $this->actingAs($this->officer)->post(route('reg-intel.assess', $circular), [
            'status' => 'actioned', 'applicability' => 'applicable', 'owner_id' => $this->officer->id,
        ])->assertSessionHasNoErrors();
        $this->assertNotNull(CircularAssessment::withoutGlobalScopes()->first()->actioned_at);

        // Another bank cannot see or delete the impact.
        $outsider = $this->user('Organization Admin', $this->other);
        $this->actingAs($outsider)->delete(route('reg-intel.impacts.destroy', [$circular, $impact]))->assertNotFound();

        $this->actingAs($this->officer)->delete(route('reg-intel.impacts.destroy', [$circular, $impact]))->assertRedirect();
        $this->assertSame('assessed', CircularAssessment::withoutGlobalScopes()->first()->status);
    }

    /* ------------------------------------------------------ doc intelligence */

    public function test_upload_extracts_fields_into_the_uploaders_bank_and_is_isolated(): void
    {
        $text = "CENTRAL BANK OF NIGERIA\nRef: BSD/DIR/PUB/LAB/019/004\nDate: 14 September 2026\n"
            ."Subject: Guidelines on Cloud Computing Risk Management\nEffective date: 1 January 2027\n"
            ."Banks shall maintain a register of all cloud service providers and obtain prior approval before migrating workloads.\n";

        $this->actingAs($this->officer)->post(route('doc-intel.upload'), [
            'doc_type' => 'circular',
            'file' => UploadedFile::fake()->createWithContent('cloud.txt', $text),
        ])->assertRedirect();

        $job = DocIntelligenceJob::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->org->id, $job->organization_id);
        $this->assertSame('awaiting_review', $job->status);
        $this->assertSame('CBN', $job->structured_output['regulator']);
        $this->assertSame('BSD/DIR/PUB/LAB/019/004', $job->structured_output['reference']);
        $this->assertSame('2026-09-14', $job->structured_output['issued_date']);
        $this->assertSame('2027-01-01', $job->structured_output['effective_date']);
        $this->assertEquals(1.0, $job->confidence);
        Storage::disk('local')->assertExists($job->source_file);
        $this->assertStringStartsWith("doc-intel/{$this->org->id}/", $job->source_file);

        $outsider = $this->user('Organization Admin', $this->other);
        $this->actingAs($outsider)->get(route('doc-intel.show', $job))->assertNotFound();
        $this->actingAs($outsider)->get(route('doc-intel.download', $job))->assertNotFound();
        $this->actingAs($outsider)->get(route('doc-intel.index'))
            ->assertInertia(fn (Assert $page) => $page->has('jobs', 0));

        // Disallowed file types are refused.
        $this->actingAs($this->officer)->post(route('doc-intel.upload'), [
            'doc_type' => 'circular', 'file' => UploadedFile::fake()->create('payload.exe', 10),
        ])->assertSessionHasErrors('file');
    }

    public function test_review_is_segregated_and_acceptance_feeds_the_circular_feed(): void
    {
        $this->actingAs($this->officer)->post(route('doc-intel.upload'), [
            'doc_type' => 'ndpc_decision',
            'file' => UploadedFile::fake()->createWithContent('order.txt', "NIGERIA DATA PROTECTION COMMISSION\nReference: NDPC/ENF/2026/017\n"
                ."Date: 3 August 2026\nTitle: Final Order on unauthorised disclosure\nRespondent: Lagos MFB\n"
                ."The Respondent is directed to pay a remedial fine of NGN 25,000,000 within ninety days of this order.\n"),
        ]);
        $job = DocIntelligenceJob::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(25000000, $job->structured_output['penalty_amount']);

        $this->actingAs($this->officer)->post(route('doc-intel.accept', $job))->assertSessionHas('error');
        $this->assertSame('awaiting_review', $job->fresh()->status);

        $this->actingAs($this->admin)->post(route('doc-intel.accept', $job))->assertSessionHas('success');
        $job->refresh();
        $this->assertSame('accepted', $job->status);
        $this->assertSame($this->admin->id, $job->reviewed_by_id);

        $circular = RegulatoryCircular::withoutGlobalScopes()->findOrFail($job->circular_id);
        $this->assertSame($this->org->id, $circular->organization_id);
        $this->assertSame('NDPC/ENF/2026/017', $circular->circular_number);

        // Once reviewed it is closed.
        $this->actingAs($this->admin)->post(route('doc-intel.reject', $job), ['review_notes' => 'late'])->assertSessionHas('error');
    }

    public function test_low_confidence_needs_confirmation_and_ddq_needs_the_banks_vendor(): void
    {
        $this->actingAs($this->officer)->post(route('doc-intel.upload'), [
            'doc_type' => 'vendor_ddq', 'file' => UploadedFile::fake()->createWithContent('ddq.txt', "Vendor: Acme Hosting\nnothing else\n"),
        ]);
        $job = DocIntelligenceJob::withoutGlobalScopes()->firstOrFail();
        $this->assertTrue($job->needsFieldConfirmation());

        $ours = Vendor::factory()->create(['organization_id' => $this->org->id, 'last_assessed' => null]);
        $theirs = Vendor::factory()->create(['organization_id' => $this->other->id]);

        $this->actingAs($this->admin)->post(route('doc-intel.accept', $job), ['vendor_id' => $theirs->id, 'confirm_fields' => true])
            ->assertSessionHasErrors('vendor_id');
        $this->actingAs($this->admin)->post(route('doc-intel.accept', $job), ['vendor_id' => $ours->id])
            ->assertSessionHasErrors('confirm_fields');
        $this->actingAs($this->admin)->post(route('doc-intel.accept', $job), ['vendor_id' => $ours->id, 'confirm_fields' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('accepted', $job->fresh()->status);
        $this->assertSame($ours->id, $job->fresh()->vendor_id);
        $this->assertNotNull($ours->fresh()->last_assessed);
    }

    public function test_reject_requires_a_reason(): void
    {
        $this->actingAs($this->officer)->post(route('doc-intel.upload'), [
            'doc_type' => 'audit_report', 'file' => UploadedFile::fake()->createWithContent('r.txt', "Title: Audit\n"),
        ]);
        $job = DocIntelligenceJob::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin)->post(route('doc-intel.reject', $job), [])->assertSessionHasErrors('review_notes');
        $this->actingAs($this->admin)->post(route('doc-intel.reject', $job), ['review_notes' => 'Draft version'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $job->fresh()->status);
    }

    /* -------------------------------------------------------- returns centre */

    public function test_generate_populates_from_the_banks_own_register(): void
    {
        $template = $this->template();
        Risk::factory()->count(3)->create(['organization_id' => $this->org->id, 'status' => 'identified', 'risk_appetite' => 'above']);
        Risk::factory()->count(5)->create(['organization_id' => $this->other->id, 'status' => 'identified', 'risk_appetite' => 'above']);

        $this->actingAs($this->officer)->post(route('returns.generate'), ['template_id' => $template->id, 'period' => '2026-13'])
            ->assertSessionHasErrors('period');

        $this->actingAs($this->officer)->post(route('returns.generate'), ['template_id' => $template->id, 'period' => '2026-09'])
            ->assertRedirect();

        $run = ReturnRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->org->id, $run->organization_id);
        $this->assertSame($this->officer->id, $run->prepared_by_id);
        $this->assertSame(3, $run->data['values']['it_risks_active']);
        $this->assertSame(3, $run->data['values']['risks_above_appetite']);
        $this->assertSame($this->org->name, $run->data['values']['institution']);

        // Same period again opens the existing run rather than creating a rival.
        $this->actingAs($this->officer)->post(route('returns.generate'), ['template_id' => $template->id, 'period' => '2026-09'])
            ->assertRedirect(route('returns.show', $run));
        $this->assertSame(1, ReturnRun::withoutGlobalScopes()->count());

        $outsider = $this->user('Organization Admin', $this->other);
        $this->actingAs($outsider)->get(route('returns.show', $run))->assertNotFound();
        $this->actingAs($outsider)->get(route('returns.export', $run))->assertNotFound();
    }

    public function test_run_lifecycle_overrides_and_segregation(): void
    {
        $template = $this->template();
        $this->actingAs($this->admin)->post(route('returns.generate'), ['template_id' => $template->id, 'period' => '2026-09']);
        $run = ReturnRun::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin)->post(route('returns.override', $run), ['key' => 'incidents_in_period', 'value' => '2'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('returns.override', $run), [
            'key' => 'incidents_in_period', 'value' => '2', 'reason' => 'SOC tool lag',
        ])->assertSessionHasNoErrors();

        // A refresh recomputes but keeps the reviewer's override.
        $this->actingAs($this->admin)->post(route('returns.refresh', $run));
        $this->assertSame('2', ReturnRunPopulator::effectiveValues($run->fresh())['incidents_in_period']);

        $this->actingAs($this->admin)->post(route('returns.transition', $run), ['action' => 'submit_for_review']);
        $this->assertSame('in_review', $run->fresh()->status);

        // Locked once in review; the preparer cannot approve; a Compliance Officer cannot approve at all.
        $this->actingAs($this->admin)->post(route('returns.override', $run), ['key' => 'period', 'value' => 'x', 'reason' => 'y'])
            ->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('returns.transition', $run), ['action' => 'approve'])->assertSessionHas('error');
        $this->actingAs($this->officer)->post(route('returns.transition', $run), ['action' => 'approve'])->assertForbidden();

        $approver = $this->user('Organization Admin');
        $this->actingAs($approver)->post(route('returns.transition', $run), ['action' => 'approve'])->assertSessionHas('success');
        $this->assertSame($approver->id, $run->fresh()->approved_by_id);

        $this->actingAs($approver)->post(route('returns.transition', $run), ['action' => 'submit'])
            ->assertSessionHasErrors('submission_reference');
        $this->actingAs($approver)->post(route('returns.transition', $run), ['action' => 'submit', 'submission_reference' => 'CBN/2026/0042']);
        $run->refresh();
        $this->assertSame('submitted', $run->status);
        $this->assertSame('CBN/2026/0042', $run->submission_reference);

        $csv = $this->actingAs($approver)->get(route('returns.export', $run))->streamedContent();
        $this->assertStringContainsString('SOC tool lag', $csv);
        $this->assertStringContainsString('manual override', $csv);
    }

    /* --------------------------------------------------- architecture returns */

    public function test_compile_refuses_another_banks_legal_entity(): void
    {
        $theirs = LegalEntity::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'code' => 'LE-X', 'name' => 'Their sub', 'jurisdiction' => 'NG']);
        $ciso = $this->user('Super Admin');

        $this->actingAs($ciso)->post(route('regulatory-returns.compile'), [
            'template' => 'csat', 'period' => '2026', 'legal_entity_id' => $theirs->id,
        ])->assertSessionHasErrors('legal_entity_id');
    }

    public function test_calendar_rolls_forward_once_the_period_is_filed(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10));
        $ciso = $this->user('Super Admin');

        $csat = fn () => collect($this->actingAs($ciso)->get(route('regulatory-returns.index'))
            ->viewData('page')['props']['calendar'])->firstWhere('template', 'csat');

        $this->assertSame('2026-02-28', $csat()['due']);
        $this->assertLessThan(0, $csat()['days_remaining']);

        RegulatoryReturn::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'code' => 'CSAT-2026', 'template' => 'csat', 'name' => 'CSAT 2026',
            'period' => '2026', 'state' => RegulatoryReturn::SUBMITTED,
        ]);

        $entry = $csat();
        $this->assertSame('2027-02-28', $entry['due']);
        $this->assertFalse($entry['filed']);
        $this->assertGreaterThan(0, $entry['days_remaining']);
    }

    public function test_csat_mapper_reports_the_self_assessment_without_moving_evidence_confidence(): void
    {
        $this->actingAs($this->admin);
        CsatAssessment::create([
            'organization_id' => $this->org->id, 'assessment_year' => 2026, 'status' => 'in_progress',
            'framework_version' => '1.0', 'created_by' => $this->admin->id,
        ]);

        $result = (new CsatMapper)->compile(null, '2026');
        $section = collect($result['sections'])->firstWhere('ref', 'RBCF-2.5');

        $this->assertSame(2026, $section['answer']['assessment_year']);
        $this->assertSame('in_progress', $section['answer']['status']);
        $this->assertStringContainsString('approval', $section['caveat']);

        $citation = collect($result['citations'])->firstWhere('question_ref', 'RBCF-2.5');
        $this->assertFalse($citation['sealable']);
        // Nothing else is cited in an empty estate, so a non-sealable citation must not count.
        $this->assertSame(0, $result['evidence_confidence']);
    }

    /* -------------------------------------------------------------- dashboard */

    public function test_dashboard_carries_the_regulatory_feed(): void
    {
        $this->circular();
        $assessed = $this->circular();
        CircularAssessment::create(['organization_id' => $this->org->id, 'circular_id' => $assessed->id, 'status' => 'assessed',
            'due_date' => now()->subDays(2)]);
        DocIntelligenceJob::create(['organization_id' => $this->org->id, 'doc_type' => 'circular', 'source_file' => 'x', 'status' => 'awaiting_review']);
        DocIntelligenceJob::create(['organization_id' => $this->other->id, 'doc_type' => 'circular', 'source_file' => 'y', 'status' => 'awaiting_review']);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('regulatory.unread', 1)
                ->where('regulatory.awaiting_action', 1)
                ->where('regulatory.overdue_actions', 1)
                ->where('regulatory.documents_to_review', 1));
    }
}
