<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('assessment_year')->unsigned();
            $table->enum('status', ['draft', 'in_progress', 'pending_approval', 'approved', 'submitted'])->default('draft');
            $table->date('submission_deadline')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('framework_version', 20)->default('FFIEC-CAT-1.1');
            $table->enum('composite_risk_level', ['least', 'minimal', 'moderate', 'significant', 'most'])->nullable();
            $table->decimal('composite_risk_score', 5, 3)->nullable();
            $table->enum('overall_maturity_level', ['sub_baseline', 'baseline', 'evolving', 'intermediate', 'advanced', 'innovative'])->nullable();
            $table->tinyInteger('ai_readiness_score')->unsigned()->nullable();
            $table->enum('ai_readiness_rag', ['red', 'amber', 'green'])->nullable();
            $table->foreignId('prior_assessment_id')->nullable()->constrained('csat_assessments')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'assessment_year']);
            $table->unique(['organization_id', 'assessment_year', 'framework_version'], 'csat_assess_org_year_ver_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_assessments');
    }
};
