<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Management validation (ATH-VAL-007).
 *
 * - assets gain discovery provenance (source, external_id, last_seen_at), a custodian, a review
 *   stamp and the criticality they inherit from the business services they support (plan §2.4.1);
 * - asset_sync_jobs record who ran a sync, in which mode, and true created/updated/skipped counts
 *   with row errors, instead of invented totals;
 * - asset_business_service links a physical asset to the EA business services it supports. It is
 *   a GRC-side pivot on purpose: an asset is not an ArchiMate element, so it stays out of
 *   ea_relationships, which is exported to Archi;
 * - service-to-service dependencies the platform seeder wrote with the type "service" become
 *   canonical edges (BusinessService class, relation "serving", provider → consumer), so the
 *   ServiceDependency projection and the EA graph can see them.
 */
return new class extends Migration
{
    private const SERVICE_CLASS = 'App\\Models\\Ea\\BusinessService';

    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('source', 32)->default('manual')->after('status');
            $table->string('external_id')->nullable()->after('source');
            $table->foreignId('custodian_id')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable()->after('external_id');
            $table->string('criticality_inherited', 16)->nullable()->after('criticality');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'source', 'external_id']);
        });

        Schema::table('asset_sync_jobs', function (Blueprint $table) {
            $table->string('mode', 16)->default('sandbox')->after('status');
            $table->foreignId('triggered_by')->nullable()->after('mode')->constrained('users')->nullOnDelete();
            $table->string('file_name')->nullable()->after('triggered_by');
            $table->integer('records_created')->default(0)->after('records_imported');
            $table->integer('records_skipped')->default(0)->after('records_updated');
            $table->json('errors')->nullable()->after('output_summary');
        });

        Schema::create('asset_business_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('business_service_id')->constrained('ea_business_services')->cascadeOnDelete();
            $table->string('role', 32)->default('supports');
            $table->timestamps();
            $table->unique(['asset_id', 'business_service_id']);
            $table->index(['organization_id', 'business_service_id']);
        });

        DB::table('ea_relationships')
            ->where('source_type', 'service')->where('target_type', 'service')
            ->orderBy('id')
            ->get()
            ->each(function ($edge) {
                // Legacy rows read "source depends_on target": the target is the provider.
                DB::table('ea_relationships')->where('id', $edge->id)->update([
                    'source_type' => self::SERVICE_CLASS, 'source_id' => $edge->target_id,
                    'target_type' => self::SERVICE_CLASS, 'target_id' => $edge->source_id,
                    'relation_type' => 'serving',
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_business_service');

        Schema::table('asset_sync_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('triggered_by');
            $table->dropColumn(['mode', 'file_name', 'records_created', 'records_skipped', 'errors']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'source', 'external_id']);
            $table->dropConstrainedForeignId('custodian_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['source', 'external_id', 'last_seen_at', 'criticality_inherited', 'reviewed_at']);
        });
    }
};
