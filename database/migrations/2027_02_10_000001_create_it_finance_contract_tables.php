<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT Financial Management P2: contracts, subscriptions and renewals (ATH-FIN-001 §4.4, plan C58).
 * Money is decimal(18,2), rates decimal(18,8), currency char(3). Every user FK is nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('contract_code', 32);
            $t->string('title', 200);
            $t->string('type', 32);
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $t->foreignId('cost_centre_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $t->foreignId('budget_line_id')->nullable()->constrained('fin_budget_lines')->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('technical_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('procurement_contact_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('description')->nullable();
            $t->date('commenced_on');
            $t->date('ends_on');
            $t->unsignedSmallInteger('term_months');
            $t->unsignedSmallInteger('notice_period_days')->default(0);
            $t->string('renewal_type', 16)->default('manual');
            $t->boolean('auto_renew')->default(false);
            $t->unsignedSmallInteger('renewal_lead_days')->nullable();
            $t->date('renewal_window_opens_on');
            $t->date('notice_deadline_on');
            $t->json('notification_offsets')->nullable();
            $t->char('currency', 3);
            $t->decimal('fx_rate', 18, 8)->default(1);
            $t->string('fx_rate_type', 16)->default('spot');
            $t->date('fx_rate_date')->nullable();
            $t->decimal('contract_value', 18, 2)->default(0);
            $t->decimal('base_contract_value', 18, 2)->default(0);
            $t->string('billing_frequency', 16)->default('annual');
            $t->decimal('amount_per_billing', 18, 2)->nullable();
            $t->string('licence_metric', 16)->nullable();
            $t->decimal('licence_quantity', 18, 2)->nullable();
            $t->decimal('deployed_quantity', 18, 2)->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->nullable();
            $t->decimal('price_escalation_pct', 6, 2)->nullable();
            $t->string('data_processing_role', 16)->default('none');
            $t->string('dpa_status', 16)->default('not_required');
            $t->text('sla_summary')->nullable();
            $t->text('exit_terms')->nullable();
            $t->json('key_clauses')->nullable();
            $t->string('external_reference', 100)->nullable();
            $t->string('status', 32)->default('draft');
            $t->foreignId('renewed_from_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->foreignId('renewed_to_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->string('decision', 24)->nullable();
            $t->timestamp('decision_at')->nullable();
            $t->foreignId('decision_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('terminated_on')->nullable();
            $t->text('termination_reason')->nullable();
            $t->smallInteger('last_notified_offset')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'contract_code']);
            $t->index(['organization_id', 'status']);
            $t->index(['organization_id', 'ends_on']);
            $t->index(['organization_id', 'notice_deadline_on']);
            $t->index(['organization_id', 'renewal_window_opens_on']);
            $t->index(['organization_id', 'vendor_id']);
            $t->index(['organization_id', 'owner_id']);
            $t->index(['organization_id', 'cost_centre_id']);
        });

        Schema::create('fin_contract_team', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('contract_id')->constrained('fin_contracts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('role', 16)->default('member');
            $t->timestamps();
            $t->unique(['contract_id', 'user_id']);
            $t->index(['organization_id', 'user_id']);
        });

        Schema::create('fin_contract_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('contract_id')->constrained('fin_contracts')->cascadeOnDelete();
            $t->string('linkable_type');
            $t->unsignedBigInteger('linkable_id');
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->unique(['contract_id', 'linkable_type', 'linkable_id']);
            $t->index(['linkable_type', 'linkable_id']);
        });

        Schema::create('fin_contract_renewals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('renewal_code', 32);
            $t->foreignId('contract_id')->constrained('fin_contracts')->restrictOnDelete();
            $t->date('opened_on');
            $t->string('recommendation', 24)->nullable();
            $t->text('justification')->nullable();
            $t->text('benchmark_notes')->nullable();
            $t->unsignedSmallInteger('proposed_term_months')->nullable();
            $t->date('proposed_commences_on')->nullable();
            $t->decimal('proposed_value', 18, 2)->nullable();
            $t->char('proposed_currency', 3)->nullable();
            $t->decimal('proposed_fx_rate', 18, 8)->nullable();
            $t->string('proposed_fx_rate_type', 16)->nullable();
            $t->date('proposed_fx_rate_date')->nullable();
            $t->decimal('proposed_base_value', 18, 2)->nullable();
            $t->decimal('uplift_pct', 7, 2)->nullable();
            $t->text('alternatives_considered')->nullable();
            $t->string('status', 16)->default('open');
            $t->boolean('is_auto')->default(false);
            $t->foreignId('recommended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreignId('executed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('executed_at')->nullable();
            $t->foreignId('new_contract_id')->nullable()->constrained('fin_contracts')->nullOnDelete();
            $t->unsignedBigInteger('expense_request_id')->nullable(); // P3 adds the table and listener (plan C52)
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'renewal_code']);
            $t->index(['organization_id', 'status']);
            $t->index(['contract_id', 'status']);
        });

        Schema::create('fin_contract_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('contract_id')->constrained('fin_contracts')->cascadeOnDelete();
            $t->string('type', 32);
            $t->json('payload')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['contract_id', 'occurred_at']);
            $t->index(['organization_id', 'type']);
        });

        Schema::create('fin_contract_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('contract_id')->constrained('fin_contracts')->cascadeOnDelete();
            $t->foreignId('evidence_id')->nullable()->constrained('evidence')->nullOnDelete();
            $t->string('kind', 24);
            $t->string('title', 200);
            $t->char('sha256', 64)->nullable();
            $t->date('signed_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->timestamps();
            $t->index('contract_id');
            $t->index(['organization_id', 'expires_on']);
        });

        Schema::create('fin_calendar_feeds', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique('user_id');
        });
    }

    public function down(): void
    {
        foreach (['fin_calendar_feeds', 'fin_contract_documents', 'fin_contract_events', 'fin_contract_renewals', 'fin_contract_links', 'fin_contract_team', 'fin_contracts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
