<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FairRun extends Model
{
    protected $guarded = [];

    protected $casts = ['histogram' => 'array', 'ran_at' => 'datetime'];
}
