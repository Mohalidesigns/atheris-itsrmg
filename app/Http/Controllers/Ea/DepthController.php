<?php

namespace App\Http\Controllers\Ea;

use App\Http\Controllers\Controller;
use App\Models\Ea\Capability;
use App\Models\Ea\Diagram;
use App\Models\Ea\DiagramVersion;
use App\Models\Ea\DraftChange;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Plateau;
use App\Models\Ea\PlateauEntity;
use App\Models\Ea\TechComponent;
use App\Repositories\Ea\EaRepository;
use App\Services\Ea\ArchiMateRoundTrip;
use App\Services\Ea\CapabilityLinkIndex;
use App\Services\Ea\CostModelService;
use App\Services\Ea\DiagramLayout;
use App\Services\Ea\DiagramService;
use App\Services\Ea\DraftChangeService;
use App\Services\Ea\GraphQlService;
use App\Services\Ea\HierarchyIndex;
use App\Services\Ea\ImpactAnalyser;
use App\Services\Ea\PlateauDiffService;
use App\Services\Ea\RelationshipValidator;
use App\Services\Ea\ViewpointGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * DepthController — every ATH-EAR-002 Phase 4 surface (§9, "Depth and scale").
 *
 *  · WS 4.1  the real diagram editor (B13)
 *  · WS 4.2  n-hop change impact (B14)
 *  · WS 4.3  plateau diff and scenario authoring (B15)
 *  · WS 4.4  the ArchiMate round-trip release gate
 *  · WS 4.5  GraphQL API and scoped writes with draft-and-approve
 *  · WS 4.7  cost, TCO and technical debt (B17)
 *
 * These live together because they share a shape: each is a *lens over the whole
 * repository* rather than a catalogue of one entity type, and each one needs the
 * two materialised indexes §10 asks for.
 */
class DepthController extends Controller
{
    public function __construct(
        private DiagramService $diagrams = new DiagramService(),
        private ImpactAnalyser $impact = new ImpactAnalyser(),
        private PlateauDiffService $plateaux = new PlateauDiffService(),
        private CostModelService $cost = new CostModelService(),
        private DraftChangeService $drafts = new DraftChangeService(),
        private EaRepository $repo = new EaRepository(),
    ) {
    }

    /* ==================== WS 4.1 — diagrams ==================== */

    public function diagramIndex()
    {
        $diagrams = Diagram::orderByDesc('updated_at')->get()
            ->map(fn ($diagram) => [
                'id' => $diagram->id,
                'code' => $diagram->code,
                'name' => $diagram->name,
                'viewpoint' => $diagram->viewpoint,
                'status' => $diagram->status,
                'version' => $diagram->version,
                'is_topology' => (bool) $diagram->is_topology,
                'approved_at' => optional($diagram->approved_at)->toDateString(),
                'element_count' => count($diagram->elements_json ?? []),
                'edge_count' => count($diagram->edges_json ?? []),
                'errors' => count($diagram->validation_json['errors'] ?? []),
                'updated_at' => optional($diagram->updated_at)->toDateTimeString(),
            ]);

        return Inertia::render('Ea/Diagrams', [
            'diagrams' => $diagrams,
            'viewpoints' => ViewpointGenerator::VIEWPOINTS,
            // RBCF App. II §1.1(i) asks for an *approved* network topology
            // diagram. Surfacing the count on the index is how an architect
            // discovers the bank does not have one.
            'topology' => [
                'total' => Diagram::where('is_topology', true)->count(),
                'approved' => Diagram::where('is_topology', true)->whereNotNull('approved_at')->count(),
            ],
            'startTypes' => collect(DiagramService::BINDINGS)->map(fn ($class, $type) => [
                'archimate_type' => $type,
                'entity_type' => $class,
                'label' => class_basename($class),
            ])->values(),
        ]);
    }

