<?php

namespace App\Models\Ea;

use Illuminate\Database\Eloquent\Model;

class KriValue extends Model
{
    protected $table = 'ea_kri_values';

    protected $guarded = [];

    protected $casts = ['recorded_at' => 'datetime'];
}
