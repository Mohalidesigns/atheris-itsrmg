<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['schema' => 'array', 'effective_from' => 'date', 'navy_variant' => 'boolean'];
}
