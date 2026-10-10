<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiemSignal extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'received_at' => 'datetime'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(SecurityAlert::class, 'security_alert_id');
    }
}
