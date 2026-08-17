<?php

namespace Tests\Feature\Ea;

use App\Models\Ea\ApplicationCapability;
use App\Models\Ea\Capability;
use App\Models\Ea\Diagram;
use App\Models\Ea\DiagramEntity;
use App\Models\Ea\DiagramVersion;
use App\Models\Ea\DraftChange;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Plateau;
use App\Models\Ea\PlateauEntity;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\TechComponent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ea\ArchiMateRoundTrip;
use App\Services\Ea\CapabilityLinkIndex;
use App\Services\Ea\CostModelService;
use App\Services\Ea\DiagramLayout;
use App\Services\Ea\DiagramService;
use App\Services\Ea\DraftChangeService;
use App\Services\Ea\GraphQlService;
use App\Services\Ea\HierarchyIndex;
use App\Services\Ea\ImpactAnalyser;
use App\Services\Ea\McpServer;
use App\Services\Ea\PlateauDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ATH-EAR-002 Phase 4 — "Depth and scale" (§9), WS 4.1–4.7.
 *
 * One test per mechanic the phase claims, written against the claim rather than
 * the implementation:
 *
 *   4.1  a canvas validates against ArchiMate 3.2, versions every save,
 *        refuses approval while invalid, and exports to three formats
 *   4.2  n-hop traversal starts from any entity type and crosses every edge
 *        kind the repository holds
 *   4.3  a plateau diff reports count, cost, risk and capability-coverage
 *        deltas over authored membership
 *   4.4  the ArchiMate round-trip gate passes
 *   4.5  a scoped write becomes a draft and changes nothing until approved
 *   4.7  TCO, cost per capability and technical debt, each with coverage
 *
 * §10's performance target is exercised separately in
 * {@see EaPerformanceBenchmarkTest}.
 */
class EaDepthAndScaleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $architect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->organization = Organization::factory()->create();
        $this->architect = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->architect->givePermissionTo(['view ea', 'create ea', 'edit ea', 'delete ea', 'approve ea', 'export ea']);
        $this->actingAs($this->architect);
    }

    /* ================= fixtures ================= */

    private function capability(string $code, ?int $parentId = null, string $criticality = 'high'): Capability
    {
        return Capability::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => "Capability {$code}",
            'level' => $parentId ? 2 : 1,
            'parent_id' => $parentId,
            'criticality' => $criticality,
        ]);
    }

    private function application(string $code, array $overrides = []): EaApplication
    {
        return EaApplication::withoutGlobalScopes()->forceCreate(array_merge([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => "Application {$code}",
            'criticality' => 'critical',
            'lifecycle' => 'live',
            'business_fit' => 4,
            'technical_fit' => 3,
            'annual_cost' => 100_000,
            'cost_currency' => 'USD',
            'user_count' => 500,
        ], $overrides));
    }

    private function techComponent(string $code, array $overrides = []): TechComponent
    {
        return TechComponent::withoutGlobalScopes()->forceCreate(array_merge([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => "Component {$code}",
            'radar_status' => 'adopt',
        ], $overrides));
    }

    private function link(EaApplication $application, Capability $capability, float $weight = 1.0): void
    {
        ApplicationCapability::forceCreate([
            'organization_id' => $this->organization->id,
            'application_id' => $application->id,
            'capability_id' => $capability->id,
            'allocation_weight' => $weight,
        ]);
    }

    private function plateau(string $code, string $type): Plateau
    {
        return Plateau::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => $code,
            'name' => "Plateau {$code}",
            'plateau_type' => $type,
            'effective_from' => now()->toDateString(),
        ]);
    }

    /* ================================================================== */
    /* WS 4.1 — the real diagram editor (B13)                             */
    /* ================================================================== */

    public function test_a_canvas_is_validated_against_the_archimate_metamodel(): void
    {
        $service = new DiagramService();

        $elements = [
            ['id' => 'a', 'label' => 'Core Banking', 'type' => 'application-component', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'b', 'label' => 'Payments', 'type' => 'capability', 'position' => ['x' => 200, 'y' => 0]],
            ['id' => 'c', 'label' => 'Oracle', 'type' => 'node', 'position' => ['x' => 400, 'y' => 0]],
        ];

        // Legal: an application component realises a capability.
        $legal = $service->validate($elements, [
            ['id' => 'e1', 'source' => 'a', 'target' => 'b', 'data' => ['relation' => 'realisation']],
        ]);
        $this->assertTrue($legal['valid'], 'realisation from application-component to capability must be permitted');

        // Illegal: composition only joins like elements.
        $illegal = $service->validate($elements, [
            ['id' => 'e2', 'source' => 'c', 'target' => 'a', 'data' => ['relation' => 'composition']],
        ]);
        $this->assertFalse($illegal['valid']);
        $this->assertStringContainsString('does not permit', $illegal['errors'][0]['message']);

        // Illegal: an edge whose endpoint is not on the canvas.
        $dangling = $service->validate($elements, [
            ['id' => 'e3', 'source' => 'a', 'target' => 'ghost', 'data' => ['relation' => 'association']],
        ]);
        $this->assertFalse($dangling['valid']);
        $this->assertStringContainsString('not on the canvas', $dangling['errors'][0]['message']);
    }

    public function test_every_save_snapshots_a_version_and_the_snapshot_restores(): void
    {
        $service = new DiagramService();
        $diagram = new Diagram(['organization_id' => $this->organization->id, 'version' => 0]);

        $first = [['id' => 'a', 'label' => 'One', 'type' => 'application-component', 'position' => ['x' => 0, 'y' => 0]]];
        $diagram = $service->save($diagram, ['code' => 'DGM-T1', 'name' => 'Test', 'status' => 'draft'], $first, []);

        // The first save of a new diagram has nothing to snapshot.
        $this->assertSame(0, DiagramVersion::where('diagram_id', $diagram->id)->count());
        $this->assertSame(1, $diagram->version);

        $second = array_merge($first, [
            ['id' => 'b', 'label' => 'Two', 'type' => 'capability', 'position' => ['x' => 200, 'y' => 0]],
        ]);
        $diagram = $service->save($diagram, ['code' => 'DGM-T1', 'name' => 'Test', 'status' => 'draft'], $second, [], 'Added a capability');

        $this->assertSame(2, $diagram->version);
        $this->assertCount(2, $diagram->elements_json);

        $version = DiagramVersion::where('diagram_id', $diagram->id)->where('version', 1)->firstOrFail();
        $this->assertSame(1, $version->element_count, 'the snapshot must capture the canvas *before* the save');

        // Restoring is itself a save, so the pre-restore canvas survives too.
        $restored = $service->restore($diagram->fresh(), $version);
        $this->assertCount(1, $restored->elements_json);
        $this->assertSame(3, $restored->version);
        $this->assertSame(2, DiagramVersion::where('diagram_id', $diagram->id)->count());
    }

    public function test_an_invalid_canvas_cannot_be_approved(): void
    {
        $service = new DiagramService();
        $diagram = new Diagram(['organization_id' => $this->organization->id, 'version' => 0]);

        $diagram = $service->save($diagram, ['code' => 'DGM-T2', 'name' => 'Topology', 'is_topology' => true], [
            ['id' => 'a', 'label' => 'App', 'type' => 'application-component', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'b', 'label' => 'Node', 'type' => 'node', 'position' => ['x' => 200, 'y' => 0]],
        ], [
            ['id' => 'e1', 'source' => 'b', 'target' => 'a', 'data' => ['relation' => 'composition']],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $service->approve($diagram);
    }

    public function test_approval_records_the_approver_and_an_edit_breaks_it(): void
    {
        $service = new DiagramService();
        $diagram = new Diagram(['organization_id' => $this->organization->id, 'version' => 0]);

        $elements = [
            ['id' => 'a', 'label' => 'App', 'type' => 'application-component', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'b', 'label' => 'Cap', 'type' => 'capability', 'position' => ['x' => 200, 'y' => 0]],
        ];
        $edges = [['id' => 'e1', 'source' => 'a', 'target' => 'b', 'data' => ['relation' => 'realisation']]];

        $diagram = $service->save($diagram, ['code' => 'DGM-T3', 'name' => 'Approved topology', 'is_topology' => true], $elements, $edges);
        $service->approve($diagram);

        $diagram->refresh();
        $this->assertSame('approved', $diagram->status);
        $this->assertSame($this->architect->id, $diagram->approved_by);
        $this->assertNotNull($diagram->approved_at);

        // Break-on-edit, the same mechanic the quality seal uses: an approved
        // diagram that changes is no longer approved.
        $diagram = $service->save($diagram, ['code' => 'DGM-T3', 'name' => 'Approved topology'], array_merge($elements, [
            ['id' => 'c', 'label' => 'Extra', 'type' => 'capability', 'position' => ['x' => 400, 'y' => 0]],
        ]), $edges);

        $this->assertNull($diagram->approved_at);
        $this->assertSame('review', $diagram->status);
    }

    public function test_a_canvas_indexes_the_repository_records_it_depicts(): void
    {
        $application = $this->application('APP-DGM');
        $service = new DiagramService();
        $diagram = new Diagram(['organization_id' => $this->organization->id, 'version' => 0]);

        $diagram = $service->save($diagram, ['code' => 'DGM-T4', 'name' => 'Bound'], [
            [
                'id' => 'app-'.$application->id, 'label' => $application->name, 'type' => 'application-component',
                'entity_type' => EaApplication::class, 'entity_id' => $application->id,
                'position' => ['x' => 0, 'y' => 0],
            ],
            ['id' => 'free', 'label' => 'Annotation', 'type' => 'grouping', 'position' => ['x' => 200, 'y' => 0]],
        ], []);

        // Only the bound element is indexed; the annotation is not a record.
        $this->assertSame(1, DiagramEntity::where('diagram_id', $diagram->id)->count());

        $showing = $service->diagramsShowing(EaApplication::class, $application->id);
        $this->assertCount(1, $showing);
        $this->assertSame('DGM-T4', $showing->first()->code);
    }

    public function test_a_canvas_can_be_derived_from_the_repository(): void
    {
        $capability = $this->capability('CAP-DER');
        $application = $this->application('APP-DER');
        $this->link($application, $capability);

        $canvas = (new DiagramService())->generateAround(EaApplication::class, $application->id, 2);

        $this->assertNotEmpty($canvas['elements']);
        $this->assertContains(
            $capability->name,
            array_column($canvas['elements'], 'label'),
            'a derived canvas must reach the capability the application realises'
        );
        // Every element is positioned, so the export is not one black square.
        foreach ($canvas['elements'] as $element) {
            $this->assertArrayHasKey('x', $element['position']);
        }
    }

    public function test_auto_layout_is_deterministic_and_stacks_archimate_layers(): void
    {
        $layout = new DiagramLayout();
        $elements = [
            ['id' => 'n', 'label' => 'Node', 'type' => 'node', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'c', 'label' => 'Cap', 'type' => 'capability', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'a', 'label' => 'App', 'type' => 'application-component', 'position' => ['x' => 0, 'y' => 0]],
        ];

        $first = $layout->apply($elements, [], 'layered');
        $second = $layout->apply($elements, [], 'layered');
        $this->assertSame($first, $second, 'the same input must produce the same coordinates');

        $y = [];
        foreach ($first as $element) {
            $y[$element['type']] = $element['position']['y'];
        }
        $this->assertLessThan($y['application-component'], $y['capability'], 'strategy sits above application');
        $this->assertLessThan($y['node'], $y['application-component'], 'application sits above technology');
    }

    public function test_a_diagram_exports_to_svg_png_and_pdf(): void
    {
        $service = new DiagramService();
        $diagram = new Diagram(['organization_id' => $this->organization->id, 'version' => 0]);
        $diagram = $service->save($diagram, ['code' => 'DGM-T5', 'name' => 'Exportable'], [
            ['id' => 'a', 'label' => 'App', 'type' => 'application-component', 'position' => ['x' => 40, 'y' => 40]],
            ['id' => 'b', 'label' => 'Cap', 'type' => 'capability', 'position' => ['x' => 300, 'y' => 40]],
        ], [
            ['id' => 'e1', 'source' => 'a', 'target' => 'b', 'data' => ['relation' => 'realisation']],
        ]);

        $svg = $service->toSvg($diagram);
        $this->assertStringStartsWith('<?xml', $svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('Exportable', $svg);

        $pdf = $service->toPdf($diagram);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);

        if (extension_loaded('gd')) {
            $png = $service->toPng($diagram);
            $this->assertSame("\x89PNG", substr($png, 0, 4));
        }
    }

    /* ================================================================== */
    /* WS 4.2 — n-hop impact (B14)                                        */
    /* ================================================================== */

    public function test_impact_traversal_starts_from_any_entity_type(): void
    {
        $capability = $this->capability('CAP-IMP');
        $application = $this->application('APP-IMP');
        $component = $this->techComponent('TECH-IMP', ['application_ids' => [$application->id]]);
        $this->link($application, $capability);

        $analyser = new ImpactAnalyser();

        // From the technology component: reaches the application, then the
        // capability the application realises.
        $fromTech = $analyser->traverse(TechComponent::class, $component->id, 2);
        $names = array_column($fromTech['nodes'], 'name');
        $this->assertContains($application->name, $names);
        $this->assertContains($capability->name, $names);

        // From the capability, back the other way.
        $fromCapability = (new ImpactAnalyser())->traverse(Capability::class, $capability->id, 2);
        $this->assertContains($application->name, array_column($fromCapability['nodes'], 'name'));
    }

    public function test_depth_bounds_the_traversal(): void
    {
        $capability = $this->capability('CAP-DEPTH');
        $application = $this->application('APP-DEPTH');
        $component = $this->techComponent('TECH-DEPTH', ['application_ids' => [$application->id]]);
        $this->link($application, $capability);

        $oneHop = (new ImpactAnalyser())->traverse(TechComponent::class, $component->id, 1);
        $this->assertNotContains($capability->name, array_column($oneHop['nodes'], 'name'),
            'the capability is two hops away and must not appear at depth 1');

        $twoHops = (new ImpactAnalyser())->traverse(TechComponent::class, $component->id, 2);
        $this->assertContains($capability->name, array_column($twoHops['nodes'], 'name'));
    }

    public function test_direction_separates_what_a_change_affects_from_what_it_depends_on(): void
    {
        $upstream = $this->application('APP-UP');
        $downstream = $this->application('APP-DOWN');

        Relationship::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'source_type' => EaApplication::class, 'source_id' => $upstream->id,
            'target_type' => EaApplication::class, 'target_id' => $downstream->id,
            'relation_type' => 'flow',
        ]);

        $analyser = new ImpactAnalyser();

        $down = $analyser->traverse(EaApplication::class, $upstream->id, 2, ImpactAnalyser::DOWNSTREAM);
        $this->assertContains($downstream->name, array_column($down['nodes'], 'name'));

        $up = (new ImpactAnalyser())->traverse(EaApplication::class, $upstream->id, 2, ImpactAnalyser::UPSTREAM);
        $this->assertNotContains($downstream->name, array_column($up['nodes'], 'name'));
    }

    public function test_change_impact_reports_the_consequences_a_change_board_asks_about(): void
    {
        $application = $this->application('APP-CB', ['criticality' => 'critical']);
        $other = $this->application('APP-CB2', ['criticality' => 'critical']);

        EaInterface::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'IF-CB', 'name' => 'Settlement feed',
            'source_app_id' => $application->id, 'target_app_id' => $other->id,
            'status' => 'active',
        ]);

        Process::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'BP-CB', 'name' => 'Interbank settlement', 'level' => 3,
            'criticality' => 'critical', 'rto_hours' => 2,
            'linked_applications' => [$other->id],
        ]);

        $impact = (new ImpactAnalyser())->changeImpact(EaApplication::class, $application->id, 3);

        $this->assertSame(1, $impact['summary']['applications']);
        $this->assertSame(1, $impact['summary']['critical_applications']);
        $this->assertSame(1, $impact['summary']['tight_rto_processes'],
            'a two-hour recovery objective is what turns a change into an out-of-hours window');
        // USD 100,000 at the configured reference rate.
        $this->assertGreaterThan(0, $impact['summary']['annual_cost_ngn']);
    }

    public function test_the_impact_endpoint_answers_json_for_the_entity_tab(): void
    {
        $application = $this->application('APP-JSON');

        $this->getJson(route('ea.impact.json', [
            'entity_type' => 'EaApplication', 'entity_id' => $application->id, 'depth' => 2,
        ]))
            ->assertOk()
            ->assertJsonStructure(['graph' => ['nodes', 'edges', 'by_hop'], 'summary' => ['applications', 'evidence_confidence']]);
    }

    public function test_path_between_two_entities_is_reported_with_its_hop_count(): void
    {
        $capability = $this->capability('CAP-PATH');
        $application = $this->application('APP-PATH');
        $component = $this->techComponent('TECH-PATH', ['application_ids' => [$application->id]]);
        $this->link($application, $capability);

        $path = (new ImpactAnalyser())->pathBetween(
            TechComponent::class, $component->id, Capability::class, $capability->id
        );

        $this->assertNotNull($path);
        $this->assertCount(3, $path, 'component → application → capability');
        $this->assertSame($component->name, $path[0]['name']);
        $this->assertSame($capability->name, $path[2]['name']);
    }

    /* ================================================================== */
    /* WS 4.3 — plateau diff (B15)                                        */
    /* ================================================================== */

    public function test_a_plateau_diff_reports_count_cost_and_capability_deltas(): void
    {
        $current = $this->plateau('P-CUR', 'current');
        $target = $this->plateau('P-TGT', 'target');

        $retained = $this->application('APP-KEEP', ['annual_cost' => 50_000, 'cost_currency' => 'USD']);
        $retired = $this->application('APP-GONE', ['annual_cost' => 80_000, 'cost_currency' => 'USD']);
        $introduced = $this->application('APP-NEW', ['annual_cost' => 20_000, 'cost_currency' => 'USD', 'lifecycle' => 'plan']);

        $soleCapability = $this->capability('CAP-SOLE');
        $this->link($retired, $soleCapability);

        $service = new PlateauDiffService();
        foreach ([$retained, $retired] as $application) {
            $service->setDisposition($current, EaApplication::class, $application->id, 'retain');
        }
        $service->setDisposition($target, EaApplication::class, $retained->id, 'retain');
        $service->setDisposition($target, EaApplication::class, $retired->id, 'retire', ['one_off_cost' => 5_000_000]);
        $service->setDisposition($target, EaApplication::class, $introduced->id, 'introduce');

        $diff = $service->diff($current, $target);

        $this->assertSame(1, $diff['summary']['added']);
        $this->assertSame(1, $diff['summary']['removed']);
        $this->assertSame(1, $diff['summary']['retained']);

        // Retiring the 80k system and adding the 20k one must reduce the total.
        $this->assertLessThan(0, $diff['summary']['annual_cost_delta_ngn']);
        $this->assertSame(5_000_000.0, $diff['summary']['one_off_cost_ngn']);

        // The capability only the retired system realised loses its coverage.
        $lost = collect($diff['capability_coverage']['lost'])->pluck('code')->all();
        $this->assertContains('CAP-SOLE', $lost);
    }

    public function test_a_target_cost_overrides_the_modelled_cost(): void
    {
        $current = $this->plateau('P-C2', 'current');
        $target = $this->plateau('P-T2', 'target');
        $application = $this->application('APP-RENEG', ['annual_cost' => 100_000, 'cost_currency' => 'USD']);

        $service = new PlateauDiffService();
        $service->setDisposition($current, EaApplication::class, $application->id, 'retain');
        $service->setDisposition($target, EaApplication::class, $application->id, 'modify', [
            'target_annual_cost' => 1_000_000,
            'target_cost_currency' => 'NGN',
            'rationale' => 'Renegotiated at renewal.',
        ]);

        $diff = $service->diff($current, $target);

        $this->assertSame(1_000_000.0, $diff['cost']['to_ngn']);
        $this->assertLessThan(0, $diff['cost']['delta_ngn']);
        $this->assertSame(1, $diff['summary']['changed']);
    }

    public function test_a_replacement_without_a_successor_is_refused(): void
    {
        $target = $this->plateau('P-T3', 'target');
        $application = $this->application('APP-REPL');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/successor/');

        (new PlateauDiffService())->setDisposition($target, EaApplication::class, $application->id, 'replace');
    }

    public function test_a_plateau_seeds_from_the_live_estate_and_clones(): void
    {
        $this->application('APP-S1');
        $this->application('APP-S2');
        $this->application('APP-S3', ['lifecycle' => 'retired']);

        $current = $this->plateau('P-SEED', 'current');
        $service = new PlateauDiffService();

        $created = $service->seedFromCurrentEstate($current, [EaApplication::class]);
        $this->assertSame(2, $created['EaApplication'], 'a retired system is not part of the current estate');

        $copy = $this->plateau('P-COPY', 'target');
        $this->assertSame(2, $service->clone($current, $copy));
        $this->assertSame(2, PlateauEntity::where('plateau_id', $copy->id)->count());
    }

    /* ================================================================== */
    /* WS 4.4 — the ArchiMate round-trip gate                             */
    /* ================================================================== */

    public function test_the_archimate_round_trip_gate_passes(): void
    {
        $capability = $this->capability('CAP-XCH');
        $application = $this->application('APP-XCH');
        $component = $this->techComponent('TECH-XCH');

        EaInterface::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'IF-XCH', 'name' => 'Ledger feed',
            'source_app_id' => $application->id, 'target_app_id' => $application->id,
            'status' => 'active',
        ]);

        Relationship::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'source_type' => EaApplication::class, 'source_id' => $application->id,
            'target_type' => Capability::class, 'target_id' => $capability->id,
            'relation_type' => 'realisation',
        ]);

        $result = (new ArchiMateRoundTrip())->run();

        foreach ($result['checks'] as $check) {
            $this->assertTrue($check['pass'], "Round-trip check failed: {$check['name']} — {$check['detail']}");
        }
        $this->assertTrue($result['pass']);
        $this->assertSame($result['counts']['exported_elements'], $result['counts']['parsed_elements']);
        $this->assertSame($result['counts']['exported_relationships'], $result['counts']['parsed_relationships']);
    }

    public function test_a_relationship_with_an_unexported_endpoint_is_dropped_rather_than_dangled(): void
    {
        $application = $this->application('APP-DANGLE');

        // A relationship pointing at a record type the exporter does not carry.
        Relationship::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'source_type' => EaApplication::class, 'source_id' => $application->id,
            'target_type' => \App\Models\Ea\DpiaAssessment::class, 'target_id' => 999,
            'relation_type' => 'association',
        ]);

        $result = (new ArchiMateRoundTrip())->run();

        $endpoints = collect($result['checks'])->firstWhere('name', 'Every relationship endpoint resolves to an element');
        $this->assertTrue($endpoints['pass'],
            'Archi answers a dangling reference with an empty view rather than an error, so it must never be emitted');
    }

    public function test_an_incoming_document_is_inspected_without_being_imported(): void
    {
        $this->application('APP-IMPORT');
        $exported = (new \App\Services\Ea\ArchiMateExchange())->export();

        $before = EaApplication::withoutGlobalScopes()->count();
        $report = (new ArchiMateRoundTrip())->inspectImport($exported['xml']);

        $this->assertSame($before, EaApplication::withoutGlobalScopes()->count(), 'inspection must be a dry run');
        $this->assertArrayHasKey('EaApplication', $report['importable']);
        $this->assertSame('Atheris NexusRisk', $report['dialect']);
    }

    /* ================================================================== */
    /* WS 4.5 — scoped writes with draft-and-approve                      */
    /* ================================================================== */

    public function test_a_scoped_write_changes_nothing_until_it_is_approved(): void
    {
        $application = $this->application('APP-DRAFT', ['criticality' => 'medium']);
        $service = new DraftChangeService();

        $draft = $service->propose(EaApplication::class, 'update', ['criticality' => 'critical'], $application->id, 'mcp', 'test-agent');

        $this->assertSame('pending', $draft->status);
        $this->assertSame('medium', $application->fresh()->criticality, 'proposing must not write');

        $service->approve($draft, 'Confirmed with the owner.');

        $this->assertSame('critical', $application->fresh()->criticality);
        $this->assertSame('applied', $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->applied_at);
    }

    public function test_an_attribute_outside_the_scope_is_refused_at_proposal_time(): void
    {
        $application = $this->application('APP-SCOPE');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/organization_id/');

        (new DraftChangeService())->propose(
            EaApplication::class, 'update', ['organization_id' => 999], $application->id
        );
    }

    public function test_an_unwritable_entity_type_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not writable through the scoped API/i');

        (new DraftChangeService())->propose(\App\Models\Ea\QualitySeal::class, 'update', ['state' => 'approved'], 1);
    }

    public function test_a_draft_is_revalidated_at_approval_and_fails_loudly_when_stale(): void
    {
        $first = $this->application('APP-STALE-1');
        $second = $this->application('APP-STALE-2');

        $service = new DraftChangeService();
        $draft = $service->propose(EaApplication::class, 'update', ['code' => 'APP-CLAIMED'], $first->id);

        // Someone takes the code between proposal and approval.
        $second->forceFill(['code' => 'APP-CLAIMED'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no longer be applied/');

        try {
            $service->approve($draft);
        } finally {
            $this->assertSame('failed', $draft->fresh()->status);
            $this->assertSame('APP-STALE-1', $first->fresh()->code, 'the record must be untouched');
        }
    }

    public function test_a_rejected_draft_is_never_applied(): void
    {
        $application = $this->application('APP-REJECT', ['lifecycle' => 'live']);
        $service = new DraftChangeService();

        $draft = $service->propose(EaApplication::class, 'update', ['lifecycle' => 'retired'], $application->id);
        $service->reject($draft, 'Cited by a signed return.');

        $this->assertSame('rejected', $draft->fresh()->status);
        $this->assertSame('live', $application->fresh()->lifecycle);
    }

    public function test_the_mcp_write_tools_propose_rather_than_write(): void
    {
        $application = $this->application('APP-MCP', ['criticality' => 'low']);

        $response = (new McpServer())->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => [
                'name' => 'propose_update',
                'arguments' => [
                    'entity_type' => 'EaApplication',
                    'entity_id' => $application->id,
                    'changes' => ['criticality' => 'critical'],
                    'rationale' => 'Observed in the incident record.',
                ],
            ],
        ]);

        $payload = json_decode($response['result']['content'][0]['text'], true);

        $this->assertFalse($payload['applied']);
        $this->assertSame('pending', $payload['status']);
        $this->assertStringContainsString('approve ea', $payload['next_step']);
        $this->assertSame('low', $application->fresh()->criticality);
        $this->assertSame(1, DraftChange::count());
    }

    public function test_the_graphql_endpoint_reads_the_repository(): void
    {
        $capability = $this->capability('CAP-GQL');
        $application = $this->application('APP-GQL', ['criticality' => 'critical']);
        $this->link($application, $capability);

        $result = (new GraphQlService())->execute(<<<'GQL'
        query {
          applications(criticality: "critical", limit: 5) {
            code
            name
            capabilities { code }
          }
        }
        GQL);

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame('APP-GQL', $result['data']['applications'][0]['code']);
        $this->assertSame('CAP-GQL', $result['data']['applications'][0]['capabilities'][0]['code']);
    }

    public function test_a_graphql_mutation_only_records_a_draft(): void
    {
        $application = $this->application('APP-GQLM', ['lifecycle' => 'live']);

        $result = (new GraphQlService())->execute(<<<GQL
        mutation {
          proposeUpdate(entityType: "EaApplication", id: {$application->id}, agent: "cmdb", input: {
            lifecycle: "sunset"
          })
        }
        GQL);

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertFalse($result['data']['proposeUpdate']['applied']);
        $this->assertSame('live', $application->fresh()->lifecycle);
    }

    public function test_graphql_refuses_a_field_that_is_not_on_the_type(): void
    {
        $this->application('APP-GQLF');

        $result = (new GraphQlService())->execute('query { applications { organization_id } }');

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('is not a field', $result['errors'][0]['message']);
    }

    public function test_graphql_names_the_constructs_it_does_not_support(): void
    {
        $result = (new GraphQlService())->execute('query { ...appFields }');

        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('fragments', $result['errors'][0]['message']);
    }

    /* ================================================================== */
    /* WS 4.7 — cost, TCO and technical debt (B17)                        */
    /* ================================================================== */

    public function test_tco_is_modelled_from_licence_cost_with_itemised_uplifts(): void
    {
        $application = $this->application('APP-TCO', ['annual_cost' => 1000, 'cost_currency' => 'NGN', 'criticality' => 'critical']);

        $tco = (new CostModelService())->tco($application);

        $this->assertSame('modelled', $tco['basis']);
        $this->assertSame(1000.0, $tco['licence_ngn']);
        $this->assertGreaterThan($tco['licence_ngn'], $tco['total_ngn']);
        $this->assertArrayHasKey('infrastructure', $tco['components']);
        $this->assertArrayHasKey('support', $tco['components']);
    }

    public function test_a_recorded_tco_beats_the_model(): void
    {
        $application = $this->application('APP-TCO2', [
            'annual_cost' => 1000, 'cost_currency' => 'NGN', 'tco_annual_ngn' => 9999,
        ]);

        $tco = (new CostModelService())->tco($application);

        $this->assertSame('recorded', $tco['basis']);
        $this->assertSame(9999.0, $tco['total_ngn']);
    }

    public function test_a_cost_in_an_unknown_currency_is_reported_not_assumed(): void
    {
        $application = $this->application('APP-XYZ', ['annual_cost' => 5000, 'cost_currency' => 'XYZ', 'annual_cost_ngn' => null]);

        $tco = (new CostModelService())->tco($application);

        $this->assertNull($tco['total_ngn'], 'a foreign amount must never be added to a naira total as though it were naira');
        $this->assertSame('unknown_currency', $tco['basis']);

        $portfolio = (new CostModelService())->portfolio();
        $this->assertSame(1, $portfolio['totals']['unknown_currency']);
        $this->assertLessThan(100, $portfolio['totals']['cost_coverage_percent']);
    }

    public function test_cost_per_capability_rolls_up_the_subtree(): void
    {
        $parent = $this->capability('CAP-PARENT');
        $child = $this->capability('CAP-CHILD', $parent->id);
        (new HierarchyIndex())->rebuild(Capability::class);

        $application = $this->application('APP-CHILD', ['annual_cost' => 1000, 'cost_currency' => 'NGN']);
        $this->link($application, $child);

        $rows = collect((new CostModelService())->costPerCapability()['capabilities'])->keyBy('code');

        $this->assertSame(0.0, $rows['CAP-PARENT']['direct_cost_ngn'], 'nothing is mapped to the parent directly');
        $this->assertGreaterThan(0, $rows['CAP-PARENT']['rolled_cost_ngn'], 'the child’s cost must roll up');
        $this->assertSame($rows['CAP-CHILD']['rolled_cost_ngn'], $rows['CAP-PARENT']['rolled_cost_ngn']);
    }

    public function test_allocation_weight_splits_a_shared_application_cost(): void
    {
        $first = $this->capability('CAP-A');
        $second = $this->capability('CAP-B');
        (new HierarchyIndex())->rebuild(Capability::class);

        $application = $this->application('APP-SHARED', ['annual_cost' => 1000, 'cost_currency' => 'NGN']);
        $this->link($application, $first, 0.7);
        $this->link($application, $second, 0.3);

        $rows = collect((new CostModelService())->costPerCapability()['capabilities'])->keyBy('code');

        $this->assertEqualsWithDelta(
            $rows['CAP-A']['direct_cost_ngn'] / 0.7,
            $rows['CAP-B']['direct_cost_ngn'] / 0.3,
            0.01,
            'the two shares must come from the same total'
        );
    }

    public function test_technical_debt_scores_from_recorded_signals_and_reports_its_confidence(): void
    {
        $application = $this->application('APP-DEBT', ['technical_fit' => 1, 'lifecycle' => 'sunset']);
        $this->techComponent('TECH-EOL', [
            'application_ids' => [$application->id],
            'obsolescence_flag' => true,
            'eol_date' => now()->subYear()->toDateString(),
        ]);

        $debt = (new CostModelService())->technicalDebt($application);

        $this->assertGreaterThanOrEqual(60, $debt['score']);
        $this->assertSame('severe', $debt['band']);
        $this->assertNotEmpty($debt['drivers']);
        $this->assertSame(100.0, $debt['confidence']);

        $clean = $this->application('APP-CLEAN', ['technical_fit' => 5, 'lifecycle' => 'live', 'criticality' => 'low']);
        $this->assertSame('low', (new CostModelService())->technicalDebt($clean)['band']);
    }

    public function test_rationalisation_candidates_keep_the_best_fitting_system(): void
    {
        $capability = $this->capability('CAP-DUP');
        $strong = $this->application('APP-STRONG', ['business_fit' => 5, 'technical_fit' => 5, 'annual_cost' => 200_000, 'cost_currency' => 'NGN']);
        $weak = $this->application('APP-WEAK', ['business_fit' => 2, 'technical_fit' => 2, 'annual_cost' => 50_000, 'cost_currency' => 'NGN']);
        $this->link($strong, $capability);
        $this->link($weak, $capability);
        (new HierarchyIndex())->rebuild(Capability::class);

        $candidates = (new CostModelService())->rationalisationCandidates();

        $this->assertCount(1, $candidates);
        $this->assertSame('APP-STRONG', $candidates[0]['survivor']['code'],
            'a bank does not consolidate onto its weakest platform to save money');
        $this->assertGreaterThan(0, $candidates[0]['annual_saving_ngn']);
    }

    public function test_every_cost_total_reports_its_coverage_of_the_estate(): void
    {
        $this->application('APP-COST-1', ['annual_cost' => 1000, 'cost_currency' => 'NGN']);
        $this->application('APP-COST-2', ['annual_cost' => null, 'annual_cost_ngn' => null]);

        $totals = (new CostModelService())->portfolio()['totals'];

        $this->assertSame(2, $totals['application_count']);
        $this->assertSame(1, $totals['no_cost_recorded']);
        $this->assertSame(50.0, $totals['cost_coverage_percent'],
            'a total over half the estate must say so rather than present itself as the estate');
    }

    /* ================================================================== */
    /* §10 — the materialised indexes                                     */
    /* ================================================================== */

    public function test_the_hierarchy_closure_answers_subtree_ancestors_and_children(): void
    {
        $root = $this->capability('CAP-R');
        $mid = $this->capability('CAP-M', $root->id);
        $leaf = $this->capability('CAP-L', $mid->id);

        $index = new HierarchyIndex();
        $index->rebuild(Capability::class);

        $this->assertEqualsCanonicalizing([$mid->id, $leaf->id], $index->subtree(Capability::class, $root->id));
        $this->assertSame([$mid->id, $root->id], $index->ancestors(Capability::class, $leaf->id));
        $this->assertSame([$mid->id], $index->children(Capability::class, $root->id));
        $this->assertSame([$root->id], $index->roots(Capability::class));
        $this->assertSame(2, $index->subtreeSizes(Capability::class)[$root->id]);
        $this->assertTrue($index->isFresh(Capability::class));
    }

    public function test_a_stale_closure_is_reported_as_stale(): void
    {
        $root = $this->capability('CAP-S1');
        $index = new HierarchyIndex();
        $index->rebuild(Capability::class);

        $this->capability('CAP-S2', $root->id);

        $this->assertFalse($index->isFresh(Capability::class),
            'a stale index is worse than none, because roll-ups computed from it look authoritative');

        $index->rebuild(Capability::class);
        $this->assertTrue($index->isFresh(Capability::class));
    }

    public function test_the_capability_pivot_mirrors_the_json_column(): void
    {
        $first = $this->capability('CAP-P1');
        $second = $this->capability('CAP-P2');
        $application = $this->application('APP-PIVOT', ['capability_ids' => [$first->id, $second->id]]);

        $index = new CapabilityLinkIndex();
        $index->rebuild();

        $this->assertTrue($index->isFresh());
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $index->capabilitiesFor([$application->id]));
        $this->assertSame([$application->id], $index->applicationsFor($first->id));

        // An id that does not resolve is dropped rather than stored dangling.
        $application->forceFill(['capability_ids' => [$first->id, 99999]])->save();
        $index->rebuild();
        $this->assertSame([$first->id], $index->capabilitiesFor([$application->id]));
    }

    public function test_the_reindex_endpoint_rebuilds_both_indexes(): void
    {
        $root = $this->capability('CAP-RI');
        $this->capability('CAP-RI2', $root->id);
        $this->application('APP-RI', ['capability_ids' => [$root->id]]);

        $this->post(route('ea.reindex'))->assertRedirect();

        $this->assertTrue((new HierarchyIndex())->isFresh(Capability::class));
        $this->assertTrue((new CapabilityLinkIndex())->isFresh());
    }
}
