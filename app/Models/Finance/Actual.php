<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** One posted actual (ATH-FIN-001 §4.3). An immutable ledger row: only its budget-line match can change. */
class Actual extends Model
{
    use FinanceRecord;

    public const SOURCES = ['invoice', 'gl_import', 'manual', 'accrual', 'accrual_reversal', 'prepayment_release', 'cloud_import', 'adjustment'];

    public const MATCH_STATUSES = ['matched', 'unmatched', 'unbudgeted', 'duplicate'];

    public const MATCH_FIELDS = ['budget_line_id', 'match_status', 'match_reason', 'match_rule_id', 'matched_by_id', 'matched_at', 'duplicate_of_id', 'updated_at'];

    protected $table = 'fin_actuals';

    protected $guarded = [];

    protected $casts = [
        'posted_on' => 'date', 'fx_rate_date' => 'date', 'matched_at' => 'datetime', 'amount' => 'decimal:2', 'base_amount' => 'decimal:2',
        'fx_rate' => 'decimal:8', 'is_capex' => 'boolean', 'is_lease' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (Actual $a) {
            $bad = array_diff(array_keys($a->getDirty()), self::MATCH_FIELDS);
            if ($bad) {
                throw ValidationException::withMessages(['workflow' => 'A posted actual is immutable; only its budget-line match can change ('.implode(', ', $bad).').']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['workflow' => 'A posted actual cannot be deleted; post a reversal.']));
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function matchRule(): BelongsTo
    {
        return $this->belongsTo(MatchRule::class, 'match_rule_id');
    }

    public function scopeCounted(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.match_status', '!=', 'duplicate');
    }

    public function scopeForPeriod(Builder $q, int $id): Builder
    {
        return $q->where('period_id', $id);
    }

    public function scopeForFiscalYear(Builder $q, int $id): Builder
    {
        return $q->where('fiscal_year_id', $id);
    }
}
