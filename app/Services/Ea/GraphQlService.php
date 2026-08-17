<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\DraftChange;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Plateau;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\TechComponent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * GraphQlService — the WS 4.5 GraphQL API over the EA repository.
 *
 * **Scope statement, stated plainly.** This is a hand-written executor for a
 * documented *subset* of GraphQL, not a spec-complete server. It supports named
 * queries and mutations, field selection, nested selection through defined
 * relations, arguments with scalar and list values, aliases, and variables. It
 * does **not** support fragments, directives, unions, interfaces,
 * introspection beyond the `__schema` summary below, or subscriptions.
 *
 * Why not a library: §10 requires the module to "install and run air-gapped
 * inside a Nigerian data centre with no outbound internet", and the platform
 * carries seven composer dependencies in total. Adding a GraphQL server plus its
 * transitive tree to support read access to eight entity types is the wrong
 * trade. The subset is enough for the integration cases the spec names — an
 * agent, a CMDB sync, a BI extract — and the limits are declared rather than
 * discovered.
 *
 * Mutations do not mutate. Every mutation returns a {@see DraftChange} for
 * review, which is the "draft-and-approve" half of WS 4.5. A caller that wants
 * an immediate write uses the authenticated UI, where a human is present.
 */
class GraphQlService
{
    /**
     * The schema. Each type declares queryable fields, filterable arguments and
     * traversable relations. Field lists are allow-lists: a query for a field not
     * named here is an error, not a silent null, so a caller cannot probe for
     * columns (tenancy, seal internals) the API does not expose.
     */
    public const TYPES = [
        'applications' => [
            'model' => EaApplication::class,
            'type' => 'Application',
            'fields' => [
                'id', 'code', 'name', 'description', 'criticality', 'lifecycle', 'time_score',
                'six_r_score', 'business_fit', 'technical_fit', 'annual_cost', 'cost_currency',
                'annual_cost_ngn', 'tco_annual_ngn', 'user_count', 'owner_role',
                'hosting_country', 'dr_country', 'hosting_model', 'contains_nigerian_payment_data',
                'transfer_basis', 'contract_end_date', 'licence_model',
            ],
            'args' => ['id', 'code', 'criticality', 'lifecycle', 'time_score', 'hosting_country', 'limit', 'offset'],
            'relations' => [
                'capabilities' => 'capabilities',
                'interfaces' => 'interfaces',
                'technologies' => 'technologies',
                'quality_seal' => 'seal',
                'impact' => 'impact',
                'cost' => 'cost',
            ],
        ],
        'capabilities' => [
            'model' => Capability::class,
            'type' => 'Capability',
            'fields' => ['id', 'code', 'name', 'description', 'level', 'parent_id', 'criticality', 'source', 'maturity'],
            'args' => ['id', 'code', 'level', 'parent_id', 'criticality', 'limit', 'offset'],
            'relations' => [
                'children' => 'children',
                'applications' => 'capabilityApplications',
                'quality_seal' => 'seal',
                'cost' => 'capabilityCost',
            ],
        ],
        'technologies' => [
            'model' => TechComponent::class,
            'type' => 'TechnologyComponent',
            'fields' => ['id', 'code', 'name', 'category', 'vendor', 'version', 'radar_status', 'eol_date', 'eos_date', 'obsolescence_flag', 'tech_debt_score'],
            'args' => ['id', 'code', 'radar_status', 'obsolescence_flag', 'limit', 'offset'],
            'relations' => ['quality_seal' => 'seal'],
        ],
        'interfaces' => [
            'model' => EaInterface::class,
            'type' => 'Interface',
            'fields' => ['id', 'code', 'name', 'source_app_id', 'target_app_id', 'protocol', 'pattern', 'classification', 'pii_carrying', 'status', 'objective', 'counterparty_type', 'review_cadence', 'last_reviewed_at'],
            'args' => ['id', 'code', 'status', 'classification', 'counterparty_type', 'limit', 'offset'],
            'relations' => ['quality_seal' => 'seal'],
        ],
        'processes' => [
            'model' => Process::class,
            'type' => 'BusinessProcess',
            'fields' => ['id', 'code', 'name', 'level', 'parent_id', 'capability_id', 'criticality', 'rto_hours', 'rpo_hours'],
            'args' => ['id', 'code', 'level', 'criticality', 'limit', 'offset'],
            'relations' => ['children' => 'children'],
        ],
        'plateaux' => [
            'model' => Plateau::class,
            'type' => 'Plateau',
            'fields' => ['id', 'code', 'name', 'plateau_type', 'effective_from', 'effective_to', 'description'],
            'args' => ['id', 'code', 'plateau_type', 'limit', 'offset'],
            'relations' => [],
        ],
        'relationships' => [
            'model' => Relationship::class,
            'type' => 'Relationship',
            'fields' => ['id', 'source_type', 'source_id', 'target_type', 'target_id', 'relation_type'],
            'args' => ['id', 'source_type', 'source_id', 'target_type', 'target_id', 'relation_type', 'limit', 'offset'],
            'relations' => [],
        ],
    ];

