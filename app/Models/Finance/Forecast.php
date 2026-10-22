<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A manual forecast override of a baseline line, or the EAC snapshot taken when a period closed (plan P4-7, C117). The live EAC is computed. */
class Forecast extends Model
{
    use FinanceRecord;

    public const METHODS = ['remaining_budget', 'run_rate', 'manual'];

    protected $table = 'fin_forecasts';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'base_amount' => 'decimal:2', 'is_snapshot' => 'boolean', 'cleared_at' => 'datetime'];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }

    public function scopeActiveOverride(Builder $q): Builder
    {
        return $q->where('method', 'manual')->whereNull('cleared_at')->where('is_snapshot', false);
    }
}
