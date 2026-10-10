<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Head of Compliance is the primary user of Regulatory Intelligence, but the Compliance Officer
 * role held no `platform` permission and could not open the module. RolesAndPermissionsSeeder grants
 * it on a fresh seed; this applies the same grant to an existing database.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['view platform', 'create platform', 'edit platform'];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'Compliance Officer')->where('guard_name', 'web')->value('id');
        if (! $roleId) {
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            if (! $permissionId) {
                continue;
            }
            $exists = DB::table('role_has_permissions')
                ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();
            if (! $exists) {
                DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Revoking could remove a grant an administrator made deliberately; leave as is.
    }
};
