<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ea\ApplicationRequest;
use App\Http\Requests\Ea\ArbRequest;
use App\Http\Requests\Ea\CapabilityRequest;
use App\Http\Requests\Ea\DpiaRequest;
use App\Http\Requests\Ea\InterfaceRequest;
use App\Http\Requests\Ea\PrincipleRequest;
use App\Http\Requests\Ea\StandardRequest;
use App\Models\Ea\AdmDeliverable;
use App\Models\Ea\AnomalyFinding;
use App\Models\Ea\ArbSubmission;
use App\Models\Ea\AuditLog;
use App\Models\Ea\Capability;
use App\Models\Ea\ConsentPurpose;
use App\Models\Ea\ControlMapping;
use App\Models\Ea\CourseOfAction;
use App\Models\Ea\DataFlow;
use App\Models\Ea\DpiaAssessment;
use App\Models\Ea\Driver;
use App\Models\Ea\EaApi;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\EvidencePack;
use App\Models\Ea\ExchangeJob;
use App\Models\Ea\Exception as EaException;
use App\Models\Ea\FeedRun;
use App\Models\Ea\GlossaryTerm;
use App\Models\Ea\Goal;
use App\Models\Ea\InfoDomain;
use App\Models\Ea\Initiative;
use App\Models\Ea\InitiativeDependency;
use App\Models\Ea\KriDefinition;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\MaturityAssessment;
use App\Models\Ea\MaturityDomain;
use App\Models\Ea\MaturityResponse;
use App\Models\Ea\Outcome;
use App\Models\Ea\Pattern;
use App\Models\Ea\Plateau;
use App\Models\Ea\Principle;
use App\Models\Ea\Process;
use App\Models\Ea\Relationship;
use App\Models\Ea\Solution;
use App\Models\Ea\Stakeholder;
use App\Models\Ea\Standard;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ThreatModel;
use App\Models\Ea\ThreatTechnique;
use App\Models\Ea\ValueStream;
use App\Models\Ea\Zone;
use App\Models\Ea\ZoneAssignment;
use App\Repositories\Ea\EaRepository;
use App\Services\Ea\AnomalyEngine;
use App\Services\Ea\ArchiMateExchange;
use App\Services\Ea\AssetApplicationClient;
use App\Services\Ea\BlastRadiusService;
use App\Services\Ea\BulkImporter;
use App\Services\Ea\ControlInheritanceService;
use App\Services\Ea\CveFeedClient;
use App\Services\Ea\EolFeedClient;
use App\Services\Ea\EvidencePackGenerator;
use App\Services\Ea\GraphResolver;
use App\Services\Ea\KriCalculator;
use App\Services\Ea\McpServer;
use App\Services\Ea\NlSearchService;
use App\Services\Ea\PrincipleImpactEngine;
use App\Services\Ea\QualitySealService;
use App\Services\Ea\ScoreCardService;
use App\Services\Ea\TechObsolescenceService;
use App\Services\Ea\VendorConcentrationService;
use App\Services\Ea\ViewpointGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Enterprise Architecture (EA-Studio) controller — single entry point for
 * every EA page and write action across Phase 1, Phase 2, Phase 3 and the
 * Phase 4 gap-closure work (ATH-GAP-EA-001 v1.0).
 */
class EaController extends Controller
{
    public function __construct(protected EaRepository $repo)
    {
    }

    /* =========================== Phase 1 — Reads =========================== */

    public function commandCentre()
    {
        $kpis = [
            'capabilities' => Capability::count(),
            'applications' => EaApplication::count(),
            'tech_components' => TechComponent::count(),
            'obsolete_tech' => TechComponent::where('obsolescence_flag', true)->count(),
            'info_domains' => InfoDomain::count(),
            'logical_entities' => LogicalEntity::count(),
            'pii_entities' => LogicalEntity::where('pii_flag', true)->count(),
            'value_streams' => ValueStream::count(),
            'open_anomalies' => AnomalyFinding::where('status', 'open')->count(),
            'open_exceptions' => EaException::where('status', 'active')->count(),
            'arb_in_review' => ArbSubmission::where('status', 'in_review')->count(),
            'initiatives_in_flight' => Initiative::where('status', 'in_flight')->count(),
        ];
        $lifecycle = EaApplication::select('lifecycle', DB::raw('count(*) as c'))->groupBy('lifecycle')->pluck('c', 'lifecycle');
        $time = EaApplication::select('time_score', DB::raw('count(*) as c'))->whereNotNull('time_score')->groupBy('time_score')->pluck('c', 'time_score');
        $radar = TechComponent::select('radar_status', DB::raw('count(*) as c'))->groupBy('radar_status')->pluck('c', 'radar_status');
        $eolNext12 = TechComponent::whereNotNull('eol_date')
            ->whereBetween('eol_date', [now(), now()->addYear()])
            ->orderBy('eol_date')->take(10)->get();
        $assessment = MaturityAssessment::orderByDesc('year')->first();
        $kris = KriDefinition::with(['values' => fn ($q) => $q->orderByDesc('recorded_at')->limit(1)])
            ->limit(10)->get();
        return Inertia::render('Ea/CommandCentre', compact('kpis', 'lifecycle', 'time', 'radar', 'eolNext12', 'assessment', 'kris'));
    }

    /**
     * WS 2.5 — the Architecture Completeness Score Card (B5).
     *
     * §3.6: "none of the six products ships an assessment-and-completeness
     * layer … That is an open white space, and it maps almost perfectly onto
     * CBN's maturity-level requirement."
     */
    public function scoreCard()
    {
        Gate::authorize('viewAny', Capability::class);

        $service = new ScoreCardService();
        $grid = $service->compute();

        return Inertia::render('Ea/ScoreCard', $grid + [
            'maturity' => $service->indicativeMaturity($grid['overall']['score']),
            // §7.1's single-graph precondition: a return that cites an entity
            // must be able to resolve it, so the health of cross-module
            // references belongs next to the completeness reading.
            'graphHealth' => (new GraphResolver())->audit(),
        ]);
    }

    public function capabilityMap(Request $r)
    {
        $tree = Capability::orderBy('level')->orderBy('code')->get();
        $byParent = $tree->groupBy('parent_id');
        $counts = ['bian' => $tree->where('source', 'bian')->count(), 'custom' => $tree->where('source', 'custom')->count()];
        $overlay = $r->query('overlay', 'criticality'); // criticality | app_count | maturity | cost | risk
        $overlayMap = $this->capabilityOverlay($tree, $overlay);
        return Inertia::render('Ea/CapabilityMap', compact('tree', 'byParent', 'counts', 'overlay', 'overlayMap') + [
            'options' => [
                'parents' => $tree->map(fn ($c) => ['value' => $c->id, 'label' => "{$c->code} — {$c->name}"])->values(),
                'plateaux' => $this->plateauOptions(),
            ],
            'seals' => (new QualitySealService())->mapFor(Capability::class, $tree->pluck('id')),
            'stewardship' => $this->stewardshipOptions(Capability::class),
        ]);
    }

