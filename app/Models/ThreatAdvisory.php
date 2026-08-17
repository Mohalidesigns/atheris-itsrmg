<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThreatAdvisory extends Model
{
    protected $guarded = [];

    protected $casts = ['cves' => 'array', 'iocs' => 'array', 'published_at' => 'datetime'];
}
