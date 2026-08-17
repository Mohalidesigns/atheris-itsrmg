<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ir_category_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->tinyInteger('category_code')->unsigned();
            $table->decimal('total_score', 6, 2)->default(0);
            $table->tinyInteger('question_count')->unsigned();
            $table->tinyInteger('answered_count')->unsigned()->default(0);
            $table->decimal('average_score', 5, 3)->default(0.000);
            $table->enum('risk_level', ['least', 'minimal', 'moderate', 'significant', 'most'])->default('least');
            $table->decimal('completion_pct', 5, 2)->default(0.00);
            $table->timestamp('calculated_at');

            $table->unique(['assessment_id', 'category_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ir_category_scores');
    }
};
