<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One accounting period of a fiscal year. */
class Period extends Model
{
    use FinanceRecord;

    public const STATUSES = ['open', 'closed', 'locked'];

    protected $table = 'fin_periods';

    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'closed_at' => 'datetime',
        'locked_at' => 'datetime',
        'sequence' => 'integer',
        'is_adjustment' => 'boolean',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    public function closes(): HasMany
    {
        return $this->hasMany(PeriodClose::class, 'period_id')->orderByDesc('id');
    }

    public function latestClose(): HasOne
    {
        return $this->hasOne(PeriodClose::class, 'period_id')->latestOfMany();
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function scopeForFiscalYear(Builder $q, int $id): Builder
    {
        return $q->where('fiscal_year_id', $id);
    }

    public function scopeContaining(Builder $q, CarbonInterface $date): Builder
    {
        return $q->where('is_adjustment', false)
            ->whereDate('starts_on', '<=', $date->toDateString())->whereDate('ends_on', '>=', $date->toDateString())
            ->orderBy('sequence')->orderBy('id');
    }
}
