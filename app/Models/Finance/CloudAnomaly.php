<?php

namespace App\Models\Finance;

use App\Models\Ea\EaApplication;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A cloud cost day that broke its baseline (plan P6-4). */
class CloudAnomaly extends Model
{
    use FinanceRecord;

    public const SCOPES = ['account', 'service', 'application'];

    public const METHODS = ['rolling_7d', 'week_over_week'];

    public const STATUSES = ['open', 'acknowledged', 'resolved', 'false_positive'];

    public const OPEN = ['open', 'acknowledged'];

    protected $table = 'fin_cloud_anomalies';

    protected $guarded = [];

    protected $casts = [
        'detected_on' => 'date', 'baseline_cost' => 'decimal:2', 'observed_cost' => 'decimal:2', 'baseline_base_cost' => 'decimal:2', 'observed_base_cost' => 'decimal:2',
        'deviation_pct' => 'decimal:2', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CloudAccount::class, 'cloud_account_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function getScopeLabelAttribute(): string
    {
        return match ($this->scope) {
            'service' => (string) $this->scope_ref,
            'application' => (string) (EaApplication::withoutGlobalScopes()->whereKey((int) $this->scope_ref)->value('name') ?? 'Application #'.$this->scope_ref),
            default => 'Account total',
        };
    }

    public function getLabelAttribute(): string
    {
        return ($this->account?->name ?? 'Cloud account').' · '.$this->scope_label.' · '.$this->detected_on?->toDateString();
    }
}
