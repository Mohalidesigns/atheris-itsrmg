<?php

namespace Tests\Feature\Ea;

use App\Events\Core\BiaRecordSaved;
use App\Events\Ea\ArbDecisionRecorded;
use App\Events\Ea\CveMatchedToComponent;
use App\Events\Ea\TechComponentBecameObsolete;
use App\Models\BiaRecord;
use App\Models\Control;
use App\Models\Ea\ArbSubmission;
use App\Models\Ea\ControlMapping;
use App\Models\Ea\EaApplication;
use App\Models\Ea\KriDefinition;
use App\Models\Ea\KriValue;
use App\Models\Ea\Process;
use App\Models\Ea\TechComponent;
use App\Models\Ea\TechVulnerability;
use App\Models\Issue;
use App\Models\Kri;
use App\Models\KriReading;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vulnerability;
use App\Services\Ea\ControlInheritanceService;
use App\Services\Ea\GraphResolver;
use App\Services\Ea\KriPublisher;
use App\Services\Ea\OwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ATH-EAR-002 Phase 2 exit criteria (§9):
 *
 *   "An EOL finding creates a risk in the core register with a working
 *    back-link; a BIA change updates EA process criticality; an ARB decision
 *    opens issues; control coverage is computed over real control IDs; EA KRIs
 *    appear on the board pack."
 *
 * One test per clause, plus the idempotency §7.5 demands of every listener:
 * "I-1 in particular will fire repeatedly against the same component and must
 * update rather than accumulate."
 */
class EaIntegrationContractsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->organization = Organization::factory()->create();
    }

    private function techComponent(array $overrides = []): TechComponent
    {
        return TechComponent::withoutGlobalScopes()->forceCreate(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'TEC-'.fake()->unique()->numerify('####'),
            'name' => 'Oracle Database',
            'vendor' => 'Oracle',
            'version' => '12.2',
            'radar_status' => 'hold',
            'eol_date' => now()->subMonths(6),
            'tech_debt_score' => 82,
            'obsolescence_flag' => true,
        ], $overrides));
    }

    /* ------------------------------------------------------------------ */
    /* I-1 — Obsolescence → Risk                                           */
    /* ------------------------------------------------------------------ */

    public function test_an_eol_finding_creates_a_risk_with_a_working_back_link(): void
    {
        $component = $this->techComponent();

        TechComponentBecameObsolete::dispatch($component);

        $risk = Risk::withoutGlobalScopes()->where('source', 'ea.obsolescence')->first();

        $this->assertNotNull($risk, 'I-1 should have opened a risk in the core register.');
        $this->assertSame('EA-OBS-'.str_pad((string) $component->id, 5, '0', STR_PAD_LEFT), $risk->risk_id_code);
        $this->assertStringContainsString('Oracle Database', $risk->title);

        // The back-link §7.3 requires, so the Risk detail page can render it.
        $this->assertContains('tech_component:'.$component->id, $risk->tags);
        $this->assertContains('tech_component_code:'.$component->code, $risk->tags);

        // Resolvable in the direction that matters: from the risk back to the
        // architecture object that caused it.
        $linkedId = (int) str_replace('tech_component:', '',
            collect($risk->tags)->first(fn ($t) => str_starts_with($t, 'tech_component:')));
        $this->assertSame($component->id, $linkedId);
        $this->assertNotNull(TechComponent::withoutGlobalScopes()->find($linkedId));
    }

    public function test_i1_updates_rather_than_accumulating_when_it_fires_repeatedly(): void
    {
        // §7.5: "Every listener must be idempotent — I-1 in particular will
        // fire repeatedly against the same component." The nightly job would
        // otherwise open 365 duplicate risks a year.
        $component = $this->techComponent();

        foreach (range(1, 5) as $ignored) {
            TechComponentBecameObsolete::dispatch($component->fresh());
        }

        $this->assertSame(1, Risk::withoutGlobalScopes()->where('source', 'ea.obsolescence')->count());
    }

    public function test_i1_scores_by_criticality_and_exposure_rather_than_a_flat_rating(): void
    {
        // A register full of identical "medium" findings is useless for
        // prioritisation, which is how an auto-created risk feed gets switched
        // off within a month.
        $severe = $this->techComponent(['code' => 'TEC-SEV', 'tech_debt_score' => 95, 'eol_date' => now()->subYear()]);
        $mild = $this->techComponent(['code' => 'TEC-MILD', 'tech_debt_score' => 40, 'eol_date' => now()->addYears(3)]);

        TechComponentBecameObsolete::dispatch($severe);
        TechComponentBecameObsolete::dispatch($mild);

        $severeRisk = Risk::withoutGlobalScopes()->where('risk_id_code', 'EA-OBS-'.str_pad((string) $severe->id, 5, '0', STR_PAD_LEFT))->first();
        $mildRisk = Risk::withoutGlobalScopes()->where('risk_id_code', 'EA-OBS-'.str_pad((string) $mild->id, 5, '0', STR_PAD_LEFT))->first();

        $this->assertGreaterThan($mildRisk->inherent_score, $severeRisk->inherent_score);
    }

    public function test_i1_auto_closes_the_risk_when_the_component_is_remediated(): void
    {
        // §7.3: "auto-close when remediated".
        $component = $this->techComponent();
        TechComponentBecameObsolete::dispatch($component);

        $code = 'EA-OBS-'.str_pad((string) $component->id, 5, '0', STR_PAD_LEFT);
        $this->assertNotSame('closed', Risk::withoutGlobalScopes()->where('risk_id_code', $code)->first()->status);

        $component->update(['obsolescence_flag' => false, 'eol_date' => now()->addYears(5)]);
        TechComponentBecameObsolete::dispatch($component->fresh());

        $this->assertSame('closed', Risk::withoutGlobalScopes()->where('risk_id_code', $code)->first()->status);
    }

    public function test_i1_assigns_the_owner_from_the_ea_ownership_model(): void
    {
        // §7.3: "owner from the EA ownership model" — B1, not a free-text guess.
        $component = $this->techComponent();
        $owner = User::factory()->create(['organization_id' => $this->organization->id]);

        (new OwnershipService())->subscribe(TechComponent::class, $component->id, $owner->id, 'accountable');

        TechComponentBecameObsolete::dispatch($component);

        $risk = Risk::withoutGlobalScopes()->where('source', 'ea.obsolescence')->first();
        $this->assertSame($owner->id, $risk->risk_owner_id);
    }

    /* ------------------------------------------------------------------ */
    /* I-2 — CVE → Vulnerability                                           */
    /* ------------------------------------------------------------------ */

    public function test_a_matched_cve_opens_a_vulnerability_linked_to_affected_applications(): void
    {
        $application = EaApplication::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'APP-CORE', 'name' => 'Core Banking', 'criticality' => 'critical', 'lifecycle' => 'live',
        ]);

        $component = $this->techComponent(['application_ids' => [$application->id]]);

        $finding = TechVulnerability::forceCreate([
            'component_id' => $component->id,
            'cve_id' => 'CVE-2021-44228',
            'severity' => 'critical',
            'cvss' => 10.0,
            'summary' => 'Log4Shell — JNDI injection allows remote code execution.',
            'source' => 'nvd',
            'status' => 'open',
        ]);

        CveMatchedToComponent::dispatch($component, $finding);

        $vulnerability = Vulnerability::withoutGlobalScopes()->where('cve_id', 'CVE-2021-44228')->first();

        $this->assertNotNull($vulnerability);
        $this->assertSame('critical', $vulnerability->severity);
        $this->assertSame('ea.cve_feed', $vulnerability->source);

        // §7.3: "linked to the affected applications and assets".
        $this->assertCount(1, $vulnerability->affected_assets);
        $this->assertSame($application->id, $vulnerability->affected_assets[0]['ea_application_id']);
    }

    public function test_i2_does_not_reopen_a_vulnerability_a_human_already_remediated(): void
    {
        $component = $this->techComponent();
        $finding = TechVulnerability::forceCreate([
            'component_id' => $component->id, 'cve_id' => 'CVE-2022-0778',
            'severity' => 'high', 'cvss' => 7.5, 'source' => 'nvd', 'status' => 'open',
        ]);

        CveMatchedToComponent::dispatch($component, $finding);

        $vulnerability = Vulnerability::withoutGlobalScopes()->where('cve_id', 'CVE-2022-0778')->first();
        $vulnerability->update(['status' => 'remediated']);

        // The feed re-runs nightly and still reports the CVE.
        CveMatchedToComponent::dispatch($component, $finding);

        $this->assertSame('remediated', $vulnerability->fresh()->status);
        $this->assertSame(1, Vulnerability::withoutGlobalScopes()->where('cve_id', 'CVE-2022-0778')->count());
    }

    /* ------------------------------------------------------------------ */
    /* I-6 — BIA → EA process                                              */
    /* ------------------------------------------------------------------ */

    public function test_a_bia_change_updates_ea_process_criticality_and_recovery_objectives(): void
    {
        $process = Process::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'BP-PAY', 'name' => 'Funds Transfer', 'level' => 2,
            'criticality' => 'low', 'rto_hours' => 24, 'rpo_hours' => 12,
        ]);

        BiaRecord::create([
            'organization_id' => $this->organization->id,
            'process_name' => 'Funds Transfer',
            'department' => 'Operations',
            'criticality' => 'critical',
            'rto_hours' => 2,
            'rpo_hours' => 0,
        ]);

        $process->refresh();

        // §7.3: "RTO/RPO and criticality write into `ea_processes`". Before
        // this contract the EA columns were seeded, so blast radius reported
        // recovery exposure from numbers nobody had agreed to.
        $this->assertSame('critical', $process->criticality);
        $this->assertSame(2, (int) $process->rto_hours);
        $this->assertSame(0, (int) $process->rpo_hours);
    }

    public function test_i6_does_nothing_when_the_process_name_is_ambiguous(): void
    {
        // Writing a recovery objective onto the wrong process is worse than
        // writing none, so an ambiguous match is not a match.
        foreach (['BP-A', 'BP-B'] as $code) {
            Process::withoutGlobalScopes()->forceCreate([
                'organization_id' => $this->organization->id,
                'code' => $code, 'name' => 'Settlement', 'level' => 2,
                'criticality' => 'low', 'rto_hours' => 24,
            ]);
        }

        BiaRecord::create([
            'organization_id' => $this->organization->id,
            'process_name' => 'Settlement',
            'criticality' => 'critical',
            'rto_hours' => 1,
        ]);

        foreach (Process::withoutGlobalScopes()->whereIn('code', ['BP-A', 'BP-B'])->get() as $process) {
            $this->assertSame('low', $process->criticality, 'An ambiguous name must not be written to.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* I-9 — ARB decision → Issues                                         */
    /* ------------------------------------------------------------------ */

    public function test_an_arb_approval_with_conditions_opens_owned_issues(): void
    {
        $submission = ArbSubmission::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'ARB-001',
            'title' => 'Adopt managed Kubernetes for channel workloads',
            'summary' => 'Move channel services onto a managed cluster.',
            'status' => 'approved',
            'risk_band' => 'high',
            'decided_by' => 'Architecture Review Board',
            'decided_at' => now(),
        ]);

        ArbDecisionRecorded::dispatch($submission, [
            ['text' => 'Complete a threat model before the first production workload.'],
            ['text' => 'Agree an exit plan with the cloud provider.'],
        ]);

        $issues = Issue::withoutGlobalScopes()->where('source_type', 'ea_arb_condition')->get();

        $this->assertCount(2, $issues);
        $this->assertSame('high', $issues->first()->severity, 'Severity should follow the submission risk band.');
        $this->assertNotNull($issues->first()->due_date, 'A condition with no due date is not tracked.');
        $this->assertSame($submission->id, (int) $issues->first()->source_id);
    }

    public function test_i9_does_not_duplicate_issues_when_a_decision_is_recorded_again(): void
    {
        $submission = ArbSubmission::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'ARB-002', 'title' => 'Retire legacy MQ', 'summary' => 'Decommission.',
            'status' => 'approved', 'risk_band' => 'medium',
        ]);

        foreach (range(1, 3) as $ignored) {
            ArbDecisionRecorded::dispatch($submission, [['text' => 'Confirm no consumers remain.']]);
        }

        $this->assertSame(1, Issue::withoutGlobalScopes()->where('source_type', 'ea_arb_condition')->count());
    }

    public function test_i9_ignores_a_rejected_submission(): void
    {
        $submission = ArbSubmission::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'ARB-003', 'title' => 'Rejected proposal', 'summary' => 'No.',
            'status' => 'rejected', 'risk_band' => 'high',
        ]);

        ArbDecisionRecorded::dispatch($submission, [['text' => 'Should never be raised.']]);

        $this->assertSame(0, Issue::withoutGlobalScopes()->where('source_type', 'ea_arb_condition')->count());
    }

    /* ------------------------------------------------------------------ */
    /* I-4 — control coverage over real control IDs                        */
    /* ------------------------------------------------------------------ */

    public function test_control_coverage_is_computed_over_real_control_ids(): void
    {
        $application = EaApplication::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'APP-X', 'name' => 'Payments Hub', 'criticality' => 'high', 'lifecycle' => 'live',
        ]);

        $control = Control::factory()->create([
            'organization_id' => $this->organization->id,
            'control_code' => 'AC-01',
            'title' => 'Access control policy',
            'status' => 'implemented',
            'last_tested' => now()->subMonth(),
        ]);

        ControlMapping::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'component_type' => 'application', 'component_id' => $application->id,
            'control_id' => $control->id, 'framework' => 'CBN', 'coverage' => 'full',
        ]);

        // A deliberately dangling mapping — the state §2.5 says produces
        // "arithmetic over noise".
        ControlMapping::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'component_type' => 'application', 'component_id' => $application->id,
            'control_id' => 999999, 'framework' => 'CBN', 'coverage' => 'full',
        ]);

        $service = new ControlInheritanceService();
        $resolved = collect($service->resolveForApplication($application->id));

        $this->assertTrue($resolved->firstWhere('control_id', $control->id)['resolves']);
        $this->assertSame('AC-01', $resolved->firstWhere('control_id', $control->id)['control_code']);
        $this->assertFalse($resolved->firstWhere('control_id', 999999)['resolves']);

        // The citable figure counts only mappings that resolve to an
        // implemented control — the only percentage safe next to a
        // regulator's question.
        $coverage = $service->citableCoverage($application->id, 'CBN');
        $this->assertSame(2, $coverage['mapped']);
        $this->assertSame(1, $coverage['dangling']);
        $this->assertSame(1, $coverage['effective']);
        $this->assertSame(50.0, $coverage['citable_percent']);
    }

    public function test_the_graph_resolver_reports_dangling_cross_module_references(): void
    {
        $application = EaApplication::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'APP-Y', 'name' => 'Cards', 'criticality' => 'high', 'lifecycle' => 'live',
        ]);

        ControlMapping::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'component_type' => 'application', 'component_id' => $application->id,
            'control_id' => 888888, 'framework' => 'CBN', 'coverage' => 'full',
        ]);

        $resolver = new GraphResolver();

        $this->assertFalse($resolver->isClean());

        $row = collect($resolver->audit())->firstWhere('column', 'control_id');
        $this->assertSame(1, $row['dangling']);

        // A dangling id is worse than an absent one: absent is visibly
        // incomplete, dangling silently inflates a coverage figure.
        $resolver->pruneDangling();
        $this->assertTrue((new GraphResolver())->isClean());
    }

    public function test_the_graph_resolver_refuses_an_unresolvable_reference_on_write(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Control #777777 does not exist/');

        (new GraphResolver())->assertResolvable(['control_id' => 777777]);
    }

    /* ------------------------------------------------------------------ */
    /* I-8 — EA KRIs → the platform register                               */
    /* ------------------------------------------------------------------ */

    public function test_ea_kris_are_published_into_the_platform_register(): void
    {
        $definition = KriDefinition::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'OBS-01',
            'name' => 'Applications running unsupported technology',
            'category' => 'architecture',
            'threshold_green' => 2, 'threshold_amber' => 5, 'threshold_red' => 10,
            'direction' => 'higher_worse', 'unit' => 'count',
        ]);

        foreach ([['2026-Q2', 3, 'amber'], ['2026-Q3', 8, 'red']] as [$period, $value, $status]) {
            KriValue::forceCreate([
                'kri_id' => $definition->id, 'period' => $period,
                'value' => $value, 'status' => $status, 'recorded_at' => now(),
            ]);
        }

        $result = (new KriPublisher())->publish();

        $this->assertSame(1, $result['definitions']);
        $this->assertSame(2, $result['readings']);

        // §7.3: "so EA metrics appear on board packs and executive dashboards".
        $kri = Kri::withoutGlobalScopes()->where('code', 'EA-OBS-01')->first();
        $this->assertNotNull($kri);
        $this->assertSame(KriPublisher::CATEGORY, $kri->category);
        $this->assertSame(2, KriReading::where('kri_id', $kri->id)->count());
        $this->assertSame('red', KriReading::where('kri_id', $kri->id)->where('period', '2026-Q3')->first()->status);
    }

    public function test_i8_republishing_updates_rather_than_duplicating(): void
    {
        $definition = KriDefinition::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'OBS-02', 'name' => 'Obsolete components', 'category' => 'architecture',
            'direction' => 'higher_worse', 'unit' => 'count',
        ]);
        KriValue::forceCreate([
            'kri_id' => $definition->id, 'period' => '2026-Q3',
            'value' => 4, 'status' => 'amber', 'recorded_at' => now(),
        ]);

        (new KriPublisher())->publish();
        (new KriPublisher())->publish();

        $this->assertSame(1, Kri::withoutGlobalScopes()->where('code', 'EA-OBS-02')->count());

        $kri = Kri::withoutGlobalScopes()->where('code', 'EA-OBS-02')->first();
        $this->assertSame(1, KriReading::where('kri_id', $kri->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* Contract switches                                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_contract_can_be_switched_off(): void
    {
        // A bank piloting EA before its Risk register is populated should be
        // able to hold I-1 off rather than have findings open risks nobody owns.
        config(['ea.contracts.obsolescence_to_risk' => false]);

        TechComponentBecameObsolete::dispatch($this->techComponent());

        $this->assertSame(0, Risk::withoutGlobalScopes()->where('source', 'ea.obsolescence')->count());
    }
}
