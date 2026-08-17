<?php

namespace App\Services\Ea;

/**
 * DiagramLayout — auto-layout for the diagram canvas (WS 4.1 / B13).
 *
 * B13's checklist is "drag-drop palette, click-drag edges with ArchiMate
 * validation, **auto-layout**, versioning, export". Auto-layout is the item that
 * decides whether the editor is usable on a real estate: a 60-element
 * application-cooperation view laid out by hand takes an afternoon, and nobody
 * does it twice.
 *
 * Four algorithms, all deterministic (same input → same coordinates, which
 * matters because a version snapshot should not churn):
 *
 *  · `layered`      — ArchiMate's own idiom. Rows by layer: motivation,
 *                     strategy, business, application, technology. This is the
 *                     layout an architect expects from a "layered viewpoint".
 *  · `hierarchical` — Sugiyama-style ranking by edge direction, for realisation
 *                     and composition trees where depth carries meaning.
 *  · `grid`         — stable fallback; no crossing minimisation, no surprises.
 *  · `circular`     — for cooperation views where every node talks to every
 *                     other and a hierarchy would be a lie.
 *
 * No force-directed option on purpose: it is non-deterministic, needs many
 * iterations to settle, and on a 3G branch link (§10) the client cannot afford
 * to run it.
 */
class DiagramLayout
{
    public const ALGORITHMS = ['layered', 'hierarchical', 'grid', 'circular', 'manual'];

    /** Canvas geometry, in the same units React Flow uses. */
    private const NODE_W = 180;
    private const NODE_H = 64;
    private const GAP_X = 60;
    private const GAP_Y = 110;
    private const ORIGIN_X = 60;
    private const ORIGIN_Y = 60;

    /**
     * ArchiMate layer bands, top to bottom. The order is the standard's own
     * stacking: motivation above strategy above business above application
     * above technology.
     */
    public const LAYERS = [
        'motivation' => ['stakeholder', 'driver', 'assessment', 'goal', 'outcome', 'principle', 'requirement', 'constraint'],
        'strategy' => ['capability', 'value-stream', 'resource', 'course-of-action'],
        'business' => ['business-actor', 'business-role', 'business-process', 'business-function', 'business-service', 'business-object', 'business-event'],
        'application' => ['application-component', 'application-collaboration', 'application-interface', 'application-service', 'application-function', 'application-process', 'data-object'],
        'technology' => ['node', 'device', 'system-software', 'technology-interface', 'technology-service', 'artifact'],
        // Physical is its own band, below technology: a data centre sits under
        // the infrastructure it houses. §6.3's A5 site model is physical-layer
        // architecture and reads wrongly when mixed into the business row.
        'physical' => ['facility', 'equipment', 'distribution-network', 'material', 'location'],
        'migration' => ['work-package', 'deliverable', 'implementation-event', 'plateau', 'gap'],
    ];

    /**
     * Lay out elements, returning them with `position` set.
     *
     * @param  array<int,array<string,mixed>>  $elements
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $elements, array $edges, string $algorithm = 'layered'): array
    {
        if (! $elements) {
            return [];
        }

        return match ($algorithm) {
            'hierarchical' => $this->hierarchical($elements, $edges),
            'circular' => $this->circular($elements),
            'grid' => $this->grid($elements),
            'manual' => $elements,
            default => $this->layered($elements),
        };
    }

    /** Which ArchiMate layer an element type belongs to. */
    public static function layerOf(string $archimateType): string
    {
        foreach (self::LAYERS as $layer => $types) {
            if (in_array($archimateType, $types, true)) {
                return $layer;
            }
        }

        return 'business';
    }

