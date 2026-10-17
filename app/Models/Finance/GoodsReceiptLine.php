<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use FinanceRecord;

    protected $table = 'fin_goods_receipt_lines';

    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:4', 'accepted' => 'boolean'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'grn_id');
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'po_line_id');
    }
}
