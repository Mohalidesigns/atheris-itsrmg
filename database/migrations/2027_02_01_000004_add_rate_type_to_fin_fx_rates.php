<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rate type (spot / average / closing / budget) joins the FX uniqueness key (ATH-FIN-003 D8, plan C47). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_fx_rates', fn (Blueprint $t) => $t->string('rate_type', 16)->default('spot'));
        Schema::table('fin_fx_rates', function (Blueprint $t) {
            $t->dropUnique('fin_fx_rates_pair_date_unique');
            $t->unique(['organization_id', 'from_currency', 'to_currency', 'rate_date', 'rate_type'], 'fin_fx_rates_pair_date_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fin_fx_rates', function (Blueprint $t) {
            $t->dropUnique('fin_fx_rates_pair_date_type_unique');
            $t->unique(['organization_id', 'from_currency', 'to_currency', 'rate_date'], 'fin_fx_rates_pair_date_unique');
        });
        Schema::table('fin_fx_rates', fn (Blueprint $t) => $t->dropColumn('rate_type'));
    }
};
