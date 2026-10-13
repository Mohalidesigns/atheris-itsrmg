<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of an asset discovery connector or a CSV import. Strictly per bank: the table always had
 * organization_id, but without a scope every bank saw every bank's sync history.
 */
class AssetSyncJob extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'errors' => 'array',
    ];

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
