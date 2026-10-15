<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * IT Financial Management permissions and roles (ATH-FIN-001 §8).
 *
 * RolesAndPermissionsSeeder makes the same grants on a fresh seed; this applies them to an
 * existing database. Idempotent: permissions and roles are created if missing and every grant
 * is inserted only when absent.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'view it-finance', 'create it-finance', 'edit it-finance', 'delete it-finance', 'approve it-finance', 'export it-finance',
        'approve it-finance-invoices', 'approve it-finance-investments', 'approve it-finance-contracts',
        'close it-finance-periods', 'manage it-finance-settings', 'view it-finance-amounts', 'import it-finance',
    ];

    private const GRANTS = [
        'Super Admin' => ['*'],
        'Organization Admin' => ['*'],
        'IT Budget Owner' => ['view it-finance', 'create it-finance', 'edit it-finance', 'export it-finance'],
        'IT Finance Controller' => ['*'],
        'Procurement Officer' => ['view it-finance', 'create it-finance', 'edit it-finance', 'import it-finance', 'view it-finance-amounts', 'view vendors'],
        'Investment Sponsor' => ['view it-finance', 'approve it-finance-investments', 'view it-finance-amounts'],
        'IT Steering Committee' => ['view it-finance', 'approve it-finance-investments', 'view it-finance-amounts'],
        'Finance Approver' => ['view it-finance', 'export it-finance', 'approve it-finance', 'approve it-finance-invoices', 'view it-finance-amounts'],
        'Auditor' => ['view it-finance', 'export it-finance', 'view it-finance-amounts'],
        'Viewer' => ['view it-finance'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            $this->permissionId($name, true);
        }

        foreach (array_keys(self::GRANTS) as $role) {
            if (in_array($role, ['IT Budget Owner', 'IT Finance Controller', 'Procurement Officer', 'Investment Sponsor', 'IT Steering Committee', 'Finance Approver'], true)
                && ! DB::table('roles')->where('name', $role)->where('guard_name', 'web')->exists()) {
                DB::table('roles')->insert(['name' => $role, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach (self::GRANTS as $role => $grants) {
            $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            $names = $grants === ['*'] ? self::PERMISSIONS : $grants;
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
