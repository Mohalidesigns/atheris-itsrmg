<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\LegalEntity;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\DoaBandService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * One version of one cost centre's budget for a cycle (ATH-FIN-001 §4.2, §5.1). Approved,
 * baseline and revised versions are immutable: an edit creates the next version.
 */
class Budget extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'baseline', 'revised', 'rejected', 'withdrawn'];

    public const MODES = ['zero_based', 'incremental', 'from_actuals'];

    public const EDITABLE = ['draft', 'rejected'];

    public const LOCKED = ['approved', 'baseline', 'revised'];

    public const IN_APPROVAL = ['submitted', 'under_review'];

    /** The only columns that may change on a locked version (status moves, supersession, baseline bookkeeping). */
    public const LOCKED_WRITABLE = ['status', 'superseded_by_id', 'baseline_slot', 'baselined_at', 'baselined_by_id', 'updated_at'];

    /** Legal status moves out of a locked status (FIN-07.06): a locked version never goes back to draft. */
    public const LOCKED_STATUS_MOVES = ['approved' => ['baseline', 'revised'], 'baseline' => ['revised'], 'revised' => []];

    protected $table = 'fin_budgets';

    protected $guarded = [];

    protected $casts = [
        'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'baselined_at' => 'datetime',
        'total_capex' => 'decimal:2', 'total_opex' => 'decimal:2', 'total_base_amount' => 'decimal:2',
        'increment_pct' => 'decimal:2', 'version_no' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (Budget $b) {
            $orig = $b->getOriginal('status');
            if (in_array($orig, self::LOCKED, true)) {
                $dirty = array_keys($b->getDirty());
                if (array_diff($dirty, self::LOCKED_WRITABLE)) {
                    throw ValidationException::withMessages(['workflow' => "Version {$b->version_no} is {$orig} and immutable; create a revision."]);
                }
                $new = $b->status;
                $moved = in_array('status', $dirty, true);
                if ($moved && ! in_array($new, self::LOCKED_STATUS_MOVES[$orig] ?? [], true)) {
                    throw ValidationException::withMessages(['workflow' => "A {$orig} version cannot move to {$new}."]);
                }
                if ($moved && $new === 'revised' && ! $b->superseded_by_id) {
                    throw ValidationException::withMessages(['workflow' => "A {$orig} version can only be revised by superseding it with a newer version."]);
                }
                $bad = null;
                if (in_array('superseded_by_id', $dirty, true) && (! $moved || $new !== 'revised')) {
                    $bad = 'superseded_by_id';
                }
                foreach (['baselined_at', 'baselined_by_id'] as $col) {
                    if (in_array($col, $dirty, true) && (! $moved || $new !== 'baseline')) {
                        $bad = $col;
                    }
                }
                if (in_array('baseline_slot', $dirty, true) && ! ($moved && ($new === 'baseline' || ($new === 'revised' && $b->baseline_slot === null)))) {
                    $bad = 'baseline_slot';
                }
                if ($bad) {
                    throw ValidationException::withMessages(['workflow' => "Version {$b->version_no} is {$orig} and immutable; {$bad} cannot change here."]);
                }
            }
        });
        static::deleting(function (Budget $b) {
            if ($b->status !== 'draft') {
                throw ValidationException::withMessages(['workflow' => 'Only a draft budget version can be deleted.']);
            }
        });
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(BudgetCycle::class, 'cycle_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function baselinedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'baselined_by_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function sourceBudget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_budget_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_id')->orderBy('sort_order')->orderBy('line_no');
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(BudgetScenario::class, 'budget_id')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BudgetComment::class, 'budget_id')->orderBy('id');
    }

    /** Every version of this budget (same code), oldest first. */
    public function versions(): Builder
    {
        return static::query()->where('organization_id', $this->organization_id)->where('budget_code', $this->budget_code)->orderBy('version_no');
    }

    public function scopeForFiscalYear(Builder $q, int $id): Builder
    {
        return $q->where('fiscal_year_id', $id);
    }

    public function scopeBaseline(Builder $q): Builder
    {
        return $q->where('status', 'baseline');
    }

    /** Highest version number per budget code. */
    public function scopeLatestVersions(Builder $q): Builder
    {
        return $q->whereIn('fin_budgets.id', fn ($sub) => $sub->from('fin_budgets as b2')->selectRaw('max(b2.id)')
            ->whereNull('b2.deleted_at')->groupBy('b2.organization_id', 'b2.budget_code'));
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);

        return $ids === null ? $q : $q->whereIn('cost_centre_id', $ids ?: [0]);
    }

    public function getAmountBandAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, (string) $this->total_base_amount)['key'];
    }

    public function getAmountBandLabelAttribute(): string
    {
        return app(DoaBandService::class)->bandFor((int) $this->organization_id, (string) $this->total_base_amount)['label'];
    }

    public function getCycleTypeAttribute(): ?string
    {
        return $this->cycle?->type;
    }

    public function getVersionLabelAttribute(): string
    {
        return 'v'.$this->version_no;
    }

    public function getIsLockedAttribute(): bool
    {
        return in_array($this->status, self::LOCKED, true);
    }

    public function getIsEditableAttribute(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'budget_code', 'BUD-');
    }
}
