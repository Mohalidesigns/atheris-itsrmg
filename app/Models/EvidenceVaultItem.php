<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class EvidenceVaultItem extends Model
{
    use HasTenantIdAlias;

    protected $table = 'evidence_vault';

    protected $guarded = [];

    protected $casts = ['retention_until' => 'datetime', 'worm_locked' => 'boolean'];
}
