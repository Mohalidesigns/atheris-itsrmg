<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ma_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->enum('score_type', ['component', 'factor', 'domain']);
            $table->string('scope_code', 20);
            $table->string('scope_name');
            $table->decimal('baseline_score', 6, 4)->default(0.0000);
            $table->decimal('evolving_score', 6, 4)->default(0.0000);
            $table->decimal('intermediate_score', 6, 4)->default(0.0000);
            $table->decimal('advanced_score', 6, 4)->default(0.0000);
            $table->decimal('innovative_score', 6, 4)->default(0.0000);
            $table->tinyInteger('achieved_maturity_level')->unsigned()->default(0);
            $table->tinyInteger('target_maturity_level')->unsigned()->nullable();
            $table->decimal('completion_pct', 5, 2)->default(0.00);
            $table->timestamp('calculated_at');

            $table->unique(['assessment_id', 'score_type', 'scope_code'], 'csat_ma_scores_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ma_scores');
    }
};
