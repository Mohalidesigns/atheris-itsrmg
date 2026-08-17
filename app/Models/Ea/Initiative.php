<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Initiative extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_initiatives';

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date', 'target_end_date' => 'date',
        'linked_capabilities' => 'array', 'linked_applications' => 'array',
        'linked_risks' => 'array', 'linked_obligations' => 'array',
        'budget_ngn' => 'decimal:2',
    ];

    public function plateau()
    {
        return $this->belongsTo(Plateau::class);
    }

    public function deliverables()
    {
        return $this->hasMany(AdmDeliverable::class, 'initiative_id');
    }

    public function predecessorLinks()
    {
        return $this->hasMany(InitiativeDependency::class, 'successor_id');
    }

    public function successorLinks()
    {
        return $this->hasMany(InitiativeDependency::class, 'predecessor_id');
    }
}
