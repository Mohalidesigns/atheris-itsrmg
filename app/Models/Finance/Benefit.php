<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A benefit an investment promises, measured by periodic readings against a target (FIN-05.06). */
class Benefit extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const TYPES = ['cost_saving', 'cost_avoidance', 'revenue', 'risk_reduction', 'compliance', 'productivity', 'customer'];

    public const UNITS = ['currency', 'pct', 'hours', 'count', 'score'];

    public const AGGREGATIONS = ['sum', 'latest'];

    public const STATUSES = ['planned', 'tracking', 'realised', 'partially_realised', 'not_realised', 'cancelled'];

    public const FINAL = ['realised', 'partially_realised', 'not_realised', 'cancelled'];

    protected $table = 'fin_benefits';

    protected $guarded = [];

    protected $casts = [
        'baseline_value' => 'decimal:2', 'target_value' => 'decimal:2', 'realised_value' => 'decimal:2', 'realised_pct' => 'decimal:2', 'due_on' => 'date',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(BenefitReading::class, 'benefit_id')->orderBy('id');
    }

    public function lastReadingPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'last_reading_period_id');
    }

    public function getIsMoneyAttribute(): bool
    {
        return $this->unit === 'currency';
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'benefit_code', 'BEN-');
    }
}
