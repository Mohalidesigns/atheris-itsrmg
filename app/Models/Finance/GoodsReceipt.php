<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

/** A goods receipt note: immutable once recorded (FIN-03.03). */
class GoodsReceipt extends Model
{
    use FinanceRecord, GeneratesSequentialCode;

    protected $table = 'fin_goods_receipts';

    protected $guarded = [];

    protected $casts = ['received_on' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['workflow' => 'A goods receipt is immutable.']));
        static::deleting(fn () => throw ValidationException::withMessages(['workflow' => 'A goods receipt cannot be deleted.']));
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'grn_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(FinanceDocument::class, 'documentable');
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'grn_code', 'GRN-');
    }
}
