<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Trait BelongsToTenant — scopes every read to the authenticated user's
 * organisation and auto-populates the tenancy column on create.
 *
 * ATH-EAR-002 §2.5 (RC-5) recorded the pre-remediation position: newer modules
 * declared `tenant_id` while 33 older core tables declared `organization_id`,
 * both resolving to `users.organization_id` at runtime. "It works today. It
 * will break the first time someone writes a cross-module join or extracts a
 * module into a service." §7.1 makes the fix a precondition for the returns
 * engine, since "a return that cites an entity must be able to resolve it".
 *
 * WS 2.2 renamed the physical column on all 80 tables. The `tenant_id`
 * accessor and mutator below are the compatibility layer §2.5 asks for: code,
 * seeders and fixtures still speaking the old name keep working, and read back
 * the same value.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $attributes = $model->getAttributes();

            if (! array_key_exists('organization_id', $attributes) || $model->organization_id === null) {
                $organizationId = optional(Auth::user())->organization_id;
                if ($organizationId) {
                    $model->organization_id = $organizationId;
                }
            }
        });
    }

    /** Compatibility: `$model->tenant_id` still reads. */
    public function getTenantIdAttribute()
    {
        return $this->attributes['organization_id'] ?? null;
    }

    /** Compatibility: `$model->tenant_id = x` still writes. */
    public function setTenantIdAttribute($value): void
    {
        $this->attributes['organization_id'] = $value;
    }
}

class TenantScope implements Scope
{
    /**
     * NULL rows are platform-shared reference data and stay visible to every
     * tenant — the tenant-override semantics ATH-GAP-EA-001 §3.1 asks for on
     * `ea_principles`, `ea_standards` and `ea_patterns`.
     *
     * §10 flags this `orWhereNull` as "a plausible leak path" requiring an
     * explicit test; see EaTenancyTest.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = Auth::check() ? optional(Auth::user())->organization_id : null;

        if ($organizationId !== null) {
            $builder->where(function ($q) use ($organizationId, $model) {
                $q->where($model->getTable().'.organization_id', $organizationId)
                    ->orWhereNull($model->getTable().'.organization_id');
            });
        }
    }
}
