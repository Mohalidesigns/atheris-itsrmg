<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CcmTest extends Model
{
    protected $guarded = [];

    protected $casts = ['framework_refs' => 'array', 'parameters' => 'array', 'active' => 'boolean'];
}
