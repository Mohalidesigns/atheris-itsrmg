<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.1: early termination as an approval workflow (fin_contract_terminations), per-bank CFO thresholds for renewals
 * (fin_settings) and the like-for-like / FX split of a renewal uplift (fin_contract_renewals).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_contract_terminations')) {
            Schema::create('fin_contract_terminations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('organization_id')->index();
                $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('termination_code', 32);
                $t->foreignId('contract_id')->constrained('fin_contracts')->restrictOnDelete();
                $t->text('reason');
                $t->date('requested_terminated_on');
                $t->boolean('is_post_deadline')->default(false);
                $t->text('override_reason')->nullable();
                $t->foreignId('override_document_id')->nullable()->constrained('fin_contract_documents')->nullOnDelete();
                $t->string('status', 16)->default('submitted'); // submitted, approved, rejected, withdrawn
                $t->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('requested_at')->nullable();
                $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('approved_at')->nullable();
                $t->text('rejection_reason')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['organization_id', 'termination_code']);
                $t->index(['organization_id', 'status']);
                $t->index(['contract_id', 'status']);
            });
        }

        Schema::table('fin_settings', function (Blueprint $t) {
            Schema::hasColumn('fin_settings', 'renewal_cfo_from') || $t->decimal('renewal_cfo_from', 18, 2)->nullable();
            Schema::hasColumn('fin_settings', 'renewal_cfo_uplift_pct') || $t->decimal('renewal_cfo_uplift_pct', 7, 2)->nullable();
        });

        Schema::table('fin_contract_renewals', function (Blueprint $t) {
            Schema::hasColumn('fin_contract_renewals', 'uplift_base_pct') || $t->decimal('uplift_base_pct', 7, 2)->nullable(); // total change in base currency
            Schema::hasColumn('fin_contract_renewals', 'fx_effect_pct') || $t->decimal('fx_effect_pct', 7, 2)->nullable(); // uplift_base_pct minus uplift_pct
        });
    }

    public function down(): void
    {
        Schema::table('fin_contract_renewals', fn (Blueprint $t) => $t->dropColumn(['uplift_base_pct', 'fx_effect_pct']));
        Schema::table('fin_settings', fn (Blueprint $t) => $t->dropColumn(['renewal_cfo_from', 'renewal_cfo_uplift_pct']));
        Schema::dropIfExists('fin_contract_terminations');
    }
};
