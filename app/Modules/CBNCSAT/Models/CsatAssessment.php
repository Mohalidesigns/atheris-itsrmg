<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CsatAssessment extends Model
{
    use SoftDeletes;

    protected $table = 'csat_assessments';

    protected $fillable = [
        'organization_id', 'assessment_year', 'status', 'submission_deadline',
        'submitted_at', 'framework_version', 'composite_risk_level', 'composite_risk_score',
        'overall_maturity_level', 'ai_readiness_score', 'ai_readiness_rag',
        'prior_assessment_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'submission_deadline' => 'date',
            'submitted_at' => 'datetime',
            'composite_risk_score' => 'decimal:3',
            'assessment_year' => 'integer',
            'ai_readiness_score' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function priorAssessment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_assessment_id');
    }

    public function institutionProfile(): HasOne
    {
        return $this->hasOne(CsatInstitutionProfile::class, 'assessment_id');
    }

    public function stakeholderEngagement(): HasMany
    {
        return $this->hasMany(CsatStakeholderEngagement::class, 'assessment_id');
    }

    public function irResponses(): HasMany
    {
        return $this->hasMany(CsatIrResponse::class, 'assessment_id');
    }

    public function irCategoryScores(): HasMany
    {
        return $this->hasMany(CsatIrCategoryScore::class, 'assessment_id');
    }

    public function irNarratives(): HasMany
    {
        return $this->hasMany(CsatIrNarrative::class, 'assessment_id');
    }

    public function maResponses(): HasMany
    {
        return $this->hasMany(CsatMaResponse::class, 'assessment_id');
    }

    public function maScores(): HasMany
    {
        return $this->hasMany(CsatMaScore::class, 'assessment_id');
    }

    public function maNarratives(): HasMany
    {
        return $this->hasMany(CsatMaNarrative::class, 'assessment_id');
    }

    public function threats(): HasMany
    {
        return $this->hasMany(CsatThreat::class, 'assessment_id');
    }

    public function vulnerabilities(): HasMany
    {
        return $this->hasMany(CsatVulnerability::class, 'assessment_id');
    }

    public function approvalRecords(): HasMany
    {
        return $this->hasMany(CsatApprovalRecord::class, 'assessment_id');
    }

    public function aiRecommendations(): HasMany
    {
        return $this->hasMany(CsatAiRecommendation::class, 'assessment_id');
    }

    public function sectionAssignments(): HasMany
    {
        return $this->hasMany(CsatSectionAssignment::class, 'assessment_id');
    }
}
