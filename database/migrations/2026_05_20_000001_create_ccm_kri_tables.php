<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ccm_tests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('framework_refs')->nullable();
            $table->string('cadence_cron')->default('0 2 * * *');
            $table->enum('default_severity', ['critical', 'high', 'moderate', 'low'])->default('moderate');
            $table->string('adapter_key', 64);
            $table->json('parameters')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ccm_tenant_tests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('ccm_test_id')->constrained('ccm_tests')->cascadeOnDelete();
            $table->json('overrides')->nullable();
            $table->enum('status', ['enabled', 'disabled', 'paused'])->default('enabled');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->enum('last_status', ['pass', 'fail', 'warn', 'error'])->nullable();
            $table->timestamps();
        });

        Schema::create('ccm_test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_test_id')->constrained('ccm_tenant_tests')->cascadeOnDelete();
            $table->enum('status', ['pass', 'fail', 'warn', 'error']);
            $table->json('evidence_ids')->nullable();
            $table->text('output_summary')->nullable();
            $table->timestamp('ran_at')->useCurrent();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();
        });

        Schema::create('evidence_vault', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('file_path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('mime', 128)->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->boolean('worm_locked')->default(false);
            $table->string('category', 32)->default('general');
            $table->timestamps();
        });

        Schema::create('kris', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 64);
            $table->decimal('threshold_green', 12, 4)->nullable();
            $table->decimal('threshold_amber', 12, 4)->nullable();
            $table->decimal('threshold_red', 12, 4)->nullable();
            $table->enum('direction', ['higher_worse', 'lower_worse'])->default('higher_worse');
            $table->string('unit', 32)->default('count');
            $table->timestamps();
        });

        Schema::create('kri_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kri_id')->constrained('kris')->cascadeOnDelete();
            $table->string('period', 16);
            $table->decimal('value', 14, 4);
            $table->enum('status', ['green', 'amber', 'red'])->default('green');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('kri_breaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kri_id')->constrained('kris')->cascadeOnDelete();
            $table->foreignId('reading_id')->nullable()->constrained('kri_readings')->nullOnDelete();
            $table->enum('level', ['amber', 'red']);
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('board_pack_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('sections');
            $table->boolean('navy_variant')->default(true);
            $table->timestamps();
        });

        Schema::create('board_pack_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('template_id')->constrained('board_pack_templates');
            $table->string('pptx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('period', 16);
            $table->string('status', 32)->default('draft');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier', 32)->unique();
            $table->string('name');
            $table->decimal('annual_ngn', 14, 2)->default(0);
            $table->decimal('annual_usd', 14, 2)->default(0);
            $table->json('features');
            $table->json('limits');
            $table->boolean('is_popular')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_tiers');
        Schema::dropIfExists('board_pack_runs');
        Schema::dropIfExists('board_pack_templates');
        Schema::dropIfExists('kri_breaches');
        Schema::dropIfExists('kri_readings');
        Schema::dropIfExists('kris');
        Schema::dropIfExists('evidence_vault');
        Schema::dropIfExists('ccm_test_runs');
        Schema::dropIfExists('ccm_tenant_tests');
        Schema::dropIfExists('ccm_tests');
    }
};
