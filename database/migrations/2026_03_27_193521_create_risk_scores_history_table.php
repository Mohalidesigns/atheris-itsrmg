<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_scores_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('inherent_likelihood')->nullable();
            $table->unsignedTinyInteger('inherent_impact')->nullable();
            $table->unsignedTinyInteger('inherent_score')->nullable();
            $table->string('inherent_rating')->nullable();

            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_impact')->nullable();
            $table->unsignedTinyInteger('residual_score')->nullable();
            $table->string('residual_rating')->nullable();

            $table->string('change_reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['risk_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_scores_history');
    }
};
