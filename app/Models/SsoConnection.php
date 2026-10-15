<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SsoConnection extends Model
{
    use BelongsToOrganization;
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean'];
}