    public function diagramShow(Diagram $diagram)
    {
        return Inertia::render('Ea/DiagramEditor', [
            'diagram' => $diagram,
            'palette' => $this->diagrams->paletteCatalogue(),
            'matrix' => $this->diagrams->permittedMatrix(),
            'relations' => RelationshipValidator::RELATIONS,
            'elementTypes' => RelationshipValidator::ELEMENTS,
            'layers' => DiagramLayout::LAYERS,
            'layerColours' => DiagramService::LAYER_COLOURS,
            'algorithms' => DiagramLayout::ALGORITHMS,
            'validation' => $diagram->validation_json
                ?? $this->diagrams->validate($diagram->elements_json ?? [], $diagram->edges_json ?? []),
            'versions' => DiagramVersion::where('diagram_id', $diagram->id)
                ->orderByDesc('version')->limit(30)
                ->get(['id', 'version', 'status', 'change_note', 'element_count', 'edge_count', 'created_at'])
                ->map(fn ($version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'status' => $version->status,
                    'change_note' => $version->change_note,
                    'element_count' => $version->element_count,
                    'edge_count' => $version->edge_count,
                    'created_at' => optional($version->created_at)->toDateTimeString(),
                ]),
            'can' => [
                'edit' => Gate::allows('update', $diagram),
                'approve' => Gate::allows('approve', $diagram),
                'export' => Gate::allows('export', Diagram::class),
            ],
        ]);
    }

    public function diagramStore(Request $request)
    {
        Gate::authorize('create', Diagram::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:ea_diagrams,code'],
            'name' => ['required', 'string', 'max:255'],
            'viewpoint' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string'],
            'is_topology' => ['nullable', 'boolean'],
            'layout_algorithm' => ['nullable', 'in:'.implode(',', DiagramLayout::ALGORITHMS)],
            // Optional derivation: create the diagram already populated from the
            // repository around one entity.
            'seed_entity_type' => ['nullable', 'string'],
            'seed_entity_id' => ['nullable', 'integer'],
            'seed_depth' => ['nullable', 'integer', 'min:1', 'max:3'],
        ]);

        $canvas = ['elements' => [], 'edges' => []];

        if (! empty($data['seed_entity_type']) && ! empty($data['seed_entity_id'])) {
            $type = class_exists($data['seed_entity_type'])
                ? $data['seed_entity_type']
                : 'App\\Models\\Ea\\'.$data['seed_entity_type'];

            if (class_exists($type)) {
                $canvas = $this->diagrams->generateAround(
                    $type, (int) $data['seed_entity_id'],
                    (int) ($data['seed_depth'] ?? 1),
                    $data['layout_algorithm'] ?? 'layered'
                );
            }
        }

        $diagram = new Diagram(['version' => 0]);

        $diagram = $this->diagrams->save(
            $diagram,
            collect($data)->only(['code', 'name', 'viewpoint', 'description', 'is_topology', 'layout_algorithm'])->all()
                + ['status' => 'draft'],
            $canvas['elements'],
            $canvas['edges'],
            $canvas['elements'] ? 'Derived from the repository' : 'Created'
        );

