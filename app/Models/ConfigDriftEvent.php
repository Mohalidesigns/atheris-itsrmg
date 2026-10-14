<?php

namespace App\Models;

use App\Models\Change\ChangeRequest;
use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configuration change detected against an asset's baseline (ATH-VAL-013). It is either
 * authorised by an approved change request, remediated, or accepted as a risk until a date.
 */
class ConfigDriftEvent extends Model
{
    use BelongsToOrganization, GeneratesSequentialCode;

    protected $guarded = [];

    protected $casts = ['detected_at' => 'datetime', 'resolved_at' => 'datetime', 'accepted_until' => 'date'];

    public const STATUSES = ['open', 'investigating', 'authorised', 'remediated', 'accepted'];

    public const OPEN_STATUSES = ['open', 'investigating'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public const SOURCES = [
        'aws_config' => 'AWS Config',
        'defender' => 'Defender for Cloud',
        'wazuh' => 'Wazuh FIM',
        'tenable' => 'Tenable SC',
        'manual' => 'Manual audit',
    ];

    public static function generateNextCode(int $organizationId): string
    {
        return static::nextSequentialCode($organizationId, 'drift_code', 'DRF-');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
