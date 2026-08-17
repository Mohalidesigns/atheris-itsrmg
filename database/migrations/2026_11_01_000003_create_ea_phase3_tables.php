<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ea_plateaux', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('plateau_type', 16)->default('current'); // current / target / transition
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ea_initiatives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('proposed'); // proposed/approved/in_flight/delivered/on_hold/cancelled
            $table->date('start_date')->nullable();
            $table->date('target_end_date')->nullable();
            $table->decimal('budget_ngn', 16, 2)->nullable();
            $table->foreignId('plateau_id')->nullable()->constrained('ea_plateaux')->nullOnDelete();
            $table->string('adm_phase', 32)->default('A'); // ADM A..H
            $table->json('linked_capabilities')->nullable();
            $table->json('linked_applications')->nullable();
            $table->json('linked_risks')->nullable();
            $table->json('linked_obligations')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->timestamps();
        });

        Schema::create('ea_patterns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 32)->default('integration');
            $table->text('intent')->nullable();
            $table->text('context')->nullable();
            $table->text('participants')->nullable();
            $table->text('forces')->nullable();
            $table->text('consequences')->nullable();
            $table->string('version')->default('1.0');
            $table->string('status', 32)->default('published');
            $table->unsignedInteger('adoption_count')->default(0);
            $table->timestamps();
        });

        Schema::create('ea_solutions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('pattern_id')->nullable()->constrained('ea_patterns')->nullOnDelete();
            $table->foreignId('initiative_id')->nullable()->constrained('ea_initiatives')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamps();
        });

        Schema::create('ea_kri_definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 32)->default('portfolio'); // portfolio/security/compliance/delivery/tech-debt
            $table->decimal('threshold_green', 10, 2)->nullable();
            $table->decimal('threshold_amber', 10, 2)->nullable();
            $table->decimal('threshold_red', 10, 2)->nullable();
            $table->string('direction', 16)->default('higher_worse');
            $table->string('unit', 16)->default('count');
            $table->timestamps();
        });

        Schema::create('ea_kri_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kri_id')->constrained('ea_kri_definitions')->cascadeOnDelete();
            $table->string('period', 16);
            $table->decimal('value', 12, 2);
            $table->string('status', 16)->default('green'); // green/amber/red
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('ea_exchange_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('type', 16); // import / export
            $table->string('format', 32)->default('archimate-oef-3.2');
            $table->string('status', 16)->default('queued'); // queued/running/completed/failed
            $table->string('file_path')->nullable();
            $table->unsignedInteger('element_count')->default(0);
            $table->unsignedInteger('relationship_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('ea_evidence_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('period', 16);
            $table->foreignId('assessment_id')->nullable()->constrained('ea_maturity_assessments')->nullOnDelete();
            $table->decimal('overall_score', 4, 2)->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('zip_path')->nullable();
            $table->string('status', 16)->default('generated');
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ea_evidence_packs');
        Schema::dropIfExists('ea_exchange_jobs');
        Schema::dropIfExists('ea_kri_values');
        Schema::dropIfExists('ea_kri_definitions');
        Schema::dropIfExists('ea_solutions');
        Schema::dropIfExists('ea_patterns');
        Schema::dropIfExists('ea_initiatives');
        Schema::dropIfExists('ea_plateaux');
    }
};
