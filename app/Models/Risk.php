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

    public const STATUSES = [
        'identified', 'assessed', 'treating', 'accepted', 'closed', 'archived',
    ];

    public const RATINGS = [
        'critical' => ['min' => 20, 'color' => '#C53030'],
        'high' => ['min' => 15, 'color' => '#DD6B20'],
        'medium' => ['min' => 8, 'color' => '#D4AF37'],
        'low' => ['min' => 4, 'color' => '#2D7D46'],
        'very_low' => ['min' => 1, 'color' => '#319795'],
    ];

    public const TREATMENT_STRATEGIES = ['accept', 'mitigate', 'transfer', 'avoid'];

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

    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('risk_id_code');

        if ($last && preg_match('/RSK-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'RSK-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
