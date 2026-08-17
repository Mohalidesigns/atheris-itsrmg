<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
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
            'users',
            'organizations',
            'audit-trail',
            'roles',
            'ea',
            'csat',
            'platform',
        ];

        $actions = ['view', 'create', 'edit', 'delete', 'approve', 'export'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "{$action} {$module}"]);
            }
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
        foreach ($riskModules as $module) {
            foreach ($actions as $action) {
                $riskManager->givePermissionTo("{$action} {$module}");
            }
        }
        $riskManager->givePermissionTo([
            'view controls', 'view frameworks', 'view assets', 'view reports',
            'view vulnerabilities', 'view incidents',
            'view ea', 'view platform',
        ]);

        // Compliance Officer - compliance + controls + evidence + frameworks
        $complianceOfficer = Role::firstOrCreate(['name' => 'Compliance Officer']);
        $complianceModules = ['controls', 'frameworks', 'compliance-assessments', 'evidence', 'gap-analysis', 'policies', 'policy-attestations', 'isms', 'pci', 'issues'];
        foreach ($complianceModules as $module) {
            foreach ($actions as $action) {
                $complianceOfficer->givePermissionTo("{$action} {$module}");
            }
        }
        $complianceOfficer->givePermissionTo([
            'view risks', 'view assets', 'view reports', 'view vulnerabilities',
            'view csat', 'create csat', 'edit csat',
        ]);

        // Security Analyst - security operations
        $securityAnalyst = Role::firstOrCreate(['name' => 'Security Analyst']);
        $secModules = ['vulnerabilities', 'vulnerability-tickets', 'incidents', 'security-alerts', 'data-breaches', 'monitoring', 'issues'];
        foreach ($secModules as $module) {
            foreach ($actions as $action) {
                $securityAnalyst->givePermissionTo("{$action} {$module}");
            }
        }
        $securityAnalyst->givePermissionTo([
            'view risks', 'view controls', 'view assets', 'view reports',
            'view platform',
        ]);

        // Auditor - view + export on everything, create/edit on evidence and issues
        $auditor = Role::firstOrCreate(['name' => 'Auditor']);
        foreach ($modules as $module) {
            $auditor->givePermissionTo("view {$module}");
            $auditor->givePermissionTo("export {$module}");
        }
        foreach (['evidence', 'issues', 'compliance-assessments'] as $m) {
            $auditor->givePermissionTo(["create {$m}", "edit {$m}"]);
        }

        // Viewer - view only
        $viewer = Role::firstOrCreate(['name' => 'Viewer']);
        foreach ($modules as $module) {
            $viewer->givePermissionTo("view {$module}");
        }

        $this->seedArchitectRoles($actions);
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
        foreach ($actions as $action) {
            $enterpriseArchitect->givePermissionTo("{$action} ea");
        }
        $enterpriseArchitect->givePermissionTo([
            'view risks', 'view controls', 'view frameworks', 'view assets',
            'view vendors', 'view vulnerabilities', 'view incidents',
            'view bcp-plans', 'view reports', 'view csat', 'view platform',
            'view issues', 'create issues', 'edit issues',
        ]);

        // Solution Architect — authors solution designs and submits them to the
        // ARB; may create and edit catalogue entries but neither deletes them
        // nor approves its own submissions (segregation of duties).
        $solutionArchitect = Role::firstOrCreate(['name' => 'Solution Architect']);
        $solutionArchitect->givePermissionTo(['view ea', 'create ea', 'edit ea', 'export ea']);
        $solutionArchitect->givePermissionTo([
            'view risks', 'view controls', 'view assets', 'view vendors',
            'view reports', 'view issues', 'create issues',
        ]);

        // Application Owner — accountable for their own applications. Read and
        // edit, no create/delete: owners maintain facts about existing entries,
        // they do not shape the catalogue.
        $applicationOwner = Role::firstOrCreate(['name' => 'Application Owner']);
        $applicationOwner->givePermissionTo(['view ea', 'edit ea', 'export ea']);
        $applicationOwner->givePermissionTo([
            'view assets', 'view vendors', 'view incidents', 'view issues',
        ]);

        // Data Steward — maintains the data architecture layer (logical
        // entities, flows, classification, DPIA) and the glossary.
        $dataSteward = Role::firstOrCreate(['name' => 'Data Steward']);
        $dataSteward->givePermissionTo(['view ea', 'create ea', 'edit ea', 'export ea']);
        $dataSteward->givePermissionTo([
            'view risks', 'view controls', 'view data-breaches',
            'view policies', 'view reports', 'view issues', 'create issues',
        ]);
    }
}
