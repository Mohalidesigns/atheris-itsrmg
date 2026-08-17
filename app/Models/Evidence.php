<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Evidence extends Model
{
    use BelongsToOrganization;
    use HasTenantIdAlias;

    protected $table = 'evidence';

    protected $fillable = [
        'organization_id', 'evidenceable_type', 'evidenceable_id',
        'title', 'description', 'type', 'file_path', 'file_name',
        'file_size', 'mime_type', 'url', 'status', 'uploaded_by',
        'reviewed_by', 'reviewed_at', 'valid_from', 'valid_until',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public const TYPES = ['document', 'screenshot', 'url', 'attestation', 'log'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'expired'];

    public function evidenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }
}
