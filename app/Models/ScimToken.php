<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ScimToken extends Model
{
    use BelongsToOrganization;
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['scopes' => 'array', 'revoked_at' => 'datetime',  'expires_at' => 'datetime', 'last_used_at' => 'datetime'];
}
