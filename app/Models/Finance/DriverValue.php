<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The value of a driver in one period. */
class DriverValue extends Model
{
    use FinanceRecord;

    protected $table = 'fin_driver_values';

    protected $guarded = [];

    protected $casts = ['value' => 'decimal:4'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }
}
