<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A what-if scenario over a draft budget: a global percentage and optional per-line overrides (FIN-02.07). */
class BudgetScenario extends Model
{
    use FinanceRecord;

    protected $table = 'fin_budget_scenarios';

    protected $guarded = [];

    protected $casts = [
        'line_adjustments' => 'array', 'is_promoted' => 'boolean', 'promoted_at' => 'datetime', 'adjustment_pct' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by_id');
    }
}
