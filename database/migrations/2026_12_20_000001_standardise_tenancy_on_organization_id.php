<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATH-EAR-002 WS 2.2 — tenancy standardisation.
 *
 * §2.5 (RC-5): EA's tables use `tenant_id` and never `organization_id`;
 * `BelongsToTenant` resolves `tenant_id` from `Auth::user()->organization_id`
 * and `OrganizationScope` filters on `organization_id` — "so the two are
 * functionally the same value under different names".
 *
 * §7.1 makes fixing this a *precondition* for the returns engine, "because a
 * return that cites an entity must be able to resolve it". §2.5 notes the split
 * is not EA-versus-core but old-core-versus-newer-modules, "which makes it a
 * platform-level cleanup, not an EA one, and slightly larger than it first
 * appears".
 *
 * It is larger still than the document estimated: 80 tables carried
 * `tenant_id`, not the ~48 in §13. No table carried both, so the rename is
 * unambiguous.
 *
 * `BelongsToTenant` gains a `tenant_id` accessor/mutator so code and seeders
 * still speaking the old name keep working — the compatibility accessor §2.5
 * asks for.
 *
 * Portability matters here: the test suite runs on in-memory SQLite while
 * production is MySQL, so this uses Laravel's schema builder throughout rather
 * than `SHOW TABLES` and `ALTER TABLE ... CHANGE`. Reversible via down().
 */
return new class extends Migration
{
    /**
     * Tables carrying `$from` and not `$to`.
     *
     * Discovered at run time rather than hard-coded: a fixed list would
     * silently miss a table added between authoring and deployment, leaving a
     * mixed schema — the exact failure this migration exists to end.
     */
    private function tablesToRename(string $from, string $to): array
    {
        $names = [];

        foreach (Schema::getTableListing() as $table) {
            // Laravel 12+ may qualify names as "schema.table".
            $table = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;

            if (Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
                $names[] = $table;
            }
        }

        // De-duplicate. On MySQL the listing spans every schema the connection
        // can see, so a table name present in two databases is stripped to the
        // same unqualified name twice. Renaming it twice throws on the second
        // pass — the column is already gone — and takes `migrate:fresh` down
        // with it. Found when a second database on the same MySQL server
        // happened to share table names with this one.
        return array_values(array_unique($names));
    }

    private function rename(array $tables, string $from, string $to): void
    {
        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
                $blueprint->renameColumn($from, $to);
            });
        }
    }

    public function up(): void
    {
        $tables = $this->tablesToRename('tenant_id', 'organization_id');

        $this->rename($tables, 'tenant_id', 'organization_id');

        logger()->info('[EA] Tenancy standardised on organization_id', ['tables' => count($tables)]);
    }

    public function down(): void
    {
        // Only tables renamed *by this migration* go back — the 33 tables that
        // always used organization_id must not be touched. They are told apart
        // by carrying a foreign key on organization_id to `organizations`,
        // which the newer-module tables never declared.
        $constrained = $this->tablesWithOrganizationForeignKey();

        $candidates = array_values(array_filter(
            $this->tablesToRename('organization_id', 'tenant_id'),
            fn ($table) => ! in_array($table, $constrained, true),
        ));

        $this->rename($candidates, 'organization_id', 'tenant_id');
    }

    private function tablesWithOrganizationForeignKey(): array
    {
        $tables = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;

            try {
                foreach (Schema::getForeignKeys($table) as $foreignKey) {
                    if (in_array('organization_id', $foreignKey['columns'] ?? [], true)) {
                        $tables[] = $table;
                        break;
                    }
                }
            } catch (\Throwable) {
                // Driver does not support foreign-key introspection; skip.
            }
        }

        return $tables;
    }
};
