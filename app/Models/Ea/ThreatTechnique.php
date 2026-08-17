<?php

namespace App\Models\Ea;

use Illuminate\Database\Eloquent\Model;

class ThreatTechnique extends Model
{
    protected $table = 'ea_threat_techniques';

    protected $guarded = [];

    public function model()
    {
        return $this->belongsTo(ThreatModel::class, 'model_id');
    }
}
