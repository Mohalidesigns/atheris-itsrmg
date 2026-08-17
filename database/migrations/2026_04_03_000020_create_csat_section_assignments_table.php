<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_section_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->enum('scope_type', ['domain', 'factor', 'component', 'statement']);
            $table->string('scope_code', 20);
            $table->foreignId('assigned_to')->constrained('users');
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'overdue'])->default('pending');
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');

            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_section_assignments');
    }
};
