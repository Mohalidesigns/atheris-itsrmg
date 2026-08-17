<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // EA-IA
        Schema::create('ea_interfaces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('source_app_id')->nullable()->constrained('ea_applications_ext')->nullOnDelete();
            $table->foreignId('target_app_id')->nullable()->constrained('ea_applications_ext')->nullOnDelete();
            $table->string('protocol', 32)->nullable(); // REST / SOAP / SFTP / AMQP / Kafka / JDBC
            $table->string('pattern', 32)->nullable(); // sync/async/batch/file/event
            $table->string('classification', 32)->default('Internal');
            $table->boolean('pii_carrying')->default(false);
            $table->string('status', 32)->default('active'); // proposed/active/deprecated/retired
            $table->timestamps();
        });

        Schema::create('ea_apis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('interface_id')->nullable()->constrained('ea_interfaces')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->string('version')->default('v1');
            $table->string('status', 32)->default('active');
            $table->string('provider_role')->nullable(); // provider app
            $table->string('consumer_role')->nullable();
            $table->string('auth_method', 32)->default('oauth2');
            $table->timestamps();
        });

        // EA-SEC security zones
        Schema::create('ea_zones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->unsignedTinyInteger('trust_level')->default(3); // 1 lowest .. 5 highest
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ea_zone_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('zone_id')->constrained('ea_zones')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('ea_applications_ext')->cascadeOnDelete();
            $table->string('assignment_type', 32)->default('assigned'); // assigned/proposed/approved
            $table->timestamps();
        });

        Schema::create('ea_control_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('component_type', 64); // application / tech / interface
            $table->unsignedBigInteger('component_id');
            $table->unsignedBigInteger('control_id')->nullable(); // FK to controls
            $table->string('framework', 32)->nullable(); // ISO / PCI / CBN / NDPA
            $table->string('coverage', 32)->default('partial'); // full/partial/planned/gap
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['component_type', 'component_id']);
        });

        // EA-PROC process inventory
        Schema::create('ea_processes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedTinyInteger('level')->default(1); // L1/L2/L3
            $table->foreignId('capability_id')->nullable()->constrained('ea_capabilities')->nullOnDelete();
            $table->unsignedInteger('rto_hours')->nullable();
            $table->unsignedInteger('rpo_hours')->nullable();
            $table->string('criticality', 32)->default('medium');
            $table->json('linked_applications')->nullable();
            $table->timestamps();
        });

        // EA-GOV ARB
        Schema::create('ea_arb_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('status', 32)->default('submitted'); // submitted/in_review/approved/rejected/deferred
            $table->unsignedBigInteger('submitter_id')->nullable();
            $table->json('impact_blast_radius')->nullable();
            $table->json('impacted_principles')->nullable();
            $table->json('impacted_standards')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_by')->nullable();
            $table->string('digital_signature', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('ea_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('subject'); // standard / principle / policy reference
            $table->text('justification')->nullable();
            $table->text('compensating_controls')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status', 32)->default('active'); // active/expired/revoked/renewed
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
        });

        // Vendor concentration summary (denormalised — optional)
        Schema::create('ea_vendor_concentration', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedInteger('application_count')->default(0);
            $table->unsignedInteger('critical_application_count')->default(0);
            $table->decimal('annual_spend_ngn', 16, 2)->default(0);
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ea_vendor_concentration');
        Schema::dropIfExists('ea_exceptions');
        Schema::dropIfExists('ea_arb_submissions');
        Schema::dropIfExists('ea_processes');
        Schema::dropIfExists('ea_control_mappings');
        Schema::dropIfExists('ea_zone_assignments');
        Schema::dropIfExists('ea_zones');
        Schema::dropIfExists('ea_apis');
        Schema::dropIfExists('ea_interfaces');
    }
};
