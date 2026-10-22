<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;

/** A dated exchange rate (ATH-FIN-001 FIN-01.05). */
class FxRate extends Model
{
    use FinanceRecord;

    public const SOURCES = ['manual', 'import', 'provider'];

    /** Mirrors config('finance.fx.rate_types'); a constant so it is usable in static contexts. */
    public const RATE_TYPES = ['spot', 'average', 'closing', 'budget'];

    protected $table = 'fin_fx_rates';

    protected $guarded = [];

    protected $casts = [
        'rate' => 'decimal:8',
        'rate_date' => 'date',
    ];
}
