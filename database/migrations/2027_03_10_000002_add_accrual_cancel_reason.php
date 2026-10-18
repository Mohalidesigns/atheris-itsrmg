<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_accruals', function (Blueprint $t) {
            $t->text('cancel_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fin_accruals', function (Blueprint $t) {
            $t->dropColumn(['cancel_reason', 'cancelled_at']);
        });
    }
};
