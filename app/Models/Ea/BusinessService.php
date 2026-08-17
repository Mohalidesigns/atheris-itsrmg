<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * BusinessService — the canonical business service, ATH-EAR-002 WS 2.1 / §7.2.
 *
 * §7.2 allows either a new `ea_business_services` table or promoting
 * `ValueStream`. A new table is the right call: a value stream is an ArchiMate
 * strategy-layer concept describing how value is delivered end to end, while a
 * business service is what the BCP module runs a BIA against. Overloading one
 * onto the other would make both harder to explain to an assessor.
 *
 * Replaces the retired core `App\Models\BusinessService`, which survives as a
 * deprecated read-through projection over this table.
 */
class BusinessService extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_business_services';

    protected $guarded = [];

    public function capability()
    {
        return $this->belongsTo(Capability::class, 'capability_id');
    }

    public function processes()
    {
        return $this->hasMany(Process::class, 'service_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Dependencies now live in the single relationship graph rather than a
     * private join table — §7.1's one-graph principle.
     */
    public function dependencies()
    {
        return $this->hasMany(Relationship::class, 'source_id')
            ->where('source_type', self::class);
    }

    public function dependents()
    {
        return $this->hasMany(Relationship::class, 'target_id')
            ->where('target_type', self::class);
    }

    /** BCP states service recovery objectives in minutes; EA processes in hours. */
    public function rtoHours(): ?float
    {
        return $this->rto_minutes !== null ? round($this->rto_minutes / 60, 2) : null;
    }

    public function rpoHours(): ?float
    {
        return $this->rpo_minutes !== null ? round($this->rpo_minutes / 60, 2) : null;
    }
}
