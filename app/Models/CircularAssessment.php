<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bank's triage of a regulator circular: Unread → Assessed → Actioned (or Not applicable). */
class CircularAssessment extends Model
{
    use BelongsToOrganization;

    public const UNREAD = 'unread';

    public const ASSESSED = 'assessed';

    public const ACTIONED = 'actioned';

    public const NOT_APPLICABLE = 'not_applicable';

    public const STATUSES = [self::UNREAD, self::ASSESSED, self::ACTIONED, self::NOT_APPLICABLE];

    public const APPLICABILITY = ['applicable', 'partially_applicable', 'not_applicable'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'assessed_at' => 'datetime',
        'actioned_at' => 'datetime',
    ];

    public function circular(): BelongsTo
    {
        return $this->belongsTo(RegulatoryCircular::class, 'circular_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_id');
    }

    /** Open = the bank still owes work on it. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::UNREAD, self::ASSESSED], true);
    }
}
