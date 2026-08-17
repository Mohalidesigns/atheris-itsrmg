<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_themes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->json('tokens');
            $table->string('logo_path')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('subject_type', 64);
            $table->string('key', 64);
            $table->string('label');
            $table->enum('data_type', ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'date'])->default('text');
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedInteger('order_index')->default(0);
            $table->timestamps();
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subject_id')->index();
            $table->foreignId('field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('content_marketplace_items', function (Blueprint $table) {
            $table->id();
            $table->string('publisher');
            $table->enum('type', ['regulator_pack', 'kri_pack', 'workflow_template', 'ccm_pack', 'control_pack']);
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('version')->default('1.0');
            $table->string('status', 32)->default('published');
            $table->json('manifest_json')->nullable();
            $table->string('content_bundle_path')->nullable();
            $table->decimal('price_ngn', 14, 2)->default(0);
            $table->unsignedInteger('installs_count')->default(0);
            $table->decimal('rating', 3, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('content_installs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('marketplace_item_id')->constrained('content_marketplace_items')->cascadeOnDelete();
            $table->string('version');
            $table->timestamp('installed_at')->useCurrent();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::create('sso_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->enum('type', ['saml', 'oidc'])->default('saml');
            $table->string('idp_name');
            $table->longText('idp_metadata')->nullable();
            $table->string('sp_entity_id')->nullable();
            $table->text('certificate')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('scim_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('token_hash', 128);
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->enum('source', ['ad', 'entra', 'aws', 'tenable', 'qualys', 'csv', 'manual'])->default('manual');
            $table->string('status', 32)->default('completed');
            $table->unsignedInteger('records_imported')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->text('output_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_sync_jobs');
        Schema::dropIfExists('scim_tokens');
        Schema::dropIfExists('sso_connections');
        Schema::dropIfExists('content_installs');
        Schema::dropIfExists('content_marketplace_items');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('tenant_themes');
    }
};
