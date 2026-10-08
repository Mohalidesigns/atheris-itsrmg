<?php

namespace App\Modules\CBNCSAT\Services;

use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatMaResponse;
use App\Modules\CBNCSAT\Models\CsatMaScore;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use Illuminate\Support\Collection;

/**
 * CBN-CSAT maturity scoring (BRD BR-MA-05):
 *  (a) component maturity = highest level L such that every statement at levels 1..L is Yes / Yes[CC];
 *  (b) assessment-factor maturity = average of its components' levels;
 *  (c) domain maturity = lowest factor maturity in the domain (whole levels);
 *  (d) per-level fractional score = (Yes + Yes[CC]) / applicable statements.
 *
 * N/A marks a statement as not applicable: it is removed from the population rather than
 * counted as a failure (the spec gives N/A as a valid answer but no failure semantics).
 * Unanswered statements count against the level, so an incomplete component cannot attain it.
 */
class MaturityScoringService
{
    private const LEVEL_COLUMNS = [1 => 'baseline_score', 2 => 'evolving_score', 3 => 'intermediate_score', 4 => 'advanced_score', 5 => 'innovative_score'];

    public const LEVEL_KEYS = [0 => 'sub_baseline', 1 => 'baseline', 2 => 'evolving', 3 => 'intermediate', 4 => 'advanced', 5 => 'innovative'];

    /** @var Collection<int, CsatMaStatement>|null */
    private ?Collection $statements = null;

    private function statements(): Collection
    {
        return $this->statements ??= CsatMaStatement::where('is_active', true)
            ->get(['id', 'domain_code', 'domain_name', 'factor_code', 'factor_name', 'component_code', 'component_name', 'maturity_level']);
    }

    /** @return Collection<int, string> statement_id => response */
    private function responses(int $assessmentId): Collection
    {
        return CsatMaResponse::where('assessment_id', $assessmentId)
            ->whereNotNull('response')
            ->pluck('response', 'statement_id');
    }

    public function scoreComponent(Collection $statements, Collection $responses): array
    {
        $achieved = 0;
        $broken = false;
        $levelScores = [];

        foreach (range(1, 5) as $level) {
            $atLevel = $statements->where('maturity_level', $level);
            $applicable = $atLevel->reject(fn ($s) => ($responses[$s->id] ?? null) === 'na');
            $met = $applicable->filter(fn ($s) => in_array($responses[$s->id] ?? null, ['yes', 'yes_cc'], true))->count();

            // A level with no applicable statements is trivially met.
            $fraction = $applicable->count() > 0 ? $met / $applicable->count() : 1.0;
            $levelScores[$level] = round($fraction, 4);

            if (! $broken && $fraction >= 1.0) {
                $achieved = $level;
            } else {
                $broken = true;
            }
        }

        $answered = $statements->filter(fn ($s) => isset($responses[$s->id]))->count();

        return [
            'achieved_maturity_level' => $achieved,
            'level_scores' => $levelScores,
            'completion_pct' => $statements->count() ? round($answered / $statements->count() * 100, 2) : 0,
        ];
    }

    /** Full hierarchy for an assessment without persisting. */
    public function calculate(int $assessmentId): array
    {
        $responses = $this->responses($assessmentId);
        $domains = [];

        foreach ($this->statements()->groupBy('domain_code')->sortKeys() as $domainCode => $domainStatements) {
            $factors = [];
            foreach ($domainStatements->groupBy('factor_code') as $factorCode => $factorStatements) {
                $components = [];
                foreach ($factorStatements->groupBy('component_code') as $componentCode => $componentStatements) {
                    $components[$componentCode] = ['code' => $componentCode, 'name' => $componentStatements->first()->component_name]
                        + $this->scoreComponent($componentStatements, $responses);
                }
                $c = collect($components);
                $average = $c->avg('achieved_maturity_level');
                $factors[$factorCode] = [
                    'code' => $factorCode,
                    'name' => $factorStatements->first()->factor_name,
                    'average_level' => round($average, 2),
                    'achieved_maturity_level' => (int) floor($average),
                    'level_scores' => collect(range(1, 5))->mapWithKeys(fn ($l) => [$l => round($c->avg(fn ($x) => $x['level_scores'][$l]), 4)])->all(),
                    'completion_pct' => round($c->avg('completion_pct'), 2),
                    'components' => $components,
                ];
            }
            $f = collect($factors);
            $domains[(int) $domainCode] = [
                'code' => 'D'.$domainCode,
                'name' => CsatMaStatement::DOMAIN_NAMES[$domainCode] ?? $domainStatements->first()->domain_name,
                'achieved_maturity_level' => (int) $f->min('achieved_maturity_level'),
                'level_scores' => collect(range(1, 5))->mapWithKeys(fn ($l) => [$l => round($f->avg(fn ($x) => $x['level_scores'][$l]), 4)])->all(),
                'completion_pct' => round($domainStatements->filter(fn ($s) => isset($responses[$s->id]))->count() / max(1, $domainStatements->count()) * 100, 2),
                'factors' => $factors,
            ];
        }

        return $domains;
    }

    public function recalculateAndPersist(int $assessmentId): array
    {
        $domains = $this->calculate($assessmentId);
        $now = now();

        $persist = function (string $type, string $code, string $name, array $row) use ($assessmentId, $now) {
            $values = [
                'scope_name' => $name,
                'achieved_maturity_level' => $row['achieved_maturity_level'],
                'completion_pct' => $row['completion_pct'],
                'calculated_at' => $now,
            ];
            foreach (self::LEVEL_COLUMNS as $level => $column) {
                $values[$column] = $row['level_scores'][$level] ?? 0;
            }
            // Targets live on the same row and are set separately — never overwritten here.
            CsatMaScore::updateOrCreate(['assessment_id' => $assessmentId, 'score_type' => $type, 'scope_code' => $code], $values);
        };

        foreach ($domains as $domain) {
            $persist('domain', $domain['code'], $domain['name'], $domain);
            foreach ($domain['factors'] as $factor) {
                $persist('factor', $factor['code'], $factor['name'], $factor);
                foreach ($factor['components'] as $component) {
                    $persist('component', $component['code'], $component['name'], $component);
                }
            }
        }

        $overall = collect($domains)->min('achieved_maturity_level') ?? 0;
        CsatAssessment::withoutGlobalScopes()->where('id', $assessmentId)
            ->update(['overall_maturity_level' => self::LEVEL_KEYS[$overall]]);

        return $domains;
    }
}
