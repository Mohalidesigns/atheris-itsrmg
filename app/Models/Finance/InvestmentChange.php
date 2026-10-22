<?php

namespace App\Models\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A change to an approved investment's cost or schedule; beyond tolerance it needs a gate approval (FIN-05.11). */
class InvestmentChange extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const KINDS = ['scope', 'reassign'];

    /** Fields a reassignment may change (P6-22). */
    public const REASSIGN_FIELDS = ['sponsor_id', 'manager_id', 'cost_centre_id', 'rgt', 'is_regulatory'];

    public const STATUSES = ['submitted', 'applied', 'rejected', 'withdrawn'];

    protected $table = 'fin_investment_changes';

    protected $guarded = [];

    protected $casts = [
        'cost_delta' => 'decimal:2', 'field_changes' => 'array', 'requires_approval' => 'boolean', 'tolerance_snapshot' => 'array',
        'requested_at' => 'datetime', 'decided_at' => 'datetime', 'applied_at' => 'datetime',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function gate(): HasOne
    {
        return $this->hasOne(StageGate::class, 'investment_change_id')->latestOfMany();
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'change_code', 'ICR-');
    }
}
