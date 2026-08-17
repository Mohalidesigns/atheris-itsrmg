<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('siem_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->enum('provider', ['sentinel', 'splunk_es', 'qradar', 'wazuh'])->default('sentinel');
            $table->json('config')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_signal_at')->nullable();
            $table->timestamps();
        });

        Schema::create('siem_signals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('provider', 32);
            $table->string('external_id', 128);
            $table->enum('severity', ['critical', 'high', 'medium', 'low', 'info'])->default('medium');
            $table->string('title');
            $table->json('payload')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('regulator_code', 32);
            $table->string('template_key', 64)->unique();
            $table->string('title');
            $table->longText('body_template');
            $table->json('schema')->nullable();
            $table->string('version')->default('1.0');
            $table->timestamps();
        });

        Schema::create('incident_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incident_id')->index();
            $table->string('template_key', 64);
            $table->longText('draft_body')->nullable();
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->enum('delivery_status', ['draft', 'in_review', 'approved', 'signed', 'delivered', 'acknowledged'])->default('draft');
            $table->json('delivery_receipt')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fair_scenarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('risk_id')->nullable();
            $table->string('name');
            $table->text('loss_event_description')->nullable();
            $table->json('frequency_distribution');
            $table->json('magnitude_distribution');
            $table->json('control_effectiveness')->nullable();
            $table->unsignedInteger('iterations')->default(10000);
            $table->timestamps();
        });

        Schema::create('fair_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained('fair_scenarios')->cascadeOnDelete();
            $table->decimal('ale_mean_ngn', 18, 2);
            $table->decimal('ale_median_ngn', 18, 2);
            $table->decimal('ale_p95_ngn', 18, 2);
            $table->decimal('ale_p99_ngn', 18, 2);
            $table->json('histogram');
            $table->timestamp('ran_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('ndpc_fine_bands', function (Blueprint $table) {
            $table->id();
            $table->string('band_name');
            $table->decimal('fine_min_ngn', 18, 2);
            $table->decimal('fine_max_ngn', 18, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('epss_cache', function (Blueprint $table) {
            $table->string('cve_id', 32)->primary();
            $table->decimal('epss_score', 6, 4);
            $table->decimal('percentile', 6, 4);
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('kev_cache', function (Blueprint $table) {
            $table->string('cve_id', 32)->primary();
            $table->date('date_added');
            $table->boolean('known_ransomware_use')->default(false);
            $table->string('vendor_project')->nullable();
            $table->string('product')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('threat_advisories', function (Blueprint $table) {
            $table->id();
            $table->enum('source', ['ngcert', 'nitda', 'us-cert', 'au-cert', 'custom_rss'])->default('ngcert');
            $table->string('advisory_id', 64);
            $table->string('title');
            $table->longText('body')->nullable();
            $table->timestamp('published_at');
            $table->json('cves')->nullable();
            $table->json('iocs')->nullable();
            $table->timestamps();
        });

        Schema::create('vulnerability_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->enum('severity', ['critical', 'high', 'medium', 'low']);
            $table->unsignedInteger('hours_to_remediate');
            $table->json('escalation_rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vulnerability_sla_policies');
        Schema::dropIfExists('threat_advisories');
        Schema::dropIfExists('kev_cache');
        Schema::dropIfExists('epss_cache');
        Schema::dropIfExists('ndpc_fine_bands');
        Schema::dropIfExists('fair_runs');
        Schema::dropIfExists('fair_scenarios');
        Schema::dropIfExists('incident_notifications');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('siem_signals');
        Schema::dropIfExists('siem_integrations');
    }
};
