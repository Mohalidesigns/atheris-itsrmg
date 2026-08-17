<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\ControlMapping;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\Initiative;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\Site;
use App\Models\Ea\TechComponent;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * ImpactAnalyser — n-hop change-impact traversal from *any* entity (WS 4.2 / B14).
 *
 * §5.4 on why this one matters commercially: n-hop impact is "Orbus's *weakest*
 * rated feature (77/100). Attackable." §4 row for B14 scores it 4/4 on impact.
 *
 * What it replaces: {@see BlastRadiusService}, which starts only from an
 * `EaApplication`, expands only application-to-application edges, and re-queries
 * per node inside the BFS loop. That is fine for the Blast Radius screen and
 * hopeless as a per-entity impact tab at the §10 scale target.
 *
 * Three differences that matter:
 *
 *  1. **Any start type.** "What breaks if we retire this technology component"
 *     and "what does this capability depend on" are the same question.
 *  2. **All the edges, not just the explicit ones.** The repository's real graph
 *     is spread across `ea_relationships`, `ea_interfaces`, the capability
 *     pivot, `ea_processes.linked_applications`, `ea_tech_components_ext.
 *     application_ids`, hosting sites and vendors. A traversal that reads only
 *     the generic table under-reports, and an under-reported blast radius is
 *     worse than none — it gets believed.
 *  3. **Preloaded adjacency.** The whole edge set is loaded once and walked in
 *     memory, so depth 5 over 20,000 relationships costs a fixed number of
 *     queries rather than one per frontier node.
 */
class ImpactAnalyser
{
    public const DEFAULT_DEPTH = 2;
    public const MAX_DEPTH = 5;

    /** Traversal direction. */
    public const BOTH = 'both';
    public const DOWNSTREAM = 'downstream';   // things this entity affects
    public const UPSTREAM = 'upstream';       // things this entity depends on

    /** Entity types that can start a traversal, and their display metadata. */
    public const START_TYPES = [
        EaApplication::class => 'Application',
        Capability::class => 'Capability',
        TechComponent::class => 'Technology component',
        EaInterface::class => 'Interface',
        Process::class => 'Business process',
        LogicalEntity::class => 'Logical data entity',
        Site::class => 'Site',
    ];

    /** Lazily-built adjacency: "Class|id" => list of edges. */
    private ?array $adjacency = null;

    private array $nodeCache = [];

    /**
     * Breadth-first traversal.
     *
     * @return array{
     *   root:array<string,mixed>|null,
     *   nodes:array<int,array<string,mixed>>,
     *   edges:array<int,array<string,mixed>>,
     *   by_hop:array<int,int>,
     *   by_type:array<string,int>,
     *   depth:int,
     *   direction:string,
     *   truncated:bool
     * }
     */
    public function traverse(string $entityType, int $entityId, int $depth = self::DEFAULT_DEPTH, string $direction = self::BOTH, int $nodeBudget = 600): array
    {
        $depth = max(1, min(self::MAX_DEPTH, $depth));
        $root = $this->node($entityType, $entityId, 0);

        if (! $root) {
            return [
                'root' => null, 'nodes' => [], 'edges' => [], 'by_hop' => [],
                'by_type' => [], 'depth' => $depth, 'direction' => $direction, 'truncated' => false,
            ];
        }

        $this->buildAdjacency();

        $rootKey = $this->key($entityType, $entityId);
        $visited = [$rootKey => 0];
        $nodes = [$rootKey => $root + ['is_root' => true]];
        $edges = [];
        $frontier = [[$entityType, $entityId]];
        $truncated = false;

        for ($hop = 1; $hop <= $depth; $hop++) {
            $next = [];

            foreach ($frontier as [$type, $id]) {
                foreach ($this->adjacency[$this->key($type, $id)] ?? [] as $edge) {
                    if ($direction === self::DOWNSTREAM && ! $edge['outgoing']) {
                        continue;
                    }
                    if ($direction === self::UPSTREAM && $edge['outgoing']) {
                        continue;
                    }

                    $farKey = $this->key($edge['far_type'], $edge['far_id']);

                    // Record the edge once, oriented as stored.
                    $edges[$edge['id']] = [
                        'id' => $edge['id'],
                        'source' => $edge['outgoing'] ? $this->key($type, $id) : $farKey,
                        'target' => $edge['outgoing'] ? $farKey : $this->key($type, $id),
                        'relation' => $edge['relation'],
                        'kind' => $edge['kind'],
                        'label' => $edge['label'],
                        'hop' => $hop,
                    ];

                    if (isset($visited[$farKey])) {
                        continue;
                    }

                    if (count($nodes) >= $nodeBudget) {
                        $truncated = true;
                        continue;
                    }

                    $node = $this->node($edge['far_type'], $edge['far_id'], $hop);
                    if (! $node) {
                        continue;
                    }

                    $visited[$farKey] = $hop;
                    $nodes[$farKey] = $node + ['via' => $edge['relation'], 'from' => $this->key($type, $id)];
                    $next[] = [$edge['far_type'], $edge['far_id']];
                }
            }

            if (! $next) {
                break;
            }
            $frontier = $next;
        }

        // Drop edges whose far end got budget-trimmed, so the graph the client
        // renders has no dangling ends.
        $edges = array_values(array_filter($edges, fn ($e) => isset($nodes[$e['source']]) && isset($nodes[$e['target']])));

        $byHop = [];
        foreach ($nodes as $node) {
            $byHop[$node['hop']] = ($byHop[$node['hop']] ?? 0) + 1;
        }
        ksort($byHop);

        return [
            'root' => $root,
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'by_hop' => $byHop,
            'by_type' => collect($nodes)->groupBy('label_type')->map->count()->all(),
            'depth' => $depth,
            'direction' => $direction,
            'truncated' => $truncated,
        ];
    }

