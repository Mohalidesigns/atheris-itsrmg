<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** Signed, immutable ledger row posted by an applied transfer or supplementary request (plan C22). */
class BudgetAdjustment extends Model
{
    use FinanceRecord;

    public const SOURCES = ['transfer', 'supplementary'];

    protected $table = 'fin_budget_adjustments';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'base_amount' => 'decimal:2', 'applied_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['workflow' => 'Budget adjustments are immutable.']));
        static::deleting(fn () => throw ValidationException::withMessages(['workflow' => 'Budget adjustments are immutable.']));
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }
}
