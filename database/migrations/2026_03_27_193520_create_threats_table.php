<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('threat_id_code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable(); // natural, human, environmental, technical
            $table->string('source')->nullable(); // internal, external, partner
            $table->string('type')->nullable(); // deliberate, accidental, environmental
            $table->unsignedTinyInteger('likelihood')->nullable();
            $table->unsignedTinyInteger('capability')->nullable();
            $table->unsignedTinyInteger('intent')->nullable();
            $table->string('severity')->nullable(); // critical, high, medium, low
            $table->boolean('is_active')->default(true);
            $table->text('countermeasures')->nullable();
            $table->json('tags')->nullable();
            $table->date('last_seen')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'threat_id_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threats');
    }
};
