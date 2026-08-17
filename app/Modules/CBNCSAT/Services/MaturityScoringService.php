<?php

namespace App\Modules\CBNCSAT\Services;

use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatMaResponse;
use App\Modules\CBNCSAT\Models\CsatMaScore;
use App\Modules\CBNCSAT\Models\CsatMaStatement;

class MaturityScoringService
{
    public function calculateComponentMaturity(int $assessmentId, string $componentCode): array
    {
        $achievedLevel = 0;
        $levelScores = [];

        foreach (range(1, 5) as $level) {
            $statements = CsatMaStatement::where('component_code', $componentCode)
                ->where('maturity_level', $level)
                ->where('is_active', true)
                ->get();

            if ($statements->isEmpty()) {
                $levelScores[$level] = 1.0;
                continue;
            }

            $responses = CsatMaResponse::where('assessment_id', $assessmentId)
                ->whereIn('statement_id', $statements->pluck('id'))
                ->get()
                ->keyBy('statement_id');

            $total = $statements->count();
            $yesCount = $responses->filter(fn($r) => in_array($r->response, ['yes', 'yes_cc']))->count();
            $fraction = $total > 0 ? $yesCount / $total : 0;
            $levelScores[$level] = round($fraction, 4);

            if ($fraction < 1.0) break;
            $achievedLevel = $level;
        }

        $totalStatements = CsatMaStatement::where('component_code', $componentCode)->where('is_active', true)->count();
        $answeredCount = CsatMaResponse::where('assessment_id', $assessmentId)
            ->whereIn('statement_id', CsatMaStatement::where('component_code', $componentCode)->where('is_active', true)->pluck('id'))
            ->whereNotNull('response')
            ->count();
        $completionPct = $totalStatements > 0 ? ($answeredCount / $totalStatements) * 100 : 0;

        return [
            'component_code' => $componentCode,
            'achieved_maturity_level' => $achievedLevel,
            'completion_pct' => round($completionPct, 2),
            'level_scores' => $levelScores,
        ];
    }

    public function calculateFactorMaturity(int $assessmentId, string $factorCode): array
    {
        $components = CsatMaStatement::where('factor_code', $factorCode)
            ->where('is_active', true)
            ->distinct('component_code')
            ->pluck('component_code');

        $componentScores = $components->map(fn($code) => $this->calculateComponentMaturity($assessmentId, $code));

        $factorLevel = $componentScores->count() > 0
            ? $componentScores->min('achieved_maturity_level')
            : 0;

        return [
            'factor_code' => $factorCode,
            'achieved_maturity_level' => $factorLevel,
            'component_scores' => $componentScores->toArray(),
        ];
    }

    public function calculateDomainMaturity(int $assessmentId, int $domainCode): array
    {
        $components = CsatMaStatement::where('domain_code', $domainCode)
            ->where('is_active', true)
            ->distinct('component_code')
            ->pluck('component_code');

        $componentScores = $components->map(fn($code) => $this->calculateComponentMaturity($assessmentId, $code));
        $domainLevel = $componentScores->count() > 0 ? $componentScores->min('achieved_maturity_level') : 0;

        return [
            'domain_code' => $domainCode,
            'achieved_maturity_level' => $domainLevel,
            'component_scores' => $componentScores->toArray(),
        ];
    }

    public function recalculateAndPersist(int $assessmentId): array
    {
        $domainScores = [];

        foreach (range(1, 5) as $domainCode) {
            $domain = $this->calculateDomainMaturity($assessmentId, $domainCode);
            $domainScores[] = $domain;

            $domainName = CsatMaStatement::DOMAIN_NAMES[$domainCode] ?? "Domain {$domainCode}";

            // Persist component scores
            foreach ($domain['component_scores'] as $cs) {
                $componentName = CsatMaStatement::where('component_code', $cs['component_code'])->value('component_name') ?? $cs['component_code'];
                CsatMaScore::updateOrCreate(
                    ['assessment_id' => $assessmentId, 'score_type' => 'component', 'scope_code' => $cs['component_code']],
                    [
                        'scope_name' => $componentName,
                        'baseline_score' => $cs['level_scores'][1] ?? 0,
                        'evolving_score' => $cs['level_scores'][2] ?? 0,
                        'intermediate_score' => $cs['level_scores'][3] ?? 0,
                        'advanced_score' => $cs['level_scores'][4] ?? 0,
                        'innovative_score' => $cs['level_scores'][5] ?? 0,
                        'achieved_maturity_level' => $cs['achieved_maturity_level'],
                        'completion_pct' => $cs['completion_pct'],
                        'calculated_at' => now(),
                    ]
                );
            }

            // Persist domain score
            CsatMaScore::updateOrCreate(
                ['assessment_id' => $assessmentId, 'score_type' => 'domain', 'scope_code' => "D{$domainCode}"],
                [
                    'scope_name' => $domainName,
                    'achieved_maturity_level' => $domain['achieved_maturity_level'],
                    'completion_pct' => collect($domain['component_scores'])->avg('completion_pct') ?? 0,
                    'calculated_at' => now(),
                ]
            );
        }

        // Overall maturity = lowest domain
        $overallLevel = collect($domainScores)->min('achieved_maturity_level');
        $levelMap = [0 => 'sub_baseline', 1 => 'baseline', 2 => 'evolving', 3 => 'intermediate', 4 => 'advanced', 5 => 'innovative'];

        CsatAssessment::where('id', $assessmentId)->update([
            'overall_maturity_level' => $levelMap[$overallLevel] ?? 'sub_baseline',
        ]);

        return $domainScores;
    }
}