    /** Root queries that are not entity lists. */
    public const ROOT_QUERIES = [
        'scoreCard' => 'Architecture completeness score card (B5).',
        'costModel' => 'Portfolio cost, TCO and technical-debt roll-up (B17).',
        'plateauDiff' => 'Delta between two plateaux — args: from, to (B15).',
        'impact' => 'n-hop change impact — args: entityType, entityId, depth, direction (B14).',
        'drafts' => 'Pending scoped writes awaiting approval.',
        'roundTrip' => 'ArchiMate Open Exchange round-trip gate result.',
        '__schema' => 'This schema summary.',
    ];

    /** Mutations, all of which produce a draft rather than a write. */
    public const MUTATIONS = [
        'proposeCreate' => 'Propose a new entity — args: entityType, input.',
        'proposeUpdate' => 'Propose a change — args: entityType, id, input.',
        'proposeDelete' => 'Propose a deletion — args: entityType, id.',
    ];

    public const MAX_LIMIT = 500;

    public const DEFAULT_LIMIT = 50;

    public const MAX_DEPTH = 4;

    public function __construct(
        private DraftChangeService $drafts = new DraftChangeService,
    ) {}

    /**
     * Execute a document.
     *
     * @return array{data?:array<string,mixed>, errors?:array<int,array{message:string}>}
     */
    public function execute(string $query, array $variables = []): array
    {
        try {
            $document = (new GraphQlParser)->parse($query, $variables);
        } catch (\Throwable $e) {
            return ['errors' => [['message' => 'Syntax error: '.$e->getMessage()]]];
        }

        $data = [];
        $errors = [];

        foreach ($document['selections'] as $selection) {
            $key = $selection['alias'] ?: $selection['name'];
            try {
                $data[$key] = $document['operation'] === 'mutation'
                    ? $this->resolveMutation($selection)
                    : $this->resolveQuery($selection);
            } catch (\Throwable $e) {
                $errors[] = ['message' => $e->getMessage(), 'path' => [$key]];
                $data[$key] = null;
            }
        }

        return $errors ? ['data' => $data, 'errors' => $errors] : ['data' => $data];
    }

    /* ===================== queries ===================== */

    private function resolveQuery(array $selection): mixed
    {
        $name = $selection['name'];

        if (isset(self::TYPES[$name])) {
            return $this->resolveEntityList($name, $selection);
        }

        return match ($name) {
            '__schema' => $this->schema(),
            'scoreCard' => (new ScoreCardService)->compute(),
            'costModel' => (new CostModelService)->portfolio(),
            'plateauDiff' => $this->resolvePlateauDiff($selection['args']),
            'impact' => $this->resolveImpact($selection['args']),
            'drafts' => (new DraftChangeService)->queue(),
            'roundTrip' => (new ArchiMateRoundTrip)->run(),
            default => throw new \InvalidArgumentException(
                "Unknown query '{$name}'. Available: ".
                implode(', ', array_merge(array_keys(self::TYPES), array_keys(self::ROOT_QUERIES))).'.'
            ),
        };
    }

    private function resolveEntityList(string $name, array $selection): array
    {
        $definition = self::TYPES[$name];
        $this->assertFields($name, $selection);

        $query = $definition['model']::query();

        $limit = self::DEFAULT_LIMIT;
        $offset = 0;

        foreach ($selection['args'] as $argument => $value) {
            if (! in_array($argument, $definition['args'], true)) {
                throw new \InvalidArgumentException(
                    "'{$argument}' is not an argument of {$name}. Available: ".implode(', ', $definition['args']).'.'
                );
            }

            if ($argument === 'limit') {
                $limit = min(self::MAX_LIMIT, max(1, (int) $value));

                continue;
            }
            if ($argument === 'offset') {
                $offset = max(0, (int) $value);

                continue;
            }

            is_array($value) ? $query->whereIn($argument, $value) : $query->where($argument, $value);
        }

        $rows = $query->orderBy('id')->offset($offset)->limit($limit)->get();
        $scalarFields = $this->scalarFields($name, $selection);

        return $rows->map(function ($row) use ($scalarFields, $selection, $definition) {
            $out = [];
            foreach ($scalarFields as $field) {
                $value = $row->{$field};
                $out[$field] = $value instanceof \DateTimeInterface ? $value->toDateString() : $value;
            }
            foreach ($selection['selections'] as $child) {
                if (! isset($definition['relations'][$child['name']])) {
                    continue;
                }
                $out[$child['alias'] ?: $child['name']] = $this->resolveRelation(
                    $definition['relations'][$child['name']], $row, $child
                );
            }

            return $out;
        })->values()->all();
    }

