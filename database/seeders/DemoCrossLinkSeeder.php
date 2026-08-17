<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Control;
use App\Models\IncidentResponseProcedure;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskTreatment;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\VulnerabilityTicket;
use Illuminate\Database\Seeder;

/**
 * Wires demo records together across modules so relationship views
 * (linked assets, linked controls, vulnerability tickets, response
 * procedures) render meaningful data out of the box.
 */
class DemoCrossLinkSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Organization::pluck('id') as $orgId) {
            $assets = Asset::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->limit(12)->get();
            $risks = Risk::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->limit(12)->get();
            $vulns = Vulnerability::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->limit(12)->get();
            $controls = Control::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->limit(12)->get();

            // Asset ↔ Risk links
            foreach ($risks as $i => $risk) {
                $slice = $assets->slice(($i * 2) % max(1, $assets->count()), 2);
                if ($slice->isNotEmpty()) {
                    $risk->assets()->syncWithoutDetaching($slice->pluck('id')->all());
                }
            }

            // Asset ↔ Vulnerability links
            foreach ($vulns as $i => $vuln) {
                $slice = $assets->slice(($i * 2 + 1) % max(1, $assets->count()), 2);
                if ($slice->isNotEmpty()) {
                    $vuln->assets()->syncWithoutDetaching($slice->pluck('id')->all());
                }
            }

            // Risk ↔ Control links (risk_controls pivot)
            $effectiveness = ['effective', 'partially_effective', 'ineffective'];
            foreach ($risks as $i => $risk) {
                $slice = $controls->slice(($i * 2) % max(1, $controls->count()), 2);
                foreach ($slice as $j => $control) {
                    $risk->controls()->syncWithoutDetaching([
                        $control->id => ['effectiveness' => $effectiveness[($i + $j) % 3]],
                    ]);
                }
            }

            // Vulnerability tickets — remediation workflow demo data
            if (! VulnerabilityTicket::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
                $counter = 0;
                foreach ($vulns->take(6) as $vuln) {
                    $counter++;
                    $status = ['open', 'in_progress', 'resolved'][$counter % 3];
                    VulnerabilityTicket::create([
                        'organization_id' => $orgId,
                        'vulnerability_id' => $vuln->id,
                        'ticket_code' => 'TKT-'.str_pad($counter, 4, '0', STR_PAD_LEFT),
                        'title' => 'Remediate: '.$vuln->title,
                        'description' => 'Track remediation of '.$vuln->vuln_id_code.' to closure within SLA.',
                        'status' => $status,
                        'priority' => min(5, max(1, 6 - $counter)),
                        'assigned_to' => $vuln->assigned_to,
                        'sla_hours' => 72,
                        'sla_due_at' => now()->addHours(72 - $counter * 10),
                        'resolved_at' => $status === 'resolved' ? now()->subDays(1) : null,
                        'resolution_notes' => $status === 'resolved' ? 'Patch applied and verified on affected hosts.' : null,
                    ]);
                }
            }

            // Risk assessments & treatment plans so those registers render
            $userId = User::withoutGlobalScopes()->where('organization_id', $orgId)->value('id');
            if ($userId && ! RiskAssessment::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
                foreach ($risks->take(8) as $i => $risk) {
                    $likelihood = $risk->inherent_likelihood ?: rand(2, 5);
                    $impact = $risk->inherent_impact ?: rand(2, 5);
                    RiskAssessment::create([
                        'organization_id' => $orgId,
                        'risk_id' => $risk->id,
                        'assessed_by' => $userId,
                        'methodology' => 'qualitative',
                        'assessment_type' => $i % 3 === 0 ? 'residual' : 'inherent',
                        'likelihood' => $likelihood,
                        'impact' => $impact,
                        'score' => $likelihood * $impact,
                        'rating' => Risk::calculateRating($likelihood * $impact),
                        'justification' => 'Periodic assessment based on current control coverage and threat activity.',
                        'assessment_date' => now()->subDays(10 + $i * 7),
                        'next_review_date' => now()->addMonths(3)->toDateString(),
                    ]);
                }
            }

            if ($userId && ! RiskTreatment::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
                $strategies = ['mitigate', 'transfer', 'accept', 'avoid'];
                $statuses = ['draft', 'approved', 'in_progress', 'completed'];
                foreach ($risks->take(6) as $i => $risk) {
                    $status = $statuses[$i % 4];
                    RiskTreatment::create([
                        'organization_id' => $orgId,
                        'risk_id' => $risk->id,
                        'title' => 'Treatment plan: '.str($risk->title)->limit(60),
                        'description' => 'Planned actions to bring the risk within appetite.',
                        'strategy' => $strategies[$i % 4],
                        'status' => $status,
                        'assigned_to' => $userId,
                        'due_date' => now()->addDays(30 + $i * 15)->toDateString(),
                        'priority' => ($i % 5) + 1,
                        'completion_percentage' => $status === 'completed' ? 100 : ($status === 'in_progress' ? rand(20, 80) : 0),
                        'completed_at' => $status === 'completed' ? now()->subDays(5) : null,
                        'target_likelihood' => 2,
                        'target_impact' => 2,
                        'target_score' => 4,
                    ]);
                }
            }

            // Incident response procedures
            if (! IncidentResponseProcedure::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
                $procedures = [
                    [
                        'title' => 'Ransomware Containment & Recovery',
                        'category' => 'malware',
                        'incident_types' => ['ransomware', 'malware'],
                        'steps' => [
                            'Isolate affected hosts from the network immediately',
                            'Preserve volatile evidence (memory image, active connections)',
                            'Identify ransomware family and initial access vector',
                            'Activate backups validation and restore plan',
                            'Notify CBN within 24 hours if customer impact confirmed',
                            'Conduct post-incident review and update controls',
                        ],
                    ],
                    [
                        'title' => 'Phishing / Business Email Compromise Response',
                        'category' => 'phishing',
                        'incident_types' => ['phishing', 'social_engineering'],
                        'steps' => [
                            'Quarantine reported message across all mailboxes',
                            'Reset credentials for affected users and revoke sessions',
                            'Review mail-forwarding rules and OAuth grants',
                            'Block sender domains and indicators at the gateway',
                            'Run awareness follow-up with affected business units',
                        ],
                    ],
                    [
                        'title' => 'Data Breach Notification (NDPA 72-hour)',
                        'category' => 'data_breach',
                        'incident_types' => ['data_breach', 'unauthorized_access'],
                        'steps' => [
                            'Confirm scope of personal data affected',
                            'Engage DPO and legal counsel',
                            'Prepare NDPC notification within 72 hours of discovery',
                            'Assess need for individual notification',
                            'Document remediation and root cause',
                        ],
                    ],
                ];

                foreach ($procedures as $p) {
                    IncidentResponseProcedure::create([
                        'organization_id' => $orgId,
                        'title' => $p['title'],
                        'description' => 'Standard operating procedure: '.$p['title'].'.',
                        'category' => $p['category'],
                        'incident_types' => $p['incident_types'],
                        'steps' => $p['steps'],
                        'escalation_contacts' => ['CISO', 'Head of IT Operations', 'DPO'],
                        'is_active' => true,
                        'version' => '1.0',
                        'last_reviewed' => now()->subMonths(2)->toDateString(),
                    ]);
                }
            }
        }
    }
}
