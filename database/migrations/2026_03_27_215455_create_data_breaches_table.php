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
        Schema::create('data_breaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->string('breach_id_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('breach_type')->nullable(); // confidentiality, integrity, availability
            $table->json('data_types_affected')->nullable();
            $table->unsignedInteger('records_affected')->nullable();
            $table->string('status')->default('identified'); // identified, investigating, contained, notified, resolved, closed
            $table->boolean('ndpa_notification_required')->default(false);
            $table->dateTime('ndpa_notified_at')->nullable();
            $table->dateTime('ndpa_notification_deadline')->nullable();
            $table->boolean('regulatory_body_notified')->default(false);
            $table->boolean('individuals_notified')->default(false);
            $table->dateTime('individuals_notified_at')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('remedial_actions')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_breaches');
    }
};
