<?php

namespace App\Models\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Services\Finance\DoaBandService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One request to pass a gate (or an over-tolerance change), and the approval subject of `finance.business_case_approval` (P5-4). */
class StageGate extends Model
{
    use FinanceRecord;

    public const GATES = ['business_case', 'approved', 'in_delivery', 'delivered', 'benefits_tracking', 'closed', 'change'];

    public const STATUSES = ['submitted', 'approved', 'rejected', 'withdrawn', 'passed'];

    protected $table = 'fin_stage_gates';

    protected $guarded = [];

    protected $casts = [
        'criteria' => 'array', 'payload' => 'array', 'sod_excluded_ids' => 'array', 'approval_base_amount' => 'decimal:2',
        'requested_at' => 'datetime', 'passed_at' => 'datetime', 'decided_at' => 'datetime',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function businessCase(): BelongsTo
    {
        return $this->belongsTo(BusinessCase::class, 'business_case_id');
    }

    public function investmentChange(): BelongsTo
    {
        return $this->belongsTo(InvestmentChange::class, 'investment_change_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function passedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passed_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function approvalBaseAmount(): string
    {
        return (string) ($this->approval_base_amount ?? '0');
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['key'];
    }

    public function getAmountBandLabelAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['label'];
    }

    private function inv(): ?Investment
    {
        return $this->relationLoaded('investment') && $this->investment ? $this->investment : Investment::withoutGlobalScopes()->find($this->investment_id);
    }

    public function getRgtAttribute(): string
    {
        return (string) $this->inv()?->rgt;
    }

    public function getIsRegulatoryAttribute(): string
    {
        return $this->inv()?->is_regulatory ? 'yes' : 'no';
    }

    public function getRequiresCfoAttribute(): string
    {
        return in_array($this->gate, ['approved', 'change'], true) ? 'yes' : 'no';
    }

    public function getRequiresBoardAttribute(): string
    {
        return in_array($this->gate, ['approved', 'change'], true)
            && bccomp($this->approvalBaseAmount(), (string) config('finance.approval_defaults.business_case_board_from'), 2) >= 0 ? 'yes' : 'no';
    }

    public function getSponsorIdAttribute(): ?int
    {
        return $this->inv()?->sponsor_id;
    }

    public function getGateTitleAttribute(): string
    {
        $label = match ($this->gate) {
            'approved' => 'Business case approval', 'delivered' => 'Delivery acceptance', 'change' => 'Change beyond tolerance',
            default => ucfirst(str_replace('_', ' ', (string) $this->gate)),
        };

        return trim(($this->inv()?->label ?? '').' · '.$label);
    }

    /** Everyone who took part, frozen on the row when the request was made (security H2); live only for rows that predate the snapshot. @return list<int> */
    public function sodExcludedIds(): array
    {
        $frozen = $this->sod_excluded_ids;

        return is_array($frozen) && $frozen !== [] ? array_values(array_map('intval', $frozen)) : $this->liveSodExcludedIds();
    }

    /** @return list<int> */
    public function liveSodExcludedIds(): array
    {
        $i = $this->inv();
        $preparer = $this->business_case_id ? BusinessCase::withoutGlobalScopes()->whereKey($this->business_case_id)->value('created_by_id') : null;

        // a reassignment excludes the outgoing and the incoming sponsor and manager (P6-22)
        $moved = [];
        if ($this->investment_change_id) {
            $fc = (array) InvestmentChange::withoutGlobalScopes()->whereKey($this->investment_change_id)->value('field_changes');
            foreach (['sponsor_id', 'manager_id'] as $k) {
                $moved[] = $fc[$k]['from'] ?? null;
                $moved[] = $fc[$k]['to'] ?? null;
            }
        }

        return array_values(array_unique(array_map('intval', array_filter([
            $this->requested_by_id, $this->created_by_id, $i?->manager_id, $i?->sponsor_id, $preparer, ...$moved,
        ]))));
    }
}
