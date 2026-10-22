<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cost driver (headcount, seats, transactions ...) used to allocate showback (ATH-FIN-001 FIN-01.10). */
class Driver extends Model
{
    use FinanceRecord;

    protected $table = 'fin_drivers';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function values(): HasMany
    {
        return $this->hasMany(DriverValue::class, 'driver_id');
    }
}
