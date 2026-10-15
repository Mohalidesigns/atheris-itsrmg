<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A fiscal year and its accounting periods (ATH-FIN-001 FIN-01.01). */
class FiscalYear extends Model
{
    use FinanceRecord, SoftDeletes;

    public const STATUSES = ['planning', 'open', 'closed'];

    public const PERIOD_SCHEMES = ['monthly', 'monthly_13', '4-4-5'];

    protected $table = 'fin_fiscal_years';

    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_current' => 'boolean',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class, 'fiscal_year_id')->orderBy('sequence');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class, 'fiscal_year_id');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(BudgetCycle::class, 'fiscal_year_id');
    }

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('is_current', true);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }
}
