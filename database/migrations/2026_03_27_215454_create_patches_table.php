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
        Schema::create('patches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('patch_id_code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('vendor')->nullable();
            $table->string('version')->nullable();
            $table->date('release_date')->nullable();
            $table->string('severity')->nullable();
            $table->string('status')->default('pending'); // pending, testing, approved, deployed, failed
            $table->json('affected_systems')->nullable();
            $table->dateTime('deployed_at')->nullable();
            $table->foreignId('deployed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patches');
    }
};
