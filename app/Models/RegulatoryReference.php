<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulatoryReference extends Model
{
    protected $guarded = [];

    protected $casts = ['effective_date' => 'date'];
}
