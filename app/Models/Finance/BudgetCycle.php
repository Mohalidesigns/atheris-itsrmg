<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A budget or forecast cycle with an open window and a coordinator (ATH-FIN-001 FIN-02.01). */
class BudgetCycle extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const TYPES = ['annual', 'mid_year_reforecast', 'rolling_forecast', 'supplementary'];

    public const STATUSES = ['planned', 'open', 'in_review', 'closed'];

    protected $table = 'fin_budget_cycles';

    protected $guarded = [];

    protected $casts = ['opens_on' => 'date', 'closes_on' => 'date'];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class, 'cycle_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(BudgetTarget::class, 'cycle_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function scopeForFiscalYear(Builder $q, int $id): Builder
    {
        return $q->where('fiscal_year_id', $id);
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'cycle_code', 'BC-');
    }
}
