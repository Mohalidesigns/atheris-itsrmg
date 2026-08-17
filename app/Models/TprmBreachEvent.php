<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TprmBreachEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['discovered_at' => 'datetime'];
}
