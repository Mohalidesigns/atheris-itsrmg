<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATH-EAR-002 Phase 1 — "Make it stay true".
 *
 * §4 rates rows 13 (surveys), 14 (freshness enforcement) and 33 (ownership) as
 * "the emergency": the three mechanics that keep an EA repository alive, on
 * which the module scored 0. "Without them the module dies in every pilot
 * regardless of what else we build."
 *
 * Tables per §8.1:
 *   ea_subscriptions      B1 — the identity spine. Ownership becomes a
 *                              relationship, not a free-text field.
 *   ea_quality_seals      B3 — per-entity freshness state machine.
 *   ea_seal_policies      B3 — per-entity-type auto-expiry interval and the
 *                              mandatory attributes that gate approval.
 *   ea_surveys            B2 — survey definition.
 *   ea_survey_campaigns   B2 — one execution of a survey.
 *   ea_survey_responses   B2 — per-recipient, per-entity, magic-link token.
 *   ea_decision_records   B4 — Architecture Decision Records.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------------- B1 — Ownership & subscriptions ---------------- */
        Schema::create('ea_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('entity_type', 128);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // responsible | accountable | consulted | observer.
            // LeanIX builds its entire freshness model on Responsible /
            // Accountable / Observer; Consulted completes the RACI so the
            // survey engine can address a wider audience than the approvers.
            $table->string('role', 16)->default('responsible');
            // Optional business role label — "Application Owner", "Solution
            // Architect", "Data Steward" — so one person can hold a named seat
            // rather than an anonymous subscription.
            $table->string('business_role', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'user_id', 'role'], 'ea_subs_unique');
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'role']);
        });

        /* ---------------- B3 — Quality seal ---------------- */
        Schema::create('ea_quality_seals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('entity_type', 128);
            $table->unsignedBigInteger('entity_id');
            // draft | approved | check_needed | rejected  (LeanIX's four states)
            $table->string('state', 16)->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            // When the seal breaks on schedule regardless of whether anything
            // changed — the mechanic that forces periodic re-validation.
            $table->timestamp('expires_at')->nullable();
            $table->string('break_reason', 255)->nullable();
            $table->timestamp('broken_at')->nullable();
            $table->unsignedBigInteger('broken_by')->nullable();
            // Weighted completeness at the time of last computation, 0-100.
            $table->unsignedTinyInteger('completeness')->default(0);
            $table->json('missing_attributes')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id'], 'ea_seals_unique');
            $table->index(['state', 'expires_at']);
        });

        Schema::create('ea_seal_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('entity_type', 128);
            // LeanIX offers 30 or 90; the plan (B3) asks for 30/60/90.
            $table->unsignedSmallInteger('renewal_interval_days')->default(90);
            $table->boolean('auto_expiry_enabled')->default(true);
            // Attributes that must be populated before the seal may be
            // approved, and which drive the completeness score.
            $table->json('mandatory_attributes')->nullable();
            $table->json('optional_attributes')->nullable();
            $table->boolean('break_on_edit')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'entity_type'], 'ea_seal_policy_unique');
        });

        /* ---------------- B2 — Surveys & campaigns ---------------- */
        Schema::create('ea_surveys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('entity_type', 128);
            // Saved filter narrowing which entities are in scope, e.g.
            // {"criticality": "critical"}. Empty means the whole catalogue.
            $table->json('scope_filter')->nullable();
            // Field schema: [{attribute, label, type, options, required, help}]
            // Each field names a real column on the target entity — §5.4 B2
            // requires the form builder to write "directly to entity fields".
            $table->json('fields');
            // subscription | query — how the audience is resolved.
            $table->string('audience_mode', 16)->default('subscription');
            // Which subscription roles receive it, when audience_mode is
            // subscription.
            $table->json('audience_roles')->nullable();
            // Extra named recipients (non-licensed users) by email.
            $table->json('additional_recipients')->nullable();
            // once | weekly | monthly | quarterly | annually
            $table->string('cadence', 16)->default('once');
            // Staleness trigger — only include entities whose seal has not been
            // approved within N days. Ardoq's "not updated in 6 months".
            $table->unsignedSmallInteger('stale_after_days')->nullable();
            $table->unsignedSmallInteger('window_days')->default(14);
            $table->unsignedSmallInteger('reminder_interval_days')->default(7);
            $table->unsignedTinyInteger('max_reminders')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'ea_surveys_code_unique');
        });

        Schema::create('ea_survey_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('survey_id')->constrained('ea_surveys')->cascadeOnDelete();
            $table->string('code', 40);
            // draft | running | closed | cancelled
            $table->string('state', 16)->default('running');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            // Point-in-time record of who was asked about what, so completion
            // rate stays meaningful even after subscriptions change.
            $table->json('audience_snapshot')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('entities_count')->default(0);
            $table->unsignedInteger('responses_count')->default(0);
            $table->string('launched_by')->nullable();
            $table->timestamps();

            $table->index(['state', 'closes_at']);
        });

        Schema::create('ea_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('campaign_id')->constrained('ea_survey_campaigns')->cascadeOnDelete();
            $table->string('entity_type', 128);
            $table->unsignedBigInteger('entity_id');
            // Nullable: §5.4 B2 requires "magic-link responses from non-licensed
            // users". LeanIX can only survey active licensed users, which is a
            // structural crowdsourcing ceiling — this is where we beat it.
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_role', 16)->nullable();
            $table->string('token', 64)->unique();
            // pending | opened | submitted | declined | expired
            $table->string('state', 16)->default('pending');
            $table->json('answers')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'state']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('recipient_user_id');
        });

        /* ---------------- B4 — Architecture Decision Records ---------------- */
        Schema::create('ea_decision_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('title');
            // The classic Nygard ADR body.
            $table->text('context')->nullable();
            $table->text('decision')->nullable();
            $table->text('consequences')->nullable();
            $table->text('alternatives')->nullable();
            // proposed | accepted | rejected | superseded | deprecated
            $table->string('status', 16)->default('proposed');
            $table->date('decided_on')->nullable();
            $table->string('decided_by')->nullable();
            // Supersession chain — an ADR is never edited into a new decision,
            // it is superseded by one, so the reasoning history survives.
            $table->unsignedBigInteger('supersedes_id')->nullable()->index();
            $table->unsignedBigInteger('arb_submission_id')->nullable()->index();
            $table->unsignedBigInteger('initiative_id')->nullable()->index();
            // [{entity_type, entity_id}] — what this decision constrains.
            $table->json('linked_entities')->nullable();
            $table->json('impacted_principles')->nullable();
            $table->json('impacted_standards')->nullable();
            $table->string('driver', 64)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'ea_adr_code_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ea_decision_records');
        Schema::dropIfExists('ea_survey_responses');
        Schema::dropIfExists('ea_survey_campaigns');
        Schema::dropIfExists('ea_surveys');
        Schema::dropIfExists('ea_seal_policies');
        Schema::dropIfExists('ea_quality_seals');
        Schema::dropIfExists('ea_subscriptions');
    }
};
