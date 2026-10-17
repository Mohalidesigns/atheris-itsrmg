<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A purchase order raised when an approved expense request is committed (FIN-03.03). One per request. */
class PurchaseOrder extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const STATUSES = ['open', 'partially_received', 'received', 'closed', 'cancelled'];

    public const OPEN = ['open', 'partially_received', 'received'];

    protected $table = 'fin_purchase_orders';

    protected $guarded = [];

    protected $casts = [
        'po_date' => 'date', 'expected_delivery_on' => 'date', 'fx_rate_date' => 'date', 'closed_at' => 'datetime',
        'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'base_total_amount' => 'decimal:2',
        'fx_rate' => 'decimal:8', 'is_capex' => 'boolean', 'is_lease' => 'boolean',
    ];

    public function expenseRequest(): BelongsTo
    {
        return $this->belongsTo(ExpenseRequest::class, 'expense_request_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class, 'tax_type_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'po_id')->orderBy('line_no');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'po_id')->orderBy('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'po_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(FinanceDocument::class, 'documentable');
    }

    public function getReceivedPctAttribute(): string
    {
        $ordered = (string) $this->lines->sum('quantity');
        if (bccomp($ordered, '0', 4) <= 0) {
            return '0.00';
        }

        return bcmul(bcdiv((string) $this->lines->sum('received_quantity'), $ordered, 6), '100', 2);
    }

    public function getLabelAttribute(): string
    {
        return $this->po_code;
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'po_code', 'PO-');
    }
}