    public function capabilityShow(Capability $capability)
    {
        $capability->load('children', 'parent', 'plateau');
        $linkedApps = EaApplication::whereJsonContains('capability_ids', $capability->id)->get();
        $relationships = Relationship::query()
            ->where(function ($q) use ($capability) {
                $q->where(function ($r) use ($capability) {
                    $r->where('source_type', Capability::class)->where('source_id', $capability->id);
                })->orWhere(function ($r) use ($capability) {
                    $r->where('target_type', Capability::class)->where('target_id', $capability->id);
                });
            })->get();
        return Inertia::render('Ea/CapabilityShow', compact('capability', 'linkedApps', 'relationships'));
    }

    public function valueStreams()
    {
        $streams = ValueStream::orderBy('code')->get();
        return Inertia::render('Ea/ValueStreams', compact('streams'));
    }

    public function applicationPortfolio(Request $request)
    {
        $filter = $request->query('filter');
        $overlay = $request->query('overlay', 'quadrant');
        $query = EaApplication::query();
        if ($filter === 'critical') $query->where('criticality', 'critical');
        if ($filter === 'eliminate') $query->where('time_score', 'Eliminate');
        $apps = $query->orderByDesc('criticality')->orderBy('name')->get();

        $timeCounts = EaApplication::select('time_score', DB::raw('count(*) as c'))
            ->whereNotNull('time_score')->groupBy('time_score')->pluck('c', 'time_score');
        $sixRCounts = EaApplication::select('six_r_score', DB::raw('count(*) as c'))
            ->whereNotNull('six_r_score')->groupBy('six_r_score')->pluck('c', 'six_r_score');
        $grid = [];
        for ($b = 5; $b >= 1; $b--) {
            for ($t = 1; $t <= 5; $t++) {
                $cell = EaApplication::where('business_fit', $b)->where('technical_fit', $t)->get();
                $cost = (float) $cell->sum('annual_cost_ngn');
                $users = (int) $cell->sum('user_count');
                $grid[] = ['bf' => $b, 'tf' => $t, 'apps' => $cell, 'count' => $cell->count(), 'cost' => $cost, 'users' => $users];
            }
        }
        return Inertia::render('Ea/ApplicationPortfolio', compact('apps', 'timeCounts', 'sixRCounts', 'grid', 'filter', 'overlay') + [
            'options' => $this->applicationFormOptions(),
            // Phase 1 (WS 1.3): "seal indicator on every entity".
            'seals' => (new QualitySealService())->mapFor(EaApplication::class, $apps->pluck('id')),
            'stewardship' => $this->stewardshipOptions(EaApplication::class),
        ]);
    }

    public function applicationShow(EaApplication $application)
    {
        $capabilities = Capability::whereIn('id', $application->capability_ids ?? [])->get();
        $tech = TechComponent::whereJsonContains('application_ids', $application->id)->get();
        $interfaces = EaInterface::where('source_app_id', $application->id)
            ->orWhere('target_app_id', $application->id)->get();
        $zone = ZoneAssignment::where('application_id', $application->id)->with('zone')->first();
        $controls = (new ControlInheritanceService())->resolveForApplication($application->id);
        $threats = ThreatModel::with('techniques')
            ->where('subject_type', EaApplication::class)
            ->where('subject_id', $application->id)
            ->get();
        $impact = (new BlastRadiusService())->changeImpact($application->id, 2);
        return Inertia::render('Ea/ApplicationShow', compact('application', 'capabilities', 'tech', 'interfaces', 'zone', 'controls', 'threats', 'impact'));
    }

    public function technologyRadar()
    {
        $components = TechComponent::with('vulnerabilities')->orderBy('name')->get();
        $byStatus = $components->groupBy('radar_status');
        $eolWindow = $components->filter(fn ($t) => $t->eol_date && $t->eol_date->between(now(), now()->addYear()))->values();
        $debtTop = $components->sortByDesc('tech_debt_score')->take(15)->values();
        return Inertia::render('Ea/TechnologyRadar', compact('components', 'byStatus', 'eolWindow', 'debtTop') + [
            'options' => ['plateaux' => $this->plateauOptions()],
            'seals' => (new QualitySealService())->mapFor(TechComponent::class, $components->pluck('id')),
            'stewardship' => $this->stewardshipOptions(TechComponent::class),
        ]);
    }

    public function infoDomains()
    {
        $domains = InfoDomain::withCount('entities')->orderBy('name')->get();
        return Inertia::render('Ea/InfoDomains', compact('domains'));
    }

    public function logicalEntities()
    {
        $entities = LogicalEntity::with('domain')->orderBy('name')->get();
        $byClassification = $entities->groupBy('classification');
        return Inertia::render('Ea/LogicalEntities', compact('entities', 'byClassification'));
    }

    public function dataFlows()
    {
        $flows = DataFlow::with('source', 'target')->orderBy('name')->get();
        $crossBorder = $flows->where('cross_border', true)->count();
        return Inertia::render('Ea/DataFlows', compact('flows', 'crossBorder'));
    }

    public function cbnMaturity()
    {
        $assessment = MaturityAssessment::orderByDesc('year')->first();
        $domains = MaturityDomain::with('questions')->orderBy('code')->get();
        $responses = $assessment
            ? MaturityResponse::where('assessment_id', $assessment->id)->get()->keyBy('question_id')
            : collect();
        $byDomain = [];
        foreach ($domains as $d) {
            $answered = 0; $sum = 0;
            foreach ($d->questions as $q) {
                $r = $responses->get($q->id);
                if ($r) { $answered++; $sum += $r->selected_level; }
            }
            $byDomain[] = [
                'domain' => $d,
                'answered' => $answered,
                'total' => $d->questions->count(),
                'avg' => $answered ? round($sum / $answered, 2) : 0,
            ];
        }
        $history = MaturityAssessment::orderBy('year')->get(['year', 'framework', 'overall_score']);
        return Inertia::render('Ea/CbnMaturity', compact('assessment', 'domains', 'responses', 'byDomain', 'history'));
    }

    /* =========================== Phase 2 — Reads =========================== */

    public function interfaces(Request $r)
    {
        $items = EaInterface::with('sourceApp', 'targetApp', 'apis')->orderBy('name')->get();
        // CBN RBCF App. II §1.1(i)-(k) requires the objective of every
        // connection to be documented and regularly reviewed. Surface how far
        // the register is from satisfying that so the gap is visible, not
        // buried (ATH-EAR-002 Appendix B).
        $cbn = [
            'total' => $items->count(),
            'with_objective' => $items->filter(fn ($i) => filled($i->objective))->count(),
            'external' => $items->whereIn('counterparty_type', ['regulator', 'switch', 'third_party'])->count(),
            'review_overdue' => $items->filter(fn ($i) => $this->reviewOverdue($i))->count(),
        ];
        return Inertia::render('Ea/Interfaces', [
            'interfaces' => $items,
            'cbn' => $cbn,
            'options' => ['applications' => $this->applicationOptions()],
            'seals' => (new QualitySealService())->mapFor(EaInterface::class, $items->pluck('id')),
            'stewardship' => $this->stewardshipOptions(EaInterface::class),
        ]);
    }

