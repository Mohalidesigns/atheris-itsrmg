<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISMS validation (ATH-VAL-010).
 *
 * - The Statement of Applicability was read-only: nothing recorded who changed an entry or when.
 * - soa_versions: ISO/IEC 27001 cl. 6.1.3(d) makes the SoA documented information that management
 *   approves. A version is a frozen snapshot of every Annex A entry, prepared by one person and
 *   approved (or returned) by another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statements_of_applicability', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('control_id')->constrained('users')->nullOnDelete();
            $table->unique(['organization_id', 'requirement_id']);
        });

        Schema::create('soa_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status', 16)->default('submitted');
            $table->json('snapshot');
            $table->json('summary');
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soa_versions');
        Schema::table('statements_of_applicability', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'requirement_id']);
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
