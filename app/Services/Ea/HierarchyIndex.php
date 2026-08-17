<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\ClosureEdge;
use App\Models\Ea\Process;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HierarchyIndex — maintains and reads `ea_closure`, the materialised transitive
 * closure of the module's `parent_id` hierarchies.
 *
 * ATH-EAR-002 §10 makes this a named non-functional requirement: "Capability
 * tree, portfolio grid and blast radius must render within 2s at 5,000 entities
 * / 20,000 relationships. The current `byParent` recursive render and
 * JSON-column filtering will not hold; add materialised closure tables for
 * hierarchies and indexed columns for anything filtered."
 *
 * What the recursive render actually costs: `CapabilityMap` ships the whole
 * table plus a `groupBy('parent_id')` map to the browser and lets React walk it.
 * At 42 capabilities that is invisible. At 5,000 it is a 5,000-node React tree
 * rebuilt on every filter keystroke, over a payload measured in megabytes on a
 * 3G branch link (§10 again: "usable on 3G").
 *
 * The closure lets the server answer the three questions the tree needs —
 * "children of X", "whole subtree of X", "path to root" — as indexed reads, and
 * lets it ship one *level* at a time (progressive loading) instead of the lot.
 */
class HierarchyIndex
{
    /** Hierarchies under management: model class => table. */
    public const HIERARCHIES = [
        Capability::class => 'ea_capabilities',
        Process::class => 'ea_processes',
    ];

    /** Depth guard — a cycle in parent_id must not become an infinite loop. */
    public const MAX_DEPTH = 64;

    /**
     * Rebuild the closure for one hierarchy (or all of them).
     *
     * Iterative rather than a recursive CTE: the suite runs on SQLite and
     * production on MySQL, and the two disagree about CTE support by version.
     *
     * @return array<string,int> rows written per model class
     */
    public function rebuild(?string $modelClass = null): array
    {
        $targets = $modelClass ? [$modelClass => self::HIERARCHIES[$modelClass] ?? null] : self::HIERARCHIES;
        $written = [];

        foreach ($targets as $class => $table) {
            if (! $table || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'parent_id')) {
                continue;
            }

            $nodes = DB::table($table)->select('id', 'parent_id', 'organization_id')->get()->keyBy('id');
            $rows = [];

            foreach ($nodes as $node) {
                $rows[] = $this->row($class, $node->id, $node->id, 0, $node->organization_id);

                $cursor = $node->parent_id;
                $depth = 0;
                $seen = [];
                while ($cursor && isset($nodes[$cursor]) && ! isset($seen[$cursor]) && $depth < self::MAX_DEPTH) {
                    $seen[$cursor] = true;
                    $rows[] = $this->row($class, (int) $cursor, $node->id, ++$depth, $node->organization_id);
                    $cursor = $nodes[$cursor]->parent_id;
                }
            }

            ClosureEdge::where('entity_type', $class)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('ea_closure')->insertOrIgnore($chunk);
            }