    /**
     * The change-impact tab payload: the traversal plus the consequences a
     * change board actually asks about — critical systems touched, cost exposed,
     * RTO-bearing processes, control gaps, initiatives in flight, and the
     * regulatory dimensions Phase 3 added (residency, concentration).
     */
    public function changeImpact(string $entityType, int $entityId, int $depth = self::DEFAULT_DEPTH, string $direction = self::BOTH): array
    {
        $graph = $this->traverse($entityType, $entityId, $depth, $direction);

        $appIds = $this->idsOfType($graph['nodes'], EaApplication::class);
        $processIds = $this->idsOfType($graph['nodes'], Process::class);
        $capabilityIds = $this->idsOfType($graph['nodes'], Capability::class);
        $techIds = $this->idsOfType($graph['nodes'], TechComponent::class);

        $apps = $appIds ? EaApplication::whereIn('id', $appIds)->get() : collect();
        $processes = $processIds ? Process::whereIn('id', $processIds)->get() : collect();

        // Cost exposed. `annual_cost` (with a currency) is the Phase 3 column;
        // `annual_cost_ngn` is the original naira-only one. Prefer the first and
        // report unknown currencies rather than assuming naira — the same stance
        // FxExposureService takes.
        $fx = new FxExposureService();
        $costNgn = 0.0;
        $unknownCurrency = 0;
        foreach ($apps as $app) {
            $converted = $fx->toNgn(
                $app->annual_cost !== null ? (float) $app->annual_cost : null,
                $app->cost_currency
            );

            if ($converted !== null) {
                $costNgn += $converted;
            } elseif ($app->annual_cost_ngn !== null) {
                $costNgn += (float) $app->annual_cost_ngn;
            } elseif ($app->annual_cost !== null) {
                $unknownCurrency++;
            }
        }

        // Processes whose recovery objective is tight enough that this change
        // needs a window rather than a maintenance slot. I-6 made rto_hours real
        // (sourced from BIA), so this number means something.
        $tightRto = $processes->filter(fn ($p) => $p->rto_hours !== null && $p->rto_hours <= 4)->values();

        $controlGaps = $appIds
            ? ControlMapping::where('component_type', 'application')->whereIn('component_id', $appIds)
                ->where('coverage', 'gap')->count()
            : 0;

        $initiatives = $appIds
            ? Initiative::query()->where(function ($q) use ($appIds) {
                foreach ($appIds as $id) {
                    $q->orWhereJsonContains('linked_applications', $id);
                }
            })->get(['id', 'code', 'name', 'status', 'adm_phase', 'target_end_date'])
            : collect();

        $sealed = 0;
        $sealMap = (new QualitySealService())->mapFor(EaApplication::class, collect($appIds));
        foreach ($sealMap as $seal) {
            if (($seal['state'] ?? null) === 'approved') {
                $sealed++;
            }
        }

        return [
            'graph' => $graph,
            'summary' => [
                'nodes' => count($graph['nodes']) - 1,
                'applications' => count($appIds),
                'critical_applications' => $apps->where('criticality', 'critical')->count(),
                'high_applications' => $apps->where('criticality', 'high')->count(),
                'processes' => count($processIds),
                'tight_rto_processes' => $tightRto->count(),
                'capabilities' => count($capabilityIds),
                'tech_components' => count($techIds),
                'obsolete_tech' => $techIds ? TechComponent::whereIn('id', $techIds)->where('obsolescence_flag', true)->count() : 0,
                'control_gaps' => $controlGaps,
                'initiatives' => $initiatives->count(),
                'annual_cost_ngn' => round($costNgn, 2),
                'unknown_currency_apps' => $unknownCurrency,
                'cross_border_apps' => $apps->filter(fn ($a) => $a->hosting_country && $a->hosting_country !== 'NG')->count(),
                'nigerian_payment_data_apps' => $apps->where('contains_nigerian_payment_data', true)->count(),
                // Evidence quality of the impact statement itself. An impact
                // assessment computed over unsealed records is a guess, and §10
                // makes provenance a release gate.
                'evidence_confidence' => count($appIds) ? round($sealed / count($appIds) * 100, 1) : null,
            ],
            'applications' => $apps->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name,
                'criticality' => $a->criticality, 'lifecycle' => $a->lifecycle,
                'hosting_country' => $a->hosting_country,
            ])->values(),
            'processes' => $processes->map(fn ($p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name,
                'criticality' => $p->criticality, 'rto_hours' => $p->rto_hours,
            ])->values(),
            'tight_rto_processes' => $tightRto->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'rto_hours' => $p->rto_hours,
            ])->values(),
            'initiatives' => $initiatives,
        ];
    }

    /**
     * Shortest path between two entities — "how is this connected to that", the
     * question an architect asks when a dependency looks wrong.
     *
     * @return array<int,array<string,mixed>>|null nodes along the path, or null
     */
    public function pathBetween(string $fromType, int $fromId, string $toType, int $toId, int $maxDepth = self::MAX_DEPTH): ?array
    {
        $this->buildAdjacency();

        $start = $this->key($fromType, $fromId);
        $goal = $this->key($toType, $toId);
        if ($start === $goal) {
            return [];
        }

        $previous = [$start => null];
        $frontier = [[$fromType, $fromId]];

        for ($hop = 1; $hop <= $maxDepth; $hop++) {
            $next = [];
            foreach ($frontier as [$type, $id]) {
                foreach ($this->adjacency[$this->key($type, $id)] ?? [] as $edge) {
                    $farKey = $this->key($edge['far_type'], $edge['far_id']);
                    if (isset($previous[$farKey])) {
                        continue;
                    }
                    $previous[$farKey] = ['from' => $this->key($type, $id), 'relation' => $edge['relation'], 'kind' => $edge['kind']];

                    if ($farKey === $goal) {
                        return $this->unwind($previous, $goal);
                    }
                    $next[] = [$edge['far_type'], $edge['far_id']];
                }
            }
            if (! $next) {
                break;
            }
            $frontier = $next;
        }

        return null;
    }

    private function unwind(array $previous, string $goal): array
    {
        $path = [];
        $cursor = $goal;
        while ($cursor !== null && isset($previous[$cursor])) {
            $step = $previous[$cursor];
            [$type, $id] = $this->unkey($cursor);
            $path[] = [
                'key' => $cursor,
                'entity_type' => $type,
                'id' => $id,
                'name' => $this->node($type, $id, 0)['name'] ?? "#{$id}",
                'via' => $step['relation'] ?? null,
            ];
            $cursor = $step['from'] ?? null;
        }
        [$type, $id] = $this->unkey($cursor ?? '');
        if ($type) {
            $path[] = [
                'key' => $cursor, 'entity_type' => $type, 'id' => $id,
                'name' => $this->node($type, $id, 0)['name'] ?? "#{$id}", 'via' => null,
            ];
        }

        return array_reverse($path);
    }

    /* ===================== adjacency ===================== */

    /**
     * Load every edge in the repository once.
     *
     * At the §10 target (20,000 relationships) this is 8 queries and a few MB of
     * PHP arrays — cheaper by an order of magnitude than the per-node querying
     * it replaces, and the cost is flat in traversal depth.
     */
    private function buildAdjacency(): void
    {
        if ($this->adjacency !== null) {
            return;
        }

        $this->adjacency = [];

        // 1. The generic ArchiMate graph.
        foreach (Relationship::query()->get(['id', 'source_type', 'source_id', 'target_type', 'target_id', 'relation_type']) as $row) {
            $this->link(
                $row->source_type, (int) $row->source_id,
                $row->target_type, (int) $row->target_id,
                $row->relation_type ?: 'association', 'relationship', 'rel-'.$row->id
            );
        }

        // 2. Interfaces — the CBN App. II §1.1(i) connection catalogue. The
        //    interface is materialised as its own node because the regulator's
        //    question is about connections, not just endpoints.
        //    Typed as ArchiMate reads them: the calling component is *assigned*
        //    its interface, and the interface *serves* the component that
        //    consumes it. Typing both ends `flow` would put an illegal edge on
        //    every derived diagram.
        foreach (EaInterface::query()->get(['id', 'name', 'source_app_id', 'target_app_id', 'status']) as $row) {
            if ($row->source_app_id) {
                $this->link(EaApplication::class, (int) $row->source_app_id, EaInterface::class, (int) $row->id,
                    'assignment', 'interface', 'if-s-'.$row->id, $row->name);
            }
            if ($row->target_app_id) {
                $this->link(EaInterface::class, (int) $row->id, EaApplication::class, (int) $row->target_app_id,
                    'serving', 'interface', 'if-t-'.$row->id, $row->name);
            }
        }

        // 3. Application → capability, from the materialised pivot (§10).
        foreach (DB::table('ea_application_capabilities')->get(['id', 'application_id', 'capability_id']) as $row) {
            $this->link(EaApplication::class, (int) $row->application_id, Capability::class, (int) $row->capability_id,
                'realisation', 'capability', 'ac-'.$row->id);
        }

        // 4. Technology → application.
        foreach (TechComponent::query()->get(['id', 'application_ids']) as $row) {
            foreach ((array) ($row->application_ids ?? []) as $appId) {
                $this->link(TechComponent::class, (int) $row->id, EaApplication::class, (int) $appId,
                    'assignment', 'technology', "ta-{$row->id}-{$appId}");
            }
        }

        // 5. Process → application, and process → capability.
        foreach (Process::query()->get(['id', 'linked_applications', 'capability_id', 'parent_id']) as $row) {
            foreach ((array) ($row->linked_applications ?? []) as $appId) {
                $this->link(EaApplication::class, (int) $appId, Process::class, (int) $row->id,
                    'serving', 'process', "pa-{$row->id}-{$appId}");
            }
            if ($row->capability_id) {
                $this->link(Process::class, (int) $row->id, Capability::class, (int) $row->capability_id,
                    'realisation', 'process', 'pc-'.$row->id);
            }
            if ($row->parent_id) {
                $this->link(Process::class, (int) $row->parent_id, Process::class, (int) $row->id,
                    'composition', 'hierarchy', 'pp-'.$row->id);
            }
        }

        // 6. Capability hierarchy.
        foreach (Capability::query()->whereNotNull('parent_id')->get(['id', 'parent_id']) as $row) {
            $this->link(Capability::class, (int) $row->parent_id, Capability::class, (int) $row->id,
                'composition', 'hierarchy', 'cc-'.$row->id);
        }

        // 7. Data flows between logical entities, and each entity's information
        //    domain. Without these the data layer is a set of isolated nodes and
        //    "what data does this change touch" — the NDPA question A2 and the
        //    DPIA screen both ask — cannot be answered by traversal.
        foreach (DataFlow::query()->get(['id', 'name', 'source_entity_id', 'target_entity_id', 'cross_border']) as $row) {
            if ($row->source_entity_id && $row->target_entity_id) {
                // `association`, not `flow`: ArchiMate reserves flow for
                // behaviour elements, and both ends here are data objects.
                $this->link(LogicalEntity::class, (int) $row->source_entity_id, LogicalEntity::class, (int) $row->target_entity_id,
                    'association', $row->cross_border ? 'cross_border_flow' : 'data_flow', 'df-'.$row->id, $row->name);
            }
        }

        foreach (LogicalEntity::query()->whereNotNull('domain_id')->get(['id', 'domain_id']) as $row) {
            $this->link(LogicalEntity::class, (int) $row->id, InfoDomain::class, (int) $row->domain_id,
                'realisation', 'information_domain', 'led-'.$row->id);
        }

        // 8. Application → site / vendor / logical entity. The wedge dimensions:
        //    a change that moves an application moves its residency posture too.
        $appColumns = ['id', 'hosting_site_id', 'dr_site_id', 'vendor_id'];
        foreach (EaApplication::query()->get($appColumns) as $row) {
            // `association` rather than `assignment`: ArchiMate reserves
            // assignment for allocation of behaviour or active structure, and a
            // "runs at this data centre" fact is a location association. Getting
            // this wrong puts an illegal edge on every derived diagram.
            if ($row->hosting_site_id) {
                $this->link(EaApplication::class, (int) $row->id, Site::class, (int) $row->hosting_site_id,
                    'association', 'hosting', 'as-'.$row->id, 'hosted at');
            }
            if ($row->dr_site_id) {
                $this->link(EaApplication::class, (int) $row->id, Site::class, (int) $row->dr_site_id,
                    'association', 'dr', 'ad-'.$row->id, 'DR site');
            }
            if ($row->vendor_id && class_exists(Vendor::class)) {
                $this->link(EaApplication::class, (int) $row->id, Vendor::class, (int) $row->vendor_id,
                    'association', 'vendor', 'av-'.$row->id);
            }
        }
    }

    private function link(string $sourceType, int $sourceId, string $targetType, int $targetId, string $relation, string $kind, string $id, ?string $label = null): void
    {
        if (! $sourceId || ! $targetId) {
            return;
        }

        $sourceKey = $this->key($sourceType, $sourceId);
        $targetKey = $this->key($targetType, $targetId);

        $this->adjacency[$sourceKey][] = [
            'id' => $id, 'outgoing' => true, 'far_type' => $targetType, 'far_id' => $targetId,
            'relation' => $relation, 'kind' => $kind, 'label' => $label ?: $relation,
        ];
        $this->adjacency[$targetKey][] = [
            'id' => $id, 'outgoing' => false, 'far_type' => $sourceType, 'far_id' => $sourceId,
            'relation' => $relation, 'kind' => $kind, 'label' => $label ?: $relation,
        ];
    }

    /* ===================== node packing ===================== */

    private function node(string $type, int $id, int $hop): ?array
    {
        $cacheKey = $this->key($type, $id);

        if (! array_key_exists($cacheKey, $this->nodeCache)) {
            // Tenant-scoped deliberately. The adjacency is built from scoped
            // queries, so an unscoped lookup here would be the only way a
            // traversal could surface another organisation's record — the leak
            // path §10 requires a test for.
            $this->nodeCache[$cacheKey] = class_exists($type) ? $type::query()->find($id) : null;
        }

        $model = $this->nodeCache[$cacheKey];
        if (! $model) {
            return null;
        }

        return array_filter([
            'key' => $cacheKey,
            'id' => $id,
            'entity_type' => $type,
            'label_type' => class_basename($type),
            'archimate_type' => RelationshipValidator::canonicalType($type),
            'name' => $model->name ?? $model->code ?? "#{$id}",
            'code' => $model->code ?? null,
            'criticality' => $model->criticality ?? null,
            'lifecycle' => $model->lifecycle ?? null,
            'hop' => $hop,
        ], fn ($v) => $v !== null);
    }

    private function idsOfType(array $nodes, string $type): array
    {
        return array_values(array_map(
            fn ($n) => (int) $n['id'],
            array_filter($nodes, fn ($n) => $n['entity_type'] === $type && empty($n['is_root']))
        ));
    }

    private function key(string $type, int $id): string
    {
        return $type.'|'.$id;
    }

    private function unkey(string $key): array
    {
        if (! str_contains($key, '|')) {
            return [null, 0];
        }
        [$type, $id] = explode('|', $key, 2);

        return [$type, (int) $id];
    }
}
