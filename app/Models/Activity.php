<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Activity row stamped with organization_id so the audit trail is tenant-scoped.
 * Writing never fails: the org is resolved best-effort and may stay null.
 */
class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $user = auth()->user();
            if ($user && $user->organization_id && ! $user->hasRole('Super Admin')) {
                $builder->where('activity_log.organization_id', $user->organization_id);
            }
        });

        static::creating(function (self $activity) {
            if ($activity->organization_id) {
                return;
            }
            try {
                $orgId = null;
                $subject = $activity->subject_type && $activity->subject_id ? $activity->subject()->withoutGlobalScopes()->first() : null;
                $orgId = $subject instanceof Organization ? $subject->id : ($subject?->organization_id ?? null);
                if (! $orgId && $activity->causer_type && $activity->causer_id) {
                    $causer = $activity->causer()->withoutGlobalScopes()->first();
                    $orgId = $causer?->organization_id ?? null;
                }
                if (! $orgId && app()->bound('current_organization_id')) {
                    $orgId = app('current_organization_id');
                }
                if (! $orgId && auth()->check()) {
                    $orgId = auth()->user()->organization_id;
                }
                $activity->organization_id = $orgId ?: null;
            } catch (\Throwable) {
                // never block audit writes
            }
        });
    }
}
