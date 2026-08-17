<?php

namespace App\Services\Ea;

use App\Models\Ea\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * OwnershipService — §8.3: "Resolve responsible/accountable parties for any
 * entity; power notification routing."
 *
 * This is the service the rest of Phase 1 asks questions of: who may approve
 * this seal, who should receive this survey, whose "My Architecture" list does
 * this entity belong on. Keeping those answers in one place means the RACI
 * semantics are defined once.
 */
class OwnershipService
{
    /** Every subscription on an entity, approvers first. */
    public function forEntity(string $entityType, int|string $entityId): Collection
    {
        return Subscription::with('user')
            ->forEntity($entityType, $entityId)
            ->get()
            ->sortBy(fn ($s) => array_search($s->role, Subscription::ROLES, true))
            ->values();
    }

    /** The people who may approve a quality seal on this entity. */
    public function approvers(string $entityType, int|string $entityId): Collection
    {
        return Subscription::with('user')
            ->forEntity($entityType, $entityId)
            ->approvers()
            ->get();
    }

    public function canApprove(?User $user, string $entityType, int|string $entityId): bool
    {
        if (! $user) {
            return false;
        }

        // Administrators and Enterprise Architects can always approve — a seal
        // nobody can clear is worse than no seal, and §2.3 notes ownership is
        // sparse in a fresh repository.
        if ($this->isArchitect($user)) {
            return true;
        }

        return Subscription::forEntity($entityType, $entityId)
            ->approvers()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function isArchitect(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        try {
            return $user->hasRole(['Super Admin', 'Organization Admin', 'Enterprise Architect']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Attach a person to an entity in a RACI role. Idempotent. */
    public function subscribe(
        string $entityType,
        int|string $entityId,
        int $userId,
        string $role = 'responsible',
        ?string $businessRole = null,
        ?string $notes = null,
    ): Subscription {
        if (! in_array($role, Subscription::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown subscription role [{$role}].");
        }

        $subscription = Subscription::firstOrNew([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'role' => $role,
        ]);
        $subscription->business_role = $businessRole;
        $subscription->notes = $notes;
        $subscription->save();

        AuditLogger::logBare('subscribe', $entityType, (int) $entityId, [
            'user_id' => $userId,
            'role' => $role,
            'business_role' => $businessRole,
        ]);

        return $subscription;
    }

    public function unsubscribe(Subscription $subscription): void
    {
        AuditLogger::logBare('unsubscribe', $subscription->entity_type, (int) $subscription->entity_id, [
            'user_id' => $subscription->user_id,
            'role' => $subscription->role,
        ]);

        $subscription->delete();
    }

    /** Everything a person is subscribed to, grouped by entity type. */
    public function forUser(int $userId): Collection
    {
        return Subscription::where('user_id', $userId)
            ->get()
            ->groupBy('entity_type');
    }

    /**
     * The ownership completeness metric required by WS 1.1.
     *
     * "Ownership completeness" is the share of entities that have at least one
     * *accountable* party — not merely any subscription. A repository where
     * every record has an observer and no owner is not owned, and Ardoq ships a
     * Foundation Insights agent whose first job is finding exactly that gap.
     *
     * @return array<int, array{type:string,label:string,total:int,owned:int,accountable:int,percent:float}>
     */
    public function completenessByType(): array
    {
        $rows = [];

        foreach (EntityRegistry::all() as $type => $definition) {
            $total = (int) $type::query()->count();

            $owned = Subscription::where('entity_type', $type)
                ->distinct()->count('entity_id');

            $accountable = Subscription::where('entity_type', $type)
                ->where('role', 'accountable')
                ->distinct()->count('entity_id');

            $rows[] = [
                'type' => $type,
                'label' => $definition['plural'],
                'route' => $definition['route'] ?? null,
                'total' => $total,
                'owned' => $owned,
                'accountable' => $accountable,
                'unowned' => max(0, $total - $owned),
                'percent' => $total ? round($accountable / $total * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Entities of a type with no subscription at all — the work list for
     * closing the ownership gap, and the natural scope for a "who owns this?"
     * survey.
     */
    public function unownedOf(string $type, int $limit = 100): Collection
    {
        if (! EntityRegistry::supports($type)) {
            return collect();
        }

        $owned = Subscription::where('entity_type', $type)->pluck('entity_id')->unique();

        return $type::query()
            ->when($owned->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $owned))
            ->limit($limit)
            ->get();
    }

    /** Distinct email addresses to notify for an entity, by role. */
    public function recipientsFor(string $entityType, int|string $entityId, array $roles): Collection
    {
        return Subscription::with('user')
            ->forEntity($entityType, $entityId)
            ->whereIn('role', $roles)
            ->get()
            ->filter(fn ($s) => $s->user && $s->user->email)
            ->unique(fn ($s) => strtolower($s->user->email))
            ->values();
    }

    /** Resolve the model behind a subscription for display. */
    public function describe(Subscription $subscription): string
    {
        return EntityRegistry::describe($subscription->entity_type, $subscription->entity_id);
    }

    public function entityOf(Subscription $subscription): ?Model
    {
        return EntityRegistry::find($subscription->entity_type, $subscription->entity_id);
    }
}
