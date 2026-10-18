<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** Plan P5-6 (C131): the Finance Approver role may decide investment gates ("approve it-finance-investments"). */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'Finance Approver')->where('guard_name', 'web')->value('id');
        $permissionId = DB::table('permissions')->where('name', 'approve it-finance-investments')->where('guard_name', 'web')->value('id');
        if ($roleId && $permissionId
            && ! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'Finance Approver')->where('guard_name', 'web')->value('id');
        $permissionId = DB::table('permissions')->where('name', 'approve it-finance-investments')->where('guard_name', 'web')->value('id');
        if ($roleId && $permissionId) {
            DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
