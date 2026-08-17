<?php

namespace App\Services\Ea;

use App\Models\Ea\BusinessService;
use App\Models\Ea\Capability;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\Initiative;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Plateau;
use App\Models\Ea\Principle;
use App\Models\Ea\Process;
use App\Models\Ea\QualitySeal;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ValueStream;
use App\Models\Ea\Zone;

/**
 * ScoreCardService — B5, the Architecture Completeness Score Card.
 *
 * ATH-EAR-002 §3.6 identifies this as open white space: "**none of the six
 * products ships an assessment-and-completeness layer.** Avolution ships 40
 * frameworks but no instrument that tells you how complete your architecture
 * *documentation* is. That is an open white space, and it maps almost perfectly
 * onto CBN's maturity-level requirement."
 *
 * The instrument is IFEAD's Enterprise Architecture Score Card (§3.6): a
 * traffic-light grid evaluated per cell of a 4×6 matrix —
 *
 *   four aspect areas   · Business/Organization · Information
 *                       · Information Systems   · Technology Infrastructure
 *   six abstraction levels · Contextual "Why"   · Environmental "With Who"
 *                          · Conceptual "What"  · Logical "How"
 *                          · Physical "With What" · Transformational "When"
 *
 * scored 🔴 unknown / 🟡 partially documented / 🟢 fully documented. "It
 * measures **knowledge completeness and cross-domain alignment
 * simultaneously**."
 *
 * §5.4 B5: "turns 'how complete is our architecture' from a consultant's
 * opinion into a computed number."
 *
 * Crucially the score is computed from *sealed* data, not merely present data.
 * A cell full of records nobody has confirmed is not documented architecture,
 * it is a table — which is precisely the failure §2.1 diagnosed in the module
 * to begin with.
 */
class ScoreCardService
{
    public const ASPECTS = [
        'business' => 'Business / Organization',
        'information' => 'Information',
        'systems' => 'Information Systems',
        'technology' => 'Technology Infrastructure',
    ];

    public const LEVELS = [
        'contextual' => 'Contextual — Why',
        'environmental' => 'Environmental — With Who',
        'conceptual' => 'Conceptual — What',
        'logical' => 'Logical — How',
        'physical' => 'Physical — With What',
        'transformational' => 'Transformational — When',
    ];

    /**
     * What populates each cell of the 4×6 grid.
     *
     * `expected` is the count below which a cell cannot be green however well
     * sealed it is — an architecture with two applications documented is not
     * complete, it is empty and tidy.
     *
     * @return array<string, array{model:?class-string, expected:int, hint:string}>
     */
    private function cellSources(): array
    {
        return [
            // Business / Organization
            'business.contextual' => ['model' => \App\Models\Ea\Goal::class, 'expected' => 3, 'hint' => 'Drivers and goals in the motivation layer'],
            'business.environmental' => ['model' => \App\Models\Ea\Stakeholder::class, 'expected' => 3, 'hint' => 'Stakeholders and their concerns'],
            'business.conceptual' => ['model' => Capability::class, 'expected' => 10, 'hint' => 'Business capability map'],
            'business.logical' => ['model' => Process::class, 'expected' => 10, 'hint' => 'Process inventory'],
            'business.physical' => ['model' => BusinessService::class, 'expected' => 5, 'hint' => 'Business services in operation'],
            'business.transformational' => ['model' => ValueStream::class, 'expected' => 3, 'hint' => 'Value streams'],

            // Information
            'information.contextual' => ['model' => \App\Models\Ea\Driver::class, 'expected' => 2, 'hint' => 'Information drivers and obligations'],
            'information.environmental' => ['model' => \App\Models\Ea\ConsentPurpose::class, 'expected' => 2, 'hint' => 'Processing purposes and consent basis'],
            'information.conceptual' => ['model' => InfoDomain::class, 'expected' => 4, 'hint' => 'Information domains'],
            'information.logical' => ['model' => LogicalEntity::class, 'expected' => 10, 'hint' => 'Logical data entities'],
            'information.physical' => ['model' => DataFlow::class, 'expected' => 5, 'hint' => 'Data flows between systems'],
            'information.transformational' => ['model' => \App\Models\Ea\DpiaAssessment::class, 'expected' => 1, 'hint' => 'DPIAs on changing processing'],

            // Information Systems
            'systems.contextual' => ['model' => Principle::class, 'expected' => 5, 'hint' => 'Architecture principles'],
            'systems.environmental' => ['model' => \App\Models\Ea\EaApi::class, 'expected' => 3, 'hint' => 'External APIs and partners'],
            'systems.conceptual' => ['model' => \App\Models\Ea\Pattern::class, 'expected' => 3, 'hint' => 'Reference patterns'],
            'systems.logical' => ['model' => EaApplication::class, 'expected' => 15, 'hint' => 'Application portfolio'],
            'systems.physical' => ['model' => EaInterface::class, 'expected' => 10, 'hint' => 'Connection catalogue (CBN App. II §1.1(i)–(k))'],
            'systems.transformational' => ['model' => Initiative::class, 'expected' => 3, 'hint' => 'Initiatives changing the estate'],

            // Technology Infrastructure
            'technology.contextual' => ['model' => \App\Models\Ea\Standard::class, 'expected' => 5, 'hint' => 'Technology standards'],
            'technology.environmental' => ['model' => Zone::class, 'expected' => 3, 'hint' => 'Security zones and trust boundaries'],
            'technology.conceptual' => ['model' => \App\Models\Ea\Solution::class, 'expected' => 2, 'hint' => 'Solution designs'],
            'technology.logical' => ['model' => TechComponent::class, 'expected' => 15, 'hint' => 'Technology components'],
            'technology.physical' => ['model' => \App\Models\Ea\ZoneAssignment::class, 'expected' => 5, 'hint' => 'Deployment into zones'],
            'technology.transformational' => ['model' => Plateau::class, 'expected' => 2, 'hint' => 'Baseline and target plateaux'],
        ];
    }

