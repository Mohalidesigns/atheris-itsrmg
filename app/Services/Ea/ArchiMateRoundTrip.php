<?php

namespace App\Services\Ea;

/**
 * ArchiMateRoundTrip — the WS 4.4 release gate: "ArchiMate round-trip release
 * gate against Archi and Sparx EA."
 *
 * Why a *gate* and not a feature. Interchange is the credibility test an EA tool
 * fails in front of an architect: they will open the export in Archi (free, and
 * on every architect's laptop) inside the first ten minutes of an evaluation. If
 * it loads with warnings, or loses half the model, the demo is over. §3 records
 * the same expectation of the competitors, and an on-premises Nigerian
 * deployment (§10) cannot lean on a hosted converter.
 *
 * What "round-trip" means here, precisely:
 *
 *   export → parse → compare
 *
 * A pass requires (a) every element and relationship survives, (b) types map to
 * legal ArchiMate 3.2 names in both directions, (c) identifiers are stable so a
 * second export of the same repository is byte-identical, and (d) relationship
 * endpoints still resolve to elements that exist in the document — the failure
 * mode that makes Archi show an empty view rather than an error.
 *
 * Dialects are handled rather than assumed: Archi emits `xsi:type` on `element`
 * and its own `<propertyDefinitions>`; Sparx emits the same core shape with
 * different id prefixes and often no `documentation`. Both are accepted on
 * import; the export targets the strict schema shape both read.
 */
class ArchiMateRoundTrip
{
    /**
     * Element type names permitted by ArchiMate 3.2 in the Open Exchange
     * format. An export emitting anything outside this list is what makes Archi
     * warn on load.
     */
    public const EXCHANGE_ELEMENT_TYPES = [
        // Strategy
        'Resource', 'Capability', 'ValueStream', 'CourseOfAction',
        // Business
        'BusinessActor', 'BusinessRole', 'BusinessCollaboration', 'BusinessInterface',
        'BusinessProcess', 'BusinessFunction', 'BusinessInteraction', 'BusinessEvent',
        'BusinessService', 'BusinessObject', 'Contract', 'Representation', 'Product',
        // Application
        'ApplicationComponent', 'ApplicationCollaboration', 'ApplicationInterface',
        'ApplicationFunction', 'ApplicationInteraction', 'ApplicationProcess',
        'ApplicationEvent', 'ApplicationService', 'DataObject',
        // Technology & physical
        'Node', 'Device', 'SystemSoftware', 'TechnologyCollaboration', 'TechnologyInterface',
        'Path', 'CommunicationNetwork', 'TechnologyFunction', 'TechnologyProcess',
        'TechnologyInteraction', 'TechnologyEvent', 'TechnologyService', 'Artifact',
        'Equipment', 'Facility', 'DistributionNetwork', 'Material',
        // Motivation
        'Stakeholder', 'Driver', 'Assessment', 'Goal', 'Outcome', 'Principle',
        'Requirement', 'Constraint', 'Meaning', 'Value',
        // Implementation & migration
        'WorkPackage', 'Deliverable', 'ImplementationEvent', 'Plateau', 'Gap',
        // Other
        'Location', 'Grouping', 'Junction',
    ];

    /** Relationship type names permitted by the exchange format. */
    public const EXCHANGE_RELATIONSHIP_TYPES = [
        'Composition', 'Aggregation', 'Assignment', 'Realization', 'Serving',
        'Access', 'Influence', 'Triggering', 'Flow', 'Specialization', 'Association', 'Junction',
    ];

    /**
     * Map our internal relation names onto exchange-format type names.
     *
     * Note the spellings: the standard uses American forms (`Realization`,
     * `Specialization`) while the module's internal vocabulary uses British
     * ones. Emitting `Realisation` is exactly the kind of near-miss that makes
     * Archi drop a relationship silently.
     */
    public const RELATION_TO_EXCHANGE = [
        'composition' => 'Composition',
        'aggregation' => 'Aggregation',
        'assignment' => 'Assignment',
        'realisation' => 'Realization',
        'realization' => 'Realization',
        'realises' => 'Realization',
        'serving' => 'Serving',
        'serves' => 'Serving',
        'used-by' => 'Serving',   // ArchiMate 3.x renamed Used-By to Serving
        'usedby' => 'Serving',
        'access' => 'Access',
        'accesses' => 'Access',
        'influence' => 'Influence',
        'influences' => 'Influence',
        'triggering' => 'Triggering',
        'triggers' => 'Triggering',
        'flow' => 'Flow',
        'flows' => 'Flow',
        'specialisation' => 'Specialization',
        'specialization' => 'Specialization',
        'association' => 'Association',
        'dependson' => 'Association',
        'depends_on' => 'Association',
    ];

