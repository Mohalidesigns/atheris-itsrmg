<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_threat_catalogue', function (Blueprint $table) {
            $table->id();
            $table->string('threat_name', 500);
            $table->enum('threat_source', ['internal', 'external', 'natural']);
            $table->enum('threat_category', ['technical', 'human', 'environmental']);
            $table->enum('typical_likelihood', ['high', 'moderate', 'low']);
            $table->enum('typical_impact', ['high', 'moderate', 'low']);
            $table->text('description');
            $table->text('cbn_relevance_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_threat_catalogue');
    }
};
