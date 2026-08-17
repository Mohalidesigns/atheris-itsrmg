<?php

namespace App\Services\Ea;

use App\Events\Ea\TechComponentBecameObsolete;
use App\Models\Ea\Initiative;
use App\Models\Ea\TechComponent;
use App\Models\Ea\TechVulnerability;
use Illuminate\Support\Carbon;

/**
 * TechObsolescenceService — daily recompute of the `obsolescence_flag` and the
 * derived `tech_debt_score` on `ea_tech_components_ext`.
 *
 * Score formula:
 *   eol_factor      = max(0, 100 - (days_to_eol / 3.65))    (≈ 0..100 over 10y)
 *   cve_factor      = min(100, 8 * critical + 4 * high + cvss_top * 1.5)
 *   support_factor  = 50 if eol_passed else 0
 *
 *   tech_debt_score = clamp((eol_factor + cve_factor + support_factor) / 3, 0, 100)
 *
 * obsolescence_flag := tech_debt_score >= 60 OR eol_date <= today + 12m
 */
class TechObsolescenceService
{
    public function recompute(): array
    {
        $touched = 0; $flagged = 0;
        $components = TechComponent::all();
        foreach ($components as $t) {
            $eolFactor = $this->eolFactor($t->eol_date);
            $cveFactor = $this->cveFactor($t);
            $supportFactor = $t->eol_date && $t->eol_date->lt(now()) ? 50 : 0;
            $score = round(max(0, min(100, ($eolFactor + $cveFactor + $supportFactor) / 3)), 2);
            $flag = ($score >= 60) || ($t->eol_date && $t->eol_date->lte(now()->addYear()));
            $wasFlagged = (bool) $t->obsolescence_flag;
            $t->tech_debt_score = $score;
            $t->obsolescence_flag = $flag;
            $t->save();
            if ($flag) $flagged++;
            $touched++;

            // Contract I-1 (§7.3). Fired on every evaluation, not only on the
            // transition, so the risk register stays in step when the score
            // moves or the component is remediated — OpenObsolescenceRisk is
            // idempotent and handles both directions.
            if ($flag || $wasFlagged) {
                TechComponentBecameObsolete::dispatch($t, [
                    'tech_debt_score' => $score,
                    'newly_flagged' => $flag && ! $wasFlagged,
                    'has_replacement_initiative' => $this->hasReplacementInitiative($t),
                ]);
            }
        }
        return ['touched' => $touched, 'flagged' => $flagged];
    }

    /**
     * §7.3 qualifies I-1 as firing for a component "past EOL threshold **with
     * no replacement initiative**". An estate mid-migration should not have its
     * risk register filled with findings the programme is already addressing.
     */
    private function hasReplacementInitiative(TechComponent $component): bool
    {
        return Initiative::withoutGlobalScopes()
            ->whereIn('status', ['approved', 'in_flight'])
            ->where(function ($q) use ($component) {
                $q->where('name', 'like', '%'.$component->name.'%')
                  ->orWhere('description', 'like', '%'.$component->name.'%');
            })
            ->exists();
    }

    private function eolFactor(?Carbon $eol): float
    {
        if (! $eol) return 0;
        $days = now()->diffInDays($eol, false); // negative if past
        if ($days < 0) return 100;
        if ($days > 3650) return 0;
        return round(100 - ($days / 36.5), 2);
    }

    private function cveFactor(TechComponent $t): float
    {
        $critical = TechVulnerability::where('component_id', $t->id)->where('status', 'open')->where('severity', 'critical')->count();
        $high = TechVulnerability::where('component_id', $t->id)->where('status', 'open')->where('severity', 'high')->count();
        $top = TechVulnerability::where('component_id', $t->id)->where('status', 'open')->max('cvss') ?: 0;
        return min(100, 8 * $critical + 4 * $high + (float) $top * 1.5);
    }
}
