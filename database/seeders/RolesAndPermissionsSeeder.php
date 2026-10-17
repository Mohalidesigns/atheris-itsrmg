<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const FINANCE_EXTRA_PERMISSIONS = [
        'approve it-finance-invoices', 'approve it-finance-investments', 'approve it-finance-contracts',
        'close it-finance-periods', 'manage it-finance-settings', 'view it-finance-amounts', 'import it-finance',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $modules = [
            'risks',
            'risk-assessments',
            'risk-treatments',
            'threats',
            'controls',
            'frameworks',
            'compliance-assessments',
            'evidence',
            'gap-analysis',
            'vulnerabilities',
            'vulnerability-tickets',
            'incidents',
            'security-alerts',
            'data-breaches',
            'assets',
            'vendors',
            'vendor-assessments',
            'policies',
            'policy-attestations',
            'bcp-plans',
            'isms',
            'pci',
            'monitoring',
            'reports',
            'issues',
            'changes',
            'users',
            'organizations',
            'audit-trail',
            'roles',
            'ea',
            'csat',
            'platform',
            // Workflow Studio: who initiates and who approves each process (ATH-VAL-016).
            'workflows',
            // IT Financial Management (ATH-FIN-001 §8).
            'it-finance',
        ];

        $actions = ['view', 'create', 'edit', 'delete', 'approve', 'export'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "{$action} {$module}"]);
            }
        }

        // IT Financial Management: permissions beyond the standard six (ATH-FIN-001 §8).
        foreach (self::FINANCE_EXTRA_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // Super Admin - everything
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // Organization Admin - everything except org management
        $orgAdmin = Role::firstOrCreate(['name' => 'Organization Admin']);
        $orgAdmin->givePermissionTo(Permission::all());

        // Risk Manager - risk + threat + treatment modules
        $riskManager = Role::firstOrCreate(['name' => 'Risk Manager']);
        $riskModules = ['risks', 'risk-assessments', 'risk-treatments', 'threats', 'issues'];
        $riskManager->givePermissionTo($this->crossGrant($actions, $riskModules));
        $riskManager->givePermissionTo([
            'view controls', 'view frameworks', 'view assets', 'view reports',
            'view vulnerabilities', 'view incidents',
            'view ea', 'view platform',
            // Change Management: sits on the CAB for Risk (ATH-CHG-001 §6).
            'view changes', 'create changes', 'edit changes', 'approve changes',
            // Vendor Management: the bank's Vendor Risk Manager holds this role (ATH-VAL-008).
            'view vendors', 'create vendors', 'edit vendors',
            'view vendor-assessments', 'create vendor-assessments', 'edit vendor-assessments',
            // Continuous Monitoring: reads CCM results and drift against the risk register (ATH-VAL-013).
            'view monitoring',
            'view workflows',
            // KRIs & Dashboards: the CRO prepares the board packs (ATH-VAL-014).
            'create reports',
        ]);

        // Compliance Officer - compliance + controls + evidence + frameworks
        $complianceOfficer = Role::firstOrCreate(['name' => 'Compliance Officer']);
        $complianceModules = ['controls', 'frameworks', 'compliance-assessments', 'evidence', 'gap-analysis', 'policies', 'policy-attestations', 'isms', 'pci', 'issues'];
        $complianceOfficer->givePermissionTo($this->crossGrant($actions, $complianceModules));
        $complianceOfficer->givePermissionTo([
            'view risks', 'view assets', 'view reports', 'view vulnerabilities',
            'view csat', 'create csat', 'edit csat',
            // The DPCO holds this role and needs the processor register (NDPA s.29, ATH-VAL-008).
            'view vendors', 'view vendor-assessments',
            // Regulatory Intelligence: the Head of Compliance triages circulars, reviews extracted
            // documents and prepares returns (ATH-VAL-004).
            'view platform', 'create platform', 'edit platform',
            // Change Management: CAB member for Compliance.
            'view changes', 'create changes', 'approve changes',
            // Continuous Monitoring: runs access-review campaigns and owns CCM evidence (ATH-VAL-013).
            'view monitoring', 'create monitoring', 'edit monitoring', 'approve monitoring',
            'view workflows',
        ]);

        // Security Analyst - security operations
        $securityAnalyst = Role::firstOrCreate(['name' => 'Security Analyst']);
        $secModules = ['vulnerabilities', 'vulnerability-tickets', 'incidents', 'security-alerts', 'data-breaches', 'monitoring', 'issues'];
        $securityAnalyst->givePermissionTo($this->crossGrant($actions, $secModules));
        $securityAnalyst->givePermissionTo([
            'view risks', 'view controls', 'view assets', 'view reports',
            'view platform',
            'view changes', 'create changes', 'edit changes',
        ]);

        // Auditor - view + export on everything, create/edit on evidence and issues
        $auditor = Role::firstOrCreate(['name' => 'Auditor']);
        $auditor->givePermissionTo(array_merge(
            $this->crossGrant(['view', 'export'], $modules),
            $this->crossGrant(['create', 'edit'], ['evidence', 'issues', 'compliance-assessments']),
        ));

        // Viewer - view only
        $viewer = Role::firstOrCreate(['name' => 'Viewer']);
        $viewer->givePermissionTo($this->crossGrant(['view'], $modules));

        $this->seedArchitectRoles($actions);
        $this->seedChangeManagerRole($actions);
        $this->seedFinanceRoles($actions);
    }

    /**
     * Change Management (ATH-CHG-001 §6): the Change Manager is the change authority — every
     * `changes` permission plus read access to the records a change is raised against.
     */
    private function seedChangeManagerRole(array $actions): void
    {
        $changeManager = Role::firstOrCreate(['name' => 'Change Manager']);
        $changeManager->givePermissionTo($this->crossGrant($actions, ['changes']));
        $changeManager->givePermissionTo([
            'view assets', 'view risks', 'view incidents', 'view vulnerabilities',
            'view issues', 'view ea', 'view platform',
        ]);
    }

    /**
     * The architect personas.
     *
     * ATH-EAR-002 §2.3 (RC-3): nobody in the system was an architect. Only
     * Super Admin and Organization Admin held `create ea`, which meant the
     * people who should populate and maintain an architecture repository had
     * no seat, and the people who did have write access were the two roles
     * that should not be doing data entry. EA is a crowdsourced discipline —
     * these four roles are the identity spine the survey engine and quality
     * seal (Phase 1, B1–B3) will be built on.
     */
    private function seedArchitectRoles(array $actions): void
    {
        // Enterprise Architect — owns the repository end to end, including the
        // governance loop (ARB decisions, exception approval) and the external
        // data feeds.
        $enterpriseArchitect = Role::firstOrCreate(['name' => 'Enterprise Architect']);
        $enterpriseArchitect->givePermissionTo($this->crossGrant($actions, ['ea']));
        $enterpriseArchitect->givePermissionTo([
            'view risks', 'view controls', 'view frameworks', 'view assets',
            'view vendors', 'view vulnerabilities', 'view incidents',
            'view bcp-plans', 'view reports', 'view csat', 'view platform',
            'view issues', 'create issues', 'edit issues',
            'view changes', 'create changes', 'edit changes',
        ]);

        // Solution Architect — authors solution designs and submits them to the
        // ARB; may create and edit catalogue entries but neither deletes them
        // nor approves its own submissions (segregation of duties).
        $solutionArchitect = Role::firstOrCreate(['name' => 'Solution Architect']);
        $solutionArchitect->givePermissionTo(['view ea', 'create ea', 'edit ea', 'export ea']);
        $solutionArchitect->givePermissionTo([
            'view risks', 'view controls', 'view assets', 'view vendors',
            'view reports', 'view issues', 'create issues',
            'view changes', 'create changes', 'edit changes',
        ]);

        // Application Owner — accountable for their own applications. Read and
        // edit, no create/delete: owners maintain facts about existing entries,
        // they do not shape the catalogue.
        $applicationOwner = Role::firstOrCreate(['name' => 'Application Owner']);
        $applicationOwner->givePermissionTo(['view ea', 'edit ea', 'export ea']);
        $applicationOwner->givePermissionTo([
            'view assets', 'view vendors', 'view incidents', 'view issues',
            // Business-owner approval stage on changes to their applications.
            'view changes', 'create changes', 'edit changes', 'approve changes',
        ]);

        // Data Steward — maintains the data architecture layer (logical
        // entities, flows, classification, DPIA) and the glossary.
        $dataSteward = Role::firstOrCreate(['name' => 'Data Steward']);
        $dataSteward->givePermissionTo(['view ea', 'create ea', 'edit ea', 'export ea']);
        $dataSteward->givePermissionTo([
            'view risks', 'view controls', 'view data-breaches',
            'view policies', 'view reports', 'view issues', 'create issues',
            'view changes', 'create changes', 'edit changes',
        ]);
    }

    /**
     * IT Financial Management roles (ATH-FIN-001 §8). Roles are global; banks assign them to users.
     */
    private function seedFinanceRoles(array $actions): void
    {
        $grants = [
            'IT Budget Owner' => ['view it-finance', 'create it-finance', 'edit it-finance', 'export it-finance', 'view it-finance-amounts', 'view workflows'],
            'IT Finance Controller' => array_merge(
                array_map(fn ($a) => "{$a} it-finance", $actions),
                self::FINANCE_EXTRA_PERMISSIONS,
            ),
            'Procurement Officer' => ['view it-finance', 'create it-finance', 'edit it-finance', 'import it-finance', 'view it-finance-amounts', 'view vendors'],
            'Investment Sponsor' => ['view it-finance', 'approve it-finance-investments', 'view it-finance-amounts'],
            'IT Steering Committee' => ['view it-finance', 'approve it-finance-investments', 'view it-finance-amounts'],
            'Finance Approver' => ['view it-finance', 'export it-finance', 'approve it-finance', 'approve it-finance-invoices', 'approve it-finance-contracts', 'view it-finance-amounts'],
        ];
        foreach ($grants as $role => $permissions) {
            Role::firstOrCreate(['name' => $role])->givePermissionTo($permissions);
        }

        Role::firstOrCreate(['name' => 'Auditor'])->givePermissionTo('view it-finance-amounts');
    }

    /**
     * Every "{action} {module}" pair, so each role is granted in one call. Each
     * givePermissionTo() flushes the permission cache and the next string lookup
     * rebuilds it, so per-permission calls made this seeder take ~15 s per test.
     *
     * @return list<string>
     */
    private function crossGrant(array $actions, array $modules): array
    {
        $names = [];
        foreach ($modules as $module) {
            foreach ($actions as $action) {
                $names[] = "{$action} {$module}";
            }
        }

        return $names;
    }
}
