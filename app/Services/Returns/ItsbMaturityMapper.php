<?php

namespace App\Services\Returns;

use App\Models\Ea\Initiative;
use App\Models\Ea\MaturityAssessment;
use App\Models\Ea\MaturityDomain;
use App\Models\Ea\MaturityResponse;
use App\Models\LegalEntity;
use App\Services\Ea\ScoreCardService;

/**
 * ItsbMaturityMapper — the CBN IT Standards Blueprint architecture maturity
 * assessment (A1).
 *
 * §6.1 Finding 1: the ITSB v2.1 (July 2019) "adopts **TOGAF version 9.2** as
 * the mandated enterprise architecture standard, applying to all Financial
 * Service Industries **and their external managed service providers**. It
 * requires all four TOGAF ADM domains — Business, Application, Information and
 * Technology Architecture. It sets **target maturity by institution
 * category**":
 *
 *   Category One — international commercial banks; established commercial and
 *                  merchant banks → **Level 3, Defined**
 *   Category Two — banks operating ≤18 months; payment system providers
 *                  → **Level 2, Repeatable**
 *
 * "Maturity is scored on a COBIT-style 0–5 scale, and institutions must *submit
 * to a formal assessment by the IT Standards Council*."
 *
 * §6.3 A1: the output is "a Governance Council-ready pack" with "the gap and
 * the remediation initiatives auto-derived".
 *
 * ⚠️ §12.1 R1: "ITSB enforcement is dormant. If the IT Standards Governance
 * Council is not actively assessing architecture maturity, the strongest
 * narrative weakens." The mapper is built regardless — the assessment is
 * useful to a bank whether or not the Council calls for it this year — but the
 * category target is read from the legal entity rather than assumed.
 */
class ItsbMaturityMapper extends ReturnMapper
{
    public static function name(): string
    {
        return 'CBN ITSB architecture maturity assessment';
    }

    public static function regulator(): string
    {
        return 'CBN IT Standards Governance Council';
    }

    public static function description(): string
    {
        return 'Scores architecture maturity 0–5 against the ITSB target for the institution '
            .'category, with the gap and remediating initiatives derived from the repository.';
    }

    public static function cadence(): string
    {
        return 'annual';
    }

    /**
     * The four TOGAF ADM domains the ITSB requires, mapped onto the Score Card
     * aspect areas that evidence them.
     */
    private const ADM_DOMAINS = [
        'business' => ['label' => 'Business Architecture', 'aspect' => 'business'],
        'information' => ['label' => 'Information (Data) Architecture', 'aspect' => 'information'],
        'application' => ['label' => 'Application Architecture', 'aspect' => 'systems'],
        'technology' => ['label' => 'Technology Architecture', 'aspect' => 'technology'],
    ];

    public function compile(?LegalEntity $entity, string $period): array
    {
        $sections = [];
        $citations = [];

        $scoreCard = (new ScoreCardService())->compute();

        $target = $entity?->itsbTargetLevel();
        $category = $entity?->itsbCategory();

        $domains = $this->admDomains($scoreCard, $target, $citations);
        $sections[] = $this->domainSection($domains, $target, $category, $entity);
        $sections[] = $this->questionnaireSection($citations, $period);
        $sections[] = $this->gapSection($domains, $target, $citations);

        $overall = (int) round(collect($domains)->avg('level') * 10) / 10;
        $completeness = (int) round(collect($sections)->avg('completeness'));

        return [
            'sections' => $sections,
            'summary' => [
                'overall_level' => $overall,
                'target_level' => $target,
                'category' => $category,
                'meets_target' => $target !== null ? $overall >= $target : null,
                'gap_levels' => $target !== null ? max(0, round($target - $overall, 1)) : null,
                'documentation_completeness' => $scoreCard['overall']['score'],
                'domains_at_target' => $target !== null
                    ? collect($domains)->filter(fn ($d) => $d['level'] >= $target)->count()
                    : 0,
                'remediation_initiatives' => Initiative::whereIn('status', ['approved', 'in_flight'])->count(),
            ],
            'citations' => $citations,
            'completeness' => $completeness,
            'evidence_confidence' => $this->confidenceFrom($citations),
        ];
    }

