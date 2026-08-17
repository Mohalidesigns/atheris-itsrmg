<?php

namespace Database\Seeders;

use App\Models\Ea\Capability;
use App\Models\Ea\Diagram;
use App\Models\Ea\DraftChange;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Initiative;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Plateau;
use App\Models\Ea\PlateauEntity;
use App\Models\Ea\Principle;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\Standard;
use App\Models\Ea\TechComponent;
use App\Models\User;
use App\Services\Ea\CapabilityLinkIndex;
use App\Services\Ea\DiagramService;
use App\Services\Ea\DraftChangeService;
use App\Services\Ea\HierarchyIndex;
use App\Services\Ea\PlateauDiffService;
use App\Services\Ea\RelationshipValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * EaDepthDemoSeeder — demo data for ATH-EAR-002 Phase 4 (§9, "Depth and scale").
 *
 * Phase 4's four headline features are all *derived* — they compute over the
 * graph rather than storing anything of their own — so each one is only as
 * convincing as the graph underneath it. Three gaps in the existing demo estate
 * made them look broken rather than empty:
 *
 *  · `ea_tech_components_ext.application_ids` was null on all 51 components, so
 *    n-hop impact never reached technology and every technical-debt score was
 *    computed with its obsolescence dimension blank.
 *  · `ea_processes.linked_applications` was null on all 60 processes, so
 *    cost-per-process was zero and no impact analysis ever reported a recovery
 *    objective at risk — the single most useful thing a change board reads.
 *  · No plateau had membership, so the plateau diff had nothing to diff.
 *
 * Following the house rule from the earlier phases, the data seeded here is
 * deliberately uneven: about a fifth of applications carry no technology link,
 * some target costs are absent, and one diagram carries a real metamodel error.
 * A uniformly green demo teaches an evaluator nothing, and every one of these
 * screens exists to *find* problems.
 */
class EaDepthDemoSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = EaApplication::withoutGlobalScopes()->value('organization_id');

        // Several helpers below (DiagramService, PlateauDiffService) write
        // tenant-scoped rows through the model layer, which reads the
        // authenticated user. Seeders have none, so borrow one.
        $actor = User::where('organization_id', $organizationId)->first();
        if ($actor) {
            Auth::login($actor);
        }

        $this->linkTechnologyToApplications();
        $this->linkProcessesToApplications();
        $this->enrichRelationshipGraph();
        $this->rebuildIndexes();
        $this->seedPlateauMembership();
        $this->seedDiagrams();
        $this->seedDraftQueue($actor);

        if ($actor) {
            Auth::logout();
        }
    }

    /* ================= estate links ================= */

    /**
     * Give every technology component the applications it runs under.
     *
     * Assignment is by category so the graph reads like a real estate: a
     * database serves the transaction systems, a container platform serves the
     * digital channels, a mainframe serves core banking only.
     */
    private function linkTechnologyToApplications(): void
    {
        $applications = EaApplication::withoutGlobalScopes()->orderBy('id')->get();
        if ($applications->isEmpty()) {
            return;
        }

        $components = TechComponent::withoutGlobalScopes()->orderBy('id')->get();

        // Deliberate gap: roughly one application in five has no recorded
        // technology stack, which is what an unmapped estate looks like and what
        // the Score Card is meant to surface.
        $mapped = $applications->reject(fn ($app, $index) => $index % 5 === 4)->values();

        foreach ($components as $index => $component) {
            $breadth = match (strtolower((string) $component->category)) {
                'database', 'platform', 'middleware', 'integration' => 8,
                'os', 'operating system', 'infrastructure', 'security' => 6,
                'language', 'framework', 'runtime' => 4,
                default => 3,
            };

            $applicationIds = [];
            for ($n = 0; $n < $breadth; $n++) {
                // Deterministic spread: stride by a co-prime of the set size so
                // the same component does not always land on the same handful.
                $position = ($index * 7 + $n * 3) % max(1, $mapped->count());
                $applicationIds[] = (int) $mapped[$position]->id;
            }

            $component->forceFill(['application_ids' => array_values(array_unique($applicationIds))])->saveQuietly();
        }
    }

    /**
     * Link L2 and L3 processes to the applications that support them, and give
     * the leaf processes a recovery objective where the BIA has not set one.
     *
     * The RTO matters beyond bookkeeping: `ImpactAnalyser` reports
     * `tight_rto_processes` — processes with a four-hour-or-better objective —
     * because that is what turns a change into an out-of-hours window. Contract
     * I-6 (Phase 2) already writes a real RTO from the BIA where one exists, so
     * only the untouched rows are filled here.
     */
    private function linkProcessesToApplications(): void
    {
        $applications = EaApplication::withoutGlobalScopes()->orderBy('id')->get();
        if ($applications->isEmpty()) {
            return;
        }

        $byCapability = [];
        foreach (DB::table('ea_application_capabilities')->get() as $link) {
            $byCapability[(int) $link->capability_id][] = (int) $link->application_id;
        }

        $hierarchy = new HierarchyIndex;
        $processes = Process::withoutGlobalScopes()->orderBy('level')->orderBy('id')->get();

        foreach ($processes as $index => $process) {
            $candidates = [];

            // Prefer the applications that realise the process's own capability
            // (or anything in its subtree) — a real support relationship rather
            // than a random one.
            if ($process->capability_id) {
                $capabilityIds = array_merge(
                    [(int) $process->capability_id],
                    $hierarchy->subtree(Capability::class, (int) $process->capability_id)
                );
                foreach ($capabilityIds as $capabilityId) {
                    $candidates = array_merge($candidates, $byCapability[$capabilityId] ?? []);
                }
            }

            if (! $candidates) {
                $candidates = [
                    (int) $applications[($index * 5) % $applications->count()]->id,
                    (int) $applications[($index * 5 + 2) % $applications->count()]->id,
                ];
            }

            $candidates = array_values(array_unique($candidates));
            $take = $process->level >= 3 ? 2 : min(4, count($candidates));

            $updates = ['linked_applications' => array_slice($candidates, 0, max(1, $take))];

            // Leaf processes without a BIA-derived objective get one, weighted so
            // the critical ones are genuinely tight.
            if ($process->rto_hours === null) {
                $updates['rto_hours'] = match ($process->criticality) {
                    'critical' => [1, 2, 4][$index % 3],
                    'high' => [4, 8][$index % 2],
                    'medium' => 24,
                    default => 72,
                };
                $updates['rpo_hours'] = max(1, (int) ($updates['rto_hours'] / 2));
            }

            $process->forceFill($updates)->saveQuietly();
        }
    }

    /**
     * Thicken `ea_relationships` with the explicit ArchiMate edges the estate
     * implies but never recorded.
     *
     * Every candidate goes through {@see RelationshipValidator} before it is
     * written. That is not defensive tidiness: the WS 4.4 release gate asserts
     * every exported relationship type is legal ArchiMate 3.2, so a seeder
     * writing `dependsOn` between two nodes would fail the gate rather than the
     * seeder, and the failure would look like an exporter bug.
     */
    private function enrichRelationshipGraph(): void
    {
        $pairs = [];

        // Applications realise the capabilities they are mapped to.
        foreach (DB::table('ea_application_capabilities')->orderBy('id')->limit(120)->get() as $link) {
            $pairs[] = [EaApplication::class, (int) $link->application_id, 'realisation', Capability::class, (int) $link->capability_id];
        }

        // Technology components are assigned to the applications they run.
        foreach (TechComponent::withoutGlobalScopes()->whereNotNull('application_ids')->orderBy('id')->get() as $component) {
            foreach (array_slice((array) $component->application_ids, 0, 2) as $applicationId) {
                $pairs[] = [TechComponent::class, (int) $component->id, 'assignment', EaApplication::class, (int) $applicationId];
            }
        }

        // Initiatives (work packages) realise capabilities and applications.
        $capabilities = Capability::withoutGlobalScopes()->orderBy('id')->pluck('id')->all();
        $applications = EaApplication::withoutGlobalScopes()->orderBy('id')->pluck('id')->all();
        foreach (Initiative::withoutGlobalScopes()->orderBy('id')->get() as $index => $initiative) {
            if ($capabilities) {
                $pairs[] = [Initiative::class, (int) $initiative->id, 'realisation', Capability::class, (int) $capabilities[$index % count($capabilities)]];
            }
            if ($applications) {
                $pairs[] = [Initiative::class, (int) $initiative->id, 'realisation', EaApplication::class, (int) $applications[($index * 3) % count($applications)]];
            }
        }

        // Plateaux aggregate what they contain — the edge that makes an
        // ArchiMate export of a roadmap readable in Archi.
        foreach (Plateau::withoutGlobalScopes()->orderBy('id')->get() as $index => $plateau) {
            foreach (array_slice($capabilities, $index * 4, 4) as $capabilityId) {
                $pairs[] = [Plateau::class, (int) $plateau->id, 'aggregation', Capability::class, (int) $capabilityId];
            }
        }

        // Principles influence standards (requirements) — the governance chain
        // §5.4 wants traceable from principle to submission.
        $standards = Standard::withoutGlobalScopes()->orderBy('id')->pluck('id')->all();
        foreach (Principle::withoutGlobalScopes()->orderBy('id')->get() as $index => $principle) {
            if ($standards) {
                $pairs[] = [Principle::class, (int) $principle->id, 'influence', Standard::class, (int) $standards[$index % count($standards)]];
            }
        }

        // Process hierarchy as explicit composition.
        foreach (Process::withoutGlobalScopes()->whereNotNull('parent_id')->orderBy('id')->limit(40)->get() as $process) {
            $pairs[] = [Process::class, (int) $process->parent_id, 'composition', Process::class, (int) $process->id];
        }

        $organizationId = EaApplication::withoutGlobalScopes()->value('organization_id');
        $written = 0;
        $refused = 0;

        foreach ($pairs as [$sourceType, $sourceId, $relation, $targetType, $targetId]) {
            if (! $sourceId || ! $targetId) {
                continue;
            }

            if (! RelationshipValidator::isPermitted($sourceType, $relation, $targetType)) {
                $refused++;

                continue;
            }

            $exists = Relationship::withoutGlobalScopes()
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('target_type', $targetType)->where('target_id', $targetId)
                ->where('relation_type', $relation)
                ->exists();

            if ($exists) {
                continue;
            }

            Relationship::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'relation_type' => $relation,
                'attrs' => ['seeded_by' => 'EaDepthDemoSeeder'],
            ]);
            $written++;
        }

        $this->command?->info("  EA depth: {$written} relationship(s) written, {$refused} refused by the ArchiMate matrix.");
    }

    private function rebuildIndexes(): void
    {
        (new HierarchyIndex)->rebuild();
        (new CapabilityLinkIndex)->rebuild();
    }

    /* ================= scenarios ================= */

    /**
     * Populate the four plateaux with a rationalisation story a board would
     * recognise: today's estate, two transitions, and a 2028 target that retires
     * the duplicates, replaces the sunsetting core and introduces three new
     * systems — with the one-off cost of getting there.
     */
    private function seedPlateauMembership(): void
    {
        $plateaux = Plateau::withoutGlobalScopes()->orderBy('effective_from')->orderBy('id')->get();
        if ($plateaux->count() < 2) {
            return;
        }

        $service = new PlateauDiffService;
        $current = $plateaux->firstWhere('plateau_type', 'current') ?: $plateaux->first();
        $target = $plateaux->firstWhere('plateau_type', 'target') ?: $plateaux->last();
        $transitions = $plateaux->where('plateau_type', 'transition')->values();

        // Baseline: everything live today, in every plateau.
        foreach ($plateaux as $plateau) {
            if (PlateauEntity::withoutGlobalScopes()->where('plateau_id', $plateau->id)->exists()) {
                continue;
            }
            $service->seedFromCurrentEstate($plateau);
        }

        $applications = EaApplication::withoutGlobalScopes()->orderBy('id')->get();

        // --- the target state ---------------------------------------------

        // 1. Retire the systems already marked Eliminate, plus the sunsetting
        //    ones. This is the disposition an APM assessment implies but that
        //    nothing in the module previously let anyone record.
        $retire = $applications->filter(
            fn ($app) => $app->time_score === 'Eliminate' || $app->lifecycle === 'sunset'
        )->take(9);

        foreach ($retire as $app) {
            $service->setDisposition($target, EaApplication::class, $app->id, 'retire', [
                'rationale' => $app->time_score === 'Eliminate'
                    ? 'TIME assessment: Eliminate. Function absorbed by the consolidated platform.'
                    : 'Already sunsetting; the 2028 target state does not carry it.',
                'one_off_cost' => round((float) ($app->annual_cost_ngn ?? 40_000_000) * 0.35, 2),
                'confidence' => 4,
            ]);
        }

        // 2. Replace the most expensive sunsetting system with the strongest
        //    surviving one — the post-merger core consolidation (§11 wedge 3).
        $successor = $applications->where('lifecycle', 'live')->sortByDesc('technical_fit')->first();
        $replaced = $applications->where('lifecycle', 'sunset')->sortByDesc('annual_cost_ngn')->first();

        if ($successor && $replaced && $successor->id !== $replaced->id) {
            $service->setDisposition($target, EaApplication::class, $replaced->id, 'replace', [
                'replaced_by_id' => $successor->id,
                'rationale' => "Consolidated onto {$successor->name} following the recapitalisation merger.",
                'one_off_cost' => 1_850_000_000.00,
                'confidence' => 3,
            ]);
        }

        // 3. Modernise the four costliest survivors — same system, lower
        //    licence after renegotiation, recorded in its own currency so the FX
        //    lens still applies to the target state.
        foreach ($applications->where('lifecycle', 'live')->sortByDesc('annual_cost')->take(4) as $index => $app) {
            $service->setDisposition($target, EaApplication::class, $app->id, 'modify', [
                'target_annual_cost' => $app->annual_cost !== null
                    ? round((float) $app->annual_cost * 0.78, 2)
                    : null,
                'target_cost_currency' => $app->cost_currency ?: 'NGN',
                'one_off_cost' => 220_000_000.00,
                // Deliberately uneven: the first two are firm commercial
                // positions, the others are planning assumptions.
                'confidence' => $index < 2 ? 5 : 2,
                'rationale' => 'Renewal renegotiated at the 2027 licence review; scope unchanged.',
            ]);
        }

        // 4. Three systems the target state introduces but the estate does not
        //    yet have. They are real records with `plan` lifecycle, because a
        //    scenario made of imaginary rows cannot be costed or owned.
        $introduce = [
            ['FBN-APP-901', 'Unified Customer Data Platform', 'critical', 1_450_000.00, 'USD'],
            ['FBN-APP-902', 'Cloud-Native Payments Orchestrator', 'critical', 980_000.00, 'USD'],
            ['FBN-APP-903', 'Regulatory Reporting Hub (CBN/NDPC)', 'high', 320_000_000.00, 'NGN'],
        ];

        foreach ($introduce as [$code, $name, $criticality, $cost, $currency]) {
            $app = EaApplication::withoutGlobalScopes()->firstOrCreate(['code' => $code], [
                'organization_id' => $current->organization_id,
                'name' => $name,
                'description' => 'Introduced by the 2028 target architecture; not yet in service.',
                'criticality' => $criticality,
                'lifecycle' => 'plan',
                'time_score' => 'Invest',
                'business_fit' => 5,
                'technical_fit' => 5,
                'annual_cost' => $cost,
                'cost_currency' => $currency,
                'hosting_country' => 'NG',
                'hosting_model' => 'private_cloud',
                'contains_nigerian_payment_data' => $code === 'FBN-APP-902',
            ]);

            // Present in the target, and in the later transition, but absent
            // from today — which is what makes the diff show an addition.
            $service->setDisposition($target, EaApplication::class, $app->id, 'introduce', [
                'target_annual_cost' => $cost,
                'target_cost_currency' => $currency,
                'one_off_cost' => 640_000_000.00,
                'confidence' => 3,
                'rationale' => 'Target-state capability with no incumbent system.',
            ]);

            if ($transitions->count() > 1) {
                $service->setDisposition($transitions->last(), EaApplication::class, $app->id, 'introduce', [
                    'target_annual_cost' => $cost,
                    'target_cost_currency' => $currency,
                    'confidence' => 2,
                ]);
            }

            PlateauEntity::withoutGlobalScopes()
                ->where('plateau_id', $current->id)
                ->where('entity_type', EaApplication::class)
                ->where('entity_id', $app->id)
                ->delete();
        }

        // 5. Retire the obsolete technology the retirements make redundant, so
        //    the risk delta has something real to close: I-1 has already opened
        //    a `EA-OBS-*` risk for each of these.
        foreach (TechComponent::withoutGlobalScopes()->where('obsolescence_flag', true)->orderBy('id')->limit(8)->get() as $component) {
            $service->setDisposition($target, TechComponent::class, $component->id, 'retire', [
                'rationale' => 'Past end-of-life; removed with the systems that depend on it.',
                'confidence' => 4,
            ]);
        }

        // 6. The transitions retire a subset each, so the roadmap has a shape
        //    rather than a cliff at the end.
        foreach ($transitions as $index => $transition) {
            foreach ($retire->slice($index * 3, 3) as $app) {
                $service->setDisposition($transition, EaApplication::class, $app->id, 'retire', [
                    'rationale' => "Scheduled for retirement in {$transition->name}.",
                    'confidence' => 3,
                ]);
            }
        }
    }

    /* ================= diagrams ================= */

    /**
     * Three diagrams derived from the repository, not drawn.
     *
     * Derivation is the point of WS 4.1: the first diagram of an application
     * should be generated and then curated. The third one is deliberately
     * invalid so the validation panel and the approval refusal are visible in a
     * demo rather than described.
     */
    private function seedDiagrams(): void
    {
        $service = new DiagramService;

        $coreBanking = EaApplication::withoutGlobalScopes()->where('criticality', 'critical')->orderBy('id')->first();
        $capability = Capability::withoutGlobalScopes()->where('level', 1)->orderBy('id')->first();

        if ($coreBanking) {
            $this->deriveDiagram($service, [
                'code' => 'DGM-TOPO-01',
                'name' => 'Core Banking Connection Topology',
                'viewpoint' => 'application_cooperation',
                'description' => 'Approved network topology for the core banking estate and every connection to '.
                    'switches, regulators and third parties. Produced for CBN RBCF Appendix II §1.1(i).',
                'is_topology' => true,
                'layout_algorithm' => 'layered',
            ], EaApplication::class, $coreBanking->id, 2, approve: true);
        }

        if ($capability) {
            $this->deriveDiagram($service, [
                'code' => 'DGM-REAL-01',
                'name' => $capability->name.' — Realisation View',
                'viewpoint' => 'layered',
                'description' => 'What realises this capability, and what those systems in turn depend on.',
                'layout_algorithm' => 'hierarchical',
            ], Capability::class, $capability->id, 2);
        }

        // The five viewpoint snapshots EaPhase4Seeder created carry no elements
        // at all — they were placeholders for a canvas that could not yet be
        // generated. Fill each one from the repository so the Diagrams index is
        // a set of real drawings rather than five empty frames.
        $this->fillEmptyDiagrams($service);

        // The deliberately-broken one. Two illegal edges: a technology node
        // cannot compose an application component, and a data object cannot
        // trigger a capability. Both are the kind of thing a hand-drawn canvas
        // acquires, and both must block approval.
        $application = EaApplication::withoutGlobalScopes()->orderBy('id')->first();
        $component = TechComponent::withoutGlobalScopes()->orderBy('id')->first();

        if ($application && $component) {
            $diagram = Diagram::withoutGlobalScopes()->firstOrNew(['code' => 'DGM-DRAFT-01']);
            $diagram->organization_id ??= $application->organization_id;
            $diagram->version ??= 0;

            $elements = [
                ['id' => 'n-app', 'label' => $application->name, 'type' => 'application-component',
                    'entity_type' => EaApplication::class, 'entity_id' => $application->id, 'position' => ['x' => 60, 'y' => 60]],
                ['id' => 'n-node', 'label' => $component->name, 'type' => 'node',
                    'entity_type' => TechComponent::class, 'entity_id' => $component->id, 'position' => ['x' => 60, 'y' => 300]],
                ['id' => 'n-data', 'label' => 'Customer Master', 'type' => 'data-object', 'position' => ['x' => 340, 'y' => 180]],
                ['id' => 'n-cap', 'label' => 'Customer Onboarding', 'type' => 'capability', 'position' => ['x' => 620, 'y' => 60]],
            ];

            $edges = [
                ['id' => 'e-1', 'source' => 'n-node', 'target' => 'n-app', 'label' => 'assignment', 'data' => ['relation' => 'assignment']],
                ['id' => 'e-2', 'source' => 'n-app', 'target' => 'n-data', 'label' => 'access', 'data' => ['relation' => 'access']],
                // Illegal: composition is only permitted between like elements.
                ['id' => 'e-3', 'source' => 'n-node', 'target' => 'n-app', 'label' => 'composition', 'data' => ['relation' => 'composition']],
                // Illegal: a data object cannot trigger anything.
                ['id' => 'e-4', 'source' => 'n-data', 'target' => 'n-cap', 'label' => 'triggering', 'data' => ['relation' => 'triggering']],
            ];

            $service->save($diagram, [
                'code' => 'DGM-DRAFT-01',
                'name' => 'Customer Onboarding — Working Draft',
                'viewpoint' => 'custom',
                'description' => 'Work in progress. Carries two ArchiMate metamodel errors, which is why it '.
                    'cannot be approved — the validation panel lists both.',
                'status' => 'draft',
                'layout_algorithm' => 'manual',
            ], $elements, $edges, 'Initial sketch from the onboarding workshop');
        }
    }

    /**
     * Give every empty diagram a canvas derived from a seed entity chosen to
     * match its viewpoint, and lay it out with the algorithm that viewpoint
     * implies.
     */
    private function fillEmptyDiagrams(DiagramService $service): void
    {
        $seeds = [
            'layered' => [Capability::class, 'level', 1, 'layered'],
            'application_cooperation' => [EaApplication::class, 'criticality', 'critical', 'circular'],
            'information_structure' => [LogicalEntity::class, null, null, 'grid'],
            'implementation_deployment' => [TechComponent::class, 'radar_status', 'adopt', 'layered'],
            'risk_security' => [EaApplication::class, 'criticality', 'high', 'layered'],
        ];

        foreach (Diagram::withoutGlobalScopes()->get() as $diagram) {
            if (! empty($diagram->elements_json)) {
                continue;
            }

            [$class, $column, $value, $algorithm] = $seeds[$diagram->viewpoint] ?? [EaApplication::class, null, null, 'layered'];
            if (! class_exists($class)) {
                continue;
            }

            $seed = $class::withoutGlobalScopes()
                ->when($column, fn ($q) => $q->where($column, $value))
                ->orderBy('id')->first()
                ?: $class::withoutGlobalScopes()->orderBy('id')->first();

            if (! $seed) {
                continue;
            }

            $canvas = $service->generateAround($class, $seed->id, 2, $algorithm, 22);
            if (! $canvas['elements']) {
                continue;
            }

            $previousStatus = $diagram->status;

            $service->save($diagram, [
                'code' => $diagram->code,
                'name' => $diagram->name,
                'viewpoint' => $diagram->viewpoint,
                'description' => $diagram->description,
                'layout_algorithm' => $algorithm,
                'status' => $previousStatus,
            ], $canvas['elements'], $canvas['edges'], 'Populated from the repository');

            // Restore the approval the placeholder claimed, but only where the
            // derived canvas is actually valid — an approved diagram with
            // metamodel errors is the state WS 4.1 exists to prevent.
            if ($previousStatus === 'approved') {
                try {
                    $service->approve($diagram->refresh());
                } catch (\InvalidArgumentException $e) {
                    $diagram->refresh()->forceFill(['status' => 'review'])->save();
                    $this->command?->warn("  EA depth: {$diagram->code} demoted to review — ".$e->getMessage());
                }
            }
        }
    }

    private function deriveDiagram(DiagramService $service, array $attributes, string $entityType, int $entityId, int $depth, bool $approve = false, int $limit = 26): void
    {
        // The limit is the curation step: a derived canvas of the whole
        // neighbourhood is unreadable, and the generator trims by graph distance
        // so what survives is the neighbourhood rather than the periphery.
        $canvas = $service->generateAround($entityType, $entityId, $depth, $attributes['layout_algorithm'] ?? 'layered', $limit);

        if (! $canvas['elements']) {
            return;
        }

        $diagram = Diagram::withoutGlobalScopes()->firstOrNew(['code' => $attributes['code']]);
        $diagram->organization_id ??= EaApplication::withoutGlobalScopes()->value('organization_id');
        $diagram->version ??= 0;

        $diagram = $service->save($diagram, $attributes, $canvas['elements'], $canvas['edges'], 'Derived from the repository');

        if ($approve) {
            try {
                $service->approve($diagram);
            } catch (\InvalidArgumentException $e) {
                // A derived canvas can inherit an illegal edge from the estate.
                // Leaving it in review is the honest outcome and the validation
                // panel says why, so the seeder does not force it through.
                $this->command?->warn('  EA depth: '.$attributes['code'].' left in review — '.$e->getMessage());
            }
        }
    }

    /* ================= draft-and-approve queue ================= */

    /**
     * A populated review queue, because WS 4.5's whole claim is that an agent
     * proposes and a human decides — and an empty queue demonstrates neither.
     *
     * The drafts are written directly rather than through
     * {@see DraftChangeService} so the seeder can stage the
     * decided ones (applied, rejected, failed-revalidation) without actually
     * mutating the estate a moment after the other seeders built it.
     */
    private function seedDraftQueue(?User $actor): void
    {
        if (DraftChange::withoutGlobalScopes()->exists()) {
            return;
        }

        $organizationId = EaApplication::withoutGlobalScopes()->value('organization_id');
        $applications = EaApplication::withoutGlobalScopes()->orderBy('id')->take(6)->get();
        $component = TechComponent::withoutGlobalScopes()->whereNotNull('eol_date')->orderBy('id')->first();

        if ($applications->count() < 4) {
            return;
        }

        $drafts = [
            [
                'reference' => 'DRAFT-CMDB0001',
                'origin' => 'mcp',
                'operation' => 'update',
                'entity_type' => EaApplication::class,
                'entity_id' => $applications[0]->id,
                'payload' => ['user_count' => (int) ($applications[0]->user_count ?? 500) + 1240, 'owner_role' => 'Head, Retail Technology'],
                'before' => ['user_count' => $applications[0]->user_count, 'owner_role' => $applications[0]->owner_role],
                'status' => 'pending',
                'proposed_by_label' => 'cmdb-sync (mcp)',
                'reason' => 'ServiceNow CMDB reports a higher entitlement count than the repository holds.',
                'validation_json' => ['ok' => true, 'problems' => [], 'notes' => [
                    'This record carries an approved quality seal; applying the change will break it and require re-approval.',
                ]],
            ],
            [
                'reference' => 'DRAFT-AGENT0002',
                'origin' => 'mcp',
                'operation' => 'update',
                'entity_type' => EaApplication::class,
                'entity_id' => $applications[1]->id,
                'payload' => ['lifecycle' => 'sunset', 'time_score' => 'Eliminate'],
                'before' => ['lifecycle' => $applications[1]->lifecycle, 'time_score' => $applications[1]->time_score],
                'status' => 'pending',
                'proposed_by_label' => 'claude-architecture-assistant',
                'reason' => 'Duplicate capability coverage with two better-fitting systems; proposed for the 2028 target state.',
                'validation_json' => ['ok' => true, 'problems' => [], 'notes' => []],
            ],
            [
                'reference' => 'DRAFT-GQL00003',
                'origin' => 'graphql',
                'operation' => 'create',
                'entity_type' => EaInterface::class,
                'entity_id' => null,
                'payload' => [
                    'code' => 'IF-NIBSS-014',
                    'name' => 'NIBSS Instant Payment — settlement confirmation',
                    'source_app_id' => $applications[2]->id,
                    'target_app_id' => $applications[3]->id,
                    'protocol' => 'REST',
                    'pattern' => 'async',
                    'classification' => 'Restricted',
                    'pii_carrying' => true,
                    'objective' => 'Receive settlement confirmations for instant-payment batches.',
                    'counterparty_type' => 'switch',
                    'review_cadence' => 'quarterly',
                ],
                'before' => null,
                'status' => 'pending',
                'proposed_by_label' => 'integration-discovery (graphql)',
                'reason' => 'Observed in API gateway traffic but absent from the connection catalogue.',
                'validation_json' => ['ok' => true, 'problems' => [], 'notes' => []],
            ],
            [
                'reference' => 'DRAFT-EOL00004',
                'origin' => 'mcp',
                'operation' => 'update',
                'entity_type' => TechComponent::class,
                'entity_id' => $component?->id,
                'payload' => ['eol_date' => now()->addMonths(4)->toDateString(), 'radar_status' => 'hold'],
                'before' => ['eol_date' => optional($component?->eol_date)->toDateString(), 'radar_status' => $component?->radar_status],
                'status' => 'applied',
                'proposed_by_label' => 'endoflife-feed (mcp)',
                'reason' => 'Vendor brought the end-of-life date forward; approved and applied.',
                'validation_json' => ['ok' => true, 'problems' => [], 'notes' => []],
                'decided_at' => now()->subDays(6),
                'applied_at' => now()->subDays(6),
            ],
            [
                'reference' => 'DRAFT-AGENT0005',
                'origin' => 'mcp',
                'operation' => 'delete',
                'entity_type' => EaApplication::class,
                'entity_id' => $applications[4]->id ?? $applications[0]->id,
                'payload' => [],
                'before' => ['name' => $applications[4]->name ?? $applications[0]->name],
                'status' => 'rejected',
                'proposed_by_label' => 'claude-architecture-assistant',
                'reason' => 'Rejected: the system is cited by a signed CSAT return. Retire it through a plateau '.
                    'disposition instead, so the citation keeps resolving.',
                'validation_json' => ['ok' => true, 'problems' => [], 'notes' => [
                    '7 relationship(s) reference this entity and would be orphaned.',
                ]],
                'decided_at' => now()->subDays(2),
            ],
            [
                'reference' => 'DRAFT-STALE0006',
                'origin' => 'graphql',
                'operation' => 'update',
                'entity_type' => Capability::class,
                'entity_id' => Capability::withoutGlobalScopes()->orderBy('id')->value('id'),
                'payload' => ['code' => 'DUPLICATE-CODE'],
                'before' => ['code' => Capability::withoutGlobalScopes()->orderBy('id')->value('code')],
                'status' => 'failed',
                'proposed_by_label' => 'bulk-import-agent (graphql)',
                'reason' => 'Revalidation failed at apply time: the code was taken by another capability between '.
                    'proposal and approval.',
                'validation_json' => ['ok' => false, 'problems' => [
                    "Code 'DUPLICATE-CODE' is already used by another Capability.",
                ], 'notes' => []],
                'decided_at' => now()->subDay(),
            ],
        ];

        foreach ($drafts as $draft) {
            if (empty($draft['entity_id']) && $draft['operation'] !== 'create') {
                continue;
            }

            DraftChange::withoutGlobalScopes()->create($draft + [
                'organization_id' => $organizationId,
                'proposed_by' => $actor?->id,
                'decided_by' => in_array($draft['status'], ['applied', 'rejected', 'failed'], true) ? $actor?->id : null,
            ]);
        }
    }
}
