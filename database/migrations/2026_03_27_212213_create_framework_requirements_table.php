<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('control_frameworks')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('framework_requirements')->nullOnDelete();
            $table->string('requirement_code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('section')->nullable();
            $table->string('level')->nullable(); // domain, objective, requirement, sub-requirement
            $table->boolean('is_mandatory')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['framework_id', 'requirement_code']);
            $table->index(['framework_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_requirements');
    }
};
