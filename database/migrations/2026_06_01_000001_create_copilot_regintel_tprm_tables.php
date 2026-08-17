<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('obligations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('regulator_code', 32);
            $table->string('reference_code', 64);
            $table->string('title');
            $table->longText('body_markdown')->nullable();
            $table->date('effective_date')->nullable();
            $table->unsignedInteger('review_cycle_days')->default(365);
            $table->json('applicability')->nullable();
            $table->string('owner_role', 64)->nullable();
            $table->text('evidence_requirement')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::create('regulatory_circulars', function (Blueprint $table) {
            $table->id();
            $table->string('regulator_code', 32);
            $table->string('circular_number', 64)->nullable();
            $table->string('title');
            $table->date('issued_at')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->string('source_url')->nullable();
            $table->string('pdf_path')->nullable();
            $table->longText('plain_text')->nullable();
            $table->longText('llm_summary')->nullable();
            $table->json('impact_assessment')->nullable();
            $table->enum('status', ['draft', 'summarising', 'impact_pending', 'editorial_review', 'published', 'superseded'])->default('draft');
            $table->timestamps();
        });

        Schema::create('circular_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circular_id')->constrained('regulatory_circulars')->cascadeOnDelete();
            $table->string('impacted_type');
            $table->unsignedBigInteger('impacted_id');
            $table->decimal('impact_score', 4, 2)->default(0);
            $table->text('rationale')->nullable();
            $table->timestamps();
        });

        Schema::create('tprm_security_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->enum('provider', ['securityscorecard', 'bitsight', 'upguard'])->default('securityscorecard');
            $table->unsignedInteger('rating_value');
            $table->string('grade', 2);
            $table->timestamp('captured_at');
            $table->integer('delta_from_previous')->default(0);
            $table->timestamps();
        });

        Schema::create('tprm_breach_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('event_type', 64);
            $table->string('headline');
            $table->string('url')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamps();
        });

        Schema::create('shared_vendor_directory', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('legal_name');
            $table->string('category', 64);
            $table->string('country', 2)->default('NG');
            $table->json('contacts')->nullable();
            $table->string('baseline_ddq_version')->default('v1.0');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('copilot_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title');
            $table->enum('mode', ['risk', 'control', 'policy', 'incident', 'general'])->default('general');
            $table->timestamps();
        });

        Schema::create('copilot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('copilot_conversations')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'tool', 'system'])->default('user');
            $table->longText('content');
            $table->string('tool_name')->nullable();
            $table->json('tool_args')->nullable();
            $table->json('tool_result')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('model')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_messages');
        Schema::dropIfExists('copilot_conversations');
        Schema::dropIfExists('shared_vendor_directory');
        Schema::dropIfExists('tprm_breach_events');
        Schema::dropIfExists('tprm_security_ratings');
        Schema::dropIfExists('circular_impacts');
        Schema::dropIfExists('regulatory_circulars');
        Schema::dropIfExists('obligations');
    }
};
