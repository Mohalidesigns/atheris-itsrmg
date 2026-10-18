<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One measurement of a benefit in a fiscal period, optionally with a supporting document. */
class BenefitReading extends Model
{
    use FinanceRecord;

    protected $table = 'fin_benefit_readings';

    protected $guarded = [];

    protected $casts = ['value' => 'decimal:2'];

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(Benefit::class, 'benefit_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FinanceDocument::class, 'document_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
