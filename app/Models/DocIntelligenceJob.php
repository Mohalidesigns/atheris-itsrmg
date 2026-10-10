<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded regulatory document and its extraction. Pipeline (spec §6.4.4):
 * uploaded → ocr_done → extraction_done → awaiting_review → accepted | rejected.
 */
class DocIntelligenceJob extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    public const TYPES = [
        'circular' => 'Regulator circular',
        'ndpc_decision' => 'NDPC decision',
        'audit_report' => 'Audit report',
        'vendor_ddq' => 'Vendor DDQ',
    ];

    /** Spec §6.4.6: extractions below this confidence need the reviewer to confirm every field. */
    public const REVIEW_THRESHOLD = 0.90;

    protected $guarded = [];

    protected $casts = [
        'structured_output' => 'array',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function circular(): BelongsTo
    {
        return $this->belongsTo(RegulatoryCircular::class, 'circular_id');
    }

    public function isReviewable(): bool
    {
        return in_array($this->status, ['extraction_done', 'awaiting_review'], true);
    }

    public function needsFieldConfirmation(): bool
    {
        return $this->confidence < self::REVIEW_THRESHOLD;
    }
}
