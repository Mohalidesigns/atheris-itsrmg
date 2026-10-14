<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ThreatModel extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_threat_models';

    protected $guarded = [];

    protected $casts = ['summary' => 'array', 'risk_score' => 'decimal:2'];

    public function techniques()
    {
        return $this->hasMany(ThreatTechnique::class, 'model_id');
    }
}
