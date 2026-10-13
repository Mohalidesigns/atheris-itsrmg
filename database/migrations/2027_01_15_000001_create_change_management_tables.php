<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change Management (Change Enablement) module — ATH-CHG-001 §2.
 *
 * Risk-based approval routing (per-bank stage policy + per-change approval snapshots), CAB / ECAB
 * boards with members, votes and meetings, freeze windows, standard (pre-approved) change
 * templates, implementation tasks and a per-change timeline. Every tenant table carries
 * organization_id; change_cab_members, change_cab_meeting_items and change_events are children
 * reached only through their scoped parents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_standard_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->string('category', 32)->default('other');
            $table->text('description')->nullable();
            $table->text('implementation_plan')->nullable();
            $table->text('backout_plan')->nullable();
            $table->string('risk_level', 16)->default('low');
            $table->unsignedInteger('expected_downtime_minutes')->nullable();
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('review_due_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
        });

        Schema::create('change_cab_boards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->string('type', 8)->default('cab');
            $table->unsignedBigInteger('chair_id')->nullable();
            $table->unsignedTinyInteger('quorum')->default(3);
            $table->string('meeting_cadence')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('change_cab_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('change_cab_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->string('represents', 64)->nullable();
            $table->boolean('is_voting')->default(true);
            $table->timestamps();
            $table->unique(['board_id', 'user_id']);
        });

        Schema::create('change_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->unsignedTinyInteger('sequence')->default(10);
            $table->string('key', 32);
            $table->json('applies_to_types')->nullable();
            $table->string('min_risk_level', 16)->default('low');
            $table->string('approver_type', 16)->default('role');
            $table->string('approver_ref')->nullable();
            $table->unsignedTinyInteger('quorum')->nullable();
            $table->unsignedInteger('sla_hours')->default(48);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('change_code', 32)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('justification')->nullable();
            $table->string('change_type', 16)->default('normal');
            $table->string('category', 32)->default('other');
            $table->string('priority', 16)->default('medium');
            $table->unsignedTinyInteger('impact')->default(3);
            $table->unsignedTinyInteger('likelihood')->default(3);
            $table->unsignedTinyInteger('risk_score')->default(9);
            $table->string('risk_level', 16)->default('medium');
            $table->text('impact_assessment')->nullable();
            $table->text('implementation_plan')->nullable();
            $table->text('backout_plan')->nullable();
            $table->text('test_plan')->nullable();
            $table->text('communication_plan')->nullable();
            $table->boolean('outage_required')->default(false);
            $table->unsignedInteger('expected_downtime_minutes')->nullable();
            $table->json('affected_asset_ids')->nullable();
            $table->json('affected_services')->nullable();
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference')->nullable();
            $table->unsignedBigInteger('standard_template_id')->nullable();
            $table->unsignedBigInteger('requester_id')->nullable();
            $table->unsignedBigInteger('change_owner_id')->nullable();
            $table->unsignedBigInteger('implementer_id')->nullable();
            $table->unsignedBigInteger('change_manager_id')->nullable();
            $table->boolean('cab_required')->default(false);
            $table->string('status', 24)->default('draft')->index();
            $table->timestamp('scheduled_start_at')->nullable()->index();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->text('freeze_override_reason')->nullable();
            $table->boolean('started_before_approval')->default(false);
            $table->text('emergency_start_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('implemented_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->boolean('pir_required')->default(false);
            $table->string('pir_outcome', 32)->nullable();
            $table->text('pir_summary')->nullable();
            $table->unsignedBigInteger('pir_by_id')->nullable();
            $table->string('closure_code', 32)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('change_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('change_request_id')->constrained('change_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->string('stage_key', 32);
            $table->string('stage_name');
            $table->unsignedTinyInteger('sequence')->default(10);
            $table->string('approver_type', 16)->default('role');
            $table->string('approver_ref')->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->unsignedBigInteger('board_id')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('decided_by_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('change_cab_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('change_request_id')->constrained('change_requests')->cascadeOnDelete();
            $table->foreignId('approval_id')->constrained('change_approvals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('vote', 16);
            $table->text('comment')->nullable();
            $table->timestamp('voted_at')->nullable();
            $table->timestamps();
            $table->unique(['approval_id', 'user_id']);
        });

        Schema::create('change_cab_meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('board_id')->constrained('change_cab_boards')->cascadeOnDelete();
            $table->string('title');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->unsignedBigInteger('chaired_by_id')->nullable();
            $table->text('minutes')->nullable();
            $table->timestamps();
        });

        Schema::create('change_cab_meeting_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('change_cab_meetings')->cascadeOnDelete();
            $table->foreignId('change_request_id')->constrained('change_requests')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->string('decision', 16)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'change_request_id']);
        });

        Schema::create('change_freeze_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('scope', 16)->default('all');
            $table->json('categories')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('allow_emergency')->default(true);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
        });

        Schema::create('change_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->foreignId('change_request_id')->constrained('change_requests')->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 24)->default('implementation');
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->string('status', 16)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('change_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained('change_requests')->cascadeOnDelete();
            $table->string('event_type', 32)->index();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_events');
        Schema::dropIfExists('change_tasks');
        Schema::dropIfExists('change_freeze_windows');
        Schema::dropIfExists('change_cab_meeting_items');
        Schema::dropIfExists('change_cab_meetings');
        Schema::dropIfExists('change_cab_votes');
        Schema::dropIfExists('change_approvals');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('change_approval_stages');
        Schema::dropIfExists('change_cab_members');
        Schema::dropIfExists('change_cab_boards');
        Schema::dropIfExists('change_standard_templates');
    }
};
