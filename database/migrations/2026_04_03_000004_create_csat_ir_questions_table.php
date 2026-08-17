<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ir_questions', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('category_code')->unsigned();
            $table->string('category_name');
            $table->tinyInteger('question_number')->unsigned();
            $table->text('question_text');
            $table->text('level_1_criteria');
            $table->text('level_2_criteria');
            $table->text('level_3_criteria');
            $table->text('level_4_criteria');
            $table->text('level_5_criteria');
            $table->boolean('cbn_specific')->default(false);
            $table->string('framework_version', 20)->default('FFIEC-CAT-1.1');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category_code', 'question_number', 'framework_version'], 'csat_ir_q_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ir_questions');
    }
};