    public function apis()
    {
        $apis = EaApi::with('interface')->orderBy('name')->get();
        return Inertia::render('Ea/Apis', compact('apis'));
    }

    public function blastRadius(Request $r)
    {
        $appId = (int) $r->query('app_id', EaApplication::min('id') ?: 0);
        $depth = (int) min(4, max(1, $r->query('depth', 3)));
        $app = EaApplication::find($appId);
        $impact = $app
            ? (new BlastRadiusService())->changeImpact($appId, $depth)
            : ['radius' => ['nodes' => [], 'edges' => [], 'by_type' => [], 'levels' => []], 'summary' => []];
        $applications = EaApplication::orderBy('name')->get(['id', 'name', 'criticality']);
        return Inertia::render('Ea/BlastRadius', compact('app', 'impact', 'applications', 'depth'));
    }

    public function securityZones()
    {
        $zones = Zone::with('assignments.application')->orderBy('trust_level')->orderBy('code')->get();
        $assignments = ZoneAssignment::with('zone', 'application')->get();
        return Inertia::render('Ea/SecurityZones', compact('zones', 'assignments'));
    }

    public function controlMappings(Request $r)
    {
        $framework = $r->query('framework', 'CBN');
        $mappings = ControlMapping::query()->when($framework !== '*', fn ($q) => $q->where('framework', $framework))->get();
        $coverage = $mappings->groupBy('coverage')->map->count();
        $portfolioCoverage = (new ControlInheritanceService())->portfolioCoverageReport($framework);
        return Inertia::render('Ea/ControlMappings', compact('mappings', 'coverage', 'portfolioCoverage', 'framework'));
    }

    public function processes()
    {
        $procs = Process::orderBy('level')->orderBy('code')->get();
        $byLevel = $procs->groupBy('level');
        return Inertia::render('Ea/Processes', compact('procs', 'byLevel'));
    }

    public function principles()
    {
        $principles = Principle::orderBy('code')->get();
        return Inertia::render('Ea/Principles', compact('principles'));
    }

    public function standards()
    {
        $standards = Standard::orderBy('category')->orderBy('code')->get();
        return Inertia::render('Ea/Standards', compact('standards'));
    }

    public function arb()
    {
        $submissions = ArbSubmission::orderByDesc('created_at')->get();
        $buckets = $submissions->groupBy('status')->map->count();
        return Inertia::render('Ea/Arb', compact('submissions', 'buckets'));
    }

    public function arbShow(ArbSubmission $submission)
    {
        $principleImpact = (new PrincipleImpactEngine())->analyse($submission->title, $submission->summary ?? '', $submission->impact_blast_radius ?? []);
        return Inertia::render('Ea/ArbShow', compact('submission', 'principleImpact'));
    }

    public function arbWizard()
    {
        $apps = EaApplication::orderBy('name')->get(['id', 'name', 'criticality']);
        $standards = Standard::get(['id', 'code', 'name']);
        $principles = Principle::get(['id', 'code', 'name']);
        $plateaux = Plateau::orderBy('plateau_type')->get();
        return Inertia::render('Ea/ArbWizard', compact('apps', 'standards', 'principles', 'plateaux'));
    }

    public function exceptions()
    {
        $exceptions = EaException::with('standard', 'principle')->orderByDesc('expires_at')->get();
        $expiringSoon = $exceptions->filter(fn ($e) => $e->expires_at && $e->expires_at->between(now(), now()->addDays(90)))->count();
        $expired = $exceptions->filter(fn ($e) => $e->expires_at && $e->expires_at->isPast() && $e->status === 'active')->count();
        return Inertia::render('Ea/Exceptions', compact('exceptions', 'expiringSoon', 'expired') + [
            'options' => [
                'standards' => Standard::orderBy('code')->get()->map(fn ($x) => ['value' => $x->id, 'label' => "{$x->code} — {$x->name}"])->values(),
                'principles' => Principle::orderBy('code')->get()->map(fn ($x) => ['value' => $x->id, 'label' => "{$x->code} — {$x->name}"])->values(),
            ],
        ]);
    }

    public function vendorConcentration()
    {
        $rows = (new VendorConcentrationService())->compute(20);
        $totals = [
            'total_apps' => array_sum(array_column($rows, 'apps')),
            'total_spend' => array_sum(array_column($rows, 'annual_spend_ngn')),
            'top_share' => !empty($rows) ? round(($rows[0]['apps'] ?? 0) / max(EaApplication::count(), 1) * 100, 1) : 0,
        ];
        return Inertia::render('Ea/VendorConcentration', ['rows' => $rows, 'totals' => $totals]);
    }

    /* =========================== Phase 3 — Reads =========================== */

    public function plateaux()
    {
        $plateaux = Plateau::orderBy('effective_from')->get();
        $initiatives = Initiative::with('plateau')->orderBy('start_date')->get();
        return Inertia::render('Ea/Plateaux', compact('plateaux', 'initiatives'));
    }

    public function initiatives()
    {
        $initiatives = Initiative::with('plateau', 'deliverables')->orderByDesc('start_date')->get();
        $byStatus = $initiatives->groupBy('status')->map->count();
        $byPhase = $initiatives->groupBy('adm_phase')->map->count();
        $dependencies = InitiativeDependency::with('predecessor', 'successor')->get();
        return Inertia::render('Ea/Initiatives', compact('initiatives', 'byStatus', 'byPhase', 'dependencies') + [
            'options' => [
                'initiatives' => $initiatives->map(fn ($i) => ['value' => $i->id, 'label' => "{$i->code} — {$i->name}"])->values(),
                'phases' => ['Pre', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'Req'],
            ],
        ]);
    }

    public function roadmap()
    {
        $initiatives = Initiative::with('plateau')->orderBy('start_date')->get();
        $plateaux = Plateau::orderBy('effective_from')->get();
        $dependencies = InitiativeDependency::with('predecessor', 'successor')->get();
        return Inertia::render('Ea/Roadmap', compact('initiatives', 'plateaux', 'dependencies'));
    }

    public function admTracker()
    {
        $initiatives = Initiative::with('deliverables')->orderBy('adm_phase')->orderBy('name')->get();
        $byPhase = $initiatives->groupBy('adm_phase')->map->count();
        $phases = ['Pre', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'Req'];
        return Inertia::render('Ea/AdmTracker', compact('initiatives', 'byPhase', 'phases'));
    }

    public function patterns()
    {
        $patterns = Pattern::orderBy('category')->orderBy('name')->get();
        return Inertia::render('Ea/Patterns', compact('patterns'));
    }

    public function solutions()
    {
        $solutions = Solution::with('pattern', 'initiative')->orderBy('name')->get();
        return Inertia::render('Ea/Solutions', compact('solutions'));
    }

