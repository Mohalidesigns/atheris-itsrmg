<?php

namespace App\Models;

use App\Models\Capa\CapaAction;
use App\Models\Capa\ContainmentAction;
use App\Models\Capa\RootCause;
use App\Models\Concerns\GeneratesSequentialCode;
use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Ea\ArbSubmission;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        // CAPA (IT-GOV-CAPA-001)
        'cbn_provisions' => 'array',
        'stage_signoffs' => 'array',
        'verification_criteria' => 'array',
        'settling_days' => 'integer',
        'verification_due_at' => 'datetime',
        'containment_completed_at' => 'datetime',
        'rca_completed_at' => 'datetime',
        'plan_approved_at' => 'datetime',
        'implemented_at' => 'datetime',
        'critical_notified_at' => 'datetime',
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
     * lives outside the platform and is cited by source_reference), its code and title attributes
     * and show route. `code` / `title` may be accessors; `columns` lists the real columns to select
     * when listing records (default id + code + title), `with` the relations those accessors need,
     * and `order` the real column to sort by (default code). See sourceColumns().
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
        // code / title are accessors over the catalogue test (ccm_tests).
        'ccm_test' => ['label' => 'CCM test', 'model' => CcmTenantTest::class, 'code' => 'code', 'route' => 'ccm.show',
            'columns' => ['id', 'ccm_test_id'], 'with' => ['test:id,code,title'], 'order' => 'id'],
        'config_drift' => ['label' => 'Configuration drift', 'model' => ConfigDriftEvent::class, 'code' => 'drift_code', 'route' => 'drift.show'],
        'kri' => ['label' => 'KRI breach', 'model' => Kri::class, 'code' => 'code', 'title' => 'name', 'route' => 'kri.show'],
        'core_banking' => ['label' => 'Core banking', 'model' => CoreBankingIntegration::class, 'code' => 'name', 'title' => 'product', 'route' => 'core-banking.show'],
        'finance_variance' => ['label' => 'Budget variance', 'model' => null],
        'access_review' => ['label' => 'Access review', 'model' => AccessReviewCampaign::class, 'code' => 'campaign_code', 'title' => 'name', 'route' => 'access-reviews.show'],
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

    /* ---------------------------- CAPA (IT-GOV-CAPA-001) ---------------------------- */

    /** An issue is a CAPA once a framework path has been chosen for it. */
    public function isCapa(): bool
    {
        return filled($this->capa_framework);
    }

    public function containmentActions(): HasMany
    {
        return $this->hasMany(ContainmentAction::class)->orderBy('performed_at')->orderBy('id');
    }

    public function capaRootCause(): HasOne
    {
        return $this->hasOne(RootCause::class);
    }

    public function capaActions(): HasMany
    {
        return $this->hasMany(CapaAction::class)->orderBy('id');
    }

    /** The earlier CAPA this one is a recurrence of. */
    public function recurrenceOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_of_id');
    }

    /** Later CAPAs raised because this one came back. */
    public function recurrences(): HasMany
    {
        return $this->hasMany(self::class, 'recurrence_of_id')->orderBy('created_at');
    }

    public function scopeCapa($query)
    {
        return $query->whereNotNull('capa_framework');
    }

    public static function generateNextCode(int $organizationId): string
    {
        return static::nextSequentialCode($organizationId, 'issue_code', 'ISS-');
    }

    /** Real columns to select when listing a source type's records. */
    public static function sourceColumns(array $meta): array
    {
        return $meta['columns'] ?? array_values(array_unique(['id', $meta['code'], $meta['title'] ?? 'title']));
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
            'title' => $record?->{$meta['title'] ?? 'title'},
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
