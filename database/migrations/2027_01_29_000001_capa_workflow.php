<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrated CAPA (IT-GOV-CAPA-001). The Issues register already is the CAPA register; this adds
 * the multi-framework classification, the CBN cross-references, the six-stage gate state and the
 * stage records (containment actions, root cause analysis, action plan). Issues without a
 * capa_framework keep the plain open → closed lifecycle.
 *
 * incidents.customer_impact drives the CBN CPR §6.0 auto-trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->string('capa_framework', 24)->nullable()->index();
            $table->string('capa_classification', 32)->nullable();
            $table->string('framework_reference', 64)->nullable();
            $table->json('cbn_provisions')->nullable();
            $table->text('cbn_correlation')->nullable();
            $table->string('capa_stage', 24)->default('identification');
            $table->json('stage_signoffs')->nullable();
            $table->foreignId('recurrence_of_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->unsignedSmallInteger('settling_days')->nullable();
            $table->timestamp('verification_due_at')->nullable();
            $table->json('verification_criteria')->nullable();
            $table->timestamp('containment_completed_at')->nullable();
            $table->timestamp('rca_completed_at')->nullable();
            $table->timestamp('plan_approved_at')->nullable();
            $table->timestamp('implemented_at')->nullable();
            $table->timestamp('critical_notified_at')->nullable();
        });

        Schema::create('capa_containment_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->text('action');
            $table->unsignedBigInteger('performed_by_id')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('capa_root_causes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('issue_id')->unique()->constrained('issues')->cascadeOnDelete();
            $table->string('method', 16)->default('five_whys');
            $table->json('five_whys')->nullable();
            $table->json('fishbone')->nullable();
            $table->text('root_cause_summary')->nullable();
            $table->unsignedBigInteger('completed_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('capa_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->string('action_code', 48);
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('planned');
            $table->foreignId('change_request_id')->nullable()->constrained('change_requests')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->boolean('customer_impact')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', fn (Blueprint $table) => $table->dropColumn('customer_impact'));
        Schema::dropIfExists('capa_actions');
        Schema::dropIfExists('capa_root_causes');
        Schema::dropIfExists('capa_containment_actions');
        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['recurrence_of_id']);
            $table->dropIndex(['capa_framework']);
            $table->dropColumn([
                'capa_framework', 'capa_classification', 'framework_reference', 'cbn_provisions', 'cbn_correlation',
                'capa_stage', 'stage_signoffs', 'recurrence_of_id', 'settling_days', 'verification_due_at',
                'verification_criteria', 'containment_completed_at', 'rca_completed_at', 'plan_approved_at',
                'implemented_at', 'critical_notified_at',
            ]);
        });
    }
};
