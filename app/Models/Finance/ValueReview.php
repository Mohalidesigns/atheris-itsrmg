<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Services\Finance\FinanceClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A post-implementation value review scheduled after delivery (FIN-05.07). */
class ValueReview extends Model
{
    use FinanceRecord;

    public const TYPES = ['pir_3m', 'pir_6m', 'pir_12m', 'ad_hoc'];

    public const STATUSES = ['scheduled', 'due', 'overdue', 'completed', 'cancelled'];

    public const OPEN = ['scheduled', 'due', 'overdue'];

    public const COST_OUTCOMES = ['under', 'on', 'over'];

    public const TYPE_LABELS = ['pir_3m' => '3-month review', 'pir_6m' => '6-month review', 'pir_12m' => '12-month review', 'ad_hoc' => 'Ad hoc review'];

    protected $table = 'fin_value_reviews';

    protected $guarded = [];

    protected $casts = ['due_on' => 'date', 'completed_on' => 'date', 'benefits_realised_pct' => 'decimal:2'];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function getLabelAttribute(): string
    {
        $inv = Investment::withoutGlobalScopes()->whereKey($this->investment_id)->value('investment_code');

        return trim("{$inv} · ".(self::TYPE_LABELS[$this->review_type] ?? $this->review_type));
    }

    public function getDaysToDueAttribute(): ?int
    {
        return FinanceClock::daysUntil($this->due_on);
    }
}
