<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('vuln_id_code');
            $table->string('cve_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity'); // critical, high, medium, low, info
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->string('cvss_vector')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->default('open'); // open, in_progress, remediated, accepted, false_positive
            $table->json('affected_assets')->nullable();
            $table->dateTime('discovered_at')->nullable();
            $table->dateTime('remediated_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->json('tags')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vulnerabilities');
    }
};
