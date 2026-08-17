<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('compliance_assessments')->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('framework_requirements')->cascadeOnDelete();
            $table->foreignId('control_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status'); // compliant, partially_compliant, non_compliant, not_applicable, not_assessed
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('assessed_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'requirement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_results');
    }
};
