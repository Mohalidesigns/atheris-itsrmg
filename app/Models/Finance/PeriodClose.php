<?php

namespace App\Models\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One close of a period, with its checklist snapshot and, when asked for, the reopen request (plan P4-6, C101). Also the approval subject of `finance.period_reopen`. */
class PeriodClose extends Model
{
    use FinanceRecord;

    public const STATUSES = ['closed', 'locked', 'reopened'];

    public const REOPEN_STATUSES = ['submitted', 'approved', 'rejected', 'withdrawn'];

    protected $table = 'fin_period_closes';

    protected $guarded = [];

    protected $casts = [
        'checklist' => 'array', 'closed_at' => 'datetime', 'locked_at' => 'datetime', 'reopen_requested_at' => 'datetime',
        'reopen_decided_at' => 'datetime', 'reopened_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    public function reopenRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopen_requested_by_id');
    }

    public function reopenDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopen_decided_by_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'reopen_approval_request_id');
    }

    /** The period's live status (closed or locked) for the approval condition. */
    public function getPeriodStatusAttribute(): string
    {
        return (string) Period::withoutGlobalScopes()->whereKey($this->period_id)->value('status');
    }

    public function getPeriodLabelAttribute(): string
    {
        $p = Period::withoutGlobalScopes()->with('fiscalYear:id,year_label')->find($this->period_id);

        return trim(($p?->fiscalYear?->year_label ?? '').' '.($p?->name ?? ''));
    }

    /** Everyone who must not approve the reopen: the requester and whoever created the close record. @return list<int> */
    public function sodExcludedIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter([$this->reopen_requested_by_id, $this->created_by_id]))));
    }

    public function approvalBaseAmount(): string
    {
        return '0';
    }
}
