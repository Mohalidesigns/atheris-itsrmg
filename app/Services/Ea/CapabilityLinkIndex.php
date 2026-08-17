<?php

namespace App\Services\Ea;

use App\Models\Ea\ApplicationCapability;
use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CapabilityLinkIndex — materialises `ea_applications_ext.capability_ids` into
 * the `ea_application_capabilities` pivot, and answers the two questions that
 * used to require `whereJsonContains`.
 *
 * §10 names the problem: "JSON-column filtering will not hold". Specifically:
 *
 *   · `EaController::capabilityOverlay()` runs one `whereJsonContains` per
 *     capability — 5,000 unindexed full scans to paint one map.
 *   · `CapabilityShow` runs another to list linked applications.
 *   · `BlastRadiusService::changeImpact()` pulls every app's `capability_ids`
 *     into PHP and flattens it.
 *
 * The JSON column stays the authoring surface (forms post an array of ids); this
 * index is derived, and {@see sync()} runs on every application write via the
 * model observer so it cannot drift.
 */
class CapabilityLinkIndex
{
    /** Sync one application's rows from its JSON column. */
    public function sync(EaApplication $application): void
    {
        if (! Schema::hasTable('ea_application_capabilities')) {
            return;
        }

        $ids = collect($application->capability_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        // Drop links to capabilities that no longer exist rather than storing a
        // dangling pointer — the GraphResolver stance in §7.1, applied here.
        if ($ids->isNotEmpty()) {
            $ids = Capability::withoutGlobalScopes()->whereIn('id', $ids)->pluck('id')
                ->map(fn ($id) => (int) $id)->values();
        }

        $existing = ApplicationCapability::where('application_id', $application->id)->get();
        $existingByCapability = $existing->keyBy('capability_id');

        // Custom weights are an architect's judgement; do not overwrite them
        // just because the app was renamed. Only rows whose weight still looks
        // like an even split get re-balanced.
        $evenWeight = $ids->isNotEmpty() ? round(1 / $ids->count(), 4) : 0.0;

        foreach ($ids as $capabilityId) {
            $row = $existingByCapability->get($capabilityId);
            if ($row) {
                if ($this->looksEven($row->allocation_weight, $existing->count())) {
                    $row->update(['allocation_weight' => $evenWeight]);
                }
                continue;
            }
            ApplicationCapability::create([
                'organization_id' => $application->organization_id,
                'application_id' => $application->id,
                'capability_id' => $capabilityId,
                'allocation_weight' => $evenWeight,
            ]);
        }

        ApplicationCapability::where('application_id', $application->id)
            ->whereNotIn('capability_id', $ids->all() ?: [0])
            ->delete();
    }

    /** A weight within a rounding step of the previous even split. */
    private function looksEven(?float $weight, int $previousCount): bool
    {
        if ($weight === null || $previousCount === 0) {
            return true;
        }

        return abs($weight - round(1 / max(1, $previousCount), 4)) < 0.001;
    }

    /**
     * Full rebuild. Returns rows written.
     */
    public function rebuild(): int
    {
        if (! Schema::hasTable('ea_application_capabilities')) {
            return 0;
        }

        $valid = Schema::hasTable('ea_capabilities')
            ? array_flip(DB::table('ea_capabilities')->pluck('id')->map(fn ($i) => (int) $i)->all())
            : [];

        $rows = [];
        DB::table('ea_applications_ext')
            ->select('id', 'organization_id', 'capability_ids')
            ->orderBy('id')
            ->chunk(500, function ($apps) use (&$rows, $valid) {
                foreach ($apps as $app) {
                    $ids = json_decode((string) $app->capability_ids, true);
                    if (! is_array($ids)) {
                        continue;
                    }
                    $ids = array_values(array_filter(
                        array_unique(array_map('intval', $ids)),
                        fn ($id) => isset($valid[$id])
                    ));
                    if (! $ids) {
                        continue;
                    }
                    $weight = round(1 / count($ids), 4);
                    foreach ($ids as $capabilityId) {
                        $rows[] = [
                            'organization_id' => $app->organization_id,
                            'application_id' => (int) $app->id,
                            'capability_id' => $capabilityId,
                            'allocation_weight' => $weight,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            });

        DB::table('ea_application_capabilities')->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ea_application_capabilities')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    /**
     * Application ids per capability, in one query rather than one per
     * capability. This single method replaces the overlay's N scans.
     *
     * @return array<int,array<int,int>> capability id => application ids
     */
    public function applicationsByCapability(): array
    {
        $map = [];
        foreach (ApplicationCapability::query()->get(['capability_id', 'application_id']) as $row) {
            $map[(int) $row->capability_id][] = (int) $row->application_id;
        }

        return $map;
    }

    /** Capability ids realised by a set of applications. */
    public function capabilitiesFor(array $applicationIds): array
    {
        if (! $applicationIds) {
            return [];
        }

        return ApplicationCapability::whereIn('application_id', $applicationIds)
            ->distinct()->pluck('capability_id')->map(fn ($i) => (int) $i)->all();
    }

    /** Application ids realising a capability, optionally including its subtree. */
    public function applicationsFor(int $capabilityId, bool $includeDescendants = false): array
    {
        $ids = [$capabilityId];
        if ($includeDescendants) {
            $ids = array_merge($ids, (new HierarchyIndex())->subtree(Capability::class, $capabilityId));
        }

        return ApplicationCapability::whereIn('capability_id', $ids)
            ->distinct()->pluck('application_id')->map(fn ($i) => (int) $i)->all();
    }

    /** True when the pivot matches the JSON columns. */
    public function isFresh(): bool
    {
        if (! Schema::hasTable('ea_application_capabilities')) {
            return false;
        }

        $expected = 0;
        $valid = array_flip(DB::table('ea_capabilities')->pluck('id')->map(fn ($i) => (int) $i)->all());
        foreach (DB::table('ea_applications_ext')->pluck('capability_ids') as $json) {
            $ids = json_decode((string) $json, true);
            if (! is_array($ids)) {
                continue;
            }
            $expected += count(array_filter(
                array_unique(array_map('intval', $ids)),
                fn ($id) => isset($valid[$id])
            ));
        }

        return $expected === DB::table('ea_application_capabilities')->count();
    }
}
