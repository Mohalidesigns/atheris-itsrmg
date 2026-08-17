<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_stakeholder_engagement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->string('role_key', 100);
            $table->string('role_label');
            $table->enum('engagement_status', ['yes', 'no', 'na', 'yes_with_comment']);
            $table->text('comment')->nullable();
            $table->string('name_of_person')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'role_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_stakeholder_engagement');
    }
};
