<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Process;
use App\Models\Ea\TechComponent;
use App\Models\Ea\Zone;
use App\Models\Ea\ZoneAssignment;

/**
 * ViewpointGenerator — emits ArchiMate 3.2 standard viewpoints as
 * `{nodes, edges}` payloads consumable by the front-end React-Flow viewer.
 *
 * Eight viewpoints are supported out of the box, matching the gap analysis
 * remediation list (§5.8):
 *
 *   layered                  — Strategy / Business / Application / Technology
 *   information_structure    — Information Domains → Logical Entities → Apps
 *   application_cooperation  — Apps + the interfaces between them
 *   application_usage        — Apps + the capabilities they realise
 *   technology               — Tech components grouped by category
 *   implementation_deployment — Apps mapped onto Nodes
 *   goal_realisation         — (when Motivation layer is populated)
 *   risk_security            — Apps with zone assignments + control posture
 */
class ViewpointGenerator
{
    public const VIEWPOINTS = [
        'layered',
        'information_structure',
        'application_cooperation',
        'application_usage',
        'technology',
        'implementation_deployment',
        'goal_realisation',
        'risk_security',
    ];

    public function generate(string $viewpoint): array
    {
        $method = 'view'.str_replace('_', '', ucwords($viewpoint, '_'));
        if (!method_exists($this, $method)) {
            return ['nodes' => [], 'edges' => [], 'viewpoint' => $viewpoint, 'error' => 'unknown viewpoint'];
        }
        $data = $this->$method();
        return $data + ['viewpoint' => $viewpoint, 'generated_at' => now()->toIso8601String()];
    }

