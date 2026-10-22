<?php

namespace App\Models\Finance;

use App\Models\Asset;
use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    use FinanceRecord;

    protected $table = 'fin_purchase_order_lines';

    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:2', 'amount' => 'decimal:2', 'received_quantity' => 'decimal:4'];

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'po_line_id');
    }

    public function getRemainingQuantityAttribute(): string
    {
        return bcsub((string) $this->quantity, (string) $this->received_quantity, 4);
    }
}
