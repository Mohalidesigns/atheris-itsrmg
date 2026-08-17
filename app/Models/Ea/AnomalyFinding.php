<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class AnomalyFinding extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_anomaly_findings';

    protected $guarded = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
