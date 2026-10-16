<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contract or subscription (ATH-FIN-001 §3.6, §4.4). Window and notice dates are computed and
 * stored for indexing; the status is derived from dates and decisions (ContractStatusDeriver).
 */
class Contract extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const TYPES = ['licence', 'subscription', 'saas', 'support_maintenance', 'managed_service', 'professional_services', 'lease', 'telecom', 'cloud_commitment', 'hardware_warranty', 'other'];

    public const STATUSES = ['draft', 'active', 'in_renewal_window', 'notice_deadline_passed', 'expired', 'renewed', 'terminated', 'replaced'];

    public const LIVE = ['active', 'in_renewal_window', 'notice_deadline_passed'];

    public const TERMINAL = ['renewed', 'terminated', 'replaced'];

    public const RENEWABLE = ['active', 'in_renewal_window', 'notice_deadline_passed', 'expired'];

    public const RENEWAL_TYPES = ['manual', 'auto', 'evergreen', 'fixed_term'];

    public const BILLING = ['monthly', 'quarterly', 'semi_annual', 'annual', 'one_off', 'usage'];

    public const LICENCE_METRICS = ['seat', 'user', 'core', 'device', 'instance', 'tb', 'transaction', 'site', 'enterprise', 'other'];

    public const DP_ROLES = ['none', 'processor', 'controller', 'joint'];

    public const DPA = ['not_required', 'pending', 'signed'];

    public const DECISIONS = ['renew_as_is', 'renew_renegotiated', 'replace', 'terminate', 'extend_short_term'];

    protected $table = 'fin_contracts';

    protected $guarded = [];

    protected $casts = [
        'commenced_on' => 'date', 'ends_on' => 'date', 'renewal_window_opens_on' => 'date', 'notice_deadline_on' => 'date',
        'fx_rate_date' => 'date', 'terminated_on' => 'date',
        'contract_value' => 'decimal:2', 'base_contract_value' => 'decimal:2', 'amount_per_billing' => 'decimal:2',
        'licence_quantity' => 'decimal:2', 'deployed_quantity' => 'decimal:2', 'price_escalation_pct' => 'decimal:2', 'fx_rate' => 'decimal:8',
        'notification_offsets' => 'array', 'key_clauses' => 'array', 'auto_renew' => 'boolean',
        'decision_at' => 'datetime', 'escalated_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function technicalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technical_owner_id');
    }

    public function procurementContact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'procurement_contact_id');
    }

    public function decisionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_by_id');
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    public function renewedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_to_id');
    }

    public function team(): HasMany
    {
        return $this->hasMany(ContractTeamMember::class, 'contract_id');
    }

    public function teamUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'fin_contract_team', 'contract_id', 'user_id')->withPivot('role');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ContractLink::class, 'contract_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class, 'contract_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(Renewal::class, 'contract_id')->orderByDesc('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContractEvent::class, 'contract_id')->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function openRenewal(): HasOne
    {
        return $this->hasOne(Renewal::class, 'contract_id')->whereIn('status', Renewal::ACTIVE)->latestOfMany();
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->whereIn('fin_contracts.status', self::LIVE);
    }

    /** Contracts whose end or notice date falls within the next $days days (live contracts only). */
    public function scopeDueWithin(Builder $q, int $days): Builder
    {
        $from = FinanceClock::today()->toDateString();
        $to = FinanceClock::today()->addDays($days)->toDateString();

        return $q->live()->where(fn ($w) => $w->whereBetween('fin_contracts.ends_on', [$from, $to])->orWhereBetween('fin_contracts.notice_deadline_on', [$from, $to]));
    }

    /** The "Passed" list (plan C59): not draft/terminal, no decision, and the notice or end date is behind us. */
    public function scopePassed(Builder $q, CarbonInterface $today): Builder
    {
        $d = $today->toDateString();

        return $q->whereNotIn('fin_contracts.status', array_merge(['draft'], self::TERMINAL))->whereNull('fin_contracts.decision')
            ->where(fn ($w) => $w->where('fin_contracts.notice_deadline_on', '<', $d)->orWhere('fin_contracts.ends_on', '<', $d));
    }

    /** Row restriction (plan C70): all cost centres, Procurement, or the contract's own people; otherwise by cost centre. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);
        if ($ids === null || $user->hasRole('Procurement Officer')) {
            return $q;
        }

        return $q->where(function ($w) use ($user, $ids) {
            $w->whereIn('fin_contracts.cost_centre_id', $ids ?: [0])
                ->orWhere('fin_contracts.owner_id', $user->id)->orWhere('fin_contracts.technical_owner_id', $user->id)
                ->orWhere('fin_contracts.procurement_contact_id', $user->id)->orWhere('fin_contracts.created_by_id', $user->id)
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('fin_contract_team')->whereColumn('fin_contract_team.contract_id', 'fin_contracts.id')->where('fin_contract_team.user_id', $user->id));
        });
    }

    /** True when $user is the contract's owner, technical owner, procurement contact, creator or a team member. */
    public function involves(User $user): bool
    {
        $id = (int) $user->id;

        return in_array($id, array_map('intval', array_filter([$this->owner_id, $this->technical_owner_id, $this->procurement_contact_id, $this->created_by_id])), true)
            || $this->team()->where('user_id', $id)->exists();
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, (string) $this->base_contract_value)['key'];
    }

    public function getAmountBandLabelAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, (string) $this->base_contract_value)['label'];
    }

    public function getAnnualisedBaseValueAttribute(): string
    {
        $perMonth = bcdiv((string) $this->base_contract_value, (string) max((int) $this->term_months, 1), 8);

        return bcadd(bcmul($perMonth, '12', 8), '0.005', 2);
    }

    public function getNextTermForecastAttribute(): string
    {
        $factor = bcadd('1', bcdiv((string) ($this->price_escalation_pct ?? '0'), '100', 8), 8);

        return bcadd(bcmul((string) $this->base_contract_value, $factor, 8), '0.005', 2);
    }

    public function getDaysToEndAttribute(): ?int
    {
        return FinanceClock::daysUntil($this->ends_on);
    }

    public function getDaysToNoticeAttribute(): ?int
    {
        return FinanceClock::daysUntil($this->notice_deadline_on);
    }

    public function getDaysToWindowAttribute(): ?int
    {
        return FinanceClock::daysUntil($this->renewal_window_opens_on);
    }

    /** @return array<int,int> */
    public function getEffectiveOffsetsAttribute(): array
    {
        $offsets = $this->notification_offsets ?: (app(FinanceSettings::class)->for((int) $this->organization_id)->renewal_notice_offsets ?: []);
        $offsets = array_values(array_unique(array_map('intval', $offsets)));
        rsort($offsets);

        return $offsets;
    }

    public function getEffectiveLeadDaysAttribute(): int
    {
        return (int) ($this->renewal_lead_days ?? app(FinanceSettings::class)->for((int) $this->organization_id)->renewal_lead_days ?? config('finance.settings_defaults.renewal_lead_days'));
    }

    public function getIsAutoAttribute(): bool
    {
        return $this->auto_renew || in_array($this->renewal_type, ['auto', 'evergreen'], true);
    }

    public function getIsTerminalAttribute(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function getHasDecisionAttribute(): bool
    {
        return $this->decision !== null;
    }

    public function getLicencePositionAttribute(): ?string
    {
        if ($this->licence_quantity === null || $this->deployed_quantity === null || bccomp((string) $this->licence_quantity, '0', 2) <= 0) {
            return null;
        }
        $entitled = (string) $this->licence_quantity;
        $deployed = (string) $this->deployed_quantity;

        return match (true) {
            bccomp($deployed, $entitled, 2) > 0 => 'over',
            bccomp($deployed, bcmul($entitled, '0.8', 2), 2) < 0 => 'under',
            default => 'ok',
        };
    }

    public function getLabelAttribute(): string
    {
        return trim($this->contract_code.' '.$this->title);
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'contract_code', 'CON-');
    }
}
