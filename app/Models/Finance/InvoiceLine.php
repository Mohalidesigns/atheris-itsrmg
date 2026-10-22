<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use FinanceRecord;

    protected $table = 'fin_invoice_lines';

    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:2', 'amount' => 'decimal:2'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'po_line_id');
    }
}