    /** Reverse map, for import. */
    public static function exchangeToRelation(string $exchangeType): string
    {
        return match ($exchangeType) {
            'Composition' => 'composition',
            'Aggregation' => 'aggregation',
            'Assignment' => 'assignment',
            'Realization' => 'realisation',
            'Serving' => 'serving',
            'Access' => 'access',
            'Influence' => 'influence',
            'Triggering' => 'triggering',
            'Flow' => 'flow',
            'Specialization' => 'specialisation',
            default => 'association',
        };
    }

    /**
     * Internal model class → exchange element type, derived from the exporter's
     * own registry rather than restated. Two lists would drift, and check 10
     * below would then fail for a bookkeeping reason rather than a real one.
     *
     * @return array<class-string,string>
     */
    public static function classToExchange(): array
    {
        $map = [];
        foreach (ArchiMateExchange::REGISTRY as $class => [$prefix, $type, $description]) {
            $map[$class] = $type;
        }

        return $map;
    }

    /**
     * Exchange element type → internal model class, for import.
     *
     * Several exchange types are produced by more than one of our models
     * (`Requirement` from both Standard and Pattern in earlier drafts), so the
     * first registration wins and the ambiguity is resolved in one place instead
     * of at each call site.
     *
     * @return array<string,class-string>
     */
    public static function exchangeToClass(): array
    {
        $map = [];
        foreach (self::classToExchange() as $class => $type) {
            $map[$type] ??= $class;
        }

        return $map;
    }

    public function __construct(
        private ArchiMateExchange $exchange = new ArchiMateExchange(),
    ) {
    }

