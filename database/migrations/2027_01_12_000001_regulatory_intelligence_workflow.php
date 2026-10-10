<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regulatory Intelligence module validation (ATH-VAL-004).
 *
 * - Circulars stay platform-wide (organization_id NULL = Content-Ops feed), but a bank can now hold
 *   privately ingested circulars from Document Intelligence (organization_id set).
 * - circular_assessments: each bank's own triage of a circular (Unread / Assessed / Actioned).
 * - circular_impacts gains organization_id: impacts point at a bank's own obligations, controls,
 *   policies and risks, so they are tenant data.
 * - doc_intelligence_jobs records the real upload and its review.
 * - return_runs records preparer, approver and submitter for segregation of duties.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regulatory_circulars', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->index()->after('id');
            $table->unsignedBigInteger('doc_intelligence_job_id')->nullable()->after('status');
        });

        Schema::create('circular_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('circular_id')->constrained('regulatory_circulars')->cascadeOnDelete();
            // unread / assessed / actioned / not_applicable
            $table->string('status', 24)->default('unread');
            // applicable / partially_applicable / not_applicable
            $table->string('applicability', 32)->nullable();
            $table->string('severity', 16)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('assessed_by_id')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'circular_id'], 'circular_assessments_org_circular_unique');
        });

        Schema::table('circular_impacts', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->index()->after('id');
            $table->string('action_required')->nullable()->after('rationale');
        });

        Schema::table('doc_intelligence_jobs', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('source_file');
            $table->string('mime_type', 128)->nullable()->after('original_name');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
            $table->unsignedBigInteger('uploaded_by_id')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->unsignedBigInteger('vendor_id')->nullable()->after('review_notes');
            $table->unsignedBigInteger('circular_id')->nullable()->after('vendor_id');
        });

        Schema::table('return_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('prepared_by_id')->nullable()->after('data');
            $table->unsignedBigInteger('approved_by_id')->nullable()->after('prepared_by_id');
            $table->timestamp('approved_at')->nullable()->after('approved_by_id');
            $table->unsignedBigInteger('submitted_by_id')->nullable()->after('approved_at');
            $table->string('submission_reference', 128)->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('return_runs', function (Blueprint $table) {
            $table->dropColumn(['prepared_by_id', 'approved_by_id', 'approved_at', 'submitted_by_id', 'submission_reference']);
        });
        Schema::table('doc_intelligence_jobs', function (Blueprint $table) {
            $table->dropColumn(['original_name', 'mime_type', 'size_bytes', 'uploaded_by_id', 'reviewed_at', 'review_notes', 'vendor_id', 'circular_id']);
        });
        Schema::table('circular_impacts', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropColumn(['organization_id', 'action_required']);
        });
        Schema::dropIfExists('circular_assessments');
        Schema::table('regulatory_circulars', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropColumn(['organization_id', 'doc_intelligence_job_id']);
        });
    }
};