    /**
     * Score each ADM domain 0–5 from documentation completeness.
     *
     * The mapping is deliberately conservative: COBIT Level 3 "Defined" means
     * documented, formally trained and integrated via policy. Documentation
     * completeness evidences the first of those three, so the ceiling here is
     * Level 4 — reaching 5 requires evidence this repository cannot see, and
     * claiming it would be exactly the sort of overstatement BOFIA 2020 treats
     * as a breach.
     */
    private function admDomains(array $scoreCard, ?int $target, array &$citations): array
    {
        $domains = [];

        foreach (self::ADM_DOMAINS as $key => $definition) {
            $score = $scoreCard['overall']['by_aspect'][$definition['aspect']] ?? 0;

            $level = match (true) {
                $score >= 88 => 4,
                $score >= 70 => 3,
                $score >= 45 => 2,
                $score >= 20 => 1,
                default => 0,
            };

            $cells = collect($scoreCard['cells'])
                ->filter(fn ($c) => $c['aspect'] === $definition['aspect']);

            foreach ($cells->where('route', '!=', null)->take(6) as $cell) {
                $citations[] = [
                    'question_ref' => 'ITSB-'.strtoupper($key),
                    'entity_type' => '',
                    'entity_id' => 0,
                    'label' => $cell['hint'],
                    'note' => "{$cell['count']} record(s), score {$cell['score']}",
                ];
            }

            $domains[$key] = [
                'domain' => $key,
                'label' => $definition['label'],
                'documentation_score' => $score,
                'level' => $level,
                'level_label' => $this->levelLabel($level),
                'target' => $target,
                'meets_target' => $target !== null ? $level >= $target : null,
                'weakest_cells' => $cells->sortBy('score')->take(3)
                    ->map(fn ($c) => ['hint' => $c['hint'], 'score' => $c['score'], 'state' => $c['state']])
                    ->values()->all(),
            ];
        }

        return $domains;
    }

    private function levelLabel(int $level): string
    {
        return [
            0 => 'Non-existent',
            1 => 'Initial / Ad hoc',
            2 => 'Repeatable but intuitive',
            3 => 'Defined',
            4 => 'Managed and measurable',
            5 => 'Optimised',
        ][$level] ?? 'Unknown';
    }

    private function domainSection(array $domains, ?int $target, ?string $category, ?LegalEntity $entity): array
    {
        $atTarget = $target !== null
            ? collect($domains)->filter(fn ($d) => $d['level'] >= $target)->count()
            : 0;

        return $this->section(
            'ITSB-ADM',
            'Architecture maturity by TOGAF ADM domain',
            'ITSB v2.1 adopts TOGAF 9.2 and requires all four ADM domains — Business, Application, '
                .'Information and Technology Architecture — assessed on a COBIT-style 0–5 scale.',
            [
                'institution' => $entity?->name,
                'licence_class' => $entity?->licence_class,
                'itsb_category' => $category ? ('Category '.ucfirst($category)) : null,
                'target_level' => $target,
                'domains' => array_values($domains),
                'domains_at_target' => $atTarget,
                'domains_total' => count($domains),
            ],
            $target !== null ? (int) round($atTarget / max(1, count($domains)) * 100) : 50,
            $entity === null
                ? 'No legal entity was selected, so the ITSB category target could not be determined. '
                    .'Category One requires Level 3; Category Two requires Level 2.'
                : ($category === null
                    ? 'The licence class on this entity does not map to an ITSB category, so no target '
                        .'level has been applied.'
                    : null),
        );
    }

