<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class EvidenceVaultItem extends Model
{
    use BelongsToTenant;

    protected $table = 'evidence_vault';

    protected $guarded = [];

    protected $casts = ['retention_until' => 'datetime', 'worm_locked' => 'boolean'];
}
