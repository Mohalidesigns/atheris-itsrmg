<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ComplianceResult extends Model
{
    protected $fillable = [
        'assessment_id', 'requirement_id', 'control_id',
        'status', 'findings', 'recommendations',
        'assessed_by', 'assessed_at',
    ];

    protected function casts(): array
    {
        return ['assessed_at' => 'date'];
    }

    public const STATUSES = [
        'compliant', 'partially_compliant', 'non_compliant',
        'not_applicable', 'not_assessed',
    ];

    /** Results that open (or keep open) a remediation gap, with the gap severity they imply. */
    public const GAP_SEVERITY = ['non_compliant' => 'high', 'partially_compliant' => 'medium'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ComplianceAssessment::class, 'assessment_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'requirement_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'evidenceable');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
