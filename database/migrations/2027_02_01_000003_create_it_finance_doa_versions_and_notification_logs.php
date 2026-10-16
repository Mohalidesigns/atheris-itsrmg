<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned DoA bands with maker-checker (ATH-FIN-003 C8/S7/D5), the finance notification log
 * (ATH-FIN-001 §4.7) and the band snapshot context on approval requests (plan C31).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_doa_band_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedSmallInteger('version_no');
            $t->json('bands');
            $t->date('effective_from');
            $t->string('status', 16);
            $t->text('reason')->nullable();
            $t->foreignId('proposed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('proposed_at');
            $t->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('superseded_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'version_no']);
            $t->index(['organization_id', 'status', 'effective_from']);
        });

        $defaults = config('finance.settings_defaults.doa_bands');
        foreach (DB::table('fin_settings')->get() as $s) {
            $bands = $s->doa_bands ?: json_encode($defaults);
            $now = now();
            DB::table('fin_doa_band_versions')->insert([
                'organization_id' => $s->organization_id, 'version_no' => 1, 'bands' => $bands,
                'effective_from' => substr((string) ($s->created_at ?? $now), 0, 10), 'status' => 'active',
                'reason' => 'Initial bands', 'proposed_at' => $now, 'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::create('fin_notification_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('notifiable_type');
            $t->unsignedBigInteger('notifiable_id');
            $t->string('notification_key', 64);
            $t->smallInteger('offset_days')->nullable();
            $t->foreignId('sent_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('sent_at');
            $t->string('channel', 16)->default('database');
            $t->timestamps();
            $t->index(['notifiable_type', 'notifiable_id', 'notification_key'], 'fin_notification_logs_subject_key_index');
            $t->index(['organization_id', 'sent_at']);
        });

        if (! Schema::hasColumn('approval_requests', 'context')) {
            Schema::table('approval_requests', fn (Blueprint $t) => $t->json('context')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('approval_requests', 'context')) {
            Schema::table('approval_requests', fn (Blueprint $t) => $t->dropColumn('context'));
        }
        Schema::dropIfExists('fin_notification_logs');
        Schema::dropIfExists('fin_doa_band_versions');
    }
};
