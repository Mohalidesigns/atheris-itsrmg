<?php

namespace Database\Seeders;

use App\Models\AssetSyncJob;
use App\Models\AucsControl;
use App\Models\AucsFrameworkMapping;
use App\Models\AuditEvent;
use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\BusinessCapability;
use App\Models\BusinessProcess;
use App\Models\BusinessService;
use App\Models\CcmTenantTest;
use App\Models\CcmTest;
use App\Models\CcmTestRun;
use App\Models\ContentInstall;
use App\Models\CopilotConversation;
use App\Models\CopilotMessage;
use App\Models\CoreBankingIntegration;
use App\Models\CoreBankingSnapshot;
use App\Models\CustomField;
use App\Models\DocIntelligenceJob;
use App\Models\DrExercise;
use App\Models\DrRunbook;
use App\Models\EvidenceVaultItem;
use App\Models\FairRun;
use App\Models\FairScenario;
use App\Models\FeatureFlag;
use App\Models\FrameworkClause;
use App\Models\IncidentNotification;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\Kri;
use App\Models\KriBreach;
use App\Models\KriReading;
use App\Models\MarketplaceItem;
use App\Models\NotificationTemplate;
use App\Models\Obligation;
use App\Models\Organization;
use App\Models\PricingTier;
use App\Models\RegulatoryCircular;
use App\Models\RegulatoryReference;
use App\Models\ReturnRun;
use App\Models\ReturnTemplate;
use App\Models\ScimToken;
use App\Models\ServiceDependency;
use App\Models\SharedVendorDirectory;
use App\Models\SiemIntegration;
use App\Models\SiemSignal;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\TenantTheme;
use App\Models\ThreatAdvisory;
use App\Models\TprmBreachEvent;
use App\Models\TprmSecurityRating;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTask;
use App\Services\FairMonteCarloService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtherisPlatformSeeder extends Seeder
{
    /**
     * The demo tenant the platform layer belongs to.
     *
     * This used to be a hard-coded `1` — the "Acme Nigeria Ltd" organisation
     * created by DatabaseSeeder. That made SSO, SCIM, the public API, CCM,
     * KRIs, the evidence vault, board packs, Copilot, SIEM, FAIR, doc-intel,
     * workflows, core banking, DR, the marketplace, theming and the obligations
     * register invisible from either bank demo login: tenancy scoping shows a
     * session only its own organisation's rows, and nobody demos as Acme.
     * Whole modules read as empty product.
     *
     * They now belong to Kano Heritage Bank — the Tier-2 demo tenant a reviewer
     * actually signs in as.
     */
    private int $orgId;

    public function run(): void
    {
        $this->orgId = $this->resolveTenant();
        $this->tenants();
        $this->featureFlags();
        $this->regulatoryReferences();
        $this->aucs();
        $this->businessServices();
        $this->issues();
        $this->ccm();
        $this->evidenceVault();
        $this->kri();
        $this->boardPacks();
        $this->pricing();
        $this->obligations();
        $this->circulars();
        $this->copilot();
        $this->tprm();
        $this->siem();
        $this->notifications();
        $this->fair();
        $this->advisories();
        $this->docIntel();
        $this->returns();
        $this->workflows();
        $this->coreBanking();
        $this->dr();
        $this->marketplace();
        $this->identity();
        $this->theming();
        $this->auditSamples();
        $this->assetSync();
    }

    /**
     * Resolve — and if necessary create — the Kano Heritage organisation.
     *
     * This seeder runs *before* KanoHeritageDemoSeeder and cannot be reordered:
     * that seeder reads the AUCS control catalogue this one builds. So the
     * organisation is created here when it does not exist yet, keyed on the
     * same slug KanoHeritageDemoSeeder keys on, which means that seeder updates
     * this row with the full attribute set rather than creating a second bank.
     */
    private function resolveTenant(): int
    {
        return Organization::firstOrCreate(
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
        )->id;
    }

    /**
     * A user belonging to the demo tenant, for the records that need an actor.
     *
     * Kano's own users are seeded later, so this creates the CISO account on
     * the same email KanoHeritageDemoSeeder keys on — it fills in the name,
     * department and role when it runs. Binding these records to an Acme user
     * instead would leave a Copilot conversation owned by somebody outside the
     * organisation that owns it.
     */
    private function tenantUser(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@kanoheritage.ng'],
            [
                'name' => 'Adaeze Kunle-Usman',
                'password' => bcrypt('password'),
                'organization_id' => $this->orgId,
                'job_title' => 'CISO',
                'department' => 'Information Security',
                'email_verified_at' => now(),
            ]
        );
    }

    /** @return array<int,int> ids of users in the demo tenant. */
    private function tenantUserIds(): array
    {
        $ids = User::where('organization_id', $this->orgId)->pluck('id')->all();

        return $ids ?: [$this->tenantUser()->id];
    }

    private function tenants(): void
    {
        if (Tenant::count() === 0) {
            Tenant::create(['id' => 1, 'slug' => 'acme-nigeria', 'name' => 'Acme Nigeria Ltd', 'country' => 'NG', 'regulator_primary' => 'CBN', 'licence_type' => 'DMB', 'hosting_mode' => 'saas-dedicated', 'status' => 'active', 'tier' => 'professional']);
            Tenant::create(['id' => 2, 'slug' => 'sterling-bank-demo', 'name' => 'Sterling Bank (Demo)', 'country' => 'NG', 'regulator_primary' => 'CBN', 'licence_type' => 'DMB', 'hosting_mode' => 'saas-shared', 'status' => 'active', 'tier' => 'essentials']);
            Tenant::create(['id' => 3, 'slug' => 'kuda-mfb-demo', 'name' => 'Kuda MFB (Demo)', 'country' => 'NG', 'regulator_primary' => 'CBN', 'licence_type' => 'MFB', 'hosting_mode' => 'on-prem', 'status' => 'active', 'tier' => 'enterprise']);
        }
    }

    private function featureFlags(): void
    {
        $keys = [
            'copilot.enabled' => true, 'copilot.ollama_fallback' => true,
            'regintel.crawlers' => true, 'regintel.auto_publish' => false,
            'ccm.starter_pack' => true, 'ccm.auto_issue' => true,
            'kri.pack.nigerian' => true, 'kri.auto_breach_alerts' => true,
            'board_packs.navy_variant' => true,
            'tprm.securityscorecard' => true, 'tprm.bitsight' => true,
            'fair.naira' => true, 'fair.ndpc_fine_bands' => true,
            'siem.sentinel' => true, 'siem.splunk_es' => true, 'siem.qradar' => false, 'siem.wazuh' => true,
            'notifications.cbn_24h' => true, 'notifications.ndpc_72h' => true, 'notifications.nfiu_sar' => true,
            'doc_intel.circulars' => true, 'doc_intel.vendor_ddq' => true,
            'returns.cbn_crms' => true, 'returns.ndpc_audit' => true,
            'workflows.marketplace' => true,
            'core_banking.finacle' => true, 'core_banking.flexcube' => true, 'core_banking.t24' => false, 'core_banking.bankone' => true,
            'dr.runbooks' => true,
            'marketplace.public' => true,
            'i18n.french' => false, 'i18n.portuguese' => false, 'i18n.arabic' => false, 'i18n.swahili' => false,
            'theming.per_tenant' => true,
        ];
        foreach ($keys as $k => $enabled) {
            FeatureFlag::updateOrCreate(['key' => $k], ['organization_id' => null, 'enabled' => $enabled, 'rollout_percent' => $enabled ? 100 : 0]);
        }
    }

    private function regulatoryReferences(): void
    {
        $refs = [
            ['CBN', 'RBCSF.3.9.3', 'Cybersecurity Self-Assessment', '1.0', '2021-07-01'],
            ['CBN', 'RBCSF.2.4', 'Multi-factor Authentication', '1.0', '2021-07-01'],
            ['CBN', 'IT-STD.4.2', 'IT Standards — Incident Reporting', '1.0', '2020-01-01'],
            ['NDPC', 'NDPA.39', 'Data breach notification (72-hour)', '2023', '2023-06-14'],
            ['NDPC', 'NDPA.41', 'Records of processing', '2023', '2023-06-14'],
            ['NDIC', 'OUTSOURCING.5.1', 'Outsourcing notification', '2020', '2020-05-10'],
            ['NAICOM', 'ERM.3', 'Enterprise Risk Management for insurers', '2021', '2021-03-15'],
            ['ISO', '27001.A.5', 'Information security policies', '2022', '2022-10-25'],
            ['ISO', '27001.A.8.8', 'Management of technical vulnerabilities', '2022', '2022-10-25'],
            ['NIST', 'CSF.GV.OV', 'Organizational context', '2.0', '2024-02-26'],
            ['PCI', 'DSS.4.0.1.R3', 'Vulnerability management', '4.0.1', '2024-06-01'],
        ];
        foreach ($refs as [$reg, $code, $title, $ver, $date]) {
            RegulatoryReference::updateOrCreate(
                ['regulator_code' => $reg, 'reference_code' => $code],
                ['title' => $title, 'version' => $ver, 'effective_date' => $date, 'clause_path' => $code, 'body_markdown' => "# {$title}\n\nAuthoritative text for {$reg} {$code}."]
            );
        }
    }

    private function aucs(): void
    {
        if (AucsControl::count() > 0) {
            return;
        }
        $domains = [
            'GOV' => 'Governance', 'IAM' => 'Identity & Access', 'DPR' => 'Data Protection',
            'CRY' => 'Cryptography', 'VUL' => 'Vulnerability Management', 'LOG' => 'Logging & Monitoring',
            'INC' => 'Incident Response', 'BCP' => 'Business Continuity', 'TPR' => 'Third-Party Risk',
            'SDL' => 'Secure Development', 'NET' => 'Network Security', 'END' => 'Endpoint Security',
            'AWR' => 'Awareness & Training', 'PHY' => 'Physical Security', 'PRI' => 'Privacy',
            'REG' => 'Regulatory & Compliance', 'OPS' => 'Operations & Change', 'RES' => 'Resilience',
        ];
        $controls = [];
        foreach ($domains as $dkey => $domain) {
            for ($i = 1; $i <= 22; $i++) { // ~396 controls total
                $code = sprintf('AUCS-%s-%02d', $dkey, $i);
                $title = "{$domain} control {$i}";
                $controls[] = AucsControl::create([
                    'code' => $code,
                    'title' => $title,
                    'description' => "Atheris canonical {$domain} control #{$i}. Maps across ISO 27001, NIST CSF 2.0, CBN RBCSF, NDPA, PCI-DSS 4.0.1.",
                    'domain' => $domain,
                    'sub_domain' => $domain,
                    'objective_text' => "Ensure the tenant implements {$domain} control #{$i} to design-effective and operating-effective standards.",
                    'version' => '1.0',
                ]);
            }
        }

        $frameworks = [
            ['ISO-27001', '2022', ['A.5.1', 'A.5.25', 'A.8.8', 'A.8.1', 'A.9.2', 'A.12.1', 'A.16.1', 'A.18.1']],
            ['NIST-CSF', '2.0', ['GV.OV-01', 'ID.AM-01', 'PR.AA-01', 'PR.DS-01', 'DE.CM-01', 'RS.CO-01', 'RC.RP-01']],
            ['CBN-RBCSF', '2021', ['§2.1', '§2.4', '§3.1', '§3.9.3', '§4.2', '§5.1', '§6.3']],
            ['NDPA', '2023', ['§24', '§25', '§26', '§39', '§41']],
            ['PCI-DSS', '4.0.1', ['R1.1', 'R2.2', 'R3.1', 'R6.2', 'R8.3', 'R10.1', 'R11.4', 'R12.1']],
            ['CBN-IT-STD', '2020', ['4.1', '4.2', '4.3', '5.1', '6.2']],
            ['NDIC-OUTSRC', '2020', ['5.1', '5.2', '6.1']],
            ['NAICOM-ERM', '2021', ['3.1', '3.2', '4.1']],
        ];
        $clauses = [];
        foreach ($frameworks as [$code, $ver, $paths]) {
            foreach ($paths as $p) {
                $clauses[] = FrameworkClause::create([
                    'framework_code' => $code, 'version' => $ver, 'clause_path' => $p,
                    'title' => "{$code} {$p}",
                    'body_markdown' => "Clause {$p} of {$code} {$ver}.",
                    'effective_date' => $ver === '2022' ? '2022-10-25' : null,
                ]);
            }
        }

        // Map each control to 1-3 clauses
        foreach ($controls as $c) {
            $n = rand(1, 3);
            $picks = collect($clauses)->random($n);
            foreach ($picks as $cl) {
                AucsFrameworkMapping::create([
                    'aucs_control_id' => $c->id,
                    'framework_clause_id' => $cl->id,
                    'mapping_strength' => ['full', 'partial', 'related'][rand(0, 2)],
                ]);
            }
        }
    }

    private function businessServices(): void
    {
        if (BusinessService::count() > 0) {
            return;
        }
        $caps = ['Channels', 'Core Banking', 'Payments', 'Customer Lifecycle', 'Treasury', 'Risk & Compliance'];
        $capIds = [];
        foreach ($caps as $name) {
            $capIds[$name] = BusinessCapability::create(['organization_id' => $this->orgId, 'name' => $name, 'description' => "{$name} capability"])->id;
        }
        $svcMap = [
            ['Channels', 'USSD Banking', 'critical', 15, 5],
            ['Channels', 'Mobile Banking', 'critical', 15, 5],
            ['Channels', 'Internet Banking', 'critical', 30, 10],
            ['Channels', 'Agent Banking', 'high', 30, 15],
            ['Channels', 'ATM Network', 'critical', 15, 5],
            ['Channels', 'PoS Terminal Network', 'high', 30, 10],
            ['Channels', 'WhatsApp Banking', 'medium', 60, 30],
            ['Core Banking', 'Finacle Core', 'critical', 30, 10],
            ['Core Banking', 'Flexcube Core', 'critical', 30, 10],
            ['Payments', 'NIBSS NIP', 'critical', 15, 5],
            ['Payments', 'Interswitch Switch', 'critical', 15, 5],
            ['Payments', 'Card Issuance', 'high', 60, 30],
            ['Customer Lifecycle', 'KYC & Onboarding', 'high', 240, 60],
            ['Customer Lifecycle', 'AML Screening', 'high', 120, 60],
            ['Customer Lifecycle', 'Loan Origination', 'medium', 480, 120],
            ['Treasury', 'FX Operations', 'high', 120, 60],
        ];
        $svcIds = [];
        foreach ($svcMap as [$cap, $name, $crit, $rto, $rpo]) {
            $svcIds[] = BusinessService::create([
                'organization_id' => $this->orgId, 'name' => $name, 'capability_id' => $capIds[$cap],
                'criticality' => $crit, 'recovery_time_objective_min' => $rto, 'recovery_point_objective_min' => $rpo,
                'description' => "{$name} serving Nigerian banking customers.",
            ])->id;
        }
        // Processes
        foreach ($svcIds as $sid) {
            for ($p = 1; $p <= 2; $p++) {
                BusinessProcess::create([
                    'organization_id' => $this->orgId, 'service_id' => $sid,
                    'name' => 'Process '.$p.' for service '.$sid,
                    'criticality' => ['critical', 'high', 'medium'][rand(0, 2)],
                ]);
            }
        }
        // Dependencies
        for ($i = 0; $i < 20; $i++) {
            $src = $svcIds[array_rand($svcIds)];
            $tgt = $svcIds[array_rand($svcIds)];
            if ($src !== $tgt) {
                ServiceDependency::firstOrCreate([
                    'source_type' => 'service', 'source_id' => $src,
                    'target_type' => 'service', 'target_id' => $tgt,
                ], ['organization_id' => $this->orgId, 'relation_type' => 'depends_on']);
            }
        }
    }

    private function issues(): void
    {
        if (Issue::count() > 5) {
            return;
        }
        $sources = ['audit', 'control_test', 'risk', 'incident', 'vulnerability', 'assessment'];
        $severities = ['critical', 'high', 'moderate', 'low'];
        $statuses = ['open', 'in_progress', 'blocked', 'remediated', 'verified', 'closed'];
        $titles = [
            'Privileged AD accounts missing MFA',
            'EOL AIX hosts identified on core network',
            'NIBSS NIP reconciliation SLA breached',
            'Encryption-at-rest not enabled on audit DB',
            'Outstanding critical CVE on perimeter firewall',
            'Patch cycle exceeded 30 days for Windows servers',
            'Vendor DDQ refresh overdue for Interswitch',
            'Backup integrity test failure — Finacle weekly backup',
            'Anomalous admin login from 41.x.x.x',
            'Policy exception expired without renewal',
            'Overdue ATM firmware patch',
            'Third-party risk rating degraded below threshold',
            'Unauthorised cloud S3 bucket set to public',
            'USSD fraud indicator spike (BEC campaign)',
            'NDPA Article 39 documentation gap identified',
        ];
        $users = $this->tenantUserIds();
        foreach ($titles as $t) {
            // ATH-EAR-002 WS 2.4 — no rand() into foreign keys. §7.1: "the
            // application layer must enforce referential integrity, and
            // seeders must stop writing random IDs."
            $sourceType = $sources[array_rand($sources)];
            $issue = Issue::create([
                'organization_id' => $this->orgId,
                'source_type' => $sourceType,
                'source_id' => $this->realIdFor($sourceType),
                'title' => $t,
                'description' => $t.'. Demo remediation entry.',
                'severity' => $severities[array_rand($severities)],
                'owner_id' => $users[array_rand($users)] ?? null,
                'due_date' => now()->addDays(rand(-5, 30))->toDateString(),
                'sla_minutes' => rand(60, 43200),
                'status' => $statuses[array_rand($statuses)],
                'root_cause' => 'Configuration drift (demo)',
                'remediation_plan' => 'Apply control, re-test, document evidence.',
            ]);
            IssueEvent::create([
                'issue_id' => $issue->id,
                'event_type' => 'created',
                'payload' => ['actor' => 'system', 'note' => 'Demo seed'],
                'created_at' => now(),
            ]);
        }

        // vulnerability SLA policies
        $sev = [['critical', 72], ['high', 336], ['medium', 720], ['low', 2160]];
        foreach ($sev as [$s, $h]) {
            DB::table('vulnerability_sla_policies')->updateOrInsert(
                ['organization_id' => $this->orgId, 'severity' => $s],
                ['hours_to_remediate' => $h, 'escalation_rules' => json_encode(['chain' => ['owner', 'CISO', 'CRO']]), 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    private function ccm(): void
    {
        if (CcmTest::count() > 0) {
            return;
        }
        $tests = [
            ['CCM-IAM-MFA-001', 'MFA coverage on privileged AD accounts', 'ad', ['CBN-RBCSF §2.4', 'ISO 27001 A.9']],
            ['CCM-IAM-STALE-002', 'Stale accounts (>90 days)', 'ad', ['CBN-RBCSF §2.4']],
            ['CCM-IAM-PRIV-003', 'Privileged account count drift', 'ad', ['CBN-RBCSF §2.4']],
            ['CCM-CRY-TLS-004', 'TLS 1.3 on external endpoints', 'self', ['PCI-DSS R4.1']],
            ['CCM-CRY-ENC-005', 'Encryption-at-rest on core databases', 'aws', ['NDPA §39', 'PCI-DSS R3.5']],
            ['CCM-NET-S3-006', 'S3 buckets with public access', 'aws', ['PCI-DSS R1.1']],
            ['CCM-NET-FW-007', 'Firewall rules review frequency', 'self', ['CBN IT-STD 4.2']],
            ['CCM-VUL-PATCH-008', 'Windows patch compliance (30d)', 'defender', ['CBN-RBCSF §3.9']],
            ['CCM-VUL-PATCH-009', 'Linux patch compliance (30d)', 'tenable', ['CBN-RBCSF §3.9']],
            ['CCM-VUL-CVE-010', 'Critical CVEs outstanding', 'tenable', ['ngCERT advisories']],
            ['CCM-LOG-RET-011', 'Audit log retention ≥ 7 years', 'self', ['NDPA §41']],
            ['CCM-LOG-CEN-012', 'Central log forwarding enabled', 'self', ['NIST PR.PT']],
            ['CCM-INC-MTTD-013', 'Mean time to detect < 24h', 'self', ['CBN 24h advisory']],
            ['CCM-INC-MTTR-014', 'Mean time to resolve < 72h', 'self', ['CBN ITSM']],
            ['CCM-BCP-RTO-015', 'NIP failover RTO validated quarterly', 'self', ['CBN BCM']],
            ['CCM-BCP-BACKUP-016', 'Backups tested monthly', 'self', ['CBN IT-STD']],
            ['CCM-TPR-DDQ-017', 'Vendor DDQ refresh cadence', 'self', ['CBN Outsourcing']],
            ['CCM-TPR-RATE-018', 'Vendor security rating below threshold', 'securityscorecard', ['SCF TPRM']],
            ['CCM-PRI-DSAR-019', 'NDPC DSAR SLA (30 days)', 'self', ['NDPA §35']],
            ['CCM-PRI-DPO-020', 'DPCO appointed and logged', 'self', ['NDPA §32']],
            ['CCM-GOV-POL-021', 'Policies reviewed within 12 months', 'self', ['ISO 27001 A.5']],
            ['CCM-GOV-ATT-022', 'Policy attestation coverage >95%', 'self', ['ISO 27001 A.6']],
            ['CCM-SDL-SAST-023', 'SAST scan on every merge', 'self', ['OWASP SAMM']],
            ['CCM-SDL-SCA-024', 'SCA scan on every release', 'self', ['PCI-DSS R6.3']],
            ['CCM-OPS-CHG-025', 'Change request approval SLA', 'self', ['ISO A.12.1']],
            ['CCM-OPS-CFG-026', 'Configuration baseline drift', 'aws', ['CBN IT-STD']],
            ['CCM-DPR-DLP-027', 'DLP rule coverage on email', 'self', ['NDPA §26']],
            ['CCM-DPR-MASK-028', 'PII masking in non-prod', 'self', ['NDPA §26']],
            ['CCM-END-AV-029', 'AV agent coverage', 'defender', ['CBN-RBCSF §4.1']],
            ['CCM-END-EDR-030', 'EDR agent health', 'defender', ['CBN-RBCSF §4.1']],
            ['CCM-AWR-TRAIN-031', 'Mandatory awareness training coverage', 'self', ['ISO A.6.3']],
            ['CCM-AWR-PHISH-032', 'Phishing simulation click-rate', 'self', ['NIST PR.AT']],
            ['CCM-PHY-ACC-033', 'Data centre access review frequency', 'self', ['ISO A.7.1']],
            ['CCM-PHY-CCTV-034', 'CCTV coverage for critical rooms', 'self', ['ISO A.7.4']],
            ['CCM-REG-MAP-035', 'AUCS ↔ Obligation mapping completeness', 'self', ['Atheris internal']],
            ['CCM-REG-CIR-036', 'CBN circular action-items closed within 30d', 'self', ['CBN circular circ.']],
            ['CCM-REG-RET-037', 'CBN CRMS IT return submission on-time', 'self', ['CBN CRMS']],
            ['CCM-RES-DR-038', 'Quarterly DR exercise completed', 'self', ['CBN BCM']],
            ['CCM-RES-BCP-039', 'BIA refresh within 12 months', 'self', ['CBN BCM']],
            ['CCM-RES-COMM-040', 'Crisis comms runbook tested', 'self', ['CBN ITSM']],
        ];
        foreach ($tests as [$code, $title, $adapter, $refs]) {
            $t = CcmTest::create([
                'code' => $code, 'title' => $title,
                'description' => $title.' (Atheris CCM starter pack).',
                'framework_refs' => $refs, 'adapter_key' => $adapter,
                'default_severity' => 'high', 'parameters' => ['threshold' => 'default'],
                'active' => true,
            ]);
            $status = ['pass', 'pass', 'pass', 'warn', 'fail'][rand(0, 4)];
            $tt = CcmTenantTest::create([
                'organization_id' => $this->orgId, 'ccm_test_id' => $t->id,
                'status' => 'enabled', 'last_status' => $status,
                'last_run_at' => now()->subHours(rand(1, 48)),
                'next_run_at' => now()->addDay(),
            ]);
            CcmTestRun::create([
                'tenant_test_id' => $tt->id,
                'status' => $status,
                'output_summary' => "Latest run returned: {$status}",
                'latency_ms' => rand(150, 2500),
                'ran_at' => now()->subHours(rand(1, 48)),
            ]);
        }
    }

    private function evidenceVault(): void
    {
        if (EvidenceVaultItem::count() > 0) {
            return;
        }
        $cats = ['cbn_csat', 'ndpa_breach', 'vendor_ddq', 'ccm_run', 'policy_attestation', 'audit_report'];
        for ($i = 0; $i < 40; $i++) {
            EvidenceVaultItem::create([
                'organization_id' => $this->orgId,
                'subject_type' => 'CcmTestRun',
                'subject_id' => $this->realIdFor('CcmTestRun'),
                'file_path' => 'evidence/demo/'.Str::uuid().'.pdf',
                'sha256' => hash('sha256', 'demo-'.$i),
                'bytes' => rand(120_000, 8_000_000),
                'mime' => 'application/pdf',
                'retention_until' => now()->addYears(7),
                'worm_locked' => true,
                'category' => $cats[array_rand($cats)],
            ]);
        }
    }

    private function kri(): void
    {
        if (Kri::count() > 0) {
            return;
        }
        $items = [
            ['KRI-CH-ATM', 'ATM Network Availability', 'channel', 99.5, 99.0, 97.0, 'lower_worse', '%'],
            ['KRI-CH-USSD', 'USSD Channel Availability', 'channel', 99.9, 99.5, 98.0, 'lower_worse', '%'],
            ['KRI-CH-MB', 'Mobile Banking Availability', 'channel', 99.9, 99.5, 98.0, 'lower_worse', '%'],
            ['KRI-PAY-NIP', 'NIBSS NIP Failure Rate', 'payments', 0.1, 0.5, 1.0, 'higher_worse', '%'],
            ['KRI-PAY-CARD', 'Card Issuance SLA breaches', 'payments', 0, 3, 10, 'higher_worse', 'count'],
            ['KRI-FRAUD-BEC', 'BEC fraud reports (weekly)', 'fraud', 0, 2, 5, 'higher_worse', 'count'],
            ['KRI-FRAUD-CARD', 'Card fraud losses (₦m / week)', 'fraud', 0, 5, 20, 'higher_worse', 'NGN_M'],
            ['KRI-IT-EOL', 'EOL AIX host count', 'infrastructure', 0, 3, 10, 'higher_worse', 'count'],
            ['KRI-IT-PATCH', 'Overdue critical patches', 'vulnerability', 0, 5, 20, 'higher_worse', 'count'],
            ['KRI-IT-PRIV', 'Privileged accounts', 'iam', 20, 40, 80, 'higher_worse', 'count'],
            ['KRI-IT-MFA', 'MFA coverage (privileged)', 'iam', 100, 95, 80, 'lower_worse', '%'],
            ['KRI-IT-STALE', 'Stale accounts (>90d)', 'iam', 0, 10, 50, 'higher_worse', 'count'],
            ['KRI-POL-ATT', 'Policy attestation coverage', 'governance', 95, 85, 70, 'lower_worse', '%'],
            ['KRI-POL-EXP', 'Expired policies', 'governance', 0, 3, 10, 'higher_worse', 'count'],
            ['KRI-INC-OPEN', 'Open critical incidents', 'incident', 0, 1, 3, 'higher_worse', 'count'],
            ['KRI-INC-MTTR', 'MTTR (critical, hours)', 'incident', 24, 72, 168, 'higher_worse', 'hours'],
            ['KRI-VUL-KEV', 'Open KEV vulnerabilities', 'vulnerability', 0, 2, 5, 'higher_worse', 'count'],
            ['KRI-VUL-CRIT', 'Critical CVEs unpatched', 'vulnerability', 0, 5, 15, 'higher_worse', 'count'],
            ['KRI-TPR-GRADE', 'Vendors rated D or F', 'tprm', 0, 2, 5, 'higher_worse', 'count'],
            ['KRI-TPR-BREACH', 'Vendor breach events (30d)', 'tprm', 0, 1, 3, 'higher_worse', 'count'],
            ['KRI-COMP-CBN', 'CBN circular actions open', 'compliance', 0, 3, 10, 'higher_worse', 'count'],
            ['KRI-COMP-NDPC', 'NDPC DSAR breaches', 'compliance', 0, 1, 3, 'higher_worse', 'count'],
            ['KRI-BCP-RTO', 'DR exercises overdue', 'resilience', 0, 1, 3, 'higher_worse', 'count'],
            ['KRI-BCP-BACK', 'Failed backup tests', 'resilience', 0, 1, 3, 'higher_worse', 'count'],
            ['KRI-CYB-EMAIL', 'Phishing click-through (%)', 'security', 2, 5, 10, 'higher_worse', '%'],
            ['KRI-CYB-SIEM', 'SIEM critical alerts (weekly)', 'security', 5, 15, 30, 'higher_worse', 'count'],
            ['KRI-DPR-DLP', 'DLP incidents (30d)', 'data', 0, 3, 10, 'higher_worse', 'count'],
            ['KRI-DPR-MASK', 'PII found in non-prod', 'data', 0, 1, 3, 'higher_worse', 'count'],
            ['KRI-OPS-CHG', 'Failed changes (30d)', 'operations', 0, 3, 10, 'higher_worse', 'count'],
            ['KRI-OPS-OUT', 'Major outages (30d)', 'operations', 0, 1, 3, 'higher_worse', 'count'],
        ];
        foreach ($items as [$code, $name, $cat, $g, $a, $r, $dir, $unit]) {
            $kri = Kri::create([
                'organization_id' => $this->orgId, 'code' => $code, 'name' => $name,
                'description' => $name.' — Nigerian DMB KRI.',
                'category' => $cat, 'threshold_green' => $g, 'threshold_amber' => $a, 'threshold_red' => $r,
                'direction' => $dir, 'unit' => $unit,
            ]);
            // 12 readings
            for ($w = 11; $w >= 0; $w--) {
                $val = $dir === 'higher_worse'
                    ? max(0, $g + ($w < 3 ? rand(0, (int) ($r * 1.2)) : rand(0, (int) ($a))))
                    : max(0, $r + rand(0, (int) max($g - $r, 1)));
                $status = self::kriStatus($val, $g, $a, $r, $dir);
                KriReading::create([
                    'kri_id' => $kri->id,
                    'period' => now()->subWeeks($w)->format('Y-\WW'),
                    'value' => $val,
                    'status' => $status,
                    'recorded_at' => now()->subWeeks($w),
                ]);
                if ($status === 'red' && rand(0, 1)) {
                    KriBreach::create([
                        'kri_id' => $kri->id,
                        'level' => 'red',
                        'notes' => 'Red-status breach auto-recorded (demo).',
                        'occurred_at' => now()->subWeeks($w),
                    ]);
                }
            }
        }
    }

    private static function kriStatus($val, $g, $a, $r, $dir): string
    {
        if ($dir === 'higher_worse') {
            if ($val <= $g) {
                return 'green';
            }
            if ($val <= $a) {
                return 'amber';
            }

            return 'red';
        }
        if ($val >= $g) {
            return 'green';
        }
        if ($val >= $a) {
            return 'amber';
        }

        return 'red';
    }

    private function boardPacks(): void
    {
        if (BoardPackTemplate::count() > 0) {
            return;
        }
        $templates = [
            ['BAC Pack — Monthly', ['exec_summary', 'risk_heat_map', 'kri_panel', 'top_10_risks', 'control_status', 'cbn_return_readiness']],
            ['Board Risk Committee', ['risk_overview', 'fair_summary', 'incident_summary', 'tprm_summary']],
            ['CISO Weekly', ['incidents', 'vulnerabilities', 'ccm_results', 'threat_advisories']],
            ['CRO Monthly', ['kri_panel', 'fair_ale', 'risk_appetite']],
        ];
        foreach ($templates as [$name, $sections]) {
            $t = BoardPackTemplate::create(['name' => $name, 'sections' => $sections, 'navy_variant' => true]);
            for ($i = 1; $i <= 3; $i++) {
                BoardPackRun::create([
                    'organization_id' => $this->orgId,
                    'template_id' => $t->id,
                    'period' => now()->subMonths($i)->format('Y-m'),
                    'status' => 'generated',
                    'pptx_path' => 'board-packs/demo-'.$t->id.'-'.$i.'.pptx',
                    'pdf_path' => 'board-packs/demo-'.$t->id.'-'.$i.'.pdf',
                    'generated_at' => now()->subMonths($i),
                ]);
            }
        }
    }

    private function pricing(): void
    {
        if (PricingTier::count() > 0) {
            return;
        }
        PricingTier::create([
            'tier' => 'essentials', 'name' => 'Essentials',
            'annual_ngn' => 12_000_000, 'annual_usd' => 8_000,
            'features' => ['Risk Register & Assessments', 'Policy Management', 'Incident Register', 'Vendor Register', 'AUCS Browser (read-only)', 'Up to 25 users'],
            'limits' => ['users' => '25', 'storage' => '50 GB', 'api' => '300 rpm'],
        ]);
        PricingTier::create([
            'tier' => 'professional', 'name' => 'Professional',
            'annual_ngn' => 36_000_000, 'annual_usd' => 24_000,
            'features' => ['Everything in Essentials', 'CBN-CSAT bundled free', 'Continuous Controls Monitoring', 'KRI Pack & Board Packs', 'Atheris Copilot', 'Regulatory Intelligence', 'SSO (SAML/OIDC) + SCIM', 'Up to 200 users'],
            'limits' => ['users' => '200', 'storage' => '500 GB', 'api' => '1,200 rpm'],
            'is_popular' => true,
        ]);
        PricingTier::create([
            'tier' => 'enterprise', 'name' => 'Enterprise',
            'annual_ngn' => 96_000_000, 'annual_usd' => 64_000,
            'features' => ['Everything in Professional', 'Air-gapped on-prem deployment', 'Core-Banking Adapters', 'FAIR quantification', 'Document Intelligence', 'Dedicated Customer Success', 'Unlimited users'],
            'limits' => ['users' => 'Unlimited', 'storage' => 'Unlimited', 'api' => 'Custom', 'hosting' => 'SaaS / Dedicated / On-prem'],
        ]);
    }

    private function obligations(): void
    {
        if (Obligation::count() > 0) {
            return;
        }
        $rows = [
            ['CBN', 'RBCSF-§3.9.3', 'Annual Cybersecurity Self-Assessment', 'CISO', 365],
            ['CBN', 'ITSM-Advisory', '24-hour cyber incident advisory to CBN', 'CISO', 30],
            ['CBN', 'Outsourcing', 'Material outsourcing prior approval', 'Compliance Head', 30],
            ['CBN', 'BCM', 'Annual BCP/DR test and report', 'CRO', 365],
            ['NDPC', 'NDPA-§39', '72-hour breach notification to NDPC', 'DPCO', 30],
            ['NDPC', 'NDPA-§32', 'DPCO appointment and annual audit', 'DPCO', 365],
            ['NDPC', 'NDPA-§35', 'Subject access requests within 30 days', 'DPCO', 30],
            ['NDPC', 'NDPA-§41', 'Records of processing activities', 'DPCO', 90],
            ['NDIC', 'Outsourcing-Notif', 'Notify NDIC of material outsourcing', 'Compliance Head', 30],
            ['NAICOM', 'ERM-Annual', 'Annual ERM report', 'CRO', 365],
            ['SEC', 'Cyber-Disclosure', 'Material cyber event disclosure', 'CISO', 30],
            ['NCC', 'Registration', 'Telecoms licensing if applicable', 'Legal', 365],
            ['PENCOM', 'Pension-Filings', 'Quarterly pension filings', 'HR', 90],
            ['BoG', 'CyberDirective-2023', 'Ghana cyber directive obligations', 'CISO', 365],
            ['CBK', 'GuidanceNote-2019', 'Kenya cybersecurity guidance note', 'CISO', 365],
        ];
        foreach ($rows as [$reg, $code, $title, $role, $cycle]) {
            Obligation::create([
                'organization_id' => $this->orgId, 'regulator_code' => $reg, 'reference_code' => $code,
                'title' => $title, 'body_markdown' => $title.' obligation.',
                'effective_date' => now()->subYear(), 'review_cycle_days' => $cycle,
                'applicability' => ['dmb' => true, 'mfb' => true, 'insurance' => $reg === 'NAICOM'],
                'owner_role' => $role,
                'evidence_requirement' => 'Evidence pack with signed attestation and source artefacts.',
            ]);
        }
    }

    private function circulars(): void
    {
        if (RegulatoryCircular::count() > 0) {
            return;
        }
        $items = [
            ['CBN', 'BSD/DIR/GEN/LAB/14/028', 'Guidelines on Cybersecurity Risk Management for OFIs', now()->subDays(6)],
            ['CBN', 'BPS/DIR/GEN/CIR/07/019', 'Enhancements to NIBSS NIP Operational Guidelines', now()->subDays(13)],
            ['CBN', 'FPR/DIR/PUB/CIR/02/011', 'BVN Linkage for Bank Accounts', now()->subDays(20)],
            ['NDPC', 'NDPC/CIR/2026/001', 'Clarification on NDPA Section 39 breach form', now()->subDays(4)],
            ['NDPC', 'NDPC/CIR/2026/002', 'Guidance on cross-border data transfers', now()->subDays(45)],
            ['SEC', 'SEC/CIR/01/2026', 'Cyber-event disclosure expectations', now()->subDays(30)],
            ['NCC', 'NCC/GUIDELINE/01/2026', 'Licensing for digital services', now()->subDays(60)],
            ['NAICOM', 'NAICOM/ERM/01/2026', 'Annual ERM filing reminder', now()->subDays(10)],
            ['PENCOM', 'PENCOM/CIR/2026/01', 'Quarterly pension filing update', now()->subDays(21)],
            ['NDIC', 'NDIC/OUT/2026/001', 'Revised outsourcing notification template', now()->subDays(90)],
        ];
        foreach ($items as [$reg, $num, $title, $date]) {
            RegulatoryCircular::create([
                'regulator_code' => $reg,
                'circular_number' => $num,
                'title' => $title,
                'issued_at' => $date,
                'ingested_at' => $date->copy()->addHours(3),
                'source_url' => 'https://example.org/'.$reg.'/'.Str::slug($num),
                'plain_text' => "Full text of {$reg} {$num}: {$title}. Effective immediately...",
                'llm_summary' => "Atheris summary: This {$reg} circular introduces updated expectations regarding {$title}. Impacted functions: Compliance, Risk, IT Security. Action owners: CISO / CRO / DPCO.",
                'impact_assessment' => [
                    'impacted_policies' => ['Information Security Policy', 'Incident Response Policy'],
                    'impacted_obligations' => [$reg.'/'.$num],
                    'impacted_controls' => ['AUCS-INC-03', 'AUCS-GOV-02'],
                    'severity' => ['Medium', 'High', 'Low'][rand(0, 2)],
                ],
                'status' => 'published',
            ]);
        }
    }

    private function copilot(): void
    {
        if (CopilotConversation::count() > 0) {
            return;
        }
        $user = $this->tenantUser();
        $c = CopilotConversation::create([
            'organization_id' => $this->orgId, 'user_id' => $user->id,
            'title' => 'Which risks are overdue?', 'mode' => 'risk',
        ]);
        CopilotMessage::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'Show me overdue critical risks', 'tokens_in' => 12, 'model' => 'claude-sonnet-4-6', 'created_at' => now()->subMinutes(30)]);
        CopilotMessage::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => "I found 3 overdue critical risks:\n• R-0007: Privileged AD accounts missing MFA (owner: Fatima Bello)\n• R-0014: EOL AIX hosts on core network (owner: CTO)\n• R-0021: NIBSS NIP SLA breach (owner: Head of IT Ops)\n\nAll three have linked Issues and exceeded their 14-day remediation SLA.", 'tokens_out' => 120, 'model' => 'claude-sonnet-4-6', 'created_at' => now()->subMinutes(29)]);

        $c2 = CopilotConversation::create(['organization_id' => $this->orgId, 'user_id' => $user->id, 'title' => 'Summarise latest CBN circular', 'mode' => 'general']);
        CopilotMessage::create(['conversation_id' => $c2->id, 'role' => 'user', 'content' => 'Summarise the latest CBN cybersecurity circular', 'model' => 'claude-sonnet-4-6', 'tokens_in' => 12, 'created_at' => now()->subHours(6)]);
        CopilotMessage::create(['conversation_id' => $c2->id, 'role' => 'assistant', 'content' => "BSD/DIR/GEN/LAB/14/028 — Guidelines on Cybersecurity Risk Management for OFIs (issued last week). Key obligations: (1) appoint a CISO reporting to the Board, (2) annual self-assessment using the CBN CSAT workbook, (3) 24-hour advisory on material incidents, (4) quarterly BCP testing. I've auto-mapped this to your existing obligations and flagged 2 policies for review.", 'model' => 'claude-sonnet-4-6', 'tokens_out' => 160, 'created_at' => now()->subHours(6)->addSeconds(8)]);
    }

    private function tprm(): void
    {
        if (TprmSecurityRating::count() > 0) {
            return;
        }
        $vendors = Vendor::limit(10)->get();
        if ($vendors->isEmpty()) {
            // Vendors are seeded later (KanoHeritageDemoSeeder); create a minimal
            // set here so TPRM ratings/breach events never run on an empty table.
            // These belong to the demo tenant like the rest of this seeder's
            // output — `Organization::query()->value('id')` took whichever
            // organisation happened to be first, which is Acme.
            $vendors = collect([
                ['code' => 'VDR-TPRM-001', 'name' => 'Interswitch Group', 'category' => 'Payment Processor', 'risk_level' => 'critical'],
                ['code' => 'VDR-TPRM-002', 'name' => 'NIBSS Plc', 'category' => 'Payment Infrastructure', 'risk_level' => 'critical'],
                ['code' => 'VDR-TPRM-003', 'name' => 'MainOne (Equinix)', 'category' => 'Data Centre / Connectivity', 'risk_level' => 'high'],
                ['code' => 'VDR-TPRM-004', 'name' => 'Temenos T24 (Partner)', 'category' => 'Core Banking', 'risk_level' => 'critical'],
                ['code' => 'VDR-TPRM-005', 'name' => 'Galaxy Backbone', 'category' => 'Cloud / Hosting', 'risk_level' => 'medium'],
            ])->map(fn ($v) => Vendor::firstOrCreate(
                ['name' => $v['name']],
                [
                    'organization_id' => $this->orgId,
                    'vendor_code' => $v['code'],
                    'category' => $v['category'],
                    'risk_level' => $v['risk_level'],
                    'status' => 'active',
                ]
            ));
        }
        foreach ($vendors as $v) {
            $grade = ['A', 'B', 'B', 'C', 'D'][rand(0, 4)];
            $rating = ['A' => 92, 'B' => 82, 'C' => 72, 'D' => 55][$grade];
            TprmSecurityRating::create([
                'vendor_id' => $v->id,
                'provider' => 'securityscorecard',
                'rating_value' => $rating,
                'grade' => $grade,
                'captured_at' => now()->subDays(rand(1, 30)),
                'delta_from_previous' => rand(-10, 10),
            ]);
        }
        $headlines = [
            'Data incident disclosed — 2026-Q1',
            'Ransomware group names vendor on leak site',
            'CVE-2026-12345 reported affecting vendor stack',
            'New domain squatting typosquat detected',
        ];
        for ($i = 0; $i < 6; $i++) {
            $v = $vendors->random();
            TprmBreachEvent::create([
                'vendor_id' => $v->id,
                'event_type' => ['breach', 'typosquat', 'cve', 'leak_site'][rand(0, 3)],
                'headline' => $headlines[array_rand($headlines)],
                'url' => 'https://example.com/news/'.Str::random(10),
                'discovered_at' => now()->subDays(rand(1, 60)),
            ]);
        }

        // Shared vendor directory
        if (SharedVendorDirectory::count() === 0) {
            $sv = [
                ['interswitch', 'Interswitch', 'Switching & Payments', 'Owns Quickteller, Verve, Paydirect.'],
                ['nibss', 'NIBSS', 'Payments Infrastructure', 'Owner of NIP, BVN, POS; CBN subsidiary.'],
                ['cscs', 'CSCS', 'Capital Markets', 'Central securities clearing system.'],
                ['fmdq', 'FMDQ', 'OTC Exchange', 'Debt capital market infrastructure.'],
                ['unified-payments', 'Unified Payments', 'Card Processing', 'Nigerian card processor.'],
                ['etranzact', 'e-Tranzact', 'Payments', 'Cross-border payments.'],
                ['teamapt', 'TeamApt (Moniepoint)', 'Agent Banking', 'Agent banking & PoS.'],
                ['appzone', 'Appzone', 'Core Banking', 'BankOne core.'],
            ];
            foreach ($sv as [$slug, $name, $cat, $desc]) {
                SharedVendorDirectory::create([
                    'slug' => $slug, 'legal_name' => $name, 'category' => $cat,
                    'country' => 'NG', 'baseline_ddq_version' => 'v1.0',
                    'contacts' => ['email' => 'info@'.$slug.'.example.com'],
                    'description' => $desc,
                ]);
            }
        }
    }

    private function siem(): void
    {
        if (SiemIntegration::count() > 0) {
            return;
        }
        SiemIntegration::create(['organization_id' => $this->orgId, 'provider' => 'sentinel', 'config' => ['workspace' => 'atheris-ws'], 'status' => 'active', 'last_signal_at' => now()->subMinutes(12)]);
        SiemIntegration::create(['organization_id' => $this->orgId, 'provider' => 'splunk_es', 'config' => ['host' => 'splunk.internal'], 'status' => 'active', 'last_signal_at' => now()->subMinutes(34)]);
        SiemIntegration::create(['organization_id' => $this->orgId, 'provider' => 'wazuh', 'config' => ['manager' => 'wazuh.internal'], 'status' => 'active', 'last_signal_at' => now()->subMinutes(5)]);
        SiemIntegration::create(['organization_id' => $this->orgId, 'provider' => 'qradar', 'config' => [], 'status' => 'degraded', 'last_signal_at' => now()->subHours(2)]);

        $titles = [
            'Brute force login attempt — 41.58.x.x',
            'Suspicious PowerShell execution on FINACLE-APP-01',
            'Lateral movement indicator on ATM-NETWORK-02',
            'DNS tunneling suspected from internal host',
            'Multiple failed MFA attempts for admin account',
            'Unusual login geography (Ghana → NG)',
            'Sensitive file exfiltration alert (DLP)',
            'Beaconing traffic detected to known-bad C2',
        ];
        $providers = ['sentinel', 'splunk_es', 'wazuh'];
        for ($i = 0; $i < 25; $i++) {
            SiemSignal::create([
                'organization_id' => $this->orgId,
                'provider' => $providers[array_rand($providers)],
                'external_id' => strtoupper(Str::random(10)),
                'severity' => ['critical', 'high', 'medium', 'low'][rand(0, 3)],
                'title' => $titles[array_rand($titles)],
                'payload' => ['src_ip' => '41.58.1.'.rand(1, 250), 'user' => 'svc_app', 'rule' => 'R-'.rand(100, 999)],
                'received_at' => now()->subMinutes(rand(1, 1440)),
                'incident_id' => rand(0, 1) ? $this->realIdFor('Incident') : null,
            ]);
        }
    }

    private function notifications(): void
    {
        if (NotificationTemplate::count() > 0) {
            return;
        }
        $tmpls = [
            ['CBN', 'cbn_24h', 'CBN 24h Cyber Advisory', "Subject: Cyber Incident Advisory per CBN ITSM\n\nDear Director, Banking Supervision,\n\n1. Incident summary:\n2. Affected systems:\n3. Initial assessment of impact:\n4. Actions in progress:\n\nSigned,\nCISO"],
            ['NDPC', 'ndpc_72h', 'NDPC 72h Breach Form (§39)', "NDPC Personal Data Breach Notification\n\nController:\nDPCO:\nDate of breach:\nCategories and volume:\nLikely consequences:\nMeasures taken:\n"],
            ['NFIU', 'nfiu_sar', 'NFIU Suspicious Activity Report', "NFIU SAR\n\nSubject:\nTransaction reference:\nSuspicion indicators:\nNarrative:\n"],
            ['NAICOM', 'naicom_note', 'NAICOM Incident Note', "NAICOM Incident Note\n\nInsurer:\nIncident date:\nImpact:\nMitigations:\n"],
        ];
        foreach ($tmpls as [$reg, $key, $title, $body]) {
            NotificationTemplate::create([
                'regulator_code' => $reg, 'template_key' => $key,
                'title' => $title, 'body_template' => $body,
                'schema' => ['required' => ['summary', 'impact', 'timeline']], 'version' => '1.0',
            ]);
        }
        // Example draft notifications
        $incidentId = DB::table('incidents')->value('id') ?: 1;
        IncidentNotification::create([
            'incident_id' => $incidentId, 'template_key' => 'cbn_24h',
            'draft_body' => 'DRAFT — 24h advisory for incident #'.$incidentId,
            'delivery_status' => 'draft', 'deadline_at' => now()->addHours(24),
        ]);
        IncidentNotification::create([
            'incident_id' => $incidentId, 'template_key' => 'ndpc_72h',
            'draft_body' => 'DRAFT — NDPC 72h breach form for incident #'.$incidentId,
            'delivery_status' => 'in_review', 'deadline_at' => now()->addHours(72),
        ]);
    }

    private function fair(): void
    {
        if (! FairScenario::where('organization_id', $this->orgId)->exists()) {
            // [name, description, frequency (events/yr), magnitude (₦), control effectiveness %]
            // Risk links are made by DemoCrossLinkSeeder once the bank registers exist.
            $scenarios = [
                ['DDoS against USSD channel', '₦10m - ₦250m impact per hour of downtime', [0.5, 2, 6], [10_000_000, 60_000_000, 250_000_000], 55],
                ['Core banking ransomware event', '₦500m - ₦5bn impact with 72h recovery', [0.05, 0.2, 0.6], [500_000_000, 1_500_000_000, 5_000_000_000], 63],
                ['NDPA Article 39 breach', '₦10m - ₦1bn regulatory fine plus remediation', [0.1, 0.4, 1.5], [10_000_000, 150_000_000, 1_000_000_000], 48],
                ['Vendor compromise (Interswitch)', '₦50m - ₦2bn cross-channel impact', [0.05, 0.25, 1], [50_000_000, 400_000_000, 2_000_000_000], 52],
            ];
            $monteCarlo = app(FairMonteCarloService::class);
            foreach ($scenarios as $i => [$name, $desc, [$fMin, $fMost, $fMax], [$mMin, $mMost, $mMax], $ctrl]) {
                $s = FairScenario::create([
                    'organization_id' => $this->orgId,
                    'name' => $name, 'loss_event_description' => $desc,
                    'frequency_distribution' => ['min' => $fMin, 'most' => $fMost, 'max' => $fMax],
                    'magnitude_distribution' => ['min' => $mMin, 'most' => $mMost, 'max' => $mMax],
                    'control_effectiveness' => ['percent' => $ctrl],
                    'iterations' => 10_000,
                ]);
                $result = $monteCarlo->simulate($s, seed: 1000 + $i);
                FairRun::create(collect($result)->only(['ale_mean_ngn', 'ale_median_ngn', 'ale_p95_ngn', 'ale_p99_ngn', 'histogram'])->all() + [
                    'scenario_id' => $s->id,
                    'ran_at' => now()->subDays(rand(0, 30)),
                ]);
            }
        }

        // NDPC fine bands
        if (DB::table('ndpc_fine_bands')->count() === 0) {
            $bands = [
                ['Tier 1', 2_000_000, 10_000_000, 'Minor breach'],
                ['Tier 2', 10_000_000, 100_000_000, 'Moderate breach'],
                ['Tier 3', 100_000_000, 1_000_000_000, 'Significant breach'],
                ['Tier 4', 1_000_000_000, 10_000_000_000, 'Up to 2% of annual turnover'],
            ];
            foreach ($bands as [$n, $mn, $mx, $note]) {
                DB::table('ndpc_fine_bands')->insert([
                    'band_name' => $n, 'fine_min_ngn' => $mn, 'fine_max_ngn' => $mx, 'notes' => $note,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function advisories(): void
    {
        if (ThreatAdvisory::count() > 0) {
            return;
        }
        $rows = [
            ['ngcert', 'NGCERT-2026-01', 'Critical bug in popular banking middleware', ['CVE-2026-1111']],
            ['ngcert', 'NGCERT-2026-02', 'USSD fraud campaign targeting Nigerian banks', []],
            ['nitda', 'NITDA-2026-05', 'Phishing campaign impersonating NDPC', []],
            ['us-cert', 'CISA-AA26-050A', 'Ransomware actor using stolen credentials', ['CVE-2026-2222', 'CVE-2026-2223']],
            ['nitda', 'NITDA-2026-06', 'Zero-day in enterprise VPN appliance', ['CVE-2026-3333']],
        ];
        foreach ($rows as [$src, $id, $title, $cves]) {
            ThreatAdvisory::create([
                'source' => $src, 'advisory_id' => $id, 'title' => $title,
                'body' => $title.' — indicators and mitigations.',
                'published_at' => now()->subDays(rand(1, 30)),
                'cves' => $cves, 'iocs' => ['ip' => ['41.58.1.1'], 'hash' => [Str::random(64)]],
            ]);
        }

        // EPSS + KEV demo rows
        if (DB::table('epss_cache')->count() === 0) {
            foreach (range(1, 20) as $i) {
                DB::table('epss_cache')->insert([
                    'cve_id' => 'CVE-2026-'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                    'epss_score' => round(mt_rand(0, 10000) / 10000, 4),
                    'percentile' => round(mt_rand(0, 10000) / 10000, 4),
                    'updated_at' => now(),
                ]);
            }
        }
        if (DB::table('kev_cache')->count() === 0) {
            foreach (range(1, 8) as $i) {
                DB::table('kev_cache')->insert([
                    'cve_id' => 'CVE-2026-'.str_pad((string) (2000 + $i), 4, '0', STR_PAD_LEFT),
                    'date_added' => now()->subDays(rand(1, 60)),
                    'known_ransomware_use' => rand(0, 1),
                    'vendor_project' => 'Vendor '.$i,
                    'product' => 'Product '.$i,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function docIntel(): void
    {
        if (DocIntelligenceJob::count() > 0) {
            return;
        }
        $types = ['circular', 'ndpc_decision', 'audit_report', 'vendor_ddq'];
        for ($i = 0; $i < 8; $i++) {
            DocIntelligenceJob::create([
                'organization_id' => $this->orgId,
                'doc_type' => $types[array_rand($types)],
                'source_file' => 'docs/demo/'.Str::uuid().'.pdf',
                'ocr_text' => 'Extracted OCR text sample...',
                'structured_output' => [
                    'regulator' => 'CBN', 'reference' => 'BSD/DIR/GEN/LAB/14/'.rand(1, 100),
                    'effective_date' => now()->subDays(rand(1, 100))->toDateString(),
                    'summary' => 'Demo structured extraction from the uploaded document.',
                ],
                'confidence' => round(mt_rand(8500, 9900) / 10000, 4),
                'status' => ['awaiting_review', 'accepted', 'extraction_done'][rand(0, 2)],
            ]);
        }
    }

    private function returns(): void
    {
        if (ReturnTemplate::count() > 0) {
            return;
        }
        $tmpls = [
            ['CBN', 'cbn_crms_it', 'CBN CRMS IT Return'],
            ['CBN', 'cbn_cyber_sa', 'Annual Cyber Self-Assessment'],
            ['NDPC', 'ndpc_audit', 'NDPC Annual Audit Report'],
            ['NDIC', 'ndic_outsource', 'NDIC Outsourcing Notification'],
        ];
        foreach ($tmpls as [$reg, $code, $name]) {
            $t = ReturnTemplate::create([
                'regulator_code' => $reg, 'return_code' => $code, 'name' => $name,
                'schema' => ['fields' => [['key' => 'institution', 'type' => 'text'], ['key' => 'period', 'type' => 'text']]],
                'version' => '1.0', 'effective_from' => now()->subMonths(6), 'navy_variant' => true,
            ]);
            ReturnRun::create([
                'organization_id' => $this->orgId, 'template_id' => $t->id,
                'period' => now()->subMonth()->format('Y-m'),
                'status' => 'submitted',
                'pdf_path' => 'returns/'.$code.'-'.now()->subMonth()->format('Y-m').'.pdf',
                'xlsx_path' => 'returns/'.$code.'-'.now()->subMonth()->format('Y-m').'.xlsx',
                'data' => ['auto_populated' => true],
                'submitted_at' => now()->subWeeks(3),
            ]);
            ReturnRun::create([
                'organization_id' => $this->orgId, 'template_id' => $t->id,
                'period' => now()->format('Y-m'),
                'status' => 'draft',
                'data' => ['auto_populated' => true],
            ]);
        }
    }

    private function workflows(): void
    {
        if (Workflow::count() > 0) {
            return;
        }
        $wfs = [
            ['CBN ITSM Incident Response', 'incident', [
                ['start'], ['classify'], ['investigate'], ['notify_cbn_24h'], ['remediate'], ['lessons_learned'], ['close'],
            ]],
            ['NDPC DPIA', 'privacy', [
                ['start'], ['scoping'], ['risk_assessment'], ['control_mapping'], ['dpo_review'], ['approval'], ['end'],
            ]],
            ['NAICOM Breach Notification', 'incident', [
                ['start'], ['triage'], ['notify_naicom'], ['customer_notice'], ['close'],
            ]],
            ['NDIC Risk Finding Closure', 'audit', [
                ['start'], ['root_cause'], ['remediation_plan'], ['verify'], ['close'],
            ]],
            ['Vendor Onboarding DDQ', 'tprm', [
                ['start'], ['ddq_send'], ['ddq_review'], ['risk_accept'], ['contract'], ['end'],
            ]],
            ['Policy Exception Review', 'governance', [
                ['start'], ['submit'], ['ciso_review'], ['cro_approval'], ['track'], ['expire'],
            ]],
        ];
        foreach ($wfs as [$name, $cat, $steps]) {
            $def = [
                'nodes' => array_map(fn ($s, $i) => ['id' => $i, 'label' => $s[0]], $steps, array_keys($steps)),
                'edges' => array_map(fn ($i) => ['source' => $i, 'target' => $i + 1], range(0, count($steps) - 2)),
            ];
            $w = Workflow::create([
                'organization_id' => $this->orgId, 'name' => $name, 'version' => '1.0',
                'definition_json' => $def, 'status' => 'published', 'category' => $cat,
            ]);
            // Marketplace templates are separate
            Workflow::create([
                'organization_id' => null, 'name' => $name.' (Template)', 'version' => '1.0',
                'definition_json' => $def, 'status' => 'template', 'category' => $cat,
            ]);
            // Instance
            $inst = WorkflowInstance::create([
                'workflow_id' => $w->id,
                'subject_type' => 'Incident', 'subject_id' => $this->realIdFor('Incident'),
                'state_json' => ['current' => $steps[2][0] ?? 'start'],
                'status' => 'running',
                'started_at' => now()->subDays(rand(1, 5)),
            ]);
            foreach ($steps as $i => $step) {
                WorkflowTask::create([
                    'instance_id' => $inst->id,
                    'key' => $step[0], 'assignee_id' => null,
                    'due_at' => now()->addDays($i),
                    'completed_at' => $i < 2 ? now()->subDays(rand(0, 3)) : null,
                    'outcome' => $i < 2 ? 'completed' : null,
                    'status' => $i < 2 ? 'completed' : 'pending',
                ]);
            }
        }
    }

    private function coreBanking(): void
    {
        if (CoreBankingIntegration::count() > 0) {
            return;
        }
        $products = ['finacle', 'flexcube', 't24', 'bankone', 'interswitch', 'nibss'];
        foreach ($products as $p) {
            CoreBankingIntegration::create([
                'organization_id' => $this->orgId,
                'product' => $p,
                'config' => ['endpoint' => 'https://'.$p.'.internal/api'],
                'status' => $p === 't24' ? 'pending' : 'connected',
                'last_sync_at' => now()->subHours(rand(1, 12)),
            ]);
            for ($i = 0; $i < 2; $i++) {
                CoreBankingSnapshot::create([
                    'organization_id' => $this->orgId,
                    'product' => $p,
                    'captured_at' => now()->subDays($i),
                    'artefacts' => [
                        'product_catalogue' => 42 + $i,
                        'branch_count' => 128,
                        'technical_health' => ['cpu' => rand(30, 90), 'mem' => rand(40, 95)],
                    ],
                ]);
            }
        }
    }

    private function dr(): void
    {
        if (DrRunbook::count() > 0) {
            return;
        }
        $runbooks = [
            ['NIBSS NIP Failover', 'payments', 120, 'Head of Payments', 'Failover to secondary NIP node.'],
            ['Interswitch Switch Failover', 'payments', 90, 'Head of Cards', 'Route card traffic to standby switch.'],
            ['Finacle Core Banking DR', 'core', 240, 'CTO', 'Full Finacle DR cutover.'],
            ['ATM Reroute', 'channel', 60, 'Head of ATMs', 'Redirect terminals to standby acquirer.'],
            ['USSD Channel Failover', 'channel', 45, 'Head of Digital', 'Failover USSD MNO aggregator.'],
            ['Data Centre Power Loss', 'facility', 180, 'Head of Ops', 'DC-level disaster comms and switchover.'],
        ];
        foreach ($runbooks as [$name, $cat, $dur, $role, $desc]) {
            $rb = DrRunbook::create([
                'organization_id' => $this->orgId,
                'name' => $name, 'category' => $cat,
                'estimated_duration_min' => $dur, 'owner_role' => $role,
                'description' => $desc,
                'steps' => [
                    ['title' => 'Notify CRT & CISO', 'description' => 'Page on-call teams'],
                    ['title' => 'Confirm primary unavailable', 'description' => 'Validate outage scope'],
                    ['title' => 'Initiate failover', 'description' => 'Execute documented switch'],
                    ['title' => 'Validate service restoration', 'description' => 'Smoke-test key flows'],
                    ['title' => 'Communicate to business', 'description' => 'Update stakeholders'],
                    ['title' => 'Capture evidence', 'description' => 'Upload to Evidence Vault'],
                ],
            ]);
            for ($i = 0; $i < 2; $i++) {
                DrExercise::create([
                    'runbook_id' => $rb->id,
                    'scheduled_at' => now()->subMonths($i + 1),
                    'started_at' => now()->subMonths($i + 1),
                    'finished_at' => now()->subMonths($i + 1)->addMinutes($dur),
                    'participants' => ['CISO', 'CRO', 'Head of Ops'],
                    'evidence_ids' => [1, 2],
                    'outcome_notes' => 'Completed within target RTO.',
                    'status' => 'completed',
                ]);
            }
        }
    }

    private function marketplace(): void
    {
        if (MarketplaceItem::count() > 0) {
            return;
        }
        $items = [
            ['Atheris', 'regulator_pack', 'cbn-2026', 'CBN Regulator Pack 2026', 'Full CBN pack: circulars, obligations, KRIs, notification templates.', 0, 4.9, 142],
            ['Atheris', 'regulator_pack', 'ndpc-2026', 'NDPC Regulator Pack 2026', 'Full NDPC pack with §39 breach form + DPIA templates.', 0, 4.8, 128],
            ['Atheris', 'kri_pack', 'nigerian-dmb-kri', 'Nigerian DMB KRI Pack (30 KRIs)', 'Board-ready KRIs for Nigerian DMBs.', 1_500_000, 4.9, 86],
            ['Atheris', 'ccm_pack', 'cbn-rbcsf-ccm', 'CBN RBCSF CCM Starter', '40+ CCM tests mapped to CBN RBCSF.', 0, 4.7, 94],
            ['Partner: Bowmans', 'regulator_pack', 'sarb-za', 'SARB Regulator Pack (South Africa)', 'South Africa SARB obligations + circulars.', 3_500_000, 4.6, 22],
            ['Partner: KPMG', 'workflow_template', 'ndpc-dpia', 'NDPC DPIA Workflow', 'Drop-in DPIA workflow template.', 800_000, 4.5, 45],
            ['Partner: PwC', 'control_pack', 'pci-dss-4', 'PCI-DSS 4.0.1 Control Pack', 'Mapped controls + test procedures.', 2_000_000, 4.8, 38],
            ['Atheris', 'regulator_pack', 'bog-gh', 'BoG Regulator Pack (Ghana)', 'Bank of Ghana obligations + circulars.', 2_500_000, 4.5, 12],
            ['Atheris', 'regulator_pack', 'cbk-ke', 'CBK Regulator Pack (Kenya)', 'Central Bank of Kenya obligations + circulars.', 2_500_000, 4.4, 9],
            ['Atheris', 'workflow_template', 'cbn-itsm', 'CBN ITSM Incident Workflow', 'CBN-aligned incident response workflow.', 0, 4.7, 67],
        ];
        foreach ($items as [$pub, $type, $slug, $name, $desc, $price, $rating, $installs]) {
            MarketplaceItem::create([
                'publisher' => $pub, 'type' => $type, 'slug' => $slug, 'name' => $name,
                'description' => $desc,
                'version' => '1.0', 'status' => 'published',
                'manifest_json' => ['signed' => true, 'signer' => 'cosign://atheris'],
                'price_ngn' => $price, 'installs_count' => $installs, 'rating' => $rating,
            ]);
        }

        // Seed a few installs
        foreach (MarketplaceItem::inRandomOrder()->take(3)->get() as $m) {
            ContentInstall::create([
                'organization_id' => $this->orgId, 'marketplace_item_id' => $m->id,
                'version' => $m->version, 'installed_at' => now()->subDays(rand(1, 30)),
                'status' => 'active',
            ]);
        }
    }

    private function identity(): void
    {
        if (SsoConnection::count() === 0) {
            SsoConnection::create(['organization_id' => $this->orgId, 'type' => 'saml', 'idp_name' => 'Entra ID (Tenant Primary)', 'sp_entity_id' => 'https://atheris.ng/sp', 'enabled' => true]);
            SsoConnection::create(['organization_id' => $this->orgId, 'type' => 'oidc', 'idp_name' => 'Okta Workforce', 'sp_entity_id' => 'https://atheris.ng/sp', 'enabled' => true]);
            SsoConnection::create(['organization_id' => $this->orgId, 'type' => 'saml', 'idp_name' => 'Ping Identity', 'sp_entity_id' => 'https://atheris.ng/sp', 'enabled' => false]);
        }
        if (ScimToken::count() === 0) {
            ScimToken::create(['organization_id' => $this->orgId, 'name' => 'Entra ID provisioning', 'token_hash' => hash('sha256', Str::random(40)), 'scopes' => ['users:r', 'users:w', 'groups:r', 'groups:w'], 'expires_at' => now()->addYear()]);
            ScimToken::create(['organization_id' => $this->orgId, 'name' => 'Okta provisioning', 'token_hash' => hash('sha256', Str::random(40)), 'scopes' => ['users:r', 'users:w'], 'expires_at' => now()->addYear(), 'last_used_at' => now()->subHours(2)]);
        }
    }

    private function theming(): void
    {
        if (TenantTheme::count() === 0) {
            TenantTheme::create([
                'organization_id' => $this->orgId,
                'tokens' => ['navy' => '#0A1F44', 'gold' => '#C9A86A', 'green' => '#2D7D46', 'charcoal' => '#2D3748'],
                'logo_path' => null, 'status' => 'active',
            ]);
        }
        if (CustomField::count() === 0) {
            $fields = [
                ['Risk', 'cbn_clause', 'CBN RBCSF Clause', 'text', 10],
                ['Risk', 'regulator_reportable', 'Regulator Reportable?', 'boolean', 20],
                ['Control', 'aucs_code', 'AUCS Code', 'text', 10],
                ['Incident', 'ncert_reference', 'ngCERT Reference', 'text', 10],
                ['Vendor', 'nibss_interchange', 'NIBSS Interchange Member?', 'boolean', 10],
                ['Policy', 'approving_board_date', 'Approving Board Date', 'date', 10],
            ];
            foreach ($fields as [$subj, $key, $label, $type, $order]) {
                CustomField::create([
                    'organization_id' => $this->orgId, 'subject_type' => $subj, 'key' => $key,
                    'label' => $label, 'data_type' => $type, 'order_index' => $order, 'required' => false,
                ]);
            }
        }
    }

    private function auditSamples(): void
    {
        if (AuditEvent::count() > 0) {
            return;
        }
        $actions = ['create', 'update', 'delete', 'login', 'approve'];
        $subjects = ['Risk', 'Control', 'Policy', 'Incident', 'Vendor', 'Issue', 'User'];
        $users = $this->tenantUserIds();
        for ($i = 0; $i < 50; $i++) {
            AuditEvent::create([
                'organization_id' => $this->orgId,
                'actor_id' => $users[array_rand($users)] ?? 1,
                'actor_type' => 'user',
                'action' => $actions[array_rand($actions)],
                'subject_type' => $subjectType = $subjects[array_rand($subjects)],
                'subject_id' => $this->realIdFor($subjectType),
                'payload' => ['ip' => '41.58.'.rand(0, 255).'.'.rand(0, 255)],
                'ip' => '41.58.'.rand(0, 255).'.'.rand(0, 255),
                'user_agent' => 'Atheris/CLI Mozilla/5.0',
                'trace_id' => Str::uuid(),
                'created_at' => now()->subMinutes(rand(1, 10080)),
            ]);
        }
    }

    private function assetSync(): void
    {
        if (AssetSyncJob::count() > 0) {
            return;
        }
        $sources = ['ad', 'aws', 'tenable', 'qualys', 'csv'];
        foreach ($sources as $s) {
            AssetSyncJob::create([
                'organization_id' => $this->orgId, 'source' => $s,
                'status' => 'completed',
                'records_imported' => rand(40, 400),
                'records_updated' => rand(10, 60),
                'output_summary' => "Nightly {$s} sync successful",
                'started_at' => now()->subHours(rand(1, 24)),
                'finished_at' => now()->subHours(rand(0, 23)),
            ]);
        }
    }

    /**
     * Resolve a real primary key for a polymorphic subject type.
     *
     * ATH-EAR-002 WS 2.4: "No `rand()` into foreign keys anywhere." §2.5 shows
     * why it is not merely untidy — a service computing over ids that may not
     * resolve produces "arithmetic over noise", and a demo that shows a
     * regulator a coverage figure built that way is worse than showing none.
     *
     * Returns null rather than inventing an id when the target table is empty.
     */
    private array $idCache = [];

    private function realIdFor(?string $subjectType): ?int
    {
        if (! $subjectType) {
            return null;
        }

        $table = match (strtolower($subjectType)) {
            'incident' => 'incidents',
            'risk' => 'risks',
            'control' => 'controls',
            'vulnerability' => 'vulnerabilities',
            'asset' => 'assets',
            'vendor' => 'vendors',
            'policy' => 'policies',
            'ccmtestrun' => 'ccm_test_runs',
            'user' => 'users',
            default => null,
        };

        if (! $table || ! Schema::hasTable($table)) {
            return null;
        }

        $ids = $this->idCache[$table] ??= DB::table($table)->pluck('id')->all();

        return empty($ids) ? null : (int) $ids[array_rand($ids)];
    }
}
