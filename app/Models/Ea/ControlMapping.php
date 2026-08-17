<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class ControlMapping extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_control_mappings';

    protected $guarded = [];
}
