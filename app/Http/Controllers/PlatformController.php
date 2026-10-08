<?php

namespace App\Http\Controllers;

use App\Models\AssetSyncJob;
use App\Models\AucsControl;
use App\Models\AucsFrameworkMapping;
use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\BusinessCapability;
use App\Models\BusinessService;
use App\Models\CcmTenantTest;
use App\Models\CcmTestRun;
use App\Models\ContentInstall;
use App\Models\CopilotConversation;
use App\Models\CopilotMessage;
use App\Models\CoreBankingIntegration;
use App\Models\CoreBankingSnapshot;
use App\Models\CustomField;
use App\Models\DocIntelligenceJob;
use App\Models\DrExercise;
use App\Models\DrRunbook;
use App\Models\EvidenceVaultItem;
use App\Models\FairRun;
use App\Models\FairScenario;
use App\Models\FeatureFlag;
use App\Models\FrameworkClause;
use App\Models\IncidentNotification;
use App\Models\Issue;
use App\Models\Kri;
use App\Models\KriBreach;
use App\Models\KriReading;
use App\Models\MarketplaceItem;
use App\Models\NotificationTemplate;
use App\Models\Obligation;
use App\Models\PricingTier;
use App\Models\RegulatoryCircular;
use App\Models\ReturnRun;
use App\Models\ReturnTemplate;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\ScimToken;
use App\Models\ServiceDependency;
use App\Models\SharedVendorDirectory;
use App\Models\SiemIntegration;
use App\Models\SiemSignal;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\TenantTheme;
use App\Models\ThreatAdvisory;
use App\Models\TprmBreachEvent;
use App\Models\TprmSecurityRating;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\FairMonteCarloService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PlatformController extends Controller
{
    /* ------------------------------ Copilot ------------------------------ */
    public function copilotIndex()
    {
        $conversations = CopilotConversation::orderBy('created_at', 'desc')->take(20)->get();
        $active = $conversations->first();
        $messages = $active
            ? CopilotMessage::where('conversation_id', $active->id)->orderBy('created_at')->get()
            : collect();

        return Inertia::render('Copilot/Index', compact('conversations', 'active', 'messages'));
    }

    public function copilotSend(Request $request)
    {
        $data = $request->validate([
            'conversation_id' => 'nullable|integer',
            'content' => 'required|string',
        ]);
        $conv = $data['conversation_id']
            ? CopilotConversation::find($data['conversation_id'])
            : CopilotConversation::create([
                'organization_id' => 1,
                'user_id' => $request->user()->id,
                'title' => Str::limit($data['content'], 40),
                'mode' => 'general',
            ]);
        CopilotMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => $data['content'],
            'tokens_in' => strlen($data['content']) / 4,
            'model' => 'claude-sonnet-4-6',
        ]);
        $reply = 'Based on Atheris AUCS, CBN RBCSF and your current tenant data: '.
            "I can help you explore risks, controls, obligations, incidents, and KRIs. Try asking: \n".
            "• *Show me overdue critical risks*\n".
            "• *Which controls are failing CCM tests?*\n".
            "• *Summarise last week's CBN circulars*\n".
            '• *Draft an NDPC 72-hour breach notification for Incident #INC-001*';
        CopilotMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'content' => $reply,
            'tokens_out' => 120,
            'model' => 'claude-sonnet-4-6',
        ]);

        return redirect()->back()->with('success', 'Copilot response received');
    }

    /* --------------------------- Regulatory Intel --------------------------- */
    public function regIntelIndex(Request $r)
    {
        $regulator = $r->query('regulator');
        $circulars = RegulatoryCircular::query()
            ->when($regulator, fn ($q) => $q->where('regulator_code', $regulator))
            ->orderByDesc('issued_at')
            ->take(100)
            ->get();
        $counts = RegulatoryCircular::select('regulator_code', DB::raw('count(*) as c'))
            ->groupBy('regulator_code')->pluck('c', 'regulator_code');

        return Inertia::render('RegulatoryIntel/Index', compact('circulars', 'counts', 'regulator'));
    }

    public function regIntelShow(RegulatoryCircular $circular)
    {
        return Inertia::render('RegulatoryIntel/Show', ['circular' => $circular]);
    }

    public function obligationsIndex(Request $r)
    {
        $obligations = Obligation::query()
            ->when($r->query('regulator'), fn ($q, $reg) => $q->where('regulator_code', $reg))
            ->orderBy('regulator_code')
            ->get()
            ->map(fn ($o) => $o->toArray() + [
                'next_due' => $o->nextDue()->toDateString(),
                'days_to_due' => (int) today()->diffInDays($o->nextDue(), false),
            ])
            ->sortBy('next_due')->values();

        return Inertia::render('Obligations/Index', [
            'obligations' => $obligations,
            'regulators' => Obligation::query()->distinct()->orderBy('regulator_code')->pluck('regulator_code'),
            'regulator' => $r->query('regulator'),
        ]);
    }

    /* ------------------------------- AUCS ------------------------------- */
    public function aucsIndex(Request $r)
    {
        $domain = $r->query('domain');
        $controls = AucsControl::query()
            ->when($domain, fn ($q) => $q->where('domain', $domain))
            ->orderBy('code')
            ->get();
        $domains = AucsControl::distinct('domain')->orderBy('domain')->pluck('domain');
        $mappingsByControl = AucsFrameworkMapping::with('clause')->get()->groupBy('aucs_control_id');
        $frameworkCodes = FrameworkClause::distinct('framework_code')->pluck('framework_code');

        return Inertia::render('Aucs/Index', compact('controls', 'domains', 'mappingsByControl', 'frameworkCodes', 'domain'));
    }

    /* ---------------------------- Asset Discovery ---------------------------- */
    public function assetDiscoveryIndex()
    {
        $jobs = AssetSyncJob::orderByDesc('started_at')->take(50)->get();
        $summary = AssetSyncJob::select('source', DB::raw('count(*) as c'),
            DB::raw('SUM(records_imported) as imported'))->groupBy('source')->get();

        return Inertia::render('Assets/Discovery', compact('jobs', 'summary'));
    }

    public function assetDiscoverySync(Request $r)
    {
        $source = $r->input('source', 'manual');
        AssetSyncJob::create([
            'organization_id' => 1,
            'source' => $source,
            'status' => 'completed',
            'records_imported' => rand(20, 500),
            'records_updated' => rand(5, 50),
            'output_summary' => "Dry-run sync from {$source}: sample payload ingested",
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);

        return redirect()->route('asset-discovery.index')->with('success', "Sync queued for {$source}");
    }

    /* ---------------------------- Business Services ---------------------------- */
    public function businessServicesIndex()
    {
        $services = BusinessService::with('capability', 'processes')->get();
        $capabilities = BusinessCapability::orderBy('name')->get();
        $dependencies = ServiceDependency::get();

        return Inertia::render('BusinessServices/Index', compact('services', 'capabilities', 'dependencies'));
    }

    public function businessServicesGraph()
    {
        $services = BusinessService::get();
        $dependencies = ServiceDependency::get();
        $capabilities = BusinessCapability::get();

        return Inertia::render('BusinessServices/Graph', compact('services', 'dependencies', 'capabilities'));
    }

    /* -------------------------------- Issues -------------------------------- */
    public function issuesIndex(Request $r)
    {
        $status = $r->query('status');
        $issues = Issue::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('severity')->orderByDesc('created_at')
            ->get();
        $counts = Issue::select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        return Inertia::render('Issues/Index', compact('issues', 'counts', 'status'));
    }

    public function issuesSlaPolicies()
    {
        $policies = DB::table('vulnerability_sla_policies')->get();

        return Inertia::render('Issues/SlaPolicies', compact('policies'));
    }

    public function itsmIndex()
    {
        $integrations = collect([
            ['key' => 'jira', 'name' => 'Atlassian Jira', 'status' => 'connected', 'tickets_synced' => 128],
            ['key' => 'servicenow', 'name' => 'ServiceNow', 'status' => 'connected', 'tickets_synced' => 64],
            ['key' => 'freshservice', 'name' => 'Freshservice', 'status' => 'available', 'tickets_synced' => 0],
        ]);

        return Inertia::render('Issues/Itsm', compact('integrations'));
    }

    /* ------------------------------- Risk Graph ------------------------------- */
    public function risksGraph(Request $request)
    {
        $limit = in_array((int) $request->get('limit'), [10, 20, 40], true) ? (int) $request->get('limit') : 10;

        // Top active risks by exposure, with their real relationships (all tenant-scoped).
        $risks = Risk::active()
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('rating'), fn ($q) => $q->where('inherent_rating', $request->rating))
            ->with([
                'controls:id,control_code,title,effectiveness',
                'assets:id,asset_id_code,name',
                'threatAssessments' => fn ($q) => $q->with('threat:id,threat_id_code,name,severity'),
            ])
            ->orderByDesc('inherent_score')->orderByDesc('residual_score')
            ->limit($limit)
            ->get(['id', 'risk_id_code', 'title', 'inherent_score', 'inherent_rating', 'residual_score']);

        $assetIds = $risks->flatMap(fn ($r) => $r->assets->pluck('id'))->unique();
        $vulns = $assetIds->isEmpty() ? collect() : Vulnerability::query()
            ->whereNotIn('status', ['remediated', 'closed', 'false_positive'])
            ->whereHas('assets', fn ($q) => $q->whereIn('assets.id', $assetIds))
            ->with('assets:id')
            ->get(['id', 'vuln_id_code', 'cve_id', 'title', 'severity'])
            ->map(fn ($v) => $v->only(['id', 'vuln_id_code', 'cve_id', 'title', 'severity'])
                + ['asset_ids' => $v->assets->pluck('id')->intersect($assetIds)->values()]);

        return Inertia::render('Risks/Graph', [
            'risks' => $risks,
            'vulns' => $vulns,
            'categories' => RiskCategory::orderBy('sort_order')->get(['id', 'name']),
            'filters' => ['limit' => $limit] + $request->only('category_id', 'rating'),
        ]);
    }

    /* --------------------------------- CCM --------------------------------- */
    public function ccmIndex()
    {
        $tests = CcmTenantTest::with('test')->get();
        $lastRuns = CcmTestRun::orderByDesc('ran_at')->take(50)->get();
        $summary = [
            'total' => $tests->count(),
            'pass' => $tests->where('last_status', 'pass')->count(),
            'fail' => $tests->where('last_status', 'fail')->count(),
            'warn' => $tests->where('last_status', 'warn')->count(),
            'error' => $tests->where('last_status', 'error')->count(),
        ];

        return Inertia::render('Ccm/Index', compact('tests', 'lastRuns', 'summary'));
    }

    public function ccmRun(CcmTenantTest $tenantTest)
    {
        $statuses = ['pass', 'fail', 'warn'];
        $status = $statuses[array_rand($statuses)];
        CcmTestRun::create([
            'tenant_test_id' => $tenantTest->id,
            'status' => $status,
            'output_summary' => "Ad-hoc run completed with status: {$status}",
            'latency_ms' => rand(250, 2500),
            'ran_at' => now(),
        ]);
        $tenantTest->update(['last_status' => $status, 'last_run_at' => now(), 'next_run_at' => now()->addDay()]);

        return redirect()->back()->with('success', "CCM test ran: {$status}");
    }

    /* ---------------------------- Evidence Vault ---------------------------- */
    public function evidenceVaultIndex()
    {
        $evidence = EvidenceVaultItem::orderByDesc('created_at')->take(200)->get();
        $stats = [
            'total' => EvidenceVaultItem::count(),
            'worm_locked' => EvidenceVaultItem::where('worm_locked', true)->count(),
            'bytes' => EvidenceVaultItem::sum('bytes'),
        ];

        return Inertia::render('EvidenceVault/Index', compact('evidence', 'stats'));
    }

    /* -------------------------------- KRIs -------------------------------- */
    public function kriIndex()
    {
        $kris = Kri::get();
        $readingsByKri = KriReading::orderByDesc('recorded_at')->get()->groupBy('kri_id');
        $breaches = KriBreach::with('kri')->orderByDesc('occurred_at')->take(50)->get();

        return Inertia::render('Kri/Index', compact('kris', 'readingsByKri', 'breaches'));
    }

    /* ---------------------------- Board Packs ---------------------------- */
    public function boardPacksIndex()
    {
        $templates = BoardPackTemplate::get();
        $runs = BoardPackRun::with('template')->orderByDesc('created_at')->take(50)->get();

        return Inertia::render('BoardPacks/Index', compact('templates', 'runs'));
    }

    public function boardPacksGenerate(Request $r)
    {
        $tmpl = BoardPackTemplate::findOrFail($r->input('template_id'));
        BoardPackRun::create([
            'organization_id' => 1,
            'template_id' => $tmpl->id,
            'period' => now()->format('Y-m'),
            'status' => 'generated',
            'pptx_path' => 'board-packs/demo-'.now()->timestamp.'.pptx',
            'pdf_path' => 'board-packs/demo-'.now()->timestamp.'.pdf',
            'generated_at' => now(),
        ]);

        return redirect()->route('board-packs.index')->with('success', 'Board pack generated (demo)');
    }

    /* ------------------------------- Pricing ------------------------------- */
    public function pricingIndex()
    {
        $tiers = PricingTier::orderBy('annual_ngn')->get();

        return Inertia::render('Pricing/Index', compact('tiers'));
    }

    /* --------------------------------- TPRM --------------------------------- */
    public function securityRatingsIndex()
    {
        $vendors = Vendor::with(['assessments'])->take(50)->get();
        $ratings = TprmSecurityRating::orderByDesc('captured_at')->get()->groupBy('vendor_id');
        $breaches = TprmBreachEvent::orderByDesc('discovered_at')->take(50)->get();

        return Inertia::render('Tprm/Ratings', compact('vendors', 'ratings', 'breaches'));
    }

    public function sharedVendorsIndex()
    {
        $vendors = SharedVendorDirectory::orderBy('legal_name')->get();

        return Inertia::render('Tprm/SharedDirectory', compact('vendors'));
    }

    /* -------------------------------- SIEM -------------------------------- */
    public function siemIndex()
    {
        $integrations = SiemIntegration::get();
        $signals = SiemSignal::orderByDesc('received_at')->take(50)->get();

        return Inertia::render('Siem/Index', compact('integrations', 'signals'));
    }

    /* ------------------------ Regulatory Notifications ------------------------ */
    public function notificationsIndex()
    {
        $templates = NotificationTemplate::get();
        $notifications = IncidentNotification::orderByDesc('created_at')->take(50)->get();

        return Inertia::render('Notifications/Index', compact('templates', 'notifications'));
    }

    public function notificationsDraft(Request $r)
    {
        $data = $r->validate([
            'incident_id' => 'required|integer',
            'template_key' => 'required|string',
        ]);
        $tmpl = NotificationTemplate::where('template_key', $data['template_key'])->firstOrFail();
        $draft = IncidentNotification::create([
            'incident_id' => $data['incident_id'],
            'template_key' => $tmpl->template_key,
            'draft_body' => $tmpl->body_template,
            'delivery_status' => 'draft',
            'deadline_at' => now()->addHours(24),
        ]);

        return redirect()->route('notifications.index')->with('success', 'Notification drafted');
    }

    /* --------------------------------- FAIR --------------------------------- */
    public function fairIndex()
    {
        $scenarios = FairScenario::with([
            'risk:id,risk_id_code,title,fair_annual_loss_expectancy',
            'runs' => fn ($q) => $q->latest('ran_at')->latest('id'),
        ])->orderBy('name')->get()
            ->map(fn ($s) => $s->setRelation('runs', $s->runs->take(5)));

        return Inertia::render('Fair/Index', [
            'scenarios' => $scenarios,
            // Active risks, plus any already-linked risk that has since closed so the link still shows.
            'risks' => Risk::where(fn ($q) => $q->active()->orWhereIn('id', $scenarios->pluck('risk_id')->filter()))
                ->orderBy('risk_id_code')->get(['id', 'risk_id_code', 'title', 'status']),
        ]);
    }

    public function fairRun(FairScenario $scenario, FairMonteCarloService $monteCarlo)
    {
        $result = $monteCarlo->simulate($scenario);

        FairRun::create(collect($result)->only(['ale_mean_ngn', 'ale_median_ngn', 'ale_p95_ngn', 'ale_p99_ngn', 'histogram'])->all() + [
            'scenario_id' => $scenario->id,
            'ran_at' => now(),
        ]);

        // A scenario quantifies its linked risk: the register's ALE/SLE follow the latest run.
        if ($scenario->risk) {
            $scenario->risk->update([
                'fair_annual_loss_expectancy' => $result['ale_mean_ngn'],
                'fair_single_loss_expectancy' => $result['sle_ngn'],
            ]);
        }

        return redirect()->back()->with('success', sprintf(
            'FAIR Monte Carlo completed (%s iterations): mean ALE ₦%s, P95 ₦%s%s.',
            number_format($result['iterations']),
            number_format($result['ale_mean_ngn']),
            number_format($result['ale_p95_ngn']),
            $scenario->risk ? " — {$scenario->risk->risk_id_code} ALE updated" : ''
        ));
    }

    public function fairLink(Request $request, FairScenario $scenario)
    {
        $validated = $request->validate([
            'risk_id' => ['nullable', Rule::exists('risks', 'id')
                ->where('organization_id', $request->user()->organization_id)->whereNull('deleted_at')],
        ]);
        $scenario->update(['risk_id' => $validated['risk_id'] ?? null]);

        return redirect()->back()->with('success', $scenario->risk_id
            ? "Scenario linked to {$scenario->risk->risk_id_code}. Run the simulation to update its ALE."
            : 'Scenario unlinked.');
    }

    /* --------------------------- Vulnerability Prio --------------------------- */
    public function vulnPrioritiserIndex()
    {
        $vulns = Vulnerability::take(100)->get()->map(function ($v) {
            $v->epss = round(mt_rand(0, 10000) / 10000, 4);
            $v->kev = $v->epss > 0.8;
            $v->priority = round(($v->cvss_score ?? 5) * 10 + $v->epss * 100 + ($v->kev ? 50 : 0), 2);

            return $v;
        })->sortByDesc('priority')->values();

        return Inertia::render('Vuln/Prioritiser', compact('vulns'));
    }

    public function threatAdvisoriesIndex()
    {
        $advisories = ThreatAdvisory::orderByDesc('published_at')->take(100)->get();

        return Inertia::render('Threats/Advisories', compact('advisories'));
    }

    /* ---------------------------- Doc Intelligence ---------------------------- */
    public function docIntelIndex()
    {
        $jobs = DocIntelligenceJob::orderByDesc('created_at')->take(50)->get();

        return Inertia::render('DocIntel/Index', compact('jobs'));
    }

    public function docIntelUpload(Request $r)
    {
        DocIntelligenceJob::create([
            'organization_id' => 1,
            'doc_type' => $r->input('doc_type', 'circular'),
            'source_file' => 'docs/demo-'.now()->timestamp.'.pdf',
            'confidence' => 0.92,
            'status' => 'awaiting_review',
            'structured_output' => [
                'regulator' => 'CBN',
                'reference' => 'BSD/DIR/GEN/LAB/14/'.rand(1, 200),
                'effective_date' => now()->toDateString(),
                'summary' => 'Demo extracted summary of uploaded regulatory document.',
            ],
        ]);

        return redirect()->route('doc-intel.index')->with('success', 'Document queued (demo)');
    }

    /* ------------------------------- Returns ------------------------------- */
    public function returnsIndex()
    {
        $templates = ReturnTemplate::get();
        $runs = ReturnRun::with('template')->orderByDesc('created_at')->take(50)->get();

        return Inertia::render('Returns/Index', compact('templates', 'runs'));
    }

    public function returnsGenerate(Request $r)
    {
        $tmpl = ReturnTemplate::findOrFail($r->input('template_id'));
        ReturnRun::create([
            'organization_id' => 1,
            'template_id' => $tmpl->id,
            'period' => now()->format('Y-m'),
            'status' => 'draft',
            'pdf_path' => 'returns/demo-'.now()->timestamp.'.pdf',
            'xlsx_path' => 'returns/demo-'.now()->timestamp.'.xlsx',
            'data' => ['auto_populated' => true, 'overrides' => []],
        ]);

        return redirect()->route('returns.index')->with('success', "Return generated for {$tmpl->name}");
    }

    /* ------------------------------- Workflows ------------------------------- */
    public function workflowsIndex()
    {
        $workflows = Workflow::orderBy('name')->get();

        return Inertia::render('Workflows/Index', compact('workflows'));
    }

    public function workflowsMarketplace()
    {
        $templates = Workflow::where('status', 'template')->get();

        return Inertia::render('Workflows/Marketplace', compact('templates'));
    }

    public function workflowShow(Workflow $workflow)
    {
        $workflow->load(['instances' => fn ($q) => $q->orderByDesc('started_at')->take(20)]);

        return Inertia::render('Workflows/Show', ['workflow' => $workflow]);
    }

    public function workflowsInstances()
    {
        $instances = WorkflowInstance::with('workflow', 'tasks')->orderByDesc('started_at')->take(100)->get();

        return Inertia::render('Workflows/Instances', compact('instances'));
    }

    /* ------------------------------ Core Banking ------------------------------ */
    public function coreBankingIndex()
    {
        $integrations = CoreBankingIntegration::get();

        return Inertia::render('CoreBanking/Index', compact('integrations'));
    }

    public function coreBankingSnapshots()
    {
        $snapshots = CoreBankingSnapshot::orderByDesc('captured_at')->take(50)->get();

        return Inertia::render('CoreBanking/Snapshots', compact('snapshots'));
    }

    /* --------------------------------- DR --------------------------------- */
    public function drRunbooksIndex()
    {
        $runbooks = DrRunbook::withCount('exercises')->get();

        return Inertia::render('Dr/Runbooks', compact('runbooks'));
    }

    public function drRunbookShow(DrRunbook $runbook)
    {
        $exercises = $runbook->exercises()->orderByDesc('scheduled_at')->get();

        return Inertia::render('Dr/RunbookShow', compact('runbook', 'exercises'));
    }

    public function drExercisesIndex()
    {
        $exercises = DrExercise::with('runbook')->orderByDesc('scheduled_at')->take(100)->get();

        return Inertia::render('Dr/Exercises', compact('exercises'));
    }

    /* ------------------------------ Marketplace ------------------------------ */
    public function marketplaceIndex()
    {
        $items = MarketplaceItem::orderByDesc('rating')->get();

        return Inertia::render('Marketplace/Index', compact('items'));
    }

    public function marketplaceInstalls()
    {
        $installs = ContentInstall::with('item')->orderByDesc('installed_at')->get();

        return Inertia::render('Marketplace/Installs', compact('installs'));
    }

    public function marketplaceInstall(MarketplaceItem $item)
    {
        ContentInstall::create([
            'organization_id' => 1,
            'marketplace_item_id' => $item->id,
            'version' => $item->version,
            'installed_at' => now(),
            'status' => 'active',
        ]);
        $item->increment('installs_count');

        return redirect()->route('marketplace.installs')->with('success', "Installed {$item->name}");
    }

    /* --------------------------- Identity & Access --------------------------- */
    public function identitySso()
    {
        $connections = SsoConnection::get();

        return Inertia::render('Identity/Sso', compact('connections'));
    }

    public function identityScim()
    {
        $tokens = ScimToken::get();

        return Inertia::render('Identity/Scim', compact('tokens'));
    }

    public function identityApi()
    {
        $endpoints = collect([
            ['method' => 'GET', 'path' => '/api/v1/risks', 'description' => 'List risks (paginated)'],
            ['method' => 'POST', 'path' => '/api/v1/risks', 'description' => 'Create a risk'],
            ['method' => 'GET', 'path' => '/api/v1/controls', 'description' => 'List controls'],
            ['method' => 'GET', 'path' => '/api/v1/incidents', 'description' => 'List incidents'],
            ['method' => 'POST', 'path' => '/api/v1/incidents', 'description' => 'Create an incident'],
            ['method' => 'GET', 'path' => '/api/v1/vulnerabilities', 'description' => 'List vulnerabilities'],
            ['method' => 'GET', 'path' => '/api/v1/aucs/controls', 'description' => 'AUCS control catalogue'],
            ['method' => 'POST', 'path' => '/api/v1/copilot/conversations', 'description' => 'Start a Copilot conversation'],
            ['method' => 'POST', 'path' => '/api/v1/ccm/run/{id}', 'description' => 'Run a CCM test'],
            ['method' => 'GET', 'path' => '/api/v1/kris', 'description' => 'List KRIs with latest readings'],
            ['method' => 'POST', 'path' => '/api/v1/board-packs/generate', 'description' => 'Generate a board pack'],
            ['method' => 'POST', 'path' => '/api/v1/fair/scenarios/{id}/runs', 'description' => 'Run FAIR Monte Carlo'],
            ['method' => 'GET', 'path' => '/api/v1/regulatory/circulars', 'description' => 'Regulatory circulars feed'],
            ['method' => 'GET', 'path' => '/api/v1/obligations', 'description' => 'Obligations library'],
        ]);

        return Inertia::render('Identity/Api', compact('endpoints'));
    }

    /* -------------------------------- Settings -------------------------------- */
    public function settingsTheme()
    {
        $theme = TenantTheme::first() ?? (object) [
            'tokens' => ['navy' => '#0A1F44', 'gold' => '#C9A86A', 'green' => '#2D7D46'],
            'logo_path' => null,
        ];

        return Inertia::render('Settings/Theme', compact('theme'));
    }

    public function settingsCustomFields()
    {
        $fields = CustomField::orderBy('subject_type')->orderBy('order_index')->get();

        return Inertia::render('Settings/CustomFields', compact('fields'));
    }

    public function settingsFeatureFlags()
    {
        $flags = FeatureFlag::orderBy('key')->get();

        return Inertia::render('Settings/FeatureFlags', compact('flags'));
    }

    public function settingsFeatureFlagToggle(FeatureFlag $flag)
    {
        $flag->update(['enabled' => ! $flag->enabled]);

        return redirect()->back();
    }
}
