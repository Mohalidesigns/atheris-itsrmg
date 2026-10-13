<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security Operations module validation (ATH-VAL-005).
 *
 * - incident_notifications gains organization_id (backfilled from the incident) so regulatory
 *   notifications are tenant data, plus preparer / submission fields for segregation of duties;
 *   delivery_status becomes a string (draft / in_review / approved / submitted).
 * - siem_integrations gains a per-connector webhook secret for HMAC-signed ingest.
 * - siem_signals records when a signal was promoted to a security alert.
 * - incidents can name the response procedure being followed.
 * - data_breaches records when the breach was discovered: the NDPA 72-hour clock runs from
 *   awareness, not from when the record was typed in.
 * - Data clean-up: incident types are unified on Incident::TYPES, and demo CVE ids lose the
 *   "-vNN" suffix that stopped them matching the EPSS and KEV feeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->index()->after('id');
            $table->string('regulator_code', 16)->nullable()->after('template_key');
            $table->unsignedBigInteger('prepared_by_id')->nullable()->after('draft_body');
            $table->timestamp('submitted_at')->nullable()->after('signed_at');
            $table->unsignedBigInteger('submitted_by_id')->nullable()->after('submitted_at');
            $table->string('submission_reference')->nullable()->after('submitted_by_id');
        });
        // The lifecycle is draft → in_review → approved → submitted; the old enum had no "submitted".
        Schema::table('incident_notifications', function (Blueprint $table) {
            $table->string('delivery_status', 24)->default('draft')->change();
        });
        // Postgres keeps the enum's CHECK constraint across ->change(); MySQL/SQLite rebuild the column.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE incident_notifications DROP CONSTRAINT IF EXISTS incident_notifications_delivery_status_check');
        }
        DB::table('incident_notifications')->whereIn('delivery_status', ['delivered', 'acknowledged'])->update(['delivery_status' => 'submitted']);
        DB::table('incident_notifications')->where('delivery_status', 'signed')->update(['delivery_status' => 'approved']);

        Schema::table('siem_integrations', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('config');
        });

        Schema::table('siem_signals', function (Blueprint $table) {
            $table->unsignedBigInteger('security_alert_id')->nullable()->after('incident_id');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('response_procedure_id')->nullable()->after('lead_investigator_id');
        });

        Schema::table('data_breaches', function (Blueprint $table) {
            $table->timestamp('discovered_at')->nullable()->after('records_affected');
        });

        // Notifications belong to their incident's bank; orphans (no incident) are dropped.
        foreach (DB::table('incident_notifications')->get(['id', 'incident_id', 'template_key']) as $n) {
            $orgId = DB::table('incidents')->where('id', $n->incident_id)->value('organization_id');
            if (! $orgId) {
                DB::table('incident_notifications')->where('id', $n->id)->delete();

                continue;
            }
            DB::table('incident_notifications')->where('id', $n->id)->update([
                'organization_id' => $orgId,
                'regulator_code' => DB::table('notification_templates')->where('template_key', $n->template_key)->value('regulator_code'),
            ]);
        }

        // The breach was discovered when its incident was detected, or failing that when it was logged.
        foreach (DB::table('data_breaches')->get(['id', 'incident_id', 'created_at']) as $b) {
            $detected = $b->incident_id ? DB::table('incidents')->where('id', $b->incident_id)->value('detected_at') : null;
            DB::table('data_breaches')->where('id', $b->id)->update(['discovered_at' => $detected ?? $b->created_at]);
        }

        DB::table('incidents')->where('type', 'dos')->update(['type' => 'denial_of_service']);
        DB::table('incidents')->where('type', 'data_leak')->update(['type' => 'data_breach']);

        foreach (DB::table('vulnerabilities')->where('cve_id', 'like', 'CVE-%-v%')->get(['id', 'cve_id']) as $v) {
            DB::table('vulnerabilities')->where('id', $v->id)
                ->update(['cve_id' => preg_replace('/-v\d+$/', '', $v->cve_id)]);
        }
    }

    public function down(): void
    {
        Schema::table('data_breaches', fn (Blueprint $t) => $t->dropColumn('discovered_at'));
        Schema::table('incidents', fn (Blueprint $t) => $t->dropColumn('response_procedure_id'));
        Schema::table('siem_signals', fn (Blueprint $t) => $t->dropColumn('security_alert_id'));
        Schema::table('siem_integrations', fn (Blueprint $t) => $t->dropColumn('webhook_secret'));
        Schema::table('incident_notifications', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropColumn(['organization_id', 'regulator_code', 'prepared_by_id', 'submitted_at', 'submitted_by_id', 'submission_reference']);
        });
    }
};
