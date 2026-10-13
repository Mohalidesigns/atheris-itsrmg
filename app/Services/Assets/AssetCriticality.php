<?php

namespace App\Services\Assets;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Criticality inheritance (plan §2.4.1 `criticality_inherited`, gap D1.2/D1.3).
 *
 * An asset inherits the criticality of the most critical business service it supports: a
 * "medium" server that hosts NIBSS NIP is a critical asset for that bank, whatever the asset
 * owner typed. The inherited value is stored on the asset; the owner's own rating is never
 * overwritten, and Asset::effective_criticality takes the higher of the two.
 */
class AssetCriticality
{
    /** Recompute the inherited rating for the given assets (ids), or every asset of one bank. */
    public function recompute(array $assetIds = [], ?int $organizationId = null): int
    {
        $query = Asset::withoutGlobalScopes()->withTrashed()
            ->when($assetIds, fn ($q) => $q->whereIn('id', $assetIds))
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId));

        if (! $assetIds && ! $organizationId) {
            return 0;
        }

        $inherited = DB::table('asset_business_service as abs')
            ->join('ea_business_services as s', 's.id', '=', 'abs.business_service_id')
            ->whereIn('abs.asset_id', (clone $query)->select('id'))
            ->get(['abs.asset_id', 's.criticality'])
            ->groupBy('asset_id')
            ->map(fn ($rows) => $rows->pluck('criticality')
                ->sortByDesc(fn ($c) => Asset::CRITICALITY_RANK[$c] ?? 0)->first());

        $changed = 0;
        foreach ($query->get(['id', 'criticality_inherited']) as $asset) {
            $value = $inherited[$asset->id] ?? null;
            if ($asset->criticality_inherited !== $value) {
                // Query-builder write: inheritance is derived data and must not spam the activity log.
                DB::table('assets')->where('id', $asset->id)->update(['criticality_inherited' => $value]);
                $changed++;
            }
        }

        return $changed;
    }

    /** A service's criticality changed or it was deleted: re-derive every asset that supported it. */
    public function recomputeForService(int $serviceId): int
    {
        $ids = DB::table('asset_business_service')->where('business_service_id', $serviceId)->pluck('asset_id')->all();

        return $ids ? $this->recompute($ids) : 0;
    }
}
