<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatIrNarrative;
use App\Modules\CBNCSAT\Models\CsatMaCompensatingControl;
use App\Modules\CBNCSAT\Models\CsatMaNarrative;
use App\Modules\CBNCSAT\Models\CsatMaResponse;
use App\Modules\CBNCSAT\Models\CsatMaScore;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use App\Modules\CBNCSAT\Models\CsatStakeholderEngagement;
use App\Modules\CBNCSAT\Models\CsatThreatCatalogue;
use App\Modules\CBNCSAT\Services\CsatInsightService;
use App\Modules\CBNCSAT\Services\InherentRiskScoringService;
use App\Modules\CBNCSAT\Services\MaturityScoringService;
use Illuminate\Database\Seeder;

/**
 * Turns the two demo CSAT cycles into coherent stories:
 *  - Kano Heritage: a complete, deterministic cycle that passes the Sheet 6 checklist, so the
 *    four-stage approval → submit-to-CBN flow can be demonstrated end to end.
 *  - First Bank: a partially complete cycle (~75% maturity answered) that the checklist blocks.
 * Scores are computed by the scoring services, never hard-coded. Idempotent per assessment.
 */
class CsatDemoCompletionSeeder extends Seeder
{
    public function run(): void
    {
        $kano = Organization::where('slug', 'kano-heritage-bank')->value('id');
        $firstBank = Organization::where('slug', 'first-bank-nigeria')->value('id');

        foreach (CsatAssessment::withoutGlobalScopes()->whereIn('organization_id', array_filter([$kano, $firstBank]))->get() as $assessment) {
            CsatMaNarrative::provisionFor($assessment->id);
            if ($assessment->threats()->exists()) {
                continue; // already completed by an earlier run
            }
            $complete = $assessment->organization_id === $kano;
            $userId = User::withoutGlobalScopes()->where('organization_id', $assessment->organization_id)->value('id');

            if ($complete) {
                $this->answerAllStatements($assessment, $userId);
                $this->narratives($assessment, $userId);
            }
            $this->stakeholders($assessment, $complete ? 10 : 6);
            $this->registers($assessment, $userId, $complete ? 8 : 3, $complete ? 6 : 2);

            app(InherentRiskScoringService::class)->recalculateAndPersist($assessment->id);
            app(MaturityScoringService::class)->recalculateAndPersist($assessment->id);
            CsatMaScore::where('assessment_id', $assessment->id)->where('score_type', 'domain')
                ->update(['target_maturity_level' => 3]);

            $assessment->update([
                'status' => 'in_progress',
                'submission_deadline' => $assessment->submission_deadline ?? now()->addMonths(3),
            ]);
            app(CsatInsightService::class)->generate($assessment->fresh());
        }
    }

    /** Baseline met everywhere, Evolving mostly met, higher levels progressively weaker. */
    private function answerAllStatements(CsatAssessment $assessment, ?int $userId): void
    {
        foreach (CsatMaStatement::where('is_active', true)->get() as $s) {
            $h = crc32("csat-demo-{$s->id}") % 100;
            $response = match ($s->maturity_level) {
                1 => $h < 8 ? 'yes_cc' : 'yes',
                2 => $h < 82 ? 'yes' : ($h < 90 ? 'yes_cc' : 'no'),
                3 => $h < 45 ? 'yes' : ($h < 50 ? 'na' : 'no'),
                default => $h < 15 ? 'yes' : ($h < 22 ? 'na' : 'no'),
            };
            $r = CsatMaResponse::updateOrCreate(
                ['assessment_id' => $assessment->id, 'statement_id' => $s->id],
                ['response' => $response, 'has_compensating_control' => $response === 'yes_cc', 'responded_by' => $userId, 'responded_at' => now()->subDays(10 + $h % 40)]
            );
            if ($response === 'yes_cc') {
                CsatMaCompensatingControl::updateOrCreate(['response_id' => $r->id], [
                    'control_name' => 'Interim manual control — '.str($s->component_name)->limit(60),
                    'control_description' => 'Manual review performed weekly by the information security team and evidenced in the Evidence Vault until the automated control is deployed.',
                    'effectiveness_level' => ['high', 'medium', 'medium', 'low'][$h % 4],
                    'planned_permanent_date' => now()->addMonths(2 + $h % 6)->toDateString(),
                    'created_by' => $userId,
                ]);
            }
        }
    }

