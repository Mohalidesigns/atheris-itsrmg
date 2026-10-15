<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Create demo organization
        $organization = Organization::create([
            'name' => 'Acme Nigeria Ltd',
            'slug' => 'acme-nigeria',
            'industry' => 'Financial Services',
            'size' => 'medium',
            'country' => 'NG',
            'currency' => 'NGN',
            'subscription_plan' => 'enterprise',
            'subscription_expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        // Create demo admin user
        $admin = User::factory()->create([
            'name' => 'Adaeze Kunle-Usman',
            'email' => 'admin@acme.ng',
            'organization_id' => $organization->id,
            'job_title' => 'Chief Information Security Officer',
            'department' => 'Information Security',
        ]);
        $admin->assignRole('Organization Admin');

        // Create demo auditor
        $auditor = User::factory()->create([
            'name' => 'Chidi Okonkwo',
            'email' => 'auditor@acme.ng',
            'organization_id' => $organization->id,
            'job_title' => 'IT Auditor',
            'department' => 'Internal Audit',
        ]);
        $auditor->assignRole('Auditor');

        // Create risk manager
        $riskManager = User::factory()->create([
            'name' => 'Fatima Bello',
            'email' => 'risk@acme.ng',
            'organization_id' => $organization->id,
            'job_title' => 'Risk Manager',
            'department' => 'Enterprise Risk',
        ]);
        $riskManager->assignRole('Risk Manager');

        // Regulatory frameworks reference data (NDPA, CBN, ISO 27001 + requirements)
        $this->call(RegulatoryFrameworkSeeder::class);

        // CSAT reference data seeders
        $this->call([
            CsatIrQuestionsSeeder::class,
            CsatMaStatementsSeeder::class,
            CsatThreatCatalogueSeeder::class,
        ]);

        // Atheris platform (Phases 0-8) demo data
        $this->call(AtherisPlatformSeeder::class);

        // Kano Heritage Bank — full cross-linked demo dataset
        $this->call(KanoHeritageDemoSeeder::class);

        // Session 3 top-ups
        $this->call(ObligationsTopupSeeder::class);
        $this->call(KanoHeritageCsatSeeder::class);
        $this->call(CsatMaNarrativesTemplateSeeder::class);
        $this->call(IsmsPciMonitoringSeeder::class);
        $this->call(FirstBankDemoSeeder::class);
        // CBN-CSAT: computed scores, narratives, registers; Kano ready for approval, First Bank partial.
        $this->call(CsatDemoCompletionSeeder::class);
        // Compliance: requirement results behind every assessment, control mappings, gaps, evidence.
        $this->call(ComplianceDemoSeeder::class);

        // Session 5 — Enterprise Architecture module
        $this->call(EaPhase1Seeder::class);
        $this->call(EaPhase2Seeder::class);
        $this->call(EaPhase3Seeder::class);
        $this->call(EaPhase4Seeder::class);
        // ATH-EAR-002 Phase 1 — ownership, quality seals, surveys, ADRs.
        // ATH-EAR-002 §6.4 — the Nigerian Banking Reference Architecture pack,
        // then the tenant estate wired onto it.
        $this->call(NigerianReferenceArchitectureSeeder::class);
        $this->call(EaWedgeDemoSeeder::class);
        // ATH-EAR-002 Phase 4 — depth and scale. Wires the estate links the
        // derived features traverse (tech↔app, process↔app, the explicit
        // ArchiMate graph), authors the plateau scenarios the diff compares,
        // derives three diagrams and stages the change-proposal queue.
        // Runs BEFORE stewardship for the same break-on-edit reason.
        $this->call(EaDepthDemoSeeder::class);

        // Stewardship runs LAST: it approves quality seals, and any later edit
        // to a sealed record correctly breaks the seal (B3 break-on-edit), so
        // seeding approvals before the wedge data would invalidate them all.
        $this->call(EaStewardshipSeeder::class);

        // Cross-module demo wiring (asset↔risk, asset↔vuln, risk↔control,
        // vulnerability tickets, response procedures)
        $this->call(DemoCrossLinkSeeder::class);

        // Security Operations: coherent incident timelines, breach NDPA clocks, alerts, SIEM
        // connectors, regulatory notifications and remediation tickets (before Returns Centre runs)
        $this->call(SecurityOperationsDemoSeeder::class);

        // Issues & Remediation: in-bank sources, SLA clocks from policy, lifecycle and escalation
        // trail, ITSM connectors and tickets (after SecOps so findings and SLA policies are final)
        $this->call(IssuesDemoSeeder::class);

        // Change Management: approval policy, CAB/ECAB, freeze windows, standard templates and a
        // change register per bank linked to the records above (ATH-CHG-001 §10)
        $this->call(ChangeManagementDemoSeeder::class);

        // IT Financial Management foundation: settings, TBM taxonomy, chart of accounts, currencies & FX, taxes,
        // fiscal calendar, cost centres, drivers and finance users per bank (ATH-FIN-001 §3.1, §13)
        $this->call(FinanceFoundationSeeder::class);

        // IT Financial Management P1 (budgeting): DoA band versions, budget FX rates, cycles, FY baselines, a mid-year
        // revision, Workflow Studio default stages, transfers, supplementary requests, ceilings and next-year budgets
        $this->call(FinanceDemoSeeder::class);

        // Asset Management: coherent owners and departments, provenance from real sandbox connector
        // runs and a CSV import, business services and dependency chains per bank, asset → service
        // links, inherited criticality, and First Bank's EA applications tied to their assets
        // (ATH-VAL-007; after EA stewardship, written without model events so seals are untouched)
        $this->call(AssetManagementDemoSeeder::class);

        // Vendor Management: NDPA processor and CBN outsourcing facts, owners, computed tiers and
        // review clocks, Shared Directory links, six months of sandbox ratings and a triaged breach
        // watch (ATH-VAL-008; before Regulatory Intelligence so vendor return figures are final)
        $this->call(VendorManagementDemoSeeder::class);

        // Policy Management: categories, owners and approvers from each policy's title, attestations
        // only for policies in force, policy→control mapping and exceptions in every state (ATH-VAL-009)
        $this->call(PolicyManagementDemoSeeder::class);

        // ISMS: defensible SoA exclusions with reasons, a justification for every control, two approved
        // SoA versions and changes since; claim/evidence contradictions kept (ATH-VAL-010)
        $this->call(IsmsDemoSeeder::class);

        // PCI DSS: CDE scoping of assets, control mappings to PCI DSS v4.0.1 and last year's Report on
        // Compliance as a completed assessment with evidence (ATH-VAL-011)
        $this->call(PciDemoSeeder::class);

        // Business Continuity: BIAs linked to the services they set RTO/RPO for, plans linked to the
        // services they recover, DR exercises measured against target, First Bank's DR runbooks and
        // test history (ATH-VAL-012)
        $this->call(BusinessContinuityDemoSeeder::class);
        // Continuous Monitoring: CCM run history measured from the bank's records, drift reconciled with
        // approved changes, access-review campaigns (ATH-VAL-013)
        $this->call(ContinuousMonitoringDemoSeeder::class);

        // KRIs & Dashboards: the KRI pack for both banks with owners, risks and readings measured or
        // recorded through the service; board packs prepared and approved (ATH-VAL-014)
        $this->call(KriDashboardsDemoSeeder::class);

        // Enterprise Architecture: CBN blueprint domains rated on the CMMI scale through the
        // assessment service, and a real evidence pack from the approved assessment (ATH-VAL-015)
        $this->call(EaMaturityDemoSeeder::class);

        // Workflow Studio: approval flows per bank and live approvals for the inbox (ATH-VAL-016)
        $this->call(WorkflowStudioDemoSeeder::class);

        // Core Banking: read-only adapters synced through the connector (ATH-VAL-017)
        $this->call(CoreBankingDemoSeeder::class);

        // Regulatory Intelligence: circular triage, Document Intelligence, Returns Centre runs and
        // First Bank's compiled architecture returns (after stewardship so seal states are real)
        $this->call(RegulatoryIntelligenceDemoSeeder::class);

        // SSO connections, hashed SCIM tokens and offline Copilot conversations per bank (ATH-VAL-019)
        $this->call(IdentityAdminCopilotDemoSeeder::class);

        // Reusable assessment questions (IT Risk → Question Library)
        $this->call(QuestionLibrarySeeder::class);

        // One test login per role (password: Password@123) — attached to
        // Kano Heritage Bank so every role sees populated modules
        $this->call(ReportsIntegrationsSettingsDemoSeeder::class);

        $this->call(RoleDemoUsersSeeder::class);

        // Integrated CAPA (IT-GOV-CAPA-001): last, because CCM, KRI, drift and core banking findings are
        // raised by the seeders above. Moves Kano's register onto the six-stage workflow across all three
        // framework paths, routes actions through the change register, and fires the CPR §6.0 trigger
        $this->call(CapaDemoSeeder::class);
    }
}
