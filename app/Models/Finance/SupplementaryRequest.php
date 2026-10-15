<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Services\Finance\DoaBandService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A mid-year top-up request approved on top of the baseline (FIN-02.11). */
class SupplementaryRequest extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'applied', 'withdrawn'];

    protected $table = 'fin_supplementary_requests';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2', 'base_amount' => 'decimal:2', 'fx_rate' => 'decimal:8', 'is_capex' => 'boolean',
        'fx_rate_date' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'applied_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function costPool(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'cost_pool_id');
    }

    public function tower(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'tower_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function targetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'target_line_id');
    }

    public function resultingLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'resulting_line_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, (string) $this->base_amount)['key'];
    }

    public function getCapexOpexAttribute(): string
    {
        return $this->is_capex ? 'capex' : 'opex';
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'request_code', 'SUP-');
    }
}
