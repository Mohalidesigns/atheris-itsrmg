<?php

namespace App\Models;

use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Ea\ArbSubmission;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A corrective / preventive action (CAPA) raised from any finding: an audit observation, a failed
 * control test, a risk, an incident, a vulnerability, a compliance gap, an NDPA finding or an ARB
 * condition. One register, one SLA clock, one verification step.
 */
class Issue extends Model
{
    use BelongsToOrganization, GeneratesSequentialCode, HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'sla_due_at' => 'datetime',
        'sla_paused_at' => 'datetime',
        'escalated_at' => 'datetime',
        'remediated_at' => 'datetime',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
        'ticket_synced_at' => 'datetime',
    ];

    public const SEVERITIES = ['critical', 'high', 'moderate', 'low'];

    /** Lifecycle, in order. Escalation is a flag (escalation_level), not a status. */
    public const STATUSES = ['open', 'in_progress', 'blocked', 'remediated', 'verified', 'closed'];

    /** Statuses whose SLA clock is still running (blocked pauses it). */
    public const OPEN_STATUSES = ['open', 'in_progress', 'blocked'];

    /** Remediation done, awaiting or past verification: the clock has stopped. */
    public const DONE_STATUSES = ['remediated', 'verified', 'closed'];

    /**
     * Where an issue can come from: label, the model of its source record (null when the finding
     * lives outside the platform and is cited by source_reference), its code column and show route.
     */
    public const SOURCES = [
        'audit' => ['label' => 'Audit finding', 'model' => null],
        'control_test' => ['label' => 'Control test', 'model' => Control::class, 'code' => 'control_code', 'route' => 'controls.show'],
        'risk' => ['label' => 'Risk', 'model' => Risk::class, 'code' => 'risk_id_code', 'route' => 'risks.show'],
        'incident' => ['label' => 'Incident', 'model' => Incident::class, 'code' => 'incident_id_code', 'route' => 'incidents.show'],
        'vulnerability' => ['label' => 'Vulnerability', 'model' => Vulnerability::class, 'code' => 'vuln_id_code', 'route' => 'vulnerabilities.show'],
        'assessment' => ['label' => 'Compliance gap', 'model' => Gap::class, 'code' => 'gap_code', 'route' => null],
        'ndpa_finding' => ['label' => 'NDPA finding', 'model' => null],
        'ea_arb_condition' => ['label' => 'ARB condition', 'model' => ArbSubmission::class, 'code' => 'code', 'route' => 'ea.arb.show'],
        'ccm_test' => ['label' => 'CCM test', 'model' => CcmTenantTest::class, 'code' => 'code', 'route' => 'ccm.show'],
        'config_drift' => ['label' => 'Configuration drift', 'model' => ConfigDriftEvent::class, 'code' => 'drift_code', 'route' => 'drift.show'],
        'kri' => ['label' => 'KRI breach', 'model' => Kri::class, 'code' => 'code', 'route' => 'kri.show'],
        'access_review' => ['label' => 'Access review', 'model' => AccessReviewCampaign::class, 'code' => 'campaign_code', 'route' => 'access-reviews.show'],
    ];

    /** Issue severity → SLA policy severity (the policy table uses the vulnerability scale). */
    public const POLICY_SEVERITY = ['critical' => 'critical', 'high' => 'high', 'moderate' => 'medium', 'low' => 'low'];

    public function events(): HasMany
    {
        return $this->hasMany(IssueEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function remediator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remediated_by_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    public function itsmConnection(): BelongsTo
    {
        return $this->belongsTo(ItsmConnection::class);
    }

    public static function generateNextCode(int $organizationId): string
    {
        return static::nextSequentialCode($organizationId, 'issue_code', 'ISS-');
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

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** on_track / at_risk (last 20% of the window) / breached / paused / met / missed. */
    public function slaState(): string
    {
        if (! $this->sla_due_at) {
            return 'none';
        }
        if (in_array($this->status, self::DONE_STATUSES, true)) {
            return $this->remediated_at && $this->remediated_at->lte($this->sla_due_at) ? 'met' : 'missed';
        }
        if ($this->status === 'blocked') {
            return 'paused';
        }
        if (now()->gt($this->sla_due_at)) {
            return 'breached';
        }
        $window = max(1, (int) $this->sla_minutes);

        return now()->diffInMinutes($this->sla_due_at) <= $window * 0.2 ? 'at_risk' : 'on_track';
    }

    public function scopeOverdue($query)
    {
        return $query->whereIn('status', ['open', 'in_progress'])->where('sla_due_at', '<', now());
    }
}
