<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_institution_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->unique()->constrained('csat_assessments')->cascadeOnDelete();
            $table->string('institution_name');
            $table->enum('cbn_licence_type', ['dmb', 'mfb', 'mortgage_bank', 'psb', 'merchant_bank', 'development_finance']);
            $table->text('head_office_address');
            $table->string('ciso_name');
            $table->string('ciso_email');
            $table->string('ciso_phone', 20);
            $table->string('ciso_grade', 100)->nullable();
            $table->string('ciso_reporting_line')->nullable();
            $table->string('parent_bank_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_institution_profiles');
    }
};
