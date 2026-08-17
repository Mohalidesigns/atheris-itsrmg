<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class ScimToken extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['scopes' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime'];
}
