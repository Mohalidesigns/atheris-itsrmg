<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->string('vulnerability_name', 500);
            $table->text('description')->nullable();
            $table->enum('vulnerability_category', ['people', 'process', 'technology']);
            $table->enum('likelihood_of_exploit', ['high', 'moderate', 'low']);
            $table->enum('impact_if_exploited', ['high', 'moderate', 'low']);
            $table->tinyInteger('composite_score')->unsigned()->storedAs(
                "CASE likelihood_of_exploit WHEN 'high' THEN 3 WHEN 'moderate' THEN 2 ELSE 1 END * CASE impact_if_exploited WHEN 'high' THEN 3 WHEN 'moderate' THEN 2 ELSE 1 END"
            );
            $table->boolean('mitigants_in_place')->default(false);
            $table->text('existing_mitigants')->nullable();
            $table->text('planned_mitigants')->nullable();
            $table->enum('remediation_status', ['identified', 'assigned', 'in_progress', 'remediated', 'verified'])->default('identified');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assessment_id', 'composite_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_vulnerabilities');
    }
};