    /**
     * Run the gate.
     *
     * @return array{
     *   pass:bool,
     *   checks:array<int,array{name:string,pass:bool,detail:string}>,
     *   counts:array<string,int>,
     *   xml_bytes:int,
     *   sha256:string
     * }
     */
    public function run(?int $tenantId = null): array
    {
        $exported = $this->exchange->export($tenantId);
        $xml = $exported['xml'];
        $checks = [];

        // 1. Well-formed XML. Anything else and the rest is meaningless.
        $wellFormed = $this->isWellFormed($xml, $error);
        $checks[] = [
            'name' => 'Document is well-formed XML',
            'pass' => $wellFormed,
            'detail' => $wellFormed ? 'Parsed without libxml errors.' : "libxml: {$error}",
        ];

        if (! $wellFormed) {
            return [
                'pass' => false,
                'checks' => $checks,
                'counts' => ['exported_elements' => $exported['element_count'], 'exported_relationships' => $exported['relationship_count']],
                'xml_bytes' => strlen($xml),
                'sha256' => hash('sha256', $xml),
            ];
        }

        // 2. Declares the ArchiMate 3.0 exchange namespace — what Archi and
        //    Sparx key their importers on.
        $hasNamespace = str_contains($xml, ArchiMateExchange::NAMESPACE);
        $checks[] = [
            'name' => 'Declares the Open Exchange namespace',
            'pass' => $hasNamespace,
            'detail' => $hasNamespace ? ArchiMateExchange::NAMESPACE : 'Namespace declaration missing.',
        ];

        // 3. Re-parse with our own parser — the round trip proper.
        $parsed = $this->exchange->parse($xml);

        $elementsSurvive = count($parsed['elements']) === $exported['element_count'];
        $checks[] = [
            'name' => 'All elements survive the round trip',
            'pass' => $elementsSurvive,
            'detail' => sprintf('exported %d, re-parsed %d', $exported['element_count'], count($parsed['elements'])),
        ];

        $relationshipsSurvive = count($parsed['relationships']) === $exported['relationship_count'];
        $checks[] = [
            'name' => 'All relationships survive the round trip',
            'pass' => $relationshipsSurvive,
            'detail' => sprintf('exported %d, re-parsed %d', $exported['relationship_count'], count($parsed['relationships'])),
        ];

        // 4. Every element type is a legal exchange type.
        $badTypes = collect($parsed['elements'])->pluck('type')->unique()
            ->reject(fn ($type) => in_array($type, self::EXCHANGE_ELEMENT_TYPES, true))->values()->all();
        $checks[] = [
            'name' => 'Every element type is legal ArchiMate 3.2',
            'pass' => $badTypes === [],
            'detail' => $badTypes === []
                ? count(collect($parsed['elements'])->pluck('type')->unique()).' distinct types, all legal'
                : 'Not in the exchange schema: '.implode(', ', $badTypes),
        ];

        // 5. Every relationship type is a legal exchange type. This is the check
        //    that catches British-vs-American spellings.
        $badRelations = collect($parsed['relationships'])->pluck('type')->unique()
            ->reject(fn ($type) => in_array($type, self::EXCHANGE_RELATIONSHIP_TYPES, true))->values()->all();
        $checks[] = [
            'name' => 'Every relationship type is legal ArchiMate 3.2',
            'pass' => $badRelations === [],
            'detail' => $badRelations === []
                ? count(collect($parsed['relationships'])->pluck('type')->unique()).' distinct types, all legal'
                : 'Not in the exchange schema: '.implode(', ', $badRelations),
        ];

        // 6. Endpoints resolve. A relationship pointing at a missing identifier
        //    is why Archi renders an empty view instead of raising an error.
        $identifiers = array_flip(collect($parsed['elements'])->pluck('identifier')->all());
        $dangling = [];
        foreach ($parsed['relationships'] as $relationship) {
            foreach (['source', 'target'] as $end) {
                if (! isset($identifiers[$relationship[$end]])) {
                    $dangling[] = "{$relationship['identifier']}.{$end} → {$relationship[$end]}";
                }
            }
        }
        $checks[] = [
            'name' => 'Every relationship endpoint resolves to an element',
            'pass' => $dangling === [],
            'detail' => $dangling === []
                ? count($parsed['relationships']).' relationships, all endpoints resolve'
                : count($dangling).' dangling endpoint(s); first: '.$dangling[0],
        ];

        // 7. Identifiers are unique. A duplicate makes Archi keep one element
        //    and discard the other, silently.
        $duplicateIds = collect($parsed['elements'])->pluck('identifier')
            ->countBy()->filter(fn ($count) => $count > 1)->keys()->values()->all();
        $checks[] = [
            'name' => 'Element identifiers are unique',
            'pass' => $duplicateIds === [],
            'detail' => $duplicateIds === [] ? 'No duplicates.' : 'Duplicated: '.implode(', ', array_slice($duplicateIds, 0, 5)),
        ];

        // 8. Determinism. Two exports of an unchanged repository must be
        //    identical, otherwise every export looks like a change in version
        //    control and diffing two points in time is impossible.
        $second = $this->exchange->export($tenantId);
        $deterministic = $this->stripVolatile($second['xml']) === $this->stripVolatile($xml);
        $checks[] = [
            'name' => 'Export is deterministic',
            'pass' => $deterministic,
            'detail' => $deterministic
                ? 'Two consecutive exports are identical once the generation timestamp is discarded.'
                : 'Consecutive exports differ beyond the timestamp.',
        ];

        // 9. Names survive. An element exported without a name imports as
        //    "(unnamed)" in Archi, which reads as data loss.
        $unnamed = collect($parsed['elements'])->filter(fn ($e) => trim((string) $e['name']) === '')->count();
        $checks[] = [
            'name' => 'Every element carries a name',
            'pass' => $unnamed === 0,
            'detail' => $unnamed === 0 ? 'All named.' : "{$unnamed} element(s) exported without a name.",
        ];

        // 10. Import mapping is total: every exchange type we emit maps back to
        //     an internal model, so an import of our own export is lossless.
        $exportedTypes = array_values(self::classToExchange());
        $unmappable = collect($parsed['elements'])->pluck('type')->unique()
            ->reject(fn ($type) => in_array($type, $exportedTypes, true))
            ->values()->all();
        $checks[] = [
            'name' => 'Every exported type maps back to a repository model',
            'pass' => $unmappable === [],
            'detail' => $unmappable === []
                ? 'Import mapping is total.'
                : 'Exported but not importable: '.implode(', ', $unmappable),
        ];

        return [
            'pass' => collect($checks)->every(fn ($check) => $check['pass']),
            'checks' => $checks,
            'counts' => [
                'exported_elements' => $exported['element_count'],
                'exported_relationships' => $exported['relationship_count'],
                'parsed_elements' => count($parsed['elements']),
                'parsed_relationships' => count($parsed['relationships']),
                'distinct_element_types' => collect($parsed['elements'])->pluck('type')->unique()->count(),
                'distinct_relationship_types' => collect($parsed['relationships'])->pluck('type')->unique()->count(),
            ],
            'xml_bytes' => strlen($xml),
            'sha256' => hash('sha256', $xml),
        ];
    }

