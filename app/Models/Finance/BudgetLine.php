<?php

namespace App\Models\Finance;

use App\Models\Asset;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\LegalEntity;
use App\Models\Risk;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/** One planned spend line of a budget version (ATH-FIN-001 §4.2): TBM pool x tower, phased by period. */
class BudgetLine extends Model
{
    use FinanceRecord;

    public const PRIORITIES = ['mandatory', 'regulatory', 'committed', 'discretionary'];

    public const RGT = ['run', 'grow', 'transform'];

    public const PHASING = ['equal', 'custom', 'front', 'back', 'seasonal'];

    public const ORIGINS = ['planned', 'supplementary', 'transfer'];

    /** True only while a supplementary request or transfer appends a zero-original line to a baseline (C22). */
    public static bool $allowLockedAppend = false;

    protected $table = 'fin_budget_lines';

    protected $guarded = [];

    protected $casts = [
        'is_capex' => 'boolean', 'is_recurring' => 'boolean', 'fx_rate_date' => 'date',
        'annual_amount' => 'decimal:2', 'base_annual_amount' => 'decimal:2', 'unit_cost' => 'decimal:2',
        'fx_rate' => 'decimal:8', 'quantity' => 'decimal:4', 'driver_rate' => 'decimal:4',
        'py_budget' => 'decimal:2', 'py_actual' => 'decimal:2',
        'variance_amber_pct' => 'decimal:2', 'variance_red_pct' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (BudgetLine $l) {
            if ($l->parentIsLocked() && ! (self::$allowLockedAppend && ! $l->exists)) {
                throw ValidationException::withMessages(['workflow' => 'This budget version is locked; edit it through a new version.']);
            }
        });
        static::deleting(function (BudgetLine $l) {
            if ($l->parentIsLocked()) {
                throw ValidationException::withMessages(['workflow' => 'This budget version is locked; edit it through a new version.']);
            }
        });
    }

    public function parentIsLocked(): bool
    {
        $status = Budget::withoutGlobalScopes()->whereKey($this->budget_id)->value('status');

        return in_array($status, Budget::LOCKED, true);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BudgetLinePeriod::class, 'budget_line_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BudgetAdjustment::class, 'budget_line_id');
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

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'risk_id');
    }

    public function eaApplication(): BelongsTo
    {
        return $this->belongsTo(EaApplication::class, 'ea_application_id');
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'business_service_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function sourceLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_line_id');
    }

    public function getAdjustmentsBaseAttribute(): string
    {
        return bcadd((string) BudgetAdjustment::withoutGlobalScopes()->where('budget_line_id', $this->id)->sum('base_amount'), '0', 2);
    }

    public function getCurrentBaseAmountAttribute(): string
    {
        return bcadd((string) $this->base_annual_amount, $this->adjustments_base, 2);
    }

    public function getYoyPctAttribute(): ?string
    {
        if ($this->py_budget === null || bccomp((string) $this->py_budget, '0', 2) === 0) {
            return null;
        }
        $delta = bcsub((string) $this->base_annual_amount, (string) $this->py_budget, 6);

        return bcmul(bcdiv($delta, (string) $this->py_budget, 8), '100', 2);
    }
}
