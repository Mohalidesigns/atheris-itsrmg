<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class KriDefinition extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_kri_definitions';

    protected $guarded = [];

    protected $casts = [
        'threshold_green' => 'decimal:2',
        'threshold_amber' => 'decimal:2',
        'threshold_red' => 'decimal:2',
    ];

    public function values()
    {
        return $this->hasMany(KriValue::class, 'kri_id');
    }

    public function latest()
    {
        return $this->hasOne(KriValue::class, 'kri_id')->latestOfMany('recorded_at');
    }
}
