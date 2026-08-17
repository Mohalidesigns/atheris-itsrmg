<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_ma_compensating_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->unique()->constrained('csat_ma_responses')->cascadeOnDelete();
            $table->string('control_name', 500);
            $table->text('control_description');
            $table->enum('effectiveness_level', ['high', 'medium', 'low']);
            $table->date('planned_permanent_date')->nullable();
            $table->unsignedBigInteger('issues_finding_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_ma_compensating_controls');
    }
};
