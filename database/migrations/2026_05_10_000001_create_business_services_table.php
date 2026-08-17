<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_capabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('business_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('capability_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->enum('criticality', ['critical', 'high', 'medium', 'low'])->default('medium');
            $table->unsignedInteger('recovery_time_objective_min')->nullable();
            $table->unsignedInteger('recovery_point_objective_min')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('business_processes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->enum('criticality', ['critical', 'high', 'medium', 'low'])->default('medium');
            $table->timestamps();
        });

        Schema::create('service_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->string('relation_type')->default('depends_on');
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_dependencies');
        Schema::dropIfExists('business_processes');
        Schema::dropIfExists('business_services');
        Schema::dropIfExists('business_capabilities');
    }
};
