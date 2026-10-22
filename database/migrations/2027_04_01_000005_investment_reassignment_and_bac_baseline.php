<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** P6 carry-overs (C168): reassignment as an investment change, the baselined BAC for tolerance (domain L2) and the capitalisable flag (L6). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_investment_changes', function (Blueprint $t) {
            Schema::hasColumn('fin_investment_changes', 'kind') || $t->string('kind', 12)->default('scope');
            Schema::hasColumn('fin_investment_changes', 'field_changes') || $t->json('field_changes')->nullable();
        });
        Schema::table('fin_investments', function (Blueprint $t) {
            Schema::hasColumn('fin_investments', 'bac_baseline_amount') || $t->decimal('bac_baseline_amount', 18, 2)->nullable();
            Schema::hasColumn('fin_investments', 'is_capitalisable') || $t->boolean('is_capitalisable')->default(false);
            Schema::hasColumn('fin_investments', 'capitalisation_standard') || $t->string('capitalisation_standard', 8)->nullable();
        });
        DB::table('fin_investments')->whereNotNull('bac_baselined_at')->whereNull('bac_baseline_amount')->update(['bac_baseline_amount' => DB::raw('bac')]);
    }

    public function down(): void
    {
        Schema::table('fin_investment_changes', function (Blueprint $t) {
            Schema::hasColumn('fin_investment_changes', 'kind') && $t->dropColumn('kind');
            Schema::hasColumn('fin_investment_changes', 'field_changes') && $t->dropColumn('field_changes');
        });
        Schema::table('fin_investments', function (Blueprint $t) {
            Schema::hasColumn('fin_investments', 'bac_baseline_amount') && $t->dropColumn('bac_baseline_amount');
        });
    }
};
