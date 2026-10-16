<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT Financial Management P1 budgeting (ATH-FIN-001 §3.2, §4.2): cycles, targets, versioned budgets,
 * lines with TBM pool x tower, phasing, scenarios, comments, the adjustments ledger (C22),
 * transfers and supplementary requests. Money = decimal(18,2); fx_rate = decimal(18,8); currency char(3).
 */
return new class extends Migration
{
    private const TABLES = [
        'fin_supplementary_requests', 'fin_budget_transfers', 'fin_budget_adjustments', 'fin_budget_comments',
        'fin_budget_scenarios', 'fin_budget_line_periods', 'fin_budget_lines', 'fin_budgets', 'fin_budget_targets', 'fin_budget_cycles',
    ];

    private function base(Blueprint $t): void
    {
        $t->id();
        $t->unsignedBigInteger('organization_id')->index();
        $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
    }

    private function user(Blueprint $t, string $col): void
    {
        $t->foreignId($col)->nullable()->constrained('users')->nullOnDelete();
    }

    public function up(): void
    {
        Schema::create('fin_budget_cycles', function (Blueprint $t) {
            $this->base($t);
            $t->string('cycle_code', 32);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->string('name', 160);
            $t->string('type', 24);
            $t->date('opens_on');
            $t->date('closes_on');
            $t->text('instructions')->nullable();
            $this->user($t, 'coordinator_id');
            $t->string('status', 16)->default('planned');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'cycle_code']);
            $t->index(['organization_id', 'fiscal_year_id', 'status']);
        });

        Schema::create('fin_budget_targets', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('cycle_id')->constrained('fin_budget_cycles')->cascadeOnDelete();
            $t->foreignId('cost_centre_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $t->decimal('capex_ceiling', 18, 2)->nullable();
            $t->decimal('opex_ceiling', 18, 2)->nullable();
            $t->string('notes', 500)->nullable();
            $this->user($t, 'set_by_id');
            $t->timestamps();
            $t->index(['organization_id', 'cycle_id']);
        });

        Schema::create('fin_budgets', function (Blueprint $t) {
            $this->base($t);
            $t->string('budget_code', 32);
            $t->foreignId('cycle_id')->constrained('fin_budget_cycles')->restrictOnDelete();
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $t->unsignedSmallInteger('version_no')->default(1);
            $t->unsignedBigInteger('previous_version_id')->nullable()->index();
            $t->unsignedBigInteger('superseded_by_id')->nullable()->index();
            $t->unsignedBigInteger('source_budget_id')->nullable()->index();
            $t->decimal('increment_pct', 7, 2)->nullable();
            $t->string('title', 200);
            $t->string('mode', 16)->default('zero_based');
            $t->string('status', 16)->default('draft');
            $t->string('baseline_slot', 64)->nullable();
            $this->user($t, 'owner_id');
            $this->user($t, 'submitted_by_id');
            $t->timestamp('submitted_at')->nullable();
            $this->user($t, 'approved_by_id');
            $t->timestamp('approved_at')->nullable();
            $this->user($t, 'baselined_by_id');
            $t->timestamp('baselined_at')->nullable();
            $t->text('executive_summary')->nullable();
            $t->text('assumptions')->nullable();
            $t->text('risks_summary')->nullable();
            $t->decimal('total_capex', 18, 2)->default(0);
            $t->decimal('total_opex', 18, 2)->default(0);
            $t->decimal('total_base_amount', 18, 2)->default(0);
            $t->text('rejection_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'budget_code', 'version_no']);
            $t->unique(['organization_id', 'baseline_slot']);
            $t->index(['organization_id', 'fiscal_year_id', 'cost_centre_id', 'status'], 'fin_budgets_scope_status_index');
        });
        // Self-references added after the table exists (portable on SQLite: columns are plain indexed ids, FKs below).
        Schema::table('fin_budgets', function (Blueprint $t) {
            $t->foreign('previous_version_id')->references('id')->on('fin_budgets')->nullOnDelete();
            $t->foreign('superseded_by_id')->references('id')->on('fin_budgets')->nullOnDelete();
            $t->foreign('source_budget_id')->references('id')->on('fin_budgets')->nullOnDelete();
        });

        Schema::create('fin_budget_lines', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('budget_id')->constrained('fin_budgets')->cascadeOnDelete();
            $t->unsignedInteger('line_no');
            $t->unsignedBigInteger('source_line_id')->nullable()->index();
            $t->string('origin', 16)->default('planned');
            $t->string('description', 255);
            $t->foreignId('cost_pool_id')->constrained('fin_categories')->restrictOnDelete();
            $t->foreignId('tower_id')->constrained('fin_categories')->restrictOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('gl_account_id')->nullable()->constrained('fin_gl_accounts')->nullOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $t->boolean('is_capex')->default(false);
            $t->boolean('is_recurring')->default(false);
            $t->string('priority', 16)->default('discretionary');
            $t->string('rgt', 16)->default('run');
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->unsignedBigInteger('contract_id')->nullable()->index();
            $t->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->unsignedBigInteger('ea_application_id')->nullable();
            $t->unsignedBigInteger('business_service_id')->nullable();
            $t->unsignedBigInteger('investment_id')->nullable()->index();
            $t->foreignId('risk_id')->nullable()->constrained('risks')->nullOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained('fin_drivers')->nullOnDelete();
            $t->decimal('driver_rate', 18, 4)->nullable();
            $t->decimal('quantity', 18, 4)->default(1);
            $t->decimal('unit_cost', 18, 2)->nullable();
            $t->char('currency', 3);
            $t->decimal('fx_rate', 18, 8);
            $t->string('fx_rate_type', 16)->default('budget');
            $t->date('fx_rate_date')->nullable();
            $t->decimal('annual_amount', 18, 2)->default(0);
            $t->decimal('base_annual_amount', 18, 2)->default(0);
            $t->string('phasing_method', 16)->default('equal');
            $t->text('justification')->nullable();
            $t->decimal('variance_amber_pct', 6, 2)->nullable();
            $t->decimal('variance_red_pct', 6, 2)->nullable();
            $t->decimal('py_budget', 18, 2)->nullable();
            $t->decimal('py_actual', 18, 2)->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['budget_id', 'line_no']);
            $t->index(['organization_id', 'cost_centre_id']);
            $t->index(['organization_id', 'tower_id']);
            $t->index(['organization_id', 'cost_pool_id']);
        });

        Schema::create('fin_budget_line_periods', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('budget_line_id')->constrained('fin_budget_lines')->cascadeOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->decimal('amount', 18, 2)->default(0);
            $t->decimal('base_amount', 18, 2)->default(0);
            $t->timestamps();
            $t->unique(['budget_line_id', 'period_id']);
            $t->index(['organization_id', 'period_id']);
        });

        Schema::create('fin_budget_scenarios', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('budget_id')->constrained('fin_budgets')->cascadeOnDelete();
            $t->string('name', 120);
            $t->decimal('adjustment_pct', 7, 2)->default(0);
            $t->json('line_adjustments')->nullable();
            $t->boolean('is_promoted')->default(false);
            $t->timestamp('promoted_at')->nullable();
            $this->user($t, 'promoted_by_id');
            $t->timestamps();
            $t->index('budget_id');
        });

        Schema::create('fin_budget_comments', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('budget_id')->constrained('fin_budgets')->cascadeOnDelete();
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->string('kind', 16)->default('comment');
            $t->text('body');
            $t->timestamps();
            $t->index('budget_id');
        });

        Schema::create('fin_budget_adjustments', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('budget_line_id')->constrained('fin_budget_lines')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->string('source_type', 24);
            $t->unsignedBigInteger('source_id');
            $t->decimal('amount', 18, 2);
            $t->decimal('base_amount', 18, 2);
            $this->user($t, 'applied_by_id');
            $t->timestamp('applied_at');
            $t->timestamps();
            $t->index(['organization_id', 'budget_line_id']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('fin_budget_transfers', function (Blueprint $t) {
            $this->base($t);
            $t->string('transfer_code', 32);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('from_line_id')->constrained('fin_budget_lines')->restrictOnDelete();
            $t->foreignId('to_line_id')->nullable()->constrained('fin_budget_lines')->restrictOnDelete();
            $t->foreignId('to_cost_centre_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $t->foreignId('to_cost_pool_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('to_tower_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('to_category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->string('to_description', 255)->nullable();
            $t->foreignId('from_period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->foreignId('to_period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3);
            $t->decimal('base_amount', 18, 2);
            $t->text('reason');
            $this->user($t, 'requested_by_id');
            $t->string('status', 16)->default('draft');
            $t->timestamp('submitted_at')->nullable();
            $this->user($t, 'approved_by_id');
            $t->timestamp('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $this->user($t, 'applied_by_id');
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'transfer_code']);
            $t->index(['organization_id', 'status']);
            $t->index('fiscal_year_id');
        });

        Schema::create('fin_supplementary_requests', function (Blueprint $t) {
            $this->base($t);
            $t->string('request_code', 32);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->foreignId('cost_pool_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('tower_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('target_line_id')->nullable()->constrained('fin_budget_lines')->nullOnDelete();
            $t->string('title', 200);
            $t->boolean('is_capex')->default(false);
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3);
            $t->decimal('fx_rate', 18, 8);
            $t->string('fx_rate_type', 16)->default('spot');
            $t->date('fx_rate_date')->nullable();
            $t->decimal('base_amount', 18, 2);
            $t->text('justification');
            $this->user($t, 'requested_by_id');
            $t->string('status', 16)->default('draft');
            $t->timestamp('submitted_at')->nullable();
            $this->user($t, 'approved_by_id');
            $t->timestamp('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $this->user($t, 'applied_by_id');
            $t->timestamp('applied_at')->nullable();
            $t->foreignId('resulting_line_id')->nullable()->constrained('fin_budget_lines')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'request_code']);
            $t->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if ($table === 'fin_budgets') {
                Schema::table('fin_budgets', function (Blueprint $t) {
                    $t->dropForeign(['previous_version_id']);
                    $t->dropForeign(['superseded_by_id']);
                    $t->dropForeign(['source_budget_id']);
                });
            }
            Schema::dropIfExists($table);
        }
    }
};
