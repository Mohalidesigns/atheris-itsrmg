<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * QualitySeal — B3, the freshness state machine.
 *
 * ATH-EAR-002 §3.4 identifies this as LeanIX's strongest mechanic and §5.4
 * lists it as a direct copy: a per-entity state of Approved / Check Needed /
 * Draft / Rejected, where only Responsible or Accountable subscribers may
 * approve, edits by anyone else break the seal, and an administrator-configured
 * 30/60/90-day interval breaks it on schedule *regardless of whether anything
 * changed* — forcing periodic re-validation of stale data.
 *
 * §4 row 14 scores Atheris 0 on automated freshness enforcement against
 * LeanIX's 4, and calls it one of the three mechanics without which "the module
 * dies in every pilot".
 */
class QualitySeal extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_quality_seals';

    protected $guarded = [];

    protected $casts = [
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'broken_at' => 'datetime',
        'missing_attributes' => 'array',
    ];

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const CHECK_NEEDED = 'check_needed';

    public const REJECTED = 'rejected';

    public const STATES = [self::DRAFT, self::APPROVED, self::CHECK_NEEDED, self::REJECTED];

    public const STATE_LABELS = [
        self::DRAFT => 'Draft',
        self::APPROVED => 'Approved',
        self::CHECK_NEEDED => 'Check needed',
        self::REJECTED => 'Rejected',
    ];

    public function scopeForEntity($query, string $type, int|string $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    public function isApproved(): bool
    {
        return $this->state === self::APPROVED && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function daysToExpiry(): ?int
    {
        if (! $this->expires_at) {
            return null;
        }

        return (int) round(now()->diffInDays($this->expires_at, false));
    }

    public function stateLabel(): string
    {
        return self::STATE_LABELS[$this->state] ?? ucfirst($this->state);
    }

    /** Maps onto the StatusBadge tones the rest of the UI already uses. */
    public function tone(): string
    {
        return match (true) {
            $this->state === self::APPROVED && ! $this->isExpired() => 'pass',
            $this->state === self::REJECTED => 'fail',
            $this->state === self::CHECK_NEEDED, $this->isExpired() => 'warn',
            default => 'draft',
        };
    }
}
