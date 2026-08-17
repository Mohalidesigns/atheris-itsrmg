<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\DraftChange;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\TechComponent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DraftChangeService — WS 4.5's "scoped writes with draft-and-approve".
 *
 * The spec names the precedent: "Ardoq's Scenario-merge pattern". The principle
 * is that an automated caller — an MCP agent, a GraphQL client, a CMDB sync —
 * proposes, and a human with `approve ea` merges.
 *
 * This is not a general policy about agents; it is specific to what this
 * repository *is*. §10 makes evidence integrity a release gate, and by Phase 3
 * the repository is the source of a signed regulatory return whose citations
 * snapshot the seal state at capture. An unattended write can therefore change
 * what a bank told the CBN. That earns a review step.
 *
 * Three guarantees:
 *
 *  1. **Scoped.** Only the six entity types and the attribute allow-lists below
 *     can be proposed. An agent cannot invent a column, touch tenancy, or write
 *     a quality seal — the seal is the human judgement the agent is not making.
 *  2. **Validated at propose time.** A draft that cannot apply is rejected when
 *     it is written, not when someone approves it. An approver should never be
 *     the one to discover the payload was malformed.
 *  3. **Revalidated at apply time.** The repository can move between proposal
 *     and approval, so the checks run again and a stale draft fails loudly.
 */
class DraftChangeService
{
    /**
     * Writable surface: entity type => attributes an automated caller may set.
     *
     * Note what is absent. No `organization_id` (tenancy is derived, never
     * asserted by a caller). No `plateau_id` (scenario membership is an
     * architect's judgement). No seal or ownership fields. No `capability_ids`
     * on applications — the pivot is derived, and the mapping is exactly the
     * judgement a survey exists to crowdsource from a human owner.
     */
    public const SCOPE = [
        EaApplication::class => [
            'code', 'name', 'description', 'time_score', 'business_fit', 'technical_fit',
            'criticality', 'lifecycle', 'annual_cost', 'cost_currency', 'annual_cost_ngn',
            'user_count', 'owner_role', 'vendor_id', 'hosting_country', 'hosting_model',
            'dr_country', 'contract_end_date', 'licence_model',
        ],
        Capability::class => ['code', 'name', 'description', 'parent_id', 'level', 'criticality', 'source'],
        TechComponent::class => ['code', 'name', 'category', 'vendor', 'version', 'radar_status', 'eol_date', 'eos_date'],
        EaInterface::class => [
            'code', 'name', 'source_app_id', 'target_app_id', 'protocol', 'pattern',
            'classification', 'pii_carrying', 'status', 'objective', 'counterparty_type',
            'review_cadence', 'last_reviewed_at',
        ],
        Process::class => ['code', 'name', 'parent_id', 'level', 'capability_id', 'criticality'],
        Relationship::class => ['source_type', 'source_id', 'target_type', 'target_id', 'relation_type'],
    ];

    public const OPERATIONS = ['create', 'update', 'delete'];

    public function __construct(
        private GraphResolver $resolver = new GraphResolver(),
    ) {
    }

    /* ===================== propose ===================== */

