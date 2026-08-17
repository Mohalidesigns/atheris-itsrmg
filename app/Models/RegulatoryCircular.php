<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulatoryCircular extends Model
{
    protected $guarded = [];

    protected $casts = [
        'impact_assessment' => 'array',
        'issued_at' => 'date',
        'ingested_at' => 'datetime',
    ];
}
