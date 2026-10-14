<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Continuous Monitoring (ATH-VAL-013).
 *
 * - CCM tests per bank gain a control, an owner and the issue a failure raised; runs carry their
 *   bank, how they were measured (platform data or sandbox adapter), the measured value against
 *   the threshold, and findings. One enabled copy of each test per bank.
 * - A configuration-drift register replaces the page that generated 30 random events per request.
 * - Access-review campaigns with per-entitlement decisions replace the hard-coded list.
 */
return new class extends Migration
{
    private const GRANTS = [
        'Compliance Officer' => ['view monitoring', 'create monitoring', 'edit monitoring', 'approve monitoring'],
        'Risk Manager' => ['view monitoring'],
    ];

    public function up(): void
    {
        Schema::table('ccm_tenant_tests', function (Blueprint $table) {
            $table->foreignId('control_id')->nullable()->after('ccm_test_id')->constrained('controls')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('control_id')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('issue_id')->nullable()->after('owner_id');
            $table->string('last_value')->nullable()->after('last_status');
        });

        Schema::table('ccm_test_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('id')->index();
            $table->string('mode', 16)->default('sandbox')->after('status');
            $table->decimal('measured_value', 14, 2)->nullable()->after('mode');
            $table->string('threshold')->nullable()->after('measured_value');
            $table->json('findings')->nullable()->after('threshold');
            $table->foreignId('triggered_by')->nullable()->after('findings')->constrained('users')->nullOnDelete();
        });

        // Runs belong to the bank of their test; duplicate copies of a test in one bank are merged.
        foreach (DB::table('ccm_tenant_tests')->get(['id', 'organization_id']) as $tt) {
            DB::table('ccm_test_runs')->where('tenant_test_id', $tt->id)->update(['organization_id' => $tt->organization_id]);
        }
        DB::table('ccm_tenant_tests')->whereNotNull('organization_id')->orderBy('id')->get(['id', 'organization_id', 'ccm_test_id'])
            ->groupBy(fn ($r) => $r->organization_id.'-'.$r->ccm_test_id)
            ->each(function ($copies) {
                $keep = $copies->first()->id;
                foreach ($copies->slice(1) as $dup) {
                    DB::table('ccm_test_runs')->where('tenant_test_id', $dup->id)->update(['tenant_test_id' => $keep]);
                    DB::table('ccm_tenant_tests')->where('id', $dup->id)->delete();
                }
            });
        Schema::table('ccm_tenant_tests', function (Blueprint $table) {
            $table->unique(['organization_id', 'ccm_test_id']);
        });

        Schema::create('config_drift_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('drift_code', 32);
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('source', 32);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('baseline_value')->nullable();
            $table->string('observed_value')->nullable();
            $table->string('severity', 16)->default('medium');
            $table->timestamp('detected_at');
            $table->string('status', 16)->default('open');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('change_request_id')->nullable()->index();
            $table->unsignedBigInteger('issue_id')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->date('accepted_until')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'drift_code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('access_review_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('campaign_code', 32);
            $table->string('name');
            $table->string('system');
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('category', 24)->default('application');
            $table->string('source', 16)->default('manual');
            $table->string('cadence', 16)->default('quarterly');
            $table->string('status', 16)->default('in_progress');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'campaign_code']);
        });

        Schema::create('access_review_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('campaign_id')->constrained('access_review_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_name');
            $table->string('entitlement');
            $table->boolean('privileged')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 16)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revocation_confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'decision']);
        });

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
        Schema::dropIfExists('access_review_items');
        Schema::dropIfExists('access_review_campaigns');
        Schema::dropIfExists('config_drift_events');
        Schema::table('ccm_tenant_tests', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'ccm_test_id']);
            $table->dropConstrainedForeignId('control_id');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['issue_id', 'last_value']);
        });
        Schema::table('ccm_test_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('triggered_by');
            $table->dropColumn(['organization_id', 'mode', 'measured_value', 'threshold', 'findings']);
        });
    }
};
