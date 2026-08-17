<?php

namespace App\Services\Ea;

/**
 * RelationshipValidator — checks whether a (sourceType, relationType, targetType)
 * triple is permitted under ArchiMate 3.2.
 *
 * The Open Group's permitted-relationship matrix has roughly 600 entries for
 * the 56-element × 11-relationship cube. The model below encodes the canonical
 * subset used by the EA-Studio metamodel (Strategy / Business / Application /
 * Technology / Motivation / Implementation & Migration) — the 11 relation types
 * are: composition, aggregation, assignment, realisation, used-by, serving,
 * access, influence, triggering, flow, specialisation.
 *
 * Any pair not present in `$matrix` is treated as NOT permitted; the caller is
 * expected to fall back to a `dependsOn` / `association` relation when it
 * requires an unconstrained link.
 */
class RelationshipValidator
{
    public const RELATIONS = [
        'composition', 'aggregation', 'assignment', 'realisation', 'used-by', 'serving',
        'access', 'influence', 'triggering', 'flow', 'specialisation', 'association',
    ];

    /**
     * Canonical ArchiMate 3.2 element types — short names used as `source_type`
     * / `target_type` in ea_relationships. Internal Eloquent classes are mapped
     * onto these via {@see RelationshipValidator::canonicalType()}.
     */
    public const ELEMENTS = [
        // Strategy
        'capability', 'value-stream', 'resource', 'course-of-action',
        // Business
        'business-actor', 'business-role', 'business-process', 'business-function',
        'business-service', 'business-object', 'business-event',
        // Application
        'application-component', 'application-collaboration', 'application-interface',
        'application-service', 'application-function', 'application-process', 'data-object',
        // Technology
        'node', 'device', 'system-software', 'technology-interface', 'technology-service', 'artifact',
        // Physical — a data centre is a Facility, not a Node. §6.3's A5 site
        // model (TIA-942 tier, generator autonomy, grid zone) is physical-layer
        // architecture, and typing it as technology would lose that distinction
        // on export.
        'facility', 'equipment', 'distribution-network', 'material', 'location',
        // Motivation
        'stakeholder', 'driver', 'assessment', 'goal', 'outcome', 'principle',
        'requirement', 'constraint',
        // Implementation & Migration
        'work-package', 'deliverable', 'implementation-event', 'plateau', 'gap',
        // Other
        'grouping', 'junction',
    ];

    /**
     * Matrix is keyed by relation. Value is a list of "sourceType=>targetType[]" pairs.
     */
    protected static array $matrix = [
        'composition' => [
            'capability' => ['capability'],
            'value-stream' => ['value-stream'],
            'business-process' => ['business-process'],
            'business-service' => ['business-service'],
            // A component composes the interfaces it exposes — the standard's
            // own treatment of interfaces as parts of their component, and the
            // shape the CBN connection catalogue takes when drawn.
            'application-component' => ['application-component', 'application-interface'],
            'node' => ['node', 'technology-interface', 'device'],
            'goal' => ['goal'],
            'work-package' => ['work-package'],
            'plateau' => ['plateau'],
        ],
        'aggregation' => [
            'capability' => ['capability', 'business-process'],
            'business-actor' => ['business-actor'],
            'application-component' => ['application-component'],
            'plateau' => ['capability', 'application-component', 'node', 'business-process'],
        ],
        'assignment' => [
            'business-actor' => ['business-process', 'business-function', 'business-role'],
            'business-role' => ['business-process', 'business-function'],
            'application-component' => ['application-service', 'application-function', 'application-interface', 'application-process'],
            'node' => ['system-software', 'artifact', 'application-component', 'technology-service', 'technology-interface'],
            'work-package' => ['deliverable'],
        ],
        'realisation' => [
            'application-component' => ['business-service', 'business-process', 'capability'],
            'application-service' => ['business-service', 'capability'],
            'node' => ['application-component', 'application-service'],
            'work-package' => ['capability', 'application-component', 'plateau'],
            'deliverable' => ['capability', 'application-component', 'plateau'],
            'data-object' => ['business-object'],
            'goal' => ['outcome'],
            'outcome' => ['capability'],
        ],
        'used-by' => [
            'application-service' => ['business-process', 'business-function', 'application-component'],
            'business-service' => ['business-process', 'business-actor', 'business-role'],
            'technology-service' => ['application-component', 'application-service', 'node'],
            'application-interface' => ['business-process', 'application-component', 'application-service'],
            'technology-interface' => ['application-component', 'node'],
        ],
        'serving' => [
            'business-service' => ['business-actor', 'business-role', 'business-process'],
            'application-service' => ['business-process', 'application-component'],
            'technology-service' => ['application-component', 'application-service'],
            'application-component' => ['business-process', 'application-component'],
            // ArchiMate 3.x renamed Used-By to Serving; an interface serving the
            // components that consume it is how an integration diagram reads.
            'application-interface' => ['application-component', 'application-service', 'business-process'],
            'technology-interface' => ['application-component', 'node'],
        ],
        'access' => [
            'business-process' => ['business-object', 'data-object'],
            'application-component' => ['data-object'],
            'application-function' => ['data-object'],
            'application-service' => ['data-object', 'business-object'],
        ],
        'influence' => [
            'driver' => ['goal', 'stakeholder', 'assessment'],
            'assessment' => ['goal', 'driver'],
            'stakeholder' => ['goal', 'driver'],
            'goal' => ['goal', 'principle', 'requirement'],
            'principle' => ['requirement'],
            'requirement' => ['requirement', 'capability', 'application-component'],
        ],
        'triggering' => [
            'business-process' => ['business-process', 'business-event'],
            'business-event' => ['business-process'],
            'application-process' => ['application-process'],
            'work-package' => ['work-package', 'implementation-event'],
        ],
        'flow' => [
            'business-process' => ['business-process', 'business-object'],
            'application-component' => ['application-component', 'data-object'],
            'application-service' => ['application-service'],
        ],
        'specialisation' => [
            'capability' => ['capability'],
            'business-process' => ['business-process'],
            'application-component' => ['application-component'],
            'node' => ['node'],
            'goal' => ['goal'],
            'principle' => ['principle'],
        ],
        'association' => [
            // association is the universal escape hatch — permitted between any two element types.
            '*' => ['*'],
        ],
    ];

