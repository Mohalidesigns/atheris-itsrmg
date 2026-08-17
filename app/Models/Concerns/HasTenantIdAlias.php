<?php

namespace App\Models\Concerns;

/**
 * HasTenantIdAlias — read and write `tenant_id` on a table whose column is now
 * `organization_id`.
 *
 * ATH-EAR-002 WS 2.2 renamed the physical column on 80 tables and put this
 * compatibility layer inside {@see BelongsToTenant}. That covered every model
 * carrying the tenancy *scope* — but 39 models on renamed tables do not use the
 * trait at all (join tables, indexes, platform-wide config such as feature
 * flags, and the models that were simply never given it), so for those the
 * compatibility layer did not exist and `Model::create(['tenant_id' => …])`
 * failed with "Unknown column 'tenant_id'".
 *
 * That surfaced as a broken `migrate:fresh --seed` in Phase 4: several seeders
 * still speak the old name, which §2.5 explicitly promised would keep working.
 *
 * The alias is deliberately *only* the alias. Adding `BelongsToTenant` to these
 * models would also add its global scope, which would be wrong for at least
 * three of them — `feature_flags`, `ea_closure` and `ea_application_capabilities`
 * are platform-wide or pure indexes, and scoping them would change behaviour
 * well beyond fixing a column name.
 */
trait HasTenantIdAlias
{
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