    private function resolveRelation(string $resolver, $parent, array $selection): mixed
    {
        return match ($resolver) {
            'capabilities' => Capability::whereIn('id',
                (new CapabilityLinkIndex)->capabilitiesFor([$parent->id])
            )->get(['id', 'code', 'name', 'level'])->all(),

            'capabilityApplications' => EaApplication::whereIn('id',
                (new CapabilityLinkIndex)->applicationsFor($parent->id, true)
            )->get(['id', 'code', 'name', 'criticality', 'lifecycle'])->all(),

            'interfaces' => EaInterface::query()
                ->where('source_app_id', $parent->id)->orWhere('target_app_id', $parent->id)
                ->get(['id', 'code', 'name', 'protocol', 'status'])->all(),

            'technologies' => TechComponent::query()
                ->whereJsonContains('application_ids', $parent->id)
                ->get(['id', 'code', 'name', 'radar_status', 'obsolescence_flag'])->all(),

            'children' => $parent->children()->get(['id', 'code', 'name'])->all(),

            'seal' => (new QualitySealService)->mapFor($parent::class, [$parent->id])[$parent->id] ?? null,

            'impact' => (new ImpactAnalyser)->changeImpact(
                $parent::class, $parent->id,
                min(self::MAX_DEPTH, max(1, (int) ($selection['args']['depth'] ?? 2)))
            )['summary'],

            'cost' => (new CostModelService)->tco($parent) + [
                'technical_debt' => (new CostModelService)->technicalDebt($parent),
            ],

            'capabilityCost' => collect((new CostModelService)->costPerCapability()['capabilities'])
                ->firstWhere('id', $parent->id),

            default => null,
        };
    }

    private function resolvePlateauDiff(array $args): array
    {
        $from = Plateau::find($args['from'] ?? null);
        $to = Plateau::find($args['to'] ?? null);

        if (! $from || ! $to) {
            throw new \InvalidArgumentException('plateauDiff needs `from` and `to` plateau ids that exist.');
        }

        return (new PlateauDiffService)->diff($from, $to);
    }

    private function resolveImpact(array $args): array
    {
        $entityType = (string) ($args['entityType'] ?? EaApplication::class);

        // Accept the short name an integrator will actually type.
        if (! class_exists($entityType)) {
            $entityType = 'App\\Models\\Ea\\'.$entityType;
        }

        if (! isset(ImpactAnalyser::START_TYPES[$entityType])) {
            throw new \InvalidArgumentException(
                'impact needs an entityType from: '.
                implode(', ', array_map('class_basename', array_keys(ImpactAnalyser::START_TYPES))).'.'
            );
        }

        return (new ImpactAnalyser)->changeImpact(
            $entityType,
            (int) ($args['entityId'] ?? 0),
            (int) ($args['depth'] ?? 2),
            (string) ($args['direction'] ?? ImpactAnalyser::BOTH)
        );
    }

    /* ===================== mutations ===================== */

    private function resolveMutation(array $selection): array
    {
        // A read token cannot propose. The approval gate is not a substitute for
        // authorisation — it is the second half of it.
        //
        // Fail closed: no identity is refused as firmly as a wrong one. The
        // route sits inside the `auth` group so an HTTP caller is always
        // authenticated, but this service is also reachable from console and
        // job context, and `Auth::check() && ...` would have waved those
        // through unauthorised. Queries stay open to any `view ea` holder,
        // which is why POST /ea/graphql carries no route-level write grant —
        // see EaAuthorizationTest::GATED_IN_SERVICE.
        if (! Auth::check() || ! Gate::allows('create', EaApplication::class)) {
            throw new \RuntimeException('Proposing a change needs the `create ea` permission.');
        }

        $args = $selection['args'];
        $entityType = (string) ($args['entityType'] ?? '');
        if (! class_exists($entityType)) {
            $entityType = 'App\\Models\\Ea\\'.$entityType;
        }

        $draft = match ($selection['name']) {
            'proposeCreate' => $this->drafts->propose(
                $entityType, 'create', (array) ($args['input'] ?? []), null, 'graphql', $args['agent'] ?? null
            ),
            'proposeUpdate' => $this->drafts->propose(
                $entityType, 'update', (array) ($args['input'] ?? []), (int) ($args['id'] ?? 0), 'graphql', $args['agent'] ?? null
            ),
            'proposeDelete' => $this->drafts->propose(
                $entityType, 'delete', [], (int) ($args['id'] ?? 0), 'graphql', $args['agent'] ?? null
            ),
            default => throw new \InvalidArgumentException(
                "Unknown mutation '{$selection['name']}'. Available: ".implode(', ', array_keys(self::MUTATIONS)).'.'
            ),
        };

        return [
            'reference' => $draft->reference,
            'status' => $draft->status,
            'operation' => $draft->operation,
            'entity_type' => $draft->entity_type,
            'entity_id' => $draft->entity_id,
            'validation' => $draft->validation_json,
            // Said explicitly in the response so an integrator cannot mistake a
            // 200 for a write having happened.
            'applied' => false,
            'message' => 'Recorded as a draft for human approval; nothing has been written yet.',
        ];
    }