    /** Rows by ArchiMate layer, alphabetical within a row for stability. */
    private function layered(array $elements): array
    {
        $bands = [];
        foreach ($elements as $element) {
            $layer = self::layerOf($element['type'] ?? 'application-component');
            $bands[$layer][] = $element;
        }

        $out = [];
        $row = 0;
        foreach (array_keys(self::LAYERS) as $layer) {
            if (empty($bands[$layer])) {
                continue;
            }
            $band = $bands[$layer];
            usort($band, fn ($a, $b) => strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
            foreach (array_values($band) as $column => $element) {
                $element['position'] = [
                    'x' => self::ORIGIN_X + $column * (self::NODE_W + self::GAP_X),
                    'y' => self::ORIGIN_Y + $row * self::GAP_Y,
                ];
                $element['layer'] = $layer;
                $out[] = $element;
            }
            $row++;
        }

        return $out;
    }

    /**
     * Longest-path ranking over the edge set: a node sits one rank below its
     * deepest predecessor. Cycles are broken by visit order, which is stable
     * because the element list is.
     */
    private function hierarchical(array $elements, array $edges): array
    {
        $ids = array_column($elements, 'id');
        $incoming = array_fill_keys($ids, []);
        foreach ($edges as $edge) {
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            if ($source && $target && isset($incoming[$target])) {
                $incoming[$target][] = $source;
            }
        }

        $rank = [];
        $resolve = function (string $id, array $path) use (&$resolve, &$rank, $incoming): int {
            if (isset($rank[$id])) {
                return $rank[$id];
            }
            if (isset($path[$id])) {
                return 0; // cycle — treat as a root rather than recursing forever
            }
            $path[$id] = true;
            $best = 0;
            foreach ($incoming[$id] ?? [] as $predecessor) {
                if (! isset($incoming[$predecessor])) {
                    continue;
                }
                $best = max($best, $resolve($predecessor, $path) + 1);
            }

            return $rank[$id] = $best;
        };

        foreach ($ids as $id) {
            $resolve($id, []);
        }

        $byRank = [];
        foreach ($elements as $element) {
            $byRank[$rank[$element['id']] ?? 0][] = $element;
        }
        ksort($byRank);

        $out = [];
        foreach ($byRank as $level => $band) {
            foreach (array_values($band) as $column => $element) {
                $element['position'] = [
                    'x' => self::ORIGIN_X + $column * (self::NODE_W + self::GAP_X),
                    'y' => self::ORIGIN_Y + $level * self::GAP_Y,
                ];
                $element['rank'] = $level;
                $out[] = $element;
            }
        }

        return $out;
    }

    private function grid(array $elements): array
    {
        $perRow = max(1, (int) ceil(sqrt(count($elements))));
        $out = [];
        foreach (array_values($elements) as $index => $element) {
            $element['position'] = [
                'x' => self::ORIGIN_X + ($index % $perRow) * (self::NODE_W + self::GAP_X),
                'y' => self::ORIGIN_Y + intdiv($index, $perRow) * self::GAP_Y,
            ];
            $out[] = $element;
        }

        return $out;
    }

    private function circular(array $elements): array
    {
        $count = count($elements);
        $radius = max(220, (int) round($count * (self::NODE_W + self::GAP_X) / (2 * M_PI)));
        $centreX = self::ORIGIN_X + $radius + self::NODE_W;
        $centreY = self::ORIGIN_Y + $radius + self::NODE_H;

        $out = [];
        foreach (array_values($elements) as $index => $element) {
            $angle = 2 * M_PI * $index / max(1, $count) - M_PI / 2;
            $element['position'] = [
                'x' => (int) round($centreX + $radius * cos($angle)),
                'y' => (int) round($centreY + $radius * sin($angle)),
            ];
            $out[] = $element;
        }

        return $out;
    }

    /**
     * Bounding box of a laid-out element set, for export canvas sizing.
     *
     * @return array{width:int,height:int,min_x:int,min_y:int}
     */
    public function bounds(array $elements): array
    {
        if (! $elements) {
            return ['width' => 800, 'height' => 400, 'min_x' => 0, 'min_y' => 0];
        }

        $xs = array_map(fn ($e) => (int) ($e['position']['x'] ?? 0), $elements);
        $ys = array_map(fn ($e) => (int) ($e['position']['y'] ?? 0), $elements);

        return [
            'min_x' => min($xs) - 30,
            'min_y' => min($ys) - 30,
            'width' => max($xs) - min($xs) + self::NODE_W + 60,
            'height' => max($ys) - min($ys) + self::NODE_H + 60,
        ];
    }

    public function nodeWidth(): int
    {
        return self::NODE_W;
    }

    public function nodeHeight(): int
    {
        return self::NODE_H;
    }
}
