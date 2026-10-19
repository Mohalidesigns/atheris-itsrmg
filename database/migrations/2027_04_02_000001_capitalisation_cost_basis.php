<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P6 review H2: IAS 16 / IAS 38 cost basis. Recognition-start date, the actuals a capitalisation claimed, and the per-bank "VAT is recoverable" switch. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_capitalisations', function (Blueprint $t) {
            Schema::hasColumn('fin_capitalisations', 'recognition_date') || $t->date('recognition_date')->nullable();
            Schema::hasColumn('fin_capitalisations', 'consumed_actual_ids') || $t->json('consumed_actual_ids')->nullable();
        });
        Schema::table('fin_settings', function (Blueprint $t) {
            Schema::hasColumn('fin_settings', 'capitalisation_vat_recoverable') || $t->boolean('capitalisation_vat_recoverable')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('fin_capitalisations', function (Blueprint $t) {
            foreach (['recognition_date', 'consumed_actual_ids'] as $c) {
                Schema::hasColumn('fin_capitalisations', $c) && $t->dropColumn($c);
            }
        });
        Schema::table('fin_settings', function (Blueprint $t) {
            Schema::hasColumn('fin_settings', 'capitalisation_vat_recoverable') && $t->dropColumn('capitalisation_vat_recoverable');
        });
    }
};
