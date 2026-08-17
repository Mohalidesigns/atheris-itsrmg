<?php

namespace App\Listeners\Ea;

use App\Events\Ea\ArchitectureEntityChanged;
use App\Models\User;
use App\Services\Ea\EntityRegistry;
use App\Services\Ea\QualitySealService;

/**
 * BreakQualitySeal — §7.5.
 *
 * LeanIX's rule, transcribed from §3.4: edits to base fields, relations,
 * external IDs or mandatory/optional fields break the seal; subscriptions,
 * comments, metrics and survey operations explicitly do not.
 *
 * The second half of that rule is satisfied structurally rather than by a
 * filter — subscriptions, seals and survey responses live in their own tables,
 * so writing one never touches the entity and never reaches this listener.
 *
 * Idempotent by construction: breaking an already-broken seal is a no-op inside
 * QualitySealService::breakOnEdit().
 */
class BreakQualitySeal
{
    public function __construct(private QualitySealService $seals)
    {
    }

    public function handle(ArchitectureEntityChanged $event): void
    {
        if (! EntityRegistry::supports($event->entityType)) {
            return;
        }

        // Bookkeeping columns are not architecture facts; a touch on
        // updated_at must not invalidate an owner's approval.
        $meaningful = array_values(array_diff(
            $event->changedAttributes,
            ['updated_at', 'created_at', 'version_no', 'version', 'organization_id'],
        ));

        if (empty($meaningful)) {
            return;
        }

        $this->seals->breakOnEdit(
            $event->entityType,
            $event->entityId,
            $meaningful,
            $event->actorId ? User::find($event->actorId) : null,
        );
    }
}
