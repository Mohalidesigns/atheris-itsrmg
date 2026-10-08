<?php

namespace Tests\Feature\Csat;

use App\Models\Organization;
use App\Models\User;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatIrQuestion;
use App\Modules\CBNCSAT\Models\CsatIrResponse;
use App\Modules\CBNCSAT\Models\CsatMaCompensatingControl;
use App\Modules\CBNCSAT\Models\CsatMaNarrative;
use App\Modules\CBNCSAT\Models\CsatMaResponse;
use App\Modules\CBNCSAT\Models\CsatMaScore;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use App\Modules\CBNCSAT\Models\CsatStakeholderEngagement;
use App\Modules\CBNCSAT\Services\InherentRiskScoringService;
use App\Modules\CBNCSAT\Services\MaturityScoringService;
use Database\Seeders\CsatIrQuestionsSeeder;
use Database\Seeders\CsatMaStatementsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CBN-CSAT: scoring per BRD BR-MA-05, tenant isolation, write permissions, lifecycle locking,
 * the Sheet 6 checklist gate and the four-stage segregated approval (BR-AW-01).
 */
class CsatModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $preparer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RolesAndPermissionsSeeder::class, CsatIrQuestionsSeeder::class, CsatMaStatementsSeeder::class]);

        $this->org = Organization::factory()->create();
        $this->preparer = $this->officer('Organization Admin');
    }

    private function officer(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function assessment(array $attributes = [], ?Organization $org = null): CsatAssessment
    {
        $a = CsatAssessment::withoutGlobalScopes()->create($attributes + [
            'organization_id' => ($org ?? $this->org)->id, 'assessment_year' => 2026,
            'status' => 'in_progress', 'created_by' => $this->preparer->id,
        ]);
        CsatMaNarrative::provisionFor($a->id);

        return $a;
    }

    /** Everything the Sheet 6 checklist needs. */
    private function complete(CsatAssessment $a): void
    {
        $a->institutionProfile()->create([
            'institution_name' => 'Test Bank', 'cbn_licence_type' => 'dmb', 'head_office_address' => 'Lagos',
            'ciso_name' => 'CISO', 'ciso_email' => 'ciso@test.ng', 'ciso_phone' => '0800',
        ]);
        foreach (CsatStakeholderEngagement::ROLES as $key => $label) {
            CsatStakeholderEngagement::create(['assessment_id' => $a->id, 'role_key' => $key, 'role_label' => $label, 'engagement_status' => 'yes']);
        }
        foreach (CsatIrQuestion::where('is_active', true)->pluck('id') as $q) {
            CsatIrResponse::create(['assessment_id' => $a->id, 'question_id' => $q, 'selected_level' => 3]);
        }
        foreach (range(1, 5) as $c) {
            $a->irNarratives()->create(['category_code' => $c, 'narrative_key' => 'k', 'narrative_label' => 'L', 'narrative_value' => 'Described.']);
        }
        $rows = CsatMaStatement::where('is_active', true)->pluck('id')
            ->map(fn ($id) => ['assessment_id' => $a->id, 'statement_id' => $id, 'response' => 'yes', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('csat_ma_responses')->insert($rows->all());
        $a->maNarratives()->update(['response_text' => str_repeat('Board-approved programme with evidence. ', 4)]);
        app(MaturityScoringService::class)->recalculateAndPersist($a->id);
        CsatMaScore::where('assessment_id', $a->id)->where('score_type', 'domain')->update(['target_maturity_level' => 3]);
        $a->threats()->create(['threat_name' => 'Ransomware', 'threat_source' => 'external', 'threat_category' => 'technical', 'likelihood' => 'high', 'impact' => 'high', 'mitigating_controls_desc' => 'EDR', 'created_by' => $this->preparer->id]);
        $a->vulnerabilities()->create(['vulnerability_name' => 'Unpatched', 'vulnerability_category' => 'technology', 'likelihood_of_exploit' => 'high', 'impact_if_exploited' => 'high', 'mitigants_in_place' => false, 'created_by' => $this->preparer->id]);
        $a->update(['submission_deadline' => now()->addMonth()]);
    }

    public function test_maturity_follows_cumulative_rule_and_ignores_na(): void
    {
        $a = $this->assessment();
        $component = CsatMaStatement::where('is_active', true)->value('component_code');
        $statements = CsatMaStatement::where('component_code', $component)->get();
        $answer = fn ($level, $response) => $statements->where('maturity_level', $level)->each(fn ($s) => CsatMaResponse::updateOrCreate(
            ['assessment_id' => $a->id, 'statement_id' => $s->id], ['response' => $response]));

        $answer(1, 'yes');
        $answer(2, 'na'); // not applicable → level 2 is met
        $answer(3, 'no');
        $answer(4, 'yes'); // higher "yes" cannot skip the failed level 3

        $service = app(MaturityScoringService::class);
        $result = $service->scoreComponent($statements, CsatMaResponse::where('assessment_id', $a->id)->pluck('response', 'statement_id'));

        $this->assertSame(2, $result['achieved_maturity_level']);
        $this->assertSame(1.0, $result['level_scores'][2]);
        $this->assertSame(0.0, $result['level_scores'][3]);
    }

    public function test_domain_is_lowest_factor_and_scores_are_persisted(): void
    {
        $a = $this->assessment();
        $this->complete($a);

        $domains = app(MaturityScoringService::class)->calculate($a->id);
        foreach ($domains as $d) {
            $this->assertSame(collect($d['factors'])->min('achieved_maturity_level'), $d['achieved_maturity_level']);
        }
        $this->assertGreaterThan(0, CsatMaScore::where('assessment_id', $a->id)->where('score_type', 'factor')->count());
        $this->assertSame('innovative', $a->fresh()->overall_maturity_level, 'All "yes" answers attain the top level.');

        $ir = app(InherentRiskScoringService::class)->calculateCompositeRisk($a->id);
        $this->assertTrue($ir['is_complete']);
        $this->assertSame('moderate', $ir['level']);
    }

    public function test_another_tenants_threat_cannot_be_edited_through_your_assessment(): void
    {
        $otherOrg = Organization::factory()->create();
        $mine = $this->assessment();
        $theirs = $this->assessment(['created_by' => $this->preparer->id], $otherOrg);
        $threat = $theirs->threats()->create(['threat_name' => 'Theirs', 'threat_source' => 'external', 'threat_category' => 'technical', 'likelihood' => 'low', 'impact' => 'low', 'created_by' => $this->preparer->id]);

        $this->actingAs($this->preparer)
            ->put(route('csat.threats.update', [$mine->id, $threat->id]), [
                'threat_name' => 'Hijacked', 'threat_source' => 'external', 'threat_category' => 'technical', 'likelihood' => 'high', 'impact' => 'high',
            ])->assertNotFound();
        $this->actingAs($this->preparer)->delete(route('csat.threats.destroy', [$mine->id, $threat->id]))->assertNotFound();
        $this->actingAs($this->preparer)->get(route('csat.overview', $theirs->id))->assertNotFound();

        $this->assertSame('Theirs', $threat->fresh()->threat_name);
    }

    public function test_read_only_roles_cannot_answer(): void
    {
        $a = $this->assessment();
        $viewer = $this->officer('Viewer');
        $viewer->givePermissionTo('view csat');

        $this->actingAs($viewer)->post(route('csat.ir.response.save', $a->id), [
            'question_id' => CsatIrQuestion::value('id'), 'selected_level' => 2,
        ])->assertForbidden();
    }

    public function test_choosing_a_level_keeps_the_existing_comment(): void
    {
        $a = $this->assessment();
        $q = CsatIrQuestion::value('id');
        CsatIrResponse::create(['assessment_id' => $a->id, 'question_id' => $q, 'selected_level' => 2, 'comment' => 'Evidence ref EV-12']);

        $this->actingAs($this->preparer)->post(route('csat.ir.response.save', $a->id), ['question_id' => $q, 'selected_level' => 4]);

        $r = CsatIrResponse::where('assessment_id', $a->id)->where('question_id', $q)->first();
        $this->assertSame(4, (int) $r->selected_level);
        $this->assertSame('Evidence ref EV-12', $r->comment);
    }

    public function test_compensating_control_only_for_yes_cc_answers_in_this_assessment(): void
    {
        $a = $this->assessment();
        $statement = CsatMaStatement::value('id');
        $yes = CsatMaResponse::create(['assessment_id' => $a->id, 'statement_id' => $statement, 'response' => 'yes']);
        $payload = ['control_name' => 'Interim', 'control_description' => 'Manual review', 'effectiveness_level' => 'medium', 'planned_permanent_date' => now()->addMonth()->toDateString()];

        $this->actingAs($this->preparer)->post(route('csat.ma.cc.save', $a->id), $payload + ['response_id' => $yes->id])
            ->assertSessionHasErrors('response_id');

        $this->actingAs($this->preparer)->post(route('csat.ma.response.save', $a->id), ['statement_id' => $statement, 'response' => 'yes_cc']);
        $this->actingAs($this->preparer)->post(route('csat.ma.cc.save', $a->id), $payload + ['response_id' => $yes->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CsatMaCompensatingControl::count());

        // Changing the answer away from Yes [CC] drops the control.
        $this->actingAs($this->preparer)->post(route('csat.ma.response.save', $a->id), ['statement_id' => $statement, 'response' => 'no']);
        $this->assertSame(0, CsatMaCompensatingControl::count());
    }

    public function test_submission_is_blocked_until_the_checklist_is_complete(): void
    {
        $a = $this->assessment();

        $this->actingAs($this->preparer)->post(route('csat.workflow.submit', $a->id))
            ->assertSessionHasErrors('workflow');
        $this->assertSame('in_progress', $a->fresh()->status);
    }

    public function test_four_stage_segregated_approval_lock_return_and_submission(): void
    {
        $a = $this->assessment();
        $this->complete($a);
        [$ciso, $cro, $ceo] = [$this->officer('Organization Admin'), $this->officer('Organization Admin'), $this->officer('Organization Admin')];

        $this->actingAs($this->preparer)->post(route('csat.workflow.submit', $a->id))->assertSessionHasNoErrors();
        $this->assertSame('pending_approval', $a->fresh()->status);

        // Locked: answers can no longer change.
        $this->actingAs($this->preparer)->post(route('csat.ir.response.save', $a->id), ['question_id' => CsatIrQuestion::value('id'), 'selected_level' => 5])
            ->assertSessionHasErrors('workflow');

        // The preparer cannot sign the next stage.
        $this->actingAs($this->preparer)->post(route('csat.workflow.approve', $a->id))->assertSessionHasErrors('workflow');

        $this->actingAs($ciso)->post(route('csat.workflow.approve', $a->id))->assertSessionHasNoErrors();
        $this->actingAs($ciso)->post(route('csat.workflow.approve', $a->id))->assertSessionHasErrors('workflow');

        // Returning requires a comment and re-opens the cycle.
        $this->actingAs($cro)->post(route('csat.workflow.reject', $a->id), ['comments' => ''])->assertSessionHasErrors('comments');
        $this->actingAs($cro)->post(route('csat.workflow.reject', $a->id), ['comments' => 'Revisit D4 targets'])->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $a->fresh()->status);

        // A fresh cycle needs every stage again — earlier signatures don't carry over.
        $this->actingAs($this->preparer)->post(route('csat.workflow.submit', $a->id))->assertSessionHasNoErrors();
        foreach ([$ciso, $cro] as $officer) {
            $this->actingAs($officer)->post(route('csat.workflow.approve', $a->id))->assertSessionHasNoErrors();
            $this->assertSame('pending_approval', $a->fresh()->status);
        }
        $this->actingAs($ceo)->post(route('csat.workflow.approve', $a->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $a->fresh()->status);

        $this->actingAs($ceo)->post(route('csat.workflow.submit-cbn', $a->id))->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame('submitted', $a->status);
        $this->assertNotNull($a->submitted_at);

        $signatures = $a->approvalRecords()->pluck('digital_signature_token');
        $this->assertSame($signatures->count(), $signatures->unique()->count());
        $this->assertTrue($signatures->every(fn ($t) => strlen($t) === 64));
    }

    public function test_insights_are_generated_from_the_answers(): void
    {
        $a = $this->assessment();

        $this->actingAs($this->preparer)->post(route('csat.ai.generate', $a->id))->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertGreaterThan(0, $a->aiRecommendations()->where('recommendation_type', 'readiness_flag')->count());
        $this->assertSame('red', $a->ai_readiness_rag);
    }
}
