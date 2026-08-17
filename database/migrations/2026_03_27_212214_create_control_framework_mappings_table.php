<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_framework_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('framework_requirements')->cascadeOnDelete();
            $table->string('coverage')->default('full'); // full, partial, planned, none
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['control_id', 'requirement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_framework_mappings');
    }
};
