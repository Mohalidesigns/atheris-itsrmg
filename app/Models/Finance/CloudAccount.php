<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A cloud provider account or subscription whose cost exports are imported as daily lines (FIN-03.09). */
class CloudAccount extends Model
{
    use FinanceRecord, SoftDeletes;

    public const PROVIDERS = ['aws', 'azure', 'gcp', 'oci', 'other'];

    public const FORMATS = ['aws_cur', 'azure', 'gcp', 'generic'];

    public const DIMENSIONS = ['cost_centre', 'ea_application', 'business_service'];

    protected $table = 'fin_cloud_accounts';

    protected $guarded = [];

    protected $casts = ['tag_mapping' => 'array', 'is_active' => 'boolean', 'last_usage_date' => 'date'];

    public function defaultCostCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'default_cost_centre_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(CloudCostLine::class, 'cloud_account_id');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(CloudAnomaly::class, 'cloud_account_id');
    }

    public function tagRules(): HasMany
    {
        return $this->hasMany(CloudTagRule::class, 'cloud_account_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'cloud_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function getLabelAttribute(): string
    {
        $provider = strtoupper((string) $this->provider);
        $id = (string) $this->account_identifier;
        $short = strlen($id) > 14 ? substr($id, 0, 4).'-…-'.substr($id, -2) : $id;

        return "{$provider} · {$short} · {$this->name}";
    }
}
