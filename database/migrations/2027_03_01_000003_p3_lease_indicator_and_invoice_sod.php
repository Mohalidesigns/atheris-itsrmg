<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P3: IFRS 16 lease assessment on contracts (L4) and the per-bank invoice segregation-of-duties threshold. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_contracts', function (Blueprint $t) {
            $t->string('lease_assessment', 24)->nullable();
            $t->text('lease_assessment_note')->nullable();
        });
        Schema::table('fin_settings', function (Blueprint $t) {
            $t->decimal('invoice_sod_from', 18, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fin_settings', fn (Blueprint $t) => $t->dropColumn('invoice_sod_from'));
        Schema::table('fin_contracts', fn (Blueprint $t) => $t->dropColumn(['lease_assessment', 'lease_assessment_note']));
    }
};
