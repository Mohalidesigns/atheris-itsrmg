<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ATH-EAR-002 WS 2.1 — the canonical-model migration. Resolves open decision
 * D1 (§12.3) in favour of §7.2's recommendation: **EA owns the canonical
 * business-architecture objects.**
 *
 * §2.5 (RC-5) states the problem: the platform ships two business-architecture
 * families — `Ea\Capability` / `Ea\Process` / `Ea\ValueStream` and core
 * `BusinessCapability` / `BusinessProcess` / `BusinessService`. "Two capability
 * trees in one product, shown to a bank, is the kind of finding a Big-4
 * assessor writes up."
 *
 * What this migration does:
 *   1. Creates `ea_business_services` — §7.2 explicitly allows a new table
 *      rather than promoting ValueStream, which is a different ArchiMate
 *      concept and should not be overloaded.
 *   2. Adds the columns the legacy surface needs onto `ea_processes`.
 *   3. Copies all four legacy tables into the EA graph, recording `legacy_id`
 *      on every migrated row so the mapping stays traceable.
 *
 * Risk R4 — "The canonical-model migration breaks existing modules" — is
 * mitigated exactly as §12.1 prescribes: the legacy tables are **not dropped**,
 * every migrated row keeps a back-reference, and `config('ea.canonical_business_architecture')`
 * decides which tables the legacy models read. Flipping the flag off restores
 * the previous behaviour without a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ea_business_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('capability_id')->nullable()->index();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('criticality', 32)->default('medium');
            // Minutes, matching the legacy core columns. EA processes hold
            // hours; a business service is the finer-grained object and BCP
            // states service RTOs in minutes.
            $table->unsignedInteger('rto_minutes')->nullable();
            $table->unsignedInteger('rpo_minutes')->nullable();
            $table->string('status', 32)->default('active');
            // Traceability back to the retired core row.
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'ea_business_services_code_unique');
        });

        // Columns the legacy BusinessProcess surface needs on the EA process.
        Schema::table('ea_processes', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->after('capability_id')->index();
            $table->unsignedBigInteger('owner_id')->nullable()->after('service_id')->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->after('owner_id')->index();
        });

        Schema::table('ea_capabilities', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('plateau_id')->index();
        });

        $this->migrateCapabilities();
        $this->migrateServices();
        $this->migrateProcesses();
        $this->migrateDependencies();
    }

    /**
     * Core capabilities into `ea_capabilities`.
     *
     * Matches on name first: a bank that populated both trees will have the
     * same capability in each, and creating a duplicate would reproduce the
     * very finding this migration exists to close.
     */
    private function migrateCapabilities(): void
    {
        if (! Schema::hasTable('business_capabilities')) {
            return;
        }

        $sequence = 1;

        foreach (DB::table('business_capabilities')->orderBy('id')->get() as $legacy) {
            $existing = DB::table('ea_capabilities')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($legacy->name)])
                ->where(fn ($q) => $q->where('organization_id', $legacy->organization_id)
                    ->orWhereNull('organization_id'))
                ->first();

            if ($existing) {
                DB::table('ea_capabilities')->where('id', $existing->id)
                    ->whereNull('legacy_id')
                    ->update(['legacy_id' => $legacy->id]);

                continue;
            }

            do {
                $code = 'BC-'.str_pad((string) $sequence++, 3, '0', STR_PAD_LEFT);
            } while (DB::table('ea_capabilities')->where('code', $code)->exists());

            DB::table('ea_capabilities')->insert([
                'organization_id' => $legacy->organization_id,
                'code' => $code,
                'name' => $legacy->name,
                'description' => $legacy->description,
                'level' => $legacy->parent_id ? 2 : 1,
                'criticality' => 'medium',
                'maturity' => 1,
                'source' => 'custom',
                'version_no' => 1,
                'legacy_id' => $legacy->id,
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }

        // Re-point parent_id onto the new EA ids now every row exists.
        foreach (DB::table('business_capabilities')->whereNotNull('parent_id')->get() as $legacy) {
            $child = DB::table('ea_capabilities')->where('legacy_id', $legacy->id)->first();
            $parent = DB::table('ea_capabilities')->where('legacy_id', $legacy->parent_id)->first();

            if ($child && $parent) {
                DB::table('ea_capabilities')->where('id', $child->id)->update(['parent_id' => $parent->id]);
            }
        }
    }

    private function migrateServices(): void
    {
        if (! Schema::hasTable('business_services')) {
            return;
        }

        $sequence = 1;

        foreach (DB::table('business_services')->orderBy('id')->get() as $legacy) {
            $capability = $legacy->capability_id
                ? DB::table('ea_capabilities')->where('legacy_id', $legacy->capability_id)->first()
                : null;

            DB::table('ea_business_services')->insert([
                'organization_id' => $legacy->organization_id,
                'code' => 'BS-'.str_pad((string) $sequence++, 3, '0', STR_PAD_LEFT),
                'name' => $legacy->name,
                'description' => $legacy->description ?? null,
                'capability_id' => $capability?->id,
                'owner_id' => $legacy->owner_id,
                'criticality' => $legacy->criticality ?? 'medium',
                'rto_minutes' => $legacy->recovery_time_objective_min ?? null,
                'rpo_minutes' => $legacy->recovery_point_objective_min ?? null,
                'status' => 'active',
                'legacy_id' => $legacy->id,
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function migrateProcesses(): void
    {
        if (! Schema::hasTable('business_processes')) {
            return;
        }

        $sequence = 1;

        foreach (DB::table('business_processes')->orderBy('id')->get() as $legacy) {
            $existing = DB::table('ea_processes')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($legacy->name)])
                ->first();

            $service = $legacy->service_id
                ? DB::table('ea_business_services')->where('legacy_id', $legacy->service_id)->first()
                : null;

            if ($existing) {
                DB::table('ea_processes')->where('id', $existing->id)->whereNull('legacy_id')->update([
                    'legacy_id' => $legacy->id,
                    'service_id' => $service?->id,
                    'owner_id' => $legacy->owner_id,
                ]);

                continue;
            }

            do {
                $code = 'BP-'.str_pad((string) $sequence++, 3, '0', STR_PAD_LEFT);
            } while (DB::table('ea_processes')->where('code', $code)->exists());

            DB::table('ea_processes')->insert([
                'organization_id' => $legacy->organization_id,
                'code' => $code,
                'name' => $legacy->name,
                'level' => 2,
                'criticality' => $legacy->criticality ?? 'medium',
                'service_id' => $service?->id,
                'owner_id' => $legacy->owner_id,
                'legacy_id' => $legacy->id,
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * `service_dependencies` folds into `ea_relationships` — §7.2: "Reconcile
     * BusinessService + ServiceDependency into EA". The EA graph table is the
     * single relationship store §7.1's one-graph principle requires.
     */
    private function migrateDependencies(): void
    {
        if (! Schema::hasTable('service_dependencies') || ! Schema::hasTable('ea_relationships')) {
            return;
        }

        // The legacy table stores short discriminators ("service"), not
        // fully-qualified class names, so both forms are accepted. Getting this
        // wrong silently migrates nothing — the rows are skipped, not failed —
        // so the post-migration count is asserted in EaCanonicalModelTest.
        $resolve = function (?string $type): ?array {
            if (! $type) {
                return null;
            }

            return match (strtolower(class_basename($type))) {
                'service', 'businessservice' => ['ea_business_services', 'App\\Models\\Ea\\BusinessService'],
                'capability', 'businesscapability' => ['ea_capabilities', 'App\\Models\\Ea\\Capability'],
                'process', 'businessprocess' => ['ea_processes', 'App\\Models\\Ea\\Process'],
                default => null,
            };
        };

        $lookup = function (?string $type, $id) use ($resolve) {
            $resolved = $resolve($type);

            if (! $resolved || ! $id) {
                return [null, null];
            }

            [$table, $class] = $resolved;
            $row = DB::table($table)->where('legacy_id', $id)->first();

            return [$class, $row?->id];
        };

        foreach (DB::table('service_dependencies')->orderBy('id')->get() as $legacy) {
            [$sourceType, $sourceId] = $lookup($legacy->source_type, $legacy->source_id);
            [$targetType, $targetId] = $lookup($legacy->target_type, $legacy->target_id);

            if (! $sourceId || ! $targetId) {
                continue;
            }

            $exists = DB::table('ea_relationships')
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('target_type', $targetType)->where('target_id', $targetId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('ea_relationships')->insert([
                'organization_id' => $legacy->organization_id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'relation_type' => strtolower($legacy->relation_type ?: 'serving'),
                'attrs' => json_encode(['migrated_from' => 'service_dependencies', 'legacy_id' => $legacy->id]),
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The legacy tables were never dropped, so reversing means removing
        // only what this migration added. Rows the migration created in
        // ea_capabilities / ea_processes are identifiable by legacy_id.
        DB::table('ea_relationships')
            ->whereJsonContains('attrs->migrated_from', 'service_dependencies')
            ->delete();

        if (Schema::hasColumn('ea_processes', 'legacy_id')) {
            DB::table('ea_processes')->whereNotNull('legacy_id')
                ->whereRaw('code LIKE ?', ['BP-%'])->delete();

            Schema::table('ea_processes', function (Blueprint $table) {
                $table->dropColumn(['service_id', 'owner_id', 'legacy_id']);
            });
        }

        if (Schema::hasColumn('ea_capabilities', 'legacy_id')) {
            DB::table('ea_capabilities')->whereNotNull('legacy_id')
                ->whereRaw('code LIKE ?', ['BC-%'])->delete();

            Schema::table('ea_capabilities', function (Blueprint $table) {
                $table->dropColumn('legacy_id');
            });
        }

        Schema::dropIfExists('ea_business_services');
    }
};
