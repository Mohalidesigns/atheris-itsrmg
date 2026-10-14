<?php

namespace App\Services\Returns;

use App\Models\Ea\Initiative;
use App\Models\Ea\MaturityAssessment;
use App\Models\Ea\MaturityQuestion;
use App\Models\LegalEntity;
use App\Services\Ea\Maturity\DomainEvidence;
use App\Services\Ea\Maturity\MaturityAssessmentService;
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
     * The CBN IT blueprint's five EA domains (ATH-VAL-015): the four TOGAF ADM domains the ITSB
     * requires, plus Security Architecture & Assurance. Keys are the maturity framework's codes.
     */
    private const DOMAINS = [
        'BA' => 'Business Architecture',
        'AA' => 'Application Architecture',
        'DA' => 'Information (Data) Architecture',
        'TA' => 'Technology Architecture',
        'SA' => 'Security Architecture & Assurance',
    ];

    public function compile(?LegalEntity $entity, string $period): array
    {
        $sections = [];
        $citations = [];

        $scoreCard = (new ScoreCardService)->compute();
        $orgId = (int) ($entity?->organization_id ?? auth()->user()?->organization_id);
        $approved = MaturityAssessment::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where('framework', MaturityAssessmentService::FRAMEWORK)->where('status', 'approved')->orderByDesc('approved_at')->first();

        $target = $entity?->itsbTargetLevel();
        $category = $entity?->itsbCategory();

        $domains = $this->admDomains($orgId, $approved, $target, $citations);
        $sections[] = $this->domainSection($domains, $target, $category, $entity);
        $sections[] = $this->questionnaireSection($approved, $citations);
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
     * Score each blueprint domain 0-5.
     *
     * Where the bank has an approved CBN EA maturity assessment, its CMMI staged rating per domain
     * is the reported level: it was rated practice by practice, with evidence, and approved by a
     * second person. Otherwise the level is what the repository's documentation supports, which is
     * deliberately capped at 4: COBIT/CMMI Level 5 "Optimised" needs evidence a repository cannot
     * see, and claiming it would be exactly the sort of overstatement BOFIA 2020 treats as a breach.
     * Both readings are reported so the Council sees the self-rating beside the documentation.
     */
    private function admDomains(int $orgId, ?MaturityAssessment $approved, ?int $target, array &$citations): array
    {
        $evidence = app(DomainEvidence::class);
        $documentation = $evidence->domains($orgId);
        $practices = $evidence->practices($orgId);
        $rated = $approved?->results['domains'] ?? [];
        $domains = [];

        foreach (self::DOMAINS as $code => $label) {
            $docLevel = $documentation[$code]['level'] ?? 0;
            $ratedLevel = $rated[$code]['level'] ?? null;
            $level = $ratedLevel ?? $docLevel;
            $domainPractices = collect($practices)->filter(fn ($p, $k) => str_starts_with($k, $code.'-'));

            foreach ($domainPractices->flatMap(fn ($p) => $p['indicators'])->filter(fn ($i) => $i['route'])->take(4) as $indicator) {
                $citations[] = [
                    'question_ref' => 'ITSB-'.$code,
                    'entity_type' => '',
                    'entity_id' => 0,
                    'label' => $indicator['label'],
                    'note' => $indicator['value'].', score '.$indicator['score'],
                ];
            }

            $domains[$code] = [
                'domain' => $code,
                'label' => $label,
                'documentation_score' => $documentation[$code]['score'] ?? 0,
                'documentation_level' => $docLevel,
                'assessed_level' => $ratedLevel,
                'level' => $level,
                'basis' => $ratedLevel !== null ? 'approved assessment' : 'documentation',
                'level_label' => $this->levelLabel($level),
                'target' => $target,
                'meets_target' => $target !== null ? $level >= $target : null,
                'weakest_cells' => $domainPractices->sortBy('score')->take(3)
                    ->map(fn ($p, $k) => ['hint' => $k, 'score' => $p['score'], 'state' => $p['score'] >= 65 ? 'ok' : 'weak'])
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
            'Architecture maturity by CBN blueprint domain',
            'ITSB v2.1 adopts TOGAF 9.2 and requires the ADM domains — Business, Application, Information '
                .'and Technology Architecture; the CBN blueprint adds Security Architecture & Assurance. Scored 0–5.',
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

    /** The approved CBN EA maturity assessment (CMMI, five blueprint domains), where one exists. */
    private function questionnaireSection(?MaturityAssessment $approved, array &$citations): array
    {
        $total = MaturityQuestion::whereHas('domain', fn ($q) => $q->where('framework', MaturityAssessmentService::FRAMEWORK))->count();

        if (! $approved) {
            return $this->section(
                'ITSB-QUESTIONNAIRE',
                'ITSB maturity self-assessment',
                'Institutions must submit to a formal assessment by the IT Standards Governance Council.',
                ['assessment' => null, 'answered' => 0, 'total' => $total],
                0,
                'No maturity self-assessment has been approved. The domain levels above are derived from '
                    .'repository documentation alone and are not a substitute for the assessment.',
            );
        }

        $results = $approved->results ?? [];
        $citations[] = [
            'question_ref' => 'ITSB-QUESTIONNAIRE',
            'entity_type' => MaturityAssessment::class,
            'entity_id' => $approved->id,
            'label' => "Approved maturity assessment {$approved->year}",
        ];

        return $this->section(
            'ITSB-QUESTIONNAIRE',
            'ITSB maturity self-assessment',
            'Institutions must submit to a formal assessment by the IT Standards Governance Council.',
            [
                'assessment_year' => $approved->year,
                'framework' => $approved->framework,
                'overall_score' => $approved->overall_score,
                'approved_at' => optional($approved->approved_at)->toDateString(),
                'answered' => $results['answered'] ?? 0,
                'total' => $total,
                'by_domain' => collect($results['domains'] ?? [])->map(fn ($d) => [
                    'domain' => $d['name'],
                    'level' => $d['level'],
                    'average_level' => $d['average'],
                    'documentation_supports' => $d['indicative'],
                    'overstated' => $d['overstated'],
                ])->values(),
            ],
            $total ? (int) round(($results['answered'] ?? 0) / $total * 100) : 0,
            collect($results['domains'] ?? [])->contains('overstated', true)
                ? 'Some domains are rated two or more levels above what the repository documents; the approval note records why.'
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
