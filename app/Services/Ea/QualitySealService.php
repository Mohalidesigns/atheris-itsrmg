<?php

namespace App\Services\Ea;

use App\Models\Ea\QualitySeal;
use App\Models\Ea\SealPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * QualitySealService — B3.
 *
 * The state machine, transcribed from ATH-EAR-002 §3.4's description of the
 * LeanIX mechanic and §5.4's build brief:
 *
 *   • States: Draft · Approved · Check Needed · Rejected.
 *   • Only Responsible or Accountable subscribers may approve.
 *   • Edits to base fields by anyone break the seal. Subscriptions, comments,
 *     metrics and survey operations explicitly do NOT — those are handled
 *     outside the entity's own table, so they never reach breakOnEdit().
 *   • Configurable 30/60/90-day auto-expiry breaks the seal on schedule
 *     regardless of whether anything changed, forcing re-validation.
 *   • Mandatory-attribute gating: a seal cannot be approved while a mandatory
 *     attribute is blank.
 *   • Completeness score, weighted so mandatory attributes count double.
 */
class QualitySealService
{
    public function __construct(private ?OwnershipService $ownership = null)
    {
        $this->ownership ??= new OwnershipService();
    }

    /** The seal for an entity, created in Draft if it does not exist yet. */
    public function sealFor(string $entityType, int|string $entityId): QualitySeal
    {
        $seal = QualitySeal::forEntity($entityType, $entityId)->first();

        if (! $seal) {
            $seal = QualitySeal::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'state' => QualitySeal::DRAFT,
            ]);
            $this->recomputeCompleteness($seal);
        }

        return $seal;
    }

    /* ------------------------------------------------------------------ */
    /* Completeness                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Weighted completeness, 0–100.
     *
     * Mandatory attributes are worth twice an optional one: a record with every
     * nice-to-have filled in and no owner is not 90% complete in any sense a
     * regulator would accept.
     */
    public function computeCompleteness(string $entityType, int|string $entityId): array
    {
        $model = EntityRegistry::find($entityType, $entityId);
        if (! $model) {
            return ['score' => 0, 'missing' => [], 'missing_mandatory' => []];
        }

        $policy = SealPolicy::effectiveFor($entityType);
        $mandatory = $policy->mandatory();
        $optional = $policy->optional();

        $earned = 0;
        $possible = 0;
        $missing = [];
        $missingMandatory = [];

        foreach ($mandatory as $attribute) {
            $possible += 2;
            if ($this->isPopulated($model, $attribute)) {
                $earned += 2;
            } else {
                $missing[] = $attribute;
                $missingMandatory[] = $attribute;
            }
        }

        foreach ($optional as $attribute) {
            $possible += 1;
            if ($this->isPopulated($model, $attribute)) {
                $earned += 1;
            } else {
                $missing[] = $attribute;
            }
        }

        // An entity with no accountable party is structurally incomplete
        // however many of its own fields are filled in (§2.3 — ownership is the
        // thing the repository is missing, not description text).
        $possible += 2;
        if ($this->ownership->approvers($entityType, $entityId)->isNotEmpty()) {
            $earned += 2;
        } else {
            $missing[] = '__owner';
            $missingMandatory[] = '__owner';
        }

        return [
            'score' => $possible ? (int) round($earned / $possible * 100) : 0,
            'missing' => $missing,
            'missing_mandatory' => $missingMandatory,
        ];
    }

    private function isPopulated(Model $model, string $attribute): bool
    {
        $value = $model->{$attribute} ?? null;

        if ($value === null || $value === '') {
            return false;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }

        return true;
    }

    public function recomputeCompleteness(QualitySeal $seal): QualitySeal
    {
        $result = $this->computeCompleteness($seal->entity_type, $seal->entity_id);

        $seal->completeness = $result['score'];
        $seal->missing_attributes = $result['missing'];
        $seal->save();

        return $seal;
    }

    /* ------------------------------------------------------------------ */
    /* Transitions                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Approve a seal. Refuses if the actor is not Responsible or Accountable,
     * or if a mandatory attribute is still blank.
     *
     * @throws \RuntimeException with a message intended for the user
     */
    public function approve(string $entityType, int|string $entityId, User $actor): QualitySeal
    {
        if (! $this->ownership->canApprove($actor, $entityType, $entityId)) {
            throw new \RuntimeException(
                'Only the Responsible or Accountable owner of this record may approve its quality seal.'
            );
        }

        $completeness = $this->computeCompleteness($entityType, $entityId);
        if (! empty($completeness['missing_mandatory'])) {
            throw new \RuntimeException(
                'Cannot approve: '.$this->describeMissing($entityType, $completeness['missing_mandatory'])
            );
        }

        $policy = SealPolicy::effectiveFor($entityType);
        $seal = $this->sealFor($entityType, $entityId);

        $seal->fill([
            'state' => QualitySeal::APPROVED,
            'approved_by' => $actor->id,
            'approved_by_name' => $actor->name,
            'approved_at' => now(),
            'expires_at' => $policy->auto_expiry_enabled
                ? now()->addDays($policy->renewal_interval_days)
                : null,
            'break_reason' => null,
            'broken_at' => null,
            'broken_by' => null,
            'completeness' => $completeness['score'],
            'missing_attributes' => $completeness['missing'],
        ])->save();

        AuditLogger::logBare('seal.approve', $entityType, (int) $entityId, [
            'by' => $actor->name,
            'expires_at' => optional($seal->expires_at)->toDateTimeString(),
            'completeness' => $seal->completeness,
        ]);

        return $seal;
    }

    /** Mark a record as needing review, without asserting it is wrong. */
    public function flag(string $entityType, int|string $entityId, string $reason, ?User $actor = null): QualitySeal
    {
        $seal = $this->sealFor($entityType, $entityId);

        $seal->fill([
            'state' => QualitySeal::CHECK_NEEDED,
            'break_reason' => $reason,
            'broken_at' => now(),
            'broken_by' => $actor?->id,
        ])->save();

        $this->recomputeCompleteness($seal);

        AuditLogger::logBare('seal.flag', $entityType, (int) $entityId, ['reason' => $reason]);

        return $seal;
    }

    /** Reject: the record is known to be wrong and should not be relied on. */
    public function reject(string $entityType, int|string $entityId, string $reason, User $actor): QualitySeal
    {
        if (! $this->ownership->canApprove($actor, $entityType, $entityId)) {
            throw new \RuntimeException(
                'Only the Responsible or Accountable owner of this record may reject its quality seal.'
            );
        }

        $seal = $this->sealFor($entityType, $entityId);
        $seal->fill([
            'state' => QualitySeal::REJECTED,
            'break_reason' => $reason,
            'broken_at' => now(),
            'broken_by' => $actor->id,
            'expires_at' => null,
        ])->save();

        AuditLogger::logBare('seal.reject', $entityType, (int) $entityId, ['reason' => $reason, 'by' => $actor->name]);

        return $seal;
    }

    /**
     * Break the seal because the entity's own data changed.
     *
     * Called from the ArchitectureEntityChanged listener. Deliberately silent
     * when there is no seal yet — a record that was never approved cannot go
     * stale, and creating a Draft seal for every write would fill the table
     * with rows nobody asked for.
     */
    public function breakOnEdit(string $entityType, int|string $entityId, array $changedAttributes, ?User $actor = null): ?QualitySeal
    {
        $policy = SealPolicy::effectiveFor($entityType);
        if (! $policy->break_on_edit) {
            return null;
        }

        $seal = QualitySeal::forEntity($entityType, $entityId)->first();
        if (! $seal) {
            return null;
        }

        // Only a *previously approved* seal can be broken. Draft stays draft.
        if ($seal->state !== QualitySeal::APPROVED) {
            return $this->recomputeCompleteness($seal);
        }

        $fields = implode(', ', array_slice($changedAttributes, 0, 5));
        $more = count($changedAttributes) > 5 ? ' and '.(count($changedAttributes) - 5).' more' : '';

        $seal->fill([
            'state' => QualitySeal::CHECK_NEEDED,
            'break_reason' => "Edited after approval: {$fields}{$more}.",
            'broken_at' => now(),
            'broken_by' => $actor?->id,
        ])->save();

        $this->recomputeCompleteness($seal);

        AuditLogger::logBare('seal.break', $entityType, (int) $entityId, [
            'changed' => $changedAttributes,
            'by' => $actor?->name,
        ]);

        return $seal;
    }

    /**
     * Scheduled expiry. LeanIX breaks the seal on the renewal interval
     * "regardless of whether anything changed" — that is the point, and it is
     * what makes the repository challenge itself rather than rot quietly.
     *
     * @return Collection<int, QualitySeal> the seals that expired on this run
     */
    public function expireDueSeals(): Collection
    {
        $due = QualitySeal::where('state', QualitySeal::APPROVED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($due as $seal) {
            $policy = SealPolicy::effectiveFor($seal->entity_type);
            $seal->fill([
                'state' => QualitySeal::CHECK_NEEDED,
                'break_reason' => "Scheduled re-validation: the {$policy->renewal_interval_days}-day approval interval elapsed.",
                'broken_at' => now(),
            ])->save();

            $this->recomputeCompleteness($seal);

            AuditLogger::logBare('seal.expire', $seal->entity_type, (int) $seal->entity_id, [
                'interval_days' => $policy->renewal_interval_days,
            ]);
        }

        return $due;
    }

    /**
     * A survey response counts as a verification. If the respondent holds an
     * approver role the seal is re-approved outright; otherwise the data is
     * refreshed and the seal is left for an owner to clear. §9 WS 1.2's exit
     * criterion requires responses to "visibly change seal state and
     * completeness score".
     */
    public function recordVerification(string $entityType, int|string $entityId, ?User $respondent, ?string $respondentName = null): QualitySeal
    {
        $seal = $this->sealFor($entityType, $entityId);
        $completeness = $this->computeCompleteness($entityType, $entityId);

        $canApprove = $respondent
            && $this->ownership->canApprove($respondent, $entityType, $entityId)
            && empty($completeness['missing_mandatory']);

        if ($canApprove) {
            return $this->approve($entityType, $entityId, $respondent);
        }

        $seal->fill([
            'state' => empty($completeness['missing_mandatory'])
                ? QualitySeal::CHECK_NEEDED
                : QualitySeal::DRAFT,
            'break_reason' => 'Survey response received from '
                .($respondentName ?? $respondent?->name ?? 'a respondent')
                .' — awaiting owner approval.',
            'broken_at' => now(),
            'completeness' => $completeness['score'],
            'missing_attributes' => $completeness['missing'],
        ])->save();

        return $seal;
    }

    /* ------------------------------------------------------------------ */
    /* Reporting                                                           */
    /* ------------------------------------------------------------------ */

    /** Seals keyed by entity id, for decorating an index page in one query. */
    public function mapFor(string $entityType, iterable $ids): array
    {
        return QualitySeal::where('entity_type', $entityType)
            ->whereIn('entity_id', collect($ids)->all())
            ->get()
            ->keyBy('entity_id')
            ->map(fn ($s) => [
                'state' => $s->state,
                'label' => $s->stateLabel(),
                'tone' => $s->tone(),
                'completeness' => $s->completeness,
                'expires_at' => optional($s->expires_at)->toDateString(),
                'days_to_expiry' => $s->daysToExpiry(),
                'break_reason' => $s->break_reason,
                'approved_by' => $s->approved_by_name,
            ])
            ->all();
    }

    /** Portfolio-wide freshness posture, for the Command Centre. */
    public function posture(): array
    {
        $byState = QualitySeal::selectRaw('state, count(*) as c')->groupBy('state')->pluck('c', 'state');

        $tracked = (int) $byState->sum();
        $expiringSoon = QualitySeal::where('state', QualitySeal::APPROVED)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(30)])
            ->count();

        $totalEntities = collect(EntityRegistry::types())->sum(fn ($t) => (int) $t::query()->count());

        return [
            'tracked' => $tracked,
            'total_entities' => $totalEntities,
            'unsealed' => max(0, $totalEntities - $tracked),
            'approved' => (int) ($byState[QualitySeal::APPROVED] ?? 0),
            'check_needed' => (int) ($byState[QualitySeal::CHECK_NEEDED] ?? 0),
            'draft' => (int) ($byState[QualitySeal::DRAFT] ?? 0),
            'rejected' => (int) ($byState[QualitySeal::REJECTED] ?? 0),
            'expiring_30d' => $expiringSoon,
            'avg_completeness' => (int) round((float) QualitySeal::avg('completeness')),
        ];
    }

    /** Human-readable list of blank mandatory attributes. */
    private function describeMissing(string $entityType, array $attributes): string
    {
        $definition = EntityRegistry::definition($entityType) ?? [];
        $labels = ($definition['mandatory'] ?? []) + ($definition['optional'] ?? []);

        $names = array_map(
            fn ($a) => $a === '__owner'
                ? 'no Responsible or Accountable owner is assigned'
                : ($labels[$a] ?? $a).' is blank',
            $attributes,
        );

        return implode('; ', $names).'.';
    }
}
