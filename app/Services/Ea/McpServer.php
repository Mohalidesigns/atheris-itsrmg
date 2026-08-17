<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Initiative;
use App\Models\Ea\Plateau;
use App\Models\Ea\Principle;
use App\Models\Ea\Standard;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ValueStream;

/**
 * McpServer — implements a subset of Anthropic's Model Context Protocol
 * surface so external Claude / GPT agents can interrogate the EA repository
 * without going through the UI.
 *
 * The server exposes a JSON-RPC-style endpoint that handles `initialize`,
 * `tools/list` and `tools/call`.
 *
 * Phase 4 (WS 4.5) added **scoped writes with draft-and-approve** — "Ardoq's
 * Scenario-merge pattern". The write tools (`propose_create`, `propose_update`,
 * `propose_delete`) do not write: each records a {@see \App\Models\Ea\DraftChange}
 * that a human holding `approve ea` merges, and says so in its own description
 * and in every response.
 *
 * That is not caution about agents in general. By Phase 3 this repository is the
 * source of signed regulatory returns whose citations snapshot evidence quality
 * at the moment of capture, so an unattended write can retroactively change what
 * a bank told the CBN. §10 makes evidence integrity a release gate.
 */
class McpServer
{
    public const PROTOCOL_VERSION = '2025-03-26';
    public const SERVER_INFO = [
        'name' => 'nexusrisk-ea-mcp',
        'version' => '1.0.0',
    ];

