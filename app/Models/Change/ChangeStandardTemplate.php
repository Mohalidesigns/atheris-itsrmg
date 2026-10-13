<?php

namespace App\Models\Change;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A pre-approved, low-risk, repeatable change (ITIL "standard change") — ATH-CHG-001 §2.5. */
class ChangeStandardTemplate extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = [
        'approved_at' => 'datetime',
        'review_due_at' => 'date',
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'expected_downtime_minutes' => 'integer',
    ];

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ChangeRequest::class, 'standard_template_id');
    }

    /** Active, approved and not past its review date: may be used to raise a standard change. */
    public function isEligible(): bool
    {
        return $this->is_active && $this->approved_at
            && (! $this->review_due_at || $this->review_due_at->endOfDay()->gte(now()));
    }

    /** approved / awaiting_approval / review_overdue / inactive */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            ! $this->approved_at => 'awaiting_approval',
            $this->review_due_at && $this->review_due_at->endOfDay()->lt(now()) => 'review_overdue',
            default => 'approved',
        };
    }

    public function scopeEligible($query)
    {
        return $query->where('is_active', true)->whereNotNull('approved_at')
            ->where(fn ($q) => $q->whereNull('review_due_at')->orWhere('review_due_at', '>=', now()->toDateString()));
    }
}
