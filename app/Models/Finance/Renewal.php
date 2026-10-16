<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Services\Finance\DoaBandService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A renewal decision for one contract (FIN-06.05); approved through the `finance.contract_renewal` process. */
class Renewal extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const STATUSES = ['open', 'submitted', 'approved', 'rejected', 'executed', 'withdrawn'];

    public const ACTIVE = ['open', 'submitted', 'approved'];

    public const DECISIONS = Contract::DECISIONS;

    protected $table = 'fin_contract_renewals';

    protected $guarded = [];

    protected $casts = [
        'opened_on' => 'date', 'proposed_commences_on' => 'date', 'proposed_fx_rate_date' => 'date',
        'proposed_value' => 'decimal:2', 'proposed_base_value' => 'decimal:2', 'proposed_fx_rate' => 'decimal:8', 'uplift_pct' => 'decimal:2',
        'is_auto' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'executed_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /**
     * Everyone who must not approve this renewal (segregation of duties): the submitter, the recommender, the person who
     * opened it and the contract owner.
     *
     * @return list<int>
     */
    public function sodExcludedIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter([
            $this->requested_by_id, $this->recommended_by_id, $this->created_by_id, $this->contract?->owner_id,
        ]))));
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_id');
    }

    public function newContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'new_contract_id');
    }

    /** What the DoA band and the approval snapshot are measured on: the proposed total, else the contract's own value. */
    public function approvalBaseAmount(): string
    {
        return (string) ($this->proposed_base_value ?? $this->contract?->base_contract_value ?? '0');
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['key'];
    }

    public function getAmountBandLabelAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['label'];
    }

    /** Engine condition value: the recommendation. */
    public function getDecisionAttribute(): string
    {
        return (string) $this->recommendation;
    }

    public function getContractTypeAttribute(): string
    {
        return (string) $this->contract?->type;
    }

    public function getUpliftBandAttribute(): string
    {
        $u = $this->uplift_pct === null ? '0' : (string) $this->uplift_pct;

        return match (true) {
            bccomp($u, '0', 2) <= 0 => 'decrease',
            bccomp($u, '5', 2) <= 0 => '0-5',
            bccomp($u, '10', 2) <= 0 => '5-10',
            default => '10+',
        };
    }

    public function getRequiresCfoAttribute(): string
    {
        $from = (string) config('finance.approval_defaults.renewal_cfo_from');
        $pct = (string) config('finance.approval_defaults.renewal_cfo_uplift_pct');
        $high = bccomp($this->approvalBaseAmount(), $from, 2) >= 0;
        $uplift = $this->uplift_pct !== null && bccomp((string) $this->uplift_pct, $pct, 2) > 0;

        return $high || $uplift ? 'yes' : 'no';
    }

    public function getContractOwnerIdAttribute(): ?int
    {
        return $this->contract?->owner_id;
    }

    public function getContractLabelAttribute(): string
    {
        return (string) $this->contract?->label;
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'renewal_code', 'REN-');
    }
}
