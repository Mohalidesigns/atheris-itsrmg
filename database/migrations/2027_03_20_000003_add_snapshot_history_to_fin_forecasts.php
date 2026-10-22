<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Domain M5: a period re-close keeps the earlier forecast snapshot as history (superseded) instead of deleting it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_forecasts', function (Blueprint $t) {
            $t->foreignId('period_close_id')->nullable()->after('period_id')->constrained('fin_period_closes')->nullOnDelete();
            $t->timestamp('superseded_at')->nullable()->after('cleared_by_id');
            $t->index(['organization_id', 'period_id', 'is_snapshot', 'superseded_at'], 'fin_forecasts_snapshot_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fin_forecasts', function (Blueprint $t) {
            $t->dropIndex('fin_forecasts_snapshot_idx');
            $t->dropConstrainedForeignId('period_close_id');
            $t->dropColumn('superseded_at');
        });
    }
};
