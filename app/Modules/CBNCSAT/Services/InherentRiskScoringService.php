<?php

namespace App\Modules\CBNCSAT\Services;

use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatIrCategoryScore;
use App\Modules\CBNCSAT\Models\CsatIrQuestion;
use App\Modules\CBNCSAT\Models\CsatIrResponse;

class InherentRiskScoringService
{
    public function calculateCategoryScore(int $assessmentId, int $categoryCode): array
    {
        $questionCount = CsatIrQuestion::where('category_code', $categoryCode)
            ->where('is_active', true)->count();

        $responses = CsatIrResponse::where('assessment_id', $assessmentId)
            ->whereHas('question', fn($q) => $q->where('category_code', $categoryCode)->where('is_active', true))
            ->get();

        $answeredCount = $responses->count();
        $totalScore = $responses->sum('selected_level');
        $averageScore = $answeredCount > 0 ? $totalScore / $answeredCount : 0;
        $completionPct = $questionCount > 0 ? ($answeredCount / $questionCount) * 100 : 0;

        $riskLevel = match (true) {
            $averageScore <= 1.4 => 'least',
            $averageScore <= 2.4 => 'minimal',
            $averageScore <= 3.4 => 'moderate',
            $averageScore <= 4.4 => 'significant',
            default => 'most',
        };

        return [
            'category_code' => $categoryCode,
            'total_score' => round($totalScore, 2),
            'question_count' => $questionCount,
            'answered_count' => $answeredCount,
            'average_score' => round($averageScore, 3),
            'risk_level' => $riskLevel,
            'completion_pct' => round($completionPct, 2),
        ];
    }

    public function calculateCompositeRisk(int $assessmentId): array
    {
        $categoryScores = collect(range(1, 5))
            ->map(fn($cat) => $this->calculateCategoryScore($assessmentId, $cat));

        $answeredCategories = $categoryScores->filter(fn($s) => $s['answered_count'] > 0);
        $compositeScore = $answeredCategories->count() > 0
            ? $answeredCategories->avg('average_score')
            : 0;

        $compositeLevel = match (true) {
            $compositeScore <= 1.4 => 'least',
            $compositeScore <= 2.4 => 'minimal',
            $compositeScore <= 3.4 => 'moderate',
            $compositeScore <= 4.4 => 'significant',
            default => 'most',
        };

        return [
            'score' => round($compositeScore, 3),
            'level' => $compositeLevel,
            'category_scores' => $categoryScores->toArray(),
        ];
    }

    public function recalculateAndPersist(int $assessmentId): array
    {
        $composite = $this->calculateCompositeRisk($assessmentId);

        foreach ($composite['category_scores'] as $cs) {
            CsatIrCategoryScore::updateOrCreate(
                ['assessment_id' => $assessmentId, 'category_code' => $cs['category_code']],
                [
                    'total_score' => $cs['total_score'],
                    'question_count' => $cs['question_count'],
                    'answered_count' => $cs['answered_count'],
                    'average_score' => $cs['average_score'],
                    'risk_level' => $cs['risk_level'],
                    'completion_pct' => $cs['completion_pct'],
                    'calculated_at' => now(),
                ]
            );
        }

        CsatAssessment::where('id', $assessmentId)->update([
            'composite_risk_score' => $composite['score'],
            'composite_risk_level' => $composite['level'],
        ]);

        return $composite;
    }
}
