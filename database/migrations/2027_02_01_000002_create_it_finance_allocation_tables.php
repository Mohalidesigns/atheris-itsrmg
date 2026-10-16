<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Allocation tables only (ATH-FIN-001 §4.2); execution arrives in P6 (plan C27). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_allocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('allocation_code', 32);
            $t->string('source_type', 24);
            $t->unsignedBigInteger('source_id');
            $t->string('target_type', 24);
            $t->unsignedBigInteger('target_id');
            $t->string('method', 16);
            $t->decimal('percentage', 7, 4)->nullable();
            $t->foreignId('driver_id')->nullable()->constrained('fin_drivers')->nullOnDelete();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'allocation_code']);
            $t->index(['source_type', 'source_id']);
            $t->index(['target_type', 'target_id']);
        });

        Schema::create('fin_allocation_results', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('allocation_id')->constrained('fin_allocations')->cascadeOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->string('amount_type', 16);
            $t->decimal('base_amount', 18, 2);
            $t->timestamp('computed_at');
            $t->timestamps();
            $t->unique(['allocation_id', 'period_id', 'amount_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_allocation_results');
        Schema::dropIfExists('fin_allocations');
    }
};
