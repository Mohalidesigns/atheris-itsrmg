<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** IT Financial Management P6: per-bank FinOps and showback settings. Null means the config default applies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_settings', function (Blueprint $t) {
            Schema::hasColumn('fin_settings', 'cloud_anomaly_pct') || $t->decimal('cloud_anomaly_pct', 6, 2)->nullable();
            Schema::hasColumn('fin_settings', 'cloud_anomaly_abs_floor') || $t->decimal('cloud_anomaly_abs_floor', 18, 2)->nullable();
            Schema::hasColumn('fin_settings', 'showback_units') || $t->json('showback_units')->nullable();
            Schema::hasColumn('fin_settings', 'chargeback_debit_gl') || $t->string('chargeback_debit_gl', 32)->nullable();
            Schema::hasColumn('fin_settings', 'chargeback_credit_gl') || $t->string('chargeback_credit_gl', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fin_settings', function (Blueprint $t) {
            foreach (['cloud_anomaly_pct', 'cloud_anomaly_abs_floor', 'showback_units', 'chargeback_debit_gl', 'chargeback_credit_gl'] as $c) {
                Schema::hasColumn('fin_settings', $c) && $t->dropColumn($c);
            }
        });
    }
};
