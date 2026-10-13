<?php

namespace App\Services\Change;

use App\Models\Asset;
use App\Models\Change\ChangeRequest;

/**
 * Risk of a change = impact × likelihood (1–25), banded low / medium / high / critical, with
 * automatic uplifts that are recorded as factors (ATH-CHG-001 §3.2):
 * - an outage adds one likelihood step;
 * - a critical affected asset, or the core_banking category, raises the level to at least high;
 * - an emergency change is never below medium.
 */
class ChangeRiskScorer
{
    public const RANK = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];

    public static function levelFor(int $score): string
    {
        return match (true) {
            $score >= 16 => 'critical',
            $score >= 10 => 'high',
            $score >= 5 => 'medium',
            default => 'low',
        };
    }

    public static function rank(string $level): int
    {
        return self::RANK[$level] ?? 0;
    }

    /** @return array{score:int, level:string, factors:array<int, string>} */
    public function scoreFor(ChangeRequest $change): array
    {
        $impact = max(1, min(5, (int) $change->impact));
        $likelihood = max(1, min(5, (int) $change->likelihood));
        $factors = [];

        if ($change->outage_required && $likelihood < 5) {
            $likelihood++;
            $factors[] = 'Outage required: likelihood raised one step.';
        }

        $score = $impact * $likelihood;
        $level = self::levelFor($score);

        if ($change->category === 'core_banking' && self::rank($level) < self::rank('high')) {
            $level = 'high';
            $factors[] = 'Core banking change: level raised to high.';
        }

        $ids = array_values(array_filter(array_map('intval', (array) $change->affected_asset_ids)));
        if ($ids && self::rank($level) < self::rank('high')) {
            $critical = Asset::withoutGlobalScopes()->where('organization_id', $change->organization_id)
                ->whereIn('id', $ids)->where('criticality', 'critical')->whereNull('deleted_at')->value('name');
            if ($critical) {
                $level = 'high';
                $factors[] = "Affects a critical asset ({$critical}): level raised to high.";
            }
        }

        if ($change->change_type === 'emergency' && self::rank($level) < self::rank('medium')) {
            $level = 'medium';
            $factors[] = 'Emergency change: level is never below medium.';
        }

        return ['score' => $score, 'level' => $level, 'factors' => $factors];
    }

    /** Scores and stores the result on the change (without saving when $persist is false). */
    public function apply(ChangeRequest $change, bool $persist = true): array
    {
        $result = $this->scoreFor($change);
        $change->risk_score = $result['score'];
        $change->risk_level = $result['level'];
        if ($persist) {
            $change->save();
        }

        return $result;
    }
}
