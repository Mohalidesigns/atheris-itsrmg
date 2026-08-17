<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ComplianceAssessment extends Model
{
    use BelongsToOrganization, LogsActivity;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'framework_id', 'title', 'description',
        'status', 'lead_assessor_id', 'start_date', 'end_date', 'due_date',
        'overall_score', 'total_requirements', 'compliant_count',
        'partial_count', 'non_compliant_count', 'not_applicable_count',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'due_date' => 'date',
            'overall_score' => 'decimal:2',
        ];
    }

    public const STATUSES = ['planned', 'in_progress', 'completed', 'cancelled'];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ControlFramework::class, 'framework_id');
    }

    public function leadAssessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_assessor_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ComplianceResult::class, 'assessment_id');
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(Gap::class, 'assessment_id');
    }

    public function recalculateScore(): void
    {
        $results = $this->results()->get();
        $total = $results->count();
        $compliant = $results->where('status', 'compliant')->count();
        $partial = $results->where('status', 'partially_compliant')->count();
        $nonCompliant = $results->where('status', 'non_compliant')->count();
        $na = $results->where('status', 'not_applicable')->count();
        $applicable = $total - $na;

        $this->update([
            'total_requirements' => $total,
            'compliant_count' => $compliant,
            'partial_count' => $partial,
            'non_compliant_count' => $nonCompliant,
            'not_applicable_count' => $na,
            'overall_score' => $applicable > 0 ? round((($compliant + ($partial * 0.5)) / $applicable) * 100, 2) : 0,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
