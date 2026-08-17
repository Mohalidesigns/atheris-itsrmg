<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('question_libraries')->cascadeOnDelete();
            $table->morphs('respondable'); // e.g. risk_assessment, compliance_assessment
            $table->foreignId('responded_by')->constrained('users')->cascadeOnDelete();
            $table->text('response_value');
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_responses');
    }
};
