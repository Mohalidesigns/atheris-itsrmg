<?php

namespace App\Models\Returns;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * RegulatoryReturn — ATH-EAR-002 A1, the flagship.
 *
 * §1.4: "Do not sell an EA tool. **Sell the regulatory architecture return, and
 * ship an EA repository as the machine that produces it.**" §6.2: "Build this
 * calendar into the platform as a first-class object. Every return has an
 * owner, a due date, a data dependency graph, a preparation workflow, a
 * sign-off chain and an archived submission. The EA repository is what the
 * returns read from — which is what keeps the repository current."
 *
 * That last clause is the strategy in one line: "a filing deadline maintains
 * data in a way no governance policy ever has" (§1.4).
 *
 * §6.3 A1: "Why nobody else builds it. Ardoq ships DORA, GLBA and APRA CPS230
 * patterns because those markets are large. There is no CBN pattern in any
 * product on earth."
 */
class RegulatoryReturn extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'regulatory_returns';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'summary' => 'array',
        'signoff_chain' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'signed_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public const DRAFT = 'draft';

    public const IN_PREPARATION = 'in_preparation';

    public const IN_REVIEW = 'in_review';

    public const SIGNED = 'signed';

    public const SUBMITTED = 'submitted';

    public const ARCHIVED = 'archived';

    public const STATES = [
        self::DRAFT, self::IN_PREPARATION, self::IN_REVIEW,
        self::SIGNED, self::SUBMITTED, self::ARCHIVED,
    ];

    public function citations()
    {
        return $this->hasMany(ReturnCitation::class, 'return_id');
    }

    public function legalEntity()
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function previous()
    {
        return $this->belongsTo(self::class, 'previous_return_id');
    }

    /**
     * Once signed, a return is immutable. §10 makes this a release gate:
     * "Every generated return and evidence pack hash-sealed, immutable once
     * signed, with the signer and timestamp recorded."
     */
    public function isSealed(): bool
    {
        return in_array($this->state, [self::SIGNED, self::SUBMITTED, self::ARCHIVED], true);
    }

    public function daysToDue(): ?int
    {
        return $this->due_date ? (int) round(now()->diffInDays($this->due_date, false)) : null;
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! in_array($this->state, [self::SUBMITTED, self::ARCHIVED], true);
    }

    /**
     * How much of this return is backed by evidence an owner has approved.
     *
     * §6.3 A1: "Every answer carries **evidence citations** back to the EA
     * entities that produced it, with the quality-seal state shown so the CISO
     * knows what is trustworthy before signing." A CISO signing under BOFIA
     * 2020 — where false or misleading data is a regulatory breach — needs that
     * number before the signature, not after.
     */
    public function confidenceBand(): string
    {
        return match (true) {
            $this->evidence_confidence >= 80 => 'strong',
            $this->evidence_confidence >= 55 => 'adequate',
            $this->evidence_confidence >= 30 => 'weak',
            default => 'insufficient',
        };
    }

    public function tone(): string
    {
        return match ($this->state) {
            self::SIGNED, self::SUBMITTED => 'pass',
            self::ARCHIVED => 'closed',
            self::IN_REVIEW => 'in_review',
            self::IN_PREPARATION => 'in_progress',
            default => 'draft',
        };
    }
}