    /**
     * Compute the grid.
     *
     * @return array{
     *   aspects:array, levels:array, cells:array, overall:array
     * }
     */
    public function compute(): array
    {
        $sources = $this->cellSources();
        $cells = [];

        foreach (self::ASPECTS as $aspectKey => $aspectLabel) {
            foreach (self::LEVELS as $levelKey => $levelLabel) {
                $key = "{$aspectKey}.{$levelKey}";
                $source = $sources[$key] ?? null;

                $cells[$key] = $this->evaluate($key, $aspectKey, $levelKey, $source);
            }
        }

        return [
            'aspects' => self::ASPECTS,
            'levels' => self::LEVELS,
            'cells' => $cells,
            'overall' => $this->summarise($cells),
        ];
    }

    private function evaluate(string $key, string $aspect, string $level, ?array $source): array
    {
        $base = [
            'key' => $key,
            'aspect' => $aspect,
            'level' => $level,
            'hint' => $source['hint'] ?? 'Not yet mapped to a repository object',
            'count' => 0,
            'expected' => $source['expected'] ?? 0,
            'sealed' => 0,
            'state' => 'unknown',
            'score' => 0,
            'route' => null,
        ];

        $model = $source['model'] ?? null;

        if (! $model || ! class_exists($model)) {
            return $base;
        }

        try {
            $count = (int) $model::withoutGlobalScopes()->count();
        } catch (\Throwable) {
            return $base;
        }

        $base['count'] = $count;
        $base['route'] = EntityRegistry::definition($model)['route'] ?? null;

        if ($count === 0) {
            return $base;
        }

        // Sealed share — only counted for types the seal actually tracks.
        $sealed = EntityRegistry::supports($model)
            ? (int) QualitySeal::withoutGlobalScopes()
                ->where('entity_type', $model)
                ->where('state', QualitySeal::APPROVED)
                ->count()
            : null;

        $base['sealed'] = $sealed ?? 0;
        $base['tracks_seal'] = $sealed !== null;

        $expected = max(1, $base['expected']);
        $population = min(1.0, $count / $expected);

        // A cell backed by a sealable type is scored on confirmed records;
        // otherwise on population alone, capped below full marks because
        // unverifiable documentation cannot be called complete.
        if ($sealed !== null) {
            $confirmed = min(1.0, $sealed / $expected);
            $score = (int) round((0.4 * $population + 0.6 * $confirmed) * 100);
        } else {
            $score = (int) round($population * 80);
        }

        $base['score'] = $score;
        $base['state'] = match (true) {
            $score >= 70 => 'documented',      // green
            $score >= 30 => 'partial',         // amber
            default => 'unknown',              // red
        };

        return $base;
    }

    private function summarise(array $cells): array
    {
        $collection = collect($cells);

        $byAspect = [];
        foreach (array_keys(self::ASPECTS) as $aspect) {
            $rows = $collection->where('aspect', $aspect);
            $byAspect[$aspect] = (int) round($rows->avg('score'));
        }

        $byLevel = [];
        foreach (array_keys(self::LEVELS) as $level) {
            $rows = $collection->where('level', $level);
            $byLevel[$level] = (int) round($rows->avg('score'));
        }

        return [
            'score' => (int) round($collection->avg('score')),
            'documented' => $collection->where('state', 'documented')->count(),
            'partial' => $collection->where('state', 'partial')->count(),
            'unknown' => $collection->where('state', 'unknown')->count(),
            'total_cells' => $collection->count(),
            'by_aspect' => $byAspect,
            'by_level' => $byLevel,
            // The cross-domain alignment reading IFEAD's instrument is really
            // for: a wide spread between aspects means one domain is documented
            // and the others are not, which is worse than an even middling
            // score because the gaps hide in the average.
            'alignment_gap' => max($byAspect) - min($byAspect),
            'weakest_aspect' => array_search(min($byAspect), $byAspect, true),
            'weakest_level' => array_search(min($byLevel), $byLevel, true),
        ];
    }

    /**
     * The CBN ITSB maturity band this completeness implies.
     *
     * §6.1 Finding 1: the ITSB sets target maturity by institution category —
     * Level 3 "Defined" for Category One (international commercial banks;
     * established commercial and merchant banks), Level 2 "Repeatable" for
     * Category Two (banks under 18 months; payment system providers), scored on
     * a COBIT-style 0–5 scale.
     *
     * This is an *indicative* mapping from documentation completeness, not the
     * assessment itself — the ITSB questionnaire covers process and governance
     * as well. Labelled as indicative wherever it is shown.
     */
    public function indicativeMaturity(int $score): array
    {
        [$level, $label] = match (true) {
            $score >= 90 => [5, 'Optimised'],
            $score >= 75 => [4, 'Managed'],
            $score >= 55 => [3, 'Defined'],
            $score >= 35 => [2, 'Repeatable'],
            $score >= 15 => [1, 'Initial'],
            default => [0, 'Non-existent'],
        };

        return [
            'level' => $level,
            'label' => $label,
            'meets_category_one' => $level >= 3,
            'meets_category_two' => $level >= 2,
        ];
    }
}
