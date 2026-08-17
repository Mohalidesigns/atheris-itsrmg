<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\ControlMapping;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Initiative;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\TechComponent;
use Illuminate\Support\Collection;

/**
 * BlastRadiusService — N-hop graph traversal for impact analysis.
 *
 * Given a starting EaApplication (or any EA element), the service performs a
 * BFS over (a) `ea_interfaces` (application-to-application edges) and (b) the
 * generic `ea_relationships` table, returning the affected set grouped by
 * element type.
 *
 * The depth is capped at 4 by default to bound query cost, matching the
 * recommendation in ATH-GAP-EA-001 §5.6.
 */
class BlastRadiusService
{
    public const DEFAULT_DEPTH = 3;
    public const MAX_DEPTH = 4;

    /**
     * @return array{nodes:array<int,array<string,mixed>>, edges:array<int,array<string,mixed>>, by_type:array<string,int>, levels:array<int,int>}
     */
    public function compute(int $applicationId, int $depth = self::DEFAULT_DEPTH): array
    {
        $depth = min($depth, self::MAX_DEPTH);
        $visited = [];           // key "app:{id}" / "tech:{id}" / ...
        $nodes = [];
        $edges = [];
        $levels = [0 => 1];

        $start = EaApplication::find($applicationId);
        if (!$start) {
            return ['nodes' => [], 'edges' => [], 'by_type' => [], 'levels' => []];
        }
        $rootKey = $this->key('app', $start->id);
        $visited[$rootKey] = 0;
        $nodes[] = $this->packApp($start, 0, true);

        $frontier = [['kind' => 'app', 'id' => $start->id, 'level' => 0]];

        for ($d = 1; $d <= $depth; $d++) {
            $nextFrontier = [];
            foreach ($frontier as $f) {
                if ($f['kind'] === 'app') {
                    $appId = $f['id'];

                    // 1) Interface edges
                    $ifaces = EaInterface::query()
                        ->where(function ($q) use ($appId) {
                            $q->where('source_app_id', $appId)->orWhere('target_app_id', $appId);
                        })
                        ->get();
                    foreach ($ifaces as $i) {
                        $other = $i->source_app_id === $appId ? $i->target_app_id : $i->source_app_id;
                        if (!$other) continue;
                        $otherKey = $this->key('app', $other);
                        if (!isset($visited[$otherKey])) {
                            $visited[$otherKey] = $d;
                            $oa = EaApplication::find($other);
                            if ($oa) {
                                $nodes[] = $this->packApp($oa, $d);
                                $nextFrontier[] = ['kind' => 'app', 'id' => $other, 'level' => $d];
                            }
                        }
                        $edges[] = [
                            'source' => $this->key('app', $i->source_app_id),
                            'target' => $this->key('app', $i->target_app_id),
                            'type' => 'interface',
                            'label' => $i->name,
                            'protocol' => $i->protocol,
                            'status' => $i->status,
                            'level' => $d,
                        ];
                    }

                    // 2) Generic relationships (any source/target type)
                    $rels = Relationship::query()
                        ->where(function ($q) use ($appId) {
                            $q->where(function ($r) use ($appId) {
                                $r->where('source_type', EaApplication::class)->where('source_id', $appId);
                            })->orWhere(function ($r) use ($appId) {
                                $r->where('target_type', EaApplication::class)->where('target_id', $appId);
                            });
                        })
                        ->get();
                    foreach ($rels as $r) {
                        // edge
                        $edges[] = [
                            'source' => $this->relKey($r->source_type, $r->source_id),
                            'target' => $this->relKey($r->target_type, $r->target_id),
                            'type' => $r->relation_type,
                            'label' => $r->relation_type,
                            'level' => $d,
                        ];
                        // pull the far end as a node
                        $isOutgoing = $r->source_type === EaApplication::class && $r->source_id === $appId;
                        $farType = $isOutgoing ? $r->target_type : $r->source_type;
                        $farId = $isOutgoing ? $r->target_id : $r->source_id;
                        $farKey = $this->relKey($farType, $farId);
                        if (!isset($visited[$farKey])) {
                            $visited[$farKey] = $d;
                            $packed = $this->packGeneric($farType, $farId, $d);
                            if ($packed) {
                                $nodes[] = $packed;
                                if (class_basename($farType) === 'EaApplication') {
                                    $nextFrontier[] = ['kind' => 'app', 'id' => $farId, 'level' => $d];
                                }
                            }
                        }
                    }
                }
            }
            $levels[$d] = count($nextFrontier);
            if (empty($nextFrontier)) break;
            $frontier = $nextFrontier;
        }

        $byType = collect($nodes)->groupBy('type')->map->count()->all();
        return ['nodes' => $nodes, 'edges' => $edges, 'by_type' => $byType, 'levels' => $levels];
    }

