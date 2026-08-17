<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KriReading extends Model
{
    protected $guarded = [];

    protected $casts = ['recorded_at' => 'datetime'];
}
