<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A regulatory notification for an incident (CBN 24h, NDPC 72h, NFIU SAR...).
 * Lifecycle: draft → in_review → approved → submitted. The preparer cannot approve their own draft.
 */
class IncidentNotification extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['draft', 'in_review', 'approved', 'submitted'];

    protected $guarded = [];

    protected $casts = [
        'delivery_receipt' => 'array',
        'signed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'deadline_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_key', 'template_key');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }
}
