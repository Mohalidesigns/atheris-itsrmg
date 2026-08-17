<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use App\Services\Ea\HierarchyIndex;
use Illuminate\Database\Eloquent\Model;

/**
 * One (ancestor, descendant, depth) row of a materialised hierarchy closure.
 *
 * §10: "the current `byParent` recursive render and JSON-column filtering will
 * not hold; add materialised closure tables for hierarchies". Deliberately
 * *not* tenant-scoped at the model level — it is an index, and the entities it
 * indexes carry the tenancy. Reads go through
 * {@see HierarchyIndex}, which scopes by the parent query.
 */
class ClosureEdge extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_closure';

    protected $guarded = [];

    public $timestamps = false;
}
