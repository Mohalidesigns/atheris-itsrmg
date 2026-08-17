<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bia_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('process_name');
            $table->string('department')->nullable();
            $table->text('description')->nullable();
            $table->string('criticality')->default('medium'); // critical, high, medium, low
            $table->integer('rto_hours')->nullable();
            $table->integer('rpo_hours')->nullable();
            $table->integer('mtpd_hours')->nullable()->comment('Maximum Tolerable Period of Disruption');
            $table->decimal('financial_impact_per_hour', 15, 2)->nullable()->comment('In org currency');
            $table->text('dependencies')->nullable();
            $table->text('recovery_strategy')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['organization_id', 'criticality']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bia_records');
    }
};
