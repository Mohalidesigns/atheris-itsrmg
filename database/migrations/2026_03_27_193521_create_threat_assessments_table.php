<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threat_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('threat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('likelihood')->nullable();
            $table->unsignedTinyInteger('impact')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('analysis')->nullable();
            $table->text('recommendations')->nullable();
            $table->date('assessment_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threat_assessments');
    }
};
