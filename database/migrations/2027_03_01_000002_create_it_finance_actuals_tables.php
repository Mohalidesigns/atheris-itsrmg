<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IT Financial Management P3: actuals ledger, GL import mappings and batches, match rules, recurring expenses, accruals. */
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
        Schema::create('fin_import_mappings', function (Blueprint $t) {
            $this->base($t);
            $t->string('name', 120);
            $t->string('type', 16)->default('actuals');
            $t->json('columns');
            $t->string('delimiter', 4)->default(',');
            $t->string('date_format', 16)->default('Y-m-d');
            $t->boolean('decimal_comma')->default(false);
            $t->boolean('is_default')->default(false);
            $t->boolean('has_header')->default(true);
            $t->char('default_currency', 3)->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'type', 'name']);
        });

        Schema::create('fin_import_batches', function (Blueprint $t) {
            $this->base($t);
            $t->string('type', 16);
            $t->string('file_name', 255);
            $t->char('file_sha256', 64);
            $t->foreignId('mapping_id')->nullable()->constrained('fin_import_mappings')->nullOnDelete();
            foreach (['row_count', 'accepted_count', 'rejected_count', 'duplicate_count', 'matched_count', 'unmatched_count'] as $c) {
                $t->unsignedInteger($c)->default(0);
            }
            $t->json('report')->nullable();
            $t->foreignId('imported_by_id')->constrained('users')->restrictOnDelete();
            $t->string('status', 24);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'type', 'created_at']);
            $t->index(['organization_id', 'file_sha256']);
        });

        Schema::create('fin_match_rules', function (Blueprint $t) {
            $this->base($t);
            $t->string('name', 120);
            $t->smallInteger('priority')->default(100);
            $t->foreignId('cost_centre_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $t->foreignId('gl_account_id')->nullable()->constrained('fin_gl_accounts')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->string('description_contains', 120)->nullable();
            $t->foreignId('budget_line_id')->constrained('fin_budget_lines')->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('hits')->default(0);
            $t->timestamp('last_hit_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'is_active', 'priority']);
        });

        Schema::create('fin_recurring_expenses', function (Blueprint $t) {
            $this->base($t);
            $t->string('recurring_code', 32);
            $t->string('title', 200);
            $t->foreignId('contract_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->restrictOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('gl_account_id')->nullable()->constrained('fin_gl_accounts')->nullOnDelete();
            $t->foreignId('tax_type_id')->nullable()->constrained('fin_tax_types')->nullOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->string('frequency', 16);
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3);
            $t->date('starts_on');
            $t->date('next_due_on');
            $t->date('ends_on')->nullable();
            $t->date('last_generated_on')->nullable();
            $t->string('auto_generate', 20)->default('expected_invoice');
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'recurring_code']);
            $t->index(['organization_id', 'is_active', 'next_due_on']);
        });

        Schema::create('fin_actuals', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->date('posted_on');
            $t->string('source', 24);
            $t->string('source_ref', 120)->nullable();
            $t->string('posting_key', 96)->nullable();
            $t->foreignId('invoice_id')->nullable()->constrained('fin_invoices')->nullOnDelete();
            $t->unsignedBigInteger('accrual_id')->nullable()->index();
            $t->foreignId('import_batch_id')->nullable()->constrained('fin_import_batches')->nullOnDelete();
            $t->foreignId('gl_account_id')->nullable()->constrained('fin_gl_accounts')->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->restrictOnDelete();
            $t->unsignedBigInteger('investment_id')->nullable();
            $t->unsignedBigInteger('ea_application_id')->nullable();
            $t->string('description', 255);
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3);
            $t->decimal('fx_rate', 18, 8)->default(1);
            $t->string('fx_rate_type', 16)->default('spot');
            $t->date('fx_rate_date')->nullable();
            $t->decimal('base_amount', 18, 2);
            $t->boolean('is_capex')->default(false);
            $t->boolean('is_lease')->default(false);
            $t->string('match_status', 16)->default('unmatched');
            $t->string('match_reason', 32)->nullable();
            $t->foreignId('match_rule_id')->nullable()->constrained('fin_match_rules')->nullOnDelete();
            $t->unsignedBigInteger('duplicate_of_id')->nullable()->index();
            $t->unsignedBigInteger('reverses_actual_id')->nullable();
            $t->foreignId('matched_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('matched_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'posting_key']);
            $t->index(['organization_id', 'fiscal_year_id', 'period_id']);
            $t->index('budget_line_id');
            $t->index(['organization_id', 'match_status']);
            $t->index(['organization_id', 'source', 'source_ref']);
            $t->index('invoice_id');
        });

        Schema::create('fin_accruals', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->string('type', 24);
            $t->string('source', 16);
            $t->string('dedupe_key', 96)->nullable();
            $t->foreignId('po_id')->nullable()->constrained('fin_purchase_orders')->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('fin_invoices')->nullOnDelete();
            $t->foreignId('recurring_expense_id')->nullable()->constrained('fin_recurring_expenses')->nullOnDelete();
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->restrictOnDelete();
            $t->foreignId('gl_account_id')->nullable()->constrained('fin_gl_accounts')->nullOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->string('description', 255);
            $t->char('currency', 3);
            $t->decimal('fx_rate', 18, 8)->default(1);
            $t->decimal('amount', 18, 2);
            $t->decimal('base_amount', 18, 2);
            $t->foreignId('reversal_period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->string('status', 16)->default('proposed');
            $t->unsignedBigInteger('posted_actual_id')->nullable();
            $t->unsignedBigInteger('reversal_actual_id')->nullable();
            $t->foreignId('posted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reversed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'dedupe_key']);
            $t->index(['organization_id', 'period_id', 'status']);
        });

        Schema::table('fin_invoices', function (Blueprint $t) {
            $t->foreign('recurring_expense_id')->references('id')->on('fin_recurring_expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fin_invoices', fn (Blueprint $t) => $t->dropForeign(['recurring_expense_id']));
        foreach (['fin_accruals', 'fin_actuals', 'fin_recurring_expenses', 'fin_match_rules', 'fin_import_batches', 'fin_import_mappings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
