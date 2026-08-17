<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class RiskAssessment extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'risk_id', 'assessed_by', 'methodology',
        'assessment_type', 'likelihood', 'impact', 'score', 'rating',
        'fair_tef', 'fair_vul', 'fair_lef', 'fair_plm', 'fair_slm',
        'fair_ale', 'fair_currency',
        'impact_financial', 'impact_operational', 'impact_reputational',
        'impact_regulatory', 'impact_safety',
        'justification', 'notes', 'assessment_date', 'next_review_date',
    ];

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'next_review_date' => 'date',
            'fair_tef' => 'decimal:4',
            'fair_vul' => 'decimal:4',
            'fair_lef' => 'decimal:4',
            'fair_plm' => 'decimal:2',
            'fair_slm' => 'decimal:2',
            'fair_ale' => 'decimal:2',
        ];
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
