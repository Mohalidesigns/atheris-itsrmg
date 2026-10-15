<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An allocation rule (ATH-FIN-001 §4.2). Tables only in P1; execution is P6. */
class Allocation extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const SOURCE_TYPES = ['cost_centre', 'category', 'budget_line', 'asset'];

    public const TARGET_TYPES = ['cost_centre', 'business_service', 'legal_entity', 'application'];

    public const METHODS = ['fixed_pct', 'driver', 'equal'];

    public const AMOUNT_TYPES = ['budget', 'actual', 'forecast'];

    protected $table = 'fin_allocations';

    protected $guarded = [];

    protected $casts = ['percentage' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AllocationResult::class, 'allocation_id');
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'allocation_code', 'ALC-');
    }
}
