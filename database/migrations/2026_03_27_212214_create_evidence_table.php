<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('evidenceable'); // control, compliance_result, etc.
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type'); // document, screenshot, url, attestation, log
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('url')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, expired
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'evidenceable_type', 'evidenceable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
