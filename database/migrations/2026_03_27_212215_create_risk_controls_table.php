<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->constrained()->cascadeOnDelete();
            $table->string('effectiveness')->nullable(); // effective, partially_effective, ineffective
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['risk_id', 'control_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_controls');
    }
};
