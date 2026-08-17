<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
