<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class CoreBankingSnapshot extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['artefacts' => 'array', 'captured_at' => 'datetime'];
}
