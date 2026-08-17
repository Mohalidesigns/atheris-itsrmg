<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_zones';

    protected $guarded = [];

    public function assignments()
    {
        return $this->hasMany(ZoneAssignment::class, 'zone_id');
    }
}
