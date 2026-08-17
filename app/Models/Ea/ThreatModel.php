<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class ThreatModel extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_threat_models';

    protected $guarded = [];

    protected $casts = ['summary' => 'array', 'risk_score' => 'decimal:2'];

    public function techniques()
    {
        return $this->hasMany(ThreatTechnique::class, 'model_id');
    }
}
