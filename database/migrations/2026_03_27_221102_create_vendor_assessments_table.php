<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_assessments')) {
            return;
        }

        Schema::create('vendor_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('assessment_type'); // onboarding, periodic, incident_driven
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, completed
            $table->foreignId('assessed_by')->constrained('users');
            $table->date('assessment_date');
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->date('next_review_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_assessments');
    }
};