    /**
     * Change-impact summary: for the proposed change to an application,
     * return what else changes.
     */
    public function changeImpact(int $applicationId, int $depth = self::DEFAULT_DEPTH): array
    {
        $radius = $this->compute($applicationId, $depth);
        $impactedApps = collect($radius['nodes'])->where('type', 'application')->pluck('id')->all();

        $criticalCount = EaApplication::whereIn('id', $impactedApps)->where('criticality', 'critical')->count();
        $highCount = EaApplication::whereIn('id', $impactedApps)->where('criticality', 'high')->count();

        $processes = Process::query()
            ->where(function ($q) use ($impactedApps) {
                foreach ($impactedApps as $aid) {
                    $q->orWhereJsonContains('linked_applications', $aid);
                }
            })
            ->get(['id', 'name', 'criticality']);

        $capabilities = Capability::query()
            ->whereIn('id', EaApplication::whereIn('id', $impactedApps)->pluck('capability_ids')
                ->filter()->flatten()->unique()->values()->all())
            ->get(['id', 'name', 'code', 'criticality']);

        $controlGaps = ControlMapping::query()
            ->where('component_type', 'application')
            ->whereIn('component_id', $impactedApps)
            ->where('coverage', 'gap')
            ->count();

        $initiatives = Initiative::query()
            ->where(function ($q) use ($impactedApps) {
                foreach ($impactedApps as $aid) {
                    $q->orWhereJsonContains('linked_applications', $aid);
                }
            })
            ->get(['id', 'name', 'status', 'adm_phase']);

        return [
            'radius' => $radius,
            'summary' => [
                'apps' => count($impactedApps),
                'critical_apps' => $criticalCount,
                'high_apps' => $highCount,
                'processes' => $processes->count(),
                'capabilities' => $capabilities->count(),
                'control_gaps' => $controlGaps,
                'initiatives' => $initiatives->count(),
            ],
            'processes' => $processes,
            'capabilities' => $capabilities,
            'initiatives' => $initiatives,
        ];
    }

    private function packApp(EaApplication $a, int $level, bool $isRoot = false): array
    {
        return [
            'key' => $this->key('app', $a->id),
            'id' => $a->id,
            'type' => 'application',
            'name' => $a->name,
            'code' => $a->code,
            'criticality' => $a->criticality,
            'lifecycle' => $a->lifecycle,
            'level' => $level,
            'is_root' => $isRoot,
        ];
    }

    private function packGeneric(string $type, int $id, int $level): ?array
    {
        $tail = strtolower(class_basename($type));
        $model = $type::find($id);
        if (!$model) return null;
        return [
            'key' => $this->key($tail, $id),
            'id' => $id,
            'type' => $tail,
            'name' => $model->name ?? $model->code ?? ('#'.$id),
            'code' => $model->code ?? null,
            'level' => $level,
        ];
    }

    private function key(string $kind, int $id): string
    {
        return "{$kind}:{$id}";
    }

    private function relKey(string $type, int $id): string
    {
        $tail = strtolower(class_basename($type));
        return $this->key($tail === 'eaapplication' ? 'app' : $tail, $id);
    }
}
