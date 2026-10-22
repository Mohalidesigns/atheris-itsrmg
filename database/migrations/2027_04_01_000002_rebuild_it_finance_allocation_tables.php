<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IT Financial Management P6: the P1 allocation tables were never read or written, so they are rebuilt in the shape of
 * ATH-FIN-001 §4.2 (plan P6-5, C149). Refuses to run when they hold data.
 */
return new class extends Migration
{
    private function base(Blueprint $t): void
    {
        $t->id();
        $t->unsignedBigInteger('organization_id')->index();
        $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
    }

    public function up(): void
    {
        if (Schema::hasTable('fin_allocations') && (DB::table('fin_allocations')->exists() || (Schema::hasTable('fin_allocation_results') && DB::table('fin_allocation_results')->exists()))) {
            throw new RuntimeException('fin_allocations holds data; migrate it by hand');
        }
        Schema::dropIfExists('fin_allocation_results');
        Schema::dropIfExists('fin_allocations');

        Schema::create('fin_allocations', function (Blueprint $t) {
            $this->base($t);
            $t->string('allocation_code', 32);
            $t->string('name', 160);
            $t->string('source_type', 16);
            $t->unsignedBigInteger('source_id');
            $t->string('target_type', 20);
            $t->unsignedBigInteger('target_id')->nullable();
            $t->string('target_ref', 32)->nullable();
            $t->string('method', 12);
            $t->decimal('percentage', 7, 4)->nullable();
            $t->foreignId('driver_id')->nullable()->constrained('fin_drivers')->nullOnDelete();
            $t->decimal('driver_quantity', 18, 4)->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('journal_cost_centre_code', 32)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'allocation_code']);
            $t->index(['organization_id', 'source_type', 'source_id'], 'fin_alloc_source');
            $t->index(['organization_id', 'target_type', 'target_id'], 'fin_alloc_target');
            $t->index(['organization_id', 'is_active', 'effective_from'], 'fin_alloc_active');
        });

        Schema::create('fin_allocation_runs', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->string('amount_type', 8);
            $t->string('target_type', 20);
            $t->decimal('pool_total', 18, 2)->default(0);
            $t->decimal('allocated_total', 18, 2)->default(0);
            $t->decimal('direct_total', 18, 2)->default(0);
            $t->decimal('it_total', 18, 2)->default(0);
            $t->unsignedInteger('results_count')->default(0);
            $t->timestamp('computed_at');
            $t->foreignId('computed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_frozen')->default(false);
            $t->timestamps();
            $t->unique(['organization_id', 'period_id', 'amount_type', 'target_type'], 'fin_alloc_runs_unique');
            $t->index(['organization_id', 'fiscal_year_id']);
        });

        Schema::create('fin_allocation_results', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('allocation_id')->constrained('fin_allocations')->cascadeOnDelete();
            $t->foreignId('run_id')->constrained('fin_allocation_runs')->cascadeOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->string('amount_type', 8);
            $t->foreignId('cost_pool_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->decimal('pool_base_amount', 18, 2);
            $t->decimal('share_pct', 9, 6);
            $t->decimal('base_amount', 18, 2);
            $t->timestamp('computed_at');
            $t->timestamps();
            $t->unique(['allocation_id', 'period_id', 'amount_type', 'cost_pool_id'], 'fin_alloc_results_unique');
            $t->index(['organization_id', 'period_id', 'amount_type'], 'fin_alloc_results_period');
        });

        Schema::create('fin_chargeback_runs', function (Blueprint $t) {
            $this->base($t);
            $t->string('run_code', 32);
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->string('target_type', 20);
            $t->string('status', 12);
            $t->unsignedInteger('line_count')->default(0);
            $t->decimal('total_debit', 18, 2)->default(0);
            $t->decimal('total_credit', 18, 2)->default(0);
            $t->char('currency', 3);
            $t->char('file_sha256', 64);
            $t->longText('content');
            $t->foreignId('exported_by_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('exported_at');
            $t->foreignId('voided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'run_code']);
            $t->index(['organization_id', 'period_id', 'target_type', 'status'], 'fin_chargeback_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_chargeback_runs');
        Schema::dropIfExists('fin_allocation_results');
        Schema::dropIfExists('fin_allocation_runs');
        Schema::dropIfExists('fin_allocations');

        // The exact P1 shape (2027_02_01_000002).
        Schema::create('fin_allocations', function (Blueprint $t) {
            $this->base($t);
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
            $this->base($t);
            $t->foreignId('allocation_id')->constrained('fin_allocations')->cascadeOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->string('amount_type', 16);
            $t->decimal('base_amount', 18, 2);
            $t->timestamp('computed_at');
            $t->timestamps();
            $t->unique(['allocation_id', 'period_id', 'amount_type']);
        });
    }
};
