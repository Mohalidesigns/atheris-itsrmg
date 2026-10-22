<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An accrual or a prepayment release for a period (FIN-03.06). */
class Accrual extends Model
{
    use FinanceRecord;

    public const TYPES = ['accrual', 'prepayment_release'];

    public const SOURCES = ['po', 'recurring', 'prepayment', 'manual'];

    public const STATUSES = ['proposed', 'posted', 'reversed', 'cancelled'];

    protected $table = 'fin_accruals';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'base_amount' => 'decimal:2', 'fx_rate' => 'decimal:8', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function reversalPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'reversal_period_id');
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
