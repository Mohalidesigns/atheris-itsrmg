<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Initiative;
use App\Models\Ea\Plateau;
use App\Models\Ea\TechComponent;
use Illuminate\Support\Collection;

/**
 * ScenarioComparer — produces a side-by-side delta of two architecture
 * plateaux. Used by the Scenarios page (Avolution ABACUS / BiZZdesign-style
 * "compare two target futures") and the Roadmap detail.
 */
class ScenarioComparer
{
    public function compare(int $plateauAId, int $plateauBId): array
    {
        $a = Plateau::findOrFail($plateauAId);
        $b = Plateau::findOrFail($plateauBId);

        $apps = $this->compareEntity(EaApplication::class, $plateauAId, $plateauBId);
        $caps = $this->compareEntity(Capability::class, $plateauAId, $plateauBId);
        $tech = $this->compareEntity(TechComponent::class, $plateauAId, $plateauBId);

        $initiativesA = Initiative::where('plateau_id', $plateauAId)->get();
        $initiativesB = Initiative::where('plateau_id', $plateauBId)->get();

        $costA = (float) EaApplication::where('plateau_id', $plateauAId)->sum('annual_cost_ngn');
        $costB = (float) EaApplication::where('plateau_id', $plateauBId)->sum('annual_cost_ngn');

        return [
            'plateau_a' => $a,
            'plateau_b' => $b,
            'applications' => $apps,
            'capabilities' => $caps,
            'tech' => $tech,
            'initiatives' => [
                'in_a' => $initiativesA->pluck('name')->all(),
                'in_b' => $initiativesB->pluck('name')->all(),
                'only_a' => $initiativesA->pluck('name')->diff($initiativesB->pluck('name'))->values()->all(),
                'only_b' => $initiativesB->pluck('name')->diff($initiativesA->pluck('name'))->values()->all(),
            ],
            'cost' => [
                'a' => $costA,
                'b' => $costB,
                'delta' => $costB - $costA,
            ],
            'summary' => [
                'apps_added' => count($apps['added']),
                'apps_removed' => count($apps['removed']),
                'apps_kept' => count($apps['kept']),
                'tech_added' => count($tech['added']),
                'tech_removed' => count($tech['removed']),
            ],
        ];
    }

    private function compareEntity(string $modelClass, int $a, int $b): array
    {
        $rowsA = $modelClass::where('plateau_id', $a)->get(['id', 'code', 'name']);
        $rowsB = $modelClass::where('plateau_id', $b)->get(['id', 'code', 'name']);
        $codesA = $rowsA->pluck('code');
        $codesB = $rowsB->pluck('code');
        return [
            'added' => $rowsB->whereNotIn('code', $codesA)->values()->all(),
            'removed' => $rowsA->whereNotIn('code', $codesB)->values()->all(),
            'kept' => $rowsA->whereIn('code', $codesB)->values()->all(),
        ];
    }
}
