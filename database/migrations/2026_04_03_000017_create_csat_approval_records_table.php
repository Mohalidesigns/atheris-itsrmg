<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_approval_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->tinyInteger('stage_number')->unsigned();
            $table->enum('action', ['approved', 'rejected', 'returned_for_revision']);
            $table->foreignId('approver_id')->constrained('users');
            $table->string('approver_name');
            $table->string('approver_role', 100);
            $table->string('digital_signature_token', 500)->nullable();
            $table->text('comments')->nullable();
            $table->timestamp('actioned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_approval_records');
    }
};
