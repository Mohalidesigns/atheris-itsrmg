<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A breach-watch item about a vendor, triaged by the bank: new → acknowledged | dismissed |
 * reassessment (an incident-driven assessment was opened). Strictly per bank.
 */
class TprmBreachEvent extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['new', 'acknowledged', 'dismissed', 'reassessment'];

    public const EVENT_TYPES = ['breach', 'ransomware', 'vulnerability', 'outage', 'typosquat', 'regulatory'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    protected $guarded = [];

    protected $casts = ['discovered_at' => 'datetime', 'reviewed_at' => 'datetime'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reassessment(): HasOne
    {
        return $this->hasOne(VendorAssessment::class, 'breach_event_id');
    }
}
