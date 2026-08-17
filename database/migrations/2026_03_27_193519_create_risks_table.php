<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('risk_id_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('risk_categories')->nullOnDelete();
            $table->foreignId('risk_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('identified');

            // Inherent risk scores
            $table->unsignedTinyInteger('inherent_likelihood')->nullable();
            $table->unsignedTinyInteger('inherent_impact')->nullable();
            $table->unsignedTinyInteger('inherent_score')->nullable();
            $table->string('inherent_rating')->nullable();

            // Residual risk scores
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_impact')->nullable();
            $table->unsignedTinyInteger('residual_score')->nullable();
            $table->string('residual_rating')->nullable();

            // FAIR quantification
            $table->decimal('fair_annual_loss_expectancy', 15, 2)->nullable();
            $table->decimal('fair_single_loss_expectancy', 15, 2)->nullable();

            // Treatment
            $table->string('treatment_strategy')->nullable();
            $table->date('treatment_due_date')->nullable();
            $table->string('risk_appetite')->nullable();

            // Metadata
            $table->string('source')->nullable();
            $table->json('tags')->nullable();
            $table->date('review_date')->nullable();
            $table->date('accepted_until')->nullable();
            $table->text('acceptance_justification')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['organization_id', 'risk_id_code']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'inherent_rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risks');
    }
};