    public function kri()
    {
        $definitions = KriDefinition::with(['values' => fn ($q) => $q->orderByDesc('recorded_at')->limit(12)])->get();
        return Inertia::render('Ea/Kri', compact('definitions'));
    }

    public function kriRecompute()
    {
        Gate::authorize('update', KriDefinition::class);
        $results = (new KriCalculator())->compute();
        return back()->with('success', count($results).' KRIs recomputed.');
    }

    public function exchange()
    {
        $jobs = ExchangeJob::orderByDesc('created_at')->take(50)->get();
        return Inertia::render('Ea/Exchange', compact('jobs'));
    }

    public function exchangeQueue(Request $r)
    {
        Gate::authorize('create', ExchangeJob::class);
        $type = $r->input('type', 'export');
        $exchange = new ArchiMateExchange();
        if ($type === 'export') {
            $rel = 'exchange/ea-export-'.now()->format('YmdHis').'.xml';
            $result = $exchange->exportToDisk($rel);
            ExchangeJob::create([
                'type' => 'export',
                'format' => 'archimate-oef-3.2',
                'status' => 'completed',
                'element_count' => $result['element_count'],
                'relationship_count' => $result['relationship_count'],
                'file_path' => $result['file_path'],
                'message' => "Exported {$result['element_count']} elements and {$result['relationship_count']} relationships. SHA-256: ".substr($result['sha256'], 0, 12).'…',
            ]);
            return redirect()->route('ea.exchange')->with('success', 'ArchiMate export job completed.');
        }
        if ($type === 'import' && $r->hasFile('file')) {
            $contents = file_get_contents($r->file('file')->getRealPath());
            $parsed = $exchange->parse($contents);
            ExchangeJob::create([
                'type' => 'import',
                'format' => 'archimate-oef-3.2',
                'status' => 'completed',
                'element_count' => count($parsed['elements']),
                'relationship_count' => count($parsed['relationships']),
                'file_path' => null,
                'message' => 'Parsed '.count($parsed['elements']).' elements + '.count($parsed['relationships']).' relationships from upload.',
            ]);
            return redirect()->route('ea.exchange')->with('success', 'ArchiMate XML parsed successfully.');
        }
        return back()->withErrors(['file' => 'Provide an XML file to import or use type=export.']);
    }

    public function exchangeDownload(ExchangeJob $job)
    {
        if (! $job->file_path || ! Storage::exists($job->file_path)) {
            return back()->withErrors(['file' => 'Export artefact no longer available.']);
        }
        return Storage::download($job->file_path);
    }

    public function evidencePacks()
    {
        $packs = EvidencePack::with('assessment')->orderByDesc('generated_at')->get();
        return Inertia::render('Ea/EvidencePacks', compact('packs'));
    }

    public function evidencePackGenerate(Request $r)
    {
        Gate::authorize('export', EvidencePack::class);
        $pack = (new EvidencePackGenerator())->generate(
            $r->input('assessment_id') ? (int) $r->input('assessment_id') : null,
            $r->input('signed_by') ?: optional($r->user())->email
        );
        // Contract I-10 (§7.3) — register in the Evidence Vault so an auditor
        // finds the pack where they look, rather than on local disk.
        \App\Events\Ea\EvidencePackGenerated::dispatch($pack);

        return redirect()->route('ea.evidence-packs')->with('success', "Evidence pack {$pack->code} generated. SHA-256: ".substr($pack->pdf_hash ?? '', 0, 12).'…');
    }

    public function evidencePackDownload(EvidencePack $pack, string $kind = 'pdf')
    {
        $path = $kind === 'zip' ? $pack->zip_path : $pack->pdf_path;
        if (! $path || ! Storage::exists($path)) {
            return back()->withErrors(['file' => 'Artefact not yet generated.']);
        }
        return Storage::download($path);
    }

    /* =========================== Phase 4 — Viewpoints ===========================
     |
     | The diagram editor and its persistence moved to
     | {@see \App\Http\Controllers\Ea\DepthController} in Phase 4 WS 4.1: a
     | canvas that validates against ArchiMate, versions every save and exports
     | to SVG/PNG/PDF is a different job from listing catalogue rows. Generated
     | viewpoints stay here — they are reads over the repository, not documents.
     */

    public function viewpoint(string $viewpoint)
    {
        $payload = (new ViewpointGenerator())->generate($viewpoint);
        return Inertia::render('Ea/Viewpoint', compact('viewpoint', 'payload'));
    }

    public function viewpointJson(string $viewpoint)
    {
        return response()->json((new ViewpointGenerator())->generate($viewpoint));
    }

    /* =========================== Phase 4 — DPIA / Glossary / Motivation =========================== */

    public function dpia()
    {
        $assessments = DpiaAssessment::with('dataFlow', 'logicalEntity')->orderByDesc('created_at')->get();
        $consent = ConsentPurpose::orderBy('purpose')->get();
        $crossBorder = DataFlow::where('cross_border', true)->count();
        $approved = $assessments->where('status', 'approved')->count();
        $flows = DataFlow::orderBy('name')->get(['id', 'name', 'cross_border']);
        $entities = LogicalEntity::orderBy('name')->get(['id', 'name', 'pii_flag']);
        return Inertia::render('Ea/Dpia', compact('assessments', 'consent', 'crossBorder', 'approved', 'flows', 'entities'));
    }

    public function dpiaStore(DpiaRequest $r)
    {
        Gate::authorize('create', DpiaAssessment::class);
        $attrs = $r->validated();
        $attrs['code'] = $attrs['code'] ?? 'DPIA-'.now()->format('YmdHis');
        $attrs['risk_band'] = $attrs['risk_band'] ?? $this->dpiaBand($attrs['answers'] ?? []);
        $attrs['risk_score'] = $attrs['risk_score'] ?? $this->dpiaScore($attrs['answers'] ?? []);
        $assessment = $this->repo->create(DpiaAssessment::class, $attrs);
        return redirect()->route('ea.dpia')->with('success', "DPIA {$assessment->code} created.");
    }

    public function glossary()
    {
        $terms = GlossaryTerm::orderBy('term')->get();
        $byCategory = $terms->groupBy('category')->map->count();
        return Inertia::render('Ea/Glossary', compact('terms', 'byCategory'));
    }

    public function glossaryStore(Request $r)
    {
        Gate::authorize('create', GlossaryTerm::class);
        $data = $r->validate([
            'term' => 'required|string|max:128',
            'definition' => 'required|string',
            'category' => 'nullable|string|max:32',
            'synonyms' => 'nullable|string',
            'owner_role' => 'nullable|string|max:64',
        ]);
        $term = $this->repo->create(GlossaryTerm::class, $data);
        return back()->with('success', "Glossary term '{$term->term}' added.");
    }

    public function motivation()
    {
        $goals = Goal::with('outcomes')->orderBy('code')->get();
        $drivers = Driver::orderBy('code')->get();
        $stakeholders = Stakeholder::orderBy('code')->get();
        $coa = CourseOfAction::with('initiative')->orderBy('code')->get();
        return Inertia::render('Ea/Motivation', compact('goals', 'drivers', 'stakeholders', 'coa'));
    }

