<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\Gap;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Top up data for ISMS, PCI, BCP and Monitoring modules.
 * Produces:
 *  - Full ISO 27001:2022 Annex A (93 leaf controls + 4 theme rows)
 *  - Statement of Applicability (SoA) covering every leaf control
 *  - Gaps / non-conformities (ISMS gap analysis)
 *  - BCP test records for every plan (so Tests page isn't empty after first seed)
 */
class IsmsPciMonitoringSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'kano-heritage-bank')->first();
        if (! $org) {
            $this->command?->warn('Kano Heritage org not found — skipping ISMS top-up.');

            return;
        }
        $orgId = $org->id;
        $users = User::where('organization_id', $orgId)->pluck('id')->toArray();

        $this->seedIsoAnnexA($orgId);
        $this->seedSoa($orgId, $users);
        $this->seedGaps($orgId, $users);
        $this->seedBcpTests($orgId, $users);
    }

    /* ============================ ISO 27001 Annex A ============================ */
    private function seedIsoAnnexA(int $orgId): void
    {
        $fw = ControlFramework::where('slug', 'iso-27001-2022')->first();
        if (! $fw) {
            $fw = ControlFramework::create([
                'name' => 'ISO/IEC 27001:2022',
                'slug' => 'iso-27001-2022',
                'short_name' => 'ISO 27001',
                'description' => 'Information Security Management System — ISO/IEC 27001:2022 Annex A',
                'version' => '2022',
                'issuing_body' => 'ISO',
                'category' => 'standard',
                'jurisdiction' => 'global',
                'is_system' => true,
                'is_active' => true,
                'effective_date' => '2022-10-25',
            ]);
        }

        // Canonical Annex A 2022 controls — 93 across 4 themes
        $annexA = [
            'A.5' => ['name' => 'Organizational Controls', 'controls' => [
                [1, 'Policies for information security'],
                [2, 'Information security roles and responsibilities'],
                [3, 'Segregation of duties'],
                [4, 'Management responsibilities'],
                [5, 'Contact with authorities'],
                [6, 'Contact with special interest groups'],
                [7, 'Threat intelligence'],
                [8, 'Information security in project management'],
                [9, 'Inventory of information and other associated assets'],
                [10, 'Acceptable use of information and other associated assets'],
                [11, 'Return of assets'],
                [12, 'Classification of information'],
                [13, 'Labelling of information'],
                [14, 'Information transfer'],
                [15, 'Access control'],
                [16, 'Identity management'],
                [17, 'Authentication information'],
                [18, 'Access rights'],
                [19, 'Information security in supplier relationships'],
                [20, 'Addressing information security within supplier agreements'],
                [21, 'Managing information security in the ICT supply chain'],
                [22, 'Monitoring, review and change management of supplier services'],
                [23, 'Information security for use of cloud services'],
                [24, 'Information security incident management planning and preparation'],
                [25, 'Assessment and decision on information security events'],
                [26, 'Response to information security incidents'],
                [27, 'Learning from information security incidents'],
                [28, 'Collection of evidence'],
                [29, 'Information security during disruption'],
                [30, 'ICT readiness for business continuity'],
                [31, 'Legal, statutory, regulatory and contractual requirements'],
                [32, 'Intellectual property rights'],
                [33, 'Protection of records'],
                [34, 'Privacy and protection of PII'],
                [35, 'Independent review of information security'],
                [36, 'Compliance with policies, rules and standards for information security'],
                [37, 'Documented operating procedures'],
            ]],
            'A.6' => ['name' => 'People Controls', 'controls' => [
                [1, 'Screening'],
                [2, 'Terms and conditions of employment'],
                [3, 'Information security awareness, education and training'],
                [4, 'Disciplinary process'],
                [5, 'Responsibilities after termination or change of employment'],
                [6, 'Confidentiality or non-disclosure agreements'],
                [7, 'Remote working'],
                [8, 'Information security event reporting'],
            ]],
            'A.7' => ['name' => 'Physical Controls', 'controls' => [
                [1, 'Physical security perimeters'],
                [2, 'Physical entry'],
                [3, 'Securing offices, rooms and facilities'],
                [4, 'Physical security monitoring'],
                [5, 'Protecting against physical and environmental threats'],
                [6, 'Working in secure areas'],
                [7, 'Clear desk and clear screen'],
                [8, 'Equipment siting and protection'],
                [9, 'Security of assets off-premises'],
                [10, 'Storage media'],
                [11, 'Supporting utilities'],
                [12, 'Cabling security'],
                [13, 'Equipment maintenance'],
                [14, 'Secure disposal or re-use of equipment'],
            ]],
            'A.8' => ['name' => 'Technological Controls', 'controls' => [
                [1, 'User endpoint devices'],
                [2, 'Privileged access rights'],
                [3, 'Information access restriction'],
                [4, 'Access to source code'],
                [5, 'Secure authentication'],
                [6, 'Capacity management'],
                [7, 'Protection against malware'],
                [8, 'Management of technical vulnerabilities'],
                [9, 'Configuration management'],
                [10, 'Information deletion'],
                [11, 'Data masking'],
                [12, 'Data leakage prevention'],
                [13, 'Information backup'],
                [14, 'Redundancy of information processing facilities'],
                [15, 'Logging'],
                [16, 'Monitoring activities'],
                [17, 'Clock synchronisation'],
                [18, 'Use of privileged utility programs'],
                [19, 'Installation of software on operational systems'],
                [20, 'Networks security'],
                [21, 'Security of network services'],
                [22, 'Segregation in networks'],
                [23, 'Web filtering'],
                [24, 'Use of cryptography'],
                [25, 'Secure development life cycle'],
                [26, 'Application security requirements'],
                [27, 'Secure system architecture and engineering principles'],
                [28, 'Secure coding'],
                [29, 'Security testing in development and acceptance'],
                [30, 'Outsourced development'],
                [31, 'Separation of development, test and production environments'],
                [32, 'Change management'],
                [33, 'Test information'],
                [34, 'Protection of information systems during audit and testing'],
            ]],
        ];

        $order = 0;
        foreach ($annexA as $theme => $data) {
            // Theme row (level 0)
            DB::table('framework_requirements')->updateOrInsert(
                ['framework_id' => $fw->id, 'requirement_code' => $theme],
                [
                    'title' => $data['name'],
                    'description' => 'ISO/IEC 27001:2022 Annex A theme — '.$data['name'],
                    'section' => 'Annex A',
                    'level' => 0,
                    'is_mandatory' => true,
                    'sort_order' => $order++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            foreach ($data['controls'] as [$num, $title]) {
                $code = $theme.'.'.$num;
                DB::table('framework_requirements')->updateOrInsert(
                    ['framework_id' => $fw->id, 'requirement_code' => $code],
                    [
                        'parent_id' => DB::table('framework_requirements')
                            ->where('framework_id', $fw->id)
                            ->where('requirement_code', $theme)->value('id'),
                        'title' => $title,
                        'description' => "ISO/IEC 27001:2022 Annex A control {$code} — {$title}.",
                        'section' => 'Annex A',
                        'level' => 1,
                        'is_mandatory' => true,
                        'sort_order' => $order++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    /* =================== Statement of Applicability (SoA) =================== */
    private function seedSoa(int $orgId, array $users): void
    {
        $fw = ControlFramework::where('slug', 'iso-27001-2022')->first();
        $leafReqs = DB::table('framework_requirements')
            ->where('framework_id', $fw->id)
            ->where('level', 1)
            ->get();

        $controls = Control::where('organization_id', $orgId)->pluck('id')->toArray();

        foreach ($leafReqs as $req) {
            $applicable = rand(1, 100) <= 92; // ~92% applicable
            $implementation = $applicable ? $this->weighted([
                'implemented' => 55, 'partial' => 25, 'planned' => 12, 'not_started' => 8,
            ]) : 'not_applicable';

            DB::table('statements_of_applicability')->updateOrInsert(
                ['organization_id' => $orgId, 'requirement_id' => $req->id],
                [
                    'is_applicable' => $applicable,
                    'justification' => $applicable
                        ? $this->applicableJustification($req->requirement_code)
                        : 'Not applicable — Kano Heritage does not process this scenario (see exception register).',
                    'implementation_status' => $implementation,
                    'control_id' => ! empty($controls) ? $controls[array_rand($controls)] : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function applicableJustification(string $code): string
    {
        $map = [
            'A.5.1' => 'Information Security Policy suite ratified by the Board on 2025-11-03.',
            'A.5.7' => 'ngCERT and NITDA advisory feeds ingested daily via the Atheris Regulatory Intelligence module.',
            'A.5.24' => 'Incident response plan tested quarterly with CBN ITSM alignment.',
            'A.5.30' => 'DR runbooks cover NIBSS, Finacle, ATM reroute, USSD failover; tested bi-annually.',
            'A.7.4' => 'CCTV + badge-access monitoring covers Kano HQ and Lagos DR data centres 24/7.',
            'A.8.2' => 'Privileged access governed by AD groups + CyberArk with quarterly recert.',
            'A.8.5' => 'MFA enforced across all staff + customer-facing banking via Entra ID.',
            'A.8.8' => 'Tenable + Qualys scanners feed Atheris; CBN-aligned SLA (C<72h, H<14d) enforced.',
            'A.8.15' => 'SIEM forwards every access event with 12-year retention in S3 Object Lock.',
            'A.8.24' => 'HSM-backed key management (Thales Luna). Annual key-ceremony attested by CISO + CRO.',
        ];

        return $map[$code] ?? 'Implemented per Atheris AUCS canonical mapping; evidence in the Evidence Vault.';
    }

    /* ========================= Gaps / Non-conformities ========================= */
    private function seedGaps(int $orgId, array $users): void
    {
        if (DB::table('gaps')->where('organization_id', $orgId)->count() > 5) {
            return;
        }

        $fw = ControlFramework::where('slug', 'iso-27001-2022')->first();
        $reqs = DB::table('framework_requirements')
            ->where('framework_id', $fw->id)
            ->where('level', 1)->pluck('id', 'requirement_code');

        $gaps = [
            ['A.5.1', 'high', 'identified', 'Policy suite review cycle exceeded 12 months — schedule annual review'],
            ['A.5.7', 'low', 'in_progress', 'Threat-intel ingestion currently manual for ngCERT — automate via API'],
            ['A.5.30', 'high', 'in_progress', 'USSD failover DR runbook last tested > 180 days ago'],
            ['A.6.3', 'low', 'identified', 'Security awareness training coverage at 87% (target 95%)'],
            ['A.7.4', 'low', 'identified', 'Lagos DR CCTV retention only 60 days (target 90 days)'],
            ['A.8.2', 'high', 'identified', 'Privileged AD accounts without MFA — 12 identified'],
            ['A.8.3', 'low', 'in_progress', 'Role-based access not fully implemented on Finacle direct SQL'],
            ['A.8.8', 'high', 'in_progress', 'Critical patches > 72h on 6 systems'],
            ['A.8.9', 'low', 'remediated', 'Configuration baselines drift on Windows servers — remediated 2026-03-15'],
            ['A.8.15', 'low', 'identified', 'Log forwarding gaps on 3 legacy AIX systems'],
            ['A.8.24', 'high', 'in_progress', 'TLS 1.0 still enabled on internal admin portal'],
            ['A.8.28', 'low', 'identified', 'SAST coverage missing for 2 legacy repos'],
            ['A.5.24', 'critical', 'identified', 'NDPC 72h breach form not automatically populated — manual process'],
            ['A.5.34', 'low', 'identified', 'PII impact assessment not completed for new USSD feature'],
            ['A.6.7', 'low', 'in_progress', 'Remote-working policy signed by only 82% of contractors'],
        ];

        $severityToPriority = Gap::SEVERITY_PRIORITY;

        foreach ($gaps as $i => [$code, $severity, $status, $title]) {
            $reqId = $reqs[$code] ?? null;
            DB::table('gaps')->insert([
                'organization_id' => $orgId,
                'requirement_id' => $reqId,
                'gap_code' => 'KHB-GAP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $title,
                'description' => 'ISMS non-conformity identified during internal audit — '.$title,
                'severity' => $severity,
                'status' => $status,
                'priority' => $severityToPriority[$severity] ?? 3,
                'remediation_plan' => 'Assign owner, document corrective action, track to closure.',
                'assigned_to' => $users[array_rand($users)] ?? null,
                'due_date' => now()->addDays(rand(15, 120))->toDateString(),
                'notes' => 'Auto-seeded from Kano Heritage ISMS internal audit Q1 2026.',
                'created_at' => now()->subDays(rand(1, 120)),
                'updated_at' => now(),
            ]);
        }
    }

    /* ======================== BCP Tests (top-up) ======================== */
    private function seedBcpTests(int $orgId, array $users): void
    {
        if (DB::table('bcp_tests')->count() > 5) {
            return;
        }

        $plans = DB::table('bcp_plans')->where('organization_id', $orgId)->get();
        $testTypes = ['tabletop', 'walkthrough', 'simulation', 'parallel', 'full_interruption'];
        $outcomes = ['passed', 'passed_with_issues', 'failed'];

        $columns = \Schema::getColumnListing('bcp_tests');

        $i = 1;
        $statusMap = ['passed' => 'completed', 'passed_with_issues' => 'completed', 'failed' => 'completed'];
        $passFailMap = ['passed' => 'pass', 'passed_with_issues' => 'partial', 'failed' => 'fail'];

        foreach ($plans as $p) {
            foreach (range(1, 2) as $n) {
                $outcome = $outcomes[array_rand($outcomes)];
                $row = [
                    'organization_id' => $orgId,
                    'plan_id' => $p->id,
                    'title' => $p->title.' — '.ucfirst($testTypes[array_rand($testTypes)]).' exercise '.$n,
                    'test_type' => $testTypes[array_rand($testTypes)],
                    'status' => $statusMap[$outcome] ?? 'completed',
                    'scheduled_date' => now()->subDays(rand(-30, 180))->toDateString(),
                    'completed_date' => now()->subDays(rand(1, 180))->toDateString(),
                    'conducted_by' => $users[array_rand($users)] ?? null,
                    'results' => 'Scenario exercise conducted across DR, channels and core banking teams.',
                    'findings' => rand(0, 5)
                        ? 'Findings: RTO slippage on DB cutover; minor comms gap on stakeholder notification.'
                        : 'No material findings.',
                    'recommendations' => 'Update DR runbook §4.2; refresh contact tree; retest in 90 days.',
                    'pass_fail' => $passFailMap[$outcome] ?? 'pass',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $row = array_intersect_key($row, array_flip($columns));
                DB::table('bcp_tests')->insert($row);
                $i++;
            }
        }
    }

    private function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $r = mt_rand(1, $total);
        $cum = 0;
        foreach ($weights as $k => $w) {
            $cum += $w;
            if ($r <= $cum) {
                return $k;
            }
        }

        return array_key_last($weights);
    }
}
