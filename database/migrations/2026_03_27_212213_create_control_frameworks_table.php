<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('short_name');
            $table->text('description')->nullable();
            $table->string('version')->nullable();
            $table->string('issuing_body')->nullable();
            $table->string('category'); // regulatory, standard, framework, guideline
            $table->string('jurisdiction')->nullable(); // NG, global, etc.
            $table->boolean('is_system')->default(false); // system-provided vs org-custom
            $table->boolean('is_active')->default(true);
            $table->string('logo_path')->nullable();
            $table->string('url')->nullable();
            $table->date('effective_date')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_frameworks');
    }
};