    /* ===================== schema ===================== */

    private function assertFields(string $name, array $selection): void
    {
        $definition = self::TYPES[$name];
        foreach ($selection['selections'] as $child) {
            if (in_array($child['name'], $definition['fields'], true)) {
                continue;
            }
            if (isset($definition['relations'][$child['name']])) {
                continue;
            }
            throw new \InvalidArgumentException(
                "'{$child['name']}' is not a field of {$definition['type']}. Fields: ".
                implode(', ', $definition['fields']).'. Relations: '.
                implode(', ', array_keys($definition['relations'])).'.'
            );
        }
    }

    private function scalarFields(string $name, array $selection): array
    {
        $definition = self::TYPES[$name];
        $requested = array_column($selection['selections'], 'name');
        $fields = array_values(array_intersect($requested, $definition['fields']));

        // A selection set with only relations still needs an id to be useful.
        return $fields ?: ['id', 'code', 'name'];
    }

    /** Machine-readable schema summary, for a client that wants to discover it. */
    public function schema(): array
    {
        return [
            'note' => 'A documented subset of GraphQL: named queries and mutations, field selection, '.
                'nested relations, arguments, aliases and variables. No fragments, directives, unions or subscriptions.',
            'queries' => collect(self::TYPES)->map(fn ($definition, $name) => [
                'name' => $name,
                'type' => $definition['type'],
                'fields' => $definition['fields'],
                'args' => $definition['args'],
                'relations' => array_keys($definition['relations']),
            ])->values()->all(),
            'root_queries' => self::ROOT_QUERIES,
            'mutations' => self::MUTATIONS,
            'write_policy' => 'Every mutation records a draft for human approval. Nothing is written by the API.',
            'writable_scope' => collect(DraftChangeService::SCOPE)
                ->mapWithKeys(fn ($attributes, $class) => [class_basename($class) => $attributes])->all(),
            'limits' => ['default_limit' => self::DEFAULT_LIMIT, 'max_limit' => self::MAX_LIMIT, 'max_impact_depth' => self::MAX_DEPTH],
        ];
    }

    /** Example documents for the API page — the fastest way to get a client working. */
    public function examples(): array
    {
        return [
            [
                'title' => 'Critical applications with their capabilities and cost',
                'query' => <<<'GQL'
query {
  applications(criticality: "critical", limit: 20) {
    code
    name
    hosting_country
    cost { total_ngn technical_debt }
    capabilities { code name }
  }
}
GQL,
            ],
            [
                'title' => 'Change impact of retiring a technology component',
                'query' => <<<'GQL'
query {
  impact(entityType: "TechComponent", entityId: 12, depth: 3, direction: "downstream")
}
GQL,
            ],
            [
                'title' => 'Plateau diff — cost, risk and capability deltas',
                'query' => <<<'GQL'
query {
  plateauDiff(from: 1, to: 2)
}
GQL,
            ],
            [
                'title' => 'Propose a change (records a draft; writes nothing)',
                'query' => <<<'GQL'
mutation {
  proposeUpdate(entityType: "EaApplication", id: 7, agent: "cmdb-sync", input: {
    lifecycle: "sunset"
    criticality: "high"
  })
}
GQL,
            ],
            [
                'title' => 'Connection catalogue for the CBN RBCF App. II §1.1(i) return',
                'query' => <<<'GQL'
query {
  interfaces(counterparty_type: "regulator") {
    code
    name
    objective
    review_cadence
    last_reviewed_at
    quality_seal { state completeness }
  }
}
GQL,
            ],
        ];
    }
}
