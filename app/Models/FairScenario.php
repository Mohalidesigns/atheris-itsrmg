<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FairScenario extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = [
        'frequency_distribution' => 'array',
        'magnitude_distribution' => 'array',
        'control_effectiveness' => 'array',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(FairRun::class, 'scenario_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }
}
