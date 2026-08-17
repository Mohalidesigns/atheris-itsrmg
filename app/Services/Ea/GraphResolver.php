<?php

namespace App\Services\Ea;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GraphResolver — §8.3: "Validate and resolve every cross-module reference; the
 * single place soft FKs are enforced."
 *
 * §7.1's implementation stance: "Prefer **soft foreign keys plus domain
 * events** over hard database constraints. The platform is a modular monolith
 * and the seams should stay clean, but the *application layer* must enforce
 * referential integrity, and **seeders must stop writing random IDs**."
 *
 * The problem this exists to end (§2.5): "`ea_control_mappings.control_id` is
 * an unconstrained bigint that `EaPhase2Seeder` populates with `rand(1,80)`.
 * `ControlInheritanceService` then computes control posture over IDs that may
 * not resolve. **Any coverage figure the module currently produces is
 * arithmetic over noise, and must not be shown to a regulator.**"
 *
 * Every cross-module id therefore passes through here before it is trusted.
 */
class GraphResolver
{
    /**
     * Cross-module reference registry: which EA column points at which table
     * in which other module.
     *
     * @var array<string, array{table:string, module:string, label:string}>
     */
    public const REFERENCES = [
        'control_id' => ['table' => 'controls', 'module' => 'Compliance', 'label' => 'Control'],
        'vendor_id' => ['table' => 'vendors', 'module' => 'TPRM', 'label' => 'Vendor'],
        'asset_id' => ['table' => 'assets', 'module' => 'Assets', 'label' => 'Asset'],
        'risk_id' => ['table' => 'risks', 'module' => 'Risk', 'label' => 'Risk'],
        'incident_id' => ['table' => 'incidents', 'module' => 'SecurityOps', 'label' => 'Incident'],
        'owner_id' => ['table' => 'users', 'module' => 'Platform', 'label' => 'User'],
    ];

    /** In-request memo so a bulk validation does not re-query per row. */
    private array $existing = [];

    /**
     * Does a cross-module id resolve?
     *
     * Returns true for null — an absent reference is a modelling choice, not a
     * dangling pointer. Callers wanting "must be present" should check that
     * separately, so the two failures stay distinguishable in error messages.
     */
    public function resolves(string $column, $id): bool
    {
        if ($id === null || $id === '') {
            return true;
        }

        $reference = self::REFERENCES[$column] ?? null;
        if (! $reference || ! Schema::hasTable($reference['table'])) {
            return true;
        }

        return in_array((int) $id, $this->idsFor($reference['table']), true);
    }

    /** @return array<int,int> */
    private function idsFor(string $table): array
    {
        return $this->existing[$table] ??= DB::table($table)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Validate an attribute array before a write, throwing on the first
     * dangling reference.
     *
     * @throws \InvalidArgumentException
     */
    public function assertResolvable(array $attributes): void
    {
        foreach ($attributes as $column => $value) {
            if (! isset(self::REFERENCES[$column])) {
                continue;
            }
            if (! $this->resolves($column, $value)) {
                $reference = self::REFERENCES[$column];

                throw new \InvalidArgumentException(
                    "{$reference['label']} #{$value} does not exist in the {$reference['module']} module. ".
                    'Cross-module references must resolve before they can be cited in a return.'
                );
            }
        }
    }

    /**
     * Audit the whole EA schema for dangling cross-module references.
     *
     * This is the report that tells an architect how much of the control
     * coverage figure is real. §10 makes evidence integrity a release gate;
     * a coverage percentage computed over unresolvable ids fails it.
     *
     * @return array<int, array{table:string,column:string,module:string,total:int,dangling:int,percent:float}>
     */
    public function audit(): array
    {
        $checks = [
            ['ea_control_mappings', 'control_id'],
            ['ea_applications_ext', 'vendor_id'],
            ['ea_applications_ext', 'asset_id'],
            ['ea_business_services', 'owner_id'],
            ['ea_processes', 'owner_id'],
        ];

        $rows = [];

        foreach ($checks as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $reference = self::REFERENCES[$column];
            if (! Schema::hasTable($reference['table'])) {
                continue;
            }

            $total = DB::table($table)->whereNotNull($column)->count();

            $dangling = DB::table($table)
                ->whereNotNull($column)
                ->whereNotIn($column, DB::table($reference['table'])->select('id'))
                ->count();

            $rows[] = [
                'table' => $table,
                'column' => $column,
                'module' => $reference['module'],
                'label' => $reference['label'],
                'total' => $total,
                'dangling' => $dangling,
                'resolved' => $total - $dangling,
                'percent' => $total ? round(($total - $dangling) / $total * 100, 1) : 100.0,
            ];
        }

        return $rows;
    }

    /** True when every cross-module reference in the schema resolves. */
    public function isClean(): bool
    {
        return collect($this->audit())->every(fn ($row) => $row['dangling'] === 0);
    }

    /**
     * Null out references that do not resolve.
     *
     * A dangling id is worse than an absent one: absent is visibly incomplete,
     * dangling silently inflates a coverage figure. Used by the seeder repair
     * path (WS 2.4) rather than at runtime.
     *
     * @return array<string,int> cleared count per "table.column"
     */
    public function pruneDangling(): array
    {
        $cleared = [];

        foreach ($this->audit() as $row) {
            if ($row['dangling'] === 0) {
                continue;
            }

            $reference = self::REFERENCES[$row['column']];

            $count = DB::table($row['table'])
                ->whereNotNull($row['column'])
                ->whereNotIn($row['column'], DB::table($reference['table'])->select('id'))
                ->update([$row['column'] => null]);

            $cleared["{$row['table']}.{$row['column']}"] = $count;
        }

        $this->existing = [];

        return $cleared;
    }
}
