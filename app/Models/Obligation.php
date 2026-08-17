<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class Obligation extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['applicability' => 'array', 'effective_date' => 'date'];
}
