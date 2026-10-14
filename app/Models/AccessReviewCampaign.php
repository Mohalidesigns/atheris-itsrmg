<?php

namespace App\Models;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A user-access recertification campaign over one system (ATH-VAL-013). */
class AccessReviewCampaign extends Model
{
    use BelongsToOrganization, GeneratesSequentialCode;

    protected $guarded = [];

    protected $casts = ['due_at' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public const CATEGORIES = ['application', 'infrastructure', 'privileged', 'physical', 'vendor'];

    public const CADENCES = ['monthly', 'quarterly', 'semi_annual', 'annual'];

    /** Where the entitlements come from: Atheris' own users and roles, an AD sandbox extract, or none. */
    public const SOURCES = ['atheris' => 'Atheris users and roles', 'ad' => 'Active Directory (sandbox)', 'manual' => 'Entered by hand'];

    public static function generateNextCode(int $organizationId): string
    {
        return static::nextSequentialCode($organizationId, 'campaign_code', 'UAR-');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccessReviewItem::class, 'campaign_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'in_progress' && $this->due_at?->lt(today());
    }

    public function getTitleAttribute(): string
    {
        return $this->name;
    }
}
