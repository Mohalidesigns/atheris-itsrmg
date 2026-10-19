<?php

namespace App\Models\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One daily cloud cost line (plan P6-2): the FinOps sub-ledger, never posted to fin_actuals (P6-1). */
class CloudCostLine extends Model
{
    use FinanceRecord;

    protected $table = 'fin_cloud_cost_lines';

    protected $guarded = [];

    protected $casts = [
        'tags' => 'array', 'usage_date' => 'date', 'cost' => 'decimal:6', 'base_cost' => 'decimal:2', 'fx_rate' => 'decimal:8', 'is_untagged' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CloudAccount::class, 'cloud_account_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function eaApplication(): BelongsTo
    {
        return $this->belongsTo(EaApplication::class, 'ea_application_id')->withoutGlobalScopes();
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'business_service_id')->withoutGlobalScopes();
    }

    public function scopeTagged(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.is_untagged', false);
    }

    public function scopeUntagged(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.is_untagged', true);
    }

    public function scopeBetween(Builder $q, ?string $from, ?string $to): Builder
    {
        $t = $q->getModel()->getTable();

        return $q->when($from, fn ($b) => $b->whereDate("{$t}.usage_date", '>=', $from))->when($to, fn ($b) => $b->whereDate("{$t}.usage_date", '<=', $to));
    }
}