    public function handle(array $message): array
    {
        $method = $message['method'] ?? null;
        $id = $message['id'] ?? null;
        $params = $message['params'] ?? [];

        return match ($method) {
            'initialize' => $this->respond($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'serverInfo' => self::SERVER_INFO,
                'capabilities' => ['tools' => ['listChanged' => false]],
            ]),
            'tools/list' => $this->respond($id, ['tools' => $this->tools()]),
            'tools/call' => $this->respond($id, $this->callTool($params['name'] ?? '', $params['arguments'] ?? [])),
            'ping' => $this->respond($id, []),
            default => $this->error($id, -32601, "Method '$method' not found"),
        };
    }

    public function tools(): array
    {
        return [
            [
                'name' => 'list_applications',
                'description' => 'List Enterprise Architecture applications. Optional filters: criticality, lifecycle, search.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'criticality' => ['type' => 'string'],
                        'lifecycle' => ['type' => 'string'],
                        'search' => ['type' => 'string'],
                        'limit' => ['type' => 'integer', 'default' => 50],
                    ],
                ],
            ],
            [
                'name' => 'list_capabilities',
                'description' => 'List business capabilities. Optional filter: level, source (bian/custom).',
                'inputSchema' => ['type' => 'object', 'properties' => ['level' => ['type' => 'integer'], 'source' => ['type' => 'string']]],
            ],
            [
                'name' => 'list_tech_components',
                'description' => 'List technology components, optionally filtered to those at EOL risk.',
                'inputSchema' => ['type' => 'object', 'properties' => ['eol_within_months' => ['type' => 'integer'], 'radar_status' => ['type' => 'string']]],
            ],
            [
                'name' => 'list_initiatives',
                'description' => 'List initiatives on the roadmap.',
                'inputSchema' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'adm_phase' => ['type' => 'string']]],
            ],
            [
                'name' => 'list_principles',
                'description' => 'List architecture principles in the catalogue.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name' => 'list_standards',
                'description' => 'List standards in the catalogue.',
                'inputSchema' => ['type' => 'object', 'properties' => ['radar_status' => ['type' => 'string']]],
            ],
            [
                'name' => 'list_plateaux',
                'description' => 'List architecture plateaux (current / target / transition).',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name' => 'list_value_streams',
                'description' => 'List value streams.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name' => 'search_ea',
                'description' => 'Natural-language search across the EA repository.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            ],

            /* ---------- Phase 4 reads (WS 4.2 / 4.3 / 4.7) ---------- */
            [
                'name' => 'change_impact',
                'description' => 'n-hop change impact from any EA entity: what else is affected, at what cost, '.
                    'which processes have tight recovery objectives, which controls have gaps.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type' => ['type' => 'string', 'description' => 'EaApplication | Capability | TechComponent | EaInterface | Process | LogicalEntity | Site'],
                        'entity_id' => ['type' => 'integer'],
                        'depth' => ['type' => 'integer', 'default' => 2, 'maximum' => 5],
                        'direction' => ['type' => 'string', 'enum' => ['both', 'upstream', 'downstream'], 'default' => 'both'],
                    ],
                    'required' => ['entity_type', 'entity_id'],
                ],
            ],
            [
                'name' => 'plateau_diff',
                'description' => 'Compare two plateaux: count, cost, risk, technical-debt, capability-coverage and residency deltas.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['from_plateau_id' => ['type' => 'integer'], 'to_plateau_id' => ['type' => 'integer']],
                    'required' => ['from_plateau_id', 'to_plateau_id'],
                ],
            ],
            [
                'name' => 'cost_model',
                'description' => 'Portfolio cost and TCO roll-up with technical-debt scores, cost per capability, '.
                    'and rationalisation candidates. Every total reports its coverage of the estate.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['view' => ['type' => 'string', 'enum' => ['portfolio', 'per_capability', 'per_process', 'rationalisation'], 'default' => 'portfolio']],
                ],
            ],

            /* ---------- Phase 4 scoped writes (WS 4.5) ----------
             | "GraphQL API + MCP scoped writes with draft-and-approve (Ardoq's
             | Scenario-merge pattern)."
             |
             | These tools do not write. They record a proposal that a human
             | holding `approve ea` merges. The repository is the source of a
             | signed regulatory return whose citations snapshot seal state at
             | capture, so an unattended edit can change what a bank told the
             | CBN. The tool descriptions say so, because an agent that believes
             | it has written will report success to its user. */
            [
                'name' => 'propose_update',
                'description' => 'Propose a change to an existing entity. Records a DRAFT for human approval — '.
                    'NOTHING IS WRITTEN. Returns the draft reference and its validation result. '.
                    'Writable types: EaApplication, Capability, TechComponent, EaInterface, Process, Relationship.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type' => ['type' => 'string'],
                        'entity_id' => ['type' => 'integer'],
                        'changes' => ['type' => 'object', 'description' => 'Attribute => new value. Only allow-listed attributes are accepted.'],
                        'rationale' => ['type' => 'string', 'description' => 'Why. Shown to the approver.'],
                    ],
                    'required' => ['entity_type', 'entity_id', 'changes'],
                ],
            ],
            [
                'name' => 'propose_create',
                'description' => 'Propose a new entity. Records a DRAFT for human approval — NOTHING IS WRITTEN.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type' => ['type' => 'string'],
                        'attributes' => ['type' => 'object'],
                        'rationale' => ['type' => 'string'],
                    ],
                    'required' => ['entity_type', 'attributes'],
                ],
            ],
            [
                'name' => 'propose_delete',
                'description' => 'Propose deleting an entity. Records a DRAFT for human approval — NOTHING IS WRITTEN. '.
                    'The draft reports how many relationships would be orphaned.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type' => ['type' => 'string'],
                        'entity_id' => ['type' => 'integer'],
                        'rationale' => ['type' => 'string'],
                    ],
                    'required' => ['entity_type', 'entity_id'],
                ],
            ],
            [
                'name' => 'list_drafts',
                'description' => 'List proposed changes and their status (pending / applied / rejected / failed), '.
                    'so an agent can see whether its earlier proposals were accepted.',
                'inputSchema' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]],
            ],
            [
                'name' => 'writable_scope',
                'description' => 'The attributes each entity type exposes to scoped writes. Call this before proposing.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ];
    }

    /**
     * Resolve a caller-supplied type name onto a model class.
     *
     * Agents write `EaApplication`, `ea_application`, `application` and the FQCN
     * interchangeably; accepting all four is cheaper than a support conversation.
     */
    private function resolveEntityType(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            throw new \InvalidArgumentException('entity_type is required.');
        }

        if (class_exists($name)) {
            return $name;
        }

        $candidate = 'App\\Models\\Ea\\'.$name;
        if (class_exists($candidate)) {
            return $candidate;
        }

        $normalised = strtolower(str_replace(['_', '-', ' '], '', $name));

        $aliases = [
            'application' => \App\Models\Ea\EaApplication::class,
            'eaapplication' => \App\Models\Ea\EaApplication::class,
            'capability' => Capability::class,
            'techcomponent' => TechComponent::class,
            'technology' => TechComponent::class,
            'technologycomponent' => TechComponent::class,
            'interface' => \App\Models\Ea\EaInterface::class,
            'eainterface' => \App\Models\Ea\EaInterface::class,
            'process' => \App\Models\Ea\Process::class,
            'businessprocess' => \App\Models\Ea\Process::class,
            'relationship' => \App\Models\Ea\Relationship::class,
            'logicalentity' => \App\Models\Ea\LogicalEntity::class,
            'site' => \App\Models\Ea\Site::class,
        ];

        if (isset($aliases[$normalised])) {
            return $aliases[$normalised];
        }

        throw new \InvalidArgumentException(
            "Unknown entity_type '{$name}'. Try one of: ".implode(', ', array_keys($aliases)).'.'
        );
    }

    protected function callTool(string $name, array $args): array
    {
        $content = match ($name) {
            'list_applications' => $this->encodeRows(EaApplication::query()
                ->when($args['criticality'] ?? null, fn ($q, $v) => $q->where('criticality', $v))
                ->when($args['lifecycle'] ?? null, fn ($q, $v) => $q->where('lifecycle', $v))
                ->when($args['search'] ?? null, fn ($q, $v) => $q->where('name', 'like', "%$v%"))
                ->limit((int) ($args['limit'] ?? 50))->get(['id', 'code', 'name', 'criticality', 'lifecycle'])),
            'list_capabilities' => $this->encodeRows(Capability::query()
                ->when($args['level'] ?? null, fn ($q, $v) => $q->where('level', $v))
                ->when($args['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
                ->limit(200)->get(['id', 'code', 'name', 'level', 'criticality', 'source'])),
            'list_tech_components' => $this->encodeRows(TechComponent::query()
                ->when($args['radar_status'] ?? null, fn ($q, $v) => $q->where('radar_status', $v))
                ->when($args['eol_within_months'] ?? null, fn ($q, $v) => $q->whereBetween('eol_date', [now(), now()->addMonths((int) $v)]))
                ->limit(200)->get(['id', 'code', 'name', 'vendor', 'radar_status', 'eol_date'])),
            'list_initiatives' => $this->encodeRows(Initiative::query()
                ->when($args['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($args['adm_phase'] ?? null, fn ($q, $v) => $q->where('adm_phase', $v))
                ->limit(200)->get(['id', 'code', 'name', 'status', 'adm_phase', 'progress_percent'])),
            'list_principles' => $this->encodeRows(Principle::get(['id', 'code', 'name', 'statement'])),
            'list_standards' => $this->encodeRows(Standard::query()
                ->when($args['radar_status'] ?? null, fn ($q, $v) => $q->where('radar_status', $v))
                ->get(['id', 'code', 'name', 'category', 'radar_status'])),
            'list_plateaux' => $this->encodeRows(Plateau::get(['id', 'code', 'name', 'plateau_type', 'effective_from', 'effective_to'])),
            'list_value_streams' => $this->encodeRows(ValueStream::get(['id', 'code', 'name'])),
            'search_ea' => $this->encodeRows((new NlSearchService())->search((string) ($args['query'] ?? ''), 25)['hits']),

            /* ---------- Phase 4 reads ---------- */
            'change_impact' => $this->guard(fn () => (new ImpactAnalyser())->changeImpact(
                $this->resolveEntityType($args['entity_type'] ?? null),
                (int) ($args['entity_id'] ?? 0),
                (int) ($args['depth'] ?? ImpactAnalyser::DEFAULT_DEPTH),
                (string) ($args['direction'] ?? ImpactAnalyser::BOTH)
            )),

            'plateau_diff' => $this->guard(function () use ($args) {
                $from = Plateau::findOrFail((int) ($args['from_plateau_id'] ?? 0));
                $to = Plateau::findOrFail((int) ($args['to_plateau_id'] ?? 0));

                return (new PlateauDiffService())->diff($from, $to);
            }),

            'cost_model' => $this->guard(function () use ($args) {
                $service = new CostModelService();

                return match ($args['view'] ?? 'portfolio') {
                    'per_capability' => $service->costPerCapability(),
                    'per_process' => ['processes' => $service->costPerProcess()],
                    'rationalisation' => ['candidates' => $service->rationalisationCandidates()],
                    default => $service->portfolio(),
                };
            }),

            /* ---------- Phase 4 scoped writes (draft-and-approve) ---------- */
            'propose_update' => $this->guard(fn () => $this->proposal(
                $this->resolveEntityType($args['entity_type'] ?? null), 'update',
                (array) ($args['changes'] ?? []), (int) ($args['entity_id'] ?? 0), $args['rationale'] ?? null
            )),

            'propose_create' => $this->guard(fn () => $this->proposal(
                $this->resolveEntityType($args['entity_type'] ?? null), 'create',
                (array) ($args['attributes'] ?? []), null, $args['rationale'] ?? null
            )),

            'propose_delete' => $this->guard(fn () => $this->proposal(
                $this->resolveEntityType($args['entity_type'] ?? null), 'delete',
                [], (int) ($args['entity_id'] ?? 0), $args['rationale'] ?? null
            )),

            'list_drafts' => $this->guard(fn () => [
                'drafts' => \App\Models\Ea\DraftChange::query()
                    ->when($args['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                    ->latest()->limit(100)
                    ->get(['reference', 'origin', 'operation', 'entity_type', 'entity_id', 'status', 'reason', 'created_at', 'decided_at'])
                    ->toArray(),
            ]),

            'writable_scope' => [
                'note' => 'Proposals are reviewed by a human holding `approve ea`. Nothing an agent proposes is written by this server.',
                'types' => collect(DraftChangeService::SCOPE)
                    ->mapWithKeys(fn ($attributes, $class) => [class_basename($class) => $attributes])->all(),
            ],

            default => ['error' => "Unknown tool '$name'"],
        };
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($content, JSON_PRETTY_PRINT)],
            ],
            'isError' => isset($content['error']),
        ];
    }

    private function encodeRows($rows): array
    {
        if (is_array($rows)) return ['rows' => $rows];
        return ['rows' => $rows->toArray(), 'count' => $rows->count()];
    }

    /**
     * Record a proposal and answer in terms an agent cannot misread.
     *
     * `applied: false` and the explicit `next_step` exist because an agent that
     * receives a 200 will otherwise tell its user the change was made.
     */
    private function proposal(string $entityType, string $operation, array $payload, ?int $entityId, ?string $rationale): array
    {
        $draft = (new DraftChangeService())->propose(
            $entityType, $operation, $payload, $entityId ?: null, 'mcp', 'mcp-agent'
        );

        if ($rationale) {
            $draft->update(['reason' => $rationale]);
        }

        return [
            'applied' => false,
            'draft_reference' => $draft->reference,
            'status' => $draft->status,
            'entity_type' => class_basename($draft->entity_type),
            'entity_id' => $draft->entity_id,
            'operation' => $draft->operation,
            'validation' => $draft->validation_json,
            'next_step' => 'A NexusRisk user holding `approve ea` must approve this draft on the '.
                'Architecture Governance → Change Proposals screen before anything changes. '.
                'Poll `list_drafts` to see the outcome.',
        ];
    }

    /**
     * Turn a thrown exception into an MCP tool error rather than a 500.
     *
     * The message is the agent's only feedback channel, so it carries the
     * remediation — an unhelpful error costs an agent several turns of guessing.
     */
    private function guard(callable $work): array
    {
        try {
            return $work();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ['error' => 'No such record. Use one of the list_* tools to find a valid id.'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function respond($id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
