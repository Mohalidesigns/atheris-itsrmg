<?php

namespace App\Services;

use App\Models\Risk;
use App\Models\RiskScoreHistory;

class RiskScoringService
{
    public const LIKELIHOOD_LABELS = [
        1 => 'Rare',
        2 => 'Unlikely',
        3 => 'Possible',
        4 => 'Likely',
        5 => 'Almost Certain',
    ];

    public const IMPACT_LABELS = [
        1 => 'Insignificant',
        2 => 'Minor',
        3 => 'Moderate',
        4 => 'Major',
        5 => 'Catastrophic',
    ];

    /** Lower bound of each rating band — derived from Risk::RATINGS (single source of truth). */
    public const RATING_THRESHOLDS = [
        'critical' => Risk::RATINGS['critical']['min'],
        'high' => Risk::RATINGS['high']['min'],
        'medium' => Risk::RATINGS['medium']['min'],
        'low' => Risk::RATINGS['low']['min'],
        'very_low' => Risk::RATINGS['very_low']['min'],
    ];

    public const RATING_COLORS = [
        'critical' => Risk::RATINGS['critical']['color'],
        'high' => Risk::RATINGS['high']['color'],
        'medium' => Risk::RATINGS['medium']['color'],
        'low' => Risk::RATINGS['low']['color'],
        'very_low' => Risk::RATINGS['very_low']['color'],
    ];

    public function calculateScore(int $likelihood, int $impact): int
    {
        return $likelihood * $impact;
    }

    public function calculateRating(int $score): string
    {
        return Risk::calculateRating($score);
    }

    public function scoreRisk(Risk $risk, string $type, int $likelihood, int $impact, ?string $reason = null): Risk
    {
        $score = $this->calculateScore($likelihood, $impact);
        $rating = $this->calculateRating($score);

        $prefix = $type === 'inherent' ? 'inherent' : 'residual';

        $risk->update([
            "{$prefix}_likelihood" => $likelihood,
            "{$prefix}_impact" => $impact,
            "{$prefix}_score" => $score,
            "{$prefix}_rating" => $rating,
        ]);

        if ($type === 'inherent' && $risk->status === 'identified') {
            $risk->update(['status' => 'assessed']);
        }

        $this->recordHistory($risk, $reason ?? "{$type} score updated");

        return $risk->fresh();
    }

    /**
     * Snapshot the risk's current inherent/residual position into the score
     * history that feeds the "History" tab and trend reporting.
     */
    public function recordHistory(Risk $risk, string $reason): RiskScoreHistory
    {
        return RiskScoreHistory::create([
            'risk_id' => $risk->id,
            'organization_id' => $risk->organization_id,
            'inherent_likelihood' => $risk->inherent_likelihood,
            'inherent_impact' => $risk->inherent_impact,
            'inherent_score' => $risk->inherent_score,
            'inherent_rating' => $risk->inherent_rating,
            'residual_likelihood' => $risk->residual_likelihood,
            'residual_impact' => $risk->residual_impact,
            'residual_score' => $risk->residual_score,
            'residual_rating' => $risk->residual_rating,
            'change_reason' => $reason,
            'changed_by' => auth()->id(),
            'recorded_at' => now(),
        ]);
    }

    public function getHeatMapData(int $organizationId, string $type = 'inherent'): array
    {
        $prefix = $type;
        $risks = Risk::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull("{$prefix}_likelihood")
            ->whereNotNull("{$prefix}_impact")
            ->whereNotIn('status', Risk::INACTIVE_STATUSES)
            ->get([
                'id', 'title', 'risk_id_code',
                "{$prefix}_likelihood as likelihood",
                "{$prefix}_impact as impact",
                "{$prefix}_score as score",
                "{$prefix}_rating as rating",
            ]);

        $matrix = [];
        for ($l = 5; $l >= 1; $l--) {
            for ($i = 1; $i <= 5; $i++) {
                $cellRisks = $risks->filter(fn ($r) => $r->likelihood == $l && $r->impact == $i);
                $score = $l * $i;
                $matrix[] = [
                    'likelihood' => $l,
                    'impact' => $i,
                    'score' => $score,
                    'rating' => $this->calculateRating($score),
                    'color' => self::RATING_COLORS[$this->calculateRating($score)],
                    'count' => $cellRisks->count(),
                    'risks' => $cellRisks->map(fn ($r) => [
                        'id' => $r->id,
                        'title' => $r->title,
                        'code' => $r->risk_id_code,
                    ])->values()->toArray(),
                ];
            }
        }

        return $matrix;
    }

    public function getRiskDistribution(int $organizationId): array
    {
        $risks = Risk::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', Risk::INACTIVE_STATUSES)
            ->get();

        $distribution = [];
        foreach (array_keys(self::RATING_THRESHOLDS) as $rating) {
            $distribution[$rating] = [
                'count' => $risks->where('inherent_rating', $rating)->count(),
                'color' => self::RATING_COLORS[$rating],
                'label' => ucfirst(str_replace('_', ' ', $rating)),
            ];
        }

        return $distribution;
    }
}
