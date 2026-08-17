<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('strategy'); // mitigate, transfer, avoid, accept
            $table->string('status')->default('draft'); // draft, submitted, approved, rejected, in_progress, completed, overdue

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->unsignedTinyInteger('priority')->default(3); // 1=critical, 5=low
            $table->decimal('estimated_cost', 15, 2)->nullable();
            $table->string('cost_currency', 3)->default('NGN');

            // Expected residual scores after treatment
            $table->unsignedTinyInteger('target_likelihood')->nullable();
            $table->unsignedTinyInteger('target_impact')->nullable();
            $table->unsignedTinyInteger('target_score')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('completion_percentage')->default(0);

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'risk_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_treatments');
    }
};
