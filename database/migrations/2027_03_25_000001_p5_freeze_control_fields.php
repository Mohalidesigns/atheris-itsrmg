<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_investments', function (Blueprint $t) {
            Schema::hasColumn('fin_investments', 'bac_baselined_tolerance_pct') || $t->decimal('bac_baselined_tolerance_pct', 5, 2)->nullable();
        });
        Schema::table('fin_stage_gates', function (Blueprint $t) {
            Schema::hasColumn('fin_stage_gates', 'sod_excluded_ids') || $t->json('sod_excluded_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fin_investments', fn (Blueprint $t) => $t->dropColumn('bac_baselined_tolerance_pct'));
        Schema::table('fin_stage_gates', fn (Blueprint $t) => $t->dropColumn('sod_excluded_ids'));
    }
};
