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

    public const RATING_THRESHOLDS = [
        'critical' => 20,
        'high' => 15,
        'medium' => 8,
        'low' => 4,
        'very_low' => 1,
    ];

    public const RATING_COLORS = [
        'critical' => '#C53030',
        'high' => '#DD6B20',
        'medium' => '#D4AF37',
        'low' => '#2D7D46',
        'very_low' => '#319795',
    ];

    public function calculateScore(int $likelihood, int $impact): int
    {
        return $likelihood * $impact;
    }

    public function calculateRating(int $score): string
    {
        foreach (self::RATING_THRESHOLDS as $rating => $threshold) {
            if ($score >= $threshold) {
                return $rating;
            }
        }
        return 'very_low';
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

        // Record history
        RiskScoreHistory::create([
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
            'change_reason' => $reason ?? "{$type} score updated",
            'changed_by' => auth()->id(),
            'recorded_at' => now(),
        ]);

        return $risk->fresh();
    }

    public function getHeatMapData(int $organizationId, string $type = 'inherent'): array
    {
        $prefix = $type;
        $risks = Risk::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNotNull("{$prefix}_likelihood")
            ->whereNotNull("{$prefix}_impact")
            ->whereNotIn('status', ['closed', 'archived'])
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
            ->whereNotIn('status', ['closed', 'archived'])
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
