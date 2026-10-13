<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issues & Remediation module validation (ATH-VAL-006).
 *
 * - issues gain a per-bank reference (KHB-ISS-001), a free-text source reference for findings
 *   with no record in the platform (an internal audit report, an NDPC observation), an SLA due
 *   time that pauses while blocked, a two-level escalation flag, and who remediated / verified
 *   / closed the issue (segregation of duties), plus the ITSM ticket it is mirrored to.
 *   "escalated" is no longer a lifecycle status: an escalated issue is still being worked, so
 *   existing escalated rows become in_progress at escalation level 1.
 * - issue_events gain a readable description and become the issue's timeline.
 * - itsm_connections replaces the hard-coded Jira / ServiceNow / Freshservice cards: one row per
 *   bank connector, with an encrypted API token and an HMAC webhook secret for status mirroring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->string('issue_code', 32)->nullable()->after('organization_id')->index();
            $table->string('source_reference')->nullable()->after('source_id');
            $table->timestamp('sla_due_at')->nullable()->after('sla_paused_minutes');
            $table->timestamp('sla_paused_at')->nullable()->after('sla_due_at');
            $table->text('blocked_reason')->nullable()->after('status');
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('blocked_reason');
            $table->timestamp('escalated_at')->nullable()->after('escalation_level');
            $table->unsignedBigInteger('created_by_id')->nullable()->after('owner_id');
            $table->timestamp('remediated_at')->nullable()->after('remediation_plan');
            $table->unsignedBigInteger('remediated_by_id')->nullable()->after('remediated_at');
            $table->timestamp('verified_at')->nullable()->after('remediated_by_id');
            $table->unsignedBigInteger('verified_by_id')->nullable()->after('verified_at');
            $table->timestamp('closed_at')->nullable()->after('verified_by_id');
            $table->unsignedBigInteger('itsm_connection_id')->nullable()->after('external_ticket_system');
            $table->string('external_ticket_url')->nullable()->after('itsm_connection_id');
            $table->string('external_ticket_status', 64)->nullable()->after('external_ticket_url');
            $table->timestamp('ticket_synced_at')->nullable()->after('external_ticket_status');
        });

        Schema::table('issue_events', function (Blueprint $table) {
            $table->text('description')->nullable()->after('event_type');
        });

        Schema::create('itsm_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('system', 24);
            $table->string('name');
            $table->string('base_url');
            $table->string('project_key', 64)->nullable();
            $table->string('username')->nullable();
            $table->text('api_token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('mode', 16)->default('sandbox');
            $table->string('status', 16)->default('active');
            $table->string('auto_push_severity', 16)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // Escalation is a flag on a live issue, not a place in the lifecycle.
        DB::table('issues')->where('status', 'escalated')->update([
            'status' => 'in_progress', 'escalation_level' => 1, 'escalated_at' => now(),
        ]);

        // SLA due time from the bank's policy (issue "moderate" is the policy's "medium").
        foreach (DB::table('issues')->whereNull('sla_due_at')->get(['id', 'organization_id', 'severity', 'created_at']) as $issue) {
            $severity = $issue->severity === 'moderate' ? 'medium' : $issue->severity;
            $hours = DB::table('vulnerability_sla_policies')->where('organization_id', $issue->organization_id)
                ->where('severity', $severity)->value('hours_to_remediate')
                ?? ['critical' => 72, 'high' => 336, 'medium' => 720, 'low' => 2160][$severity] ?? 720;
            $opened = $issue->created_at ? Carbon::parse($issue->created_at) : now();
            DB::table('issues')->where('id', $issue->id)->update([
                'sla_due_at' => $opened->copy()->addHours($hours),
                'sla_minutes' => $hours * 60,
            ]);
        }

        // Per-bank reference series, oldest first.
        foreach (DB::table('issues')->whereNull('issue_code')->distinct()->pluck('organization_id') as $orgId) {
            $n = 0;
            foreach (DB::table('issues')->where('organization_id', $orgId)->orderBy('id')->pluck('id') as $id) {
                DB::table('issues')->where('id', $id)->update(['issue_code' => 'ISS-'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('itsm_connections');
        Schema::table('issue_events', fn (Blueprint $table) => $table->dropColumn('description'));
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['issue_code']);
            $table->dropColumn([
                'issue_code', 'source_reference', 'sla_due_at', 'sla_paused_at', 'blocked_reason',
                'escalation_level', 'escalated_at', 'created_by_id', 'remediated_at', 'remediated_by_id',
                'verified_at', 'verified_by_id', 'closed_at', 'itsm_connection_id', 'external_ticket_url',
                'external_ticket_status', 'ticket_synced_at',
            ]);
        });
    }
};
