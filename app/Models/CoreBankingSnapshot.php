<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capture of a core platform's technical metadata (ATH-VAL-017), with the findings assessed
 * from it, the changes since the previous capture and the keys the customer-data embargo withheld.
 */
class CoreBankingSnapshot extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = [
        'artefacts' => 'array', 'findings' => 'array', 'changes' => 'array', 'embargoed_keys' => 'array',
        'captured_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(CoreBankingIntegration::class, 'integration_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
