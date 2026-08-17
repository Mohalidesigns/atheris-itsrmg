<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    protected const ACTIONS = ['view', 'create', 'edit', 'delete', 'approve', 'export'];

    protected const MODULE_GROUPS = [
        'Risk Management' => [
            'risks' => 'Risks',
            'risk-assessments' => 'Risk Assessments',
            'risk-treatments' => 'Risk Treatments',
            'threats' => 'Threat Register',
        ],
        'Compliance' => [
            'controls' => 'Control Library',
            'frameworks' => 'Regulatory Frameworks',
            'compliance-assessments' => 'Compliance Assessments',
            'evidence' => 'Evidence Repository',
            'gap-analysis' => 'Gap Analysis',
            'isms' => 'ISMS',
            'pci' => 'PCI Management',
        ],
        'Security Operations' => [
            'vulnerabilities' => 'Vulnerabilities',
            'vulnerability-tickets' => 'Vulnerability Tickets',
            'incidents' => 'Incidents',
            'security-alerts' => 'Security Alerts',
            'data-breaches' => 'Data Breaches',
            'monitoring' => 'Continuous Monitoring',
        ],
        'Assets & Vendors' => [
            'assets' => 'IT Assets',
            'vendors' => 'Vendors',
            'vendor-assessments' => 'Vendor Assessments',
        ],
        'Governance' => [
            'policies' => 'Policies',
            'policy-attestations' => 'Policy Attestations',
            'bcp-plans' => 'BCP / DR Plans',
            'reports' => 'Reports & Analytics',
            'issues' => 'Issues & Remediation',
            'csat' => 'CBN Cyber Assessment (CSAT)',
            'ea' => 'Enterprise Architecture',
        ],
        'Administration' => [
            'users' => 'Users',
            'roles' => 'Roles & Permissions',
            'organizations' => 'Organization Settings',
            'audit-trail' => 'Audit Trail',
            'platform' => 'Platform Features',
        ],
    ];

    protected function permissionGroups(): array
    {
        $existing = Permission::pluck('name')->flip();

        $groups = [];
        foreach (self::MODULE_GROUPS as $group => $modules) {
            $rows = [];
            foreach ($modules as $key => $label) {
                $permissions = [];
                foreach (self::ACTIONS as $action) {
                    $name = "{$action} {$key}";
                    if (isset($existing[$name])) {
                        $permissions[$action] = $name;
                    }
                }
                if ($permissions !== []) {
                    $rows[] = [
                        'module' => $key,
                        'label' => $label,
                        'permissions' => $permissions,
                    ];
                }
            }
            if ($rows !== []) {
                $groups[] = ['group' => $group, 'modules' => $rows];
            }
        }

        return $groups;
    }

    public function index()
    {
        $roles = Role::withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get(['id', 'name', 'created_at']);

        return Inertia::render('Admin/Roles/Index', [
            'roles' => $roles,
            'totalPermissions' => Permission::count(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Roles/Create', [
            'permissionGroups' => $this->permissionGroups(),
            'actions' => self::ACTIONS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:125|unique:roles,name',
            'permissions' => 'array',
            'permissions.*' => [Rule::exists('permissions', 'name')],
        ]);

        $role = Role::create(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions'] ?? []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.roles.index')
            ->with('success', "Role \"{$role->name}\" created successfully.");
    }

    public function show(Role $role)
    {
        return Inertia::render('Admin/Roles/Show', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users' => $role->users()->get(['id', 'name', 'email', 'job_title'])
                    ->map(fn ($u) => $u->only(['id', 'name', 'email', 'job_title'])),
            ],
            'permissionGroups' => $this->permissionGroups(),
            'actions' => self::ACTIONS,
        ]);
    }

    public function edit(Role $role)
    {
        if ($role->name === 'Super Admin') {
            return redirect()->route('admin.roles.index')
                ->with('error', 'The Super Admin role cannot be modified.');
        }

        return Inertia::render('Admin/Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users()->count(),
            ],
            'permissionGroups' => $this->permissionGroups(),
            'actions' => self::ACTIONS,
        ]);
    }

    public function update(Request $request, Role $role)
    {
        if ($role->name === 'Super Admin') {
            return back()->with('error', 'The Super Admin role cannot be modified.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:125', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => 'array',
            'permissions.*' => [Rule::exists('permissions', 'name')],
        ]);

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions'] ?? []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.roles.index')
            ->with('success', "Role \"{$role->name}\" updated successfully.");
    }

    public function destroy(Role $role)
    {
        if ($role->name === 'Super Admin') {
            return back()->with('error', 'The Super Admin role cannot be deleted.');
        }

        if ($role->users()->count() > 0) {
            return back()->with('error', "Role \"{$role->name}\" is assigned to {$role->users()->count()} user(s). Reassign those users to another role first.");
        }

        $name = $role->name;
        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.roles.index')
            ->with('success', "Role \"{$name}\" deleted successfully.");
    }
}
