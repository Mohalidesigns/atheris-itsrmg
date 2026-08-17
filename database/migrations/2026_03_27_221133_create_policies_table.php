<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('policy_code');
            $table->string('title');
            $table->longText('content')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('status')->default('draft');
            $table->string('version_number')->default('1.0');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('review_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->json('tags')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->unique(['organization_id', 'policy_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
