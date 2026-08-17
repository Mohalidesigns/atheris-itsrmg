<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class SiemIntegration extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['config' => 'array', 'last_signal_at' => 'datetime'];
}
