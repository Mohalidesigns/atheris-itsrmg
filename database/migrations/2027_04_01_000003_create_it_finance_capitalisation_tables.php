<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IT Financial Management P6: capitalisations and their memo amortisation schedule (FIN-03.13; plan P6-11..P6-14). */
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
        Schema::create('fin_capitalisations', function (Blueprint $t) {
            $this->base($t);
            $t->string('capitalisation_code', 32);
            $t->string('title', 200);
            $t->string('source_type', 12);
            $t->foreignId('investment_id')->nullable()->constrained('fin_investments')->restrictOnDelete();
            $t->foreignId('expense_request_id')->nullable()->constrained('fin_expense_requests')->restrictOnDelete();
            $t->string('standard', 8);
            $t->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->boolean('create_asset')->default(true);
            $t->string('asset_name', 200)->nullable();
            $t->string('asset_type', 20)->nullable();
            $t->foreignId('asset_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('cost_centre_id')->nullable()->constrained('fin_cost_centres')->nullOnDelete();
            $t->date('available_for_use_on');
            $t->date('capitalised_on')->nullable();
            $t->foreignId('booking_period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->decimal('amount', 18, 2);
            $t->decimal('eligible_amount', 18, 2)->default(0);
            $t->decimal('excluded_amount', 18, 2)->default(0);
            $t->text('excluded_reason')->nullable();
            $t->decimal('residual_value', 18, 2)->default(0);
            $t->unsignedSmallInteger('useful_life_months');
            $t->string('method', 16)->default('straight_line');
            $t->decimal('declining_rate_pct', 6, 2)->nullable();
            $t->json('criteria_checklist');
            $t->string('status', 12)->default('draft');
            $t->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requested_at')->nullable();
            $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->json('sod_excluded_ids')->nullable();
            $t->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'capitalisation_code']);
            $t->index(['organization_id', 'status']);
            $t->index('investment_id');
            $t->index('expense_request_id');
        });

        Schema::create('fin_amortisation_lines', function (Blueprint $t) {
            $this->base($t);
            $t->foreignId('capitalisation_id')->constrained('fin_capitalisations')->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->date('month');
            $t->foreignId('period_id')->nullable()->constrained('fin_periods')->nullOnDelete();
            $t->decimal('opening_nbv', 18, 2);
            $t->decimal('amount', 18, 2);
            $t->decimal('closing_nbv', 18, 2);
            $t->boolean('is_catch_up')->default(false);
            $t->timestamps();
            $t->unique(['capitalisation_id', 'line_no']);
            $t->index(['organization_id', 'month']);
        });

        Schema::table('fin_investments', function (Blueprint $t) {
            Schema::hasColumn('fin_investments', 'is_capitalisable') || $t->boolean('is_capitalisable')->default(false);
            Schema::hasColumn('fin_investments', 'capitalisation_standard') || $t->string('capitalisation_standard', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fin_investments', function (Blueprint $t) {
            foreach (['is_capitalisable', 'capitalisation_standard'] as $c) {
                Schema::hasColumn('fin_investments', $c) && $t->dropColumn($c);
            }
        });
        Schema::dropIfExists('fin_amortisation_lines');
        Schema::dropIfExists('fin_capitalisations');
    }
};