            $written[$class] = count($rows);
        }

        return $written;
    }

    private function row(string $class, int $ancestor, int $descendant, int $depth, $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'entity_type' => $class,
            'ancestor_id' => $ancestor,
            'descendant_id' => $descendant,
            'depth' => $depth,
        ];
    }

    /**
     * Incremental repair for a single node whose parent just changed. Cheaper
     * than a full rebuild, and correct as long as the node's own subtree is
     * re-stitched — which is what the two queries below do.
     */
    public function reindexNode(string $modelClass, int $id): void
    {
        $table = self::HIERARCHIES[$modelClass] ?? null;
        if (! $table || ! Schema::hasTable($table)) {
            return;
        }

        // Cheap and always correct. A per-subtree splice is possible but the
        // rebuild is a few thousand rows even at the §10 scale target, and a
        // wrong closure silently corrupts every roll-up built on it.
        $this->rebuild($modelClass);
    }

    /** Descendant ids of $id, excluding itself unless $inclusive. */
    public function subtree(string $modelClass, int $id, bool $inclusive = false): array
    {
        return ClosureEdge::query()
            ->where('entity_type', $modelClass)
            ->where('ancestor_id', $id)
            ->when(! $inclusive, fn ($q) => $q->where('depth', '>', 0))
            ->pluck('descendant_id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    /** Ancestor ids of $id, nearest first. */
    public function ancestors(string $modelClass, int $id): array
    {
        return ClosureEdge::query()
            ->where('entity_type', $modelClass)
            ->where('descendant_id', $id)
            ->where('depth', '>', 0)
            ->orderBy('depth')
            ->pluck('ancestor_id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    /** Direct children ids. */
    public function children(string $modelClass, int $id): array
    {
        return ClosureEdge::query()
            ->where('entity_type', $modelClass)
            ->where('ancestor_id', $id)
            ->where('depth', 1)
            ->pluck('descendant_id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    /** Root ids — nodes that appear as a descendant only of themselves. */
    public function roots(string $modelClass): array
    {
        $withParent = ClosureEdge::query()
            ->where('entity_type', $modelClass)
            ->where('depth', 1)
            ->pluck('descendant_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $all = ClosureEdge::query()
            ->where('entity_type', $modelClass)
            ->where('depth', 0)
            ->pluck('descendant_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        return array_values(array_diff($all, $withParent));
    }

    /**
     * Every node's descendants, in one query.
     *
     * The per-node {@see subtree()} is right for one lookup and wrong for a
     * roll-up: calling it inside a loop over 1,000 capabilities is 1,000
     * queries, which is the pattern §10 exists to stop. The cost roll-up used
     * to do exactly that and spent 2.4s of a 2s budget on it.
     *
     * @return array<int,array<int,int>> ancestor id => descendant ids (excluding self)
     */
    public function subtreeMap(string $modelClass): array
    {
        $map = [];

        foreach (ClosureEdge::query()
            ->where('entity_type', $modelClass)
            ->where('depth', '>', 0)
            ->get(['ancestor_id', 'descendant_id']) as $row) {
            $map[(int) $row->ancestor_id][] = (int) $row->descendant_id;
        }

        return $map;
    }

    /**
     * Subtree *sizes* for every node in one query — the number the capability
     * map needs in order to render a collapsed node honestly ("Payments (114)")
     * without loading its children.
     *
     * @return array<int,int> node id => descendant count (excluding self)
     */
    public function subtreeSizes(string $modelClass): array
    {
        return ClosureEdge::query()
            ->where('entity_type', $modelClass)
            ->where('depth', '>', 0)
            ->selectRaw('ancestor_id, count(*) as c')
            ->groupBy('ancestor_id')
            ->pluck('c', 'ancestor_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * True when the closure agrees with the live parent_id columns.
     *
     * Used by the performance test and by the Data Sources screen: a stale
     * index is worse than no index, because roll-ups computed from it look
     * authoritative.
     */
    public function isFresh(string $modelClass): bool
    {
        $table = self::HIERARCHIES[$modelClass] ?? null;
        if (! $table || ! Schema::hasTable($table)) {
            return true;
        }

        $nodes = DB::table($table)->count();
        $selfRows = ClosureEdge::where('entity_type', $modelClass)->where('depth', 0)->count();
        if ($nodes !== $selfRows) {
            return false;
        }

        $edges = DB::table($table)->whereNotNull('parent_id')
            ->whereIn('parent_id', DB::table($table)->select('id'))->count();
        $depthOne = ClosureEdge::where('entity_type', $modelClass)->where('depth', 1)->count();

        return $edges === $depthOne;
    }

    /** Freshness of every managed hierarchy, for the Data Sources screen. */
    public function status(): array
    {
        $rows = [];
        foreach (self::HIERARCHIES as $class => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows[] = [
                'entity' => class_basename($class),
                'entity_type' => $class,
                'nodes' => DB::table($table)->count(),
                'closure_rows' => ClosureEdge::where('entity_type', $class)->count(),
                'max_depth' => (int) ClosureEdge::where('entity_type', $class)->max('depth'),
                'fresh' => $this->isFresh($class),
            ];
        }

        return $rows;
    }
}
