<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Risk extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity, SoftDeletes;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'risk_id_code', 'title', 'description',
        'category_id', 'risk_owner_id', 'created_by', 'status',
        'inherent_likelihood', 'inherent_impact', 'inherent_score', 'inherent_rating',
        'residual_likelihood', 'residual_impact', 'residual_score', 'residual_rating',
        'fair_annual_loss_expectancy', 'fair_single_loss_expectancy',
        'treatment_strategy', 'treatment_due_date', 'risk_appetite',
        'source', 'tags', 'review_date', 'accepted_until',
        'acceptance_justification', 'accepted_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'treatment_due_date' => 'date',
            'review_date' => 'date',
            'accepted_until' => 'date',
            'fair_annual_loss_expectancy' => 'decimal:2',
            'fair_single_loss_expectancy' => 'decimal:2',
        ];
    }

    /** Lifecycle order matters: the register filter and edit form list them in this order. */
    public const STATUSES = [
        'identified', 'assessed', 'treating', 'mitigated', 'accepted', 'under_review', 'closed', 'archived',
    ];

    /** Statuses that take a risk off the active register (heat maps, KPIs, top-N). */
    public const INACTIVE_STATUSES = ['closed', 'archived'];

    /**
     * The single 5×5 rating scale used everywhere (backend, seeders, dashboards).
     * Mirrored in resources/js/Utils/risk.js — keep the two in sync.
     */
    public const RATINGS = [
        'critical' => ['min' => 20, 'color' => '#C53030'],
        'high' => ['min' => 12, 'color' => '#DD6B20'],
        'medium' => ['min' => 6, 'color' => '#D4AF37'],
        'low' => ['min' => 3, 'color' => '#2D7D46'],
        'very_low' => ['min' => 1, 'color' => '#319795'],
    ];

    public const TREATMENT_STRATEGIES = ['accept', 'mitigate', 'transfer', 'avoid'];

    /** Position of the risk relative to the board-approved appetite. */
    public const APPETITES = ['within', 'above', 'below'];

    /** Where the risk was identified. 'ea.obsolescence' is raised by the EA module (OpenObsolescenceRisk). */
    public const SOURCES = ['audit', 'self-assessment', 'incident', 'regulator', 'external', 'ea.obsolescence'];

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', self::INACTIVE_STATUSES);
    }

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'risk_owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class);
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(RiskTreatment::class);
    }

    public function scoreHistory(): HasMany
    {
        return $this->hasMany(RiskScoreHistory::class);
    }

    public function threatAssessments(): HasMany
    {
        return $this->hasMany(ThreatAssessment::class);
    }

    /** Remediation issues raised against this risk (issues.source_type = 'risk'). */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'source_id')->where('source_type', 'risk');
    }

    public function fairScenarios(): HasMany
    {
        return $this->hasMany(FairScenario::class);
    }

    /**
     * Controls mitigating this risk (inverse of Control::risks()).
     */
    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class, 'risk_controls')
            ->withPivot('effectiveness', 'notes')
            ->withTimestamps();
    }

    /**
     * Assets affected by this risk.
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_risk')
            ->withPivot('notes')
            ->withTimestamps();
    }

    // Helpers
    public static function calculateRating(int $score): string
    {
        foreach (self::RATINGS as $rating => $config) {
            if ($score >= $config['min']) {
                return $rating;
            }
        }

        return 'very_low';
    }

    /**
     * Next register code, keeping the tenant's existing prefix and padding
     * (e.g. KHB-RSK-040 → KHB-RSK-041). Includes soft-deleted rows so a code
     * is never reused.
     */
    public static function generateNextCode(int $organizationId): string
    {
        $codes = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->pluck('risk_id_code');

        $prefix = 'RSK-';
        $width = 4;
        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/^(.*RSK-)(\d+)$/', (string) $code, $m) && (int) $m[2] >= $max) {
                $max = (int) $m[2];
                $prefix = $m[1];
                $width = strlen($m[2]);
            }
        }

        return $prefix.str_pad($max + 1, $width, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