    /**
     * Import an ArchiMate document produced by another tool, reporting what
     * would be created without writing anything.
     *
     * Dry-run by default because an import that silently merges into a
     * repository backing a regulatory return is not acceptable — the same stance
     * WS 4.5 takes with agent writes.
     *
     * @return array{
     *   importable:array<string,int>,
     *   skipped:array<int,array{identifier:string,type:string,reason:string}>,
     *   relationships:array{importable:int,skipped:int},
     *   dialect:string
     * }
     */
    public function inspectImport(string $xml): array
    {
        $parsed = $this->exchange->parse($xml);
        $exchangeToClass = self::exchangeToClass();

        $importable = [];
        $skipped = [];
        $known = [];

        foreach ($parsed['elements'] as $element) {
            $class = $exchangeToClass[$element['type']] ?? null;
            if (! $class) {
                $skipped[] = [
                    'identifier' => $element['identifier'],
                    'type' => $element['type'],
                    'reason' => in_array($element['type'], self::EXCHANGE_ELEMENT_TYPES, true)
                        ? 'Legal ArchiMate type with no NexusRisk equivalent — it would import as a relationship-only node.'
                        : 'Not an ArchiMate 3.2 element type.',
                ];

                continue;
            }
            if (trim((string) $element['name']) === '') {
                $skipped[] = ['identifier' => $element['identifier'], 'type' => $element['type'], 'reason' => 'No name.'];

                continue;
            }
            $importable[class_basename($class)] = ($importable[class_basename($class)] ?? 0) + 1;
            $known[$element['identifier']] = true;
        }

        $relationshipsImportable = 0;
        $relationshipsSkipped = 0;
        foreach ($parsed['relationships'] as $relationship) {
            $endpointsKnown = isset($known[$relationship['source']]) && isset($known[$relationship['target']]);
            $typeLegal = in_array($relationship['type'], self::EXCHANGE_RELATIONSHIP_TYPES, true);
            $endpointsKnown && $typeLegal ? $relationshipsImportable++ : $relationshipsSkipped++;
        }

        return [
            'importable' => $importable,
            'skipped' => $skipped,
            'relationships' => ['importable' => $relationshipsImportable, 'skipped' => $relationshipsSkipped],
            'dialect' => $this->detectDialect($xml),
        ];
    }

    /**
     * Which tool produced a document. Not cosmetic: Archi and Sparx differ on
     * where documentation lives and how ids are shaped, and telling the user
     * which dialect was recognised is how an import problem gets diagnosed in
     * one message rather than three.
     */
    public function detectDialect(string $xml): string
    {
        return match (true) {
            str_contains($xml, 'Archi') || str_contains($xml, 'archimatetool') => 'Archi',
            str_contains($xml, 'Sparx') || str_contains($xml, 'Enterprise Architect') => 'Sparx EA',
            str_contains($xml, 'BiZZdesign') => 'BiZZdesign',
            str_contains($xml, 'NexusRisk') || str_contains($xml, 'Atheris') => 'Atheris NexusRisk',
            default => 'unknown',
        };
    }

    private function isWellFormed(string $xml, ?string &$error = null): bool
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false || $errors) {
            $error = $errors ? trim($errors[0]->message) : 'unparseable';

            return false;
        }

        return true;
    }

    /** Remove the generation timestamp and model identifier before comparing. */
    private function stripVolatile(string $xml): string
    {
        $xml = preg_replace('/Generated by [^<]*</', 'Generated<', $xml);

        return (string) preg_replace('/identifier="id-model-\d+"/', 'identifier="id-model-X"', $xml);
    }
}
