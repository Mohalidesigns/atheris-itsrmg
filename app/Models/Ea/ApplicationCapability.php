<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

/**
 * Materialised form of `ea_applications_ext.capability_ids`.
 *
 * §10 names JSON-column filtering as a scale blocker. `capability_ids` is the
 * column it means: `whereJsonContains` cannot use an index, and the capability
 * overlay runs one such query per capability.
 *
 * `allocation_weight` exists because cost-per-capability (B17) needs to divide
 * an application's cost between the capabilities it realises, and an even split
 * is a guess the architect should be able to override.
 */
class ApplicationCapability extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_application_capabilities';

    protected $guarded = [];

    protected $casts = ['allocation_weight' => 'float'];

    public function application()
    {
        return $this->belongsTo(EaApplication::class, 'application_id');
    }

    public function capability()
    {
        return $this->belongsTo(Capability::class, 'capability_id');
    }
}
