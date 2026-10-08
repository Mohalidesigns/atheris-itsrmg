<?php

namespace App\Modules\CBNCSAT\Services;

use App\Modules\CBNCSAT\Models\CsatAiRecommendation;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic insight engine for an assessment: gap analysis against domain targets, submission
 * readiness flags, compensating-control and threat follow-ups, plus the readiness score
 * (0–100, RAG: ≥85 green, 65–84 amber, <65 red).
 *
 * Rule-based (model_used = atheris-rules-v1) — every recommendation is traceable to the answers.
 */
class CsatInsightService
{
    public const ENGINE = 'atheris-rules-v1';

    public function __construct(private CsatWorkflowService $workflow) {}

    public function readiness(CsatAssessment $assessment): array
    {
        $items = collect($this->workflow->checklist($assessment));
        $score = (int) round($items->where('done', true)->count() / max(1, $items->count()) * 100);

        return ['score' => $score, 'rag' => $score >= 85 ? 'green' : ($score >= 65 ? 'amber' : 'red')];
    }

    /** Regenerates the (non-dismissed) recommendations and the readiness score. Returns the count. */
    public function generate(CsatAssessment $assessment): int
    {
        $recs = [];
        $levels = CsatMaStatement::MATURITY_LEVELS;

        // 1. Gap analysis — domains below their target, with the "No" answers blocking the next level.
        $domains = $assessment->maScores()->where('score_type', 'domain')->get();
        foreach ($domains as $d) {
            $target = $d->target_maturity_level;
            if (! $target || $d->achieved_maturity_level >= $target) {
                continue;
            }
            $domainCode = (int) ltrim($d->scope_code, 'D');
            $blocking = $assessment->maResponses()->whereIn('response', ['no'])
                ->whereHas('statement', fn ($q) => $q->where('domain_code', $domainCode)->where('maturity_level', '<=', $target))
                ->with('statement:id,component_name,maturity_level,statement_text')->get();
            $examples = $blocking->take(3)->map(fn ($r) => '“'.str($r->statement->statement_text)->limit(90).'” ('.$r->statement->component_name.')')->implode('; ');
            $recs[] = [
                'recommendation_type' => 'gap_analysis',
                'scope_reference' => "{$d->scope_code} {$d->scope_name}",
                'recommendation_text' => sprintf('%s is at %s against a target of %s. %d declarative statement(s) at or below the target level are answered "No"%s',
                    $d->scope_name, $levels[$d->achieved_maturity_level] ?? 'Sub-Baseline', $levels[$target] ?? $target, $blocking->count(),
                    $examples ? ". Start with: {$examples}." : '.'),
                'cbn_framework_ref' => 'CBN Risk-Based Cybersecurity Framework — maturity domain '.$domainCode,
                'effort_estimate' => $blocking->count() > 15 ? 'high' : ($blocking->count() > 5 ? 'medium' : 'low'),
            ];
        }

        // 2. Readiness flags — outstanding Sheet 6 checklist items.
        foreach ($this->workflow->checklist($assessment) as $item) {
            if (! $item['done']) {
                $recs[] = [
                    'recommendation_type' => 'readiness_flag',
                    'scope_reference' => $item['label'],
                    'recommendation_text' => "Submission blocker: {$item['label']} — {$item['detail']}.",
                    'cbn_framework_ref' => 'CBN CSAT Items to Submit (Sheet 6)',
                    'effort_estimate' => in_array($item['key'], ['maturity', 'ma_narratives'], true) ? 'high' : 'low',
                ];
            }
        }

        // 3. Policy gaps — compensating controls that are weak or have no permanent-fix date.
        $weakCc = DB::table('csat_ma_compensating_controls as cc')
            ->join('csat_ma_responses as r', 'r.id', '=', 'cc.response_id')
            ->where('r.assessment_id', $assessment->id)
            ->where(fn ($q) => $q->where('cc.effectiveness_level', 'low')->orWhereNull('cc.planned_permanent_date')
                ->orWhere('cc.planned_permanent_date', '<', now()->toDateString()))
            ->count();
        if ($weakCc) {
            $recs[] = [
                'recommendation_type' => 'policy_gap',
                'scope_reference' => 'Compensating controls',
                'recommendation_text' => "{$weakCc} compensating control(s) are rated low, have no permanent-fix date, or are past it. Raise remediation issues so Yes [CC] answers become Yes.",
                'cbn_framework_ref' => 'CBN CSAT — Yes [CC] compensating control capture',
                'effort_estimate' => 'medium',
            ];
        }

        // 4. Threat enrichment — high inherent threats without documented mitigation.
        $unmitigated = $assessment->threats()->where('inherent_risk_score', '>=', 6)
            ->where(fn ($q) => $q->whereNull('mitigating_controls_desc')->orWhere('mitigating_controls_desc', ''))->pluck('threat_name');
        if ($unmitigated->isNotEmpty()) {
            $recs[] = [
                'recommendation_type' => 'threat_enrichment',
                'scope_reference' => 'Threat register',
                'recommendation_text' => 'High-rated threats with no mitigating controls recorded: '.$unmitigated->take(5)->implode(', ').'.',
                'cbn_framework_ref' => 'CBN CSAT Threat Register (likelihood × impact ≥ 6)',
                'effort_estimate' => 'medium',
            ];
        }

        DB::transaction(function () use ($assessment, $recs) {
            $assessment->aiRecommendations()->where('is_dismissed', false)->delete();
            foreach (array_values($recs) as $i => $rec) {
                CsatAiRecommendation::create($rec + [
                    'assessment_id' => $assessment->id,
                    'priority_rank' => min(255, $i + 1),
                    'model_used' => self::ENGINE,
                    'prompt_version' => '1',
                    'generated_at' => now(),
                    'is_dismissed' => false,
                ]);
            }
            $r = $this->readiness($assessment);
            $assessment->update(['ai_readiness_score' => $r['score'], 'ai_readiness_rag' => $r['rag']]);
        });

        return count($recs);
    }
}
