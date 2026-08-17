<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->enum('recommendation_type', ['gap_analysis', 'readiness_flag', 'narrative_draft', 'threat_enrichment', 'policy_gap', 'yoy_summary']);
            $table->string('scope_reference', 150)->nullable();
            $table->text('recommendation_text');
            $table->string('cbn_framework_ref', 500)->nullable();
            $table->enum('effort_estimate', ['low', 'medium', 'high'])->nullable();
            $table->tinyInteger('priority_rank')->unsigned()->nullable();
            $table->tinyInteger('user_rating')->unsigned()->nullable();
            $table->string('model_used', 100);
            $table->string('prompt_version', 20);
            $table->timestamp('generated_at');
            $table->boolean('is_dismissed')->default(false);

            $table->index(['assessment_id', 'recommendation_type', 'is_dismissed'], 'csat_ai_rec_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ai_recommendations');
    }
};