    public function viewLayered(): array
    {
        $nodes = []; $edges = [];
        $cols = ['strategy' => 1, 'business' => 2, 'application' => 3, 'technology' => 4];
        $rowCounter = ['strategy' => 0, 'business' => 0, 'application' => 0, 'technology' => 0];

        foreach (Capability::take(20)->get() as $c) {
            $nodes[] = $this->node('cap-'.$c->id, $c->name, 'capability', 'strategy', $rowCounter['strategy']++);
        }
        foreach (Process::take(20)->get() as $p) {
            $nodes[] = $this->node('proc-'.$p->id, $p->name, 'business-process', 'business', $rowCounter['business']++);
        }
        foreach (EaApplication::take(30)->get() as $a) {
            $nodes[] = $this->node('app-'.$a->id, $a->name, 'application-component', 'application', $rowCounter['application']++);
            foreach ($a->capability_ids ?? [] as $cid) {
                $edges[] = $this->edge('app-'.$a->id, 'cap-'.$cid, 'realisation');
            }
        }
        foreach (TechComponent::take(20)->get() as $t) {
            $nodes[] = $this->node('tech-'.$t->id, $t->name, 'node', 'technology', $rowCounter['technology']++);
            foreach ($t->application_ids ?? [] as $aid) {
                $edges[] = $this->edge('tech-'.$t->id, 'app-'.$aid, 'realisation');
            }
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function viewInformationStructure(): array
    {
        $nodes = []; $edges = [];
        $row = 0;
        foreach (InfoDomain::with('entities')->take(10)->get() as $d) {
            $nodes[] = $this->node('domain-'.$d->id, $d->name, 'business-object', 'domain', $row * 4);
            foreach ($d->entities->take(5) as $e) {
                $nodes[] = $this->node('le-'.$e->id, $e->name, 'data-object', 'entities', $row);
                $edges[] = $this->edge('le-'.$e->id, 'domain-'.$d->id, 'aggregation');
                $row++;
            }
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function viewApplicationCooperation(): array
    {
        $nodes = []; $edges = [];
        foreach (EaApplication::take(40)->get() as $a) {
            $nodes[] = $this->node('app-'.$a->id, $a->name, 'application-component', 'apps', $a->id % 10);
        }
        foreach (EaInterface::with('sourceApp', 'targetApp')->take(80)->get() as $i) {
            if ($i->source_app_id && $i->target_app_id) {
                $edges[] = $this->edge('app-'.$i->source_app_id, 'app-'.$i->target_app_id, 'flow', $i->name);
            }
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function viewApplicationUsage(): array
    {
        $nodes = []; $edges = [];
        foreach (Capability::take(20)->get() as $c) {
            $nodes[] = $this->node('cap-'.$c->id, $c->name, 'capability', 'capabilities', $c->id % 10);
        }
        foreach (EaApplication::take(30)->get() as $a) {
            $nodes[] = $this->node('app-'.$a->id, $a->name, 'application-component', 'apps', $a->id % 10);
            foreach ($a->capability_ids ?? [] as $cid) {
                $edges[] = $this->edge('app-'.$a->id, 'cap-'.$cid, 'realisation');
            }
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function viewTechnology(): array
    {
        $nodes = []; $edges = [];
        foreach (TechComponent::take(60)->get() as $t) {
            $nodes[] = $this->node('tech-'.$t->id, $t->name, 'node', $t->category ?: 'platform', $t->id % 10);
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function viewImplementationDeployment(): array
    {
        $nodes = []; $edges = [];
        foreach (TechComponent::take(40)->get() as $t) {
            $nodes[] = $this->node('tech-'.$t->id, $t->name, 'node', 'tech', $t->id % 10);
            foreach ($t->application_ids ?? [] as $aid) {
                $edges[] = $this->edge('tech-'.$t->id, 'app-'.$aid, 'assignment');
            }
        }
        foreach (EaApplication::take(40)->get() as $a) {
            $nodes[] = $this->node('app-'.$a->id, $a->name, 'application-component', 'apps', $a->id % 10);
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function viewGoalRealisation(): array
    {
        $nodes = []; $edges = [];
        foreach (\App\Models\Ea\Goal::take(20)->get() as $g) {
            $nodes[] = $this->node('goal-'.$g->id, $g->name, 'goal', 'goals', $g->id % 10);
        }
        foreach (\App\Models\Ea\Outcome::take(40)->get() as $o) {
            $nodes[] = $this->node('outcome-'.$o->id, $o->name, 'outcome', 'outcomes', $o->id % 10);
            if ($o->goal_id) {
                $edges[] = $this->edge('outcome-'.$o->id, 'goal-'.$o->goal_id, 'realisation');
            }
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function viewRiskSecurity(): array
    {
        $nodes = []; $edges = [];
        foreach (Zone::take(10)->get() as $z) {
            $nodes[] = $this->node('zone-'.$z->id, $z->name, 'node', 'zones', $z->id % 10);
        }
        foreach (ZoneAssignment::with('application')->take(80)->get() as $za) {
            if ($za->application) {
                $nodes[] = $this->node('app-'.$za->application_id, $za->application->name, 'application-component', 'apps', $za->application_id % 10);
                $edges[] = $this->edge('app-'.$za->application_id, 'zone-'.$za->zone_id, 'assignment');
            }
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function node(string $id, string $label, string $type, string $layer, int $row): array
    {
        $colMap = ['strategy' => 0, 'business' => 1, 'application' => 2, 'technology' => 3, 'domain' => 0, 'entities' => 1, 'apps' => 2, 'capabilities' => 0, 'goals' => 0, 'outcomes' => 1, 'tech' => 0, 'zones' => 0, 'platform' => 1];
        $col = $colMap[$layer] ?? 0;
        return [
            'id' => $id,
            'data' => ['label' => $label, 'type' => $type, 'layer' => $layer],
            'position' => ['x' => 60 + $col * 220, 'y' => 60 + $row * 70],
            'type' => 'default',
        ];
    }

    private function edge(string $source, string $target, string $relation, ?string $label = null): array
    {
        return [
            'id' => "e-{$source}-{$target}-{$relation}",
            'source' => $source,
            'target' => $target,
            'data' => ['relation' => $relation, 'label' => $label],
            'label' => $label,
            'animated' => $relation === 'flow',
        ];
    }
}
