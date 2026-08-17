<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('stage_number')->unsigned();
            $table->string('stage_name');
            $table->string('role_required', 100);
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'stage_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_approval_stages');
    }
};
