<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_drivers';

    protected $guarded = [];
}
