<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\Threat;
use App\Models\User;
use App\Models\Vulnerability;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class RiskController extends Controller
{
    /** Columns the register may be sorted by (anything else falls back to created_at). */
    private const SORTABLE = ['created_at', 'risk_id_code', 'title', 'inherent_score', 'residual_score', 'status', 'review_date'];

    public function __construct(
        private RiskScoringService $scoringService
    ) {}

    public function dashboard()
    {
        $orgId = auth()->user()->organization_id;

        // Rating tiles count the active register so they reconcile with the heat map and distribution.
        $active = Risk::active()->get(['id', 'status', 'inherent_rating', 'residual_rating']);

        $stats = [
            'total' => Risk::count(),
            'open' => $active->count(),
            'closed' => Risk::whereIn('status', Risk::INACTIVE_STATUSES)->count(),
            'critical' => $active->where('inherent_rating', 'critical')->count(),
            'high' => $active->where('inherent_rating', 'high')->count(),
            'medium' => $active->where('inherent_rating', 'medium')->count(),
            'low' => $active->where('inherent_rating', 'low')->count(),
            'very_low' => $active->where('inherent_rating', 'very_low')->count(),
            'unscored' => $active->whereNull('inherent_rating')->count(),
            'treating' => $active->where('status', 'treating')->count(),
            'accepted' => $active->where('status', 'accepted')->count(),
            'above_appetite' => Risk::active()->where('risk_appetite', 'above')->count(),
        ];

        $statusBreakdown = collect(Risk::STATUSES)
            ->map(fn ($s) => ['status' => $s, 'count' => Risk::where('status', $s)->count()])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();

        $recentRisks = Risk::with(['owner:id,name', 'category:id,name,color'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $topRisks = Risk::with(['owner:id,name'])
            ->active()
            ->whereNotNull('inherent_score')
            ->orderByDesc('inherent_score')
            ->orderByDesc('residual_score')
            ->limit(5)
            ->get();

        return Inertia::render('Risks/Dashboard', [
            'stats' => $stats,
            'heatMapData' => [
                'inherent' => $this->scoringService->getHeatMapData($orgId, 'inherent'),
                'residual' => $this->scoringService->getHeatMapData($orgId, 'residual'),
            ],
            'distribution' => $this->scoringService->getRiskDistribution($orgId),
            'statusBreakdown' => $statusBreakdown,
            'recentRisks' => $recentRisks,
            'topRisks' => $topRisks,
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
        ]);
    }

    public function index(Request $request)
    {
        $basis = $request->get('basis') === 'residual' ? 'residual' : 'inherent';

        $sortField = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'created_at';
        $sortDir = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        $risks = $this->filteredRegister($request, $basis)
            ->with(['owner:id,name', 'category:id,name,color', 'creator:id,name'])
            ->withCount(['assessments', 'treatments'])
            ->orderBy($sortField, $sortDir)->orderByDesc('id')
            ->paginate(15)->withQueryString();

        // The heat-map view plots the whole filtered register, not just the current page.
        $heatMapRisks = $this->filteredRegister($request, $basis)
            ->whereNotNull("{$basis}_likelihood")
            ->whereNotNull("{$basis}_impact")
            ->get(['id', 'risk_id_code', 'title', 'status', "{$basis}_likelihood as likelihood", "{$basis}_impact as impact", "{$basis}_score as score"]);

        return Inertia::render('Risks/Index', [
            'risks' => $risks,
            'heatMapRisks' => $heatMapRisks,
            'categories' => RiskCategory::select('id', 'name', 'color')->orderBy('sort_order')->get(),
            'users' => $this->orgUsers(),
            'filters' => $request->only(['status', 'rating', 'category_id', 'owner_id', 'appetite', 'search', 'sort', 'direction', 'basis', 'likelihood', 'impact']),
            'statuses' => Risk::STATUSES,
            'ratings' => array_keys(RiskScoringService::RATING_THRESHOLDS),
            'appetites' => Risk::APPETITES,
        ]);
    }

    private function filteredRegister(Request $request, string $basis)
    {
        $query = Risk::query();

        if ($request->filled('status')) {
            $request->status === 'active'
                ? $query->active()
                : $query->where('status', $request->status);
        }
        if ($request->filled('rating')) {
            $query->where('inherent_rating', $request->rating);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('owner_id')) {
            $query->where('risk_owner_id', $request->owner_id);
        }
        if ($request->filled('appetite')) {
            $query->where('risk_appetite', $request->appetite);
        }
        // Heat-map cell click-through: ?basis=inherent|residual&likelihood=4&impact=5 (active risks only, like the map)
        if ($request->filled('likelihood') && $request->filled('impact')) {
            $query->where("{$basis}_likelihood", (int) $request->likelihood)
                ->where("{$basis}_impact", (int) $request->impact)
                ->active();
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('risk_id_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function create()
    {
        return Inertia::render('Risks/Create', [
            'categories' => RiskCategory::select('id', 'name', 'color')->orderBy('sort_order')->get(),
            'users' => $this->orgUsers(),
            'assets' => Asset::select('id', 'name', 'asset_id_code')->orderBy('name')->get(),
            'nextCode' => Risk::generateNextCode(auth()->user()->organization_id),
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
            'appetites' => Risk::APPETITES,
            'sources' => Risk::SOURCES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $assetIds = $validated['asset_ids'] ?? [];
        unset($validated['asset_ids']);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['created_by'] = auth()->id();
        $validated['risk_id_code'] = Risk::generateNextCode(auth()->user()->organization_id);
        $validated['status'] = 'identified';
        $this->applyScores($validated);

        if (! empty($validated['inherent_score'])) {
            $validated['status'] = 'assessed';
        }

        $risk = Risk::create($validated);
        $risk->assets()->sync($assetIds);

        if ($risk->inherent_score) {
            $this->scoringService->recordHistory($risk, 'Initial assessment at registration');
        }

        return redirect()->route('risks.show', $risk)
            ->with('success', "Risk {$risk->risk_id_code} created successfully.");
    }

    public function show(Risk $risk)
    {
        $risk->load([
            'owner:id,name,email,job_title',
            'creator:id,name',
            'category:id,name,color',
            'assessments' => fn ($q) => $q->with('assessor:id,name')->latest('assessment_date')->latest('id')->limit(10),
            'treatments' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'scoreHistory' => fn ($q) => $q->with('changedByUser:id,name')->orderByDesc('recorded_at')->limit(20),
            'threatAssessments' => fn ($q) => $q->with(['threat:id,name,threat_id_code,severity', 'assessor:id,name'])->latest(),
            'controls:id,control_code,title,effectiveness',
            'assets:id,name,asset_id_code,criticality',
            'issues' => fn ($q) => $q->with('owner:id,name')->orderBy('due_date'),
            'fairScenarios' => fn ($q) => $q->with(['runs' => fn ($r) => $r->latest('ran_at')]),
        ]);

        $threats = $risk->threatAssessments->pluck('threat')->filter()->unique('id')->values();

        $assetIds = $risk->assets->pluck('id');
        $vulnerabilities = $assetIds->isEmpty() ? collect() : Vulnerability::query()
            ->whereHas('assets', fn ($q) => $q->whereIn('assets.id', $assetIds))
            ->with('assets:id')
            ->get(['id', 'vuln_id_code', 'cve_id', 'title', 'severity', 'cvss_score', 'status'])
            ->map(fn ($v) => $v->only(['id', 'vuln_id_code', 'cve_id', 'title', 'severity', 'cvss_score', 'status'])
                + ['asset_ids' => $v->assets->pluck('id')->intersect($assetIds)->values()]);

        $audit = Activity::query()
            ->where('subject_type', Risk::class)
            ->where('subject_id', $risk->id)
            ->with('causer:id,name')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'ts' => $a->created_at?->toIso8601String(),
                'actor' => $a->causer?->name ?? 'System',
                'action' => $a->event ?? $a->description,
                'changes' => $a->event === 'updated'
                    ? collect($a->attribute_changes?->get('attributes', []))
                        ->map(fn ($new, $field) => [
                            'field' => $field,
                            'old' => $a->attribute_changes?->get('old', [])[$field] ?? null,
                            'new' => $new,
                        ])->values()
                    : [],
            ]);

        $scenario = $risk->fairScenarios->first();

        return Inertia::render('Risks/Show', [
            'risk' => $risk,
            'threats' => $threats,
            'vulnerabilities' => $vulnerabilities,
            'audit' => $audit,
            'fair' => $scenario ? [
                'scenario' => $scenario->only(['id', 'name', 'iterations']),
                'run' => $scenario->runs->first(),
            ] : null,
            'availableThreats' => Threat::where('is_active', true)->orderBy('threat_id_code')->get(['id', 'threat_id_code', 'name']),
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
            'ratingColors' => RiskScoringService::RATING_COLORS,
        ]);
    }

    public function edit(Risk $risk)
    {
        return Inertia::render('Risks/Edit', [
            'risk' => $risk->load(['owner:id,name', 'category:id,name']),
            'categories' => RiskCategory::select('id', 'name', 'color')->orderBy('sort_order')->get(),
            'users' => $this->orgUsers(),
            'assets' => Asset::select('id', 'name', 'asset_id_code')->orderBy('name')->get(),
            'linkedAssetIds' => $risk->assets()->pluck('assets.id'),
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
            'statuses' => Risk::STATUSES,
            'appetites' => Risk::APPETITES,
            'sources' => Risk::SOURCES,
            'strategies' => Risk::TREATMENT_STRATEGIES,
        ]);
    }

    public function update(Request $request, Risk $risk)
    {
        $validated = $request->validate($this->rules(updating: true));

        if ($request->has('asset_ids')) {
            $risk->assets()->sync($validated['asset_ids'] ?? []);
        }
        unset($validated['asset_ids']);

        $this->applyScores($validated);

        $before = $risk->only(['inherent_score', 'residual_score']);
        $risk->update($validated);

        if ($before !== $risk->only(['inherent_score', 'residual_score'])) {
            $this->scoringService->recordHistory($risk, $request->input('change_reason') ?: 'Score updated via risk edit');
        }

        return redirect()->route('risks.show', $risk)
            ->with('success', "Risk {$risk->risk_id_code} updated successfully.");
    }

    public function destroy(Risk $risk)
    {
        $risk->delete();

        return redirect()->route('risks.index')
            ->with('success', "Risk {$risk->risk_id_code} archived.");
    }

    /**
     * Shared validation. Foreign keys are constrained to the current tenant so a
     * crafted request cannot attach another bank's category, owner or asset.
     */
    private function rules(bool $updating = false): array
    {
        $orgId = auth()->user()->organization_id;
        $inOrg = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $orgId);

        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => ['nullable', $inOrg('risk_categories')],
            'risk_owner_id' => ['nullable', $inOrg('users')],
            'source' => ['nullable', Rule::in(Risk::SOURCES)],
            'risk_appetite' => ['nullable', Rule::in(Risk::APPETITES)],
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'inherent_likelihood' => 'nullable|integer|min:1|max:5|required_with:inherent_impact',
            'inherent_impact' => 'nullable|integer|min:1|max:5|required_with:inherent_likelihood',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => ['integer', $inOrg('assets')],
        ];

        if ($updating) {
            $rules += [
                'status' => ['required', Rule::in(Risk::STATUSES)],
                'treatment_strategy' => ['nullable', Rule::in(Risk::TREATMENT_STRATEGIES)],
                'treatment_due_date' => 'nullable|date',
                'review_date' => 'nullable|date',
                'residual_likelihood' => 'nullable|integer|min:1|max:5|required_with:residual_impact',
                'residual_impact' => 'nullable|integer|min:1|max:5|required_with:residual_likelihood',
            ];
        }

        return $rules;
    }

    /** Derive score + rating from likelihood × impact; clearing either clears the score. */
    private function applyScores(array &$validated): void
    {
        foreach (['inherent', 'residual'] as $type) {
            if (! array_key_exists("{$type}_likelihood", $validated) && ! array_key_exists("{$type}_impact", $validated)) {
                continue;
            }
            $l = $validated["{$type}_likelihood"] ?? null;
            $i = $validated["{$type}_impact"] ?? null;
            $score = ($l && $i) ? $l * $i : null;
            $validated["{$type}_score"] = $score;
            $validated["{$type}_rating"] = $score ? Risk::calculateRating($score) : null;
        }
    }

    private function orgUsers()
    {
        return User::where('organization_id', auth()->user()->organization_id)
            ->orderBy('name')->select('id', 'name')->get();
    }
}