    /** Responses to the existing CBN maturity questionnaire, where one exists. */
    private function questionnaireSection(array &$citations, string $period): array
    {
        $assessment = MaturityAssessment::orderByDesc('year')->first();
        $domains = MaturityDomain::with('questions')->get();

        if (! $assessment) {
            return $this->section(
                'ITSB-QUESTIONNAIRE',
                'ITSB maturity questionnaire responses',
                'Institutions must submit to a formal assessment by the IT Standards Governance Council.',
                ['assessment' => null, 'answered' => 0, 'total' => $domains->sum(fn ($d) => $d->questions->count())],
                0,
                'No maturity self-assessment has been completed. The computed domain levels above are '
                    .'derived from repository completeness alone and are not a substitute for the questionnaire.',
            );
        }

        $responses = MaturityResponse::where('assessment_id', $assessment->id)->get()->keyBy('question_id');
        $total = $domains->sum(fn ($d) => $d->questions->count());
        $answered = $responses->count();

        $citations[] = [
            'question_ref' => 'ITSB-QUESTIONNAIRE',
            'entity_type' => MaturityAssessment::class,
            'entity_id' => $assessment->id,
            'label' => "Maturity assessment {$assessment->year}",
        ];

        return $this->section(
            'ITSB-QUESTIONNAIRE',
            'ITSB maturity questionnaire responses',
            'Institutions must submit to a formal assessment by the IT Standards Governance Council.',
            [
                'assessment_year' => $assessment->year,
                'framework' => $assessment->framework,
                'overall_score' => $assessment->overall_score,
                'answered' => $answered,
                'total' => $total,
                'by_domain' => $domains->map(function ($domain) use ($responses) {
                    $answers = $domain->questions->map(fn ($q) => $responses->get($q->id))->filter();

                    return [
                        'domain' => $domain->name ?? $domain->code,
                        'answered' => $answers->count(),
                        'total' => $domain->questions->count(),
                        'average_level' => $answers->count()
                            ? round($answers->avg('selected_level'), 2)
                            : null,
                    ];
                })->values(),
            ],
            $total ? (int) round($answered / $total * 100) : 0,
            $answered < $total
                ? ($total - $answered).' question(s) remain unanswered.'
                : null,
        );
    }

    /**
     * §6.3 A1: "with the gap and the remediation initiatives auto-derived."
     */
    private function gapSection(array $domains, ?int $target, array &$citations): array
    {
        $gaps = collect($domains)
            ->filter(fn ($d) => $target !== null && $d['level'] < $target)
            ->map(fn ($d) => [
                'domain' => $d['label'],
                'current_level' => $d['level'],
                'target_level' => $target,
                'gap' => $target - $d['level'],
                'weakest_areas' => collect($d['weakest_cells'])->pluck('hint')->all(),
            ])
            ->values();

        $initiatives = Initiative::whereIn('status', ['approved', 'in_flight'])
            ->orderBy('target_end_date')
            ->get(['id', 'code', 'name', 'status', 'adm_phase', 'target_end_date']);

        foreach ($initiatives->take(50) as $initiative) {
            $citations[] = [
                'question_ref' => 'ITSB-GAP',
                'entity_type' => Initiative::class,
                'entity_id' => $initiative->id,
                'label' => "{$initiative->code} — {$initiative->name}",
            ];
        }

        return $this->section(
            'ITSB-GAP',
            'Gap analysis and remediation roadmap',
            'The assessment must show the gap to the category target and the plan to close it.',
            [
                'gaps' => $gaps->all(),
                'domains_below_target' => $gaps->count(),
                'remediation_initiatives' => $initiatives->map(fn ($i) => [
                    'code' => $i->code,
                    'name' => $i->name,
                    'status' => $i->status,
                    'adm_phase' => $i->adm_phase,
                    'target_end' => optional($i->target_end_date)->toDateString(),
                ])->values(),
            ],
            $gaps->isEmpty() ? 100 : ($initiatives->isNotEmpty() ? 70 : 30),
            $gaps->isNotEmpty() && $initiatives->isEmpty()
                ? 'There are maturity gaps but no approved or in-flight initiative addressing them. '
                    .'The Council will ask what the plan is.'
                : null,
        );
    }
}
