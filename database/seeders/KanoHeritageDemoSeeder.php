<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AucsControl;
use App\Models\BcpPlan;
use App\Models\BiaRecord;
use App\Models\ComplianceAssessment;
use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\DataBreach;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Patch;
use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\PolicyVersion;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\SecurityAlert;
use App\Models\Threat;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\Vulnerability;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Kano Heritage Bank Plc — coherent, cross-linked demo dataset
 * for Atheris ITSRM&G demos, CBN examiner reviews, and design-partner pilots.
 *
 * Volumes (per E2E test spec):
 *   50 Assets, 40 Risks, 60 Threats, 80 Vulnerabilities, 30 Patches,
 *   400+ AUCS Controls, 25 Policies × 5 versions + attestation campaigns + exceptions,
 *   20 Compliance assessments, 15 Incidents + 8 Data breaches + 30 Security alerts,
 *   10 BCP plans + 8 BIA + 5 DR runbooks + 12 exercise runs,
 *   35 Vendors with tiering + questionnaires + continuous-monitoring scores,
 *   50 Obligations, 30 KRIs × 12 monthly readings, 25 Issues,
 *   40+ CCM runs, 20 Users across roles, 1 CBN-CSAT @60% complete.
 */
class KanoHeritageDemoSeeder extends Seeder
{
    protected $orgId;

    public function run(): void
    {
        $this->command?->info('Seeding Kano Heritage Bank Plc demo dataset…');

        $this->org();
        $this->users();
        $this->categories();
        $this->assets();
        $this->vendors();
        $this->threats();
        $this->risks();
        $this->controls();
        $this->policies();
        $this->vulnerabilities();
        $this->patches();
        $this->incidents();
        $this->dataBreaches();
        $this->securityAlerts();
        $this->bcp();
        $this->complianceAssessments();
        $this->demoEvidence();
    }

    /* -------------------------------- Helpers -------------------------------- */
    private function pickStatus(array $statuses): string
    {
        // Realistic distribution weighting
        return $statuses[array_rand($statuses)];
    }

    private function weighted(array $distribution): string
    {
        $rand = mt_rand(1, 100);
        $cum = 0;
        foreach ($distribution as $status => $weight) {
            $cum += $weight;
            if ($rand <= $cum) {
                return $status;
            }
        }

        return array_key_first($distribution);
    }

    private function ts18m(): Carbon
    {
        return now()->subDays(rand(0, 540));
    }

    /* -------------------------------- Seeders -------------------------------- */
    private function org(): void
    {
        $org = Organization::updateOrCreate(
            ['slug' => 'kano-heritage-bank'],
            [
                'name' => 'Kano Heritage Bank Plc',
                'industry' => 'Financial Services',
                'size' => 'large',
                'country' => 'NG',
                'currency' => 'NGN',
                'subscription_plan' => 'enterprise',
                'subscription_expires_at' => now()->addYear(),
                'is_active' => true,
            ]
        );
        $this->orgId = $org->id;
    }

