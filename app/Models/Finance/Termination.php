<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A request to terminate a contract before its end date (plan H2), approved through `finance.contract_termination`. */
class Termination extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const STATUSES = ['submitted', 'approved', 'rejected', 'withdrawn'];

    protected $table = 'fin_contract_terminations';

    protected $guarded = [];

    protected $casts = [
        'requested_terminated_on' => 'date', 'is_post_deadline' => 'boolean', 'requested_at' => 'datetime', 'approved_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function overrideDocument(): BelongsTo
    {
        return $this->belongsTo(ContractDocument::class, 'override_document_id');
    }

    /**
     * Everyone who must not approve the termination (segregation of duties): the requester, whoever created the request,
     * the contract owner and the contract's creator.
     *
     * @return list<int>
     */
    public function sodExcludedIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter([
            $this->requested_by_id, $this->created_by_id, $this->contract?->owner_id, $this->contract?->created_by_id,
        ]))));
    }

    /** The DoA band is measured on what is being given up: the contract's own value. */
    public function approvalBaseAmount(): string
    {
        return (string) ($this->contract?->base_contract_value ?? '0');
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['key'];
    }

    public function getAmountBandLabelAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['label'];
    }

    public function getContractTypeAttribute(): string
    {
        return (string) $this->contract?->type;
    }

    /** CFO stage condition: the contract's value reaches the bank's renewal CFO threshold, or the termination overrides a notice deadline. */
    public function getRequiresCfoAttribute(): string
    {
        [$from] = app(FinanceSettings::class)->renewalCfoThresholds((int) $this->organization_id);

        return bccomp($this->approvalBaseAmount(), $from, 2) >= 0 || $this->is_post_deadline ? 'yes' : 'no';
    }

    public function getPostDeadlineAttribute(): string
    {
        return $this->is_post_deadline ? 'yes' : 'no';
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
        return static::nextSequentialCode($orgId, 'termination_code', 'TRM-');
    }
}
