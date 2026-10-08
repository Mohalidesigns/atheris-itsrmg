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

    /** Results can be recorded while planned (recording the first one starts the assessment) or in progress. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['planned', 'in_progress'], true);
    }

    /**
     * Leaf requirements of a framework — the assessable items. Domain headings (requirements
     * with children, e.g. ISO "A.5" or NDPA "NDPA-1") are structure, not something to assess.
     */
    public static function assessableRequirements(int $frameworkId)
    {
        return FrameworkRequirement::where('framework_id', $frameworkId)
            ->whereDoesntHave('children')
            ->orderBy('parent_id')->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /**
     * Score = (compliant + ½ partial) / assessed applicable requirements. Not-assessed items are
     * excluded so an in-progress score reflects what has been tested; progress is reported separately.
     */
    public function recalculateScore(): void
    {
        $results = $this->results()->get(['status']);
        $total = $results->count();
        $compliant = $results->where('status', 'compliant')->count();
        $partial = $results->where('status', 'partially_compliant')->count();
        $nonCompliant = $results->where('status', 'non_compliant')->count();
        $na = $results->where('status', 'not_applicable')->count();
        $assessedApplicable = $compliant + $partial + $nonCompliant;

        $this->update([
            'total_requirements' => $total,
            'compliant_count' => $compliant,
            'partial_count' => $partial,
            'non_compliant_count' => $nonCompliant,
            'not_applicable_count' => $na,
            'overall_score' => $assessedApplicable > 0 ? round((($compliant + ($partial * 0.5)) / $assessedApplicable) * 100, 2) : null,
        ]);
    }

    public function notAssessedCount(): int
    {
        return $this->results()->where('status', 'not_assessed')->count();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
