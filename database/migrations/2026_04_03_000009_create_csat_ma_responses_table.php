<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ma_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->foreignId('statement_id')->constrained('csat_ma_statements')->cascadeOnDelete();
            $table->enum('response', ['yes', 'yes_cc', 'no', 'na'])->nullable();
            $table->boolean('has_compensating_control')->default(false);
            $table->text('comment')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'statement_id']);
            $table->index(['assessment_id', 'response']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ma_responses');
    }
};
