<?php

namespace Tests\Feature\Ea;

use App\Models\Control;
use App\Models\Ea\ControlMapping;
use App\Models\Ea\GlossaryTerm;
use App\Models\Ea\Goal;
use App\Models\Ea\MaturityAssessment;
use App\Models\Ea\MaturityQuestion;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ea\ControlInheritanceService;
use App\Services\Ea\EvidencePackGenerator;
use App\Services\Returns\ItsbMaturityMapper;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enterprise Architecture (ATH-VAL-015): the CBN blueprint's five domains rated on the CMMI scale
 * with evidence rules and a segregated approval; the ITSB return and evidence pack reading the
 * approved assessment; EA records and control mappings kept within the bank.
 */
class EaMaturityModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $assessor;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->assessor = $this->user('Enterprise Architect');
        $this->approver = $this->user('Organization Admin');
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function start(): MaturityAssessment
    {
        $this->actingAs($this->assessor)->post(route('ea.maturity.store'), ['year' => 2026])->assertRedirect();

        return MaturityAssessment::withoutGlobalScopes()->where('organization_id', $this->org->id)->latest('id')->first();
    }

    private function rateAll(MaturityAssessment $a, int $level = 2, array $overrides = []): void
    {
        foreach (MaturityQuestion::orderBy('code')->get() as $q) {
            $this->actingAs($this->assessor)->post(route('ea.maturity.answer', [$a, $q]), $overrides[$q->code] ?? ['level' => $level])->assertRedirect();
        }
    }

    public function test_the_framework_is_the_five_cbn_domains_with_five_practices_each(): void
    {
        $this->assertSame(['BA', 'AA', 'DA', 'TA', 'SA'], DB::table('ea_maturity_domains')->orderBy('sort_order')->pluck('code')->all());
        $this->assertSame(25, MaturityQuestion::count());
        $this->assertFalse(MaturityQuestion::where('question', 'like', 'How mature is %')->exists());

        $this->actingAs($this->assessor)->get(route('ea.cbn-maturity'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Ea/Maturity/Index')->has('domains', 5)->where('current', null)->where('defaultTarget', 3)->where('can.start', true));
    }

    public function test_ratings_follow_the_evidence_rules(): void
    {
        $a = $this->start();
        $q = MaturityQuestion::where('code', 'AA-1')->first();
        $go = fn (array $data) => $this->actingAs($this->assessor)->post(route('ea.maturity.answer', [$a, $q]), $data);

        $go(['level' => 3])->assertSessionHasErrors('evidence_note');
        $go(['level' => 4, 'evidence_note' => 'Portfolio owned by the EA team, reviewed quarterly.'])->assertSessionHasErrors('evidence_refs');
        $go(['level' => 4, 'evidence_note' => 'Portfolio owned by the EA team, reviewed quarterly.', 'evidence_refs' => ['applications', 'not-an-indicator']])->assertRedirect();

        $response = $a->responses()->sole();
        $this->assertSame([4, ['applications']], [(int) $response->selected_level, collect($response->evidence_refs)->pluck('key')->all()]);
        $this->assertSame('0', $response->evidence_refs[0]['value']); // the repository holds no applications yet

        $this->actingAs($this->user('Viewer'))->post(route('ea.maturity.answer', [$a, $q]), ['level' => 1])->assertForbidden();
    }

    public function test_an_assessment_is_submitted_complete_and_approved_by_someone_else(): void
    {
        $a = $this->start();
        $this->actingAs($this->assessor)->post(route('ea.maturity.submit', $a))->assertSessionHasErrors('assessment');

        // Security rated 3 against an empty repository (documentation supports 0): overstated.
        $sa = collect(['SA-1', 'SA-2', 'SA-3', 'SA-4', 'SA-5'])->mapWithKeys(fn ($c) => [$c => ['level' => 3, 'evidence_note' => 'Maintained in the CISO tooling.']])->all();
        $this->rateAll($a, 2, $sa + ['BA-1' => ['level' => 1]]);
        $this->actingAs($this->assessor)->post(route('ea.maturity.submit', $a))->assertRedirect();
        $a->refresh();
        $this->assertSame('submitted', $a->status);

        $this->actingAs($this->assessor)->post(route('ea.maturity.approve', $a))->assertSessionHasErrors('assessment'); // the submitter
        $this->actingAs($this->approver)->post(route('ea.maturity.approve', $a))->assertSessionHasErrors('approval_note');
        $this->post(route('ea.maturity.approve', $a), ['approval_note' => 'Security evidence sits in the GRC module; accepted.'])->assertRedirect();

        $a->refresh();
        $domains = $a->results['domains'];
        // A domain is as mature as its weakest practice; the overall is the average of the domains.
        $this->assertSame([1, 1.8, 3, true], [$domains['BA']['level'], $domains['BA']['average'], $domains['SA']['level'], $domains['SA']['overstated']]);
        $this->assertSame(['approved', $this->approver->id, 2.0], [$a->status, (int) $a->approved_by, (float) $a->results['overall']]);
        $this->assertSame(2, $domains['BA']['gap']);

        $this->actingAs($this->assessor)->post(route('ea.maturity.answer', [$a, MaturityQuestion::first()]), ['level' => 1])->assertSessionHasErrors('assessment');
    }

    public function test_the_submitter_cannot_approve_their_own_assessment(): void
    {
        $architect = $this->user('Organization Admin');
        $this->actingAs($architect)->post(route('ea.maturity.store'), ['year' => 2026]);
        $a = MaturityAssessment::withoutGlobalScopes()->sole();
        foreach (MaturityQuestion::all() as $q) {
            $this->post(route('ea.maturity.answer', [$a, $q]), ['level' => 2]);
        }
        $this->post(route('ea.maturity.submit', $a))->assertRedirect();
        $this->post(route('ea.maturity.approve', $a), ['approval_note' => 'Self-approval attempt.'])->assertSessionHasErrors('assessment');
        $this->actingAs($this->approver)->post(route('ea.maturity.return', $a), ['approval_note' => 'Evidence for DA-3 is missing.'])->assertRedirect();
        $this->assertSame('draft', $a->fresh()->status);
    }

    public function test_the_itsb_return_and_evidence_pack_read_the_approved_assessment(): void
    {
        $this->actingAs($this->approver);
        try {
            app(EvidencePackGenerator::class)->generate();
            $this->fail('An evidence pack needs an approved assessment.');
        } catch (ValidationException) {
        }
        $docOnly = (new ItsbMaturityMapper)->compile(null, '2026');
        $this->assertCount(5, $docOnly['sections'][0]['answer']['domains']);
        $this->assertSame('documentation', $docOnly['sections'][0]['answer']['domains'][4]['basis']);
        $this->assertLessThanOrEqual(4, $docOnly['summary']['overall_level']);

        $a = $this->start();
        $this->rateAll($a, 2);
        $this->post(route('ea.maturity.submit', $a));
        $this->actingAs($this->approver)->post(route('ea.maturity.approve', $a), ['approval_note' => 'Approved.'])->assertRedirect();

        $return = (new ItsbMaturityMapper)->compile(null, '2026');
        $sa = collect($return['sections'][0]['answer']['domains'])->firstWhere('domain', 'SA');
        $this->assertSame(['approved assessment', 2, 'Security Architecture & Assurance'], [$sa['basis'], $sa['level'], $sa['label']]);
        $this->assertSame(2.0, (float) $return['summary']['overall_level']);
    }

    public function test_ea_records_and_control_mappings_stay_within_the_bank(): void
    {
        Goal::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'code' => 'G-1', 'name' => 'Their goal']);
        GlossaryTerm::withoutGlobalScopes()->create(['organization_id' => null, 'term' => 'ArchiMate', 'definition' => 'Modelling language.', 'category' => 'ea']);
        $theirControl = Control::factory()->create(['organization_id' => $this->other->id, 'effectiveness' => 'effective']);
        $myControl = Control::factory()->create(['organization_id' => $this->org->id, 'effectiveness' => 'ineffective']);
        $appId = DB::table('ea_applications_ext')->insertGetId(['organization_id' => $this->org->id, 'code' => 'APP-1', 'name' => 'Finacle', 'criticality' => 'critical', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$theirControl, $myControl] as $c) {
            ControlMapping::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'component_type' => 'application', 'component_id' => $appId,
                'control_id' => $c->id, 'framework' => 'ISMS', 'coverage' => 'full']);
        }

        $this->actingAs($this->assessor);
        $this->assertSame([0, 1], [Goal::count(), GlossaryTerm::count()]); // their goal hidden, shared glossary visible
        $rows = collect(app(ControlInheritanceService::class)->resolveForApplication($appId))->keyBy('control_id');
        $this->assertSame([false, true], [$rows[$theirControl->id]['resolves'], $rows[$myControl->id]['resolves']]);

        $this->get(route('ea.security-assurance'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('summary.applications', 1)->where('unzoned.0.controls', 1)->where('unzoned.0.unresolved', 1)->where('unzoned.0.assurance', 'weak'));

        $theirs = MaturityAssessment::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'title' => 'Theirs', 'framework' => 'cbn-ea', 'year' => 2026, 'status' => 'draft']);
        $this->get(route('ea.maturity.show', $theirs))->assertNotFound();
        $this->post(route('ea.maturity.answer', [$theirs, MaturityQuestion::first()]), ['level' => 1])->assertNotFound();
    }
}
