<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\User;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RiskController extends Controller
{
    public function __construct(
        private RiskScoringService $scoringService
    ) {}

    public function dashboard()
    {
        $orgId = auth()->user()->organization_id;

        $stats = [
            'total' => Risk::count(),
            'critical' => Risk::where('inherent_rating', 'critical')->count(),
            'high' => Risk::where('inherent_rating', 'high')->count(),
            'medium' => Risk::where('inherent_rating', 'medium')->count(),
            'low' => Risk::where('inherent_rating', 'low')->count(),
            'open' => Risk::whereNotIn('status', ['closed', 'archived'])->count(),
            'treating' => Risk::where('status', 'treating')->count(),
            'accepted' => Risk::where('status', 'accepted')->count(),
        ];

        $heatMapData = $this->scoringService->getHeatMapData($orgId, 'inherent');
        $distribution = $this->scoringService->getRiskDistribution($orgId);

        $recentRisks = Risk::with(['owner:id,name', 'category:id,name,color'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $topRisks = Risk::with(['owner:id,name'])
            ->whereNotNull('inherent_score')
            ->orderByDesc('inherent_score')
            ->limit(5)
            ->get();

        return Inertia::render('Risks/Dashboard', [
            'stats' => $stats,
            'heatMapData' => $heatMapData,
            'distribution' => $distribution,
            'recentRisks' => $recentRisks,
            'topRisks' => $topRisks,
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
        ]);
    }

    public function index(Request $request)
    {
        $query = Risk::with(['owner:id,name', 'category:id,name,color', 'creator:id,name'])
            ->withCount(['assessments', 'treatments']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('risk_id_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDir);

        $risks = $query->paginate(15)->withQueryString();

        return Inertia::render('Risks/Index', [
            'risks' => $risks,
            'categories' => RiskCategory::select('id', 'name', 'color')->get(),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'filters' => $request->only(['status', 'rating', 'category_id', 'owner_id', 'search', 'sort', 'direction']),
            'statuses' => Risk::STATUSES,
            'ratings' => array_keys(RiskScoringService::RATING_THRESHOLDS),
        ]);
    }

    public function create()
    {
        return Inertia::render('Risks/Create', [
            'categories' => RiskCategory::select('id', 'name', 'color')->get(),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'assets' => Asset::select('id', 'name', 'asset_id_code')->orderBy('name')->get(),
            'nextCode' => Risk::generateNextCode(auth()->user()->organization_id),
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:risk_categories,id',
            'risk_owner_id' => 'nullable|exists:users,id',
            'source' => 'nullable|string|max:100',
            'risk_appetite' => 'nullable|in:averse,cautious,moderate,open,hungry',
            'tags' => 'nullable|array',
            'inherent_likelihood' => 'nullable|integer|min:1|max:5',
            'inherent_impact' => 'nullable|integer|min:1|max:5',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'integer|exists:assets,id',
        ]);

        $assetIds = $validated['asset_ids'] ?? [];
        unset($validated['asset_ids']);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['created_by'] = auth()->id();
        $validated['risk_id_code'] = Risk::generateNextCode(auth()->user()->organization_id);

        if (isset($validated['inherent_likelihood']) && isset($validated['inherent_impact'])) {
            $score = $validated['inherent_likelihood'] * $validated['inherent_impact'];
            $validated['inherent_score'] = $score;
            $validated['inherent_rating'] = Risk::calculateRating($score);
            $validated['status'] = 'assessed';
        }

        $risk = Risk::create($validated);
        $risk->assets()->sync($assetIds);

        return redirect()->route('risks.show', $risk)
            ->with('success', "Risk {$risk->risk_id_code} created successfully.");
    }

    public function show(Risk $risk)
    {
        $risk->load([
            'owner:id,name,email,job_title',
            'creator:id,name',
            'category:id,name,color',
            'assessments' => fn ($q) => $q->with('assessor:id,name')->latest()->limit(10),
            'treatments' => fn ($q) => $q->with('assignee:id,name')->latest(),
            'scoreHistory' => fn ($q) => $q->orderByDesc('recorded_at')->limit(20),
            'threatAssessments' => fn ($q) => $q->with(['threat:id,name,threat_id_code', 'assessor:id,name'])->latest(),
            'controls:id,control_code,title,effectiveness',
            'assets:id,name,asset_id_code,criticality',
        ]);

        return Inertia::render('Risks/Show', [
            'risk' => $risk,
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
            'ratingColors' => RiskScoringService::RATING_COLORS,
        ]);
    }

    public function edit(Risk $risk)
    {
        return Inertia::render('Risks/Edit', [
            'risk' => $risk->load(['owner:id,name', 'category:id,name']),
            'categories' => RiskCategory::select('id', 'name', 'color')->get(),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'assets' => Asset::select('id', 'name', 'asset_id_code')->orderBy('name')->get(),
            'linkedAssetIds' => $risk->assets()->pluck('assets.id'),
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
        ]);
    }

    public function update(Request $request, Risk $risk)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:risk_categories,id',
            'risk_owner_id' => 'nullable|exists:users,id',
            'status' => 'nullable|in:' . implode(',', Risk::STATUSES),
            'treatment_strategy' => 'nullable|in:' . implode(',', Risk::TREATMENT_STRATEGIES),
            'treatment_due_date' => 'nullable|date',
            'risk_appetite' => 'nullable|in:averse,cautious,moderate,open,hungry',
            'source' => 'nullable|string|max:100',
            'tags' => 'nullable|array',
            'review_date' => 'nullable|date',
            'inherent_likelihood' => 'nullable|integer|min:1|max:5',
            'inherent_impact' => 'nullable|integer|min:1|max:5',
            'residual_likelihood' => 'nullable|integer|min:1|max:5',
            'residual_impact' => 'nullable|integer|min:1|max:5',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'integer|exists:assets,id',
        ]);

        if ($request->has('asset_ids')) {
            $risk->assets()->sync($validated['asset_ids'] ?? []);
        }
        unset($validated['asset_ids']);

        // Recalculate scores if likelihood/impact changed
        foreach (['inherent', 'residual'] as $type) {
            if (isset($validated["{$type}_likelihood"]) && isset($validated["{$type}_impact"])) {
                $score = $validated["{$type}_likelihood"] * $validated["{$type}_impact"];
                $validated["{$type}_score"] = $score;
                $validated["{$type}_rating"] = Risk::calculateRating($score);
            }
        }

        $risk->update($validated);

        return redirect()->route('risks.show', $risk)
            ->with('success', 'Risk updated successfully.');
    }

    public function destroy(Risk $risk)
    {
        $risk->delete();

        return redirect()->route('risks.index')
            ->with('success', "Risk {$risk->risk_id_code} archived.");
    }
}
