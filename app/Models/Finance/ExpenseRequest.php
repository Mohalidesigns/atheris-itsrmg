<?php

namespace App\Models\Finance;

use App\Models\Asset;
use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An expense request (ATH-FIN-001 §3.3, §4.3): approved against the DoA before it is committed as a purchase order. */
class ExpenseRequest extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'committed', 'partially_received', 'received', 'invoiced', 'closed', 'cancelled', 'withdrawn'];

    /** Statuses whose approved amount still counts as committed spend. */
    public const OPEN_COMMITMENT = ['approved', 'committed', 'partially_received', 'received', 'invoiced'];

    public const TERMINAL = ['rejected', 'closed', 'cancelled', 'withdrawn'];

    public const EDITABLE = ['draft'];

    protected $table = 'fin_expense_requests';

    protected $guarded = [];

    protected $casts = [
        'is_capex' => 'boolean', 'is_emergency' => 'boolean', 'is_lease' => 'boolean', 'over_budget' => 'boolean',
        'needed_by' => 'date', 'fx_rate_date' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'closed_at' => 'datetime',
        'amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'base_amount' => 'decimal:2',
        'base_total_amount' => 'decimal:2', 'fx_rate' => 'decimal:8', 'availability_snapshot' => 'array',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function renewal(): BelongsTo
    {
        return $this->belongsTo(Renewal::class, 'renewal_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class, 'tax_type_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class, 'expense_request_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'expense_request_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(FinanceDocument::class, 'documentable');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);
        if ($ids === null || $user->hasRole('Procurement Officer')) {
            return $q;
        }

        return $q->where(fn ($w) => $w->whereIn($q->getModel()->getTable().'.cost_centre_id', $ids ?: [0])
            ->orWhere($q->getModel()->getTable().'.requested_by_id', $user->id)->orWhere($q->getModel()->getTable().'.created_by_id', $user->id));
    }

    public function scopeForFiscalYear(Builder $q, int $id): Builder
    {
        return $q->where('fiscal_year_id', $id);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', self::TERMINAL);
    }

    /** @return list<int> everyone who must not approve (segregation of duties): the requester and whoever created it */
    public function sodExcludedIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter([$this->requested_by_id, $this->created_by_id]))));
    }

    public function approvalBaseAmount(): string
    {
        return (string) $this->base_total_amount;
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['key'];
    }

    public function getAmountBandLabelAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, $this->approvalBaseAmount())['label'];
    }

    /** Plan P3-5: the honest band, moved up one band when the request is unbudgeted or over budget. */
    public function getEffectiveBandAttribute(): string
    {
        $bands = array_column(app(DoaBandService::class)->active((int) $this->organization_id)->bands, 'key');
        $i = array_search($this->amount_band, $bands, true);
        $bump = ($this->budget_line_id === null || $this->over_budget) ? (int) config('finance.expenses.unbudgeted_band_bump', 1) : 0;

        return $bands[min(count($bands) - 1, ($i === false ? 0 : $i) + $bump)] ?? $this->amount_band;
    }

    public function getCapexOpexAttribute(): string
    {
        return $this->is_capex ? 'capex' : 'opex';
    }

    public function getIsUnbudgetedAttribute(): string
    {
        return $this->budget_line_id === null ? 'yes' : 'no';
    }

    public function getEmergencyAttribute(): string
    {
        return $this->is_emergency ? 'yes' : 'no';
    }

    public function getOverspendAttribute(): string
    {
        return $this->over_budget ? 'yes' : 'no';
    }

    /** Plan P3-6: the cost-centre owner, walking up the tree past anyone excluded by segregation of duties. */
    public function getEndorserIdAttribute(): ?int
    {
        return self::endorserFor((int) $this->organization_id, $this->cost_centre_id ? (int) $this->cost_centre_id : null, $this->sodExcludedIds());
    }

    /** @param list<int> $excluded */
    public static function endorserFor(int $orgId, ?int $costCentreId, array $excluded): ?int
    {
        $id = $costCentreId;
        for ($hops = 0; $id && $hops < 12; $hops++) {
            $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->find($id);
            if (! $cc) {
                return null;
            }
            if ($cc->owner_id && ! in_array((int) $cc->owner_id, $excluded, true)) {
                return (int) $cc->owner_id;
            }
            $id = $cc->parent_id;
        }

        return null;
    }

    public function getHasEndorserAttribute(): string
    {
        return $this->endorser_id ? 'yes' : 'no';
    }

    public function getLabelAttribute(): string
    {
        return trim("{$this->request_code} {$this->title}");
    }

    public function getDaysToNeededAttribute(): ?int
    {
        return FinanceClock::daysUntil($this->needed_by);
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'request_code', 'EXP-');
    }
}
