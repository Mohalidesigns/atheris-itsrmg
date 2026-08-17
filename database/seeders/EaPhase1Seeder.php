<?php

namespace Database\Seeders;

use App\Models\Ea\Capability;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApplication;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\MaturityAssessment;
use App\Models\Ea\MaturityDomain;
use App\Models\Ea\MaturityQuestion;
use App\Models\Ea\MaturityResponse;
use App\Models\Ea\Standard;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ValueStream;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class EaPhase1Seeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Seeding EA-Studio Phase 1 demo data…');

        // Use First Bank tenant as the pilot; fallback to Kano Heritage.
        $tenantId = Organization::where('slug', 'first-bank-nigeria')->value('id')
            ?? Organization::where('slug', 'kano-heritage-bank')->value('id')
            ?? 1;

        $this->seedCapabilities($tenantId);
        $this->seedValueStreams($tenantId);
        $this->seedApplications($tenantId);
        $this->seedTech($tenantId);
        $this->seedStandards($tenantId);
        $this->seedInfoDomains($tenantId);
        $this->seedLogicalEntities($tenantId);
        $this->seedDataFlows($tenantId);
        $this->seedMaturity($tenantId);
    }

    private function seedCapabilities(int $tenantId): void
    {
        if (Capability::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        // BIAN-inspired top-level capabilities (simplified)
        $rootSet = [
            ['BIAN-CC-001', 'Customer Management'],
            ['BIAN-CC-002', 'Sales & Distribution'],
            ['BIAN-CC-003', 'Product Management'],
            ['BIAN-CC-004', 'Operations & Execution'],
            ['BIAN-CC-005', 'Finance & Treasury'],
            ['BIAN-CC-006', 'Risk & Compliance'],
            ['BIAN-CC-007', 'Enterprise Services'],
            ['BIAN-CC-008', 'Channels'],
        ];
        $rootIds = [];
        foreach ($rootSet as [$code, $name]) {
            $rootIds[$code] = Capability::create([
                'organization_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'description' => "BIAN v12 top-level capability: {$name}",
                'level' => 1,
                'criticality' => 'high',
                'maturity' => rand(2, 4),
                'source' => 'bian',
            ])->id;
        }

        $subs = [
            'BIAN-CC-001' => [['Customer Onboarding', 'critical'], ['Customer Servicing', 'high'], ['Customer Offers', 'medium']],
            'BIAN-CC-002' => [['Branch Operations', 'high'], ['Digital Channels', 'critical'], ['Agent Network', 'high']],
            'BIAN-CC-003' => [['Loans & Credit', 'critical'], ['Deposits', 'high'], ['Cards', 'high'], ['Treasury Products', 'medium']],
            'BIAN-CC-004' => [['Payments Processing', 'critical'], ['Clearing & Settlement', 'critical'], ['Reconciliations', 'high']],
            'BIAN-CC-005' => [['General Ledger', 'high'], ['Treasury Mgmt', 'high'], ['Regulatory Reporting', 'critical']],
            'BIAN-CC-006' => [['Credit Risk', 'high'], ['Operational Risk', 'high'], ['Compliance & AML', 'critical'], ['Information Security', 'critical']],
            'BIAN-CC-007' => [['HR Management', 'medium'], ['Procurement', 'medium'], ['IT Operations', 'high'], ['Enterprise Architecture', 'high']],
            'BIAN-CC-008' => [['Internet Banking', 'critical'], ['Mobile Banking', 'critical'], ['USSD', 'critical'], ['ATM / POS', 'critical'], ['Contact Centre', 'high']],
        ];
        foreach ($subs as $parentCode => $children) {
            foreach ($children as $i => [$name, $crit]) {
                Capability::create([
                    'organization_id' => $tenantId,
                    'parent_id' => $rootIds[$parentCode],
                    'code' => $parentCode.'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'name' => $name,
                    'description' => "{$name} sub-capability under ".$parentCode,
                    'level' => 2,
                    'criticality' => $crit,
                    'maturity' => rand(1, 5),
                    'source' => 'bian',
                ]);
            }
        }

        // Tenant-custom capabilities (FBN-specific)
        $custom = [
            ['FBN-CAP-001', 'FirstBanca Private Banking', 'medium'],
            ['FBN-CAP-002', 'Diaspora Remittance', 'high'],
            ['FBN-CAP-003', 'Islamic Banking (FBN Quest)', 'medium'],
            ['FBN-CAP-004', 'Microfinance (Rural)', 'medium'],
            ['FBN-CAP-005', 'FirstMonie Agent Network', 'critical'],
        ];
        foreach ($custom as [$code, $name, $crit]) {
            Capability::create([
                'organization_id' => $tenantId, 'code' => $code, 'name' => $name,
                'description' => $name.' — First Bank-specific capability.',
                'level' => 2, 'criticality' => $crit, 'maturity' => rand(2, 4),
                'source' => 'custom',
                'parent_id' => $rootIds['BIAN-CC-003'],
            ]);
        }
    }

    private function seedValueStreams(int $tenantId): void
    {
        if (ValueStream::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $streams = [
            ['VS-ONBOARD', 'Customer Onboarding', 'End-to-end account opening from lead to funded account.', [
                ['name' => 'Lead capture', 'duration' => '1 day'],
                ['name' => 'KYC / AML', 'duration' => '2 days'],
                ['name' => 'Account creation', 'duration' => '1 day'],
                ['name' => 'BVN linkage', 'duration' => '1 day'],
                ['name' => 'First funding', 'duration' => '3 days'],
            ], ['Branch staff', 'KYC officer', 'Compliance', 'Ops'], ['BIAN-CC-001-01']],
            ['VS-LOAN', 'Consumer Loan Origination', 'Consumer loan from application to disbursement.', [
                ['name' => 'Application'],
                ['name' => 'Credit scoring'],
                ['name' => 'Approval'],
                ['name' => 'Documentation'],
                ['name' => 'Disbursement'],
            ], ['Relationship Manager', 'Credit Analyst', 'Treasury'], ['BIAN-CC-003-01']],
            ['VS-PAYMENTS', 'Domestic Payment', 'NIBSS NIP payment from initiation to settlement.', [
                ['name' => 'Initiation'], ['name' => 'Validation'], ['name' => 'Routing to NIBSS'], ['name' => 'Settlement'], ['name' => 'Notification'],
            ], ['Channels', 'Payments Ops', 'NIBSS'], ['BIAN-CC-004-01']],
            ['VS-INCIDENT', 'Cyber Incident Response', 'Cyber incident from detection to closure.', [
                ['name' => 'Detection'], ['name' => 'Triage'], ['name' => 'Containment'], ['name' => 'Eradication'], ['name' => 'Recovery'], ['name' => 'Lessons learned'],
            ], ['SOC', 'CISO', 'CRO', 'External Comms'], ['BIAN-CC-006-04']],
            ['VS-REGRET', 'Regulatory Reporting', 'Quarterly regulator return lifecycle.', [
                ['name' => 'Data gather'], ['name' => 'Validate'], ['name' => 'Sign-off'], ['name' => 'Submit'], ['name' => 'Archive'],
            ], ['Compliance', 'Finance', 'CISO'], ['BIAN-CC-005-03']],
        ];
        foreach ($streams as [$code, $name, $desc, $stages, $participants, $caps]) {
            ValueStream::create([
                'organization_id' => $tenantId, 'code' => $code, 'name' => $name, 'description' => $desc,
                'stages' => $stages, 'participants' => $participants, 'linked_capabilities' => $caps,
            ]);
        }
    }

    private function seedApplications(int $tenantId): void
    {
        if (EaApplication::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $time = ['Tolerate' => 15, 'Invest' => 35, 'Migrate' => 30, 'Eliminate' => 20];
        $lifecycle = ['live' => 70, 'build' => 10, 'plan' => 5, 'sunset' => 12, 'retired' => 3];
        $capabilityIds = Capability::where('organization_id', $tenantId)->pluck('id')->toArray();

        $seed = [
            ['FBN-APP-001', 'Finacle Core Banking (Prod)', 'critical'],
            ['FBN-APP-002', 'Finacle Core Banking (DR)', 'critical'],
            ['FBN-APP-003', 'Oracle Flexcube (Treasury)', 'high'],
            ['FBN-APP-004', 'FBN Payments Hub', 'critical'],
            ['FBN-APP-005', 'FirstMobile App', 'critical'],
            ['FBN-APP-006', 'FirstOnline Internet Banking', 'critical'],
            ['FBN-APP-007', 'USSD *894# Platform', 'critical'],
            ['FBN-APP-008', 'FirstMonie Agent Platform', 'high'],
            ['FBN-APP-009', 'ATM Network Management', 'critical'],
            ['FBN-APP-010', 'Card Issuance System', 'high'],
            ['FBN-APP-011', 'SWIFT Alliance Gateway', 'critical'],
            ['FBN-APP-012', 'NIBSS NIP Gateway', 'critical'],
            ['FBN-APP-013', 'Interswitch Card Gateway', 'critical'],
            ['FBN-APP-014', 'AML Screening (Actimize)', 'high'],
            ['FBN-APP-015', 'Fraud Detection (FICO Falcon)', 'high'],
            ['FBN-APP-016', 'KYC Onboarding Portal', 'high'],
            ['FBN-APP-017', 'CRM (Salesforce)', 'medium'],
            ['FBN-APP-018', 'Contact Centre (Genesys)', 'medium'],
            ['FBN-APP-019', 'Data Warehouse (Teradata)', 'high'],
            ['FBN-APP-020', 'Analytics Lakehouse (Databricks)', 'medium'],
            ['FBN-APP-021', 'SAP ERP (HR/Finance)', 'high'],
            ['FBN-APP-022', 'Workday (HR)', 'medium'],
            ['FBN-APP-023', 'Jira / Confluence (Internal)', 'low'],
            ['FBN-APP-024', 'ServiceNow ITSM', 'medium'],
            ['FBN-APP-025', 'Microsoft Defender for Endpoint', 'high'],
            ['FBN-APP-026', 'Microsoft Sentinel SIEM', 'high'],
            ['FBN-APP-027', 'Tenable.io Scanner', 'medium'],
            ['FBN-APP-028', 'Cloudflare WAF', 'high'],
            ['FBN-APP-029', 'CyberArk Privileged Access', 'high'],
            ['FBN-APP-030', 'Okta Workforce Identity', 'high'],
            ['FBN-APP-031', 'Azure AD (Entra) Cloud Identity', 'critical'],
            ['FBN-APP-032', 'Proofpoint Email Security', 'high'],
            ['FBN-APP-033', 'Legacy IBM AIX CRMS (EOL)', 'medium'],
            ['FBN-APP-034', 'Legacy SunGard Back-office', 'low'],
            ['FBN-APP-035', 'IslamicBanking Core (FBN Quest)', 'high'],
            ['FBN-APP-036', 'Microfinance Core (Rural)', 'medium'],
            ['FBN-APP-037', 'Treasury Front Office (Murex)', 'high'],
            ['FBN-APP-038', 'Treasury Back Office (Calypso)', 'high'],
            ['FBN-APP-039', 'Core Banking Reports Portal', 'medium'],
            ['FBN-APP-040', 'DPOPortal (NDPA DSAR workflow)', 'medium'],
            ['FBN-APP-041', 'ESB (MuleSoft)', 'high'],
            ['FBN-APP-042', 'API Gateway (Apigee)', 'high'],
            ['FBN-APP-043', 'GitLab Self-Managed', 'medium'],
            ['FBN-APP-044', 'Rancher (Kubernetes)', 'medium'],
            ['FBN-APP-045', 'Power BI Tenant', 'medium'],
            ['FBN-APP-046', 'Tableau Server', 'low'],
            ['FBN-APP-047', 'HR Self-Service Portal', 'low'],
            ['FBN-APP-048', 'Procurement Platform (Coupa)', 'low'],
            ['FBN-APP-049', 'Document Management (SharePoint)', 'medium'],
            ['FBN-APP-050', 'E-Learning Platform', 'low'],
        ];
        $weighted = function ($dist) {
            $r = mt_rand(1, array_sum($dist));
            $c = 0;
            foreach ($dist as $k => $w) {
                $c += $w;
                if ($r <= $c) {
                    return $k;
                }
            }

            return array_key_first($dist);
        };
        foreach ($seed as [$code, $name, $crit]) {
            EaApplication::create([
                'organization_id' => $tenantId,
                'code' => $code, 'name' => $name,
                'description' => $name.' — First Bank application portfolio entry.',
                'time_score' => $weighted($time),
                'business_fit' => rand(2, 5),
                'technical_fit' => rand(1, 5),
                'criticality' => $crit,
                'lifecycle' => $weighted($lifecycle),
                'annual_cost_ngn' => rand(20_000_000, 800_000_000),
                'user_count' => rand(20, 5000),
                'owner_role' => ['CTO', 'CIO', 'CISO', 'Head of Digital', 'Head of IT Ops'][rand(0, 4)],
                'capability_ids' => collect($capabilityIds)->random(rand(1, 3))->values()->toArray(),
            ]);
        }
    }

    private function seedTech(int $tenantId): void
    {
        if (TechComponent::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $list = [
            // Languages
            ['Java 17', 'Language', 'Oracle', '17 LTS', 'adopt'],
            ['Java 8', 'Language', 'Oracle', '8', 'hold'],
            ['Python 3.12', 'Language', 'PSF', '3.12', 'adopt'],
            ['Node.js 20', 'Language', 'OpenJS', '20 LTS', 'adopt'],
            ['PHP 8.3', 'Language', 'PHP', '8.3', 'adopt'],
            ['TypeScript', 'Language', 'Microsoft', '5.4', 'adopt'],
            ['COBOL', 'Language', 'Various', 'Legacy', 'hold'],
            // Frameworks
            ['Spring Boot', 'Framework', 'VMware', '3.2', 'adopt'],
            ['Laravel', 'Framework', 'Laravel', '11', 'adopt'],
            ['React', 'Framework', 'Meta', '18', 'adopt'],
            ['Angular', 'Framework', 'Google', '17', 'trial'],
            ['Vue', 'Framework', 'VueJS', '3', 'assess'],
            ['jQuery', 'Framework', 'OpenJS', '3.x', 'hold'],
            // DBs
            ['Oracle Exadata', 'Database', 'Oracle', '19c', 'adopt'],
            ['PostgreSQL', 'Database', 'PGDG', '16', 'adopt'],
            ['MySQL', 'Database', 'Oracle', '8', 'adopt'],
            ['MongoDB', 'Database', 'MongoDB', '7', 'trial'],
            ['Teradata', 'Database', 'Teradata', '17', 'hold'],
            ['Sybase', 'Database', 'SAP', '16', 'hold'],
            // Platforms
            ['Linux RHEL 9', 'OS', 'Red Hat', '9', 'adopt'],
            ['Linux RHEL 7', 'OS', 'Red Hat', '7', 'hold'],
            ['Windows Server 2022', 'OS', 'Microsoft', '2022', 'adopt'],
            ['Windows Server 2012', 'OS', 'Microsoft', '2012', 'hold'],
            ['IBM AIX 7.3', 'OS', 'IBM', '7.3', 'trial'],
            ['IBM AIX 7.1', 'OS', 'IBM', '7.1', 'hold'],
            // Cloud
            ['AWS EKS', 'Cloud', 'AWS', 'latest', 'adopt'],
            ['AWS RDS', 'Cloud', 'AWS', 'latest', 'adopt'],
            ['Azure AKS', 'Cloud', 'Microsoft', 'latest', 'trial'],
            ['GCP BigQuery', 'Cloud', 'Google', 'latest', 'assess'],
            // Middleware
            ['Kubernetes (Rancher)', 'Platform', 'SUSE', '1.29', 'adopt'],
            ['VMware vSphere', 'Platform', 'VMware', '8', 'adopt'],
            ['Kafka', 'Messaging', 'Confluent', '3.7', 'adopt'],
            ['RabbitMQ', 'Messaging', 'VMware', '3.12', 'adopt'],
            ['MuleSoft ESB', 'Integration', 'Salesforce', '4', 'adopt'],
            ['Apigee', 'API Gateway', 'Google', 'X', 'adopt'],
            // Security
            ['Palo Alto PA-7050', 'Firewall', 'Palo Alto', 'PAN-OS 11', 'adopt'],
            ['F5 BIG-IP', 'Load Balancer', 'F5', '17', 'adopt'],
            ['Cisco ASA', 'VPN', 'Cisco', '9.x', 'assess'],
            ['CyberArk PAM', 'PAM', 'CyberArk', '13', 'adopt'],
            ['Thales Luna HSM', 'HSM', 'Thales', '7', 'adopt'],
            // Dev / DevOps
            ['GitLab', 'DevOps', 'GitLab', '17', 'adopt'],
            ['Jenkins', 'DevOps', 'CloudBees', '2.4x', 'trial'],
            ['Terraform', 'IaC', 'HashiCorp', '1.8', 'adopt'],
            ['Docker', 'Container', 'Docker', '27', 'adopt'],
            // Monitoring
            ['Sentinel', 'SIEM', 'Microsoft', 'latest', 'adopt'],
            ['Splunk ES', 'SIEM', 'Splunk', '9', 'adopt'],
            ['Wazuh', 'SIEM', 'Wazuh', '4', 'trial'],
            ['New Relic', 'APM', 'New Relic', 'latest', 'trial'],
            // Obsolete
            ['Flash Player', 'Runtime', 'Adobe', 'dead', 'hold'],
            ['SHA-1', 'Cryptography', 'NIST', 'deprecated', 'hold'],
            ['TLS 1.0', 'Crypto', 'IETF', 'deprecated', 'hold'],
        ];
        $i = 0;
        foreach ($list as [$name, $cat, $vendor, $version, $status]) {
            $i++;
            $eolDate = match (true) {
                $status === 'hold' => now()->subYears(rand(0, 2))->toDateString(),
                $status === 'trial' => now()->addMonths(rand(12, 24))->toDateString(),
                default => now()->addYears(rand(2, 5))->toDateString(),
            };
            $inNext12 = Carbon::parse($eolDate)->between(now(), now()->addYear());
            TechComponent::create([
                'organization_id' => $tenantId,
                'code' => 'FBN-TECH-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => $name, 'category' => $cat, 'vendor' => $vendor,
                'version' => $version, 'radar_status' => $status,
                'eol_date' => $eolDate,
                'eos_date' => now()->parse($eolDate)->addYears(1)->toDateString(),
                'obsolescence_flag' => $inNext12 || $status === 'hold',
            ]);
        }
    }

    private function seedStandards(int $tenantId): void
    {
        if (Standard::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $list = [
            ['STD-001', 'API Security — OAuth2 + mTLS', 'Security'],
            ['STD-002', 'TLS 1.3 minimum for external endpoints', 'Security'],
            ['STD-003', 'RESTful API design guidelines', 'Integration'],
            ['STD-004', 'Event-driven integration (Kafka)', 'Integration'],
            ['STD-005', 'Container-first deployment', 'Platform'],
            ['STD-006', '12-factor apps', 'Platform'],
            ['STD-007', 'Zero-trust network architecture', 'Security'],
            ['STD-008', 'Data classification schema', 'Data'],
            ['STD-009', 'Logging & observability (OTel)', 'Operations'],
            ['STD-010', 'SRE service-level objectives', 'Operations'],
            ['STD-011', 'Secure SDLC w/ SAST + SCA', 'Security'],
            ['STD-012', 'RTO / RPO classification', 'BCP'],
            ['STD-013', 'Data masking in non-prod', 'Data'],
            ['STD-014', 'Language policy (Java 17+, Python 3.11+)', 'Platform'],
            ['STD-015', 'Database encryption-at-rest', 'Security'],
        ];
        foreach ($list as [$code, $name, $cat]) {
            Standard::create([
                'organization_id' => $tenantId, 'code' => $code, 'name' => $name, 'category' => $cat,
                'description' => $name.' — enforced across the portfolio.',
                'radar_status' => 'adopt', 'status' => 'active',
            ]);
        }
    }

    private function seedInfoDomains(int $tenantId): void
    {
        if (InfoDomain::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $domains = [
            ['DOM-CUST', 'Customer', 'Chief Customer Officer', 'Personal'],
            ['DOM-ACCT', 'Account', 'Head of Operations', 'Confidential'],
            ['DOM-TXN', 'Transaction', 'Head of Payments', 'Confidential'],
            ['DOM-PRD', 'Product', 'Head of Product', 'Internal'],
            ['DOM-CRED', 'Credit & Loans', 'CRO', 'Confidential'],
            ['DOM-EMP', 'Employee', 'CHRO', 'Personal'],
            ['DOM-VEND', 'Vendor', 'Vendor Risk Manager', 'Confidential'],
            ['DOM-REG', 'Regulatory', 'Head of Compliance', 'Internal'],
            ['DOM-RSK', 'Risk', 'CRO', 'Confidential'],
            ['DOM-SEC', 'Security & IAM', 'CISO', 'Sensitive'],
            ['DOM-FIN', 'Financial Reporting', 'CFO', 'Confidential'],
            ['DOM-PUB', 'Public Marketing', 'CMO', 'Public'],
        ];
        foreach ($domains as [$code, $name, $owner, $cls]) {
            InfoDomain::create([
                'organization_id' => $tenantId, 'code' => $code, 'name' => $name,
                'description' => $name.' domain — FBN information architecture.',
                'owner_role' => $owner, 'classification' => $cls,
            ]);
        }
    }

    private function seedLogicalEntities(int $tenantId): void
    {
        if (LogicalEntity::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $domains = InfoDomain::where('organization_id', $tenantId)->pluck('id', 'code')->toArray();
        $entities = [
            ['Customer', 'DOM-CUST', 'Personal', true, ['customer_id', 'name', 'dob', 'bvn', 'nin', 'address', 'phone', 'email']],
            ['Account', 'DOM-ACCT', 'Confidential', false, ['account_id', 'customer_id', 'balance', 'status', 'opened_at']],
            ['Transaction', 'DOM-TXN', 'Confidential', false, ['txn_id', 'account_id', 'amount', 'type', 'timestamp']],
            ['Loan Application', 'DOM-CRED', 'Confidential', true, ['loan_id', 'customer_id', 'amount', 'purpose', 'decision']],
            ['Card', 'DOM-ACCT', 'Confidential', true, ['card_id', 'pan', 'cvv', 'customer_id', 'expiry']],
            ['BVN Record', 'DOM-CUST', 'Sensitive', true, ['bvn', 'customer_id', 'linked_accounts']],
            ['NIN Record', 'DOM-CUST', 'Sensitive', true, ['nin', 'customer_id', 'name']],
            ['Employee', 'DOM-EMP', 'Personal', true, ['employee_id', 'name', 'grade', 'salary', 'manager']],
            ['Vendor', 'DOM-VEND', 'Internal', false, ['vendor_id', 'name', 'contract_ref', 'services']],
            ['Policy', 'DOM-REG', 'Internal', false, ['policy_id', 'title', 'version', 'owner']],
            ['Risk', 'DOM-RSK', 'Confidential', false, ['risk_id', 'category', 'score', 'owner']],
            ['Incident', 'DOM-RSK', 'Confidential', false, ['incident_id', 'type', 'severity', 'detected_at']],
            ['Audit Log', 'DOM-SEC', 'Sensitive', false, ['log_id', 'actor', 'action', 'entity', 'ts']],
            ['GL Posting', 'DOM-FIN', 'Confidential', false, ['gl_id', 'account', 'debit', 'credit', 'period']],
            ['Regulator Return', 'DOM-REG', 'Internal', false, ['return_id', 'regulator', 'period', 'status']],
            ['Product Catalogue', 'DOM-PRD', 'Internal', false, ['product_id', 'name', 'type', 'status']],
            ['Marketing Campaign', 'DOM-PUB', 'Public', false, ['campaign_id', 'name', 'budget']],
            ['Marketing Lead', 'DOM-CUST', 'Personal', true, ['lead_id', 'email', 'phone', 'campaign_id']],
            ['SWIFT Message', 'DOM-TXN', 'Confidential', false, ['msg_id', 'mt', 'amount', 'counterparty']],
            ['Card Transaction', 'DOM-TXN', 'Confidential', true, ['ctxn_id', 'pan', 'amount', 'merchant']],
            ['Mobile Session', 'DOM-CUST', 'Personal', true, ['session_id', 'customer_id', 'device_id']],
            ['USSD Session', 'DOM-CUST', 'Personal', true, ['session_id', 'msisdn', 'customer_id']],
            ['Agent', 'DOM-VEND', 'Confidential', false, ['agent_id', 'name', 'location']],
            ['Branch', 'DOM-ACCT', 'Internal', false, ['branch_id', 'name', 'sort_code']],
            ['SoD Rule', 'DOM-SEC', 'Sensitive', false, ['rule_id', 'role', 'forbidden_role']],
            ['Access Entitlement', 'DOM-SEC', 'Sensitive', false, ['ent_id', 'user', 'role', 'granted_at']],
            ['Vulnerability', 'DOM-RSK', 'Confidential', false, ['cve', 'asset', 'severity', 'status']],
            ['NDPA DSAR', 'DOM-CUST', 'Personal', true, ['dsar_id', 'customer_id', 'type', 'status']],
            ['Data Breach', 'DOM-SEC', 'Sensitive', false, ['breach_id', 'records', 'status', 'notified_at']],
            ['KPI Reading', 'DOM-FIN', 'Internal', false, ['kpi_id', 'value', 'period']],
            ['Fraud Case', 'DOM-RSK', 'Confidential', true, ['case_id', 'amount', 'status', 'investigator']],
            ['Support Ticket', 'DOM-CUST', 'Personal', true, ['ticket_id', 'customer_id', 'category']],
            ['Marketing Preference', 'DOM-CUST', 'Personal', true, ['customer_id', 'channel', 'opt_in']],
            ['Treasury Trade', 'DOM-FIN', 'Confidential', false, ['trade_id', 'instrument', 'amount', 'counterparty']],
            ['Risk Appetite Statement', 'DOM-RSK', 'Internal', false, ['ras_id', 'limit', 'owner']],
            ['Audit Finding', 'DOM-RSK', 'Confidential', false, ['finding_id', 'severity', 'status']],
            ['Insurance Policy', 'DOM-FIN', 'Internal', false, ['policy_id', 'limit', 'provider']],
            ['Credit Bureau Hit', 'DOM-CRED', 'Sensitive', true, ['hit_id', 'bureau', 'score', 'customer_id']],
            ['Know-Your-Employee Check', 'DOM-EMP', 'Sensitive', true, ['kye_id', 'employee_id', 'result']],
            ['Procurement Order', 'DOM-VEND', 'Internal', false, ['po_id', 'vendor_id', 'amount', 'status']],
        ];
        foreach ($entities as $i => [$name, $domCode, $cls, $pii, $attrs]) {
            LogicalEntity::create([
                'organization_id' => $tenantId,
                'domain_id' => $domains[$domCode] ?? null,
                'code' => 'ENT-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'description' => $name.' logical entity.',
                'classification' => $cls, 'pii_flag' => $pii,
                'attributes' => $attrs,
            ]);
        }
    }

    private function seedDataFlows(int $tenantId): void
    {
        if (DataFlow::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $ents = LogicalEntity::where('organization_id', $tenantId)->get();
        if ($ents->count() < 5) {
            return;
        }
        $flows = [
            ['Customer → Account', 'Customer', 'Account', 'Internal', false, 'REST'],
            ['Account → Transaction', 'Account', 'Transaction', 'Confidential', false, 'KAFKA'],
            ['Customer → Loan Application', 'Customer', 'Loan Application', 'Confidential', false, 'REST'],
            ['Card → Card Transaction', 'Card', 'Card Transaction', 'Confidential', false, 'ISO8583'],
            ['BVN Record → NIBSS BVN API', 'BVN Record', 'Customer', 'Sensitive', true, 'REST'],
            ['Customer → Marketing Preference', 'Customer', 'Marketing Preference', 'Personal', false, 'REST'],
            ['Treasury Trade → SWIFT Message', 'Treasury Trade', 'SWIFT Message', 'Confidential', true, 'MQ'],
            ['Access Entitlement → Audit Log', 'Access Entitlement', 'Audit Log', 'Sensitive', false, 'KAFKA'],
            ['NDPA DSAR → Customer', 'NDPA DSAR', 'Customer', 'Personal', false, 'REST'],
            ['Credit Bureau Hit → Loan Application', 'Credit Bureau Hit', 'Loan Application', 'Sensitive', true, 'REST'],
        ];
        foreach ($flows as [$name, $srcName, $tgtName, $cls, $crossBorder, $protocol]) {
            $src = $ents->firstWhere('name', $srcName);
            $tgt = $ents->firstWhere('name', $tgtName);
            if (! $src || ! $tgt) {
                continue;
            }
            DataFlow::create([
                'organization_id' => $tenantId,
                'source_entity_id' => $src->id,
                'target_entity_id' => $tgt->id,
                'name' => $name,
                'cross_border' => $crossBorder,
                'protocol' => $protocol,
                'classification' => $cls,
            ]);
        }
    }

    private function seedMaturity(int $tenantId): void
    {
        // Seed CBN EA Framework domains (idempotent)
        if (MaturityDomain::count() === 0) {
            $d = [
                ['EA-GOV', 'EA Governance', 'Executive sponsorship, ARB, principles & standards.'],
                ['EA-STR', 'Strategy & Business Architecture', 'Business strategy linkage, capability model, value streams.'],
                ['EA-APP', 'Application Architecture', 'Portfolio visibility, TIME scoring, rationalisation.'],
                ['EA-TEC', 'Technology Architecture', 'Standards catalogue, radar, lifecycle management.'],
                ['EA-DAT', 'Data Architecture & Privacy', 'Data classification, flows, NDPA alignment.'],
                ['EA-SEC', 'Security Architecture', 'Zones, control mapping, privileged access.'],
                ['EA-INT', 'Integration Architecture', 'Interface catalogue, APIs, event patterns.'],
                ['EA-ROA', 'Roadmap & Transformation', 'Plateaux, initiatives, ADM phase tracking.'],
                ['EA-CBN', 'CBN-Specific Obligations', 'RBCSF alignment, return automation, evidence trail.'],
                ['EA-MAT', 'Continuous Improvement', 'Metrics, KRIs, feedback loop.'],
            ];
            foreach ($d as $i => [$code, $name, $desc]) {
                $dom = MaturityDomain::create([
                    'code' => $code, 'name' => $name, 'description' => $desc, 'weight' => 10,
                ]);
                // 4 questions per domain
                for ($q = 1; $q <= 4; $q++) {
                    MaturityQuestion::create([
                        'domain_id' => $dom->id,
                        'code' => $code.'-Q'.$q,
                        'question' => "How mature is {$name} — Q{$q}?",
                        'guidance' => 'Level 1: Ad-hoc · Level 2: Initial · Level 3: Defined · Level 4: Managed · Level 5: Optimised.',
                    ]);
                }
            }
        }

        if (MaturityAssessment::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $creator = User::where('organization_id', $tenantId)->first();
        $assessment = MaturityAssessment::create([
            'organization_id' => $tenantId,
            'title' => 'CBN EA Maturity Self-Assessment '.now()->format('Y'),
            'year' => (int) now()->format('Y'),
            'status' => 'in_progress',
            'created_by' => $creator?->id,
        ]);
        $questions = MaturityQuestion::all();
        $sum = 0;
        foreach ($questions as $q) {
            $level = rand(2, 4); // centre around "Defined"
            $sum += $level;
            MaturityResponse::create([
                'assessment_id' => $assessment->id,
                'question_id' => $q->id,
                'selected_level' => $level,
                'comment' => rand(0, 1) ? 'Evidence on file; verified with Internal Audit.' : null,
                'answered_by' => $creator?->id,
            ]);
        }
        $assessment->update(['overall_score' => round($sum / max($questions->count(), 1), 2)]);
    }
}
