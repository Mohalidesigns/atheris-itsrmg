<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IT Financial Management P5: investments, business cases, scores, stage gates, changes, benefits, readings, value reviews and links. */
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
        Schema::create('fin_investments', function (Blueprint $t) {
            $this->base($t);
            $t->string('investment_code', 32);
            $t->string('title', 200);
            $t->string('type', 16);
            $t->string('rgt', 12);
            $t->string('driver', 24);
            $t->foreignId('sponsor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('cost_centre_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $t->foreignId('fiscal_year_id')->nullable()->constrained('fin_fiscal_years')->nullOnDelete();
            $t->string('stage', 20)->default('idea');
            $t->string('status', 12)->default('on_track');
            $t->string('status_before_hold', 12)->nullable();
            $t->date('starts_on')->nullable();
            $t->date('planned_ends_on')->nullable();
            $t->date('actual_ends_on')->nullable();
            $t->text('description')->nullable();
            $t->text('strategic_objective')->nullable();
            $t->decimal('planned_cost', 18, 2)->nullable();
            $t->decimal('bac', 18, 2)->nullable();
            $t->timestamp('bac_baselined_at')->nullable();
            $t->decimal('eac', 18, 2)->nullable();
            $t->text('eac_reason')->nullable();
            $t->decimal('percent_complete', 5, 2)->default(0);
            $t->date('percent_complete_as_of')->nullable();
            $t->decimal('cost_tolerance_pct', 5, 2)->default(10);
            $t->decimal('priority_score', 6, 2)->nullable();
            $t->unsignedInteger('priority_rank')->nullable();
            $t->boolean('is_regulatory')->default(false);
            $t->string('regulatory_reference', 200)->nullable();
            $t->json('value_review_dates')->nullable();
            $t->string('external_reference', 100)->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'investment_code']);
            $t->index(['organization_id', 'stage']);
            $t->index(['organization_id', 'fiscal_year_id', 'priority_rank']);
            $t->index(['organization_id', 'cost_centre_id']);
        });

        Schema::create('fin_business_cases', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('investment_id')->constrained('fin_investments')->cascadeOnDelete();
            $t->unsignedSmallInteger('version_no');
            $t->string('status', 12)->default('draft');
            $t->unsignedSmallInteger('horizon_years');
            $t->decimal('discount_rate_pct', 6, 3);
            $t->text('option_summary')->nullable();
            $t->string('recommended_option', 200)->nullable();
            $t->json('costs');
            $t->json('benefits');
            $t->json('non_financial_benefits')->nullable();
            $t->decimal('npv', 18, 2)->nullable();
            $t->decimal('irr_pct', 9, 4)->nullable();
            $t->boolean('irr_multiple')->default(false);
            $t->integer('payback_months')->nullable();
            $t->integer('discounted_payback_months')->nullable();
            $t->decimal('roi_pct', 10, 2)->nullable();
            $t->decimal('tco', 18, 2)->default(0);
            $t->decimal('one_off_cost', 18, 2)->default(0);
            $t->decimal('total_benefit', 18, 2)->default(0);
            $t->text('assumptions')->nullable();
            $t->text('risks_summary')->nullable();
            $t->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['investment_id', 'version_no']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('fin_investment_scores', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('investment_id')->constrained('fin_investments')->cascadeOnDelete();
            $t->string('criterion', 24);
            $t->unsignedTinyInteger('score');
            $t->decimal('weight_pct', 5, 2);
            $t->decimal('weighted', 6, 2);
            $t->string('note', 500)->nullable();
            $t->foreignId('scored_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['investment_id', 'criterion']);
        });

        Schema::create('fin_investment_changes', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('investment_id')->constrained('fin_investments')->cascadeOnDelete();
            $t->string('change_code', 32);
            $t->text('description');
            $t->text('reason')->nullable();
            $t->decimal('cost_delta', 18, 2)->default(0);
            $t->integer('schedule_delta_days')->default(0);
            $t->string('status', 12);
            $t->boolean('requires_approval')->default(false);
            $t->json('tolerance_snapshot')->nullable();
            $t->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requested_at');
            $t->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'change_code']);
            $t->index(['investment_id', 'status']);
        });

        Schema::create('fin_stage_gates', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('investment_id')->constrained('fin_investments')->cascadeOnDelete();
            $t->string('gate', 20);
            $t->string('from_stage', 20);
            $t->string('status', 12);
            $t->json('criteria');
            $t->json('payload')->nullable();
            $t->foreignId('business_case_id')->nullable()->constrained('fin_business_cases')->nullOnDelete();
            $t->foreignId('investment_change_id')->nullable()->constrained('fin_investment_changes')->nullOnDelete();
            $t->decimal('approval_base_amount', 18, 2)->nullable();
            $t->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requested_at');
            $t->text('request_note')->nullable();
            $t->timestamp('passed_at')->nullable();
            $t->foreignId('passed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $t->timestamps();
            $t->index(['organization_id', 'investment_id', 'id']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('fin_benefits', function (Blueprint $t) {
            $this->base($t);
            $t->string('benefit_code', 32);
            $t->foreignId('investment_id')->constrained('fin_investments')->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('type', 20);
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('measurement_method')->nullable();
            $t->decimal('baseline_value', 18, 2)->default(0);
            $t->decimal('target_value', 18, 2);
            $t->string('unit', 12);
            $t->string('aggregation', 8);
            $t->char('currency', 3)->nullable();
            $t->date('due_on')->nullable();
            $t->string('status', 20)->default('planned');
            $t->decimal('realised_value', 18, 2)->nullable();
            $t->decimal('realised_pct', 7, 2)->nullable();
            $t->foreignId('last_reading_period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'benefit_code']);
            $t->index('investment_id');
            $t->index(['organization_id', 'owner_id']);
        });

        Schema::create('fin_benefit_readings', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('benefit_id')->constrained('fin_benefits')->cascadeOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->decimal('value', 18, 2);
            $t->foreignId('document_id')->nullable()->constrained('fin_documents')->nullOnDelete();
            $t->string('note', 500)->nullable();
            $t->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['benefit_id', 'period_id']);
        });

        Schema::create('fin_value_reviews', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('investment_id')->constrained('fin_investments')->cascadeOnDelete();
            $t->string('review_type', 12);
            $t->date('due_on');
            $t->date('completed_on')->nullable();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('benefits_realised_pct', 7, 2)->nullable();
            $t->string('cost_outcome', 8)->nullable();
            $t->text('summary')->nullable();
            $t->text('lessons')->nullable();
            $t->string('status', 12)->default('scheduled');
            $t->foreignId('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status', 'due_on']);
            $t->index('investment_id');
        });

        Schema::create('fin_investment_links', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('investment_id')->constrained('fin_investments')->cascadeOnDelete();
            $t->string('linkable_type', 100);
            $t->unsignedBigInteger('linkable_id');
            $t->string('note', 300)->nullable();
            $t->timestamps();
            $t->unique(['investment_id', 'linkable_type', 'linkable_id'], 'fin_investment_links_unique');
            $t->index(['linkable_type', 'linkable_id']);
        });

        Schema::table('fin_settings', function (Blueprint $t) {
            Schema::hasColumn('fin_settings', 'investment_discount_rate_pct') || $t->decimal('investment_discount_rate_pct', 6, 3)->nullable();
            Schema::hasColumn('fin_settings', 'investment_cost_tolerance_pct') || $t->decimal('investment_cost_tolerance_pct', 5, 2)->nullable();
            Schema::hasColumn('fin_settings', 'value_review_lead_days') || $t->unsignedSmallInteger('value_review_lead_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fin_settings', function (Blueprint $t) {
            foreach (['investment_discount_rate_pct', 'investment_cost_tolerance_pct', 'value_review_lead_days'] as $c) {
                Schema::hasColumn('fin_settings', $c) && $t->dropColumn($c);
            }
        });
        foreach (['fin_investment_links', 'fin_value_reviews', 'fin_benefit_readings', 'fin_benefits', 'fin_stage_gates', 'fin_investment_changes', 'fin_investment_scores', 'fin_business_cases', 'fin_investments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
