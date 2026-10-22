<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\Vendor;
use App\Services\Finance\FinanceClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A recurring expense (FIN-03.07) that generates an expected invoice or an accrual on each due date. */
class RecurringExpense extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const FREQUENCIES = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6, 'annual' => 12];

    public const AUTO_GENERATE = ['expected_invoice', 'accrual', 'none'];

    protected $table = 'fin_recurring_expenses';

    protected $guarded = [];

    protected $casts = [
        'starts_on' => 'date', 'next_due_on' => 'date', 'ends_on' => 'date', 'last_generated_on' => 'date', 'amount' => 'decimal:2', 'is_active' => 'boolean',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class, 'tax_type_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'recurring_expense_id');
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(Accrual::class, 'recurring_expense_id');
    }

    public function getDaysToDueAttribute(): ?int
    {
        return FinanceClock::daysUntil($this->next_due_on);
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'recurring_code', 'REC-');
    }
}
