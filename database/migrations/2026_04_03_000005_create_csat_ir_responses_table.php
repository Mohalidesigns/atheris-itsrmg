<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ir_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('csat_ir_questions')->cascadeOnDelete();
            $table->tinyInteger('selected_level')->unsigned();
            $table->text('comment')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ir_responses');
    }
};
