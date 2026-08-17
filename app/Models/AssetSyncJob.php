<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class AssetSyncJob extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
}
