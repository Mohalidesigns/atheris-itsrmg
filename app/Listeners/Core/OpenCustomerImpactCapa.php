<?php

namespace App\Listeners\Core;

use App\Events\Core\IncidentSaved;
use App\Models\Issue;
use App\Services\Issues\CapaWorkflow;
use App\Services\Issues\IssueWorkflow;
use Illuminate\Support\Str;

/**
 * IT-GOV-CAPA-001 §3.3 — CBN Consumer Protection Regulations §6.0: an operational IT failure that
 * hits customers (erroneous debits, mobile banking downtime, data leakage) must trigger an
 * immediate CAPA log. A customer-impacting incident opens one CAPA, with CPR §6.0 pre-selected;
 * re-saving the incident never opens a second.
 */
class OpenCustomerImpactCapa
{
    public function __construct(private IssueWorkflow $issues, private CapaWorkflow $capa) {}

    public function handle(IncidentSaved $event): void
    {
        $incident = $event->incident;
        if (! config('capa.enabled') || ! config('capa.auto_trigger.incident_customer_impact') || ! $incident->customer_impact || ! $incident->organization_id) {
            return;
        }

        $exists = Issue::withoutGlobalScopes()->where('organization_id', $incident->organization_id)
            ->where('source_type', 'incident')->where('source_id', $incident->id)->whereNotNull('capa_framework')->exists();
        if ($exists) {
            return;
        }

        $cfg = config('capa.auto_trigger');
        $issue = $this->issues->open([
            'organization_id' => $incident->organization_id,
            'source_type' => 'incident',
            'source_id' => $incident->id,
            'title' => 'Customer-impacting failure: '.Str::limit((string) $incident->title, 200),
            'description' => "Raised automatically from incident {$incident->incident_id_code} because it affected customers "
                .'(CBN Consumer Protection Regulations §6.0: immediate CAPA log to prevent systematic recurrence).',
            'severity' => $incident->severity === 'critical' ? 'critical' : 'high',
            'owner_id' => $incident->lead_investigator_id ?? $incident->assigned_to,
        ], null);

        $issue->update([
            'capa_framework' => $cfg['framework'],
            'capa_classification' => $cfg['classification'],
            'framework_reference' => $cfg['framework_reference'],
            'cbn_provisions' => array_values($cfg['provisions']),
            'capa_stage' => 'identification',
            'settling_days' => (int) config('capa.settling_days.default'),
            'stage_signoffs' => [],
        ]);
        $this->issues->log($issue, 'capa', "CAPA opened automatically: incident {$incident->incident_id_code} had customer impact (CBN CPR §6.0).", null, [
            'framework' => $cfg['framework'], 'auto_trigger' => 'incident_customer_impact',
        ]);
        $this->issues->applyCapaSla($issue, null);
        $this->capa->notifyCritical($issue);
    }
}
