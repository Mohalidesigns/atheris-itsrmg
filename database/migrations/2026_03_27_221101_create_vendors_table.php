<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('vendor_code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('risk_level')->default('medium'); // critical, high, medium, low
            $table->string('status')->default('active'); // active, inactive, under_review, terminated
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('website')->nullable();
            $table->string('country')->nullable()->default('NG');
            $table->text('services_provided')->nullable();
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('contract_currency')->default('NGN');
            $table->string('data_access_level')->nullable(); // none, limited, full
            $table->text('sla_details')->nullable();
            $table->date('last_assessed')->nullable();
            $table->date('next_review_date')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
