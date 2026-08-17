<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrameworkClause extends Model
{
    protected $guarded = [];

    protected $casts = ['effective_date' => 'date'];
}
