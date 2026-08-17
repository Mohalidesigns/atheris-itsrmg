<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class TenantTheme extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['tokens' => 'array'];
}
