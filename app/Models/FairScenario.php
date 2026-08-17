<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class FairScenario extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = [
        'frequency_distribution' => 'array',
        'magnitude_distribution' => 'array',
        'control_effectiveness' => 'array',
    ];

    public function runs()
    {
        return $this->hasMany(FairRun::class, 'scenario_id');
    }
}
