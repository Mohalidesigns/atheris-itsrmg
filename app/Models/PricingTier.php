<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingTier extends Model
{
    protected $guarded = [];

    protected $casts = ['features' => 'array', 'limits' => 'array', 'is_popular' => 'boolean'];
}
