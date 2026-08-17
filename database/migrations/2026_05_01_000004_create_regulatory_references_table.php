<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('regulatory_references', function (Blueprint $table) {
            $table->id();
            $table->string('regulator_code', 32);
            $table->string('reference_code', 64);
            $table->string('title');
            $table->string('version')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('clause_path')->nullable();
            $table->longText('body_markdown')->nullable();
            $table->string('source_url')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamps();

            $table->index(['regulator_code', 'reference_code']);
        });

        Schema::create('regulatory_sources', function (Blueprint $table) {
            $table->id();
            $table->string('regulator_code', 32);
            $table->string('name');
            $table->string('homepage_url')->nullable();
            $table->string('rss_or_feed_url')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('licensing_status')->default('unknown');
            $table->timestamp('last_ingested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_sources');
        Schema::dropIfExists('regulatory_references');
    }
};
