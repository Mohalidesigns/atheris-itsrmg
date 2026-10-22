<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT Financial Management P3: expense requests, purchase orders, goods receipts, invoices and documents
 * (ATH-FIN-001 §4.3, plan P3). Money is decimal(18,2), rates decimal(18,8), currency char(3).
 */
return new class extends Migration
{
    private function base(Blueprint $t): void
    {
        $t->id();
        $t->unsignedBigInteger('organization_id')->index();
        $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
    }

    private function fx(Blueprint $t): void
    {
        $t->char('currency', 3);
        $t->decimal('fx_rate', 18, 8)->default(1);
        $t->string('fx_rate_type', 16)->default('spot');
        $t->date('fx_rate_date')->nullable();
    }

    public function up(): void
    {
        Schema::create('fin_expense_requests', function (Blueprint $t) {
            $this->base($t);
            $t->string('request_code', 32);
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->restrictOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('gl_account_id')->nullable()->constrained('fin_gl_accounts')->nullOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->foreignId('renewal_id')->nullable()->constrained('fin_contract_renewals')->nullOnDelete();
            $t->unsignedBigInteger('investment_id')->nullable()->index();
            $t->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->unsignedBigInteger('ea_application_id')->nullable();
            $t->boolean('is_capex')->default(false);
            $t->boolean('is_emergency')->default(false);
            $t->boolean('is_lease')->default(false);
            $t->boolean('over_budget')->default(false);
            $t->date('needed_by')->nullable();
            $this->fx($t);
            $t->decimal('amount', 18, 2);
            $t->foreignId('tax_type_id')->nullable()->constrained('fin_tax_types')->nullOnDelete();
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('total_amount', 18, 2)->default(0);
            $t->decimal('base_amount', 18, 2)->default(0);
            $t->decimal('base_total_amount', 18, 2)->default(0);
            $t->text('justification')->nullable();
            $t->text('overbudget_justification')->nullable();
            $t->json('availability_snapshot')->nullable();
            $t->foreignId('requested_by_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 24)->default('draft');
            $t->text('rejection_reason')->nullable();
            $t->text('cancelled_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'request_code']);
            $t->index(['organization_id', 'status']);
            $t->index(['organization_id', 'fiscal_year_id', 'cost_centre_id']);
            $t->index('budget_line_id');
        });

        Schema::create('fin_purchase_orders', function (Blueprint $t) {
            $this->base($t);
            $t->string('po_code', 32);
            $t->foreignId('expense_request_id')->constrained('fin_expense_requests')->restrictOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->restrictOnDelete();
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->date('po_date');
            $t->date('expected_delivery_on')->nullable();
            $this->fx($t);
            $t->decimal('subtotal', 18, 2);
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('total_amount', 18, 2);
            $t->decimal('base_total_amount', 18, 2);
            $t->foreignId('tax_type_id')->nullable()->constrained('fin_tax_types')->nullOnDelete();
            $t->boolean('is_capex')->default(false);
            $t->boolean('is_lease')->default(false);
            $t->string('status', 24)->default('open');
            $t->foreignId('issued_by_id')->constrained('users')->restrictOnDelete();
            $t->string('external_reference', 64)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('cancelled_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'po_code']);
            $t->unique('expense_request_id');
            $t->index(['organization_id', 'status']);
        });

        Schema::create('fin_purchase_order_lines', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('po_id')->constrained('fin_purchase_orders')->cascadeOnDelete();
            $t->unsignedInteger('line_no');
            $t->string('description', 255);
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_cost', 18, 2);
            $t->decimal('amount', 18, 2);
            $t->decimal('received_quantity', 18, 4)->default(0);
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->timestamps();
            $t->unique(['po_id', 'line_no']);
        });

        Schema::create('fin_goods_receipts', function (Blueprint $t) {
            $this->base($t);
            $t->string('grn_code', 32);
            $t->foreignId('po_id')->constrained('fin_purchase_orders')->restrictOnDelete();
            $t->date('received_on');
            $t->foreignId('received_by_id')->constrained('users')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'grn_code']);
            $t->index('po_id');
        });

        Schema::create('fin_goods_receipt_lines', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('grn_id')->constrained('fin_goods_receipts')->cascadeOnDelete();
            $t->foreignId('po_line_id')->constrained('fin_purchase_order_lines')->cascadeOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->boolean('accepted')->default(true);
            $t->string('rejection_reason', 255)->nullable();
            $t->timestamps();
            $t->index('po_line_id');
        });

        Schema::create('fin_invoices', function (Blueprint $t) {
            $this->base($t);
            $t->string('invoice_code', 32);
            $t->string('vendor_invoice_no', 64);
            $t->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $t->foreignId('po_id')->nullable()->constrained('fin_purchase_orders')->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->foreignId('expense_request_id')->nullable()->constrained('fin_expense_requests')->nullOnDelete();
            $t->unsignedBigInteger('recurring_expense_id')->nullable();
            $t->date('recurring_due_on')->nullable();
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->restrictOnDelete();
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('gl_account_id')->nullable()->constrained('fin_gl_accounts')->nullOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->date('invoice_date');
            $t->date('due_on');
            $t->date('received_on')->nullable();
            $this->fx($t);
            $t->decimal('subtotal', 18, 2);
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('wht_amount', 18, 2)->default(0);
            $t->decimal('total_amount', 18, 2);
            $t->decimal('base_total_amount', 18, 2);
            $t->foreignId('tax_type_id')->nullable()->constrained('fin_tax_types')->nullOnDelete();
            $t->foreignId('wht_tax_type_id')->nullable()->constrained('fin_tax_types')->nullOnDelete();
            $t->string('match_status', 16)->default('unmatched');
            $t->decimal('match_variance_amount', 18, 2)->nullable();
            $t->decimal('match_variance_base', 18, 2)->nullable();
            $t->json('match_detail')->nullable();
            $t->timestamp('matched_at')->nullable();
            $t->text('match_override_reason')->nullable();
            $t->foreignId('match_override_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('match_override_at')->nullable();
            $t->string('status', 16)->default('draft');
            $t->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('rejection_reason')->nullable();
            $t->date('scheduled_on')->nullable();
            $t->foreignId('scheduled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('paid_on')->nullable();
            $t->foreignId('paid_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('payment_reference', 64)->nullable();
            $t->text('dispute_reason')->nullable();
            $t->timestamp('disputed_at')->nullable();
            $t->foreignId('disputed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('cancelled_reason')->nullable();
            $t->boolean('is_expected')->default(false);
            $t->boolean('is_capex')->default(false);
            $t->boolean('is_lease')->default(false);
            $t->unsignedSmallInteger('prepayment_months')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'invoice_code']);
            $t->unique(['organization_id', 'vendor_id', 'vendor_invoice_no']);
            $t->unique(['recurring_expense_id', 'recurring_due_on']);
            $t->index(['organization_id', 'status']);
            $t->index(['organization_id', 'due_on']);
            $t->index('po_id');
        });

        Schema::create('fin_invoice_lines', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('invoice_id')->constrained('fin_invoices')->cascadeOnDelete();
            $t->unsignedInteger('line_no');
            $t->foreignId('po_line_id')->nullable()->constrained('fin_purchase_order_lines')->nullOnDelete();
            $t->string('description', 255);
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_cost', 18, 2);
            $t->decimal('amount', 18, 2);
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->timestamps();
            $t->unique(['invoice_id', 'line_no']);
        });

        Schema::create('fin_documents', function (Blueprint $t) {
            $this->base($t);
            $t->string('documentable_type');
            $t->unsignedBigInteger('documentable_id');
            $t->foreignId('evidence_id')->constrained('evidence')->cascadeOnDelete();
            $t->string('kind', 24);
            $t->string('title', 200);
            $t->char('sha256', 64)->nullable();
            $t->timestamps();
            $t->index(['documentable_type', 'documentable_id']);
        });
    }

    public function down(): void
    {
        foreach (['fin_documents', 'fin_invoice_lines', 'fin_invoices', 'fin_goods_receipt_lines', 'fin_goods_receipts', 'fin_purchase_order_lines', 'fin_purchase_orders', 'fin_expense_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
