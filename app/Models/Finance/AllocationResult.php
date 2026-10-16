<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Computed allocation of one rule in one period (P6). */
class AllocationResult extends Model
{
    use FinanceRecord;

    protected $table = 'fin_allocation_results';

    protected $guarded = [];

    protected $casts = ['base_amount' => 'decimal:2', 'computed_at' => 'datetime'];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class, 'allocation_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }
}
