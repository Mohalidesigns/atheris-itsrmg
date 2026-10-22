<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An exported chargeback journal of a closed period (plan P6-10). The CSV is kept on the row. */
class ChargebackRun extends Model
{
    use FinanceRecord, GeneratesSequentialCode;

    public const STATUSES = ['exported', 'voided'];

    protected $table = 'fin_chargeback_runs';

    protected $guarded = [];

    protected $hidden = ['content'];

    protected $casts = ['total_debit' => 'decimal:2', 'total_credit' => 'decimal:2', 'exported_at' => 'datetime', 'voided_at' => 'datetime'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }

    public function getFileNameAttribute(): string
    {
        return "{$this->run_code}-".($this->period?->name ?? $this->period_id).'.csv';
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'run_code', 'CBK-');
    }
}
