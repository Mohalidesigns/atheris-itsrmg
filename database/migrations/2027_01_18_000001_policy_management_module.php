<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Policy Management validation (ATH-VAL-009).
 *
 * - One status vocabulary. The model, filters and publish action used draft / in_review /
 *   approved / published / retired; the data used draft / under_review / approved / active /
 *   expired / archived, so most policies matched no filter and "Publish" wrote a status nothing
 *   else read. Canonical: draft → in_review → approved → active → retired. An "expired" policy is
 *   one still in force past its review date, so it becomes active with the review overdue.
 * - Categories: the data used security / privacy / risk / governance, which the model did not
 *   know. They map onto the model's categories (two are added: governance, risk_management).
 * - policies record who submitted for approval and why a policy was retired, so approval can be
 *   segregated from authorship.
 * - Attestations: one vocabulary (pending / completed / waived; overdue is a pending attestation
 *   past its due date, derived, not stored). They record the policy version attested.
 * - policy_exceptions: the Exceptions page had no table behind it.
 * - control_policy: which controls implement which policy (Control Standards had nothing to show).
 */
return new class extends Migration
{
    private const POLICY_STATUS = ['under_review' => 'in_review', 'published' => 'active', 'expired' => 'active', 'archived' => 'retired'];

    private const POLICY_CATEGORY = ['security' => 'information_security', 'privacy' => 'data_protection', 'risk' => 'risk_management'];

    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->timestamp('retired_at')->nullable()->after('expiry_date');
            $table->text('status_note')->nullable()->after('retired_at');
        });

        Schema::table('policy_attestations', function (Blueprint $table) {
            $table->string('version_number', 16)->nullable()->after('policy_id');
        });

        Schema::create('policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('exception_code', 32);
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('control_id')->nullable()->constrained('controls')->nullOnDelete();
            $table->foreignId('risk_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->string('title');
            $table->text('justification');
            $table->text('compensating_controls')->nullable();
            $table->string('risk_rating', 16)->default('medium');
            $table->string('scope')->nullable();
            $table->string('status', 16)->default('requested');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('expires_on');
            $table->timestamps();
            $table->unique(['organization_id', 'exception_code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('control_policy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('control_id')->constrained('controls')->cascadeOnDelete();
            $table->string('clause')->nullable();
            $table->timestamps();
            $table->unique(['policy_id', 'control_id']);
        });

        foreach (self::POLICY_STATUS as $from => $to) {
            $query = DB::table('policies')->where('status', $from);
            if ($from === 'expired') {
                // Still in force, but its review is overdue: make sure the review date says so.
                DB::table('policies')->where('status', 'expired')
                    ->where(fn ($q) => $q->whereNull('review_date')->orWhere('review_date', '>=', now()->toDateString()))
                    ->update(['review_date' => now()->subDays(14)->toDateString()]);
            }
            $query->update(['status' => $to]);
        }
        foreach (self::POLICY_CATEGORY as $from => $to) {
            DB::table('policies')->where('category', $from)->update(['category' => $to]);
        }
        DB::table('policies')->where('status', 'retired')->whereNull('retired_at')->update(['retired_at' => DB::raw('updated_at')]);

        DB::table('policy_attestations')->where('status', 'acknowledged')->update(['status' => 'completed']);
        DB::table('policy_attestations')->where('status', 'declined')->update(['status' => 'pending']);
        // "Overdue" rows all carried future due dates; overdue is derived from the due date now.
        DB::table('policy_attestations')->where('status', 'overdue')
            ->update(['status' => 'pending', 'due_date' => now()->subDays(7)->toDateString()]);
        DB::table('policy_attestations')->where('status', 'completed')->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('control_policy');
        Schema::dropIfExists('policy_exceptions');
        Schema::table('policy_attestations', fn (Blueprint $table) => $table->dropColumn('version_number'));
        Schema::table('policies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['submitted_at', 'retired_at', 'status_note']);
        });
    }
};
