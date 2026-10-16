<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One scheduled finance notification per (record, key, offset, recipient) (plan C62). Rows with a NULL
 * offset (approval_overdue) never collide on any driver, so the hourly approvals sweep is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_notification_logs', function (Blueprint $t) {
            $t->unique(['notifiable_type', 'notifiable_id', 'notification_key', 'offset_days', 'sent_to_user_id'], 'fin_notification_logs_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fin_notification_logs', fn (Blueprint $t) => $t->dropUnique('fin_notification_logs_idem_unique'));
    }
};
