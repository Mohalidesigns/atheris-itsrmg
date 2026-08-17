<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcp_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('bcp_plans')->cascadeOnDelete();
            $table->string('title');
            $table->string('test_type'); // tabletop, walkthrough, simulation, full
            $table->string('status')->default('planned'); // planned, in_progress, completed, cancelled
            $table->date('scheduled_date');
            $table->date('completed_date')->nullable();
            $table->foreignId('conducted_by')->nullable()->constrained('users');
            $table->text('results')->nullable();
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->string('pass_fail')->nullable(); // pass, partial, fail
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcp_tests');
    }
};
