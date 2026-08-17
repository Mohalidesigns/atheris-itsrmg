<?php

namespace App\Services\Ea;

use App\Models\Asset;
use App\Models\Ea\EaApplication;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * AssetApplicationClient — read-through layer between the Asset Management
 * module's `assets` table (the CMDB) and the EA application extension
 * `ea_applications_ext`. Implements a 5-minute Redis cache and a tiny circuit
 * breaker so an Asset Management outage cannot drag the EA dashboards down.
 *
 * When called by a sync job, the client upserts any missing
 * `ea_applications_ext` rows from the Asset Management catalogue so that EA
 * has live application data instead of seeded demo data (ATH-GAP-EA-001 §5.3).
 */
class AssetApplicationClient
{
    public const CACHE_TTL = 300; // seconds
    public const CACHE_KEY = 'ea:asset-app-client:catalogue';

    private static int $failCount = 0;
    private static int $breakerThreshold = 3;
    private static ?int $breakerOpenedAt = null;

    public function catalogue(): array
    {
        if ($this->breakerOpen()) {
            return ['rows' => [], 'cached' => false, 'circuit' => 'open'];
        }
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                if (! class_exists(\App\Models\Asset::class) || ! \Schema::hasTable('assets')) {
                    return ['rows' => [], 'cached' => true, 'circuit' => 'closed', 'reason' => 'assets-table-absent'];
                }
                $rows = Asset::query()
                    ->where('asset_type', 'application')
                    ->orWhere('asset_type', 'software')
                    ->limit(2000)
                    ->get(['id', 'name', 'asset_type', 'criticality', 'owner_id'])
                    ->all();
                self::$failCount = 0;
                return ['rows' => $rows, 'cached' => true, 'circuit' => 'closed'];
            } catch (\Throwable $e) {
                self::$failCount++;
                if (self::$failCount >= self::$breakerThreshold) {
                    self::$breakerOpenedAt = time();
                }
                return ['rows' => [], 'cached' => false, 'circuit' => 'half-open', 'error' => $e->getMessage()];
            }
        });
    }

    public function syncIntoEa(): array
    {
        $cat = $this->catalogue();
        $created = 0; $updated = 0;
        foreach ($cat['rows'] as $asset) {
            $code = 'ASSET-'.($asset->id);
            $existing = EaApplication::where('code', $code)->first();
            $attrs = [
                'asset_id' => $asset->id,
                'name' => $asset->name,
                'criticality' => $asset->criticality ?: 'medium',
                'lifecycle' => 'live',
            ];
            if ($existing) {
                $existing->update($attrs);
                $updated++;
            } else {
                EaApplication::create(['code' => $code] + $attrs);
                $created++;
            }
        }
        return ['created' => $created, 'updated' => $updated, 'circuit' => $cat['circuit'] ?? 'closed'];
    }

    private function breakerOpen(): bool
    {
        if (self::$breakerOpenedAt === null) return false;
        // open for 60s
        if (time() - self::$breakerOpenedAt > 60) {
            self::$breakerOpenedAt = null;
            self::$failCount = 0;
            return false;
        }
        return true;
    }
}
