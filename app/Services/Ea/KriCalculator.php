<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\DataFlow;
use App\Models\Ea\DpiaAssessment;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Exception as EaException;
use App\Models\Ea\Initiative;
use App\Models\Ea\KriDefinition;
use App\Models\Ea\KriValue;
use App\Models\Ea\Plateau;
use App\Models\Ea\TechComponent;

/**
 * KriCalculator — implements the ten initial KRI calculations from
 * ATH-GAP-EA-001 Appendix C and writes them as a fresh row into ea_kri_values
 * for the current month.
 */
class KriCalculator
{
    public const REGISTRY = [
        'EA-KRI-01' => 'applicationsWithoutOwner',
        'EA-KRI-02' => 'criticalAppsWithoutDr',
        'EA-KRI-03' => 'techEol12mWithoutReplacement',
        'EA-KRI-04' => 'openExceptionsPastExpiry',
        'EA-KRI-05' => 'crossBorderFlowsWithoutDpia',
        'EA-KRI-06' => 'capabilitiesMaturity1',
        'EA-KRI-07' => 'standardsExceptionsLastQuarter',
        'EA-KRI-08' => 'vendorConcentration',
        'EA-KRI-09' => 'initiativesOnTime',
        'EA-KRI-10' => 'plateauDriftPercent',
    ];

    public function compute(): array
    {
        $period = now()->format('Y-m');
        $written = [];
        foreach (self::REGISTRY as $code => $method) {
            $def = KriDefinition::where('code', $code)->first();
            if (! $def) continue;
            $value = (float) call_user_func([$this, $method]);
            $status = $this->bandFor($def, $value);
            KriValue::create([
                'kri_id' => $def->id,
                'period' => $period,
                'value' => $value,
                'status' => $status,
                'recorded_at' => now(),
            ]);
            $written[$code] = ['value' => $value, 'status' => $status];
        }
        return $written;
    }

    public function applicationsWithoutOwner(): int
    {
        return EaApplication::whereNull('owner_role')->orWhere('owner_role', '')->count();
    }

    public function criticalAppsWithoutDr(): int
    {
        // Approximation: applications criticality=critical and no plateau_id (no DR plan recorded)
        return EaApplication::where('criticality', 'critical')
            ->whereNull('plateau_id')->count();
    }

    public function techEol12mWithoutReplacement(): int
    {
        $eolList = TechComponent::whereNotNull('eol_date')
            ->whereBetween('eol_date', [now(), now()->addYear()])->get();
        $count = 0;
        foreach ($eolList as $t) {
            $hasRep = Initiative::where(function ($q) use ($t) {
                $q->where('description', 'like', "%{$t->name}%")
                  ->orWhere('name', 'like', "%{$t->name}%");
            })->whereIn('status', ['proposed', 'approved', 'in_flight'])->exists();
            if (!$hasRep) $count++;
        }
        return $count;
    }

    public function openExceptionsPastExpiry(): int
    {
        return EaException::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();
    }

    public function crossBorderFlowsWithoutDpia(): int
    {
        $flows = DataFlow::where('cross_border', true)->pluck('id');
        $covered = DpiaAssessment::whereIn('data_flow_id', $flows)
            ->whereIn('status', ['approved', 'in_review'])->pluck('data_flow_id');
        return $flows->diff($covered)->count();
    }

    public function capabilitiesMaturity1(): int
    {
        return Capability::where('maturity', 1)->count();
    }

    public function standardsExceptionsLastQuarter(): int
    {
        return EaException::where('created_at', '>=', now()->subMonths(3))->count();
    }

    public function vendorConcentration(): int
    {
        // Apps belonging to the largest-share vendor
        $top = EaApplication::query()
            ->whereNotNull('vendor_id')
            ->selectRaw('vendor_id, COUNT(*) as c')
            ->groupBy('vendor_id')
            ->orderByDesc('c')
            ->first();
        return $top ? (int) $top->c : 0;
    }

    public function initiativesOnTime(): float
    {
        $window = Initiative::where('target_end_date', '>=', now()->subYear());
        $total = $window->count();
        if ($total === 0) return 100.0;
        $onTime = (clone $window->getQuery())
            ->whereIn('status', ['delivered', 'in_flight'])
            ->where(function ($q) {
                $q->whereNull('target_end_date')->orWhere('target_end_date', '>=', now());
            })->count();
        return round(($onTime / max($total, 1)) * 100, 2);
    }

    public function plateauDriftPercent(): float
    {
        $target = Plateau::where('plateau_type', 'target')->orderByDesc('effective_from')->first();
        if (!$target) return 0;
        $expected = EaApplication::where('plateau_id', $target->id)->count();
        $total = EaApplication::count();
        if ($total === 0) return 0;
        return round((($total - $expected) / $total) * 100, 2);
    }

    private function bandFor(KriDefinition $def, float $value): string
    {
        $g = $def->threshold_green; $a = $def->threshold_amber; $r = $def->threshold_red;
        if ($def->direction === 'higher_better') {
            if ($value >= $g) return 'green';
            if ($value >= $a) return 'amber';
            return 'red';
        }
        // higher_worse
        if ($value <= $g) return 'green';
        if ($value <= $a) return 'amber';
        return 'red';
    }
}
