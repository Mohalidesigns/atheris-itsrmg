<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\Diagram;
use App\Models\Ea\DiagramEntity;
use App\Models\Ea\DiagramVersion;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\TechComponent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * DiagramService — the server half of the real diagram editor (WS 4.1 / B13).
 *
 * §2.1's verdict on what this replaces: "`DiagramEditor.jsx` adds nodes via
 * `window.prompt()` and connects them by asking the user to type element ids.
 * It is a demo, and it will be seen as one." B13 is scored 4/4 on impact and
 * called table stakes.
 *
 * Five responsibilities:
 *
 *  1. **Validation** — every edge is checked against ArchiMate 3.2 by
 *     {@see RelationshipValidator}. Invalid edges are not silently dropped; they
 *     are saved and *flagged*, because an architect mid-thought should not lose
 *     work to a metamodel rule, and a review gate is where the rule belongs.
 *  2. **Versioning** — every save snapshots the canvas into
 *     `ea_diagram_versions`, so §10's "rollback for accidental bulk changes"
 *     covers diagrams.
 *  3. **Repository binding** — canvas elements that stand for real records are
 *     indexed in `ea_diagram_entities`. Without this a diagram is a picture, and
 *     RBCF App. II §1.1(i) wants an approved topology diagram tied to the
 *     connection catalogue.
 *  4. **Generation** — build a canvas *from* the repository (an application and
 *     its neighbours, a capability and what realises it), which is how a real
 *     estate gets its first hundred diagrams.
 *  5. **Export** — SVG, PNG and PDF, all server-side and library-free so an
 *     air-gapped deployment (§10) exports the same way a hosted one does.
 */
class DiagramService
{
    /** Fill and stroke per ArchiMate layer, matching the palette in the editor. */
    public const LAYER_COLOURS = [
        'motivation' => ['fill' => '#F3E8FF', 'stroke' => '#7E22CE'],
        'strategy' => ['fill' => '#FDF3E0', 'stroke' => '#C9A86A'],
        'business' => ['fill' => '#FEF9C3', 'stroke' => '#A16207'],
        'application' => ['fill' => '#DBEAFE', 'stroke' => '#1D4ED8'],
        'technology' => ['fill' => '#DCFCE7', 'stroke' => '#15803D'],
        'physical' => ['fill' => '#E0F2FE', 'stroke' => '#0E7490'],
        'migration' => ['fill' => '#FFE4E6', 'stroke' => '#BE123C'],
    ];

    /**
     * Which repository model each palette entry binds to. Palette entries with
     * no model are free-form annotation shapes.
     */
    public const BINDINGS = [
        'capability' => Capability::class,
        'application-component' => EaApplication::class,
        'application-interface' => EaInterface::class,
        'business-process' => Process::class,
        'node' => TechComponent::class,
        'data-object' => LogicalEntity::class,
    ];

    public function __construct(
        private DiagramLayout $layout = new DiagramLayout(),
    ) {
    }

    /* ===================== validation ===================== */

