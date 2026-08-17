<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('version')->default('1.0');
            $table->json('definition_json');
            $table->string('status', 32)->default('draft');
            $table->string('category', 64)->default('general');
            $table->timestamps();
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('state_json')->nullable();
            $table->string('status', 32)->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->string('key', 64);
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('outcome', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamps();
        });

        Schema::create('core_banking_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->enum('product', ['finacle', 'flexcube', 't24', 'bankone', 'interswitch', 'nibss'])->default('finacle');
            $table->json('config')->nullable();
            $table->string('status', 32)->default('connected');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });

        Schema::create('core_banking_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('product', 32);
            $table->timestamp('captured_at');
            $table->json('artefacts');
            $table->timestamps();
        });

        Schema::create('dr_runbooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->json('steps');
            $table->unsignedInteger('estimated_duration_min')->default(60);
            $table->string('owner_role', 64)->nullable();
            $table->string('category', 64)->default('general');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('dr_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('runbook_id')->constrained('dr_runbooks')->cascadeOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('participants')->nullable();
            $table->json('evidence_ids')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dr_exercises');
        Schema::dropIfExists('dr_runbooks');
        Schema::dropIfExists('core_banking_snapshots');
        Schema::dropIfExists('core_banking_integrations');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflows');
    }
};
