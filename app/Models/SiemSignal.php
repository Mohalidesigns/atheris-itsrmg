<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class SiemSignal extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'received_at' => 'datetime'];
}
