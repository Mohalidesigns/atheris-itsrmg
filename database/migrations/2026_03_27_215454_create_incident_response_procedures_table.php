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
        Schema::create('incident_response_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->json('incident_types')->nullable();
            $table->json('steps')->nullable();
            $table->json('severity_levels')->nullable();
            $table->json('escalation_contacts')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('version')->nullable();
            $table->date('last_reviewed')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_response_procedures');
    }
};
