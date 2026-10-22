<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** The phased amount of one budget line in one period (line currency and base currency). */
class BudgetLinePeriod extends Model
{
    use FinanceRecord;

    protected $table = 'fin_budget_line_periods';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'base_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        $guard = function (BudgetLinePeriod $p, bool $creating) {
            $line = BudgetLine::withoutGlobalScopes()->find($p->budget_line_id);
            if ($line && $line->parentIsLocked() && ! (BudgetLine::$allowLockedAppend && $creating)) {
                throw ValidationException::withMessages(['workflow' => 'This budget version is locked; edit it through a new version.']);
            }
        };
        static::saving(fn (BudgetLinePeriod $p) => $guard($p, ! $p->exists));
        static::deleting(fn (BudgetLinePeriod $p) => $guard($p, false));
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }
}
