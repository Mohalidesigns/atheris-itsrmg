<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IT Financial Management P4: forecasts (overrides and close snapshots), variance notes and comments, period close records. */
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
        Schema::create('fin_forecasts', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('budget_line_id')->constrained('fin_budget_lines')->cascadeOnDelete();
            $t->unsignedBigInteger('line_key');
            $t->foreignId('period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->string('method', 16);
            $t->decimal('amount', 18, 2);
            $t->decimal('base_amount', 18, 2);
            $t->text('reason')->nullable();
            $t->foreignId('set_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_snapshot')->default(false);
            $t->timestamp('cleared_at')->nullable();
            $t->foreignId('cleared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['organization_id', 'fiscal_year_id', 'line_key']);
            $t->index(['organization_id', 'period_id', 'is_snapshot']);
        });

        Schema::create('fin_variance_notes', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->foreignId('budget_line_id')->constrained('fin_budget_lines')->restrictOnDelete();
            $t->unsignedBigInteger('line_key');
            $t->foreignId('cost_centre_id')->constrained('fin_cost_centres')->restrictOnDelete();
            $t->string('basis', 8)->default('ytd');
            $t->decimal('budget_amount', 18, 2);
            $t->decimal('actual_amount', 18, 2);
            $t->decimal('variance_amount', 18, 2);
            $t->decimal('variance_pct', 9, 2)->nullable();
            $t->decimal('period_variance_amount', 18, 2)->nullable();
            $t->string('direction', 8);
            $t->string('severity', 8);
            $t->string('peak_severity', 8);
            $t->boolean('is_breaching')->default(true);
            $t->string('status', 16)->default('open');
            $t->text('explanation')->nullable();
            $t->text('corrective_action')->nullable();
            $t->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('explained_at')->nullable();
            $t->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('evaluated_at');
            $t->timestamps();
            $t->unique(['organization_id', 'line_key', 'period_id']);
            $t->index(['organization_id', 'fiscal_year_id', 'period_id', 'severity']);
            $t->index(['organization_id', 'status']);
        });

        Schema::create('fin_variance_comments', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('variance_note_id')->constrained('fin_variance_notes')->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->text('body');
            $t->timestamps();
            $t->index('variance_note_id');
        });

        Schema::create('fin_period_closes', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('fiscal_year_id')->constrained('fin_fiscal_years')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('fin_periods')->restrictOnDelete();
            $t->string('status', 16);
            $t->json('checklist');
            $t->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('locked_at')->nullable();
            $t->string('reopen_status', 16)->nullable();
            $t->text('reopen_reason')->nullable();
            $t->foreignId('reopen_requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reopen_requested_at')->nullable();
            $t->foreignId('reopen_approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $t->foreignId('reopen_decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reopen_decided_at')->nullable();
            $t->text('reopen_rejection_reason')->nullable();
            $t->timestamp('reopened_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'period_id', 'id']);
            $t->index(['organization_id', 'reopen_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_period_closes');
        Schema::dropIfExists('fin_variance_comments');
        Schema::dropIfExists('fin_variance_notes');
        Schema::dropIfExists('fin_forecasts');
    }
};
