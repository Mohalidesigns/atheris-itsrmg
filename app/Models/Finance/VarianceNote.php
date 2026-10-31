<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\Issue;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;

/** The variance of one budget line at the end of one period (YTD basis, plan P4-1), with its explanation, corrective action and acknowledgement. */
class VarianceNote extends Model
{
    use FinanceRecord;

    public const SEVERITIES = ['amber', 'red'];

    public const DIRECTIONS = ['over', 'under'];

    public const STATUSES = ['open', 'explained', 'acknowledged', 'cleared'];

    public const NEEDS_EXPLANATION = ['open'];

    protected $table = 'fin_variance_notes';

    protected $guarded = [];

    protected $casts = [
        'budget_amount' => 'decimal:2', 'actual_amount' => 'decimal:2', 'variance_amount' => 'decimal:2', 'variance_pct' => 'decimal:2',
        'period_variance_amount' => 'decimal:2', 'is_breaching' => 'boolean', 'explained_at' => 'datetime', 'acknowledged_at' => 'datetime', 'evaluated_at' => 'datetime',
    ];

    /** `evaluated_at` is rewritten by every nightly evaluation; logging it would add a row per note per night. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['created_at', 'updated_at', 'deleted_at', 'evaluated_at'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('it-finance');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(VarianceComment::class, 'variance_note_id')->orderBy('id');
    }

    public function getLabelAttribute(): string
    {
        $line = $this->line;
        $code = $line?->relationLoaded('budget') ? $line->budget?->budget_code : Budget::withoutGlobalScopes()->whereKey($line?->budget_id)->value('budget_code');

        return trim("{$this->period?->name} · {$code} · {$line?->description}", ' ·');
    }

    /** Red notes (and amber ones when the bank asks for it) that nobody has explained yet. */
    public function getNeedsExplanationAttribute(): bool
    {
        return in_array($this->status, self::NEEDS_EXPLANATION, true) && $this->is_breaching
            && ($this->severity === 'red' || (bool) config('finance.monitoring.explain_amber_at_close'));
    }

    public function scopeForPeriod(Builder $q, int $id): Builder
    {
        return $q->where('period_id', $id);
    }

    public function scopeForFiscalYear(Builder $q, int $id): Builder
    {
        return $q->where('fiscal_year_id', $id);
    }

    public function scopeRed(Builder $q): Builder
    {
        return $q->where('severity', 'red');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);

        return $ids === null ? $q : $q->whereIn('cost_centre_id', $ids ?: [0]);
    }
}
