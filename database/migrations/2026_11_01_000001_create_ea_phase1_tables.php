<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // EA-REP / EA-BA capabilities
        Schema::create('ea_capabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedTinyInteger('level')->default(1); // up to 5
            $table->string('owner_role', 64)->nullable();
            $table->string('criticality', 32)->default('medium'); // critical/high/medium/low
            $table->unsignedTinyInteger('maturity')->default(1); // 1-5
            $table->string('source', 32)->default('custom'); // bian / custom
            $table->date('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        // EA-BA value streams
        Schema::create('ea_value_streams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('stages');
            $table->json('participants')->nullable();
            $table->json('linked_capabilities')->nullable();
            $table->timestamps();
        });

        // EA-APM application extension attrs
        Schema::create('ea_applications_ext', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('time_score', 16)->nullable(); // Tolerate / Invest / Migrate / Eliminate
            $table->unsignedTinyInteger('business_fit')->nullable(); // 1-5
            $table->unsignedTinyInteger('technical_fit')->nullable(); // 1-5
            $table->string('criticality', 32)->default('medium');
            $table->string('lifecycle', 32)->default('live'); // plan/build/live/sunset/retired
            $table->decimal('annual_cost_ngn', 16, 2)->nullable();
            $table->unsignedInteger('user_count')->nullable();
            $table->string('owner_role', 64)->nullable();
            $table->json('capability_ids')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'lifecycle']);
        });

        // EA-TA technology components
        Schema::create('ea_tech_components_ext', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 64)->nullable(); // Language / Framework / DB / Platform / ...
            $table->string('vendor')->nullable();
            $table->string('version')->nullable();
            $table->string('radar_status', 16)->default('assess'); // adopt/trial/assess/hold
            $table->date('eol_date')->nullable();
            $table->date('eos_date')->nullable();
            $table->boolean('obsolescence_flag')->default(false);
            $table->json('application_ids')->nullable();
            $table->timestamps();
        });

        // EA-TA standards catalogue
        Schema::create('ea_standards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('radar_status', 16)->default('adopt');
            $table->string('status', 32)->default('active'); // draft/active/retired
            $table->timestamps();
        });

        // EA-DA info domains
        Schema::create('ea_info_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('owner_role', 64)->nullable();
            $table->string('classification', 32)->default('Internal');
            $table->timestamps();
        });

        // EA-DA logical entities
        Schema::create('ea_logical_entities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('domain_id')->nullable()->constrained('ea_info_domains')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('classification', 32)->default('Internal'); // NDPA: Public/Internal/Confidential/Personal/Sensitive
            $table->boolean('pii_flag')->default(false);
            $table->json('attributes')->nullable();
            $table->timestamps();
        });

        // EA-DA data flows
        Schema::create('ea_data_flows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('source_entity_id')->nullable()->constrained('ea_logical_entities')->nullOnDelete();
            $table->foreignId('target_entity_id')->nullable()->constrained('ea_logical_entities')->nullOnDelete();
            $table->string('name');
            $table->boolean('cross_border')->default(false);
            $table->string('protocol')->nullable();
            $table->string('classification', 32)->default('Internal');
            $table->timestamps();
        });

        // EA relationships generic graph
        Schema::create('ea_relationships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');
            $table->string('relation_type', 64); // realises / serves / dependsOn / ... ArchiMate 3.2
            $table->json('attrs')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index(['target_type', 'target_id']);
        });

        // EA audit log
        Schema::create('ea_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 32);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // EA-MAT CBN EA maturity
        Schema::create('ea_maturity_domains', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('weight')->default(10);
            $table->timestamps();
        });

        Schema::create('ea_maturity_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('ea_maturity_domains')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('question');
            $table->text('guidance')->nullable();
            $table->unsignedTinyInteger('max_level')->default(5);
            $table->timestamps();
        });

        Schema::create('ea_maturity_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title');
            $table->smallInteger('year')->unsigned();
            $table->decimal('overall_score', 4, 2)->nullable();
            $table->string('status', 32)->default('in_progress'); // draft / in_progress / completed
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('ea_maturity_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('ea_maturity_assessments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('ea_maturity_questions')->cascadeOnDelete();
            $table->unsignedTinyInteger('selected_level');
            $table->text('comment')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->unsignedBigInteger('answered_by')->nullable();
            $table->timestamps();
        });

        // EA-GOV principles (seeded in Phase 2 but table created upfront for convenience)
        Schema::create('ea_principles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('statement')->nullable();
            $table->text('rationale')->nullable();
            $table->text('implications')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ea_principles');
        Schema::dropIfExists('ea_maturity_responses');
        Schema::dropIfExists('ea_maturity_assessments');
        Schema::dropIfExists('ea_maturity_questions');
        Schema::dropIfExists('ea_maturity_domains');
        Schema::dropIfExists('ea_audit_log');
        Schema::dropIfExists('ea_relationships');
        Schema::dropIfExists('ea_data_flows');
        Schema::dropIfExists('ea_logical_entities');
        Schema::dropIfExists('ea_info_domains');
        Schema::dropIfExists('ea_standards');
        Schema::dropIfExists('ea_tech_components_ext');
        Schema::dropIfExists('ea_applications_ext');
        Schema::dropIfExists('ea_value_streams');
        Schema::dropIfExists('ea_capabilities');
    }
};
