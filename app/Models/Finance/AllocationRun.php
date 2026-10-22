<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One computation of the allocation results of a period, amount type and lens (plan P6-8). */
class AllocationRun extends Model
{
    use FinanceRecord;

    protected $table = 'fin_allocation_runs';

    protected $guarded = [];

    protected $casts = [
        'pool_total' => 'decimal:2', 'allocated_total' => 'decimal:2', 'direct_total' => 'decimal:2', 'it_total' => 'decimal:2', 'computed_at' => 'datetime', 'is_frozen' => 'boolean',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AllocationResult::class, 'run_id');
    }

    public function computedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by_id');
    }
}