    /**
     * Validate a canvas: every edge against the ArchiMate matrix, plus the
     * structural checks that catch a broken save (edge pointing at a deleted
     * element, duplicate element ids).
     *
     * @return array{valid:bool, errors:array<int,array<string,string>>, warnings:array<int,array<string,string>>, checked:int}
     */
    public function validate(array $elements, array $edges): array
    {
        $byId = [];
        $errors = [];
        $warnings = [];

        foreach ($elements as $element) {
            $id = (string) ($element['id'] ?? '');
            if ($id === '') {
                $errors[] = ['scope' => 'element', 'id' => '', 'message' => 'Element without an id cannot be referenced by an edge.'];
                continue;
            }
            if (isset($byId[$id])) {
                $errors[] = ['scope' => 'element', 'id' => $id, 'message' => "Duplicate element id '{$id}'."];
                continue;
            }
            $type = (string) ($element['type'] ?? '');
            if (! in_array($type, RelationshipValidator::ELEMENTS, true)) {
                $warnings[] = ['scope' => 'element', 'id' => $id, 'message' => "'{$type}' is not an ArchiMate 3.2 element type; it will not survive an Open Exchange export."];
            }
            $byId[$id] = $element;
        }

        foreach ($edges as $edge) {
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');
            $relation = strtolower((string) ($edge['data']['relation'] ?? $edge['label'] ?? 'association'));
            $edgeId = (string) ($edge['id'] ?? "{$source}->{$target}");

            if (! isset($byId[$source]) || ! isset($byId[$target])) {
                $errors[] = ['scope' => 'edge', 'id' => $edgeId, 'message' => 'Edge references an element that is not on the canvas.'];
                continue;
            }

            if ($source === $target && $relation !== 'association') {
                $errors[] = ['scope' => 'edge', 'id' => $edgeId, 'message' => "A '{$relation}' relationship cannot join an element to itself."];
                continue;
            }

            $reason = RelationshipValidator::reasonIfNotPermitted(
                $byId[$source]['type'] ?? 'application-component',
                $relation,
                $byId[$target]['type'] ?? 'application-component'
            );

            if ($reason !== null) {
                $errors[] = ['scope' => 'edge', 'id' => $edgeId, 'message' => $reason];
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'checked' => count($edges),
        ];
    }

    /**
     * The permitted-relationship map the editor needs client-side so a drag
     * gesture can be refused (or offered a legal alternative) without a
     * round-trip. Shipped with the page rather than fetched per drag: on a 3G
     * branch link (§10) a request per gesture is not usable.
     *
     * @return array<string,array<string,array<int,string>>> relation => source => targets
     */
    public function permittedMatrix(): array
    {
        $matrix = [];
        foreach (RelationshipValidator::RELATIONS as $relation) {
            foreach (RelationshipValidator::ELEMENTS as $source) {
                $targets = RelationshipValidator::permittedTargetsFor($source, $relation);
                if ($targets) {
                    $matrix[$relation][$source] = array_values($targets);
                }
            }
        }

        return $matrix;
    }

    /* ===================== persistence ===================== */

    /**
     * Save a canvas, snapshotting the previous state and re-indexing the
     * entities the diagram depicts.
     *
     * @param  array<string,mixed>  $attributes  code/name/viewpoint/description/status/layout_algorithm/is_topology
     */
    public function save(Diagram $diagram, array $attributes, array $elements, array $edges, ?string $note = null): Diagram
    {
        $validation = $this->validate($elements, $edges);

        return DB::transaction(function () use ($diagram, $attributes, $elements, $edges, $note, $validation) {
            // Snapshot what is about to be overwritten, not what replaces it, so
            // "restore v3" restores the canvas as it looked at v3.
            if ($diagram->exists) {
                $this->snapshot($diagram, $note);
            }

            $diagram->fill($attributes);
            $diagram->elements_json = array_values($elements);
            $diagram->edges_json = array_values($edges);
            $diagram->validation_json = $validation;
            $diagram->version = ($diagram->version ?? 0) + 1;

            // An approved diagram that changes is no longer approved. Same
            // break-on-edit reasoning as the quality seal (B3).
            if ($diagram->exists && $diagram->isDirty(['elements_json', 'edges_json']) && $diagram->approved_at) {
                $diagram->approved_at = null;
                $diagram->approved_by = null;
                $diagram->status = 'review';
            }

            $diagram->save();
            $this->reindexEntities($diagram);

            return $diagram->refresh();
        });
    }

    /** Write the current canvas to `ea_diagram_versions`. */
    public function snapshot(Diagram $diagram, ?string $note = null): DiagramVersion
    {
        return DiagramVersion::create([
            'organization_id' => $diagram->organization_id,
            'diagram_id' => $diagram->id,
            'version' => $diagram->version ?? 1,
            'elements_json' => $diagram->elements_json ?? [],
            'edges_json' => $diagram->edges_json ?? [],
            'layout_json' => $diagram->layout_json,
            'status' => $diagram->status,
            'change_note' => $note,
            'element_count' => count($diagram->elements_json ?? []),
            'edge_count' => count($diagram->edges_json ?? []),
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Restore a version. The restore is itself a save, so it snapshots the
     * current state first — an accidental restore is as recoverable as an
     * accidental edit.
     */
    public function restore(Diagram $diagram, DiagramVersion $version): Diagram
    {
        return $this->save(
            $diagram,
            ['status' => $version->status],
            $version->elements_json ?? [],
            $version->edges_json ?? [],
            "Restored from v{$version->version}"
        );
    }

    /**
     * Approve a diagram. RBCF App. II §1.1(i) does not ask for "a topology
     * diagram", it asks for an **approved** one, so the approval is recorded
     * against a person and a moment, and an invalid canvas cannot be approved.
     *
     * @throws \InvalidArgumentException
     */
    public function approve(Diagram $diagram): Diagram
    {
        $validation = $this->validate($diagram->elements_json ?? [], $diagram->edges_json ?? []);

        if (! $validation['valid']) {
            throw new \InvalidArgumentException(
                'This diagram has '.count($validation['errors']).' metamodel error(s) and cannot be approved. '.
                'First error: '.($validation['errors'][0]['message'] ?? 'unknown').'"'
            );
        }

        if (empty($diagram->elements_json)) {
            throw new \InvalidArgumentException('An empty diagram cannot be approved.');
        }

        $diagram->forceFill([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'validation_json' => $validation,
        ])->save();

        return $diagram;
    }

    /** Rebuild `ea_diagram_entities` for one diagram from its canvas JSON. */
    public function reindexEntities(Diagram $diagram): int
    {
        DiagramEntity::where('diagram_id', $diagram->id)->delete();

        $rows = [];
        foreach ($diagram->elements_json ?? [] as $element) {
            $entityType = $element['entity_type'] ?? null;
            $entityId = $element['entity_id'] ?? null;
            if (! $entityType || ! $entityId) {
                continue;
            }
            $rows[] = [
                'organization_id' => $diagram->organization_id,
                'diagram_id' => $diagram->id,
                'element_key' => (string) ($element['id'] ?? ''),
                'entity_type' => $entityType,
                'entity_id' => (int) $entityId,
                'archimate_type' => (string) ($element['type'] ?? 'application-component'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('ea_diagram_entities')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    /** Diagrams that depict a given entity — the "where is this drawn?" answer. */
    public function diagramsShowing(string $entityType, int $entityId)
    {
        return Diagram::query()
            ->whereIn('id', DiagramEntity::where('entity_type', $entityType)
                ->where('entity_id', $entityId)->pluck('diagram_id'))
            ->get(['id', 'code', 'name', 'viewpoint', 'status', 'version', 'is_topology', 'approved_at']);
    }

    /* ===================== generation ===================== */

    /**
     * Build a canvas from the repository around one entity: the entity, its
     * graph neighbours to `$depth` hops, and the edges between them.
     *
     * This is the difference between an editor and a drawing tool — the first
     * diagram of an application should not be drawn, it should be *derived*,
     * then curated.
     *
     * @return array{elements:array<int,array<string,mixed>>, edges:array<int,array<string,mixed>>}
     */
    public function generateAround(string $entityType, int $entityId, int $depth = 1, string $algorithm = 'layered', int $limit = 60): array
    {
        $impact = (new ImpactAnalyser())->traverse($entityType, $entityId, max(1, min(3, $depth)));

        // The traversal keys nodes by fully-qualified class name; a canvas id is
        // read by humans (and appears in the SVG export), so shorten it.
        $keyMap = [];
        foreach ($impact['nodes'] as $node) {
            $keyMap[$node['key']] = strtolower(class_basename($node['entity_type'])).'-'.$node['id'];
        }

        // Nearest first, so a trim keeps the neighbourhood and drops the
        // periphery. Trimming after layout would keep whatever the layout
        // happened to sort first — for the hierarchical algorithm that is the
        // rank-0 leaves, i.e. the nodes with no edges at all.
        $nodes = $impact['nodes'];
        usort($nodes, fn ($a, $b) => [$a['hop'], $a['name']] <=> [$b['hop'], $b['name']]);
        $nodes = array_slice($nodes, 0, max(1, $limit));

        $keep = [];
        $elements = [];
        foreach ($nodes as $node) {
            $keep[$node['key']] = true;
            $elements[] = [
                'id' => $keyMap[$node['key']],
                'label' => $node['name'],
                'type' => $node['archimate_type'],
                'entity_type' => $node['entity_type'],
                'entity_id' => $node['id'],
                'hop' => $node['hop'],
                'position' => ['x' => 0, 'y' => 0],
                'meta' => array_filter([
                    'criticality' => $node['criticality'] ?? null,
                    'lifecycle' => $node['lifecycle'] ?? null,
                ]),
            ];
        }

        $edges = [];
        $seen = [];
        foreach ($impact['edges'] as $index => $edge) {
            if (! isset($keep[$edge['source']], $keep[$edge['target']])) {
                continue;
            }
            $source = $keyMap[$edge['source']] ?? null;
            $target = $keyMap[$edge['target']] ?? null;
            if (! $source || ! $target) {
                continue;
            }

            $relation = $this->archimateRelationFor($edge);

            // The traversal reads the same logical edge from several sources —
            // the capability pivot and an explicit `realisation` row describe one
            // fact. Drawing it twice makes the canvas look wrong.
            $signature = $source.'|'.$relation.'|'.$target;
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;

            $edges[] = [
                'id' => 'e-'.$index,
                'source' => $source,
                'target' => $target,
                'label' => $relation,
                'data' => ['relation' => $relation, 'origin' => $edge['kind'] ?? 'relationship'],
            ];
        }

        // A derived canvas whose edges break the metamodel is a data-quality
        // finding, not a reason to refuse the diagram — so it is laid out and
        // returned either way, and validate() flags it on save.
        return [
            'elements' => $this->layout->apply($elements, $edges, $algorithm),
            'edges' => $edges,
        ];
    }

    /**
     * Map a traversal edge onto a legal ArchiMate relation where one exists.
     * Interfaces become `flow`; the generic table's `relation_type` is used when
     * it is already a legal name, and falls back to `association` otherwise —
     * the standard's own escape hatch, rather than inventing a relation.
     */
    private function archimateRelationFor(array $edge): string
    {
        $candidate = strtolower((string) ($edge['relation'] ?? $edge['type'] ?? ''));

        $aliases = [
            'realises' => 'realisation',
            'realizes' => 'realisation',
            'realises-capability' => 'realisation',
            'serves' => 'serving',
            'dependson' => 'association',
            'depends_on' => 'association',
            'uses' => 'used-by',
            'contains' => 'composition',
            'aggregates' => 'aggregation',
            'assigned' => 'assignment',
            'accesses' => 'access',
            'triggers' => 'triggering',
            'influences' => 'influence',
            'specialises' => 'specialisation',
        ];

        $candidate = $aliases[$candidate] ?? $candidate;

        return in_array($candidate, RelationshipValidator::RELATIONS, true) ? $candidate : 'association';
    }

    /**
     * Repository entities available to drop on the canvas, grouped by palette
     * type. Shipped with the editor page so the palette searches locally.
     */
    public function paletteCatalogue(int $perType = 400): array
    {
        $catalogue = [];

        foreach (self::BINDINGS as $archimateType => $class) {
            $rows = $class::query()->orderBy('name')->limit($perType)->get();
            $catalogue[$archimateType] = $rows->map(fn ($row) => [
                'entity_type' => $class,
                'entity_id' => $row->id,
                'label' => $row->name,
                'code' => $row->code ?? null,
                'type' => $archimateType,
            ])->values()->all();
        }

        return $catalogue;
    }

    /* ===================== export ===================== */

    /**
     * SVG export. Hand-rolled rather than screenshotted, so the output is
     * vector, searchable, diff-able and identical in an air-gapped deployment.
     */
    public function toSvg(Diagram $diagram): string
    {
        $elements = $this->positioned($diagram);
        $edges = $diagram->edges_json ?? [];
        $bounds = $this->layout->bounds($elements);
        $w = $this->layout->nodeWidth();
        $h = $this->layout->nodeHeight();

        $positions = [];
        foreach ($elements as $element) {
            $positions[$element['id']] = $element['position'];
        }

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $svg .= sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="%d %d %d %d" font-family="Helvetica, Arial, sans-serif">'."\n",
            $bounds['width'], $bounds['height'] + 48,
            $bounds['min_x'], $bounds['min_y'] - 48, $bounds['width'], $bounds['height'] + 48
        );
        $svg .= '  <defs><marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">'.
            '<path d="M 0 0 L 10 5 L 0 10 z" fill="#8B93A1"/></marker></defs>'."\n";
        $svg .= sprintf('  <rect x="%d" y="%d" width="%d" height="%d" fill="#FFFFFF"/>'."\n",
            $bounds['min_x'], $bounds['min_y'] - 48, $bounds['width'], $bounds['height'] + 48);

        $svg .= sprintf('  <text x="%d" y="%d" font-size="15" font-weight="bold" fill="#0A1F44">%s</text>'."\n",
            $bounds['min_x'] + 8, $bounds['min_y'] - 24, $this->xml($diagram->name));
        $svg .= sprintf('  <text x="%d" y="%d" font-size="9" fill="#6B7280">%s</text>'."\n",
            $bounds['min_x'] + 8, $bounds['min_y'] - 10,
            $this->xml(sprintf('%s · viewpoint: %s · v%d · %s',
                $diagram->code, $diagram->viewpoint, $diagram->version, $diagram->status)));

        // Edges first so boxes sit on top of the lines.
        foreach ($edges as $edge) {
            $from = $positions[$edge['source'] ?? ''] ?? null;
            $to = $positions[$edge['target'] ?? ''] ?? null;
            if (! $from || ! $to) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = $this->anchor($from, $to, $w, $h);
            $relation = (string) ($edge['data']['relation'] ?? $edge['label'] ?? 'association');
            $dashed = in_array($relation, ['influence', 'association'], true) ? ' stroke-dasharray="4 3"' : '';
            $svg .= sprintf('  <line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#8B93A1" stroke-width="1.2"%s marker-end="url(#arrow)"/>'."\n",
                $x1, $y1, $x2, $y2, $dashed);
            $svg .= sprintf('  <text x="%.1f" y="%.1f" font-size="8" fill="#6B7280" text-anchor="middle">%s</text>'."\n",
                ($x1 + $x2) / 2, ($y1 + $y2) / 2 - 3, $this->xml($relation));
        }

        foreach ($elements as $element) {
            $layer = DiagramLayout::layerOf($element['type'] ?? 'application-component');
            $colours = self::LAYER_COLOURS[$layer] ?? self::LAYER_COLOURS['application'];
            $x = $element['position']['x'];
            $y = $element['position']['y'];
            $svg .= sprintf('  <rect x="%d" y="%d" width="%d" height="%d" rx="6" fill="%s" stroke="%s" stroke-width="1.2"/>'."\n",
                $x, $y, $w, $h, $colours['fill'], $colours['stroke']);
            $svg .= sprintf('  <text x="%d" y="%d" font-size="10" font-weight="bold" fill="#0A1F44">%s</text>'."\n",
                $x + 10, $y + 24, $this->xml($this->truncate((string) ($element['label'] ?? ''), 26)));
            $svg .= sprintf('  <text x="%d" y="%d" font-size="8" fill="%s">%s</text>'."\n",
                $x + 10, $y + 40, $colours['stroke'], $this->xml((string) ($element['type'] ?? '')));
        }

        $svg .= "</svg>\n";

        return $svg;
    }

    /**
     * PNG export via GD. Present because a bank's board pack is assembled in
     * PowerPoint and SVG will not paste into it.
     */
    public function toPng(Diagram $diagram): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('PNG export needs the GD extension. SVG and PDF export do not — use one of those, or enable GD.');
        }

        $elements = $this->positioned($diagram);
        $edges = $diagram->edges_json ?? [];
        $bounds = $this->layout->bounds($elements);
        $w = $this->layout->nodeWidth();
        $h = $this->layout->nodeHeight();

        // 2× for legibility when pasted into a slide.
        $scale = 2;
        $width = max(400, $bounds['width']) * $scale;
        $height = (max(200, $bounds['height']) + 48) * $scale;

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageantialias($image, true);

        $navy = imagecolorallocate($image, 10, 31, 68);
        $grey = imagecolorallocate($image, 107, 114, 128);
        $edgeColour = imagecolorallocate($image, 139, 147, 161);

        $offsetX = -$bounds['min_x'] + 0;
        $offsetY = -$bounds['min_y'] + 48;
        $px = fn ($v) => (int) round(($v) * $scale);

        // Titles are pinned to the canvas, not to the element bounding box —
        // otherwise a diagram whose leftmost node sits at a positive x pushes its
        // own heading off the image.
        imagestring($image, 5, $px(12), $px(10), $this->ascii($diagram->name), $navy);
        imagestring($image, 2, $px(12), $px(28),
            $this->ascii("{$diagram->code} | v{$diagram->version} | {$diagram->status}"), $grey);

        $positions = [];
        foreach ($elements as $element) {
            $positions[$element['id']] = $element['position'];
        }

        foreach ($edges as $edge) {
            $from = $positions[$edge['source'] ?? ''] ?? null;
            $to = $positions[$edge['target'] ?? ''] ?? null;
            if (! $from || ! $to) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = $this->anchor(
                ['x' => $from['x'] + $offsetX, 'y' => $from['y'] + $offsetY],
                ['x' => $to['x'] + $offsetX, 'y' => $to['y'] + $offsetY],
                $w, $h
            );
            imagesetthickness($image, $scale);
            imageline($image, $px($x1), $px($y1), $px($x2), $px($y2), $edgeColour);
        }

        foreach ($elements as $element) {
            $layer = DiagramLayout::layerOf($element['type'] ?? 'application-component');
            $colours = self::LAYER_COLOURS[$layer] ?? self::LAYER_COLOURS['application'];
            [$fr, $fg, $fb] = $this->hexToRgb255($colours['fill']);
            [$sr, $sg, $sb] = $this->hexToRgb255($colours['stroke']);
            $fill = imagecolorallocate($image, $fr, $fg, $fb);
            $stroke = imagecolorallocate($image, $sr, $sg, $sb);

            $x1 = $px($element['position']['x'] + $offsetX);
            $y1 = $px($element['position']['y'] + $offsetY);
            $x2 = $px($element['position']['x'] + $offsetX + $w);
            $y2 = $px($element['position']['y'] + $offsetY + $h);

            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $fill);
            imagesetthickness($image, $scale);
            imagerectangle($image, $x1, $y1, $x2, $y2, $stroke);
            imagestring($image, 3, $x1 + 8, $y1 + 12, $this->ascii($this->truncate((string) ($element['label'] ?? ''), 24)), $navy);
            imagestring($image, 2, $x1 + 8, $y1 + 30, $this->ascii((string) ($element['type'] ?? '')), $stroke);
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /**
     * PDF export via {@see PdfWriter} — landscape, canvas-shaped, vector. Used
     * for the ARB pack and the CBN topology annex.
     */
    public function toPdf(Diagram $diagram): string
    {
        $elements = $this->positioned($diagram);
        $edges = $diagram->edges_json ?? [];
        $bounds = $this->layout->bounds($elements);
        $w = $this->layout->nodeWidth();
        $h = $this->layout->nodeHeight();

        $pageWidth = max(595.0, (float) $bounds['width'] + 80);
        $pageHeight = max(420.0, (float) $bounds['height'] + 120);

        $pdf = new PdfWriter();
        $pdf->setPageSize($pageWidth, $pageHeight);

        // PDF's origin is bottom-left; the canvas's is top-left.
        $toPdfY = fn ($y) => $pageHeight - 70 - ($y - $bounds['min_y']);
        $toPdfX = fn ($x) => 40 + ($x - $bounds['min_x']);

        $pdf->textAt(40, $pageHeight - 40, $diagram->name, 14, true, [0.04, 0.12, 0.27]);
        $pdf->textAt(40, $pageHeight - 56, sprintf(
            '%s · viewpoint: %s · version %d · %s%s',
            $diagram->code, $diagram->viewpoint, $diagram->version, $diagram->status,
            $diagram->approved_at ? ' · approved '.$diagram->approved_at->format('d M Y') : ''
        ), 8, false, [0.42, 0.45, 0.5]);

        $positions = [];
        foreach ($elements as $element) {
            $positions[$element['id']] = $element['position'];
        }

        foreach ($edges as $edge) {
            $from = $positions[$edge['source'] ?? ''] ?? null;
            $to = $positions[$edge['target'] ?? ''] ?? null;
            if (! $from || ! $to) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = $this->anchor($from, $to, $w, $h);
            $pdf->lineAt($toPdfX($x1), $toPdfY($y1), $toPdfX($x2), $toPdfY($y2), [0.55, 0.58, 0.64], 0.9);
        }

        foreach ($elements as $element) {
            $layer = DiagramLayout::layerOf($element['type'] ?? 'application-component');
            $colours = self::LAYER_COLOURS[$layer] ?? self::LAYER_COLOURS['application'];
            $x = $toPdfX($element['position']['x']);
            $y = $toPdfY($element['position']['y'] + $h);

            $pdf->rect($x, $y, $w, $h, $this->hexToRgb1($colours['fill']), $this->hexToRgb1($colours['stroke']));
            $pdf->textAt($x + 8, $y + $h - 22, $this->truncate((string) ($element['label'] ?? ''), 28), 9, true, [0.04, 0.12, 0.27]);
            $pdf->textAt($x + 8, $y + $h - 38, (string) ($element['type'] ?? ''), 7, false, $this->hexToRgb1($colours['stroke']));
        }

        return $pdf->render();
    }

    /**
     * Elements with positions guaranteed. A canvas saved before auto-layout
     * existed, or generated headlessly, may have every node at (0,0) — exporting
     * that as a single black square would be worse than laying it out.
     */
    private function positioned(Diagram $diagram): array
    {
        $elements = $diagram->elements_json ?? [];
        if (! $elements) {
            return [];
        }

        $placed = array_filter($elements, fn ($e) => ! empty($e['position']['x']) || ! empty($e['position']['y']));
        if (count($placed) >= max(1, (int) floor(count($elements) * 0.5))) {
            return array_values(array_map(function ($element) {
                $element['position'] = [
                    'x' => (int) ($element['position']['x'] ?? 0),
                    'y' => (int) ($element['position']['y'] ?? 0),
                ];

                return $element;
            }, $elements));
        }

        return $this->layout->apply($elements, $diagram->edges_json ?? [],
            $diagram->layout_algorithm === 'manual' ? 'layered' : ($diagram->layout_algorithm ?: 'layered'));
    }

    /**
     * Clip an edge to the two boxes it joins, so the line starts and ends on the
     * borders instead of running through the labels.
     *
     * Both boxes are the same size, so the exit point is found by scaling the
     * centre-to-centre vector until it meets whichever side it crosses first.
     *
     * @return array{0:float,1:float,2:float,3:float}
     */
    private function anchor(array $from, array $to, float $w, float $h): array
    {
        $cx1 = $from['x'] + $w / 2;
        $cy1 = $from['y'] + $h / 2;
        $cx2 = $to['x'] + $w / 2;
        $cy2 = $to['y'] + $h / 2;

        $dx = $cx2 - $cx1;
        $dy = $cy2 - $cy1;

        if ($dx === 0.0 && $dy === 0.0) {
            return [$cx1, $cy1, $cx2, $cy2];
        }

        // Fraction of the vector at which it leaves a box of this size.
        $scale = min(
            $dx !== 0.0 ? abs(($w / 2 + 2) / $dx) : INF,
            $dy !== 0.0 ? abs(($h / 2 + 2) / $dy) : INF
        );
        $scale = min($scale, 0.5);   // never past the midpoint on very short edges

        return [
            $cx1 + $dx * $scale,
            $cy1 + $dy * $scale,
            $cx2 - $dx * $scale,
            $cy2 - $dy * $scale,
        ];
    }

    private function hexToRgb255(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function hexToRgb1(string $hex): array
    {
        return array_map(fn ($v) => round($v / 255, 3), $this->hexToRgb255($hex));
    }

    private function truncate(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1);
    }

    /** GD's bitmap fonts are Latin-1 only. */
    private function ascii(string $text): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '-', $text);
    }
}
