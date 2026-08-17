<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ma_narratives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->tinyInteger('domain_code')->unsigned();
            $table->tinyInteger('question_number')->unsigned();
            $table->text('question_text');
            $table->text('response_text')->nullable();
            $table->text('ai_draft_text')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->smallInteger('min_characters')->unsigned()->default(100);
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'question_number'], 'csat_ma_narr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ma_narratives');
    }
};
