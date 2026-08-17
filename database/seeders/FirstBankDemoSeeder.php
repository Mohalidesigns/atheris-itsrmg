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
use App\Models\Issue;
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
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatInstitutionProfile;
use App\Modules\CBNCSAT\Models\CsatIrQuestion;
use App\Modules\CBNCSAT\Models\CsatIrResponse;
use App\Modules\CBNCSAT\Models\CsatMaResponse;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First Bank of Nigeria Plc — Tier-1 commercial bank demo tenant.
 * Full cross-linked dataset ensuring every module has data and
 * interactions across modules work end-to-end.
 *
 * Volumes:
 *  - 25 users across every major role
 *  - 70 assets (core banking, channels, infra, data, crypto, servers)
 *  - 55 risks across all 4 severity bands (critical/high/medium/low)
 *  - 70 threats (African + MITRE)
 *  - 120 vulnerabilities with real CVE IDs
 *  - 50 patches
 *  - 30 incidents + 15 data breaches + 50 security alerts
 *  - 100 open Issues (CAPA) distributed across severities
 *  - 40 vendors + 40 vendor assessments
 *  - 30 policies × 5 versions + full attestation campaign
 *  - 25 compliance assessments
 *  - 12 BCP plans + 10 BIA records
 *  - 93 Annex A SoA entries + 20 gap rows
 *  - 30 CBN-CSAT assessment responses @ 75% complete
 *  - Every module exercised with live cross-module FK linkage
 */
class FirstBankDemoSeeder extends Seeder
{
    protected int $orgId;

    public function run(): void
    {
        $this->command?->info('Seeding First Bank of Nigeria Plc (Tier-1) tenant…');
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
        $this->compliance();
        $this->issues();
        $this->soaAndGaps();
        $this->csat();
    }

    private function weighted(array $dist): string
    {
        $r = mt_rand(1, array_sum($dist));
        $c = 0;
        foreach ($dist as $k => $w) {
            $c += $w;
            if ($r <= $c) {
                return $k;
            }
        }

        return array_key_first($dist);
    }

    private function ts18m(): Carbon
    {
        return now()->subDays(rand(0, 540));
    }

    private function uids(): array
    {
        return User::where('organization_id', $this->orgId)->pluck('id')->toArray();
    }

    /* ============================ Organization ============================ */
    private function org(): void
    {
        $org = Organization::updateOrCreate(
            ['slug' => 'first-bank-nigeria'],
            [
                'name' => 'First Bank of Nigeria Plc',
                'industry' => 'Financial Services',
                'size' => 'enterprise',
                'country' => 'NG',
                'currency' => 'NGN',
                'subscription_plan' => 'enterprise',
                'subscription_expires_at' => now()->addYears(3),
                'is_active' => true,
            ]
        );
        $this->orgId = $org->id;
    }

