<?php

namespace App\Models\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Asset;
use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Services\Finance\DoaBandService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Capitalisation of investment or capex spend as an asset (FIN-03.13; IAS 38 / IAS 16), approved through
 * `finance.capitalisation_approval`. Amortisation is a memo schedule (plan P6-13).
 */
class Capitalisation extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const SOURCE_TYPES = ['investment', 'expense'];

    public const STANDARDS = ['ias_38', 'ias_16'];

    public const METHODS = ['straight_line', 'declining'];

    public const STATUSES = ['draft', 'submitted', 'capitalised', 'rejected', 'cancelled'];

    public const ASSET_TYPES = ['software', 'hardware', 'cloud_service', 'network', 'facility'];

    protected $table = 'fin_capitalisations';

    protected $guarded = [];

    protected $casts = [
        'available_for_use_on' => 'date', 'recognition_date' => 'date', 'consumed_actual_ids' => 'array', 'capitalised_on' => 'date', 'amount' => 'decimal:2', 'eligible_amount' => 'decimal:2', 'excluded_amount' => 'decimal:2',
        'residual_value' => 'decimal:2', 'declining_rate_pct' => 'decimal:2', 'criteria_checklist' => 'array', 'sod_excluded_ids' => 'array', 'create_asset' => 'boolean',
        'requested_at' => 'datetime', 'approved_at' => 'datetime', 'useful_life_months' => 'integer',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function expenseRequest(): BelongsTo
    {
        return $this->belongsTo(ExpenseRequest::class, 'expense_request_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function assetOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asset_owner_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function bookingPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'booking_period_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AmortisationLine::class, 'capitalisation_id')->orderBy('line_no');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(FinanceDocument::class, 'documentable');
    }

    /** The investment or the expense request this capitalises. */
    public function source(): ?Model
    {
        return $this->source_type === 'investment'
            ? Investment::withoutGlobalScopes()->find($this->investment_id)
            : ExpenseRequest::withoutGlobalScopes()->find($this->expense_request_id);
    }

    public function getSourceLabelAttribute(): string
    {
        $s = $this->source();

        return match (true) {
            $s instanceof Investment => trim("{$s->investment_code} {$s->title}"),
            $s instanceof ExpenseRequest => trim("{$s->request_code} {$s->title}"),
            default => '',
        };
    }

    /**
     * Everyone who must not approve (segregation of duties): the requester, the creator and the investment manager or the expense requester.
     * A snapshot taken at submit wins over the live values.
     *
     * @return list<int>
     */
    public function sodExcludedIds(): array
    {
        $s = $this->source();
        // The live owners are always added to the submit-time snapshot, so reassigning the manager or sponsor never frees the new holder to approve their own spend.
        $owners = match (true) {
            $s instanceof Investment => [$s->manager_id, $s->sponsor_id],
            $s instanceof ExpenseRequest => [$s->requested_by_id],
            default => [],
        };
        $snapshot = is_array($this->sod_excluded_ids) ? $this->sod_excluded_ids : [];

        return array_values(array_unique(array_map('intval', array_filter(array_merge($snapshot, [$this->requested_by_id, $this->created_by_id], $owners)))));
    }

    public function approvalBaseAmount(): string
    {
        return (string) $this->amount;
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['key'];
    }

    public function getAmountBandLabelAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['label'];
    }

    public function getRequiresCfoAttribute(): string
    {
        $from = (string) config('finance.approval_defaults.capitalisation_cfo_from');

        return bccomp($this->approvalBaseAmount(), $from, 2) >= 0 ? 'yes' : 'no';
    }

    public function getCapitalisationTitleAttribute(): string
    {
        return trim("{$this->capitalisation_code} {$this->title} (".strtoupper(str_replace('_', ' ', (string) $this->standard)).')');
    }

    /** Net book value on a date: cost less the amortisation of the months up to and including that month. */
    public function nbvOn(CarbonInterface $date): string
    {
        $cost = (string) $this->amount;
        if ($this->status !== 'capitalised') {
            return bcadd($cost, '0', 2);
        }

        return bcsub($cost, $this->amortisedTo($date), 2);
    }

    public function amortisedTo(CarbonInterface $date): string
    {
        if ($this->status !== 'capitalised') {
            return '0.00';
        }
        $month = $date->copy()->startOfMonth()->toDateString();
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->filter(fn ($l) => $l->month->toDateString() <= $month)->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 2), '0.00');
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'capitalisation_code', 'CAP-');
    }
}
