<?php

namespace App\Services\Finance;

use App\Models\Finance\Category;

/** TBM cost-pool lookups shared by the allocation, showback, TCO and cloud-reconciliation services (plan P6-6, P6-15). */
final class CostPools
{
    public const CLOUD = 'CP-CLD';

    public const LABOUR = 'CP-LAB';

    /**
     * @return array{root:array<int,?int>,pools:array<int,array{id:int,code:string,name:string}>,by_code:array<string,int>}
     *                                                                                                                      root maps every category id to the id of its cost pool (itself, or the nearest cost-pool ancestor), null when it hangs under a tower
     */
    public static function map(int $orgId): array
    {
        $cats = Category::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'code', 'name', 'kind', 'parent_id'])->keyBy('id');
        $root = [];
        $pools = [];
        foreach ($cats as $c) {
            $node = $c;
            $hops = 0;
            $found = null;
            while ($node && $hops++ < 6) {
                if ($node->kind === 'cost_pool') {
                    $found = (int) $node->id;
                    break;
                }
                $node = $node->parent_id ? ($cats[$node->parent_id] ?? null) : null;
            }
            $root[(int) $c->id] = $found;
            $c->kind === 'cost_pool' && $pools[(int) $c->id] = ['id' => (int) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name];
        }

        return ['root' => $root, 'pools' => $pools, 'by_code' => collect($pools)->mapWithKeys(fn ($p) => [$p['code'] => $p['id']])->all()];
    }

    /** Ids of every category in the tree of the named pool (the pool and its sub-categories). @return list<int> */
    public static function treeIds(int $orgId, string $poolCode): array
    {
        $m = self::map($orgId);
        $id = $m['by_code'][$poolCode] ?? null;

        return $id === null ? [] : array_keys(array_filter($m['root'], fn ($r) => $r === $id));
    }
}
