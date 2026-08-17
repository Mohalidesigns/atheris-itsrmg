<?php

namespace App\Models\Concerns;

use App\Services\Ea\AuditLogger;

/**
 * Trait WritesAuditLog — auto-records create / update / delete events for the
 * applied model in ea_audit_log. The trait records both before and after states
 * so a diff view can be built on top.
 */
trait WritesAuditLog
{
    public static function bootWritesAuditLog(): void
    {
        static::created(function ($model) {
            AuditLogger::log('create', $model, null, $model->getAttributes());
        });
        static::updated(function ($model) {
            $changes = $model->getChanges();
            if (! empty($changes)) {
                $before = collect($changes)->mapWithKeys(fn ($v, $k) => [$k => $model->getOriginal($k)])->all();
                AuditLogger::log('update', $model, $before, $changes);
            }
        });
        static::deleted(function ($model) {
            AuditLogger::log('delete', $model, $model->getOriginal(), null);
        });
    }
}
