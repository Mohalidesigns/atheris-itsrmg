<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcp_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('plan_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('plan_type'); // bcp, dr, crisis
            $table->string('status')->default('draft'); // draft, active, under_review, tested, expired
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('version')->default('1.0');
            $table->text('scope')->nullable();
            $table->text('objectives')->nullable();
            $table->integer('rto_hours')->nullable();
            $table->integer('rpo_hours')->nullable();
            $table->date('last_tested')->nullable();
            $table->date('next_test_date')->nullable();
            $table->date('next_review_date')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'plan_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcp_plans');
    }
};
