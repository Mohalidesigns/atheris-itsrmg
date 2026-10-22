<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A CapEx / OpEx ceiling set for a cycle, per cost centre, TBM category or legal entity (FIN-02.03). */
class BudgetTarget extends Model
{
    use FinanceRecord;

    protected $table = 'fin_budget_targets';

    protected $guarded = [];

    protected $casts = ['capex_ceiling' => 'decimal:2', 'opex_ceiling' => 'decimal:2'];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(BudgetCycle::class, 'cycle_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }

    /** "KHB-IT-DC", "Tower TW-SEC", "KHB-IT-DC · Tower TW-SEC". */
    public function getScopeLabelAttribute(): string
    {
        $parts = array_filter([
            $this->costCentre?->code,
            $this->category ? ($this->category->kind === 'tower' ? 'Tower ' : ($this->category->kind === 'cost_pool' ? 'Pool ' : '')).$this->category->code : null,
            $this->legalEntity?->code,
        ]);

        return $parts ? implode(' · ', $parts) : 'Whole bank';
    }
}
