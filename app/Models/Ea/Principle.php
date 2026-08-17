<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Principle extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_principles';

    protected $guarded = [];
}
