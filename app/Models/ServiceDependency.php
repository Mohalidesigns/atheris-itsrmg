<?php

namespace App\Models;

use App\Models\Ea\BusinessService;
use App\Models\Ea\Relationship;

/**
 * @deprecated ATH-EAR-002 WS 2.1 / D1 — the EA relationship graph is canonical.
 *
 * §7.1's single-graph principle: "there is exactly one architecture graph, EA
 * owns it, and every other module reads from it rather than keeping its own
 * copy." A private `service_dependencies` join table was a second copy.
 *
 * A projection over `ea_relationships`. New code should use
 * App\Models\Ea\Relationship, which is validated against ArchiMate 3.2
 * relationship legality by RelationshipValidator — something the legacy table
 * never was.
 */
class ServiceDependency extends Relationship
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('ea.canonical_business_architecture', true)
            ? 'ea_relationships'
            : 'service_dependencies';
    }

    /**
     * The graph holds every architecture relationship, so a caller asking for
     * "service dependencies" must not receive capability-to-application edges
     * as well. Scope to edges that touch a business service.
     */
    public function newQuery()
    {
        $query = parent::newQuery();

        if (config('ea.canonical_business_architecture', true)) {
            $service = BusinessService::class;
            $query->where(fn ($q) => $q->where('source_type', $service)->orWhere('target_type', $service));
        }

        return $query;
    }
}
