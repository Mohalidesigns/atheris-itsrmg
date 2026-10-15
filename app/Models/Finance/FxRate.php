<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;

/** A dated exchange rate (ATH-FIN-001 FIN-01.05). */
class FxRate extends Model
{
    use FinanceRecord;

    public const SOURCES = ['manual', 'import', 'provider'];

    protected $table = 'fin_fx_rates';

    protected $guarded = [];

    protected $casts = [
        'rate' => 'decimal:8',
        'rate_date' => 'date',
    ];
}
