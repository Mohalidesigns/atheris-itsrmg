<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month of a capitalisation's memo amortisation schedule (plan P6-13). */
class AmortisationLine extends Model
{
    use FinanceRecord;

    protected $table = 'fin_amortisation_lines';

    protected $guarded = [];

    protected $casts = ['month' => 'date', 'opening_nbv' => 'decimal:2', 'amount' => 'decimal:2', 'closing_nbv' => 'decimal:2', 'is_catch_up' => 'boolean'];

    public function capitalisation(): BelongsTo
    {
        return $this->belongsTo(Capitalisation::class, 'capitalisation_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }
}
