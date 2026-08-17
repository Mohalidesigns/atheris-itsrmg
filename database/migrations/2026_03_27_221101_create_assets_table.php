<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assets')) {
            return;
        }

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('asset_id_code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('asset_type'); // hardware, software, cloud_service, database, network, facility
            $table->string('category')->nullable();
            $table->string('criticality')->default('medium'); // critical, high, medium, low
            $table->string('status')->default('active'); // active, inactive, decommissioned, under_review
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department')->nullable();
            $table->string('location')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('hostname')->nullable();
            $table->string('vendor')->nullable();
            $table->string('version')->nullable();
            $table->string('license_type')->nullable();
            $table->string('data_classification')->nullable(); // public, internal, confidential, restricted
            $table->date('purchase_date')->nullable();
            $table->date('end_of_life')->nullable();
            $table->json('tags')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'asset_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
