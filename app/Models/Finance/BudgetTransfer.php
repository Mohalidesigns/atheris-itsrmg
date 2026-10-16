<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Services\Finance\DoaBandService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A virement: moves approved budget between lines, cost centres or periods, net zero (FIN-02.10). */
class BudgetTransfer extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'applied', 'withdrawn'];

    protected $table = 'fin_budget_transfers';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2', 'base_amount' => 'decimal:2',
        'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'applied_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function fromLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'from_line_id');
    }

    public function toLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'to_line_id');
    }

    public function toCostCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'to_cost_centre_id');
    }

    public function toCostPool(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'to_cost_pool_id');
    }

    public function toTower(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'to_tower_id');
    }

    public function toCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'to_category_id');
    }

    public function fromPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'from_period_id');
    }

    public function toPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'to_period_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }

    /** Target cost centre id: the target line's, else the new line's. */
    public function targetCostCentreId(): ?int
    {
        $id = $this->toLine?->cost_centre_id ?? $this->to_cost_centre_id;

        return $id === null ? null : (int) $id;
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, (string) $this->base_amount)['key'];
    }

    public function getCrossesCostCentreAttribute(): string
    {
        $to = $this->targetCostCentreId();

        return $to !== null && (int) $this->fromLine?->cost_centre_id !== $to ? 'yes' : 'no';
    }

    public function getCapexOpexAttribute(): string
    {
        $from = (bool) $this->fromLine?->is_capex;
        $to = $this->toLine ? (bool) $this->toLine->is_capex : ($this->toCostPool ? (bool) $this->toCostPool->default_capex : $from);

        return $from === $to ? ($from ? 'capex' : 'opex') : 'mixed';
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'transfer_code', 'VIR-');
    }
}
