<?php

namespace App\Services\Ea;

use App\Models\Ea\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * AuditLogger — single point that all EA writes call to leave an append-only
 * audit trail. Each entry captures actor, action, entity, before-state,
 * after-state, ip and optional context.
 */
class AuditLogger
{
    public static function log(string $action, Model $entity, ?array $before = null, ?array $after = null, array $context = []): void
    {
        $user = Auth::user();
        AuditLog::query()->create([
            'organization_id' => optional($user)->organization_id,
            'actor_id' => optional($user)->id,
            'actor_email' => optional($user)->email,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'before' => $before,
            'after' => $after ?? $entity->getAttributes(),
            'ip' => static::ip(),
            'context' => $context ?: null,
        ]);
    }

    public static function logBare(string $action, string $entityType, ?int $entityId = null, array $context = []): void
    {
        $user = Auth::user();
        AuditLog::query()->create([
            'organization_id' => optional($user)->organization_id,
            'actor_id' => optional($user)->id,
            'actor_email' => optional($user)->email,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => null,
            'after' => null,
            'ip' => static::ip(),
            'context' => $context ?: null,
        ]);
    }

    private static function ip(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            return null;
        }
    }
}