    private function narratives(CsatAssessment $assessment, ?int $userId): void
    {
        foreach ($assessment->maNarratives()->get() as $n) {
            if (blank($n->response_text)) {
                $n->update([
                    'response_text' => 'Kano Heritage Bank addresses this through its Board-approved Information Security Programme. '
                        .'Ownership sits with the CISO, reporting quarterly to the Board Risk Committee; evidence (policies, minutes, test reports) '
                        .'is held in the Evidence Vault and reviewed by Internal Audit annually. Improvements identified in the last cycle are tracked as issues.',
                    'responded_by' => $userId,
                ]);
            }
        }
        $text = [
            1 => 'MainOne and MTN dual ISP links; 14 third parties with monitored VPN access; AWS af-south-1 for DR; 3 EOL systems ring-fenced.',
            2 => 'Internet banking (in-house), mobile app on iOS/Android (310k users), 2,400 PoS via Interswitch, 1,200 ATMs.',
            3 => 'Verve and Mastercard debit programmes; NIP and USSD payment channels; 1,850 agent banking outlets.',
            4 => 'Primary DC in Kano, DR in Lagos (co-located); quarterly restore tests; 42 IT and 9 security staff (CISSP, CISM, ISO 27001 LA).',
            5 => 'Two phishing-led incidents and one card-skimming case in the last 12 months, all reported to the CBN within 24 hours.',
        ];
        $fields = [
            1 => ['isp_provider_names', 'List all ISP providers and connection types'],
            2 => ['mobile_app_details', 'Mobile banking app details (platforms, download count)'],
            3 => ['card_programmes', 'Card programmes details (schemes, volumes)'],
            4 => ['dr_facility', 'DR facility location, ownership, and management details'],
            5 => ['incident_summary', 'Summary of cybersecurity incidents in the past 12 months'],
        ];
        foreach ($text as $category => $value) {
            [$key, $label] = $fields[$category];
            CsatIrNarrative::updateOrCreate(
                ['assessment_id' => $assessment->id, 'category_code' => $category, 'narrative_key' => $key],
                ['narrative_label' => $label, 'narrative_value' => $value]
            );
        }
    }

    private function stakeholders(CsatAssessment $assessment, int $roles): void
    {
        $names = ['Musa Abubakar', 'Grace Okafor', 'Chidi Okonkwo', 'Funmi Ogundimu', 'Segun Akinola', 'Hauwa Mohammed', 'Tunde Olatunji', 'Zainab Abdullahi', 'Fatima Bello', 'Adaeze Kunle-Usman'];
        foreach (array_slice(CsatStakeholderEngagement::ROLES, 0, $roles, true) as $key => $label) {
            $i = array_search($key, array_keys(CsatStakeholderEngagement::ROLES), true);
            $status = $key === 'head_trade' ? 'yes_with_comment' : 'yes';
            CsatStakeholderEngagement::updateOrCreate(
                ['assessment_id' => $assessment->id, 'role_key' => $key],
                ['role_label' => $label, 'engagement_status' => $status, 'name_of_person' => $names[$i],
                    'comment' => $status === 'yes_with_comment' ? 'Engaged via the Trade Services ops committee; formal sign-off pending.' : null]
            );
        }
    }

    private function registers(CsatAssessment $assessment, ?int $userId, int $threats, int $vulns): void
    {
        foreach (CsatThreatCatalogue::where('is_active', true)->orderBy('id')->limit($threats)->get() as $i => $c) {
            $assessment->threats()->create([
                'threat_name' => $c->threat_name,
                'catalogue_threat_id' => $c->id,
                'description' => $c->description,
                'threat_source' => $c->threat_source,
                'threat_category' => $c->threat_category,
                'likelihood' => $c->typical_likelihood,
                'impact' => $c->typical_impact,
                // One high threat left without documented mitigation so the insight engine has something to flag.
                'mitigating_controls_desc' => $i === 0 ? null : 'Controls mapped in the AUCS library; monitored via CCM and SOC use-cases.',
                'created_by' => $userId,
            ]);
        }
        $catalogue = [
            ['Unpatched internet-facing servers', 'technology', 'high', 'high'],
            ['Privileged accounts without MFA', 'technology', 'moderate', 'high'],
            ['Phishing susceptibility of branch staff', 'people', 'high', 'moderate'],
            ['No formal third-party access review', 'process', 'moderate', 'moderate'],
            ['Backups not tested for immutable restore', 'process', 'low', 'high'],
            ['Shadow IT SaaS in business units', 'technology', 'moderate', 'low'],
        ];
        foreach (array_slice($catalogue, 0, $vulns) as $i => [$name, $category, $likelihood, $impact]) {
            $assessment->vulnerabilities()->create([
                'vulnerability_name' => $name,
                'vulnerability_category' => $category,
                'likelihood_of_exploit' => $likelihood,
                'impact_if_exploited' => $impact,
                'mitigants_in_place' => $i % 2 === 1,
                'existing_mitigants' => $i % 2 === 1 ? 'Partial — compensating monitoring in place.' : null,
                'planned_mitigants' => 'Remediation tracked in the Issues module.',
                'remediation_status' => ['identified', 'assigned', 'in_progress', 'remediated'][$i % 4],
                'assigned_to' => $userId,
                'due_date' => now()->addDays(30 + 15 * $i)->toDateString(),
                'created_by' => $userId,
            ]);
        }
    }
}
