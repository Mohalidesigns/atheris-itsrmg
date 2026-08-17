<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aucs_controls', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('domain');
            $table->string('sub_domain')->nullable();
            $table->text('objective_text')->nullable();
            $table->string('version')->default('1.0');
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('framework_clauses', function (Blueprint $table) {
            $table->id();
            $table->string('framework_code', 32);
            $table->string('version');
            $table->string('clause_path');
            $table->string('title');
            $table->longText('body_markdown')->nullable();
            $table->string('source_url')->nullable();
            $table->date('effective_date')->nullable();
            $table->timestamps();

            $table->index(['framework_code', 'version']);
        });

        Schema::create('aucs_framework_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aucs_control_id')->constrained('aucs_controls')->cascadeOnDelete();
            $table->foreignId('framework_clause_id')->constrained('framework_clauses')->cascadeOnDelete();
            $table->enum('mapping_strength', ['full', 'partial', 'related'])->default('partial');
            $table->text('mapping_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_control_adoptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('aucs_control_id')->constrained('aucs_controls')->cascadeOnDelete();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->enum('implementation_status', ['not_started', 'in_progress', 'implemented', 'tested', 'optimised'])->default('not_started');
            $table->enum('effectiveness_rating', ['effective', 'partially_effective', 'ineffective', 'not_tested'])->default('not_tested');
            $table->text('evidence_policy')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_control_adoptions');
        Schema::dropIfExists('aucs_framework_mappings');
        Schema::dropIfExists('framework_clauses');
        Schema::dropIfExists('aucs_controls');
    }
};
