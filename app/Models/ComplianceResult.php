<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
