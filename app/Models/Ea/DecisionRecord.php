<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * DecisionRecord — B4, Architecture Decision Records.
 *
 * ATH-EAR-002 §5.4: "Forrester names ADRs a defining capability; **Orbus has
 * none**, Ardoq has 'Architecture Records'. Cheap to build, immediately
 * demoable, and it is the artefact CBN's governance section asks for." §4 row
 * 32 scores Atheris 0 and Orbus 0 — a competitor at zero on a capability the
 * analysts name is the cheapest lead in the matrix.
 *
 * An accepted ADR is never edited into a different decision: it is superseded
 * by a new one, so the reasoning history survives. That is the whole value of
 * the artefact in an assessment.
 */
class DecisionRecord extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_decision_records';

    protected $guarded = [];

    protected $casts = [
        'decided_on' => 'date',
        'linked_entities' => 'array',
        'impacted_principles' => 'array',
        'impacted_standards' => 'array',
    ];

    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    public const DEPRECATED = 'deprecated';

    public const STATUSES = [
        self::PROPOSED,
        self::ACCEPTED,
        self::REJECTED,
        self::SUPERSEDED,
        self::DEPRECATED,
    ];

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy()
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    public function arbSubmission()
    {
        return $this->belongsTo(ArbSubmission::class, 'arb_submission_id');
    }

    public function initiative()
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, [self::PROPOSED, self::ACCEPTED], true);
    }

    public function tone(): string
    {
        return match ($this->status) {
            self::ACCEPTED => 'pass',
            self::REJECTED => 'fail',
            self::SUPERSEDED, self::DEPRECATED => 'closed',
            default => 'draft',
        };
    }

    /** Walk the supersession chain back to the original decision. */
    public function lineage(): array
    {
        $chain = [];
        $node = $this->supersedes;
        $guard = 0;
        while ($node && $guard++ < 20) {
            $chain[] = $node;
            $node = $node->supersedes;
        }

        return $chain;
    }
}
