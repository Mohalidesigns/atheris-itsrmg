<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_threats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->string('threat_name', 500);
            $table->unsignedBigInteger('catalogue_threat_id')->nullable();
            $table->text('description')->nullable();
            $table->enum('threat_source', ['internal', 'external', 'natural']);
            $table->enum('threat_category', ['technical', 'human', 'environmental']);
            $table->enum('likelihood', ['high', 'moderate', 'low']);
            $table->enum('impact', ['high', 'moderate', 'low']);
            $table->tinyInteger('inherent_risk_score')->unsigned()->storedAs(
                "CASE likelihood WHEN 'high' THEN 3 WHEN 'moderate' THEN 2 ELSE 1 END * CASE impact WHEN 'high' THEN 3 WHEN 'moderate' THEN 2 ELSE 1 END"
            );
            $table->text('mitigating_controls_desc')->nullable();
            $table->unsignedBigInteger('controls_register_id')->nullable();
            $table->tinyInteger('residual_risk_score')->unsigned()->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('catalogue_threat_id')->references('id')->on('csat_threat_catalogue')->nullOnDelete();
            $table->index(['assessment_id', 'inherent_risk_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_threats');
    }
};