    public static function isPermitted(string $source, string $relation, string $target): bool
    {
        $s = self::canonicalType($source);
        $t = self::canonicalType($target);
        $r = strtolower($relation);
        if (!in_array($r, self::RELATIONS, true)) {
            return false;
        }
        $rules = self::$matrix[$r] ?? [];
        if (isset($rules['*'])) {
            return true;
        }
        $allowed = $rules[$s] ?? null;
        if ($allowed === null) {
            return false;
        }
        return in_array('*', $allowed, true) || in_array($t, $allowed, true);
    }

    public static function reasonIfNotPermitted(string $source, string $relation, string $target): ?string
    {
        if (self::isPermitted($source, $relation, $target)) {
            return null;
        }
        $s = self::canonicalType($source);
        $t = self::canonicalType($target);
        $r = strtolower($relation);
        if (!in_array($r, self::RELATIONS, true)) {
            return "Unknown relation type '{$relation}'. Allowed: ".implode(', ', self::RELATIONS).'.';
        }
        return "ArchiMate 3.2 does not permit '{$r}' from {$s} to {$t}.";
    }

    public static function permittedTargetsFor(string $source, string $relation): array
    {
        $s = self::canonicalType($source);
        $r = strtolower($relation);
        $rules = self::$matrix[$r] ?? [];
        if (isset($rules['*'])) {
            return self::ELEMENTS;
        }
        return $rules[$s] ?? [];
    }

    /**
     * Map an Eloquent class name (e.g. "App\\Models\\Ea\\EaApplication") onto the
     * ArchiMate canonical type ("application-component").
     */
    public static function canonicalType(string $typeOrClass): string
    {
        $tail = strtolower(class_basename($typeOrClass));
        return match ($tail) {
            'capability' => 'capability',
            'valuestream' => 'value-stream',
            'eaapplication' => 'application-component',
            'eainterface' => 'application-interface',
            'eaapi' => 'application-service',
            'techcomponent' => 'node',
            'logicalentity', 'infodomain' => 'data-object',
            'process' => 'business-process',
            'goal' => 'goal',
            'driver' => 'driver',
            'stakeholder' => 'stakeholder',
            'outcome' => 'outcome',
            'principle' => 'principle',
            'initiative' => 'work-package',
            'solution' => 'deliverable',
            'plateau' => 'plateau',
            'standard' => 'requirement',
            'pattern' => 'requirement',
            // A security zone is a boundary, not a machine: Grouping, so an
            // export does not draw infrastructure the bank does not own.
            'zone' => 'grouping',
            // §6.3 A5 — a data centre is physical-layer.
            'site' => 'facility',
            'legalentity' => 'business-actor',
            // A vendor is an external party. Modelled as a BusinessActor so the
            // A4 concentration graph survives an ArchiMate export.
            'vendor' => 'business-actor',
            'channel' => 'business-service',
            'rail' => 'technology-service',
            'businessservice' => 'business-service',
            'courseofaction' => 'course-of-action',
            default => str_replace('_', '-', $tail),
        };
    }
}
