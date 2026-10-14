<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Workflow Studio (ATH-VAL-016).
 *
 * - Approval workflows: one configuration per bank and process (who may initiate, which stages
 *   approve, in what order, under what conditions), and the runtime record of each approval
 *   (request, its steps snapshotted from the stages, and every decision).
 * - The marketplace is removed from the product, with Pricing: content marketplace items and
 *   installs, pricing tiers, and the demo "workflow" rows the old Workflow Studio listed (workflows,
 *   instances and tasks no process ever ran).
 * - A `workflows` permission set: configuring who approves what is an administrative act.
 */
return new class extends Migration
{
    private const GRANTS = [
        'Super Admin' => ['view workflows', 'create workflows', 'edit workflows', 'delete workflows', 'approve workflows', 'export workflows'],
        'Organization Admin' => ['view workflows', 'create workflows', 'edit workflows', 'delete workflows', 'approve workflows', 'export workflows'],
        'Compliance Officer' => ['view workflows'],
        'Risk Manager' => ['view workflows'],
        'Auditor' => ['view workflows', 'export workflows'],
        'Viewer' => ['view workflows'],
    ];

    public function up(): void
    {
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('content_installs');
        Schema::dropIfExists('content_marketplace_items');
        Schema::dropIfExists('pricing_tiers');
        if (Schema::hasTable('feature_flags')) {
            DB::table('feature_flags')->whereIn('key', ['workflows.marketplace', 'marketplace.public'])->delete();
        }

        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('process', 64);
            $table->boolean('is_active')->default(true);
            $table->json('initiator_roles')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'process']);
        });

        Schema::create('approval_workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('name', 120);
            $table->string('approver_type', 16);
            $table->string('approver_ref')->nullable();
            $table->unsignedTinyInteger('min_approvals')->default(1);
            $table->unsignedSmallInteger('sla_hours')->default(72);
            $table->string('condition_field', 64)->nullable();
            $table->json('condition_values')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('workflow_id')->nullable()->constrained('approval_workflows')->nullOnDelete();
            $table->string('process', 64);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('outcome_note')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('approval_workflow_stages')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('name', 120);
            $table->string('approver_type', 16);
            $table->string('approver_ref')->nullable();
            $table->unsignedTinyInteger('min_approvals')->default(1);
            $table->string('status', 16)->default('pending');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('step_id')->constrained('approval_steps')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 16);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        $this->permissions();
    }

    private function permissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        foreach (['view', 'create', 'edit', 'delete', 'approve', 'export'] as $action) {
            if (! DB::table('permissions')->where('name', "{$action} workflows")->where('guard_name', 'web')->exists()) {
                DB::table('permissions')->insert(['name' => "{$action} workflows", 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
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
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_workflow_stages');
        Schema::dropIfExists('approval_workflows');
    }
};
