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
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('alert_id_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source')->nullable();
            $table->string('severity')->default('medium');
            $table->string('status')->default('new'); // new, acknowledged, investigating, resolved, dismissed
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->json('raw_data')->nullable();
            $table->dateTime('received_at');
            $table->dateTime('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
