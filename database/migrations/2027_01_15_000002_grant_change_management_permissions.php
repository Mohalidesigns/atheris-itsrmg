<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Change Management permissions and the Change Manager role (ATH-CHG-001 §6).
 *
 * RolesAndPermissionsSeeder makes the same grants on a fresh seed; this applies them to an
 * existing database. Idempotent: permissions and the role are created if missing and every
 * grant is inserted only when absent.
 */
return new class extends Migration
{
    private const ACTIONS = ['view', 'create', 'edit', 'delete', 'approve', 'export'];

    private const GRANTS = [
        'Super Admin' => ['*'],
        'Organization Admin' => ['*'],
        'Change Manager' => ['*', 'view assets', 'view risks', 'view incidents', 'view vulnerabilities', 'view issues', 'view ea', 'view platform'],
        'Risk Manager' => ['view changes', 'create changes', 'edit changes', 'approve changes'],
        'Compliance Officer' => ['view changes', 'create changes', 'approve changes'],
        'Security Analyst' => ['view changes', 'create changes', 'edit changes'],
        'Enterprise Architect' => ['view changes', 'create changes', 'edit changes'],
        'Solution Architect' => ['view changes', 'create changes', 'edit changes'],
        'Application Owner' => ['view changes', 'create changes', 'edit changes', 'approve changes'],
        'Data Steward' => ['view changes', 'create changes', 'edit changes'],
        'Auditor' => ['view changes', 'export changes'],
        'Viewer' => ['view changes'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ACTIONS as $action) {
            $this->permissionId("{$action} changes", true);
        }

        $roleId = DB::table('roles')->where('name', 'Change Manager')->where('guard_name', 'web')->value('id');
        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId(['name' => 'Change Manager', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (self::GRANTS as $role => $grants) {
            $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            $names = [];
            foreach ($grants as $grant) {
                if ($grant === '*') {
                    foreach (self::ACTIONS as $action) {
                        $names[] = "{$action} changes";
                    }
                } else {
                    $names[] = $grant;
                }
            }
            foreach ($names as $name) {
                $permissionId = $this->permissionId($name, false);
                if (! $permissionId) {
                    continue;
                }
                $exists = DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();
                if (! $exists) {
                    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Revoking could remove a grant an administrator made deliberately; leave as is.
    }

    private function permissionId(string $name, bool $create): ?int
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
        if (! $id && $create) {
            $id = DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id ? (int) $id : null;
    }
};
