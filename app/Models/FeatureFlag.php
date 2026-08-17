<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean', 'payload' => 'array'];
}
