<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetSyncJob;
use App\Models\Ea\BusinessService;
use App\Models\Vulnerability;
use Illuminate\Support\Facades\DB;

/**
 * One set of Asset Management numbers, read by the register, Asset Discovery and the executive
 * dashboard, so the three never disagree. Every query goes through tenant-scoped models.
 */
class AssetMetrics
{
    /** A discovered asset not seen by its connector for this long is reported stale. */
    public const STALE_DAYS = 30;

    public const EOL_WARNING_DAYS = 90;

    public function summary(): array
    {
        $live = fn () => Asset::query()->where('status', '!=', 'decommissioned');
        $today = now()->startOfDay();

        $assets = $live()->get(['id', 'criticality', 'criticality_inherited', 'asset_type', 'source', 'owner_id', 'data_classification', 'end_of_life', 'last_seen_at', 'status']);

        $byCriticality = collect(Asset::CRITICALITIES)->mapWithKeys(fn ($c) => [$c => 0])->all();
        foreach ($assets as $a) {
            $byCriticality[$a->effective_criticality] = ($byCriticality[$a->effective_criticality] ?? 0) + 1;
        }

        $mappedIds = DB::table('asset_business_service')->whereIn('asset_id', $assets->pluck('id'))->distinct()->pluck('asset_id')->flip();
        $important = $assets->filter(fn ($a) => in_array($a->effective_criticality, ['critical', 'high'], true));

        $vulnerable = Asset::query()->where('status', '!=', 'decommissioned')
            ->whereHas('vulnerabilities', fn ($q) => $q->whereIn('severity', ['critical', 'high'])
                ->whereIn('vulnerabilities.status', Vulnerability::OPEN_STATUSES))
            ->get(['id', 'criticality', 'criticality_inherited'])
            ->filter(fn ($a) => $a->effective_criticality === 'critical')
            ->count();

        $services = BusinessService::query()->withCount('assets')->get(['id', 'criticality']);

        return [
            'total' => $assets->count(),
            'by_criticality' => $byCriticality,
            'by_type' => $assets->countBy('asset_type')->all(),
            'by_source' => $assets->countBy(fn ($a) => $a->source ?: 'manual')->all(),
            'inherited_uplift' => $assets->filter(fn ($a) => $a->criticality_inherited
                && (Asset::CRITICALITY_RANK[$a->criticality_inherited] ?? 0) > (Asset::CRITICALITY_RANK[$a->criticality] ?? 0))->count(),
            'unowned' => $assets->whereNull('owner_id')->count(),
            'unclassified' => $assets->whereNull('data_classification')->count(),
            'eol_passed' => $assets->filter(fn ($a) => $a->end_of_life && $a->end_of_life->lt($today))->count(),
            'eol_soon' => $assets->filter(fn ($a) => $a->end_of_life && $a->end_of_life->gte($today)
                && $a->end_of_life->lte($today->copy()->addDays(self::EOL_WARNING_DAYS)))->count(),
            'pending_review' => $assets->where('status', 'under_review')->count(),
            'discovered' => $assets->filter(fn ($a) => ($a->source ?: 'manual') !== 'manual')->count(),
            'stale' => $assets->filter(fn ($a) => ($a->source ?: 'manual') !== 'manual' && $a->source !== 'csv'
                && (! $a->last_seen_at || $a->last_seen_at->lt(now()->subDays(self::STALE_DAYS))))->count(),
            'important_unmapped' => $important->reject(fn ($a) => isset($mappedIds[$a->id]))->count(),
            'critical_with_open_vulns' => $vulnerable,
            'services' => $services->count(),
            'services_without_assets' => $services->where('assets_count', 0)->count(),
            'last_sync' => AssetSyncJob::query()->orderByDesc('finished_at')->value('finished_at'),
        ];
    }

    /** Live assets past or nearest their end of life, most critical first within the date order. */
    public function eolWatch(int $limit = 5): array
    {
        return Asset::query()->where('status', '!=', 'decommissioned')
            ->whereNotNull('end_of_life')
            ->whereDate('end_of_life', '<=', now()->addDays(self::EOL_WARNING_DAYS)->toDateString())
            ->orderBy('end_of_life')->limit($limit)
            ->get(['id', 'asset_id_code', 'name', 'criticality', 'criticality_inherited', 'end_of_life'])
            ->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->asset_id_code, 'name' => $a->name,
                'criticality' => $a->effective_criticality,
                'end_of_life' => $a->end_of_life->toDateString(),
                'days' => (int) now()->startOfDay()->diffInDays($a->end_of_life, false),
            ])->all();
    }
}
