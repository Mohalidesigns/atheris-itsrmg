<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class CoreBankingIntegration extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['config' => 'array', 'last_sync_at' => 'datetime'];
}
