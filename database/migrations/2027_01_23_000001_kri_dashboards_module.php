<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * KRIs & Dashboards (ATH-VAL-014).
 *
 * - KRIs gain an owner, the risk they indicate, a reporting frequency and their source (recorded by
 *   hand, measured from platform data, or published from EA); one code per bank.
 * - Readings and breaches carry their bank; a reading records who entered it and a commentary; a
 *   breach records the owner's action plan and the issue a red breach raised.
 * - Board-pack runs hold a snapshot of the bank's figures at generation, who prepared it and who
 *   approved it. Runs that were only fake file paths (organisation 1, "demo" PPTX/PDF) are removed.
 */
return new class extends Migration
{
    private const GRANTS = [
        // The CRO prepares the board packs (ATH-VAL-014).
        'Risk Manager' => ['create reports'],
    ];

    public function up(): void
    {
        Schema::table('kris', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('organization_id')->constrained('users')->nullOnDelete();
            $table->foreignId('risk_id')->nullable()->after('owner_id')->constrained('risks')->nullOnDelete();
            $table->string('frequency', 16)->default('weekly')->after('unit');
            $table->string('source', 16)->default('manual')->after('frequency');
        });
        DB::table('kris')->where('category', 'Enterprise Architecture')->update(['source' => 'ea']);
        Schema::table('kris', function (Blueprint $table) {
            $table->unique(['organization_id', 'code']);
        });

        foreach (['kri_readings', 'kri_breaches'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id')->index();
            });
        }
        Schema::table('kri_readings', function (Blueprint $table) {
            $table->text('commentary')->nullable()->after('status');
            $table->foreignId('recorded_by')->nullable()->after('commentary')->constrained('users')->nullOnDelete();
        });
        Schema::table('kri_breaches', function (Blueprint $table) {
            $table->text('action_plan')->nullable()->after('notes');
            $table->foreignId('acknowledged_by')->nullable()->after('action_plan')->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by');
            $table->unsignedBigInteger('issue_id')->nullable()->after('acknowledged_at');
        });
        foreach (DB::table('kris')->get(['id', 'organization_id']) as $kri) {
            DB::table('kri_readings')->where('kri_id', $kri->id)->update(['organization_id' => $kri->organization_id]);
            DB::table('kri_breaches')->where('kri_id', $kri->id)->update(['organization_id' => $kri->organization_id]);
        }

        Schema::table('board_pack_runs', function (Blueprint $table) {
            $table->string('title')->nullable()->after('template_id');
            $table->json('content')->nullable()->after('period');
            $table->foreignId('prepared_by')->nullable()->after('content')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('prepared_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('notes')->nullable()->after('approved_at');
        });
        DB::table('board_pack_runs')->whereNull('content')
            ->where(fn ($q) => $q->where('organization_id', 1)->orWhere('pptx_path', 'like', 'board-packs/demo-%')->orWhere('pptx_path', 'like', 'board-packs/dashboard-export-%'))
            ->delete();

        $this->grant();
    }

    private function grant(): void
    {
        foreach (self::GRANTS as $role => $permissions) {
            $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($permissions as $name) {
                $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
                if ($permissionId && ! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('board_pack_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prepared_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['title', 'content', 'approved_at', 'notes']);
        });
        Schema::table('kri_breaches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['organization_id', 'action_plan', 'acknowledged_at', 'issue_id']);
        });
        Schema::table('kri_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['organization_id', 'commentary']);
        });
        Schema::table('kris', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'code']);
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('risk_id');
            $table->dropColumn(['frequency', 'source']);
        });
    }
};
