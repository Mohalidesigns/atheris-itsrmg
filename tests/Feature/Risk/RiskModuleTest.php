<?php

namespace Tests\Feature\Risk;

use App\Models\FairScenario;
use App\Models\Organization;
use App\Models\QuestionLibrary;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskCategory;
use App\Models\RiskTreatment;
use App\Models\Threat;
use App\Models\User;
use App\Services\FairMonteCarloService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * IT Risk Management end-to-end behaviour: register, assessments, treatments,
 * threats, FAIR and the question library, plus the dashboard feeds.
 */
class RiskModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();
        $this->manager = User::factory()->create(['organization_id' => $this->org->id]);
        $this->manager->assignRole('Risk Manager');
    }

    private function risk(array $attributes = []): Risk
    {
        return Risk::factory()->create($attributes + [
            'organization_id' => $this->org->id,
            'category_id' => RiskCategory::factory()->create(['organization_id' => $this->org->id])->id,
            'risk_owner_id' => $this->manager->id,
            'created_by' => $this->manager->id,
        ]);
    }

    public function test_one_rating_scale_is_used_everywhere(): void
    {
        $this->assertSame('critical', Risk::calculateRating(20));
        $this->assertSame('high', Risk::calculateRating(12));
        $this->assertSame('medium', Risk::calculateRating(6));
        $this->assertSame('low', Risk::calculateRating(3));
        $this->assertSame('very_low', Risk::calculateRating(2));
    }

    public function test_risk_with_seeded_values_saves_without_changes(): void
    {
        // Seeded demo risks carry these values; saving them unchanged used to fail on risk_appetite.
        $risk = $this->risk(['status' => 'mitigated', 'risk_appetite' => 'above', 'source' => 'regulator']);

        $this->actingAs($this->manager)
            ->put(route('risks.update', $risk), [
                'title' => $risk->title,
                'status' => 'mitigated',
                'risk_appetite' => 'above',
                'source' => 'regulator',
                'inherent_likelihood' => $risk->inherent_likelihood,
                'inherent_impact' => $risk->inherent_impact,
                'residual_likelihood' => 2,
                'residual_impact' => 2,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('risks.show', $risk));

        $risk->refresh();
        $this->assertSame(4, $risk->residual_score);
        $this->assertSame('low', $risk->residual_rating);
        $this->assertSame(1, $risk->scoreHistory()->count(), 'A score change must be recorded in history.');
    }

    public function test_new_risk_codes_keep_the_tenant_prefix(): void
    {
        $this->risk(['risk_id_code' => 'KHB-RSK-007']);

        $this->assertSame('KHB-RSK-008', Risk::generateNextCode($this->org->id));
    }

    public function test_foreign_keys_from_another_tenant_are_rejected(): void
    {
        $foreignCategory = RiskCategory::factory()->create(['organization_id' => $this->otherOrg->id]);
        $foreignRisk = Risk::factory()->create(['organization_id' => $this->otherOrg->id]);

        $this->actingAs($this->manager)
            ->post(route('risks.store'), ['title' => 'Cross-tenant', 'category_id' => $foreignCategory->id])
            ->assertSessionHasErrors('category_id');

        $this->actingAs($this->manager)
            ->post(route('risk-assessments.store'), [
                'risk_id' => $foreignRisk->id, 'methodology' => 'qualitative', 'assessment_type' => 'inherent',
                'likelihood' => 3, 'impact' => 3,
            ])
            ->assertSessionHasErrors('risk_id');

        $this->assertSame(0, RiskAssessment::withoutGlobalScopes()->count(), 'No orphan assessment may be written.');
    }

    public function test_register_ignores_unknown_sort_columns(): void
    {
        $this->risk();

        $this->actingAs($this->manager)
            ->get(route('risks.index', ['sort' => 'password', 'direction' => 'sideways']))
            ->assertOk();
    }

    public function test_heat_map_cell_drill_down_filters_the_register(): void
    {
        $hit = $this->risk(['status' => 'assessed', 'inherent_likelihood' => 4, 'inherent_impact' => 5, 'inherent_score' => 20]);
        $this->risk(['status' => 'assessed', 'inherent_likelihood' => 2, 'inherent_impact' => 2, 'inherent_score' => 4]);

        $this->actingAs($this->manager)
            ->get(route('risks.index', ['likelihood' => 4, 'impact' => 5]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Risks/Index')
                ->has('risks.data', 1)
                ->where('risks.data.0.id', $hit->id));
    }

    public function test_dashboard_rating_tiles_reconcile_with_open_risks(): void
    {
        $this->risk(['status' => 'assessed', 'inherent_score' => 20, 'inherent_rating' => 'critical']);
        $this->risk(['status' => 'treating', 'inherent_score' => 12, 'inherent_rating' => 'high']);
        $this->risk(['status' => 'closed', 'inherent_score' => 16, 'inherent_rating' => 'high']);

        $this->actingAs($this->manager)
            ->get(route('risks.dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Risks/Dashboard')
                ->where('stats.total', 3)
                ->where('stats.open', 2)
                ->where('stats.closed', 1)
                ->where('stats.critical', 1)
                ->where('stats.high', 1)
                ->has('heatMapData.inherent', 25)
                ->has('heatMapData.residual', 25));
    }

    public function test_latest_assessment_drives_the_score_and_deleting_it_reverts(): void
    {
        $risk = $this->risk(['status' => 'assessed']);
        $assess = fn (int $l, int $i) => $this->actingAs($this->manager)->post(route('risk-assessments.store'), [
            'risk_id' => $risk->id, 'methodology' => 'qualitative', 'assessment_type' => 'residual',
            'likelihood' => $l, 'impact' => $i,
        ])->assertSessionHasNoErrors();

        $assess(4, 3);
        $assess(3, 3);
        $this->assertSame(9, $risk->fresh()->residual_score);

        $latest = RiskAssessment::where('risk_id', $risk->id)->latest('id')->first();
        $this->actingAs($this->manager)->delete(route('risk-assessments.destroy', $latest))
            ->assertRedirect(route('risks.show', $risk));

        $this->assertSame(12, $risk->fresh()->residual_score, 'Deleting the latest assessment reverts to the previous one.');
        $this->assertSame(3, $risk->scoreHistory()->count());
    }

    public function test_completing_the_last_treatment_mitigates_the_risk_at_its_target(): void
    {
        $risk = $this->risk(['status' => 'treating', 'residual_likelihood' => 4, 'residual_impact' => 4, 'residual_score' => 16]);
        $treatment = RiskTreatment::create([
            'organization_id' => $this->org->id, 'risk_id' => $risk->id, 'title' => 'Deploy EDR',
            'strategy' => 'mitigate', 'status' => 'draft', 'target_likelihood' => 2, 'target_impact' => 2, 'target_score' => 4,
        ]);

        $this->actingAs($this->manager)->patch(route('risk-treatments.update-status', $treatment), ['status' => 'approved']);
        $this->assertSame($this->manager->id, $treatment->fresh()->approved_by);

        $this->actingAs($this->manager)->patch(route('risk-treatments.update-status', $treatment), ['status' => 'completed']);

        $treatment->refresh();
        $risk->refresh();
        $this->assertNotNull($treatment->completed_at);
        $this->assertSame(100, (int) $treatment->completion_percentage);
        $this->assertSame(4, $risk->residual_score);
        $this->assertSame('mitigated', $risk->status);
    }

    public function test_overdue_is_derived_from_the_due_date(): void
    {
        $risk = $this->risk();
        $late = RiskTreatment::create([
            'organization_id' => $this->org->id, 'risk_id' => $risk->id, 'title' => 'Late',
            'strategy' => 'mitigate', 'status' => 'in_progress', 'due_date' => now()->subDays(3),
        ]);
        $done = RiskTreatment::create([
            'organization_id' => $this->org->id, 'risk_id' => $risk->id, 'title' => 'Done',
            'strategy' => 'mitigate', 'status' => 'completed', 'due_date' => now()->subDays(3),
        ]);

        $this->assertTrue($late->is_overdue);
        $this->assertFalse($done->is_overdue);
        $this->assertSame([$late->id], RiskTreatment::overdue()->pluck('id')->all());
    }

    public function test_threats_using_the_seeded_taxonomy_can_be_edited(): void
    {
        $threat = Threat::factory()->create(['organization_id' => $this->org->id, 'category' => 'fraud', 'source' => 'ngcert']);

        $this->actingAs($this->manager)
            ->put(route('threats.update', $threat), [
                'name' => $threat->name, 'category' => 'fraud', 'source' => 'ngcert',
                'type' => 'deliberate', 'severity' => 'medium',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('threats.show', $threat));
    }

    public function test_fair_monte_carlo_is_reproducible_and_internally_consistent(): void
    {
        $scenario = FairScenario::create([
            'organization_id' => $this->org->id, 'name' => 'Ransomware',
            'frequency_distribution' => ['min' => 0.5, 'most' => 1, 'max' => 3],
            'magnitude_distribution' => ['min' => 1_000_000, 'most' => 5_000_000, 'max' => 20_000_000],
            'control_effectiveness' => ['percent' => 50],
            'iterations' => 5000,
        ]);
        $service = app(FairMonteCarloService::class);

        $a = $service->simulate($scenario, seed: 7);
        $b = $service->simulate($scenario, seed: 7);

        $this->assertSame($a, $b);
        $this->assertSame(5000, array_sum($a['histogram']));
        $this->assertLessThanOrEqual($a['ale_p95_ngn'], $a['ale_median_ngn']);
        $this->assertLessThanOrEqual($a['ale_p99_ngn'], $a['ale_p95_ngn']);
        // E[ALE] ≈ E[λ] × E[magnitude] × (1 − 50%) = 1.5 × 8.67m × 0.5 ≈ 6.5m
        $this->assertEqualsWithDelta(6_500_000, $a['ale_mean_ngn'], 1_000_000);
    }

    public function test_fair_run_updates_the_linked_risk_and_is_tenant_scoped(): void
    {
        $risk = $this->risk();
        $ranges = [
            'frequency_distribution' => ['min' => 0.5, 'most' => 1, 'max' => 2],
            'magnitude_distribution' => ['min' => 1_000_000, 'most' => 2_000_000, 'max' => 5_000_000],
            'control_effectiveness' => ['percent' => 40],
            'iterations' => 1000,
        ];
        $mine = FairScenario::create($ranges + ['organization_id' => $this->org->id, 'risk_id' => $risk->id, 'name' => 'Mine']);
        $theirs = FairScenario::create($ranges + ['organization_id' => $this->otherOrg->id, 'name' => 'Theirs']);

        $this->actingAs($this->manager)->post(route('fair.run', $mine))->assertRedirect();
        $this->assertSame(1, $mine->runs()->count());
        $this->assertEquals($mine->runs()->first()->ale_mean_ngn, $risk->fresh()->fair_annual_loss_expectancy);

        $this->actingAs($this->manager)->post(route('fair.run', $theirs))->assertNotFound();
        $this->actingAs($this->manager)->get(route('fair.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('scenarios', 1)->where('scenarios.0.name', 'Mine'));
    }

    public function test_question_library_crud(): void
    {
        $this->actingAs($this->manager)
            ->post(route('question-libraries.store'), [
                'title' => 'Timeout', 'question_text' => 'USSD idle timeout?', 'module' => 'risk',
                'response_type' => 'multiple_choice', 'weight' => 3,
            ])
            ->assertSessionHasErrors('response_options');

        $this->actingAs($this->manager)
            ->post(route('question-libraries.store'), [
                'title' => 'Timeout', 'question_text' => 'USSD idle timeout?', 'module' => 'risk',
                'response_type' => 'multiple_choice', 'response_options' => ['< 60s', ' ', '< 120s'], 'weight' => 3,
            ])
            ->assertSessionHasNoErrors();

        $question = QuestionLibrary::firstOrFail();
        $this->assertSame($this->org->id, $question->organization_id);
        $this->assertSame(['< 60s', '< 120s'], $question->response_options);

        $this->actingAs($this->manager)
            ->put(route('question-libraries.update', $question), [
                'title' => 'Timeout', 'question_text' => 'USSD idle timeout?', 'module' => 'risk',
                'response_type' => 'scale', 'weight' => 5, 'is_active' => false,
            ])
            ->assertSessionHasNoErrors();
        $question->refresh();
        $this->assertNull($question->response_options);
        $this->assertFalse($question->is_active);

        $this->actingAs($this->manager)->delete(route('question-libraries.destroy', $question));
        $this->assertSame(0, QuestionLibrary::count());
    }
}
