<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class ZoneAssignment extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_zone_assignments';

    protected $guarded = [];

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function application()
    {
        return $this->belongsTo(EaApplication::class);
    }
}
