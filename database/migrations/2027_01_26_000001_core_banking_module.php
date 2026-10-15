<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core Banking (ATH-VAL-017).
 *
 * - An integration is a named, read-only adapter onto one of the bank's core or switch platforms,
 *   linked to the registered asset it reads, with an owner, an environment and a mode (sandbox
 *   until credentials are configured).
 * - A snapshot belongs to its integration and records how it was captured, the findings assessed
 *   from it, what changed since the previous capture, and which keys the customer-data embargo
 *   withheld.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_banking_integrations', function (Blueprint $table) {
            $table->string('name')->nullable()->after('product');
            $table->string('environment', 16)->default('production')->after('name');
            $table->string('mode', 16)->default('sandbox')->after('environment');
            $table->foreignId('asset_id')->nullable()->after('mode')->constrained('assets')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('asset_id')->constrained('users')->nullOnDelete();
            $table->string('last_status', 16)->nullable()->after('last_sync_at');
            $table->string('last_error')->nullable()->after('last_status');
            $table->unsignedInteger('findings_count')->default(0)->after('last_error');
            $table->unsignedBigInteger('issue_id')->nullable()->after('findings_count');
            $table->foreignId('created_by')->nullable()->after('issue_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('core_banking_snapshots', function (Blueprint $table) {
            $table->foreignId('integration_id')->nullable()->after('organization_id')->constrained('core_banking_integrations')->cascadeOnDelete();
            $table->string('mode', 16)->default('sandbox')->after('product');
            $table->string('status', 16)->default('ok')->after('mode');
            $table->json('findings')->nullable()->after('artefacts');
            $table->json('changes')->nullable()->after('findings');
            $table->json('embargoed_keys')->nullable()->after('changes');
            $table->foreignId('triggered_by')->nullable()->after('embargoed_keys')->constrained('users')->nullOnDelete();
        });

        // Existing integrations get a readable name; snapshots attach to their bank's integration.
        foreach (DB::table('core_banking_integrations')->get(['id', 'organization_id', 'product', 'name']) as $i) {
            if (! $i->name) {
                DB::table('core_banking_integrations')->where('id', $i->id)->update(['name' => strtoupper($i->product).' adapter']);
            }
            DB::table('core_banking_snapshots')->where('organization_id', $i->organization_id)->where('product', $i->product)
                ->whereNull('integration_id')->update(['integration_id' => $i->id]);
        }
        DB::table('core_banking_snapshots')->whereNull('integration_id')->delete();
    }

    public function down(): void
    {
        Schema::table('core_banking_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('integration_id');
            $table->dropConstrainedForeignId('triggered_by');
            $table->dropColumn(['mode', 'status', 'findings', 'changes', 'embargoed_keys']);
        });
        Schema::table('core_banking_integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['name', 'environment', 'mode', 'last_status', 'last_error', 'findings_count', 'issue_id']);
        });
    }
};
