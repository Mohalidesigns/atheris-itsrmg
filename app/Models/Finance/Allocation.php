<?php

namespace App\Models\Finance;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\LegalEntity;
use App\Services\Finance\FinanceSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An allocation rule (ATH-FIN-001 §4.2, plan P6-5): spend of a source is shared onto targets by percentage, driver or direct. */
class Allocation extends Model
{
    use FinanceRecord, GeneratesSequentialCode, SoftDeletes;

    public const SOURCE_TYPES = ['cost_centre', 'category', 'budget_line', 'contract'];

    public const TARGET_TYPES = ['business_unit', 'cost_centre', 'business_service', 'ea_application', 'investment', 'legal_entity'];

    /** Lenses whose targets are also taggable directly on a ledger item (direct attribution wins, P6-6). */
    public const TAG_TARGETS = ['business_service', 'ea_application', 'investment', 'legal_entity'];

    public const METHODS = ['direct', 'percentage', 'driver'];

    public const AMOUNT_TYPES = ['budget', 'actual'];

    protected $table = 'fin_allocations';

    protected $guarded = [];

    protected $casts = [
        'percentage' => 'decimal:4', 'driver_quantity' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AllocationResult::class, 'allocation_id');
    }

    /** The source record by type (no morph map). */
    public function sourceModel(): ?Model
    {
        $org = (int) $this->organization_id;

        return match ($this->source_type) {
            'cost_centre' => CostCentre::withoutGlobalScopes()->where('organization_id', $org)->find($this->source_id),
            'category' => Category::withoutGlobalScopes()->where('organization_id', $org)->find($this->source_id),
            'budget_line' => BudgetLine::withoutGlobalScopes()->where('organization_id', $org)->find($this->source_id),
            'contract' => Contract::withoutGlobalScopes()->where('organization_id', $org)->find($this->source_id),
            default => null,
        };
    }

    /** The target record by type; null for business units (a code in the bank's showback units). */
    public function targetModel(): ?Model
    {
        $org = (int) $this->organization_id;

        return match ($this->target_type) {
            'cost_centre' => CostCentre::withoutGlobalScopes()->where('organization_id', $org)->find($this->target_id),
            'business_service' => BusinessService::withoutGlobalScopes()->find($this->target_id),
            'ea_application' => EaApplication::withoutGlobalScopes()->find($this->target_id),
            'investment' => Investment::withoutGlobalScopes()->where('organization_id', $org)->find($this->target_id),
            'legal_entity' => LegalEntity::withoutGlobalScopes()->where('organization_id', $org)->find($this->target_id),
            default => null,
        };
    }

    public function getSourceLabelAttribute(): string
    {
        $m = $this->sourceModel();

        return match (true) {
            $m instanceof BudgetLine => "#{$m->line_no} {$m->description}",
            $m instanceof Contract => trim(($m->contract_code ?? '').' '.($m->title ?? '')),
            $m instanceof Investment => trim(($m->investment_code ?? '').' '.($m->title ?? '')),
            $m !== null => trim(($m->code ?? '').' '.($m->name ?? '')),
            default => ucfirst(str_replace('_', ' ', (string) $this->source_type)).' #'.$this->source_id,
        };
    }

    public function getTargetLabelAttribute(): string
    {
        if ($this->target_type === 'business_unit') {
            foreach ((array) (app(FinanceSettings::class)->for((int) $this->organization_id)->showback_units ?? []) as $u) {
                if (($u['code'] ?? null) === $this->target_ref) {
                    return $u['code'].' '.$u['name'];
                }
            }

            return (string) $this->target_ref;
        }
        $m = $this->targetModel();

        return match (true) {
            $m instanceof Investment => trim(($m->investment_code ?? '').' '.($m->title ?? '')),
            $m !== null => trim(($m->code ?? '').' '.($m->name ?? '')),
            default => ucfirst(str_replace('_', ' ', (string) $this->target_type)).' #'.$this->target_id,
        };
    }

    public function isEffectiveOn(CarbonInterface $date): bool
    {
        $d = $date->toDateString();

        return $this->is_active && $this->effective_from->toDateString() <= $d && ($this->effective_to === null || $this->effective_to->toDateString() >= $d);
    }

    /** Active rules whose window overlaps [from, to]. */
    public function scopeEffectiveBetween(Builder $q, string $from, string $to): Builder
    {
        $t = $q->getModel()->getTable();

        return $q->where("{$t}.is_active", true)->whereDate("{$t}.effective_from", '<=', $to)
            ->where(fn ($b) => $b->whereNull("{$t}.effective_to")->orWhereDate("{$t}.effective_to", '>=', $from));
    }

    public static function generateNextCode(int $orgId): string
    {
        return static::nextSequentialCode($orgId, 'allocation_code', 'ALC-');
    }
}
