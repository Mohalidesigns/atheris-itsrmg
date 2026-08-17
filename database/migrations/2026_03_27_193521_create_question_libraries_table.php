<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('question_text');
            $table->string('category')->nullable();
            $table->string('response_type')->default('scale'); // scale, text, yes_no, multiple_choice
            $table->json('response_options')->nullable();
            $table->unsignedTinyInteger('weight')->default(1);
            $table->string('module')->default('risk'); // risk, compliance, vendor, isms
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_libraries');
    }
};
