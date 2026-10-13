<?php

namespace App\Models\Change;

use App\Models\Asset;
use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Incident;
use App\Models\Issue;
use App\Models\Risk;
use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use App\Models\Vulnerability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A request for change (ITIL 4 Change Enablement): standard, normal or emergency, risk-scored,
 * routed through the bank's approval stages, scheduled on the forward schedule of change,
 * implemented, reviewed (PIR) and closed. ATH-CHG-001 §2.1.
 */
class ChangeRequest extends Model
{
    use BelongsToOrganization, GeneratesSequentialCode, HasTenantIdAlias, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'impact' => 'integer',
        'likelihood' => 'integer',
        'risk_score' => 'integer',
        'outage_required' => 'boolean',
        'cab_required' => 'boolean',
        'pir_required' => 'boolean',
        'started_before_approval' => 'boolean',
        'affected_asset_ids' => 'array',
        'affected_services' => 'array',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'actual_start_at' => 'datetime',
        'actual_end_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'implemented_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public const TYPES = ['standard', 'normal', 'emergency'];

    public const CATEGORIES = ['application', 'infrastructure', 'network', 'security', 'database', 'core_banking', 'data', 'vendor', 'process', 'other'];

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /** Lifecycle, main path first, then the side and outcome states. */
    public const STATUSES = ['draft', 'submitted', 'in_approval', 'approved', 'scheduled', 'implementing', 'implemented', 'review', 'closed', 'rejected', 'cancelled', 'backed_out', 'failed'];

    /** Not yet closed, rejected or cancelled. */
    public const OPEN_STATUSES = ['draft', 'submitted', 'in_approval', 'approved', 'scheduled', 'implementing', 'implemented', 'review', 'backed_out', 'failed'];

    /** Statuses that occupy the forward schedule (conflict detection looks at these). */
    public const ACTIVE_WINDOW_STATUSES = ['approved', 'scheduled', 'implementing'];

    public const PIR_OUTCOMES = ['successful', 'successful_with_issues', 'failed', 'backed_out'];

    public const CLOSURE_CODES = ['implemented', 'backed_out', 'failed', 'cancelled', 'rejected', 'superseded'];

    /** Where a change comes from (same shape as Issue::SOURCES). */
    public const SOURCES = [
        'risk' => ['label' => 'Risk', 'model' => Risk::class, 'code' => 'risk_id_code', 'route' => 'risks.show'],
        'incident' => ['label' => 'Incident', 'model' => Incident::class, 'code' => 'incident_id_code', 'route' => 'incidents.show'],
        'vulnerability' => ['label' => 'Vulnerability', 'model' => Vulnerability::class, 'code' => 'vuln_id_code', 'route' => 'vulnerabilities.show'],
        'issue' => ['label' => 'Issue', 'model' => Issue::class, 'code' => 'issue_code', 'route' => 'issues.show'],
        'audit' => ['label' => 'Audit finding', 'model' => null],
        'regulatory' => ['label' => 'Regulatory requirement', 'model' => null],
        'project' => ['label' => 'Project', 'model' => null],
        'other' => ['label' => 'Other', 'model' => null],
    ];

    /* ------------------------------- relations ------------------------------- */

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'change_owner_id');
    }

    public function implementer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'implementer_id');
    }

    public function changeManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'change_manager_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function pirBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pir_by_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChangeStandardTemplate::class, 'standard_template_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ChangeApproval::class)->orderBy('sequence')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ChangeTask::class)->orderBy('sequence')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChangeEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function cabItems(): HasMany
    {
        return $this->hasMany(ChangeCabMeetingItem::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ChangeCabVote::class);
    }

    /* -------------------------------- helpers -------------------------------- */

    public static function generateNextCode(int $organizationId): string
    {
        return static::nextSequentialCode($organizationId, 'change_code', 'CHG-');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** The source record, resolved inside the signed-in bank (tenant scopes apply). */
    public function sourceRecord(): ?Model
    {
        $model = self::SOURCES[$this->source_type]['model'] ?? null;

        return $model && $this->source_id ? $model::find($this->source_id) : null;
    }

    /** Display shape for the source: label, code, title and a link when the record has a page. */
    public function sourceSummary(): array
    {
        $meta = self::SOURCES[$this->source_type] ?? ['label' => str_replace('_', ' ', (string) $this->source_type), 'model' => null];
        $record = $this->sourceRecord();

        return [
            'type' => $this->source_type,
            'label' => $meta['label'],
            'code' => $record ? $record->{$meta['code']} : null,
            'title' => $record?->title,
            'url' => $record && ! empty($meta['route']) ? route($meta['route'], $record->id) : null,
            'reference' => $this->source_reference,
        ];
    }

    /** The affected assets, inside the bank (tenant scope applies). */
    public function affectedAssets(): Collection
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->affected_asset_ids)));
        if (! $ids) {
            return collect();
        }

        return Asset::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'asset_id_code', 'criticality', 'asset_type']);
    }

    /** The current (lowest-sequence pending) approval, or null. */
    public function currentApproval(): ?ChangeApproval
    {
        return $this->approvals->firstWhere('status', 'pending')
            ?? $this->approvals()->where('status', 'pending')->first();
    }

    /** Approved or scheduled changes whose window has not started yet. */
    public function scopeUpcoming($query)
    {
        return $query->whereIn('status', ['approved', 'scheduled'])
            ->whereNotNull('scheduled_start_at')->where('scheduled_start_at', '>=', now())
            ->orderBy('scheduled_start_at');
    }

    /** Changes whose window overlaps [$from, $to]. */
    public function scopeInWindow($query, $from, $to)
    {
        return $query->whereNotNull('scheduled_start_at')
            ->where('scheduled_start_at', '<=', $to)
            ->where(fn ($q) => $q->where('scheduled_end_at', '>=', $from)->orWhere(fn ($w) => $w->whereNull('scheduled_end_at')->where('scheduled_start_at', '>=', $from)));
    }
}
