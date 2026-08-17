<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoardPackTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['sections' => 'array', 'navy_variant' => 'boolean'];
}