    /**
     * Record a proposed change.
     *
     * @throws \InvalidArgumentException when the proposal is outside scope or cannot apply
     */
    public function propose(string $entityType, string $operation, array $payload, ?int $entityId = null, string $origin = 'mcp', ?string $agentLabel = null): DraftChange
    {
        $this->assertInScope($entityType, $operation, $payload);

        $before = null;
        if ($entityId) {
            $existing = $entityType::find($entityId);
            if (! $existing) {
                throw new \InvalidArgumentException(
                    class_basename($entityType)." #{$entityId} does not exist, so it cannot be updated or deleted."
                );
            }
            $before = array_intersect_key($existing->getAttributes(), array_flip(self::SCOPE[$entityType]));
        }

        $validation = $this->validate($entityType, $operation, $payload, $entityId);

        return DraftChange::create([
            'reference' => 'DRAFT-'.strtoupper(Str::random(8)),
            'origin' => $origin,
            'operation' => $operation,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'before' => $before,
            'validation_json' => $validation,
            'status' => 'pending',
            'proposed_by' => Auth::id(),
            'proposed_by_label' => $agentLabel,
        ]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertInScope(string $entityType, string $operation, array $payload): void
    {
        if (! isset(self::SCOPE[$entityType])) {
            throw new \InvalidArgumentException(
                class_basename($entityType).' is not writable through the scoped API. Writable types: '.
                implode(', ', array_map('class_basename', array_keys(self::SCOPE))).'.'
            );
        }

        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException("'{$operation}' is not an operation. Use create, update or delete.");
        }

        if ($operation === 'delete') {
            return;
        }

        $allowed = self::SCOPE[$entityType];
        $rejected = array_values(array_diff(array_keys($payload), $allowed));

        if ($rejected) {
            throw new \InvalidArgumentException(
                'Not writable through the scoped API: '.implode(', ', $rejected).'. '.
                'Writable on '.class_basename($entityType).': '.implode(', ', $allowed).'.'
            );
        }

        if ($operation === 'create' && empty($payload['name']) && $entityType !== Relationship::class) {
            throw new \InvalidArgumentException('A new '.class_basename($entityType).' needs a name.');
        }
    }

    /**
     * Checks that must hold for the draft to apply. Run at propose time and
     * again at apply time.
     *
     * @return array{ok:bool, problems:array<int,string>, notes:array<int,string>}
     */
    public function validate(string $entityType, string $operation, array $payload, ?int $entityId = null): array
    {
        $problems = [];
        $notes = [];

        // Cross-module references must resolve — §7.1's soft-FK stance.
        try {
            $this->resolver->assertResolvable($payload);
        } catch (\InvalidArgumentException $e) {
            $problems[] = $e->getMessage();
        }

        // Unique codes.
        if (! empty($payload['code'])) {
            $clash = $entityType::query()
                ->where('code', $payload['code'])
                ->when($entityId, fn ($q) => $q->where('id', '!=', $entityId))
                ->exists();
            if ($clash) {
                $problems[] = "Code '{$payload['code']}' is already used by another ".class_basename($entityType).'.';
            }
        }

        // A relationship must be legal ArchiMate and point at real records.
        if ($entityType === Relationship::class && $operation !== 'delete') {
            $reason = RelationshipValidator::reasonIfNotPermitted(
                (string) ($payload['source_type'] ?? ''),
                (string) ($payload['relation_type'] ?? 'association'),
                (string) ($payload['target_type'] ?? '')
            );
            if ($reason) {
                $problems[] = $reason;
            }

            foreach ([['source_type', 'source_id'], ['target_type', 'target_id']] as [$typeKey, $idKey]) {
                $class = $payload[$typeKey] ?? null;
                $id = $payload[$idKey] ?? null;
                if ($class && $id && (! class_exists($class) || ! $class::find($id))) {
                    $problems[] = "{$typeKey}/{$idKey} does not resolve to an existing record ({$class} #{$id}).";
                }
            }
        }

        // Parent must exist and must not create a cycle.
        if (! empty($payload['parent_id'])) {
            $parent = $entityType::find($payload['parent_id']);
            if (! $parent) {
                $problems[] = "Parent #{$payload['parent_id']} does not exist.";
            } elseif ($entityId && (int) $payload['parent_id'] === (int) $entityId) {
                $problems[] = 'An entity cannot be its own parent.';
            } elseif ($entityId) {
                $descendants = (new HierarchyIndex())->subtree($entityType, $entityId);
                if (in_array((int) $payload['parent_id'], $descendants, true)) {
                    $problems[] = 'That parent is inside this entity\'s own subtree, which would create a cycle.';
                }
            }
        }

        // Deleting something other records point at.
        if ($operation === 'delete' && $entityId) {
            $dependents = Relationship::query()
                ->where(fn ($q) => $q->where('source_type', $entityType)->where('source_id', $entityId))
                ->orWhere(fn ($q) => $q->where('target_type', $entityType)->where('target_id', $entityId))
                ->count();
            if ($dependents) {
                $notes[] = "{$dependents} relationship(s) reference this entity and would be orphaned.";
            }
        }

        // An approved quality seal means someone signed for this record's
        // accuracy. An automated edit will break that seal (B3 break-on-edit),
        // which the approver should know before saying yes.
        if ($entityId && $operation !== 'create') {
            $seal = \App\Models\Ea\QualitySeal::where('entity_type', $entityType)
                ->where('entity_id', $entityId)->first();
            if ($seal && $seal->state === 'approved') {
                $notes[] = 'This record carries an approved quality seal; applying the change will break it and require re-approval.';
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'notes' => $notes];
    }

    /* ===================== decide ===================== */

    /**
     * Approve and apply.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function approve(DraftChange $draft, ?string $comment = null): DraftChange
    {
        if (! $draft->isPending()) {
            throw new \RuntimeException("Draft {$draft->reference} is already {$draft->status}.");
        }

        $revalidation = $this->validate($draft->entity_type, $draft->operation, $draft->payload, $draft->entity_id);

        if (! $revalidation['ok']) {
            $draft->update([
                'status' => 'failed',
                'validation_json' => $revalidation,
                'reason' => 'Revalidation failed at apply time: '.implode(' ', $revalidation['problems']),
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ]);

            throw new \RuntimeException(
                "Draft {$draft->reference} can no longer be applied — the repository changed since it was proposed. ".
                implode(' ', $revalidation['problems'])
            );
        }

        return DB::transaction(function () use ($draft, $comment, $revalidation) {
            $type = $draft->entity_type;

            switch ($draft->operation) {
                case 'create':
                    $model = new $type();
                    $model->fill($draft->payload);
                    $model->save();
                    $draft->entity_id = $model->id;
                    break;

                case 'update':
                    $model = $type::findOrFail($draft->entity_id);
                    $model->fill($draft->payload);
                    $model->save();
                    break;

                case 'delete':
                    $type::findOrFail($draft->entity_id)->delete();
                    break;
            }

            $draft->update([
                'status' => 'applied',
                'validation_json' => $revalidation,
                'reason' => $comment,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
                'applied_at' => now(),
                'entity_id' => $draft->entity_id,
            ]);

            AuditLogger::logBare('draft.applied', $draft->entity_type, $draft->entity_id, [
                'reference' => $draft->reference,
                'origin' => $draft->origin,
                'operation' => $draft->operation,
                'proposed_by_label' => $draft->proposed_by_label,
            ]);

            return $draft->refresh();
        });
    }

    public function reject(DraftChange $draft, ?string $reason = null): DraftChange
    {
        if (! $draft->isPending()) {
            throw new \RuntimeException("Draft {$draft->reference} is already {$draft->status}.");
        }

        $draft->update([
            'status' => 'rejected',
            'reason' => $reason,
            'decided_by' => Auth::id(),
            'decided_at' => now(),
        ]);

        AuditLogger::logBare('draft.rejected', $draft->entity_type, $draft->entity_id, [
            'reference' => $draft->reference,
            'reason' => $reason,
        ]);

        return $draft;
    }

    /** Queue summary for the review screen. */
    public function queue(): array
    {
        $drafts = DraftChange::query()->latest()->limit(200)->get();

        return [
            'drafts' => $drafts->map(fn ($draft) => [
                'id' => $draft->id,
                'reference' => $draft->reference,
                'origin' => $draft->origin,
                'operation' => $draft->operation,
                'entity_type' => $draft->entity_type,
                'entity_label' => $draft->entityLabel(),
                'entity_name' => $this->entityName($draft),
                'payload' => $draft->payload,
                'before' => $draft->before,
                'diff' => $this->diff($draft),
                'validation' => $draft->validation_json,
                'status' => $draft->status,
                'reason' => $draft->reason,
                'proposed_by' => $draft->proposed_by_label ?: optional($draft->proposer)->name ?: 'unknown',
                'created_at' => optional($draft->created_at)->toDateTimeString(),
                'decided_at' => optional($draft->decided_at)->toDateTimeString(),
            ])->values(),
            'counts' => [
                'pending' => DraftChange::where('status', 'pending')->count(),
                'applied' => DraftChange::where('status', 'applied')->count(),
                'rejected' => DraftChange::where('status', 'rejected')->count(),
                'failed' => DraftChange::where('status', 'failed')->count(),
            ],
            'scope' => collect(self::SCOPE)->mapWithKeys(fn ($attributes, $class) => [
                class_basename($class) => $attributes,
            ])->all(),
        ];
    }

    private function entityName(DraftChange $draft): ?string
    {
        if (! $draft->entity_id || ! class_exists($draft->entity_type)) {
            return $draft->payload['name'] ?? null;
        }

        $model = $draft->entity_type::find($draft->entity_id);

        return $model?->name ?? $draft->payload['name'] ?? null;
    }

    /** Field-level before/after, so an approver reads a diff and not a blob. */
    private function diff(DraftChange $draft): array
    {
        if ($draft->operation === 'create') {
            return collect($draft->payload)->map(fn ($value, $key) => [
                'field' => $key, 'before' => null, 'after' => $value,
            ])->values()->all();
        }

        if ($draft->operation === 'delete') {
            return collect($draft->before ?? [])->map(fn ($value, $key) => [
                'field' => $key, 'before' => $value, 'after' => null,
            ])->values()->all();
        }

        $before = $draft->before ?? [];

        return collect($draft->payload)
            ->filter(fn ($value, $key) => ($before[$key] ?? null) != $value)
            ->map(fn ($value, $key) => ['field' => $key, 'before' => $before[$key] ?? null, 'after' => $value])
            ->values()->all();
    }
}
