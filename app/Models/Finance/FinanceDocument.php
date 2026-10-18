<?php

namespace App\Models\Finance;

use App\Models\Evidence;
use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A document held in the evidence vault against an expense, PO, goods receipt or invoice (FIN-03.14). */
class FinanceDocument extends Model
{
    use FinanceRecord;

    public const KINDS = ['quote', 'purchase_order', 'goods_receipt', 'invoice', 'delivery_note', 'approval', 'other', 'business_case', 'benefit_evidence', 'pir_report'];

    protected $table = 'fin_documents';

    protected $guarded = [];

    public static function subjects(): array
    {
        return ['expense' => ExpenseRequest::class, 'purchase_order' => PurchaseOrder::class, 'goods_receipt' => GoodsReceipt::class, 'invoice' => Invoice::class,
            'investment' => Investment::class, 'business_case' => BusinessCase::class, 'benefit' => Benefit::class, 'value_review' => ValueReview::class];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class, 'evidence_id');
    }
}
