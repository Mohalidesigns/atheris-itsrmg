<?php

namespace App\Models\Change;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A period during which changes (all, or of listed categories) are not scheduled — ATH-CHG-001 §2.5. */
class ChangeFreezeWindow extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'categories' => 'array',
        'allow_emergency' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function covers(?string $category): bool
    {
        if ($this->scope === 'all' || empty($this->categories)) {
            return true;
        }

        return in_array($category, (array) $this->categories, true);
    }

    public function isCurrent(): bool
    {
        return $this->starts_at->lte(now()) && $this->ends_at->gte(now());
    }

    public function scopeActiveOrUpcoming($query)
    {
        return $query->where('ends_at', '>=', now())->orderBy('starts_at');
    }
}
