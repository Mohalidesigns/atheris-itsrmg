<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csat_evidence_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('csat_assessments')->cascadeOnDelete();
            $table->string('attachable_type', 100);
            $table->unsignedBigInteger('attachable_id');
            $table->string('file_name', 500);
            $table->string('s3_key', 1000);
            $table->unsignedInteger('file_size_bytes');
            $table->string('mime_type', 100);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('uploaded_at');

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_evidence_attachments');
    }
};
