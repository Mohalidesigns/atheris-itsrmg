<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business Continuity validation (ATH-VAL-012).
 *
 * - dr_exercises had no organization_id (and DrRunbook / DrExercise no tenant scope), so the DR
 *   pages showed every bank's runbooks and exercises. Exercises take their runbook's organisation
 *   and record the RTO they were meant to meet and the one they achieved.
 * - bia_records name a process in free text. They now name the EA business service they assess
 *   (module 7 left "BIA writes RTO/RPO onto the service" to this module), and record who approved
 *   the BIA and when its objectives were applied to the service.
 * - bcp_plan_service: the business services a plan recovers.
 * - Draft plans carrying an approver (demo data) lose it: a draft has not been approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dr_exercises', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedInteger('rto_target_min')->nullable()->after('finished_at');
            $table->unsignedInteger('rto_achieved_min')->nullable()->after('rto_target_min');
            $table->string('result', 16)->nullable()->after('status');
            $table->text('lessons')->nullable()->after('outcome_notes');
        });

        Schema::table('bia_records', function (Blueprint $table) {
            $table->foreignId('business_service_id')->nullable()->after('organization_id')->constrained('ea_business_services')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('applied_at')->nullable()->after('approved_at');
        });

        Schema::create('bcp_plan_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('bcp_plans')->cascadeOnDelete();
            $table->foreignId('business_service_id')->constrained('ea_business_services')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['plan_id', 'business_service_id']);
        });

        $runbooks = DB::table('dr_runbooks')->get(['id', 'organization_id', 'estimated_duration_min'])->keyBy('id');
        foreach (DB::table('dr_exercises')->get(['id', 'runbook_id']) as $exercise) {
            $runbook = $runbooks[$exercise->runbook_id] ?? null;
            DB::table('dr_exercises')->where('id', $exercise->id)->update([
                'organization_id' => $runbook?->organization_id,
                'rto_target_min' => $runbook?->estimated_duration_min,
            ]);
        }

        DB::table('bcp_plans')->where('status', 'draft')->whereNotNull('approved_by')->update(['approved_by' => null, 'approved_at' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bcp_plan_service');
        Schema::table('bia_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_service_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'applied_at']);
        });
        Schema::table('dr_exercises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['rto_target_min', 'rto_achieved_min', 'result', 'lessons']);
        });
    }
};
