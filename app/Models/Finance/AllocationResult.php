<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Computed allocation of one rule to one cost pool in one period (plan P6-6). */
class AllocationResult extends Model
{
    use FinanceRecord;

    protected $table = 'fin_allocation_results';

    protected $guarded = [];

    protected $casts = ['base_amount' => 'decimal:2', 'pool_base_amount' => 'decimal:2', 'share_pct' => 'decimal:6', 'computed_at' => 'datetime'];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class, 'allocation_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AllocationRun::class, 'run_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function costPool(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'cost_pool_id');
    }
}