    public function motivationStore(Request $r)
    {
        Gate::authorize('create', Driver::class);
        $kind = $r->input('kind');
        $modelClass = match ($kind) {
            'goal' => Goal::class,
            'driver' => Driver::class,
            'stakeholder' => Stakeholder::class,
            'outcome' => Outcome::class,
            'course-of-action' => CourseOfAction::class,
            default => null,
        };
        if (! $modelClass) return back()->withErrors(['kind' => 'Unknown motivation element kind.']);
        $data = $r->validate([
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|string|max:16',
            'role' => 'nullable|string|max:128',
            'goal_id' => 'nullable|integer|exists:ea_goals,id',
            'initiative_id' => 'nullable|integer|exists:ea_initiatives,id',
        ]);
        $this->repo->create($modelClass, $data);
        return back()->with('success', ucfirst($kind).' created.');
    }

    /* =========================== Phase 4 — Threats / Anomalies =========================== */

    public function threats()
    {
        $models = ThreatModel::with('techniques')->orderByDesc('updated_at')->get();
        $byMethodology = $models->groupBy('methodology')->map->count();
        return Inertia::render('Ea/Threats', compact('models', 'byMethodology') + [
            'options' => ['applications' => $this->applicationOptions()],
        ]);
    }

    public function threatStore(Request $r)
    {
        Gate::authorize('create', ThreatModel::class);
        $data = $r->validate([
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:255',
            'subject_type' => 'required|string|max:64',
            'subject_id' => 'required|integer',
            'methodology' => 'nullable|in:STRIDE,LINDDUN,PASTA',
            'summary' => 'nullable',
        ]);
        $model = $this->repo->create(ThreatModel::class, $data);
        return back()->with('success', "Threat model {$model->code} created.");
    }

    public function threatTechniqueStore(Request $r, ThreatModel $model)
    {
        Gate::authorize('create', ThreatTechnique::class);
        $data = $r->validate([
            'category' => 'required|string|max:32',
            'mitre_attack' => 'nullable|string|max:16',
            'threat' => 'required|string|max:255',
            'description' => 'nullable|string',
            'likelihood' => 'nullable|integer|between:1,5',
            'impact' => 'nullable|integer|between:1,5',
            'residual' => 'nullable|integer|between:0,5',
            'mitigation' => 'nullable|string',
        ]);
        $data['model_id'] = $model->id;
        $this->repo->create(ThreatTechnique::class, $data);
        return back()->with('success', 'Threat technique added.');
    }

    public function anomalies()
    {
        $findings = AnomalyFinding::orderByDesc('last_seen_at')->limit(500)->get();
        $byStatus = $findings->groupBy('status')->map->count();
        $bySeverity = $findings->groupBy('severity')->map->count();
        $byRule = $findings->groupBy('rule_code')->map->count();
        return Inertia::render('Ea/Anomalies', compact('findings', 'byStatus', 'bySeverity', 'byRule'));
    }

    public function anomaliesRun()
    {
        Gate::authorize('update', AnomalyFinding::class);
        $r = (new AnomalyEngine())->run();
        return back()->with('success', $r['created'].' new anomaly findings recorded.');
    }

    public function anomalyAck(AnomalyFinding $finding)
    {
        Gate::authorize('update', $finding);
        $finding->update(['status' => 'acknowledged', 'acknowledged_by' => optional(request()->user())->id]);
        return back()->with('success', 'Finding acknowledged.');
    }

    public function anomalyResolve(AnomalyFinding $finding)
    {
        Gate::authorize('update', $finding);
        $finding->update(['status' => 'resolved']);
        return back()->with('success', 'Finding resolved.');
    }

    /* =========================== Phase 4 — Search / MCP =========================== */

    public function search(Request $r)
    {
        $query = (string) $r->query('q', '');
        $result = $query ? (new NlSearchService())->search($query, 50) : ['hits' => [], 'intents' => [], 'count' => 0, 'query' => ''];
        return Inertia::render('Ea/NlSearch', compact('result', 'query'));
    }

    public function searchJson(Request $r)
    {
        return response()->json((new NlSearchService())->search((string) $r->query('q', ''), 25));
    }

    public function mcpInfo()
    {
        $tools = (new McpServer())->tools();
        return Inertia::render('Ea/Mcp', ['tools' => $tools, 'endpoint' => route('ea.mcp.rpc')]);
    }

    public function mcpRpc(Request $r)
    {
        Gate::authorize('create', EaApplication::class);
        return response()->json((new McpServer())->handle($r->all()));
    }

    /* =========================== Phase 4 — Dependencies / ADM =========================== */

    /**
     * §5.1 killed the Scenarios page: "a 93 LOC read-only comparer with no
     * authoring. Shipping a 'Scenario Compare' that cannot create a scenario
     * invites the comparison to Ardoq we lose. Delete the page; keep
     * `ScenarioComparer`. Rebuild in Phase 4 as **Plateau diff**."
     *
     * WS 4.3 built that: `ea.plateau-diff` plus the membership authoring screen.
     * The route survives as a redirect so an existing bookmark lands on the
     * replacement instead of a 404.
     */
    public function scenarios()
    {
        return redirect()->route('ea.plateau-diff', request()->only(['a', 'b']) ?: []);
    }

