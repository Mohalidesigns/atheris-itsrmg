<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\LegalEntity;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\FinanceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An IT programme, project or initiative moving through the stage gates idea to closed (ATH-FIN-001 §3.5, §4.5). */
class Investment extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const TYPES = ['programme', 'project', 'initiative'];

    public const RGT = ['run', 'grow', 'transform'];

    public const DRIVERS = ['regulatory', 'strategic', 'risk_reduction', 'efficiency', 'growth', 'obsolescence'];

    public const STAGES = ['idea', 'business_case', 'approved', 'in_delivery', 'delivered', 'benefits_tracking', 'closed', 'cancelled'];

    public const OPEN_STAGES = ['idea', 'business_case', 'approved', 'in_delivery', 'delivered', 'benefits_tracking'];

    public const STATUSES = ['on_track', 'at_risk', 'off_track', 'on_hold'];

    public const CAPITALISATION_STANDARDS = ['ias_38', 'ias_16'];

    public const CRITERIA = ['strategic_alignment', 'regulatory', 'risk_reduction', 'financial_return', 'delivery_risk'];

    protected $table = 'fin_investments';

    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date', 'planned_ends_on' => 'date', 'actual_ends_on' => 'date', 'percent_complete_as_of' => 'date',
        'planned_cost' => 'decimal:2', 'bac' => 'decimal:2', 'eac' => 'decimal:2', 'percent_complete' => 'decimal:2', 'cost_tolerance_pct' => 'decimal:2',
        'priority_score' => 'decimal:2', 'is_regulatory' => 'boolean', 'value_review_dates' => 'array',
        'bac_baselined_at' => 'datetime', 'cancelled_at' => 'datetime', 'bac_baseline_amount' => 'decimal:2', 'is_capitalisable' => 'boolean',
    ];

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function businessCases(): HasMany
    {
        return $this->hasMany(BusinessCase::class, 'investment_id')->orderByDesc('version_no');
    }

    public function currentBusinessCase(): HasOne
    {
        return $this->hasOne(BusinessCase::class, 'investment_id')->where('status', '!=', 'superseded')->orderByDesc('version_no');
    }

    public function approvedBusinessCase(): HasOne
    {
        return $this->hasOne(BusinessCase::class, 'investment_id')->where('status', 'approved')->orderByDesc('version_no');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(InvestmentScore::class, 'investment_id');
    }

    public function gates(): HasMany
    {
        return $this->hasMany(StageGate::class, 'investment_id')->orderByDesc('id');
    }

    public function pendingGate(): HasOne
    {
        return $this->hasOne(StageGate::class, 'investment_id')->where('status', 'submitted')->latestOfMany();
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(Benefit::class, 'investment_id');
    }

    public function valueReviews(): HasMany
    {
        return $this->hasMany(ValueReview::class, 'investment_id')->orderBy('due_on');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(InvestmentChange::class, 'investment_id')->orderByDesc('id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(InvestmentLink::class, 'investment_id');
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'investment_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(FinanceDocument::class, 'documentable');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('stage', self::OPEN_STAGES);
    }

    public function scopeForFiscalYear(Builder $q, int $id): Builder
    {
        return $q->where('fiscal_year_id', $id);
    }

    /** Row visibility (plan §2.5): every investment for users who see all cost centres or decide investment gates, else their cost centres' and their own. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);
        if ($ids === null || $user->can('approve it-finance-investments')) {
            return $q;
        }
        $id = (int) $user->id;

        return $q->where(function (Builder $w) use ($ids, $id) {
            $w->whereIn('cost_centre_id', $ids ?: [0])->orWhere('manager_id', $id)->orWhere('sponsor_id', $id)->orWhere('created_by_id', $id)
                ->orWhereIn('id', Benefit::withoutGlobalScopes()->where('owner_id', $id)->select('investment_id'))
                ->orWhereIn('id', ValueReview::withoutGlobalScopes()->where('reviewer_id', $id)->select('investment_id'));
        });
    }

    public function getLabelAttribute(): string
    {
        return trim("{$this->investment_code} {$this->title}");
    }

    public function getIsOpenAttribute(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }

    public function getDaysToPlannedEndAttribute(): ?int
    {
        return FinanceClock::daysUntil($this->planned_ends_on);
    }

    public function getIsLateAttribute(): bool
    {
        return in_array($this->stage, ['approved', 'in_delivery'], true) && $this->planned_ends_on !== null
            && $this->planned_ends_on->toDateString() < FinanceClock::today()->toDateString();
    }

    public function involves(User $user): bool
    {
        $id = (int) $user->id;
        if (in_array($id, array_map('intval', array_filter([$this->manager_id, $this->sponsor_id, $this->created_by_id])), true)) {
            return true;
        }

        return Benefit::withoutGlobalScopes()->where('investment_id', $this->id)->where('owner_id', $id)->exists()
            || ValueReview::withoutGlobalScopes()->where('investment_id', $this->id)->where('reviewer_id', $id)->exists();
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'investment_code', 'IVT-');
    }
}
