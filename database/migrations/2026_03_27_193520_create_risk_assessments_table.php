<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->cascadeOnDelete();

            $table->string('methodology'); // qualitative, fair
            $table->string('assessment_type')->default('inherent'); // inherent, residual

            // Qualitative scores
            $table->unsignedTinyInteger('likelihood')->nullable();
            $table->unsignedTinyInteger('impact')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('rating')->nullable();

            // FAIR model fields
            $table->decimal('fair_tef', 8, 4)->nullable()->comment('Threat Event Frequency');
            $table->decimal('fair_vul', 8, 4)->nullable()->comment('Vulnerability');
            $table->decimal('fair_lef', 8, 4)->nullable()->comment('Loss Event Frequency');
            $table->decimal('fair_plm', 15, 2)->nullable()->comment('Primary Loss Magnitude');
            $table->decimal('fair_slm', 15, 2)->nullable()->comment('Secondary Loss Magnitude');
            $table->decimal('fair_ale', 15, 2)->nullable()->comment('Annual Loss Expectancy');
            $table->string('fair_currency', 3)->default('NGN');

            // Impact breakdown
            $table->unsignedTinyInteger('impact_financial')->nullable();
            $table->unsignedTinyInteger('impact_operational')->nullable();
            $table->unsignedTinyInteger('impact_reputational')->nullable();
            $table->unsignedTinyInteger('impact_regulatory')->nullable();
            $table->unsignedTinyInteger('impact_safety')->nullable();

            $table->text('justification')->nullable();
            $table->text('notes')->nullable();
            $table->date('assessment_date');
            $table->date('next_review_date')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'risk_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