    public function initiativeDependencyStore(Request $r)
    {
        Gate::authorize('create', InitiativeDependency::class);
        $data = $r->validate([
            'predecessor_id' => 'required|integer|different:successor_id|exists:ea_initiatives,id',
            'successor_id' => 'required|integer|exists:ea_initiatives,id',
            'type' => 'nullable|in:finish_to_start,start_to_start,finish_to_finish,start_to_finish',
            'lag_days' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);
        $this->repo->create(InitiativeDependency::class, $data);
        return back()->with('success', 'Initiative dependency added.');
    }

    public function admDeliverableStore(Request $r, Initiative $initiative)
    {
        Gate::authorize('create', AdmDeliverable::class);
        $data = $r->validate([
            'phase' => 'required|string|max:4',
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:255',
            'status' => 'nullable|in:not_started,in_progress,draft,approved',
            'due_date' => 'nullable|date',
        ]);
        $data['initiative_id'] = $initiative->id;
        $this->repo->create(AdmDeliverable::class, $data);
        return back()->with('success', 'ADM deliverable added.');
    }

    public function admDeliverableUpdate(Request $r, AdmDeliverable $deliverable)
    {
        Gate::authorize('update', $deliverable);
        $data = $r->validate([
            'status' => 'required|in:not_started,in_progress,draft,approved',
            'due_date' => 'nullable|date',
        ]);
        $this->repo->update($deliverable, $data);
        return back()->with('success', 'Deliverable updated.');
    }

    /* =========================== Phase 1/2/3 — Writes =========================== */

    public function capabilityStore(CapabilityRequest $r)
    {
        Gate::authorize('create', Capability::class);
        $c = $this->repo->create(Capability::class, $r->validated());
        return redirect()->route('ea.capabilities.show', $c->id)->with('success', "Capability {$c->code} created.");
    }

    public function capabilityUpdate(CapabilityRequest $r, Capability $capability)
    {
        Gate::authorize('update', $capability);
        $this->repo->update($capability, $r->validated());
        return back()->with('success', 'Capability updated.');
    }

    public function capabilityDestroy(Capability $capability)
    {
        Gate::authorize('delete', $capability);

        // Descendant guard (ATH-EAR-002 Appendix A #6). Deleting a parent
        // would orphan its subtree and silently break every capability-map
        // rollup that walks byParent.
        $children = Capability::where('parent_id', $capability->id)->count();
        $linkedApps = EaApplication::whereJsonContains('capability_ids', $capability->id)->count();
        if ($children || $linkedApps) {
            return back()->with('error', $this->blockedMessage('capability', [
                $children ? "{$children} child capability(ies)" : null,
                $linkedApps ? "{$linkedApps} linked application(s)" : null,
            ]));
        }

        $this->repo->delete($capability);
        return redirect()->route('ea.capabilities')->with('success', 'Capability deleted.');
    }

    public function applicationStore(ApplicationRequest $r)
    {
        Gate::authorize('create', EaApplication::class);
        $a = $this->repo->create(EaApplication::class, $r->validated());
        return redirect()->route('ea.applications.show', $a->id)->with('success', "Application {$a->code} created.");
    }

    public function applicationUpdate(ApplicationRequest $r, EaApplication $application)
    {
        Gate::authorize('update', $application);
        $this->repo->update($application, $r->validated());
        return back()->with('success', 'Application updated.');
    }

    public function applicationDestroy(EaApplication $application)
    {
        Gate::authorize('delete', $application);

        // Dependency guard (ATH-EAR-002 Appendix A #3).
        $interfaces = EaInterface::where('source_app_id', $application->id)
            ->orWhere('target_app_id', $application->id)->count();
        $zones = ZoneAssignment::where('application_id', $application->id)->count();
        if ($interfaces || $zones) {
            return back()->with('error', $this->blockedMessage('application', [
                $interfaces ? "{$interfaces} interface(s) reference it as source or target" : null,
                $zones ? "{$zones} security-zone assignment(s)" : null,
            ]));
        }

        $this->repo->delete($application);
        return redirect()->route('ea.applications')->with('success', 'Application deleted.');
    }

    public function techStore(\App\Http\Requests\Ea\TechComponentRequest $r)
    {
        Gate::authorize('create', TechComponent::class);
        $t = $this->repo->create(TechComponent::class, $r->validated());
        return back()->with('success', "Tech component {$t->code} created.");
    }

    public function techUpdate(\App\Http\Requests\Ea\TechComponentRequest $r, TechComponent $tech)
    {
        Gate::authorize('update', $tech);
        $this->repo->update($tech, $r->validated());
        return back()->with('success', 'Tech component updated.');
    }

    public function techDestroy(TechComponent $tech)
    {
        Gate::authorize('delete', $tech);

        // Usage guard (ATH-EAR-002 Appendix A #9). A component still deployed
        // on applications, or carrying open CVEs, must not vanish from the
        // obsolescence and vulnerability picture without an explicit decision.
        $apps = count($tech->application_ids ?? []);
        $vulns = $tech->vulnerabilities()->where('status', 'open')->count();
        if ($apps || $vulns) {
            return back()->with('error', $this->blockedMessage('technology component', [
                $apps ? "{$apps} application(s) run on it" : null,
                $vulns ? "{$vulns} open CVE(s) are recorded against it" : null,
            ]));
        }

        $this->repo->delete($tech);
        return back()->with('success', 'Tech component deleted.');
    }

    public function interfaceStore(InterfaceRequest $r)
    {
        Gate::authorize('create', EaInterface::class);
        $this->repo->create(EaInterface::class, $r->validated());
        return back()->with('success', 'Interface created.');
    }

    public function interfaceUpdate(InterfaceRequest $r, EaInterface $interface)
    {
        Gate::authorize('update', $interface);
        $this->repo->update($interface, $r->validated());
        return back()->with('success', 'Interface updated.');
    }

    public function interfaceDestroy(EaInterface $interface)
    {
        Gate::authorize('delete', $interface);

        $apis = $interface->apis()->count();
        if ($apis) {
            return back()->with('error', $this->blockedMessage('interface', [
                "{$apis} registered API(s) are published over it",
            ]));
        }

        $this->repo->delete($interface);
        return back()->with('success', 'Interface deleted.');
    }

    public function principleStore(PrincipleRequest $r)
    {
        Gate::authorize('create', Principle::class);
        $this->repo->create(Principle::class, $r->validated());
        return back()->with('success', 'Principle created.');
    }

    public function principleUpdate(PrincipleRequest $r, Principle $principle)
    {
        Gate::authorize('update', $principle);
        $this->repo->update($principle, $r->validated());
        return back()->with('success', 'Principle updated.');
    }

    public function principleDestroy(Principle $principle)
    {
        Gate::authorize('delete', $principle);

        // Appendix A #15 specifies "Retire (soft)", not a hard delete. A
        // principle cited by a past ARB decision or an open exception must
        // remain resolvable, otherwise the governance audit trail breaks.
        $exceptions = EaException::where('principle_id', $principle->id)->count();
        if ($exceptions) {
            $this->repo->update($principle, ['status' => 'retired']);
            return back()->with('success', "Principle {$principle->code} retired ({$exceptions} exception(s) still cite it, so the record is preserved).");
        }

        $this->repo->delete($principle);
        return back()->with('success', 'Principle deleted.');
    }

    public function standardStore(StandardRequest $r)
    {
        Gate::authorize('create', Standard::class);
        $this->repo->create(Standard::class, $r->validated());
        return back()->with('success', 'Standard created.');
    }

    public function standardUpdate(StandardRequest $r, Standard $standard)
    {
        Gate::authorize('update', $standard);
        $this->repo->update($standard, $r->validated());
        return back()->with('success', 'Standard updated.');
    }

    public function standardDestroy(Standard $standard)
    {
        Gate::authorize('delete', $standard);

        // Appendix A #18 — "Retire (soft)". Same reasoning as principles: a
        // waiver is meaningless if the standard it waives disappears.
        $exceptions = EaException::where('standard_id', $standard->id)->count();
        if ($exceptions) {
            $this->repo->update($standard, ['status' => 'retired']);
            return back()->with('success', "Standard {$standard->code} retired ({$exceptions} exception(s) still cite it, so the record is preserved).");
        }

        $this->repo->delete($standard);
        return back()->with('success', 'Standard deleted.');
    }

    public function arbStore(ArbRequest $r)
    {
        Gate::authorize('create', ArbSubmission::class);
        $data = $r->validated();
        $data['code'] = $data['code'] ?? 'ARB-'.now()->format('YmdHis');
        $data['status'] = 'submitted';
        $impact = (new PrincipleImpactEngine())->analyse($data['title'], $data['summary'] ?? '', $data['impact_blast_radius'] ?? []);
        $data['impacted_principles'] = $data['impacted_principles'] ?? $impact['impacted_principles']->pluck('principle.id')->all();
        $data['impacted_standards'] = $data['impacted_standards'] ?? $impact['impacted_standards']->pluck('standard.id')->all();
        $data['risk_band'] = $data['risk_band'] ?? $impact['risk_band'];
        $sub = $this->repo->create(ArbSubmission::class, $data);
        return redirect()->route('ea.arb.show', $sub->id)->with('success', "ARB submission {$sub->code} created.");
    }

    public function arbDecide(Request $r, ArbSubmission $submission)
    {
        Gate::authorize('approve', $submission);
        $data = $r->validate([
            'decision' => 'required|in:approved,rejected,deferred',
            'decision_rationale' => 'nullable|string',
            'votes' => 'nullable|array',
            'minutes' => 'nullable|string',
            'conditions' => 'nullable|array',
            'conditions.*.text' => 'nullable|string',
            'conditions.*.owner_id' => 'nullable|integer|exists:users,id',
            'conditions.*.due_date' => 'nullable|date',
        ]);
        $this->repo->update($submission, [
            'status' => $data['decision'],
            'decided_at' => now(),
            'decided_by' => optional($r->user())->name,
            'decision_rationale' => $data['decision_rationale'] ?? null,
            'votes' => $data['votes'] ?? [],
            'minutes' => $data['minutes'] ?? null,
            'digital_signature' => hash('sha256', $submission->code.optional($r->user())->id.now()->timestamp),
        ]);
        // Contract I-9 (§7.3) — conditions attached to an approval become owned
        // Issues with due dates, rather than staying in the minutes.
        \App\Events\Ea\ArbDecisionRecorded::dispatch(
            $submission->refresh(),
            (array) $r->input('conditions', []),
        );

        return back()->with('success', "ARB submission marked {$data['decision']}.");
    }

    public function exceptionStore(Request $r)
    {
        Gate::authorize('create', EaException::class);
        $data = $r->validate([
            'code' => 'nullable|string|max:32',
            'subject' => 'required|string|max:255',
            'justification' => 'nullable|string',
            'compensating_controls' => 'nullable|string',
            'standard_id' => 'nullable|integer|exists:ea_standards,id',
            'principle_id' => 'nullable|integer|exists:ea_principles,id',
            'effective_from' => 'nullable|date',
            'expires_at' => 'nullable|date|after:effective_from',
            'risk_band' => 'nullable|in:low,medium,high,critical',
        ]);
        $data['code'] = $data['code'] ?? 'EXC-'.now()->format('YmdHis');
        $data['status'] = 'active';
        $this->repo->create(EaException::class, $data);
        return back()->with('success', "Exception {$data['code']} created.");
    }

    public function exceptionRenew(EaException $exception)
    {
        Gate::authorize('approve', $exception);
        $newExp = ($exception->expires_at ?? now())->copy()->addYear();
        $this->repo->update($exception, ['expires_at' => $newExp, 'status' => 'renewed']);
        return back()->with('success', "Exception {$exception->code} renewed until {$newExp->toDateString()}.");
    }

    /* =========================== Bulk Import / Templates =========================== */

    public function bulkImport(Request $r)
    {
        Gate::authorize('create', EaApplication::class);
        $r->validate([
            'type' => 'required|in:capability,application,tech-component,info-domain,logical-entity,data-flow,interface,principle,standard',
            'csv' => 'required|file',
        ]);
        $csv = file_get_contents($r->file('csv')->getRealPath());
        $result = (new BulkImporter())->import($r->input('type'), $csv);
        return back()->with('success', "Imported: {$result['created']} created, {$result['updated']} updated, ".count($result['errors']).' errors.');
    }

    public function bulkTemplate(string $type)
    {
        $body = (new BulkImporter())->template($type);
        return response($body, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="ea-'.$type.'-template.csv"']);
    }

    public function bulkImportPage()
    {
        $templates = array_keys(BulkImporter::TEMPLATES);
        return Inertia::render('Ea/BulkImport', compact('templates'));
    }

    /* =========================== Data Sources (WS 0.4) =========================== */

    /**
     * The single screen for every inbound path into the repository.
     *
     * ATH-EAR-002 §2.4 (RC-4): the three sync actions existed but were
     * unreachable from the UI, so even the manual ingestion path was closed;
     * and both feed clients fall back to bundled fixtures *silently*. §10 makes
     * provenance labelling a release gate — this page is where that label
     * lives, alongside the last-successful-fetch timestamp for each feed.
     */
    public function dataSources()
    {
        $latest = FeedRun::latestByFeed();

        $feeds = [
            [
                'key' => 'assets',
                'name' => 'Asset catalogue',
                'description' => 'Reads the platform Assets module and reconciles it into the EA application catalogue. One-way today; scheduled reconciliation is contract I-3 in Phase 2.',
                'endpoint' => 'internal: assets table',
                'route' => 'ea.sync.assets',
                'last' => $latest['assets'],
                'last_successful' => FeedRun::lastSuccessful('assets'),
            ],
            [
                'key' => 'eol',
                'name' => 'End-of-life / end-of-support feed',
                'description' => 'endoflife.date lifecycle dates for technology components. Falls back to a bundled ~17-product fixture when the host has no outbound access.',
                'endpoint' => EolFeedClient::BASE_URL,
                'route' => 'ea.sync.eol',
                'last' => $latest['eol'],
                'last_successful' => FeedRun::lastSuccessful('eol'),
            ],
            [
                'key' => 'cve',
                'name' => 'CVE / vulnerability feed',
                'description' => 'NVD 2.0 CVE records matched to component CPEs. Falls back to a bundled 7-CVE fixture when the host has no outbound access.',
                'endpoint' => CveFeedClient::ENDPOINT,
                'route' => 'ea.sync.cve',
                'last' => $latest['cve'],
                'last_successful' => FeedRun::lastSuccessful('cve'),
            ],
        ];

        $history = FeedRun::orderByDesc('ran_at')->limit(50)->get();

        $coverage = [
            'applications' => EaApplication::count(),
            'applications_from_assets' => EaApplication::whereNotNull('asset_id')->count(),
            'tech_components' => TechComponent::count(),
            'tech_with_eol' => TechComponent::whereNotNull('eol_date')->count(),
            'tech_with_cpe' => TechComponent::whereNotNull('cpe')->count(),
        ];

        return Inertia::render('Ea/DataSources', [
            'feeds' => $feeds,
            'history' => $history,
            'coverage' => $coverage,
            'templates' => array_keys(BulkImporter::TEMPLATES),
        ]);
    }

    /* =========================== Asset / EOL / CVE syncs =========================== */

    public function assetSync()
    {
        Gate::authorize('admin', EaApplication::class);
        $r = (new AssetApplicationClient())->syncIntoEa();

        // The asset client reads a local table, so "live" means the circuit
        // breaker was closed and the table was actually readable.
        $provenance = ($r['circuit'] ?? 'closed') === 'closed' ? 'live' : 'failed';
        $this->recordFeedRun('assets', $provenance, 'internal: assets table',
            ($r['created'] ?? 0) + ($r['updated'] ?? 0),
            ($r['created'] ?? 0) + ($r['updated'] ?? 0),
            "{$r['created']} created, {$r['updated']} updated.");

        return back()->with('success', "Asset sync: {$r['created']} created, {$r['updated']} updated. Circuit: {$r['circuit']}.");
    }

    public function eolSync()
    {
        Gate::authorize('admin', TechComponent::class);
        $r = (new EolFeedClient())->sync();
        (new TechObsolescenceService())->recompute();

        $this->recordFeedRun('eol', $r['provenance'], $r['endpoint'], $r['touched'], $r['updated'],
            "{$r['live_hits']} live match(es), {$r['fixture_hits']} fixture match(es).");

        return back()->with('success',
            "EOL feed sync: {$r['touched']} components scanned, {$r['updated']} updated. Source: "
            .$this->provenanceWord($r['provenance']).'.');
    }

    public function cveSync()
    {
        Gate::authorize('admin', TechComponent::class);
        $r = (new CveFeedClient())->sync();

        $this->recordFeedRun('cve', $r['provenance'], $r['endpoint'], $r['touched'], $r['created'],
            "{$r['live_hits']} live match(es), {$r['fixture_hits']} fixture match(es).");

        return back()->with('success',
            "CVE feed sync: {$r['touched']} components scanned, {$r['created']} CVEs created. Source: "
            .$this->provenanceWord($r['provenance']).'.');
    }

    /**
     * Persist provenance for a feed execution. R7 in §12.1 rates an unlabelled
     * fixture presented as live data a high-impact credibility risk, so the
     * flash message says which source answered and the Data Sources screen
     * keeps the history.
     */
    protected function recordFeedRun(string $feed, string $provenance, ?string $endpoint, int $touched, int $written, ?string $message = null): void
    {
        FeedRun::create([
            'feed' => $feed,
            'provenance' => $provenance,
            'endpoint' => $endpoint,
            'records_touched' => $touched,
            'records_written' => $written,
            'message' => $message,
            'triggered_by' => optional(request()->user())->name,
            'ran_at' => now(),
        ]);
    }

    protected function provenanceWord(string $provenance): string
    {
        return match ($provenance) {
            'live' => 'live feed',
            'fixture' => 'BUNDLED FIXTURE (no outbound connectivity — this is not live data)',
            'mixed' => 'partially live (some records came from the bundled fixture)',
            default => 'no data returned',
        };
    }

    /* =========================== Audit Trail =========================== */

    public function auditTrail(Request $r)
    {
        $entity = $r->query('entity_type');
        $logs = AuditLog::query()
            ->when($entity, fn ($q) => $q->where('entity_type', $entity))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
        $byAction = $logs->groupBy('action')->map->count();
        return Inertia::render('Ea/AuditTrail', compact('logs', 'byAction', 'entity'));
    }

    /* =========================== Helpers =========================== */

    /**
     * Picker options shared by the create/edit forms wired in WS 0.1. These
     * exist so a form can offer a real relationship picker instead of asking
     * the user to type a foreign key — the failure mode §2.1 calls out in
     * DiagramEditor, where connecting two elements means typing element IDs
     * into three sequential window.prompt() dialogs.
     */
    protected function applicationOptions(): \Illuminate\Support\Collection
    {
        return EaApplication::orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn ($a) => ['value' => $a->id, 'label' => trim("{$a->code} — {$a->name}", ' —')])
            ->values();
    }

    protected function plateauOptions(): \Illuminate\Support\Collection
    {
        return Plateau::orderBy('effective_from')->get(['id', 'code', 'name', 'plateau_type'])
            ->map(fn ($p) => ['value' => $p->id, 'label' => trim("{$p->name} ({$p->plateau_type})")])
            ->values();
    }

    protected function applicationFormOptions(): array
    {
        return [
            'capabilities' => Capability::orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => "{$c->code} — {$c->name}"])->values(),
            'plateaux' => $this->plateauOptions(),
        ];
    }

    /**
     * Uniform refusal message for a guarded delete. Deletes are blocked rather
     * than cascaded: §7.1 requires the application layer to enforce referential
     * integrity across soft foreign keys, and a return whose citation cannot be
     * resolved is worse than a delete that was refused.
     */
    /**
     * Options the StewardshipDrawer needs to render owner pickers on an index
     * page (WS 1.1 — "owner pickers on every entity").
     */
    protected function stewardshipOptions(string $entityType): array
    {
        return [
            'entity_type' => $entityType,
            'users' => \App\Models\User::orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name.' ('.$u->email.')'])->values(),
            'roles' => collect(\App\Models\Ea\Subscription::ROLES)->map(fn ($r) => [
                'value' => $r,
                'label' => \App\Models\Ea\Subscription::ROLE_LABELS[$r],
            ])->values(),
        ];
    }

    protected function blockedMessage(string $noun, array $reasons): string
    {
        $reasons = array_values(array_filter($reasons));

        return "Cannot delete this {$noun}: ".implode(' and ', $reasons)
            .'. Remove or re-point the dependent records first.';
    }

    /** Is an interface past its documented review cadence? (CBN App. II §1.1(k)) */
    protected function reviewOverdue(EaInterface $interface): bool
    {
        if (! $interface->review_cadence) {
            return false;
        }
        if (! $interface->last_reviewed_at) {
            return true;
        }

        $months = match ($interface->review_cadence) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annual' => 6,
            default => 12,
        };

        return \Carbon\Carbon::parse($interface->last_reviewed_at)->addMonths($months)->isPast();
    }

    protected function capabilityOverlay($tree, string $overlay): array
    {
        $map = [];
        $apps = EaApplication::get(['id', 'capability_ids', 'criticality', 'annual_cost_ngn']);
        foreach ($tree as $c) {
            $linked = $apps->filter(fn ($a) => in_array($c->id, $a->capability_ids ?? []));
            $map[$c->id] = match ($overlay) {
                'app_count' => $linked->count(),
                'cost' => (float) $linked->sum('annual_cost_ngn'),
                'maturity' => (int) $c->maturity,
                'risk' => $linked->where('criticality', 'critical')->count(),
                default => $c->criticality,
            };
        }
        return $map;
    }

    protected function dpiaScore(array $answers): float
    {
        $score = 0;
        foreach ($answers as $a) {
            if (is_array($a) && isset($a['weight'])) $score += (float) $a['weight'];
            elseif (is_numeric($a)) $score += (float) $a;
        }
        return round($score, 2);
    }

    protected function dpiaBand(array $answers): string
    {
        $s = $this->dpiaScore($answers);
        return match (true) {
            $s >= 7 => 'high',
            $s >= 4 => 'medium',
            default => 'low',
        };
    }
}