        return redirect()->route('ea.diagrams.show', $diagram->id)
            ->with('success', "Diagram {$diagram->code} created with ".count($canvas['elements']).' element(s).');
    }

    public function diagramUpdate(Request $request, Diagram $diagram)
    {
        Gate::authorize('update', $diagram);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', "unique:ea_diagrams,code,{$diagram->id}"],
            'name' => ['required', 'string', 'max:255'],
            'viewpoint' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,review,approved,archived'],
            'is_topology' => ['nullable', 'boolean'],
            'layout_algorithm' => ['nullable', 'in:'.implode(',', DiagramLayout::ALGORITHMS)],
            'elements_json' => ['nullable', 'array'],
            'edges_json' => ['nullable', 'array'],
            'change_note' => ['nullable', 'string', 'max:255'],
        ]);

        $diagram = $this->diagrams->save(
            $diagram,
            collect($data)->only(['code', 'name', 'viewpoint', 'description', 'status', 'is_topology', 'layout_algorithm'])->all(),
            $data['elements_json'] ?? [],
            $data['edges_json'] ?? [],
            $data['change_note'] ?? null
        );

        $errors = count($diagram->validation_json['errors'] ?? []);

        return back()->with(
            $errors ? 'warning' : 'success',
            $errors
                ? "Saved as v{$diagram->version} with {$errors} metamodel error(s) to resolve before approval."
                : "Saved as v{$diagram->version}."
        );
    }

    public function diagramDestroy(Diagram $diagram)
    {
        Gate::authorize('delete', $diagram);
        $this->repo->delete($diagram);

        return redirect()->route('ea.diagrams')->with('success', 'Diagram deleted.');
    }

    /** Auto-layout without saving, so a user can try an algorithm and undo it. */
    public function diagramLayout(Request $request, Diagram $diagram)
    {
        Gate::authorize('update', $diagram);

        $data = $request->validate([
            'algorithm' => ['required', 'in:'.implode(',', DiagramLayout::ALGORITHMS)],
            'elements_json' => ['nullable', 'array'],
            'edges_json' => ['nullable', 'array'],
        ]);

        $elements = (new DiagramLayout())->apply(
            $data['elements_json'] ?? $diagram->elements_json ?? [],
            $data['edges_json'] ?? $diagram->edges_json ?? [],
            $data['algorithm']
        );

        return response()->json(['elements' => $elements, 'algorithm' => $data['algorithm']]);
    }

    /** Live validation for the editor's inspector panel. */
    public function diagramValidate(Request $request)
    {
        $data = $request->validate([
            'elements_json' => ['nullable', 'array'],
            'edges_json' => ['nullable', 'array'],
        ]);

        return response()->json($this->diagrams->validate($data['elements_json'] ?? [], $data['edges_json'] ?? []));
    }

    /** Add repository neighbours of an element already on the canvas. */
    public function diagramExpand(Request $request)
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'integer'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:3'],
        ]);

        $type = class_exists($data['entity_type']) ? $data['entity_type'] : 'App\\Models\\Ea\\'.$data['entity_type'];
        abort_unless(class_exists($type), 422, 'Unknown entity type.');

        return response()->json($this->diagrams->generateAround(
            $type, (int) $data['entity_id'], (int) ($data['depth'] ?? 1), 'layered'
        ));
    }

    public function diagramApprove(Diagram $diagram)
    {
        Gate::authorize('approve', $diagram);

        try {
            $this->diagrams->approve($diagram);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Diagram {$diagram->code} approved at v{$diagram->version}.");
    }

    public function diagramRestore(Diagram $diagram, DiagramVersion $version)
    {
        Gate::authorize('update', $diagram);
        abort_unless($version->diagram_id === $diagram->id, 404);

        $this->diagrams->restore($diagram, $version);

        return back()->with('success', "Restored v{$version->version}; the canvas before the restore is kept as a version.");
    }

    /** PNG / SVG / PDF export (B13's fourth requirement). */
    public function diagramExport(Diagram $diagram, string $format = 'svg')
    {
        Gate::authorize('export', Diagram::class);

        $slug = \Illuminate\Support\Str::slug($diagram->code.'-'.$diagram->name);

        try {
            return match ($format) {
                'svg' => response($this->diagrams->toSvg($diagram), 200, [
                    'Content-Type' => 'image/svg+xml',
                    'Content-Disposition' => "attachment; filename=\"{$slug}-v{$diagram->version}.svg\"",
                ]),
                'png' => response($this->diagrams->toPng($diagram), 200, [
                    'Content-Type' => 'image/png',
                    'Content-Disposition' => "attachment; filename=\"{$slug}-v{$diagram->version}.png\"",
                ]),
                'pdf' => response($this->diagrams->toPdf($diagram), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => "attachment; filename=\"{$slug}-v{$diagram->version}.pdf\"",
                ]),
                default => back()->with('error', "Unknown export format '{$format}'. Use svg, png or pdf."),
            };
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /* ==================== WS 4.2 — n-hop impact ==================== */

    public function impact(Request $request)
    {
        $entityType = (string) $request->query('entity_type', EaApplication::class);
        if (! class_exists($entityType)) {
            $entityType = 'App\\Models\\Ea\\'.$entityType;
        }
        if (! isset(ImpactAnalyser::START_TYPES[$entityType])) {
            $entityType = EaApplication::class;
        }

        $entityId = (int) $request->query('entity_id', 0)
            ?: (int) $entityType::query()->orderBy('id')->value('id');

        $depth = (int) $request->query('depth', ImpactAnalyser::DEFAULT_DEPTH);
        $direction = (string) $request->query('direction', ImpactAnalyser::BOTH);

        $result = $entityId
            ? $this->impact->changeImpact($entityType, $entityId, $depth, $direction)
            : ['graph' => ['root' => null, 'nodes' => [], 'edges' => [], 'by_hop' => [], 'by_type' => [], 'truncated' => false], 'summary' => []];

        return Inertia::render('Ea/Impact', [
            'result' => $result,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'depth' => $depth,
            'direction' => $direction,
            'startTypes' => collect(ImpactAnalyser::START_TYPES)->map(fn ($label, $class) => [
                'value' => $class,
                'label' => $label,
                'count' => class_exists($class) ? $class::query()->count() : 0,
            ])->values(),
            'entities' => class_exists($entityType)
                ? $entityType::query()->orderBy('name')->limit(1000)->get(['id', 'code', 'name'])
                : [],
            'maxDepth' => ImpactAnalyser::MAX_DEPTH,
            // The diagrams that already depict this entity, so an impact finding
            // can be traced to the drawing it belongs on.
            'diagrams' => $entityId ? $this->diagrams->diagramsShowing($entityType, $entityId) : [],
        ]);
    }

    /**
     * JSON impact, for the change-impact tab mounted on every entity page.
     * A tab must not cost a full page load — §10 requires the module to be
     * usable on 3G.
     */
    public function impactJson(Request $request)
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'integer'],
            'depth' => ['nullable', 'integer', 'min:1', 'max:'.ImpactAnalyser::MAX_DEPTH],
            'direction' => ['nullable', 'in:both,upstream,downstream'],
        ]);

        $type = class_exists($data['entity_type']) ? $data['entity_type'] : 'App\\Models\\Ea\\'.$data['entity_type'];
        abort_unless(isset(ImpactAnalyser::START_TYPES[$type]), 422, 'That entity type does not support impact analysis.');

        return response()->json($this->impact->changeImpact(
            $type, (int) $data['entity_id'],
            (int) ($data['depth'] ?? ImpactAnalyser::DEFAULT_DEPTH),
            $data['direction'] ?? ImpactAnalyser::BOTH
        ));
    }

    /** "How is A connected to B?" */
    public function impactPath(Request $request)
    {
        $data = $request->validate([
            'from_type' => ['required', 'string'],
            'from_id' => ['required', 'integer'],
            'to_type' => ['required', 'string'],
            'to_id' => ['required', 'integer'],
        ]);

        $resolve = fn ($name) => class_exists($name) ? $name : 'App\\Models\\Ea\\'.$name;

        $path = $this->impact->pathBetween(
            $resolve($data['from_type']), (int) $data['from_id'],
            $resolve($data['to_type']), (int) $data['to_id']
        );

        return response()->json([
            'path' => $path,
            'connected' => $path !== null,
            'hops' => $path ? max(0, count($path) - 1) : null,
        ]);
    }

    /* ==================== WS 4.3 — plateau diff ==================== */

    public function plateauDiff(Request $request)
    {
        $plateaux = Plateau::orderBy('plateau_type')->orderBy('effective_from')->get();

        $fromId = (int) $request->query('from',
            Plateau::where('plateau_type', 'current')->value('id') ?: $plateaux->first()?->id);
        $toId = (int) $request->query('to',
            Plateau::where('plateau_type', 'target')->value('id') ?: $plateaux->last()?->id);

        $from = Plateau::find($fromId);
        $to = Plateau::find($toId);

        $diff = $from && $to && $from->id !== $to->id
            ? $this->plateaux->diff($from, $to)
            : null;

        return Inertia::render('Ea/PlateauDiff', [
            'plateaux' => $plateaux->map(fn ($plateau) => [
                'id' => $plateau->id,
                'code' => $plateau->code,
                'name' => $plateau->name,
                'plateau_type' => $plateau->plateau_type,
                'effective_from' => optional($plateau->effective_from)->toDateString(),
                'members' => PlateauEntity::where('plateau_id', $plateau->id)->count(),
            ]),
            'diff' => $diff,
            'from' => $fromId,
            'to' => $toId,
            'dispositions' => PlateauEntity::DISPOSITIONS,
            'entityTypes' => collect(PlateauDiffService::TYPES)->map(fn ($label, $class) => [
                'value' => $class, 'label' => $label,
            ])->values(),
        ]);
    }

    /** The authoring surface: one plateau's membership, editable. */
    public function plateauMembership(Request $request, Plateau $plateau)
    {
        $entityType = (string) $request->query('entity_type', EaApplication::class);
        if (! isset(PlateauDiffService::TYPES[$entityType])) {
            $entityType = EaApplication::class;
        }

        return Inertia::render('Ea/PlateauMembership', [
            'plateau' => $plateau,
            'entityType' => $entityType,
            'entityTypes' => collect(PlateauDiffService::TYPES)->map(fn ($label, $class) => [
                'value' => $class, 'label' => $label,
                'count' => PlateauEntity::where('plateau_id', $plateau->id)->where('entity_type', $class)->count(),
            ])->values(),
            'rows' => $this->plateaux->membershipTable($plateau, $entityType),
            'dispositions' => PlateauEntity::DISPOSITIONS,
            'plateaux' => Plateau::where('id', '!=', $plateau->id)
                ->get(['id', 'code', 'name', 'plateau_type']),
            'currencies' => array_keys((new \App\Services\Ea\FxExposureService())->rates()),
            // Feeding the rationalisation candidates straight into the authoring
            // screen is the point: the analysis proposes, the architect decides.
            'candidates' => $entityType === EaApplication::class
                ? array_slice($this->cost->rationalisationCandidates(), 0, 15)
                : [],
        ]);
    }

    public function plateauDispositionStore(Request $request, Plateau $plateau)
    {
        Gate::authorize('update', $plateau);

        $data = $request->validate([
            'entity_type' => ['required', 'string'],
            'entity_ids' => ['required', 'array', 'min:1'],
            'entity_ids.*' => ['integer'],
            'disposition' => ['required', 'in:'.implode(',', PlateauEntity::DISPOSITIONS)],
            'replaced_by_id' => ['nullable', 'integer'],
            'target_annual_cost' => ['nullable', 'numeric', 'min:0'],
            'target_cost_currency' => ['nullable', 'string', 'size:3'],
            'one_off_cost' => ['nullable', 'numeric', 'min:0'],
            'confidence' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rationale' => ['nullable', 'string'],
        ]);

        abort_unless(isset(PlateauDiffService::TYPES[$data['entity_type']]), 422, 'That entity type cannot be a plateau member.');

        try {
            $count = $this->plateaux->setDispositions(
                $plateau, $data['entity_type'], $data['entity_ids'], $data['disposition'],
                collect($data)->only(['replaced_by_id', 'target_annual_cost', 'target_cost_currency', 'one_off_cost', 'confidence', 'rationale'])->all()
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$count} record(s) set to '{$data['disposition']}' in {$plateau->name}.");
    }

    public function plateauMemberDestroy(Plateau $plateau, PlateauEntity $member)
    {
        Gate::authorize('update', $plateau);
        abort_unless($member->plateau_id === $plateau->id, 404);

        $member->delete();

        return back()->with('success', 'Removed from the plateau.');
    }

    public function plateauSeed(Request $request, Plateau $plateau)
    {
        Gate::authorize('update', $plateau);

        $data = $request->validate([
            'source_plateau_id' => ['nullable', 'integer', 'exists:ea_plateaux,id'],
            'types' => ['nullable', 'array'],
        ]);

        if (! empty($data['source_plateau_id'])) {
            $source = Plateau::findOrFail($data['source_plateau_id']);
            $count = $this->plateaux->clone($source, $plateau);

            return back()->with('success', "Copied {$count} membership row(s) from {$source->name}.");
        }

        $created = $this->plateaux->seedFromCurrentEstate($plateau, $data['types'] ?? null);

        return back()->with('success', 'Seeded from the live estate: '.
            collect($created)->map(fn ($count, $type) => "{$count} {$type}")->implode(', ').'.');
    }

    /* ==================== WS 4.7 — cost, TCO, debt ==================== */

    public function costModel(Request $request)
    {
        $view = (string) $request->query('view', 'portfolio');

        return Inertia::render('Ea/CostModel', [
            'view' => $view,
            'portfolio' => $this->cost->portfolio(),
            'perCapability' => $view === 'per_capability' ? $this->cost->costPerCapability() : null,
            'perProcess' => $view === 'per_process' ? $this->cost->costPerProcess() : null,
            'candidates' => $view === 'rationalisation' ? $this->cost->rationalisationCandidates() : null,
            'fxRates' => (new \App\Services\Ea\FxExposureService())->rates(),
        ]);
    }

    /* ==================== WS 4.4 — round-trip gate ==================== */

    public function roundTrip(Request $request)
    {
        $result = (new ArchiMateRoundTrip())->run();

        return Inertia::render('Ea/RoundTrip', [
            'result' => $result,
            'elementTypes' => ArchiMateRoundTrip::EXCHANGE_ELEMENT_TYPES,
            'relationshipTypes' => ArchiMateRoundTrip::EXCHANGE_RELATIONSHIP_TYPES,
            'targets' => ['Archi 5.x (archimatetool.com)', 'Sparx Enterprise Architect 16', 'BiZZdesign Horizzon'],
        ]);
    }

    public function roundTripInspect(Request $request)
    {
        Gate::authorize('create', EaApplication::class);

        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        $xml = file_get_contents($request->file('file')->getRealPath());

        try {
            $report = (new ArchiMateRoundTrip())->inspectImport((string) $xml);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not parse that document: '.$e->getMessage());
        }

        return back()->with('import_report', $report)
            ->with('success', "Recognised a {$report['dialect']} document. Nothing has been imported — this is a dry run.");
    }

    /* ==================== WS 4.5 — API and drafts ==================== */

    public function apiConsole()
    {
        $service = new GraphQlService();

        return Inertia::render('Ea/GraphApi', [
            'schema' => $service->schema(),
            'examples' => $service->examples(),
            'endpoint' => route('ea.graphql'),
            'mcpEndpoint' => route('ea.mcp.rpc'),
        ]);
    }

    public function graphql(Request $request)
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:20000'],
            'variables' => ['nullable', 'array'],
        ]);

        return response()->json((new GraphQlService())->execute($data['query'], $data['variables'] ?? []));
    }

    public function drafts()
    {
        return Inertia::render('Ea/DraftChanges', [
            'queue' => $this->drafts->queue(),
            'can' => ['approve' => Gate::allows('approve', EaApplication::class)],
        ]);
    }

    public function draftApprove(Request $request, DraftChange $draft)
    {
        Gate::authorize('approve', EaApplication::class);

        try {
            $this->drafts->approve($draft, $request->input('comment'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Draft {$draft->reference} applied.");
    }

    public function draftReject(Request $request, DraftChange $draft)
    {
        Gate::authorize('approve', EaApplication::class);

        try {
            $this->drafts->reject($draft, $request->input('reason'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Draft {$draft->reference} rejected.");
    }

    /* ==================== §10 — index maintenance ==================== */

    /**
     * Rebuild the materialised indexes.
     *
     * Exposed rather than hidden in a job because a stale index is a data-quality
     * finding an architect must be able to clear, and because §10's provenance
     * gate applies to derived data too.
     */
    public function reindex(Request $request)
    {
        Gate::authorize('update', EaApplication::class);

        $hierarchy = (new HierarchyIndex())->rebuild();
        $links = (new CapabilityLinkIndex())->rebuild();

        return back()->with('success', sprintf(
            'Rebuilt %d closure row(s) and %d application-capability link(s).',
            array_sum($hierarchy), $links
        ));
    }

    /** Index freshness, for the Data Sources screen. */
    public function indexStatus()
    {
        return response()->json([
            'hierarchies' => (new HierarchyIndex())->status(),
            'capability_links' => [
                'rows' => \App\Models\Ea\ApplicationCapability::count(),
                'fresh' => (new CapabilityLinkIndex())->isFresh(),
            ],
        ]);
    }
}
