<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A vendor invoice (FIN-03.04): three-way matched, approved, then posted as an actual. */
class Invoice extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'scheduled', 'paid', 'disputed', 'cancelled'];

    public const MATCH_STATUSES = ['unmatched', 'matched', 'variance', 'override'];

    public const UNPAID = ['submitted', 'approved', 'scheduled', 'disputed'];

    protected $table = 'fin_invoices';

    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date', 'due_on' => 'date', 'received_on' => 'date', 'fx_rate_date' => 'date', 'recurring_due_on' => 'date',
        'scheduled_on' => 'date', 'paid_on' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'matched_at' => 'datetime',
        'match_override_at' => 'datetime', 'disputed_at' => 'datetime', 'match_detail' => 'array',
        'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'wht_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'base_total_amount' => 'decimal:2',
        'match_variance_amount' => 'decimal:2', 'match_variance_base' => 'decimal:2', 'fx_rate' => 'decimal:8',
        'is_expected' => 'boolean', 'is_capex' => 'boolean', 'is_lease' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function expenseRequest(): BelongsTo
    {
        return $this->belongsTo(ExpenseRequest::class, 'expense_request_id');
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class, 'tax_type_id');
    }

    public function whtTaxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class, 'wht_tax_type_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id')->orderBy('line_no');
    }

    public function actual(): HasOne
    {
        return $this->hasOne(Actual::class, 'invoice_id')->where('source', 'invoice');
    }

    public function prepayments(): HasMany
    {
        return $this->hasMany(Accrual::class, 'invoice_id')->where('type', 'prepayment_release')->orderBy('period_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(FinanceDocument::class, 'documentable');
    }

    public function matchOverrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'match_override_by_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);
        if ($ids === null || $user->hasRole('Procurement Officer')) {
            return $q;
        }
        $t = $q->getModel()->getTable();

        return $q->where(fn ($w) => $w->whereIn("{$t}.cost_centre_id", $ids ?: [0])->orWhere("{$t}.submitted_by_id", $user->id)->orWhere("{$t}.created_by_id", $user->id));
    }

    /** @return list<int> */
    public function sodExcludedIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter([$this->submitted_by_id, $this->created_by_id, $this->match_override_by_id, $this->expenseRequesterId()]))));
    }

    private function expenseRequesterId(): ?int
    {
        return $this->expense_request_id ? ExpenseRequest::withoutGlobalScopes()->whereKey($this->expense_request_id)->value('requested_by_id') : null;
    }

    public function approvalBaseAmount(): string
    {
        return (string) $this->base_total_amount;
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['key'];
    }

    public function getAmountBandLabelAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['label'];
    }

    public function getHasPoAttribute(): string
    {
        return $this->po_id ? 'yes' : 'no';
    }

    /** At or above the bank's invoice segregation-of-duties threshold (plan P3-9). */
    public function getHighValueAttribute(): string
    {
        $from = app(FinanceSettings::class)->invoiceSodFrom((int) $this->organization_id);

        return bccomp($this->approvalBaseAmount(), $from, 2) >= 0 ? 'yes' : 'no';
    }

    public function getEndorserIdAttribute(): ?int
    {
        return ExpenseRequest::endorserFor((int) $this->organization_id, $this->cost_centre_id ? (int) $this->cost_centre_id : null, $this->sodExcludedIds());
    }

    public function getHasEndorserAttribute(): string
    {
        return $this->endorser_id ? 'yes' : 'no';
    }

    public function getPayableAmountAttribute(): string
    {
        return bcsub((string) $this->total_amount, (string) $this->wht_amount, 2);
    }

    public function getDaysToDueAttribute(): ?int
    {
        return FinanceClock::daysUntil($this->due_on);
    }

    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->status, ['submitted', 'approved', 'scheduled'], true) && $this->due_on && $this->due_on->toDateString() < FinanceClock::today()->toDateString();
    }

    public function getLabelAttribute(): string
    {
        return "{$this->invoice_code} {$this->vendor_invoice_no}";
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'invoice_code', 'INV-');
    }
}
