<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('framework_id')->constrained('control_frameworks')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('planned'); // planned, in_progress, completed, cancelled
            $table->foreignId('lead_assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->unsignedInteger('total_requirements')->default(0);
            $table->unsignedInteger('compliant_count')->default(0);
            $table->unsignedInteger('partial_count')->default(0);
            $table->unsignedInteger('non_compliant_count')->default(0);
            $table->unsignedInteger('not_applicable_count')->default(0);
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'framework_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_assessments');
    }
};