    /* ================================ Users ================================ */
    private function users(): void
    {
        $rows = [
            ['Dr. Oluwatoyin Akintayo', 'Group CISO', 'ciso@firstbanknigeria.ng', 'Information Security'],
            ['Engr. Babatunde Ilori', 'Chief Risk Officer', 'cro@firstbanknigeria.ng', 'Enterprise Risk'],
            ['Amaka Obiora-Chukwu', 'Head of Internal Audit', 'internal.audit@firstbanknigeria.ng', 'Internal Audit'],
            ['Suleiman Garba', 'Head of Compliance', 'compliance@firstbanknigeria.ng', 'Compliance'],
            ['Olanrewaju Adewale', 'Head of IT Operations', 'itops@firstbanknigeria.ng', 'IT'],
            ['Chidinma Eneh', 'DPCO', 'dpco@firstbanknigeria.ng', 'Compliance'],
            ['Biodun Oyeyemi', 'Vendor Risk Manager', 'tprm@firstbanknigeria.ng', 'TPRM'],
            ['Hajiya Aisha Bello', 'Policy Author', 'policy@firstbanknigeria.ng', 'Governance'],
            ['Kelechi Okoro', 'Incident Response Lead', 'incident@firstbanknigeria.ng', 'SOC'],
            ['Dr. Funso Adetokunbo', 'Board Risk Committee Chair', 'board.risk@firstbanknigeria.ng', 'Board'],
            ['Abdulrahman Lawal', 'Platform Admin', 'sysadmin@firstbanknigeria.ng', 'IT'],
            ['Nkechi Ogbuagu', 'Network Engineering Lead', 'network@firstbanknigeria.ng', 'IT Operations'],
            ['Olufemi Kolawole', 'Finacle Application Owner', 'finacle.app@firstbanknigeria.ng', 'IT'],
            ['Ifeoma Anyaoku', 'Flexcube Application Owner', 'flexcube.app@firstbanknigeria.ng', 'IT'],
            ['Yusuf Mohammed', 'Senior Security Operations Analyst', 'soc@firstbanknigeria.ng', 'SOC'],
            ['Ngozi Uzoamaka', 'Treasury IT Lead', 'treasury.it@firstbanknigeria.ng', 'Treasury'],
            ['Kingsley Nnamdi', 'Head of Digital Channels', 'digital@firstbanknigeria.ng', 'Digital'],
            ['Tochi Okonkwo', 'Risk Analyst', 'risk.analyst@firstbanknigeria.ng', 'Enterprise Risk'],
            ['Emmanuella Etim', 'Control Tester', 'control.tester@firstbanknigeria.ng', 'Internal Audit'],
            ['Ibrahim Musa', 'Third-Party Risk Analyst', 'tprm.analyst@firstbanknigeria.ng', 'TPRM'],
            ['Stella Adeoye', 'ISMS Manager', 'isms@firstbanknigeria.ng', 'Information Security'],
            ['Adekunle Fasola', 'PCI-DSS QSA liaison', 'pci@firstbanknigeria.ng', 'Information Security'],
            ['Tolulope Ojo', 'External Auditor (KPMG)', 'external.auditor@firstbanknigeria.ng', 'External'],
            ['Blessing Uche', 'BCP Coordinator', 'bcp@firstbanknigeria.ng', 'Operations'],
            ['Adamu Sani', 'HR Business Partner — IT', 'hr.it@firstbanknigeria.ng', 'HR'],
            // Admin last, password login
            ['Group CISO Admin', 'Group CISO', 'admin@firstbanknigeria.ng', 'Information Security'],
        ];
        foreach ($rows as [$name, $title, $email, $dept]) {
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

    private function categories(): void
    {
        foreach (['Cyber', 'Technology', 'Operational Technology', 'Third-Party', 'Data Protection', 'Fraud', 'Physical', 'Regulatory'] as $n) {
            RiskCategory::updateOrCreate(
                ['organization_id' => $this->orgId, 'name' => $n],
                ['description' => $n.' risk category', 'slug' => Str::slug($n)]
            );
        }
    }

    /* ================================ Assets ================================ */
    private function assets(): void
    {
        Asset::where('organization_id', $this->orgId)->delete();
        $uids = $this->uids();
        // 70 named Tier-1 assets
        $list = [
            // Core banking x 6
            ['FBN-AST-CB-001', 'Finacle Core Banking (Prod)', 'software', 'Core Banking', 'critical', 'active', 'restricted', 'Infosys Finacle'],
            ['FBN-AST-CB-002', 'Finacle Core Banking (DR)', 'software', 'Core Banking', 'critical', 'active', 'restricted', 'Infosys Finacle'],
            ['FBN-AST-CB-003', 'Flexcube Universal Banking (Treasury)', 'software', 'Core Banking', 'high', 'active', 'restricted', 'Oracle Flexcube'],
            ['FBN-AST-CB-004', 'FBN Payments Hub', 'software', 'Payments', 'critical', 'active', 'restricted', 'Infosys'],
            ['FBN-AST-CB-005', 'Islamic Banking Core (FBN Quest)', 'software', 'Core Banking', 'high', 'active', 'restricted', 'Path Solutions'],
            ['FBN-AST-CB-006', 'Microfinance Core (Rural)', 'software', 'Core Banking', 'medium', 'active', 'restricted', 'Appzone'],
            // Channels x 10
            ['FBN-AST-CH-001', 'FirstMobile (iOS / Android)', 'software', 'Channel', 'critical', 'active', 'restricted', 'In-house'],
            ['FBN-AST-CH-002', 'FirstOnline (Internet Banking)', 'software', 'Channel', 'critical', 'active', 'restricted', 'In-house'],
            ['FBN-AST-CH-003', 'USSD *894#', 'software', 'Channel', 'critical', 'active', 'confidential', 'In-house'],
            ['FBN-AST-CH-004', 'FirstMonie Agent Banking', 'software', 'Channel', 'high', 'active', 'restricted', 'In-house'],
            ['FBN-AST-CH-005', 'ATM Network (3,500 terminals)', 'hardware', 'Channel', 'critical', 'active', 'restricted', 'NCR / Diebold'],
            ['FBN-AST-CH-006', 'PoS Terminal Network (22,000)', 'hardware', 'Channel', 'high', 'active', 'restricted', 'Interswitch / Verve'],
            ['FBN-AST-CH-007', 'Contact Centre IVR', 'software', 'Channel', 'medium', 'active', 'internal', 'Genesys'],
            ['FBN-AST-CH-008', 'WhatsApp Banking Bot', 'software', 'Channel', 'medium', 'active', 'confidential', 'Twilio'],
            ['FBN-AST-CH-009', 'FirstCollect (Cash-in Solution)', 'software', 'Channel', 'high', 'active', 'restricted', 'In-house'],
            ['FBN-AST-CH-010', 'FirstPay (Merchant Gateway)', 'software', 'Channel', 'high', 'active', 'restricted', 'In-house'],
            // Infra x 14
            ['FBN-AST-IN-001', 'Active Directory — Lagos HQ DC', 'hardware', 'Infrastructure', 'critical', 'active', 'restricted', 'Microsoft'],
            ['FBN-AST-IN-002', 'Active Directory — Abuja DC', 'hardware', 'Infrastructure', 'critical', 'active', 'restricted', 'Microsoft'],
            ['FBN-AST-IN-003', 'Entra ID (Cloud Identity)', 'cloud_service', 'Infrastructure', 'critical', 'active', 'restricted', 'Microsoft'],
            ['FBN-AST-IN-004', 'Palo Alto Perimeter Firewall (HQ)', 'hardware', 'Network', 'critical', 'active', 'restricted', 'Palo Alto PA-7050'],
            ['FBN-AST-IN-005', 'Palo Alto Perimeter Firewall (DR)', 'hardware', 'Network', 'critical', 'active', 'restricted', 'Palo Alto PA-7050'],
            ['FBN-AST-IN-006', 'Cisco Nexus 9K Core Switches', 'hardware', 'Network', 'critical', 'active', 'restricted', 'Cisco'],
            ['FBN-AST-IN-007', 'F5 BIG-IP Cluster', 'hardware', 'Network', 'high', 'active', 'restricted', 'F5'],
            ['FBN-AST-IN-008', 'Cisco ASA VPN Concentrator', 'hardware', 'Network', 'high', 'active', 'restricted', 'Cisco ASA'],
            ['FBN-AST-IN-009', 'Lagos HQ Marina DC', 'facility', 'Facility', 'critical', 'active', 'restricted', 'On-prem'],
            ['FBN-AST-IN-010', 'Abuja DR DC (MainOne)', 'facility', 'Facility', 'critical', 'active', 'restricted', 'MainOne'],
            ['FBN-AST-IN-011', 'Ibadan Backup DC (Galaxy)', 'facility', 'Facility', 'high', 'active', 'restricted', 'Galaxy Backbone'],
            ['FBN-AST-IN-012', 'SWIFT Alliance Access Gateway', 'hardware', 'Network', 'critical', 'active', 'restricted', 'SWIFT'],
            ['FBN-AST-IN-013', 'Thales Luna HSM Cluster (Primary)', 'hardware', 'Crypto', 'critical', 'active', 'restricted', 'Thales'],
            ['FBN-AST-IN-014', 'Thales Luna HSM Cluster (DR)', 'hardware', 'Crypto', 'critical', 'active', 'restricted', 'Thales'],
            // Data stores x 12
            ['FBN-AST-DB-001', 'Finacle Core DB (Oracle Exadata)', 'database', 'Data', 'critical', 'active', 'restricted', 'Oracle'],
            ['FBN-AST-DB-002', 'Customer PII Vault', 'database', 'Data', 'critical', 'active', 'restricted', 'Oracle'],
            ['FBN-AST-DB-003', 'BVN-Linked Account Store', 'database', 'Data', 'critical', 'active', 'restricted', 'Oracle'],
            ['FBN-AST-DB-004', 'Treasury Trade Store (Postgres)', 'database', 'Data', 'high', 'active', 'restricted', 'PostgreSQL'],
            ['FBN-AST-DB-005', 'AML Screening Store', 'database', 'Data', 'high', 'active', 'confidential', 'MongoDB'],
            ['FBN-AST-DB-006', 'Fraud Detection DB (Mysql)', 'database', 'Data', 'high', 'active', 'confidential', 'MySQL'],
            ['FBN-AST-DB-007', 'Audit Log Archive (S3 Object Lock)', 'cloud_service', 'Data', 'high', 'active', 'confidential', 'AWS'],
            ['FBN-AST-DB-008', 'CRMS Customer Credit Bureau DB', 'database', 'Data', 'critical', 'active', 'restricted', 'Oracle'],
            ['FBN-AST-DB-009', 'Data Warehouse (Teradata)', 'database', 'Data', 'high', 'active', 'confidential', 'Teradata'],
            ['FBN-AST-DB-010', 'Analytics Lakehouse (Databricks)', 'cloud_service', 'Data', 'high', 'active', 'confidential', 'Databricks'],
            ['FBN-AST-DB-011', 'Contact Centre CRM (Dynamics 365)', 'cloud_service', 'Data', 'medium', 'active', 'confidential', 'Microsoft'],
            ['FBN-AST-DB-012', 'Document Management (SharePoint)', 'cloud_service', 'Data', 'medium', 'active', 'internal', 'Microsoft'],
            // Servers x 8
            ['FBN-AST-SV-001', 'IBM Power 10 (Finacle Primary)', 'hardware', 'Server', 'critical', 'active', 'restricted', 'IBM'],
            ['FBN-AST-SV-002', 'IBM AIX p780 (Legacy CRMS)', 'hardware', 'Server', 'high', 'under_review', 'restricted', 'IBM'],
            ['FBN-AST-SV-003', 'Windows Server 2022 Cluster (App)', 'hardware', 'Server', 'high', 'active', 'confidential', 'Microsoft'],
            ['FBN-AST-SV-004', 'RHEL 9 Cluster (Web)', 'hardware', 'Server', 'high', 'active', 'confidential', 'Red Hat'],
            ['FBN-AST-SV-005', 'VMware vSphere Cluster (HQ)', 'hardware', 'Server', 'high', 'active', 'restricted', 'VMware'],
            ['FBN-AST-SV-006', 'Kubernetes (Rancher) — PaaS', 'software', 'Server', 'medium', 'active', 'internal', 'Rancher'],
            ['FBN-AST-SV-007', 'RabbitMQ Cluster', 'software', 'Server', 'medium', 'active', 'internal', 'VMware'],
            ['FBN-AST-SV-008', 'GitLab Self-Managed', 'software', 'Server', 'medium', 'active', 'internal', 'GitLab'],
            // Cloud x 5
            ['FBN-AST-CL-001', 'AWS Production Account', 'cloud_service', 'Cloud', 'critical', 'active', 'restricted', 'AWS'],
            ['FBN-AST-CL-002', 'AWS DR Account', 'cloud_service', 'Cloud', 'critical', 'active', 'restricted', 'AWS'],
            ['FBN-AST-CL-003', 'Microsoft 365 Tenant', 'cloud_service', 'Cloud', 'high', 'active', 'confidential', 'Microsoft'],
            ['FBN-AST-CL-004', 'Azure Production Subscription', 'cloud_service', 'Cloud', 'high', 'active', 'restricted', 'Microsoft'],
            ['FBN-AST-CL-005', 'GCP BigQuery (analytics)', 'cloud_service', 'Cloud', 'medium', 'active', 'confidential', 'Google'],
            // Security tooling x 8
            ['FBN-AST-SC-001', 'Microsoft Sentinel SIEM', 'software', 'Security', 'critical', 'active', 'confidential', 'Microsoft'],
            ['FBN-AST-SC-002', 'Microsoft Defender for Endpoint', 'software', 'Security', 'high', 'active', 'confidential', 'Microsoft'],
            ['FBN-AST-SC-003', 'Tenable.io', 'software', 'Security', 'high', 'active', 'confidential', 'Tenable'],
            ['FBN-AST-SC-004', 'Qualys VMDR', 'software', 'Security', 'high', 'active', 'confidential', 'Qualys'],
            ['FBN-AST-SC-005', 'Proofpoint Email Gateway', 'software', 'Security', 'high', 'active', 'confidential', 'Proofpoint'],
            ['FBN-AST-SC-006', 'Cloudflare WAF', 'cloud_service', 'Security', 'high', 'active', 'confidential', 'Cloudflare'],
            ['FBN-AST-SC-007', 'CyberArk Privileged Access', 'software', 'Security', 'critical', 'active', 'restricted', 'CyberArk'],
            ['FBN-AST-SC-008', 'Okta Workforce Identity', 'cloud_service', 'Security', 'high', 'active', 'confidential', 'Okta'],
            // Third-party x 7
            ['FBN-AST-TP-001', 'NIBSS NIP Gateway', 'software', 'Third-Party', 'critical', 'active', 'restricted', 'NIBSS'],
            ['FBN-AST-TP-002', 'Interswitch Card Gateway', 'software', 'Third-Party', 'critical', 'active', 'restricted', 'Interswitch'],
            ['FBN-AST-TP-003', 'Unified Payments BVN API', 'software', 'Third-Party', 'high', 'active', 'restricted', 'Unified Payments'],
            ['FBN-AST-TP-004', 'CSCS Clearing Gateway', 'software', 'Third-Party', 'high', 'active', 'restricted', 'CSCS'],
            ['FBN-AST-TP-005', 'FMDQ OTC Gateway', 'software', 'Third-Party', 'medium', 'active', 'confidential', 'FMDQ'],
            ['FBN-AST-TP-006', 'Teamapt / Moniepoint Agent Network', 'software', 'Third-Party', 'high', 'active', 'restricted', 'TeamApt'],
            ['FBN-AST-TP-007', 'Mastercard Network Gateway', 'software', 'Third-Party', 'critical', 'active', 'restricted', 'Mastercard'],
        ];
        foreach ($list as [$code, $name, $type, $cat, $crit, $status, $cls, $vendor]) {
            Asset::create([
                'organization_id' => $this->orgId,
                'asset_id_code' => $code, 'name' => $name,
                'description' => $name.' — First Bank of Nigeria production asset.',
                'asset_type' => $type, 'category' => $cat,
                'criticality' => $crit, 'status' => $status,
                'owner_id' => $uids[array_rand($uids)],
                'department' => ['IT', 'Security', 'Channels', 'Payments', 'Treasury'][array_rand(['IT', 'Security', 'Channels', 'Payments', 'Treasury'])],
                'location' => ['Lagos Marina DC', 'Abuja DR', 'Ibadan DC', 'AWS af-south-1'][array_rand([0, 1, 2, 3])],
                'ip_address' => '10.'.rand(1, 255).'.'.rand(1, 255).'.'.rand(1, 254),
                'hostname' => strtolower(str_replace(' ', '-', substr($name, 0, 30))),
                'vendor' => $vendor,
                'data_classification' => $cls,
                'purchase_date' => now()->subYears(rand(1, 7))->toDateString(),
                'end_of_life' => $code === 'FBN-AST-SV-002' ? now()->subMonths(4)->toDateString() : now()->addYears(rand(2, 7))->toDateString(),
                'tags' => [$cat, $type],
            ]);
        }
    }

    /* ================================ Vendors ================================ */
    private function vendors(): void
    {
        Vendor::where('organization_id', $this->orgId)->delete();
        $list = [
            'Interswitch Limited' => 'Payments',
            'NIBSS' => 'Payments Infrastructure',
            'CSCS' => 'Capital Markets',
            'FMDQ Exchange' => 'OTC Exchange',
            'Unified Payments' => 'Card Processing',
            'e-Tranzact International' => 'Payments',
            'TeamApt (Moniepoint)' => 'Agent Banking',
            'Appzone Group' => 'Core Banking',
            'Infosys Finacle' => 'Core Banking Vendor',
            'Oracle Nigeria' => 'Database / Flexcube',
            'Microsoft Corporation' => 'Cloud & Identity',
            'Amazon Web Services' => 'Cloud Infrastructure',
            'Google Cloud Platform' => 'Cloud / Analytics',
            'Palo Alto Networks' => 'Network Security',
            'Cisco Systems' => 'Networking',
            'Cloudflare' => 'CDN & WAF',
            'Tenable' => 'Vulnerability Management',
            'Qualys' => 'Vulnerability Management',
            'Proofpoint' => 'Email Security',
            'CyberArk' => 'Privileged Access',
            'Okta' => 'Identity',
            'Thales Group' => 'HSM & Crypto',
            'SWIFT' => 'International Payments',
            'Mastercard' => 'Card Network',
            'Visa' => 'Card Network',
            'Verve (Interswitch)' => 'Card Network',
            'MainOne Nigeria' => 'Data Centre Colo',
            'Galaxy Backbone' => 'Data Centre Colo',
            'Rack Centre' => 'Data Centre Colo',
            'MTN Nigeria' => 'Telecoms',
            'Airtel Nigeria' => 'Telecoms',
            '9mobile' => 'Telecoms',
            'Globacom' => 'Telecoms',
            'Infobip' => 'SMS Gateway',
            'Africa\'s Talking' => 'SMS Gateway',
            'KPMG Nigeria' => 'External Audit',
            'PwC Nigeria' => 'Advisory',
            'Deloitte Nigeria' => 'Advisory',
            'EY Nigeria' => 'Advisory',
            'SecurityScorecard' => 'Vendor CM',
        ];
        $uids = $this->uids();
        $i = 0;
        $statuses = ['active' => 70, 'under_review' => 20, 'inactive' => 5, 'terminated' => 5];
        foreach ($list as $name => $cat) {
            $i++;
            $risk = str_contains($cat, 'Payment') || str_contains($cat, 'Cloud') || str_contains($cat, 'Core') ? 'critical' : ['high', 'medium'][rand(0, 1)];
            $status = $this->weighted($statuses);
            Vendor::create([
                'organization_id' => $this->orgId,
                'vendor_code' => 'FBN-VEN-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'description' => $name.' is a key vendor for First Bank of Nigeria Plc.',
                'category' => $cat,
                'risk_level' => $risk,
                'status' => $status,
                'contact_name' => 'Relationship Manager',
                'contact_email' => 'contact@'.Str::slug($name, '-').'.com',
                'contact_phone' => '+234 8'.rand(00000000, 99999999),
                'country' => 'NG',
                'services_provided' => $cat,
                'contract_start' => now()->subYears(rand(1, 6)),
                'contract_end' => now()->addYears(rand(1, 4)),
                'contract_value' => rand(50_000_000, 2_000_000_000),
                'contract_currency' => 'NGN',
                'data_access_level' => ['none', 'limited', 'full'][rand(0, 2)],
                'last_assessed' => now()->subMonths(rand(1, 12)),
                'next_review_date' => now()->addMonths(rand(1, 12)),
            ]);
        }

        // Vendor assessments — 40
        $vendors = Vendor::where('organization_id', $this->orgId)->get();
        foreach ($vendors as $v) {
            VendorAssessment::create([
                'organization_id' => $this->orgId,
                'vendor_id' => $v->id,
                'title' => $v->name.' — Annual DDQ',
                'assessment_type' => ['onboarding', 'periodic', 'incident_driven'][rand(0, 2)],
                'status' => $this->weighted(['completed' => 45, 'in_progress' => 35, 'pending' => 20]),
                'assessed_by' => $uids[array_rand($uids)],
                'overall_score' => rand(55, 98),
                'assessment_date' => now()->subMonths(rand(1, 9)),
                'findings' => 'DDQ responses received; follow-up required on 3 items.',
                'recommendations' => 'Close open items per remediation plan; re-assess in 12 months.',
                'next_review_date' => now()->addMonths(rand(6, 12)),
            ]);
        }
    }

    /* ================================ Threats ================================ */
    private function threats(): void
    {
        Threat::where('organization_id', $this->orgId)->delete();
        $list = [
            'SIM Swap attack on FirstMobile', 'USSD session hijack (*894#)', 'BVN harvesting via phishing',
            'Agent-banking POS skimming (FirstMonie)', 'BEC targeting Treasury', 'NIBSS NIP mule accounts',
            'ATM jackpotting / malware', 'Card skimming at FBN ATMs', 'Insider trading on Treasury desk',
            'Spearphishing with attachment', 'Brute force on Entra ID admin accounts', 'External-facing app exploit',
            'Supply chain compromise (Infosys)', 'Cloud services abuse (AWS)', 'Token theft on Entra ID',
            'Lateral movement via SMB', 'Data exfiltration over web proxy', 'Ransomware on Finacle',
            'Defender impairing (EDR disable)', 'Web shell on public app', 'PowerShell abuse', 'Domain trust discovery',
            'C2 over DNS tunneling', 'Web session hijacking', 'Lagos DC power failure', 'NIBSS NIP switch outage',
            'ISP link failure to DR site', 'DDoS against FirstOnline', 'Natural disaster (Lagos flood)',
            'Armed robbery on cash-in-transit', 'Civil unrest affecting branches', 'Rogue employee data theft',
            'Disgruntled admin sabotage', 'Accidental PII exposure', 'NDPA non-compliance fine',
            'CBN sanctions for late returns', 'KYC/AML detection failure', 'NFIU SAR miss',
            'PCI-DSS certification lapse', 'AI voice phishing attack', 'Deepfake CEO wire request',
            'Quantum-threat to Triple-DES keys', 'Cloud misconfig exposure', 'SaaS OAuth token abuse',
            'Interswitch compromise cascade', 'NIBSS compromise cascade', 'MSP compromise (Infosys)',
            'AWS af-south-1 outage', 'HSM key compromise', 'Code signing key theft', 'Log tampering',
            'Backup corruption', 'SMS pump fraud', 'Dormant account takeover', 'CNP fraud',
            'API abuse via exposed keys', 'GitHub Actions token leak', 'SWIFT messaging fraud',
            'SWIFT infrastructure breach', 'Mastercard network outage', 'Okta compromise',
            'CyberArk vault leak', 'Contact centre social engineering', 'Branch staff coercion',
            'Legal hold evidence tampering', 'Fraudulent branch onboarding', 'Shadow IT department',
            'Dependency confusion attack', 'Container escape on Rancher', 'Kubernetes API exposure',
            'Regulatory sanction — AML fine',
        ];
        foreach (array_slice($list, 0, 70) as $i => $n) {
            Threat::create([
                'organization_id' => $this->orgId,
                'threat_id_code' => 'FBN-THR-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $n,
                'description' => $n.'. Applies to First Bank of Nigeria Plc Tier-1 DMB operations.',
                'category' => ['fraud', 'phishing', 'credential', 'malware', 'network', 'insider', 'physical', 'supply-chain', 'cloud', 'ai-threat', 'third-party', 'regulatory'][rand(0, 11)],
                'type' => ['deliberate', 'accidental', 'environmental'][rand(0, 2)],
                'severity' => ['critical', 'high', 'medium', 'low'][rand(0, 3)],
                'likelihood' => rand(1, 5), 'capability' => rand(1, 5), 'intent' => rand(1, 5),
                'is_active' => true,
                'source' => ['internal', 'ngcert', 'nitda', 'mitre-attck', 'ibm-x-force'][rand(0, 4)],
                'tags' => ['first-bank'],
                'last_seen' => now()->subDays(rand(1, 90)),
            ]);
        }
    }

    /* ================================ Risks ================================ */
    private function risks(): void
    {
        Risk::where('organization_id', $this->orgId)->delete();
        $uids = $this->uids();
        $cats = RiskCategory::where('organization_id', $this->orgId)->pluck('id', 'name')->toArray();

        // 55 titled risks spanning severity bands
        $titles = [
            ['Unauthorised access via privileged AD compromise', 'Cyber', 5, 5],
            ['Finacle ransomware event', 'Cyber', 4, 5],
            ['SWIFT messaging fraud', 'Cyber', 3, 5],
            ['BVN harvesting incident > 100,000 customers', 'Data Protection', 4, 5],
            ['NDPA § 39 late breach notification', 'Regulatory', 4, 5],
            ['NIBSS NIP multi-hour outage', 'Operational Technology', 4, 5],
            ['IBM AIX EOL exposure on legacy CRMS', 'Technology', 4, 4],
            ['BEC attack compromising Treasury desk', 'Cyber', 4, 5],
            ['ATM jackpotting campaign', 'Fraud', 4, 4],
            ['Interswitch cascade compromise', 'Third-Party', 3, 5],
            ['Lagos Marina DC environmental failure', 'Physical', 3, 5],
            ['FirstMonie agent skimming and cash-out', 'Fraud', 4, 4],
            ['DR cutover failure at disaster point', 'Technology', 3, 5],
            ['SIM swap fraud — mobile banking', 'Fraud', 5, 4],
            ['USSD session hijack via SS7', 'Fraud', 4, 4],
            ['FirstOnline DDoS at peak', 'Cyber', 4, 3],
            ['Unpatched critical firewall CVE', 'Cyber', 3, 5],
            ['O365 account takeover via phishing', 'Cyber', 5, 3],
            ['Weak vendor DDQ allowing unvetted 4th party', 'Third-Party', 4, 4],
            ['Policy attestation coverage below 95%', 'Operational Technology', 4, 3],
            ['CBN CRMS IT return late submission', 'Regulatory', 3, 4],
            ['CBN-CSAT score decline year-over-year', 'Regulatory', 3, 4],
            ['Shared privileged accounts on Finacle', 'Cyber', 5, 4],
            ['Unencrypted customer PII backups', 'Data Protection', 3, 5],
            ['PCI-DSS 4.0.1 cardholder data env gap', 'Regulatory', 4, 4],
            ['Cross-border data transfer to non-adequate country', 'Data Protection', 3, 4],
            ['Entra MFA bypass via OAuth token theft', 'Cyber', 3, 5],
            ['Cloud misconfig — S3 public bucket', 'Cyber', 3, 4],
            ['DLP false-negative on outbound PII email', 'Data Protection', 4, 3],
            ['Finacle upgrade regression causing outage', 'Technology', 3, 4],
            ['RPO exceeded on customer DB backup', 'Operational Technology', 3, 5],
            ['Insufficient SoD in payments approval chain', 'Fraud', 4, 3],
            ['CBN ITSM 24-hour advisory missed', 'Regulatory', 3, 4],
            ['Deepfake voice authorisation of wire', 'Fraud', 2, 5],
            ['Cash-in-transit armed robbery', 'Physical', 3, 4],
            ['NFIU SAR threshold misconfiguration', 'Regulatory', 3, 4],
            ['Dormant account takeover fraud', 'Fraud', 4, 3],
            ['Board KRI dashboard on stale data', 'Operational Technology', 3, 3],
            ['Shadow IT in branch offices', 'Technology', 4, 3],
            ['NDPA DSAR 30-day SLA missed', 'Regulatory', 3, 3],
            ['CyberArk vault secrets leak', 'Cyber', 2, 5],
            ['GitLab self-managed RCE', 'Cyber', 2, 5],
            ['Kubernetes API server exposure', 'Cyber', 2, 5],
            ['Thales HSM key ceremony lapse', 'Cyber', 2, 5],
            ['Branch staff coerced to exfiltrate', 'Insider', 2, 5],
            ['Card Network downtime (Mastercard)', 'Third-Party', 2, 5],
            ['FBN Quest (Islamic) core outage', 'Technology', 2, 4],
            ['Microfinance rural core outage', 'Technology', 3, 3],
            ['MTN aggregator USSD outage', 'Third-Party', 3, 3],
            ['Infosys managed service compromise', 'Third-Party', 2, 5],
            ['Dependency confusion on internal NPM', 'Cyber', 3, 3],
            ['Container escape on Rancher cluster', 'Cyber', 2, 5],
            ['KPMG audit finding carryover', 'Regulatory', 3, 3],
            ['Regulatory sanction — AML deficiency', 'Regulatory', 2, 5],
            ['SOC queue backlog > 500 alerts', 'Cyber', 3, 3],
        ];

        foreach (array_slice($titles, 0, 55) as $i => $r) {
            [$title, $cat, $l, $im] = $r;
            $inherent = $l * $im;
            $residualL = max(1, $l - rand(0, 2));
            $residualI = max(1, $im - rand(0, 2));
            $residual = $residualL * $residualI;
            $rating = fn ($s) => $s >= 20 ? 'critical' : ($s >= 12 ? 'high' : ($s >= 6 ? 'medium' : 'low'));
            $status = $this->weighted([
                'identified' => 15, 'assessed' => 20, 'mitigated' => 20, 'accepted' => 10,
                'in_progress' => 20, 'under_review' => 10, 'closed' => 5,
            ]);
            Risk::create([
                'organization_id' => $this->orgId,
                'risk_id_code' => 'FBN-RSK-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $title,
                'description' => $title.'. First Bank of Nigeria Tier-1 DMB context.',
                'category_id' => $cats[$cat] ?? null,
                'risk_owner_id' => $uids[array_rand($uids)],
                'created_by' => $uids[array_rand($uids)],
                'status' => $status,
                'inherent_likelihood' => $l, 'inherent_impact' => $im,
                'inherent_score' => $inherent, 'inherent_rating' => $rating($inherent),
                'residual_likelihood' => $residualL, 'residual_impact' => $residualI,
                'residual_score' => $residual, 'residual_rating' => $rating($residual),
                'fair_annual_loss_expectancy' => rand(20_000_000, 2_000_000_000),
                'fair_single_loss_expectancy' => rand(5_000_000, 500_000_000),
                'treatment_strategy' => ['mitigate', 'accept', 'transfer', 'avoid'][rand(0, 3)],
                'treatment_due_date' => now()->addDays(rand(-30, 180)),
                'risk_appetite' => ['within', 'above', 'below'][rand(0, 2)],
                'source' => ['self-assessment', 'audit', 'incident', 'regulator'][rand(0, 3)],
                'review_date' => now()->addMonths(rand(1, 6)),
                'created_at' => $this->ts18m(),
                'updated_at' => now()->subDays(rand(0, 30)),
            ]);
        }
    }

    private function controls(): void
    {
        Control::where('organization_id', $this->orgId)->delete();
        $aucs = AucsControl::orderBy('id')->take(120)->get();
        $uids = $this->uids();
        foreach ($aucs as $i => $a) {
            Control::create([
                'organization_id' => $this->orgId,
                'control_code' => 'FBN-CTL-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $a->title,
                'description' => $a->description,
                'domain' => $a->domain, 'category' => $a->domain,
                'type' => ['preventive', 'detective', 'corrective', 'deterrent'][rand(0, 3)],
                'nature' => ['technical', 'administrative', 'physical'][rand(0, 2)],
                'frequency' => ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annual'][rand(0, 5)],
                'owner_id' => $uids[array_rand($uids)],
                'status' => $this->weighted(['active' => 78, 'draft' => 8, 'under_review' => 10, 'deprecated' => 4]),
                'effectiveness' => $this->weighted(['effective' => 60, 'partially_effective' => 25, 'ineffective' => 8, 'not_assessed' => 7]),
                'is_key_control' => rand(0, 10) < 4,
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
        $uids = $this->uids();
        $titles = [
            'Information Security Policy', 'Acceptable Use Policy', 'Access Control Policy', 'Change Management Policy',
            'Incident Response Policy', 'Business Continuity Policy', 'Data Protection & Privacy Policy (NDPA)',
            'Data Classification Policy', 'Data Retention Policy', 'Third-Party Risk Management Policy',
            'Outsourcing Policy (CBN-aligned)', 'Vulnerability Management Policy', 'Patch Management Policy',
            'Password & MFA Policy', 'Cryptography & Key Management Policy', 'Physical & Environmental Security Policy',
            'Secure SDLC Policy', 'Cloud Security Policy', 'Backup & Recovery Policy', 'Email & Messaging Policy',
            'Remote Access & Mobile Device Policy', 'Logging, Monitoring & Audit Policy', 'Fraud Risk Management Policy',
            'CBN RBCSF Implementation Policy', 'PCI-DSS 4.0.1 Compliance Policy', 'AML / CFT Policy',
            'Whistleblower Policy', 'Staff Screening Policy', 'Board Risk Oversight Charter', 'AI Usage & Ethics Policy',
        ];
        foreach ($titles as $i => $t) {
            $status = $this->weighted(['active' => 55, 'draft' => 15, 'under_review' => 15, 'approved' => 8, 'expired' => 4, 'archived' => 3]);
            $policy = Policy::create([
                'organization_id' => $this->orgId,
                'policy_code' => 'FBN-POL-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $t,
                'description' => $t.' for First Bank of Nigeria Plc.',
                'content' => "# {$t}\n\n## Purpose\n## Scope\n## Policy Statements\n## Roles & Responsibilities",
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
            for ($v = 1; $v <= 5; $v++) {
                PolicyVersion::create([
                    'policy_id' => $policy->id,
                    'version_number' => sprintf('%d.0', $v),
                    'content' => "Version {$v} content of {$t}.",
                    'change_summary' => $v === 1 ? 'Initial version' : 'Annual review',
                    'created_by' => $uids[array_rand($uids)],
                    'created_at' => now()->subYears(5 - $v + 1)->subMonths(rand(0, 11)),
                    'updated_at' => now()->subYears(5 - $v + 1)->subMonths(rand(0, 11)),
                ]);
            }
            foreach ($uids as $uid) {
                PolicyAttestation::updateOrCreate(
                    ['organization_id' => $this->orgId, 'policy_id' => $policy->id, 'user_id' => $uid],
                    [
                        'status' => $this->weighted(['completed' => 65, 'pending' => 22, 'overdue' => 10, 'waived' => 3]),
                        'acknowledged_at' => rand(0, 1) ? now()->subDays(rand(0, 90)) : null,
                        'due_date' => now()->addDays(rand(1, 60)),
                    ]
                );
            }
        }
    }

    /* ============================ Vulnerabilities ============================ */
    private function vulnerabilities(): void
    {
        Vulnerability::where('organization_id', $this->orgId)->delete();
        $uids = $this->uids();
        $cves = [
            ['CVE-2024-21413', 'Outlook RCE (MonikerLink)', 'critical', 9.8],
            ['CVE-2024-3400', 'PAN-OS Command Injection', 'critical', 10.0],
            ['CVE-2024-27198', 'JetBrains TeamCity Auth Bypass', 'critical', 9.8],
            ['CVE-2024-20353', 'Cisco ASA DoS', 'high', 8.6],
            ['CVE-2023-46747', 'F5 BIG-IP Config Utility RCE', 'critical', 9.8],
            ['CVE-2023-34362', 'MOVEit SQL Injection', 'critical', 9.8],
            ['CVE-2024-30088', 'Windows Kernel EoP', 'high', 7.8],
            ['CVE-2023-36884', 'Office Search Path Vulnerability', 'high', 8.8],
            ['CVE-2024-4577', 'PHP CGI Argument Injection', 'critical', 9.8],
            ['CVE-2023-4966', 'NetScaler Memory Disclosure', 'critical', 9.4],
            ['CVE-2024-1086', 'Linux Kernel nf_tables UAF', 'high', 7.8],
            ['CVE-2024-23897', 'Jenkins Arbitrary File Read', 'critical', 9.8],
            ['CVE-2024-21762', 'Fortinet SSL VPN OOB Write', 'critical', 9.6],
            ['CVE-2024-28986', 'SolarWinds Web Help Desk RCE', 'critical', 9.8],
            ['CVE-2023-3519', 'Citrix ADC Code Injection', 'critical', 9.8],
        ];
        $assetIds = Asset::where('organization_id', $this->orgId)->pluck('id')->toArray();
        for ($i = 0; $i < 120; $i++) {
            $cve = $cves[array_rand($cves)];
            Vulnerability::create([
                'organization_id' => $this->orgId,
                'vuln_id_code' => 'FBN-VLN-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'cve_id' => $cve[0].'-v'.rand(1, 99),
                'title' => $cve[1],
                'description' => $cve[1].'. Detected on First Bank asset(s).',
                'severity' => $cve[2],
                'cvss_score' => $cve[3],
                'cvss_vector' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H',
                'source' => ['tenable', 'qualys', 'defender', 'ngcert', 'manual'][rand(0, 4)],
                'status' => $this->weighted(['open' => 40, 'in_progress' => 30, 'remediated' => 22, 'accepted' => 5, 'false_positive' => 3]),
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
        $uids = $this->uids();
        for ($i = 0; $i < 50; $i++) {
            Patch::create([
                'organization_id' => $this->orgId,
                'patch_id_code' => 'FBN-PTC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => 'KB50'.rand(10000, 99999).' — Monthly rollup',
                'description' => 'Vendor monthly patch package applicable to FBN estate.',
                'vendor' => ['Microsoft', 'Red Hat', 'Oracle', 'Cisco', 'Palo Alto', 'VMware'][rand(0, 5)],
                'version' => '202'.rand(5, 6).'-'.str_pad((string) rand(1, 12), 2, '0', STR_PAD_LEFT),
                'release_date' => now()->subDays(rand(1, 180)),
                'severity' => ['critical', 'high', 'medium', 'low'][rand(0, 3)],
                'status' => $this->weighted(['pending' => 20, 'testing' => 25, 'approved' => 20, 'deployed' => 30, 'failed' => 5]),
                'affected_systems' => ['Core DB', 'AD DCs', 'Web servers', 'App servers', 'Cloud VMs'][rand(0, 4)],
                'deployed_at' => now()->subDays(rand(0, 90)),
                'deployed_by' => $uids[array_rand($uids)],
            ]);
        }
    }

    private function incidents(): void
    {
        Incident::where('organization_id', $this->orgId)->delete();
        $uids = $this->uids();
        $items = [
            ['BEC spoofing Group CFO to Treasury', 'phishing', 'high'],
            ['PII exfiltration attempt on customer DB', 'unauthorized_access', 'high'],
            ['Ransomware blocked on laptop estate', 'malware', 'medium'],
            ['NIBSS NIP recon anomaly', 'other', 'high'],
            ['Phishing campaign targeting staff', 'phishing', 'medium'],
            ['Suspicious admin login from foreign IP', 'unauthorized_access', 'high'],
            ['DDoS burst mitigated on FirstOnline', 'dos', 'medium'],
            ['USB exfil attempt — branch', 'data_leak', 'high'],
            ['ATM cash-out via compromised agent credentials', 'other', 'critical'],
            ['SIM-swap fraud — 12 customers affected', 'other', 'high'],
            ['Finacle DB patch failure — 3h outage', 'other', 'high'],
            ['Web shell found on public-facing app', 'malware', 'critical'],
            ['Fake FBN domain detected', 'phishing', 'medium'],
            ['Suspicious wire to sanctions country (blocked)', 'other', 'high'],
            ['Entra account takeover — 3 users', 'unauthorized_access', 'high'],
            ['Mastercard network gateway reroute test failure', 'other', 'medium'],
            ['Insider anomaly — unusual DB queries', 'insider_threat', 'high'],
            ['CyberArk vault access anomaly', 'unauthorized_access', 'high'],
            ['GitLab self-managed RCE attempt (blocked)', 'other', 'high'],
            ['SWIFT alliance anomaly', 'unauthorized_access', 'critical'],
            ['Data breach — 50k customer PII exposed', 'data_leak', 'critical'],
            ['USSD SS7 hijack attempt', 'other', 'high'],
            ['Deepfake voice request to wire NGN 500m', 'phishing', 'high'],
            ['Physical: break-in attempt at Abuja DR', 'other', 'medium'],
            ['Physical: Marina DC generator failure', 'other', 'high'],
            ['Supply chain: Infosys credential exposure', 'unauthorized_access', 'high'],
            ['Kubernetes API server expose (remediated)', 'other', 'high'],
            ['HSM key ceremony anomaly', 'unauthorized_access', 'medium'],
            ['Backup corruption on Treasury store', 'other', 'high'],
            ['False positive — AV misfired', 'malware', 'low'],
        ];
        foreach ($items as $i => [$title, $type, $severity]) {
            Incident::create([
                'organization_id' => $this->orgId,
                'incident_id_code' => 'FBN-INC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $title,
                'description' => $title.'. Response per CBN ITSM procedure.',
                'type' => $type,
                'severity' => $severity,
                'status' => $this->weighted(['detected' => 12, 'triaged' => 15, 'investigating' => 20, 'containing' => 13, 'eradicating' => 10, 'recovering' => 10, 'closed' => 20]),
                'source' => ['SIEM', 'SOC analyst', 'customer report', 'automated alert', 'ngCERT'][rand(0, 4)],
                'detected_at' => now()->subDays(rand(1, 90)),
                'responded_at' => now()->subDays(rand(0, 89)),
                'contained_at' => rand(0, 1) ? now()->subDays(rand(0, 85)) : null,
                'resolved_at' => rand(0, 1) ? now()->subDays(rand(0, 60)) : null,
                'closed_at' => rand(0, 3) === 0 ? now()->subDays(rand(0, 45)) : null,
                'assigned_to' => $uids[array_rand($uids)],
                'lead_investigator_id' => $uids[array_rand($uids)],
                'affected_users_count' => rand(0, 1000),
                'is_data_breach' => in_array($title, ['Data breach — 50k customer PII exposed', 'PII exfiltration attempt on customer DB', 'USB exfil attempt — branch', 'Insider anomaly — unusual DB queries']),
                'root_cause' => 'Documented during handling.',
                'lessons_learned' => $i < 15 ? 'Lessons logged; follow-up Issue created.' : null,
            ]);
        }
    }

    private function dataBreaches(): void
    {
        DataBreach::where('organization_id', $this->orgId)->delete();
        $uids = $this->uids();
        $incidents = Incident::where('organization_id', $this->orgId)->where('is_data_breach', true)->pluck('id')->toArray();
        for ($i = 0; $i < 15; $i++) {
            DataBreach::create([
                'organization_id' => $this->orgId,
                'incident_id' => ! empty($incidents) ? $incidents[array_rand($incidents)] : null,
                'breach_id_code' => 'FBN-BRC-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => "Data breach event #{$i}",
                'description' => 'NDPA-reportable personal data breach.',
                'breach_type' => ['confidentiality', 'integrity', 'availability'][rand(0, 2)],
                'data_types_affected' => ['BVN', 'PII', 'Account numbers', 'Contact details'],
                'records_affected' => rand(10, 100_000),
                'status' => $this->weighted(['identified' => 12, 'investigating' => 18, 'contained' => 18, 'notified' => 25, 'resolved' => 22, 'closed' => 5]),
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
        $uids = $this->uids();
        for ($i = 0; $i < 50; $i++) {
            SecurityAlert::create([
                'organization_id' => $this->orgId,
                'alert_id_code' => 'FBN-ALR-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => 'SIEM alert #'.rand(1000, 9999).' — Suspicious activity',
                'description' => 'SIEM-sourced alert with enriched context.',
                'source' => ['Sentinel', 'Defender', 'Splunk', 'Wazuh', 'ngCERT'][rand(0, 4)],
                'severity' => ['critical', 'high', 'medium', 'low'][rand(0, 3)],
                'status' => $this->weighted(['new' => 35, 'acknowledged' => 25, 'investigating' => 20, 'resolved' => 17, 'dismissed' => 3]),
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
        $uids = $this->uids();
        $plans = [
            ['Enterprise BCP', 'bcp'],
            ['Core Banking DR Plan (Finacle)', 'dr'],
            ['Core Banking DR Plan (Flexcube)', 'dr'],
            ['Channels BCP', 'bcp'],
            ['Payments BCP', 'bcp'],
            ['Treasury DR Plan', 'dr'],
            ['Lagos Marina Facility BCP', 'bcp'],
            ['Abuja DR Site Readiness', 'dr'],
            ['Crisis Management Plan', 'crisis'],
            ['Pandemic Response Plan', 'crisis'],
            ['Cyber-Incident Recovery Plan', 'dr'],
            ['SWIFT Continuity Plan', 'dr'],
        ];
        foreach ($plans as $i => [$name, $type]) {
            BcpPlan::create([
                'organization_id' => $this->orgId,
                'plan_code' => 'FBN-BCP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $name, 'description' => $name.' for First Bank of Nigeria Plc.',
                'plan_type' => $type,
                'status' => $this->weighted(['active' => 60, 'under_review' => 15, 'tested' => 15, 'draft' => 10]),
                'owner_id' => $uids[array_rand($uids)],
                'scope' => 'All operations and IT systems.',
                'objectives' => 'Ensure continuity within RTO/RPO.',
                'rto_hours' => [1, 2, 4, 8, 12, 24][rand(0, 5)],
                'rpo_hours' => [0, 1, 2, 4, 12][rand(0, 4)],
                'last_tested' => now()->subMonths(rand(1, 12)),
                'next_test_date' => now()->addMonths(rand(1, 6)),
                'next_review_date' => now()->addMonths(rand(1, 12)),
                'approved_by' => $uids[array_rand($uids)],
                'approved_at' => now()->subMonths(rand(1, 12)),
            ]);
        }

        BiaRecord::where('organization_id', $this->orgId)->delete();
        $bia = [
            ['Retail Banking — FirstOnline', 'Digital', 'critical', 1, 0],
            ['Retail Banking — FirstMobile', 'Digital', 'critical', 1, 0],
            ['Retail Banking — USSD', 'Channels', 'critical', 1, 0],
            ['Payments — NIBSS NIP', 'Payments', 'critical', 1, 0],
            ['Payments — Interswitch Switch', 'Payments', 'critical', 1, 0],
            ['Payments — SWIFT International', 'Payments', 'critical', 2, 1],
            ['Treasury Trading', 'Treasury', 'high', 4, 1],
            ['Card Operations (Verve / Mastercard)', 'Payments', 'high', 4, 1],
            ['FirstMonie Agent Banking', 'Channels', 'high', 4, 2],
            ['Loan Origination', 'Retail', 'medium', 24, 4],
        ];
        foreach ($bia as [$process, $dept, $crit, $rto, $rpo]) {
            BiaRecord::create([
                'organization_id' => $this->orgId,
                'process_name' => $process, 'department' => $dept,
                'description' => $process.' BIA.',
                'criticality' => $crit, 'rto_hours' => $rto, 'rpo_hours' => $rpo,
                'mtpd_hours' => $rto * 2,
                'financial_impact_per_hour' => rand(10_000_000, 200_000_000),
                'dependencies' => 'Core banking, NIBSS, Interswitch, Mastercard, SWIFT',
                'recovery_strategy' => 'Failover to DR site; activate manual workaround; engage vendor support.',
                'owner_id' => $uids[array_rand($uids)],
            ]);
        }
    }

    private function compliance(): void
    {
        ComplianceAssessment::where('organization_id', $this->orgId)->delete();
        $uids = $this->uids();
        $frameworks = ControlFramework::take(5)->pluck('id')->toArray();
        if (empty($frameworks)) {
            return;
        }
        for ($i = 0; $i < 25; $i++) {
            $total = rand(50, 300);
            $comp = (int) ($total * 0.6);
            $partial = (int) ($total * 0.2);
            $non = (int) ($total * 0.12);
            $na = $total - $comp - $partial - $non;
            ComplianceAssessment::create([
                'organization_id' => $this->orgId,
                'framework_id' => $frameworks[array_rand($frameworks)],
                'title' => 'Compliance Assessment #'.($i + 1),
                'description' => 'Periodic compliance assessment.',
                'status' => $this->weighted(['planned' => 12, 'in_progress' => 42, 'completed' => 38, 'cancelled' => 8]),
                'lead_assessor_id' => $uids[array_rand($uids)],
                'start_date' => now()->subMonths(rand(1, 12)),
                'end_date' => now()->subMonths(rand(0, 1)),
                'due_date' => now()->addMonths(rand(1, 6)),
                'overall_score' => rand(55, 98),
                'total_requirements' => $total,
                'compliant_count' => $comp, 'partial_count' => $partial,
                'non_compliant_count' => $non, 'not_applicable_count' => $na,
                'summary' => 'Assessment completed with mix of compliant and partial findings.',
            ]);
        }
    }

    /* ================================ Issues ================================ */
    private function issues(): void
    {
        // Target 100 issues across low/medium/high/critical with realistic lifecycle states
        Issue::where('organization_id', $this->orgId)->delete();
        $uids = $this->uids();
        $severities = ['critical' => 15, 'high' => 30, 'moderate' => 35, 'low' => 20]; // 100 weighted
        $statuses = ['open' => 25, 'in_progress' => 30, 'blocked' => 8, 'remediated' => 15, 'verified' => 10, 'closed' => 10, 'escalated' => 2];
        $sources = ['audit', 'control_test', 'risk', 'incident', 'vulnerability', 'assessment', 'ndpa_finding'];
        $titles = [
            'MFA rollout incomplete for privileged admin accounts',
            'Outdated SSL cert on public app',
            'Finacle DB missing encryption-at-rest',
            'USSD aggregator SLA below threshold',
            'Branch staff awareness training coverage < 90%',
            'Third-party DDQ refresh overdue for 12 vendors',
            'Backup verification not run for 30 days',
            'SWIFT HSM ceremony log gap',
            'DLP rule exception expired',
            'Vendor PCI-DSS AoC outdated',
            'Policy attestation coverage < 95%',
            'Privileged AD accounts missing just-in-time access',
            'Cloud misconfig — missing CloudTrail',
            'Runbook stale > 180 days',
            'Access review overdue for Finacle admins',
            'Patch SLA exceeded on legacy AIX',
            'Vuln KEV > 14 days open',
            'Fraud alert threshold misconfigured',
            'NDPA DSAR response > 30 days (1 case)',
            'Customer PII in log files (historic)',
            'Backup encryption key rotation overdue',
            'BCP test findings open > 90 days',
            'Vendor insurance certificate expired',
            'Incident RCA document gap',
            'CBN return late submission risk',
            'Physical: CCTV retention < 90 days',
            'Contact centre call-recording gap',
            'Branch safe access log discrepancy',
            'SoD gap in payments process',
            'Entra legacy protocol enabled',
        ];
        for ($i = 0; $i < 100; $i++) {
            $severity = $this->weighted($severities);
            $status = $this->weighted($statuses);
            $title = $titles[$i % count($titles)].' ('.($i + 1).')';
            Issue::create([
                'organization_id' => $this->orgId,
                'source_type' => $sources[array_rand($sources)],
                'source_id' => rand(1, 40),
                'title' => $title,
                'description' => $title.'. First Bank of Nigeria CAPA item.',
                'severity' => $severity,
                'owner_id' => $uids[array_rand($uids)],
                'due_date' => now()->addDays(rand(-10, 120))->toDateString(),
                'sla_minutes' => rand(60, 43200),
                'status' => $status,
                'root_cause' => 'Identified during audit / CCM / incident follow-up.',
                'remediation_plan' => 'Assign owner, document corrective action, track to closure.',
                'created_at' => $this->ts18m(),
                'updated_at' => now()->subDays(rand(0, 30)),
            ]);
        }
    }

    /* =================== SoA + Gaps for First Bank =================== */
    private function soaAndGaps(): void
    {
        $fw = ControlFramework::where('slug', 'iso-27001-2022')->first();
        if (! $fw) {
            return;
        }
        $leaf = DB::table('framework_requirements')->where('framework_id', $fw->id)->where('level', 1)->get();
        $controls = Control::where('organization_id', $this->orgId)->pluck('id')->toArray();

        DB::table('statements_of_applicability')->where('organization_id', $this->orgId)->delete();
        foreach ($leaf as $req) {
            $applicable = rand(1, 100) <= 95;
            $impl = $applicable ? $this->weighted(['implemented' => 60, 'partial' => 25, 'planned' => 10, 'not_started' => 5]) : 'not_applicable';
            DB::table('statements_of_applicability')->insert([
                'organization_id' => $this->orgId,
                'requirement_id' => $req->id,
                'is_applicable' => $applicable,
                'justification' => $applicable
                    ? 'Implemented per First Bank of Nigeria ISMS scope (Tier-1 DMB).'
                    : 'Not applicable — FBN does not process this scenario.',
                'implementation_status' => $impl,
                'control_id' => ! empty($controls) ? $controls[array_rand($controls)] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('gaps')->where('organization_id', $this->orgId)->delete();
        $uids = $this->uids();
        $gapData = [
            ['A.5.1', 'major', 'open', 'Policy suite review cycle overdue'],
            ['A.5.7', 'minor', 'in_progress', 'Threat-intel integration with ngCERT partially manual'],
            ['A.5.24', 'critical', 'open', 'NDPC 72h breach workflow not fully automated'],
            ['A.5.30', 'major', 'in_progress', 'SWIFT DR test cycle extended beyond target'],
            ['A.6.3', 'minor', 'open', 'Awareness training coverage 89% vs target 95%'],
            ['A.7.4', 'minor', 'open', 'Marina DC CCTV retention 60 days vs 90-day target'],
            ['A.8.2', 'major', 'open', 'Privileged AD accounts without MFA — 18 identified'],
            ['A.8.5', 'major', 'open', 'Entra legacy authentication protocols still enabled'],
            ['A.8.8', 'critical', 'in_progress', 'Critical patches > 72h on 11 systems'],
            ['A.8.9', 'minor', 'resolved', 'Windows baseline drift remediated 2026-03-20'],
            ['A.8.15', 'minor', 'open', 'Log forwarding gaps on 5 legacy AIX systems'],
            ['A.8.24', 'major', 'in_progress', 'TLS 1.0 still enabled on internal admin portal'],
            ['A.8.28', 'minor', 'open', 'SAST coverage missing for 3 legacy repos'],
            ['A.5.34', 'minor', 'open', 'PII DPIA not completed for new mobile feature'],
            ['A.6.7', 'minor', 'in_progress', 'Remote-working policy not fully attested by contractors'],
            ['A.8.12', 'major', 'in_progress', 'DLP false-negative rate on outbound email'],
            ['A.8.16', 'minor', 'open', 'Monitoring coverage gap on payments microservices'],
            ['A.8.22', 'critical', 'open', 'Network segmentation gap between CDE and corporate'],
            ['A.8.32', 'major', 'in_progress', 'Change management bypass incidents — 3 YTD'],
            ['A.5.37', 'minor', 'open', 'Operating procedures documentation 82% complete'],
        ];
        $sevToPri = ['critical' => 1, 'major' => 2, 'moderate' => 3, 'minor' => 4];
        $reqs = $leaf->pluck('id', 'requirement_code');
        foreach ($gapData as $i => [$code, $sev, $status, $title]) {
            DB::table('gaps')->insert([
                'organization_id' => $this->orgId,
                'requirement_id' => $reqs[$code] ?? null,
                'gap_code' => 'FBN-GAP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $title,
                'description' => 'ISMS non-conformity identified during internal audit — '.$title,
                'severity' => $sev, 'status' => $status, 'priority' => $sevToPri[$sev] ?? 3,
                'remediation_plan' => 'Assign owner, document CAPA, track to closure.',
                'assigned_to' => $uids[array_rand($uids)] ?? null,
                'due_date' => now()->addDays(rand(15, 120))->toDateString(),
                'notes' => 'First Bank ISMS internal audit Q1 2026.',
                'created_at' => now()->subDays(rand(1, 120)),
                'updated_at' => now(),
            ]);
        }
    }

    /* ================================ CSAT ================================ */
    private function csat(): void
    {
        $creator = User::where('organization_id', $this->orgId)
            ->where('email', 'admin@firstbanknigeria.ng')->first()
            ?? User::where('organization_id', $this->orgId)->first();
        if (! $creator) {
            return;
        }
        $year = (int) now()->format('Y');

        $assessment = CsatAssessment::updateOrCreate(
            ['organization_id' => $this->orgId, 'assessment_year' => $year, 'framework_version' => 'FFIEC-CAT-1.1'],
            [
                'status' => 'in_progress',
                'submission_deadline' => now()->addMonths(3)->toDateString(),
                'composite_risk_level' => 'significant',
                'composite_risk_score' => 3.7,
                'overall_maturity_level' => 'intermediate',
                'ai_readiness_score' => 75,
                'ai_readiness_rag' => 'green',
                'created_by' => $creator->id,
            ]
        );

        CsatInstitutionProfile::updateOrCreate(
            ['assessment_id' => $assessment->id],
            [
                'institution_name' => 'First Bank of Nigeria Plc',
                'cbn_licence_type' => 'dmb',
                'head_office_address' => 'Samuel Asabia House, 35 Marina, Lagos, Nigeria',
                'ciso_name' => 'Dr. Oluwatoyin Akintayo',
                'ciso_email' => 'ciso@firstbanknigeria.ng',
                'ciso_phone' => '+234-803-000-0001',
                'ciso_grade' => 'General Manager',
                'ciso_reporting_line' => 'MD/CEO via Group CIO',
                'parent_bank_name' => 'FBN Holdings Plc',
            ]
        );

        CsatIrResponse::where('assessment_id', $assessment->id)->delete();
        CsatMaResponse::where('assessment_id', $assessment->id)->delete();

        // All 47 IR responses centred around 'significant' risk
        $irWeights = [1 => 5, 2 => 15, 3 => 35, 4 => 30, 5 => 15];
        foreach (CsatIrQuestion::orderBy('id')->get() as $q) {
            CsatIrResponse::create([
                'assessment_id' => $assessment->id,
                'question_id' => $q->id,
                'selected_level' => $this->weighted($irWeights),
                'comment' => rand(0, 100) < 60 ? 'Validated per Q1 2026 self-assessment; aligned to CBN RBCSF § 3.9.3.' : null,
                'completed_by' => $creator->id,
                'completed_at' => now()->subDays(rand(1, 60)),
            ]);
        }

        // ~75% of maturity statements answered with heavier Yes
        $statements = CsatMaStatement::orderBy('id')->get();
        $target = (int) round($statements->count() * 0.75);
        $responseWeights = ['yes' => 65, 'yes_cc' => 12, 'no' => 15, 'na' => 8];
        foreach ($statements->shuffle()->take($target) as $stmt) {
            $resp = $this->weighted($responseWeights);
            CsatMaResponse::create([
                'assessment_id' => $assessment->id,
                'statement_id' => $stmt->id,
                'response' => $resp,
                'has_compensating_control' => $resp === 'yes_cc',
                'comment' => rand(0, 100) < 50 ? 'Evidence filed in Evidence Vault.' : null,
                'responded_by' => $creator->id,
                'responded_at' => now()->subDays(rand(1, 90)),
            ]);
        }
    }
}
