<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('doc_intelligence_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('doc_type', 64);
            $table->string('source_file');
            $table->longText('ocr_text')->nullable();
            $table->json('structured_output')->nullable();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->enum('status', ['uploaded', 'ocr_pending', 'ocr_done', 'extraction_pending', 'extraction_done', 'awaiting_review', 'accepted', 'rejected'])->default('uploaded');
            $table->unsignedBigInteger('reviewed_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('return_templates', function (Blueprint $table) {
            $table->id();
            $table->string('regulator_code', 32);
            $table->string('return_code', 64)->unique();
            $table->string('name');
            $table->json('schema');
            $table->string('version')->default('1.0');
            $table->date('effective_from')->nullable();
            $table->boolean('navy_variant')->default(true);
            $table->timestamps();
        });

        Schema::create('return_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('template_id')->constrained('return_templates');
            $table->string('period', 16);
            $table->enum('status', ['draft', 'in_review', 'approved', 'submitted'])->default('draft');
            $table->string('pdf_path')->nullable();
            $table->string('xlsx_path')->nullable();
            $table->string('csv_path')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_runs');
        Schema::dropIfExists('return_templates');
        Schema::dropIfExists('doc_intelligence_jobs');
    }
};
