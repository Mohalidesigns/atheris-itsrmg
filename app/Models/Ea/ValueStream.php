<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class ValueStream extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_value_streams';

    protected $guarded = [];

    protected $casts = ['stages' => 'array', 'participants' => 'array', 'linked_capabilities' => 'array'];
}
