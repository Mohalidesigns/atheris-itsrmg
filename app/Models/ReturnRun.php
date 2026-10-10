<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Returns Centre run of a regulator template for one period.
 * Lifecycle: draft → in_review → approved → submitted (in_review can be sent back to draft).
 */
class ReturnRun extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const APPROVED = 'approved';

    public const SUBMITTED = 'submitted';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReturnTemplate::class, 'template_id');
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

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }
}
