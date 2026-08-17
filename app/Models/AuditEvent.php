<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasTenantIdAlias;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];
}