    private function users(): void
    {
        $nigerianNames = [
            ['Adaeze Kunle-Usman', 'CISO', 'admin@kanoheritage.ng', 'Information Security'],
            ['Fatima Bello', 'Chief Risk Officer', 'cro@kanoheritage.ng', 'Enterprise Risk'],
            ['Chidi Okonkwo', 'Head of Internal Audit', 'audit@kanoheritage.ng', 'Internal Audit'],
            ['Ngozi Adeyemi', 'Head of Compliance', 'compliance@kanoheritage.ng', 'Compliance'],
            ['Ibrahim Danjuma', 'Head of IT Operations', 'itops@kanoheritage.ng', 'IT'],
            ['Funmi Ogundimu', 'Risk Analyst', 'risk.analyst@kanoheritage.ng', 'Enterprise Risk'],
            ['Emeka Nwosu', 'Control Tester', 'control.tester@kanoheritage.ng', 'Internal Audit'],
            ['Aisha Yusuf', 'DPCO', 'dpco@kanoheritage.ng', 'Compliance'],
            ['Kunle Adebayo', 'Vendor Risk Manager', 'tprm@kanoheritage.ng', 'TPRM'],
            ['Hauwa Mohammed', 'Policy Author', 'policy@kanoheritage.ng', 'Governance'],
            ['Tunde Olatunji', 'Incident Responder', 'incident@kanoheritage.ng', 'SOC'],
            ['Zainab Abdullahi', 'Board Risk Committee Chair', 'board.risk@kanoheritage.ng', 'Board'],
            ['Segun Akinola', 'Platform Admin', 'sysadmin@kanoheritage.ng', 'IT'],
            ['Obinna Eze', 'Network Engineer', 'network@kanoheritage.ng', 'IT Operations'],
            ['Blessing Iyere', 'Application Owner — Finacle', 'finacle@kanoheritage.ng', 'IT'],
            ['Musa Abubakar', 'Application Owner — Flexcube', 'flexcube@kanoheritage.ng', 'IT'],
            ['Chiamaka Nnamdi', 'Security Operations Analyst', 'soc.analyst@kanoheritage.ng', 'SOC'],
            ['Yusuf Aminu', 'Treasury IT Lead', 'treasury.it@kanoheritage.ng', 'Treasury'],
            ['Bola Adesanya', 'Head of Digital Channels', 'digital@kanoheritage.ng', 'Digital'],
            ['Grace Okafor', 'External Auditor (KPMG)', 'external.auditor@kanoheritage.ng', 'External'],
        ];
        foreach ($nigerianNames as [$name, $title, $email, $dept]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name, 'password' => Hash::make('password'),
                    'organization_id' => $this->orgId,
                    'job_title' => $title, 'department' => $dept,
                    'email_verified_at' => now(),
                ]
            );
            if ($user->roles->isEmpty()) {
                $user->syncRoles($this->roleForTitle($title));
            }
        }
    }

    private function roleForTitle(string $title): string
    {
        $t = strtolower($title);

        return match (true) {
            str_contains($t, 'ciso'), str_contains($t, 'platform admin') => 'Organization Admin',
            str_contains($t, 'audit'), str_contains($t, 'board') => 'Auditor',
            str_contains($t, 'risk') => 'Risk Manager',
            str_contains($t, 'compliance'), str_contains($t, 'dpco'),
            str_contains($t, 'policy'), str_contains($t, 'isms'),
            str_contains($t, 'pci') => 'Compliance Officer',
            str_contains($t, 'incident'), str_contains($t, 'soc'),
            str_contains($t, 'security operations') => 'Security Analyst',
            default => 'Viewer',
        };
    }

    private function userIds(): array
    {
        return User::where('organization_id', $this->orgId)->pluck('id')->toArray();
    }

    private function categories(): void
    {
        $cats = [
            ['Cyber', 'Cyber risk'],
            ['Technology', 'IT / tech risk'],
            ['Operational Technology', 'Ops-tech risk'],
            ['Third-Party', 'Vendor / outsourcing risk'],
            ['Data Protection', 'NDPA / data privacy risk'],
            ['Fraud', 'Financial fraud risk'],
            ['Physical', 'Physical security risk'],
            ['Regulatory', 'Regulatory compliance risk'],
        ];
        foreach ($cats as [$name, $desc]) {
            RiskCategory::updateOrCreate(
                ['organization_id' => $this->orgId, 'name' => $name],
                ['description' => $desc, 'slug' => Str::slug($name)]
            );
        }
    }

    private function assets(): void
    {
        // Target: 50 assets; wipe and reseed deterministically
        Asset::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();

        $assets = [
            // Core banking
            ['KHB-AST-CB-001', 'Finacle Core Banking (Prod)', 'software', 'Core Banking', 'critical', 'active', 'restricted', 'Infosys Finacle 11.x'],
            ['KHB-AST-CB-002', 'Finacle Core Banking (DR)', 'software', 'Core Banking', 'critical', 'active', 'restricted', 'Infosys Finacle 11.x'],
            ['KHB-AST-CB-003', 'Flexcube Core Banking (Legacy)', 'software', 'Core Banking', 'high', 'active', 'restricted', 'Oracle Flexcube 12.4'],
            ['KHB-AST-CB-004', 'Finacle Payments Hub', 'software', 'Payments', 'critical', 'active', 'restricted', 'Infosys'],
            // Channels
            ['KHB-AST-CH-001', 'USSD Channel Service (*894#)', 'software', 'Channel', 'critical', 'active', 'confidential', 'In-house'],
            ['KHB-AST-CH-002', 'Mobile Banking App (iOS/Android)', 'software', 'Channel', 'critical', 'active', 'restricted', 'Appzone'],
            ['KHB-AST-CH-003', 'Internet Banking Portal', 'software', 'Channel', 'critical', 'active', 'restricted', 'In-house'],
            ['KHB-AST-CH-004', 'Agent Banking Platform', 'software', 'Channel', 'high', 'active', 'restricted', 'TeamApt Moniepoint'],
            ['KHB-AST-CH-005', 'ATM Network (1,200 terminals)', 'hardware', 'Channel', 'critical', 'active', 'restricted', 'NCR / Diebold'],
            ['KHB-AST-CH-006', 'PoS Terminal Network (8,500)', 'hardware', 'Channel', 'high', 'active', 'restricted', 'Interswitch Quickteller'],
            ['KHB-AST-CH-007', 'WhatsApp Banking Bot', 'software', 'Channel', 'medium', 'active', 'confidential', 'Twilio'],
            ['KHB-AST-CH-008', 'Call Centre IVR', 'software', 'Channel', 'medium', 'active', 'internal', 'Genesys'],
            // Infrastructure
            ['KHB-AST-IN-001', 'Active Directory — Primary DC', 'hardware', 'Infrastructure', 'critical', 'active', 'restricted', 'Microsoft'],
            ['KHB-AST-IN-002', 'Active Directory — Secondary DC', 'hardware', 'Infrastructure', 'critical', 'active', 'restricted', 'Microsoft'],
            ['KHB-AST-IN-003', 'Microsoft Entra ID (Cloud)', 'cloud_service', 'Infrastructure', 'critical', 'active', 'restricted', 'Microsoft'],
            ['KHB-AST-IN-004', 'Palo Alto Perimeter Firewall (HQ)', 'hardware', 'Network', 'critical', 'active', 'restricted', 'Palo Alto PA-5450'],
            ['KHB-AST-IN-005', 'Palo Alto Perimeter Firewall (DR)', 'hardware', 'Network', 'critical', 'active', 'restricted', 'Palo Alto PA-5450'],
            ['KHB-AST-IN-006', 'Core Switch — Cisco Nexus 9K', 'hardware', 'Network', 'critical', 'active', 'restricted', 'Cisco'],
            ['KHB-AST-IN-007', 'F5 Load Balancer Cluster', 'hardware', 'Network', 'high', 'active', 'restricted', 'F5 BIG-IP'],
            ['KHB-AST-IN-008', 'VPN Concentrator (Cisco ASA)', 'hardware', 'Network', 'high', 'active', 'restricted', 'Cisco ASA'],
            ['KHB-AST-IN-009', 'Kano HQ Data Centre', 'facility', 'Facility', 'critical', 'active', 'restricted', 'Colo'],
            ['KHB-AST-IN-010', 'Lagos DR Data Centre (MainOne)', 'facility', 'Facility', 'critical', 'active', 'restricted', 'MainOne'],
            // Data stores
            ['KHB-AST-DB-001', 'AMB-CORE DB (Oracle 19c)', 'database', 'Data', 'critical', 'active', 'restricted', 'Oracle'],
            ['KHB-AST-DB-002', 'Customer PII Data Vault', 'database', 'Data', 'critical', 'active', 'restricted', 'Oracle'],
            ['KHB-AST-DB-003', 'BVN-Linked Accounts Store', 'database', 'Data', 'critical', 'active', 'restricted', 'Oracle'],
            ['KHB-AST-DB-004', 'Treasury Trade Store (PostgreSQL)', 'database', 'Data', 'high', 'active', 'restricted', 'PostgreSQL 16'],
            ['KHB-AST-DB-005', 'Fraud Detection DB (MySQL)', 'database', 'Data', 'high', 'active', 'confidential', 'MySQL 8'],
            ['KHB-AST-DB-006', 'AML Screening Store', 'database', 'Data', 'high', 'active', 'confidential', 'MongoDB'],
            ['KHB-AST-DB-007', 'Audit Log Archive (S3 Object Lock)', 'cloud_service', 'Data', 'high', 'active', 'confidential', 'AWS'],
            // Servers
            ['KHB-AST-SV-001', 'IBM AIX p770 (Finacle Core)', 'hardware', 'Server', 'critical', 'active', 'restricted', 'IBM'],
            ['KHB-AST-SV-002', 'IBM AIX p770 (Backup) — EOL', 'hardware', 'Server', 'high', 'under_review', 'restricted', 'IBM'],
            ['KHB-AST-SV-003', 'Windows Server 2019 Cluster (App)', 'hardware', 'Server', 'high', 'active', 'confidential', 'Microsoft'],
            ['KHB-AST-SV-004', 'Linux RHEL 9 Cluster (Web)', 'hardware', 'Server', 'high', 'active', 'confidential', 'Red Hat'],
            ['KHB-AST-SV-005', 'SWIFT Gateway Alliance', 'hardware', 'Server', 'critical', 'active', 'restricted', 'SWIFT'],
            ['KHB-AST-SV-006', 'HSM (Thales Luna)', 'hardware', 'Server', 'critical', 'active', 'restricted', 'Thales'],
            ['KHB-AST-SV-007', 'Message Queue (RabbitMQ)', 'software', 'Server', 'medium', 'active', 'internal', 'VMware'],
            // Cloud
            ['KHB-AST-CL-001', 'AWS Production Account', 'cloud_service', 'Cloud', 'critical', 'active', 'restricted', 'AWS'],
            ['KHB-AST-CL-002', 'AWS DR Account', 'cloud_service', 'Cloud', 'critical', 'active', 'restricted', 'AWS'],
            ['KHB-AST-CL-003', 'Microsoft 365 Tenant', 'cloud_service', 'Cloud', 'high', 'active', 'confidential', 'Microsoft'],
            ['KHB-AST-CL-004', 'SharePoint Online', 'cloud_service', 'Cloud', 'medium', 'active', 'confidential', 'Microsoft'],
            // Security tooling
            ['KHB-AST-SC-001', 'Microsoft Sentinel SIEM', 'software', 'Security', 'critical', 'active', 'confidential', 'Microsoft'],
            ['KHB-AST-SC-002', 'Microsoft Defender for Endpoint', 'software', 'Security', 'high', 'active', 'confidential', 'Microsoft'],
            ['KHB-AST-SC-003', 'Tenable.io Vulnerability Scanner', 'software', 'Security', 'high', 'active', 'confidential', 'Tenable'],
            ['KHB-AST-SC-004', 'Qualys VMDR', 'software', 'Security', 'high', 'active', 'confidential', 'Qualys'],
            ['KHB-AST-SC-005', 'Proofpoint Email Security', 'software', 'Security', 'high', 'active', 'confidential', 'Proofpoint'],
            ['KHB-AST-SC-006', 'Cloudflare WAF', 'cloud_service', 'Security', 'high', 'active', 'confidential', 'Cloudflare'],
            // Third-party switches
            ['KHB-AST-TP-001', 'NIBSS NIP Gateway', 'software', 'Third-Party', 'critical', 'active', 'restricted', 'NIBSS'],
            ['KHB-AST-TP-002', 'Interswitch Card Gateway', 'software', 'Third-Party', 'critical', 'active', 'restricted', 'Interswitch'],
            ['KHB-AST-TP-003', 'CSCS Clearing Gateway', 'software', 'Third-Party', 'high', 'active', 'restricted', 'CSCS'],
            ['KHB-AST-TP-004', 'FMDQ OTC Gateway', 'software', 'Third-Party', 'medium', 'active', 'confidential', 'FMDQ'],
            ['KHB-AST-TP-005', 'Unified Payments BVN API', 'software', 'Third-Party', 'high', 'active', 'restricted', 'Unified Payments'],
        ];
        foreach ($assets as [$code, $name, $type, $category, $criticality, $status, $classification, $vendor]) {
            Asset::create([
                'organization_id' => $this->orgId,
                'asset_id_code' => $code, 'name' => $name,
                'description' => $name.' — Kano Heritage Bank production asset.',
                'asset_type' => $type, 'category' => $category,
                'criticality' => $criticality, 'status' => $status,
                'owner_id' => $uids[array_rand($uids)],
                'department' => ['IT', 'Security', 'Channels', 'Payments'][array_rand(['IT', 'Security', 'Channels', 'Payments'])],
                'location' => ['Kano HQ', 'Lagos DR', 'AWS af-south-1'][array_rand([0, 1, 2])],
                'ip_address' => '10.'.rand(1, 255).'.'.rand(1, 255).'.'.rand(1, 254),
                'hostname' => strtolower(str_replace(' ', '-', substr($name, 0, 30))),
                'vendor' => $vendor,
                'data_classification' => $classification,
                'purchase_date' => now()->subYears(rand(1, 6))->toDateString(),
                'end_of_life' => $code === 'KHB-AST-SV-002' ? now()->subMonths(6)->toDateString() : now()->addYears(rand(2, 7))->toDateString(),
                'tags' => [$category, $type],
            ]);
        }
    }

    private function vendors(): void
    {
        Vendor::where('organization_id', $this->orgId)->delete();
        $vendors = [
            ['Interswitch Limited', 'Payments', 'critical', 'active'],
            ['NIBSS', 'Payments Infrastructure', 'critical', 'active'],
            ['CSCS', 'Capital Markets', 'high', 'active'],
            ['FMDQ Exchange', 'OTC Exchange', 'medium', 'active'],
            ['Unified Payments', 'Card Processing', 'high', 'active'],
            ['e-Tranzact International', 'Payments', 'high', 'active'],
            ['TeamApt (Moniepoint)', 'Agent Banking', 'high', 'active'],
            ['Appzone Group', 'Core Banking', 'high', 'active'],
            ['Infosys — Finacle', 'Core Banking Vendor', 'critical', 'active'],
            ['Oracle Nigeria — Flexcube', 'Core Banking Vendor', 'critical', 'active'],
            ['Microsoft Corporation', 'Cloud & Identity', 'critical', 'active'],
            ['Amazon Web Services', 'Cloud Infrastructure', 'critical', 'active'],
            ['Palo Alto Networks', 'Network Security', 'high', 'active'],
            ['Cisco Systems', 'Networking', 'high', 'active'],
            ['Cloudflare', 'CDN & WAF', 'medium', 'active'],
            ['Tenable', 'Vulnerability Management', 'high', 'active'],
            ['Qualys', 'Vulnerability Management', 'high', 'active'],
            ['Proofpoint', 'Email Security', 'medium', 'active'],
            ['Thales Group', 'HSM & Crypto', 'critical', 'active'],
            ['SWIFT', 'International Payments', 'critical', 'active'],
            ['MainOne Nigeria', 'Data Centre Colo', 'critical', 'active'],
            ['Galaxy Backbone', 'Data Centre Colo', 'high', 'active'],
            ['Rack Centre', 'Data Centre Colo', 'high', 'active'],
            ['MTN Nigeria', 'Telecoms (USSD aggregator)', 'high', 'active'],
            ['Airtel Nigeria', 'Telecoms', 'medium', 'active'],
            ['9mobile', 'Telecoms', 'medium', 'active'],
            ['Globacom', 'Telecoms', 'medium', 'active'],
            ['Infobip', 'SMS / Messaging', 'medium', 'active'],
            ['Africa\'s Talking', 'SMS Gateway', 'low', 'active'],
            ['KPMG Nigeria', 'External Audit', 'high', 'active'],
            ['PwC Nigeria', 'Advisory', 'medium', 'under_review'],
            ['Deloitte Nigeria', 'Advisory', 'medium', 'active'],
            ['Ernst & Young Nigeria', 'Advisory', 'medium', 'active'],
            ['SecurityScorecard', 'Vendor CM', 'medium', 'active'],
            ['Bitsight', 'Vendor CM', 'medium', 'active'],
        ];
        $uids = $this->userIds();
        $i = 0;
        foreach ($vendors as [$name, $cat, $risk, $status]) {
            $i++;
            Vendor::create([
                'organization_id' => $this->orgId,
                'vendor_code' => 'KHB-VEN-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'description' => $name.' is a key vendor for Kano Heritage Bank.',
                'category' => $cat,
                'risk_level' => $risk,
                'status' => $status,
                'contact_name' => 'Relationship Manager',
                'contact_email' => 'contact@'.Str::slug($name, '-').'.com',
                'contact_phone' => '+234 8'.rand(00000000, 99999999),
                'website' => 'https://'.Str::slug($name, '-').'.com',
                'country' => 'NG',
                'services_provided' => $cat,
                'contract_start' => now()->subYears(rand(1, 5)),
                'contract_end' => now()->addYears(rand(1, 3)),
                'contract_value' => rand(10_000_000, 500_000_000),
                'contract_currency' => 'NGN',
                'data_access_level' => ['none', 'limited', 'full'][rand(0, 2)],
                'last_assessed' => now()->subMonths(rand(1, 12)),
                'next_review_date' => now()->addMonths(rand(1, 12)),
            ]);
        }

        // Vendor assessments
        $vendors = Vendor::where('organization_id', $this->orgId)->get();
        foreach ($vendors->take(25) as $v) {
            VendorAssessment::create([
                'organization_id' => $this->orgId,
                'vendor_id' => $v->id,
                'title' => $v->name.' — Annual DDQ',
                'assessment_type' => ['onboarding', 'periodic', 'incident_driven'][rand(0, 2)],
                'status' => $this->weighted(['completed' => 40, 'in_progress' => 35, 'pending' => 25]),
                'assessed_by' => $uids[array_rand($uids)],
                'overall_score' => rand(55, 95),
                'assessment_date' => now()->subMonths(rand(1, 9)),
                'findings' => 'Assessment findings documented per Atheris DDQ template.',
                'recommendations' => 'Close gaps per remediation plan.',
                'next_review_date' => now()->addMonths(rand(6, 12)),
            ]);
        }
    }

    private function threats(): void
    {
        Threat::where('organization_id', $this->orgId)->delete();
        $threats = [
            // Africa-specific
            ['KHB-THR-001', 'SIM Swap attack on mobile banking', 'fraud', 'high', 'active'],
            ['KHB-THR-002', 'USSD session hijack', 'fraud', 'high', 'active'],
            ['KHB-THR-003', 'BVN harvesting via phishing', 'fraud', 'critical', 'active'],
            ['KHB-THR-004', 'Agent-banking POS skimming', 'fraud', 'high', 'active'],
            ['KHB-THR-005', 'BEC (Business Email Compromise)', 'fraud', 'critical', 'active'],
            ['KHB-THR-006', 'NIBSS NIP fraud — mule accounts', 'fraud', 'high', 'active'],
            ['KHB-THR-007', 'ATM jackpotting / malware', 'fraud', 'high', 'active'],
            ['KHB-THR-008', 'Card skimming at Nigerian ATMs', 'fraud', 'high', 'active'],
            ['KHB-THR-009', 'Insider trading on Treasury desk', 'insider', 'high', 'active'],
            // MITRE ATT&CK
            ['KHB-THR-010', 'Spearphishing with attachment (T1566.001)', 'phishing', 'high', 'active'],
            ['KHB-THR-011', 'Valid accounts — brute force (T1110.001)', 'credential', 'high', 'active'],
            ['KHB-THR-012', 'External remote services exploit (T1133)', 'network', 'high', 'active'],
            ['KHB-THR-013', 'Exploitation of public-facing app (T1190)', 'network', 'critical', 'active'],
            ['KHB-THR-014', 'Supply chain compromise (T1195)', 'supply-chain', 'high', 'active'],
            ['KHB-THR-015', 'Cloud services abuse (T1535)', 'cloud', 'medium', 'active'],
            ['KHB-THR-016', 'Privilege escalation — token theft (T1134)', 'credential', 'high', 'active'],
            ['KHB-THR-017', 'Lateral movement — SMB (T1021.002)', 'network', 'high', 'active'],
            ['KHB-THR-018', 'Data exfiltration over web (T1041)', 'data-loss', 'high', 'active'],
            ['KHB-THR-019', 'Ransomware deployment (T1486)', 'malware', 'critical', 'active'],
            ['KHB-THR-020', 'Impairing defences (T1562)', 'evasion', 'high', 'active'],
            ['KHB-THR-021', 'Web shell (T1505.003)', 'persistence', 'high', 'active'],
            ['KHB-THR-022', 'PowerShell abuse (T1059.001)', 'execution', 'high', 'active'],
            ['KHB-THR-023', 'Domain trust discovery (T1482)', 'discovery', 'medium', 'active'],
            ['KHB-THR-024', 'Command and control — DNS (T1071.004)', 'c2', 'high', 'active'],
            ['KHB-THR-025', 'Web session hijacking (T1563.001)', 'credential', 'medium', 'active'],
            // Operational
            ['KHB-THR-026', 'Core banking data-centre power failure', 'operational', 'critical', 'active'],
            ['KHB-THR-027', 'NIBSS NIP switch outage', 'operational', 'critical', 'active'],
            ['KHB-THR-028', 'ISP link failure to DR site', 'operational', 'high', 'active'],
            ['KHB-THR-029', 'DDoS against Internet Banking', 'network', 'high', 'active'],
            ['KHB-THR-030', 'Natural disaster (Kano HQ)', 'physical', 'high', 'active'],
            ['KHB-THR-031', 'Armed robbery on ATM cash-in-transit', 'physical', 'high', 'active'],
            ['KHB-THR-032', 'Civil unrest affecting operations', 'physical', 'medium', 'active'],
            // Insider
            ['KHB-THR-033', 'Rogue employee data theft', 'insider', 'high', 'active'],
            ['KHB-THR-034', 'Disgruntled admin sabotage', 'insider', 'high', 'active'],
            ['KHB-THR-035', 'Accidental data exposure by staff', 'insider', 'medium', 'active'],
            // Compliance
            ['KHB-THR-036', 'NDPA non-compliance fine', 'regulatory', 'high', 'active'],
            ['KHB-THR-037', 'CBN sanctions for late CRMS return', 'regulatory', 'medium', 'active'],
            ['KHB-THR-038', 'Failed KYC/AML detection', 'regulatory', 'high', 'active'],
            ['KHB-THR-039', 'NFIU SAR failure', 'regulatory', 'high', 'active'],
            ['KHB-THR-040', 'PCI-DSS certification lapse', 'regulatory', 'high', 'active'],
            // Emerging
            ['KHB-THR-041', 'AI-powered voice phishing', 'ai-threat', 'medium', 'active'],
            ['KHB-THR-042', 'Deepfake CEO fraud', 'ai-threat', 'medium', 'active'],
            ['KHB-THR-043', 'Quantum-threat to legacy crypto', 'crypto', 'low', 'active'],
            ['KHB-THR-044', 'Cloud misconfiguration exposure', 'cloud', 'high', 'active'],
            ['KHB-THR-045', 'SaaS OAuth token abuse', 'cloud', 'medium', 'active'],
            // Third-party
            ['KHB-THR-046', 'Interswitch compromise', 'third-party', 'critical', 'active'],
            ['KHB-THR-047', 'NIBSS compromise', 'third-party', 'critical', 'active'],
            ['KHB-THR-048', 'Vendor data breach cascade', 'third-party', 'high', 'active'],
            ['KHB-THR-049', 'MSP compromise affecting KHB', 'third-party', 'high', 'active'],
            ['KHB-THR-050', 'Cloud provider outage (AWS af-south-1)', 'cloud', 'high', 'active'],
            // Additional scenario-specific
            ['KHB-THR-051', 'HSM key compromise', 'crypto', 'critical', 'active'],
            ['KHB-THR-052', 'Code signing key theft', 'crypto', 'high', 'active'],
            ['KHB-THR-053', 'Log tampering to hide intrusion', 'integrity', 'high', 'active'],
            ['KHB-THR-054', 'Backup corruption', 'integrity', 'high', 'active'],
            ['KHB-THR-055', 'SMS pump fraud', 'fraud', 'medium', 'active'],
            ['KHB-THR-056', 'Dormant account takeover', 'fraud', 'medium', 'active'],
            ['KHB-THR-057', 'Insider running unauthorised queries', 'insider', 'medium', 'active'],
            ['KHB-THR-058', 'Cash-out via compromised agent banking', 'fraud', 'high', 'active'],
            ['KHB-THR-059', 'Card Not Present (CNP) fraud', 'fraud', 'medium', 'active'],
            ['KHB-THR-060', 'API abuse via exposed keys', 'api', 'high', 'active'],
        ];
        foreach ($threats as [$code, $name, $type, $severity, $status]) {
            Threat::create([
                'organization_id' => $this->orgId,
                'threat_id_code' => $code,
                'name' => $name,
                'description' => $name.'. Relevant to Nigerian DMB context.',
                'category' => $type,
                'type' => ['deliberate', 'accidental', 'environmental'][rand(0, 2)],
                'severity' => $severity,
                'likelihood' => rand(1, 5),
                'capability' => rand(1, 5),
                'intent' => rand(1, 5),
                'is_active' => $status === 'active',
                'source' => ['internal', 'ngcert', 'nitda', 'mitre-attck', 'ibm-x-force'][rand(0, 4)],
                'tags' => [$type, $severity],
                'last_seen' => now()->subDays(rand(1, 90)),
            ]);
        }
    }

    private function risks(): void
    {
        Risk::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        $cats = RiskCategory::where('organization_id', $this->orgId)->pluck('id', 'name')->toArray();

        $risks = [
            ['Unauthorised access via compromised privileged AD credentials', 'Cyber', 5, 5],
            ['NIBSS NIP transaction failures during peak', 'Operational Technology', 4, 5],
            ['NDPA §39 non-compliance — late breach notification', 'Regulatory', 3, 5],
            ['Ransomware on Finacle Core Banking', 'Cyber', 4, 5],
            ['BVN data exfiltration via insider', 'Data Protection', 3, 5],
            ['Agent banking skimming and cash-out', 'Fraud', 4, 4],
            ['BEC campaign targeting Treasury', 'Cyber', 4, 4],
            ['ATM malware / jackpotting', 'Fraud', 3, 4],
            ['Finacle DR cutover failure at point of disaster', 'Technology', 3, 5],
            ['IBM AIX EOL exposure on legacy core', 'Technology', 4, 4],
            ['Kano HQ data-centre environmental failure', 'Physical', 3, 5],
            ['Interswitch supply-chain compromise', 'Third-Party', 3, 5],
            ['Cloudflare outage blocking Internet Banking', 'Third-Party', 3, 4],
            ['USSD session hijack via SS7', 'Fraud', 4, 4],
            ['SIM swap attacks on mobile banking customers', 'Fraud', 5, 4],
            ['Unpatched critical CVE on perimeter firewall', 'Cyber', 3, 5],
            ['Phishing leads to O365 account takeover', 'Cyber', 5, 3],
            ['Weak vendor DDQ allowing data processor with inadequate controls', 'Third-Party', 4, 4],
            ['Policy attestation coverage below 95%', 'Operational Technology', 4, 3],
            ['CBN CRMS IT return late submission', 'Regulatory', 3, 4],
            ['CBN-CSAT self-assessment score decline', 'Regulatory', 3, 4],
            ['Privileged access with shared accounts', 'Cyber', 5, 4],
            ['Unencrypted data at rest on DBA backups', 'Data Protection', 3, 5],
            ['PCI-DSS v4.0.1 compliance gap on cardholder data env', 'Regulatory', 4, 4],
            ['Cross-border data transfer to non-NDPC adequacy country', 'Data Protection', 3, 4],
            ['MFA bypass via OAuth token theft', 'Cyber', 3, 5],
            ['DDoS attack on Internet Banking portal', 'Cyber', 4, 3],
            ['Cloud misconfiguration — public S3 bucket', 'Cyber', 3, 4],
            ['DLP false-negative on outbound email containing PII', 'Data Protection', 4, 3],
            ['Finacle upgrade regression causing service outage', 'Technology', 3, 4],
            ['RPO exceeded on customer database backup', 'Operational Technology', 3, 5],
            ['Insufficient segregation of duties in payments process', 'Fraud', 4, 3],
            ['CBN ITSM incident advisory missed (24h)', 'Regulatory', 3, 4],
            ['Deepfake voice authorisation on high-value transfer', 'Fraud', 2, 5],
            ['ATM cash-in-transit armed robbery', 'Physical', 3, 4],
            ['NFIU SAR threshold misconfiguration', 'Regulatory', 3, 4],
            ['Dormant account takeover fraud', 'Fraud', 4, 3],
            ['KPI/KRI dashboard feeding stale data to Board', 'Operational Technology', 3, 3],
            ['Unmanaged shadow IT in business units', 'Technology', 4, 3],
            ['NDPA DSAR breach — 30-day SLA missed', 'Regulatory', 3, 3],
        ];
        foreach ($risks as $i => [$title, $cat, $likelihood, $impact]) {
            $inherent = $likelihood * $impact;
            $residualL = max(1, $likelihood - rand(0, 2));
            $residualI = max(1, $impact - rand(0, 2));
            $residual = $residualL * $residualI;
            $rating = fn ($s) => $s >= 20 ? 'critical' : ($s >= 12 ? 'high' : ($s >= 6 ? 'medium' : 'low'));
            $status = $this->weighted([
                'identified' => 20, 'assessed' => 20, 'mitigated' => 15, 'accepted' => 10,
                'in_progress' => 20, 'under_review' => 10, 'closed' => 5,
            ]);
            Risk::create([
                'organization_id' => $this->orgId,
                'risk_id_code' => 'KHB-RSK-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $title,
                'description' => $title.'. Kano Heritage Bank context — Nigerian tier-2 DMB.',
                'category_id' => $cats[$cat] ?? null,
                'risk_owner_id' => $uids[array_rand($uids)],
                'created_by' => $uids[array_rand($uids)],
                'status' => $status,
                'inherent_likelihood' => $likelihood,
                'inherent_impact' => $impact,
                'inherent_score' => $inherent,
                'inherent_rating' => $rating($inherent),
                'residual_likelihood' => $residualL,
                'residual_impact' => $residualI,
                'residual_score' => $residual,
                'residual_rating' => $rating($residual),
                'fair_annual_loss_expectancy' => rand(5_000_000, 500_000_000),
                'fair_single_loss_expectancy' => rand(2_000_000, 100_000_000),
                'treatment_strategy' => ['mitigate', 'accept', 'transfer', 'avoid'][rand(0, 3)],
                'treatment_due_date' => now()->addDays(rand(-30, 180)),
                'risk_appetite' => ['within', 'above', 'below'][rand(0, 2)],
                'source' => ['self-assessment', 'audit', 'incident', 'regulator'][rand(0, 3)],
                'review_date' => now()->addMonths(rand(1, 6)),
                'created_at' => $this->ts18m(), 'updated_at' => now()->subDays(rand(0, 30)),
            ]);
        }
    }

    private function controls(): void
    {
        // Link existing Controls table with code KHB-CTL-XXX mapping to AUCS
        Control::where('organization_id', $this->orgId)->delete();
        $aucs = AucsControl::orderBy('id')->take(80)->get();
        $uids = $this->userIds();
        foreach ($aucs as $i => $a) {
            Control::create([
                'organization_id' => $this->orgId,
                'control_code' => 'KHB-CTL-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $a->title,
                'description' => $a->description,
                'domain' => $a->domain,
                'category' => $a->domain,
                'type' => ['preventive', 'detective', 'corrective', 'deterrent'][rand(0, 3)],
                'nature' => ['technical', 'administrative', 'physical'][rand(0, 2)],
                'frequency' => ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annual'][rand(0, 5)],
                'owner_id' => $uids[array_rand($uids)],
                'status' => $this->weighted(['active' => 75, 'draft' => 10, 'under_review' => 10, 'deprecated' => 5]),
                'effectiveness' => $this->weighted([
                    'effective' => 55, 'partially_effective' => 25, 'ineffective' => 10, 'not_assessed' => 10,
                ]),
                'is_key_control' => rand(0, 10) < 3,
                'last_tested' => now()->subDays(rand(1, 365)),
                'next_review_date' => now()->addDays(rand(1, 365)),
                'sort_order' => $i,
                'created_at' => $this->ts18m(),
            ]);
        }
    }

    private function policies(): void
    {
        Policy::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        $titles = [
            'Information Security Policy',
            'Acceptable Use Policy',
            'Access Control Policy',
            'Change Management Policy',
            'Incident Response Policy',
            'Business Continuity Policy',
            'Data Protection & Privacy Policy (NDPA-aligned)',
            'Data Classification Policy',
            'Data Retention Policy',
            'Third-Party Risk Management Policy',
            'Outsourcing Policy (CBN-aligned)',
            'Vulnerability Management Policy',
            'Patch Management Policy',
            'Password & MFA Policy',
            'Cryptography & Key Management Policy',
            'Physical & Environmental Security Policy',
            'Secure Software Development Policy',
            'Cloud Security Policy',
            'Backup & Recovery Policy',
            'Email & Messaging Security Policy',
            'Remote Access & Mobile Device Policy',
            'Logging, Monitoring & Audit Policy',
            'Fraud Risk Management Policy',
            'CBN Cybersecurity Framework Policy',
            'PCI-DSS v4.0.1 Compliance Policy',
        ];
        foreach ($titles as $i => $t) {
            $status = $this->weighted([
                'active' => 50, 'draft' => 15, 'under_review' => 15,
                'approved' => 10, 'expired' => 5, 'archived' => 5,
            ]);
            $policy = Policy::create([
                'organization_id' => $this->orgId,
                'policy_code' => 'KHB-POL-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $t,
                'description' => $t.' for Kano Heritage Bank Plc.',
                'content' => "# {$t}\n\n## Purpose\nDefines the {$t}.\n\n## Scope\nAll employees, contractors, and third parties.\n\n## Policy Statements\n1. Compliance with CBN RBCSF § 3.9.3\n2. Compliance with NDPA 2023\n3. Annual review by CISO + CRO\n\n## Roles and Responsibilities\n- CISO: Policy owner\n- CRO: Accountable officer\n- All staff: compliance",
                'category' => ['security', 'privacy', 'risk', 'governance'][rand(0, 3)],
                'status' => $status,
                'version_number' => '5.0',
                'owner_id' => $uids[array_rand($uids)],
                'approved_by' => $uids[array_rand($uids)],
                'approved_at' => now()->subMonths(rand(1, 6)),
                'published_at' => now()->subMonths(rand(1, 6)),
                'effective_date' => now()->subMonths(rand(1, 6)),
                'review_date' => now()->addMonths(rand(6, 12)),
                'expiry_date' => now()->addYear(),
                'is_mandatory' => true,
                'created_at' => $this->ts18m(),
            ]);

            // 5 versions per policy
            for ($v = 1; $v <= 5; $v++) {
                PolicyVersion::create([
                    'policy_id' => $policy->id,
                    'version_number' => sprintf('%d.0', $v),
                    'content' => "Version {$v} content of {$t}.",
                    'change_summary' => $v === 1 ? 'Initial version' : 'Updates per annual review',
                    'created_by' => $uids[array_rand($uids)],
                    'created_at' => now()->subYears(5 - $v + 1)->subMonths(rand(0, 11)),
                    'updated_at' => now()->subYears(5 - $v + 1)->subMonths(rand(0, 11)),
                ]);
            }

            // Attestation campaign — assign to every user
            foreach ($uids as $userId) {
                PolicyAttestation::updateOrCreate(
                    ['organization_id' => $this->orgId, 'policy_id' => $policy->id, 'user_id' => $userId],
                    [
                        'status' => $this->weighted(['completed' => 60, 'pending' => 25, 'overdue' => 10, 'waived' => 5]),
                        'acknowledged_at' => rand(0, 1) ? now()->subDays(rand(0, 90)) : null,
                        'due_date' => now()->addDays(rand(1, 60)),
                    ]
                );
            }
        }
    }

    private function vulnerabilities(): void
    {
        Vulnerability::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        $cves = [
            ['CVE-2024-21413', 'Microsoft Outlook RCE (MonikerLink)', 'critical', 9.8],
            ['CVE-2024-3400', 'PAN-OS GlobalProtect Command Injection', 'critical', 10.0],
            ['CVE-2024-27198', 'JetBrains TeamCity Auth Bypass', 'critical', 9.8],
            ['CVE-2024-20353', 'Cisco ASA/FTD DoS', 'high', 8.6],
            ['CVE-2024-20359', 'Cisco ASA/FTD persistent local code execution', 'high', 6.0],
            ['CVE-2023-46747', 'F5 BIG-IP Config Utility RCE', 'critical', 9.8],
            ['CVE-2023-34362', 'MOVEit Transfer SQLi', 'critical', 9.8],
            ['CVE-2024-30088', 'Windows Kernel EoP', 'high', 7.8],
            ['CVE-2024-26169', 'Windows Error Reporting Service EoP', 'high', 7.8],
            ['CVE-2023-36884', 'Office Search Path Vulnerability', 'high', 8.8],
            ['CVE-2024-4577', 'PHP CGI Argument Injection', 'critical', 9.8],
            ['CVE-2023-4966', 'Citrix NetScaler Memory Disclosure (Citrix Bleed)', 'critical', 9.4],
            ['CVE-2024-1086', 'Linux Kernel nf_tables UAF LPE', 'high', 7.8],
            ['CVE-2024-23897', 'Jenkins Arbitrary File Read', 'critical', 9.8],
            ['CVE-2024-21762', 'Fortinet FortiOS SSL VPN OOB Write', 'critical', 9.6],
        ];
        $assetIds = Asset::where('organization_id', $this->orgId)->pluck('id')->toArray();

        for ($i = 0; $i < 80; $i++) {
            $cve = $cves[array_rand($cves)];
            Vulnerability::create([
                'organization_id' => $this->orgId,
                'vuln_id_code' => 'KHB-VLN-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'cve_id' => $cve[0].'-v'.rand(1, 99),
                'title' => $cve[1],
                'description' => $cve[1].'. Affects Kano Heritage asset(s).',
                'severity' => $cve[2],
                'cvss_score' => $cve[3],
                'cvss_vector' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H',
                'source' => ['tenable', 'qualys', 'defender', 'ngcert', 'manual'][rand(0, 4)],
                'status' => $this->weighted(['open' => 45, 'in_progress' => 25, 'remediated' => 20, 'accepted' => 5, 'false_positive' => 5]),
                'affected_assets' => [$assetIds[array_rand($assetIds)]],
                'discovered_at' => $this->ts18m(),
                'assigned_to' => $uids[array_rand($uids)],
                'due_date' => now()->addDays(rand(-60, 90)),
                'tags' => [rand(0, 1) ? 'KEV' : null, rand(0, 1) ? 'EPSS>0.8' : null],
            ]);
        }
    }

    private function patches(): void
    {
        Patch::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        for ($i = 0; $i < 30; $i++) {
            Patch::create([
                'organization_id' => $this->orgId,
                'patch_id_code' => 'KHB-PTC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => 'KB50'.rand(10000, 99999).' — Monthly rollup',
                'description' => 'Microsoft / Linux / vendor monthly patch package.',
                'vendor' => ['Microsoft', 'Red Hat', 'Oracle', 'Cisco', 'Palo Alto'][rand(0, 4)],
                'version' => '202'.rand(5, 6).'-'.str_pad((string) rand(1, 12), 2, '0', STR_PAD_LEFT),
                'release_date' => now()->subDays(rand(1, 180)),
                'severity' => ['critical', 'high', 'medium', 'low'][rand(0, 3)],
                'status' => $this->weighted(['pending' => 25, 'testing' => 20, 'approved' => 20, 'deployed' => 30, 'failed' => 5]),
                'affected_systems' => ['Core DB', 'AD DCs', 'Web servers', 'App servers'][rand(0, 3)],
                'deployed_at' => now()->subDays(rand(0, 90)),
                'deployed_by' => $uids[array_rand($uids)],
            ]);
        }
    }

    private function incidents(): void
    {
        Incident::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        $titles = [
            ['BEC attempt spoofing CFO to Treasury', 'phishing', 'high'],
            ['Unauthorised database query on customer PII', 'unauthorized_access', 'high'],
            ['Ransomware attempt blocked by Defender on laptop', 'malware', 'medium'],
            ['NIBSS NIP reconciliation anomaly', 'other', 'high'],
            ['Phishing email campaign targeting staff', 'phishing', 'medium'],
            ['Suspicious admin login from Ghana IP', 'unauthorized_access', 'high'],
            ['DDoS traffic burst on Internet Banking (mitigated)', 'dos', 'medium'],
            ['USB exfiltration attempt — Finance', 'data_leak', 'high'],
            ['ATM cash-out via compromised agent credentials', 'other', 'critical'],
            ['SIM-swap fraud — 5 customers affected', 'other', 'high'],
            ['Finacle DB patch failure — 2h outage', 'other', 'high'],
            ['Web shell found on public-facing app', 'malware', 'critical'],
            ['Phishing site mimicking KHB detected', 'phishing', 'medium'],
            ['Suspicious wire transfer to sanctions country (blocked)', 'other', 'high'],
            ['Office365 account takeover — 1 user', 'unauthorized_access', 'high'],
        ];
        foreach ($titles as $i => [$title, $type, $severity]) {
            Incident::create([
                'organization_id' => $this->orgId,
                'incident_id_code' => 'KHB-INC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $title,
                'description' => $title.'. Triaged per CBN ITSM procedure.',
                'type' => $type,
                'severity' => $severity,
                'status' => $this->weighted([
                    'detected' => 10, 'triaged' => 15, 'investigating' => 20,
                    'containing' => 15, 'eradicating' => 10, 'recovering' => 10, 'closed' => 20,
                ]),
                'source' => ['SIEM', 'SOC analyst', 'customer report', 'automated alert', 'ngCERT'][rand(0, 4)],
                'detected_at' => now()->subDays(rand(1, 90)),
                'responded_at' => now()->subDays(rand(0, 89)),
                'contained_at' => rand(0, 1) ? now()->subDays(rand(0, 85)) : null,
                'resolved_at' => rand(0, 1) ? now()->subDays(rand(0, 60)) : null,
                'closed_at' => rand(0, 3) === 0 ? now()->subDays(rand(0, 45)) : null,
                'assigned_to' => $uids[array_rand($uids)],
                'lead_investigator_id' => $uids[array_rand($uids)],
                'affected_users_count' => rand(0, 500),
                'is_data_breach' => $i < 3,
                'root_cause' => 'Root cause identified during incident handling.',
                'lessons_learned' => $i < 8 ? 'Lessons captured; control gap logged as Issue.' : null,
            ]);
        }
    }

    private function dataBreaches(): void
    {
        DataBreach::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        $incidents = Incident::where('organization_id', $this->orgId)->where('is_data_breach', true)->pluck('id')->toArray();
        for ($i = 0; $i < 8; $i++) {
            DataBreach::create([
                'organization_id' => $this->orgId,
                'incident_id' => ! empty($incidents) ? $incidents[array_rand($incidents)] : null,
                'breach_id_code' => 'KHB-BRC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => "Data breach event #{$i}",
                'description' => 'NDPA-reportable personal data breach event.',
                'breach_type' => ['confidentiality', 'integrity', 'availability'][rand(0, 2)],
                'data_types_affected' => ['BVN', 'PII', 'Account numbers', 'Contact details'],
                'records_affected' => rand(10, 10_000),
                'status' => $this->weighted(['identified' => 15, 'investigating' => 20, 'contained' => 15, 'notified' => 25, 'resolved' => 20, 'closed' => 5]),
                'ndpa_notification_required' => true,
                'ndpa_notification_deadline' => now()->subDays(rand(-3, 5)),
                'ndpa_notified_at' => rand(0, 1) ? now()->subDays(rand(0, 7)) : null,
                'regulatory_body_notified' => rand(0, 1),
                'individuals_notified' => rand(0, 1),
                'assigned_to' => $uids[array_rand($uids)],
            ]);
        }
    }

    private function securityAlerts(): void
    {
        SecurityAlert::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        for ($i = 0; $i < 30; $i++) {
            SecurityAlert::create([
                'organization_id' => $this->orgId,
                'alert_id_code' => 'KHB-ALR-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => 'Sentinel alert #'.rand(100, 999).' — Suspicious activity',
                'description' => 'SIEM-sourced alert with enriched context.',
                'source' => ['Sentinel', 'Defender', 'Splunk', 'Wazuh', 'ngCERT'][rand(0, 4)],
                'severity' => ['critical', 'high', 'medium', 'low'][rand(0, 3)],
                'status' => $this->weighted(['new' => 30, 'acknowledged' => 25, 'investigating' => 20, 'resolved' => 20, 'dismissed' => 5]),
                'received_at' => now()->subHours(rand(0, 720)),
                'acknowledged_at' => rand(0, 1) ? now()->subHours(rand(0, 168)) : null,
                'acknowledged_by' => $uids[array_rand($uids)],
                'raw_data' => ['src_ip' => '41.'.rand(0, 255).'.'.rand(0, 255).'.'.rand(0, 255), 'rule' => 'R-'.rand(100, 999)],
            ]);
        }
    }

    private function bcp(): void
    {
        BcpPlan::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        $plans = [
            ['Enterprise BCP', 'bcp'],
            ['Core Banking DR Plan', 'dr'],
            ['Channels BCP (USSD/Mobile/IB)', 'bcp'],
            ['Payments BCP (NIBSS/Interswitch)', 'bcp'],
            ['Treasury DR Plan', 'dr'],
            ['Kano HQ Facility BCP', 'bcp'],
            ['Lagos DR Site Readiness', 'dr'],
            ['Crisis Management Plan', 'crisis'],
            ['Pandemic Response Plan', 'crisis'],
            ['Cyber-Incident Recovery Plan', 'dr'],
        ];
        foreach ($plans as $i => [$name, $type]) {
            BcpPlan::create([
                'organization_id' => $this->orgId,
                'plan_code' => 'KHB-BCP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $name,
                'description' => $name.' for Kano Heritage Bank.',
                'plan_type' => $type,
                'status' => $this->weighted(['active' => 60, 'under_review' => 15, 'tested' => 15, 'draft' => 10]),
                'owner_id' => $uids[array_rand($uids)],
                'scope' => 'All operations and IT systems supporting retail and treasury banking.',
                'objectives' => 'Ensure continuity of critical business services within RTO/RPO.',
                'rto_hours' => [2, 4, 8, 12, 24][rand(0, 4)],
                'rpo_hours' => [0, 1, 4, 12][rand(0, 3)],
                'last_tested' => now()->subMonths(rand(1, 12)),
                'next_test_date' => now()->addMonths(rand(1, 6)),
                'next_review_date' => now()->addMonths(rand(1, 12)),
                'approved_by' => $uids[array_rand($uids)],
                'approved_at' => now()->subMonths(rand(1, 12)),
            ]);
        }

        // BIA records
        BiaRecord::where('organization_id', $this->orgId)->delete();
        $bia = [
            ['Retail Banking — Internet Banking', 'Digital', 'critical', 2, 0],
            ['Retail Banking — Mobile Banking', 'Digital', 'critical', 2, 0],
            ['Retail Banking — USSD', 'Channels', 'critical', 1, 0],
            ['Payments — NIBSS NIP', 'Payments', 'critical', 1, 0],
            ['Payments — Interswitch Switch', 'Payments', 'critical', 1, 0],
            ['Treasury Trading', 'Treasury', 'high', 4, 1],
            ['Card Operations', 'Payments', 'high', 4, 1],
            ['Loan Origination', 'Retail', 'medium', 24, 4],
        ];
        foreach ($bia as $i => [$process, $dept, $crit, $rto, $rpo]) {
            BiaRecord::create([
                'organization_id' => $this->orgId,
                'process_name' => $process,
                'department' => $dept,
                'description' => $process.' BIA.',
                'criticality' => $crit,
                'rto_hours' => $rto,
                'rpo_hours' => $rpo,
                'mtpd_hours' => $rto * 2,
                'financial_impact_per_hour' => rand(2_000_000, 50_000_000),
                'dependencies' => 'Core banking, NIBSS, Interswitch',
                'recovery_strategy' => 'Failover to DR site; activate manual workaround; engage vendor support.',
                'owner_id' => $uids[array_rand($uids)],
            ]);
        }
    }

    private function complianceAssessments(): void
    {
        ComplianceAssessment::where('organization_id', $this->orgId)->delete();
        $uids = $this->userIds();
        $frameworks = ControlFramework::take(5)->pluck('id')->toArray();
        if (empty($frameworks)) {
            // Make a minimal framework row
            $fw = ControlFramework::create([
                'name' => 'CBN Risk-Based Cyber Security Framework',
                'slug' => 'cbn-rbcsf',
                'short_name' => 'CBN RBCSF',
                'description' => 'Risk-Based Cyber Security Framework for DMBs',
                'version' => '2021',
                'issuing_body' => 'Central Bank of Nigeria',
                'category' => 'regulatory',
                'jurisdiction' => 'NG',
                'is_system' => true,
                'is_active' => true,
                'effective_date' => '2021-07-01',
            ]);
            $frameworks = [$fw->id];
        }
        for ($i = 0; $i < 20; $i++) {
            $total = rand(40, 250);
            $comp = (int) ($total * 0.55);
            $partial = (int) ($total * 0.20);
            $non = (int) ($total * 0.15);
            $na = $total - $comp - $partial - $non;
            ComplianceAssessment::create([
                'organization_id' => $this->orgId,
                'framework_id' => $frameworks[array_rand($frameworks)],
                'title' => 'Compliance Assessment #'.($i + 1),
                'description' => 'Periodic compliance assessment against framework.',
                'status' => $this->weighted(['planned' => 15, 'in_progress' => 40, 'completed' => 35, 'cancelled' => 10]),
                'lead_assessor_id' => $uids[array_rand($uids)],
                'start_date' => now()->subMonths(rand(1, 12)),
                'end_date' => now()->subMonths(rand(0, 1)),
                'due_date' => now()->addMonths(rand(1, 6)),
                'overall_score' => rand(50, 95),
                'total_requirements' => $total,
                'compliant_count' => $comp,
                'partial_count' => $partial,
                'non_compliant_count' => $non,
                'not_applicable_count' => $na,
                'summary' => 'Assessment completed with mix of compliant and partial findings.',
            ]);
        }
    }

    private function demoEvidence(): void
    {
        // Add Nigerian Obligations beyond the Atheris seed
        if (DB::table('obligations')->where('organization_id', $this->orgId)->count() < 50) {
            $additions = [
                ['NAICOM', 'ERM-2024-Q1', 'NAICOM quarterly ERM submission'],
                ['NDIC', 'RISK-RETURN-2024', 'NDIC annual risk-return'],
                ['SEC', 'CYBER-Q1', 'SEC quarterly cyber posture'],
                ['NCC', 'LICENCE-RENEW', 'NCC licence renewal'],
                ['PENCOM', 'SA-QUARTERLY', 'PENCOM quarterly self-assessment'],
                ['CBN', 'CRMS-IT-RETURN', 'CBN CRMS IT return quarterly'],
                ['CBN', 'BIA-ANNUAL', 'CBN annual BIA submission'],
                ['CBN', 'ICT-AUDIT-ANNUAL', 'CBN annual ICT audit report'],
                ['CBN', 'CYBER-SA-ANNUAL', 'CBN cybersecurity self-assessment'],
                ['NDPC', 'DPIA-ANNUAL', 'NDPA annual DPIA register'],
                ['NDPC', 'NDPA-AUDIT', 'NDPA annual audit submission'],
                ['CBN', 'PCI-DSS-CERT', 'Annual PCI-DSS ROC submission'],
                ['CBN', 'BCP-TEST', 'Annual BCP test result to CBN'],
                ['CBN', 'INCIDENT-QUARTERLY', 'Quarterly incident dashboard to CBN'],
                ['NAICOM', 'INSURANCE-COVERAGE', 'Annual insurance coverage filing'],
            ];
            foreach ($additions as $i => [$reg, $code, $title]) {
                DB::table('obligations')->updateOrInsert(
                    ['regulator_code' => $reg, 'reference_code' => $code.'-'.$i],
                    [
                        'organization_id' => $this->orgId, 'title' => $title,
                        'body_markdown' => $title.' (demo obligation).',
                        'effective_date' => now()->subMonths(rand(1, 12)),
                        'review_cycle_days' => [30, 90, 180, 365][rand(0, 3)],
                        'owner_role' => ['CISO', 'CRO', 'DPCO', 'Head of Compliance'][rand(0, 3)],
                        'status' => 'active',
                        'applicability' => json_encode(['dmb' => true]),
                        'evidence_requirement' => 'Signed evidence pack + source artefacts.',
                        'created_at' => now(), 'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
