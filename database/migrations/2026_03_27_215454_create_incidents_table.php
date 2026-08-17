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
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('incident_id_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->nullable(); // malware, phishing, data_leak, unauthorized_access, dos, insider_threat, other
            $table->string('severity')->default('medium'); // critical, high, medium, low
            $table->string('status')->default('detected'); // detected, triaged, investigating, containing, eradicating, recovering, closed
            $table->string('source')->nullable();
            $table->dateTime('detected_at');
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('contained_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('affected_systems')->nullable();
            $table->unsignedInteger('affected_users_count')->nullable();
            $table->boolean('is_data_breach')->default(false);
            $table->text('root_cause')->nullable();
            $table->text('lessons_learned')->nullable();
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
        Schema::dropIfExists('incidents');
    }
};
