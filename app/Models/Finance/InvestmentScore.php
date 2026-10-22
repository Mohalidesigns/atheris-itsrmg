<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One criterion score of an investment, with the weight in force when it was scored (P5-10). */
class InvestmentScore extends Model
{
    use FinanceRecord;

    protected $table = 'fin_investment_scores';

    protected $guarded = [];

    protected $casts = ['weight_pct' => 'decimal:2', 'weighted' => 'decimal:2', 'score' => 'integer'];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function scoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scored_by_id');
    }
}
