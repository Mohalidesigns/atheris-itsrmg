<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Vendor Management validation (ATH-VAL-008).
 *
 * - vendors gain the inputs to tiering and review cadence (plan D8, "tiering"): a relationship
 *   owner, the NDPA processor flag and DPA date, the CBN material-outsourcing flag and notification
 *   date, a rating domain, the computed tier, and a link to the Shared Nigerian Vendor Directory;
 * - vendor_assessments record completion and the residual rating the score implies, and can name
 *   the breach event that triggered them;
 * - tprm_security_ratings and tprm_breach_events had no organization_id: every bank saw every
 *   rating and breach headline. They are backfilled from their vendor; rows whose vendor no
 *   longer exists are removed. Breach events gain a triage state;
 * - Vendor → application `serving` edges written by EaWedgeDemoSeeder without an organisation were
 *   visible to every bank as platform-shared rows; they take their vendor's organisation;
 * - the Risk Manager role (each bank's Vendor Risk Manager) and the Compliance Officer role (the
 *   DPCO) could not open the module. RolesAndPermissionsSeeder grants this on a fresh seed.
 */
return new class extends Migration
{
    private const GRANTS = [
        'Risk Manager' => ['view vendors', 'create vendors', 'edit vendors', 'view vendor-assessments', 'create vendor-assessments', 'edit vendor-assessments'],
        'Compliance Officer' => ['view vendors', 'view vendor-assessments'],
    ];

    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('tier')->nullable()->after('risk_level');
            $table->string('domain')->nullable()->after('website');
            $table->boolean('processes_personal_data')->default(false)->after('data_access_level');
            $table->date('dpa_signed_at')->nullable()->after('processes_personal_data');
            $table->boolean('material_outsourcing')->default(false)->after('dpa_signed_at');
            $table->date('regulator_notified_at')->nullable()->after('material_outsourcing');
            $table->foreignId('shared_directory_id')->nullable()->constrained('shared_vendor_directory')->nullOnDelete();
        });

        Schema::table('tprm_breach_events', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->cascadeOnDelete();
            $table->string('severity', 16)->default('medium')->after('event_type');
            $table->string('source', 32)->default('demo_feed')->after('url');
            $table->string('status', 16)->default('new')->after('discovered_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->index(['organization_id', 'status']);
        });

        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('assessment_date');
            $table->string('residual_rating', 16)->nullable()->after('overall_score');
            $table->foreignId('breach_event_id')->nullable()->constrained('tprm_breach_events')->nullOnDelete();
        });

        Schema::table('tprm_security_ratings', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'vendor_id', 'captured_at']);
        });

        $vendorOrg = DB::table('vendors')->pluck('organization_id', 'id');
        foreach (['tprm_security_ratings', 'tprm_breach_events'] as $table) {
            foreach (DB::table($table)->get(['id', 'vendor_id']) as $row) {
                isset($vendorOrg[$row->vendor_id])
                    ? DB::table($table)->where('id', $row->id)->update(['organization_id' => $vendorOrg[$row->vendor_id]])
                    : DB::table($table)->where('id', $row->id)->delete();
            }
        }

        DB::table('vendor_assessments')->where('status', 'completed')->whereNull('completed_at')->orderBy('id')
            ->get(['id', 'assessment_date', 'updated_at'])
            ->each(fn ($a) => DB::table('vendor_assessments')->where('id', $a->id)->update(['completed_at' => $a->assessment_date ?? $a->updated_at]));

        $this->repairVendorEdges();
        $this->grant();
    }

    /** Vendor-sourced EA edges with no organisation take their vendor's organisation. */
    public function repairVendorEdges(): int
    {
        $vendorOrg = DB::table('vendors')->pluck('organization_id', 'id');
        $fixed = 0;

        DB::table('ea_relationships')->whereNull('organization_id')->where('source_type', 'App\\Models\\Vendor')
            ->orderBy('id')->get(['id', 'source_id'])
            ->each(function ($edge) use ($vendorOrg, &$fixed) {
                if (isset($vendorOrg[$edge->source_id])) {
                    DB::table('ea_relationships')->where('id', $edge->id)->update(['organization_id' => $vendorOrg[$edge->source_id]]);
                    $fixed++;
                }
            });

        return $fixed;
    }

    private function grant(): void
    {
        foreach (self::GRANTS as $role => $permissions) {
            $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($permissions as $name) {
                $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
                if ($permissionId && ! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('tprm_security_ratings', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'vendor_id', 'captured_at']);
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('breach_event_id');
            $table->dropColumn(['completed_at', 'residual_rating']);
        });
        Schema::table('tprm_breach_events', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['severity', 'source', 'status', 'reviewed_at', 'review_notes']);
        });
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('shared_directory_id');
            $table->dropColumn(['tier', 'domain', 'processes_personal_data', 'dpa_signed_at', 'material_outsourcing', 'regulator_notified_at']);
        });
        // Role grants are left in place (see the 2027_01_12 grant migration).
    }
};
