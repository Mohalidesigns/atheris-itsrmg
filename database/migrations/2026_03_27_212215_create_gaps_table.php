<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained('compliance_assessments')->nullOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained('framework_requirements')->nullOnDelete();
            $table->foreignId('control_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gap_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('medium'); // critical, high, medium, low
            $table->string('status')->default('identified'); // identified, remediation_planned, in_progress, remediated, accepted, closed
            $table->text('remediation_plan')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaps');
    }
};
