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

        // Reusable assessment questions (IT Risk → Question Library)
        $this->call(QuestionLibrarySeeder::class);

        // One test login per role (password: Password@123) — attached to
        // Kano Heritage Bank so every role sees populated modules
        $this->call(RoleDemoUsersSeeder::class);
    }
}
