<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class RiskTreatment extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'risk_id', 'title', 'description',
        'strategy', 'status', 'assigned_to', 'approved_by', 'approved_at',
        'due_date', 'completed_at', 'priority', 'estimated_cost', 'cost_currency',
        'target_likelihood', 'target_impact', 'target_score',
        'notes', 'completion_percentage',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'date',
            'approved_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
        ];
    }

    public const STATUSES = [
        'draft', 'submitted', 'approved', 'rejected',
        'in_progress', 'completed', 'overdue',
    ];

    public const STRATEGIES = ['mitigate', 'transfer', 'avoid', 'accept'];

    /** Statuses after which a plan is no longer "open" work. */
    public const CLOSED_STATUSES = ['completed', 'rejected'];

    protected $appends = ['is_overdue'];

    /** Past its due date and still open — derived, so it never goes stale like a stored 'overdue' flag. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->due_date->isToday()
            && ! in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
