<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\QuestionLibrary;
use Illuminate\Database\Seeder;

/**
 * Reusable assessment questions for the demo bank tenants — risk workshops,
 * CBN/NDPA compliance self-assessment, third-party due diligence and ISMS audits.
 */
class QuestionLibrarySeeder extends Seeder
{
    private const QUESTIONS = [
        // [module, category, title, question, response_type, options, weight]
        ['risk', 'Cyber', 'Privileged access MFA', 'Is multi-factor authentication enforced for every privileged (domain, database, Finacle admin) account?', 'yes_no', null, 5],
        ['risk', 'Cyber', 'Ransomware recovery', 'How confident are you that core banking can be restored from immutable backups within the 4-hour RTO?', 'scale', null, 5],
        ['risk', 'Cyber', 'Patch latency', 'How long does it typically take to patch a critical internet-facing vulnerability?', 'multiple_choice', ['Under 72 hours', '3–14 days', '15–30 days', 'Over 30 days'], 4],
        ['risk', 'Fraud', 'SIM-swap controls', 'Are SIM-swap checks (NIBSS/telco API) applied before high-value mobile transfers?', 'yes_no', null, 4],
        ['risk', 'Operational Technology', 'NIP channel resilience', 'Rate the resilience of the NIBSS NIP integration to a peak-hour failover.', 'scale', null, 3],
        ['risk', 'Third-Party', 'Critical vendor concentration', 'Describe single points of failure on Interswitch, NIBSS or cloud providers and their exit plans.', 'text', null, 3],
        ['risk', 'Data Protection', 'BVN data minimisation', 'To what extent is BVN data minimised and masked outside the KYC system of record?', 'scale', null, 4],
        ['compliance', 'Regulatory', 'CBN incident notification', 'Can a major cyber incident be reported to CBN within 24 hours of detection, with evidence?', 'yes_no', null, 5],
        ['compliance', 'Regulatory', 'NDPA breach notification', 'Is there a tested procedure to notify the NDPC within 72 hours of a personal-data breach?', 'yes_no', null, 5],
        ['compliance', 'Regulatory', 'CBN-CSAT readiness', 'What is the current maturity of evidence for the CBN-CSAT self-assessment?', 'multiple_choice', ['Not started', 'Partially evidenced', 'Fully evidenced', 'Independently assured'], 3],
        ['vendor', 'Third-Party', 'Vendor SOC 2 / ISO 27001', 'Does the vendor hold a current SOC 2 Type II or ISO 27001 certificate covering the contracted service?', 'yes_no', null, 4],
        ['vendor', 'Third-Party', 'Data residency', 'Where will Nigerian customer data be stored and processed (NDPA cross-border transfer)?', 'text', null, 4],
        ['vendor', 'Third-Party', 'Breach notification SLA', 'What contractual breach-notification window does the vendor commit to?', 'multiple_choice', ['Within 24 hours', 'Within 72 hours', 'Over 72 hours', 'Not specified'], 3],
        ['isms', 'Technology', 'Access review cadence', 'Rate how consistently quarterly user-access reviews are completed and evidenced.', 'scale', null, 3],
        ['isms', 'Technology', 'Logging coverage', 'Are security logs from all tier-1 systems forwarded to the SIEM and retained for 12 months?', 'yes_no', null, 3],
        ['isms', 'Physical', 'Data-centre environmental controls', 'Rate the effectiveness of power, cooling and fire suppression at the primary data centre.', 'scale', null, 2],
    ];

    public function run(): void
    {
        // Only tenants that hold demo data (the bank tenants).
        $orgIds = Organization::whereIn('id', [2, 3])->pluck('id');

        foreach ($orgIds as $orgId) {
            if (QuestionLibrary::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
                continue;
            }
            foreach (self::QUESTIONS as $i => [$module, $category, $title, $text, $type, $options, $weight]) {
                QuestionLibrary::create([
                    'organization_id' => $orgId,
                    'module' => $module,
                    'category' => $category,
                    'title' => $title,
                    'question_text' => $text,
                    'response_type' => $type,
                    'response_options' => $options,
                    'weight' => $weight,
                    // One retired question per tenant so the active/inactive filter has something to show.
                    'is_active' => $i !== 15,
                    'sort_order' => $i + 1,
                ]);
            }
        }
    }
}
