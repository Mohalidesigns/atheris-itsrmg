<?php

namespace App\Services\Kri;

use App\Models\Kri;
use App\Models\KriBreach;

/** KRI figures for one bank (ATH-VAL-014): the KRI dashboard, board packs and executive dashboards read these. */
class KriMetrics
{
    public function summary(int $orgId): array
    {
        $kris = Kri::withoutGlobalScopes()->where('organization_id', $orgId)->with('latestReading')->get();
        $breaches = KriBreach::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('resolved_at')->get(['level', 'acknowledged_at']);

        return [
            'total' => $kris->count(),
            'green' => $kris->filter(fn ($k) => $k->latestReading?->status === 'green')->count(),
            'amber' => $kris->filter(fn ($k) => $k->latestReading?->status === 'amber')->count(),
            'red' => $kris->filter(fn ($k) => $k->latestReading?->status === 'red')->count(),
            'stale' => $kris->filter(fn ($k) => $k->isStale())->count(),
            'unowned' => $kris->whereNull('owner_id')->count(),
            'breaches_open' => $breaches->count(),
            'breaches_red' => $breaches->where('level', 'red')->count(),
            'breaches_unacknowledged' => $breaches->whereNull('acknowledged_at')->count(),
        ];
    }

    /** Latest reading per KRI, worst first: the board pack's KRI panel. */
    public function panel(int $orgId, int $limit = 12): array
    {
        $rank = ['red' => 0, 'amber' => 1, 'green' => 2];

        return Kri::withoutGlobalScopes()->where('organization_id', $orgId)->with(['latestReading', 'owner:id,name'])->get()
            ->filter(fn ($k) => $k->latestReading)
            ->sortBy(fn ($k) => [$rank[$k->latestReading->status] ?? 3, $k->code])
            ->take($limit)
            ->map(fn ($k) => ['code' => $k->code, 'name' => $k->name, 'category' => $k->category, 'value' => $k->latestReading->value,
                'unit' => $k->unit, 'status' => $k->latestReading->status, 'period' => $k->latestReading->period, 'owner' => $k->owner?->name])
            ->values()->all();
    }
}
