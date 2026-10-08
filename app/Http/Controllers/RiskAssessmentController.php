<?php

namespace App\Http\Controllers;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Services\FairQuantificationService;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RiskAssessmentController extends Controller
{
    public function __construct(
        private RiskScoringService $scoringService,
        private FairQuantificationService $fairService
    ) {}

    public function index(Request $request)
    {
        $assessments = RiskAssessment::with(['risk:id,title,risk_id_code', 'assessor:id,name'])
            ->when($request->filled('methodology'), fn ($q) => $q->where('methodology', $request->methodology))
            ->when($request->filled('assessment_type'), fn ($q) => $q->where('assessment_type', $request->assessment_type))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('risk', fn ($r) => $r
                ->where('title', 'like', "%{$request->search}%")
                ->orWhere('risk_id_code', 'like', "%{$request->search}%")))
            ->latest('assessment_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Risks/Assessments/Index', [
            'assessments' => $assessments,
            'filters' => $request->only(['methodology', 'assessment_type', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $risk = $request->has('risk_id') ? Risk::findOrFail($request->risk_id) : null;

        return Inertia::render('Risks/Assessments/Create', [
            'risk' => $risk,
            'risks' => Risk::active()->select('id', 'title', 'risk_id_code', 'inherent_score', 'residual_score')->orderBy('risk_id_code')->get(),
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'risk_id' => ['required', Rule::exists('risks', 'id')
                ->where('organization_id', auth()->user()->organization_id)->whereNull('deleted_at')],
            'methodology' => 'required|in:qualitative,fair',
            'assessment_type' => 'required|in:inherent,residual',

            // Qualitative fields
            'likelihood' => 'required_if:methodology,qualitative|nullable|integer|min:1|max:5',
            'impact' => 'required_if:methodology,qualitative|nullable|integer|min:1|max:5',
            'impact_financial' => 'nullable|integer|min:1|max:5',
            'impact_operational' => 'nullable|integer|min:1|max:5',
            'impact_reputational' => 'nullable|integer|min:1|max:5',
            'impact_regulatory' => 'nullable|integer|min:1|max:5',
            'impact_safety' => 'nullable|integer|min:1|max:5',

            // FAIR fields
            // fair_tef / fair_lef are decimal(8,4) — cap so a large frequency can't overflow the column
            'fair_tef' => 'required_if:methodology,fair|nullable|numeric|min:0|max:9999',
            'fair_vul' => 'required_if:methodology,fair|nullable|numeric|min:0|max:1',
            'fair_plm' => 'required_if:methodology,fair|nullable|numeric|min:0',
            'fair_slm' => 'nullable|numeric|min:0',
            'fair_currency' => 'nullable|string|size:3',

            'justification' => 'nullable|string',
            'notes' => 'nullable|string',
            'next_review_date' => 'nullable|date',
        ]);

        $risk = Risk::findOrFail($validated['risk_id']);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['assessed_by'] = auth()->id();
        $validated['assessment_date'] = now()->toDateString();

        if ($validated['methodology'] === 'qualitative') {
            $validated['score'] = $validated['likelihood'] * $validated['impact'];
            $validated['rating'] = Risk::calculateRating($validated['score']);
        }

        if ($validated['methodology'] === 'fair') {
            $fairResult = $this->fairService->calculate(
                $validated['fair_tef'],
                $validated['fair_vul'],
                $validated['fair_plm'],
                $validated['fair_slm'] ?? 0,
                $validated['fair_currency'] ?? 'NGN'
            );
            $validated['fair_lef'] = $fairResult['lef'];
            $validated['fair_ale'] = $fairResult['ale'];
        }

        $type = $validated['assessment_type'];

        DB::transaction(function () use ($validated, $risk, $type, &$fairResult) {
            RiskAssessment::create($validated);

            // The latest assessment drives the register score for its basis (inherent/residual).
            if ($validated['methodology'] === 'qualitative') {
                $this->scoringService->scoreRisk(
                    $risk, $type,
                    $validated['likelihood'],
                    $validated['impact'],
                    ucfirst($type).' assessment recorded'.(! empty($validated['justification']) ? ': '.str($validated['justification'])->limit(120) : '')
                );
            }

            if ($validated['methodology'] === 'fair') {
                $risk->update([
                    'fair_annual_loss_expectancy' => $fairResult['ale'],
                    'fair_single_loss_expectancy' => $fairResult['sle'],
                ]);
            }
        });

        return redirect()->route('risks.show', $risk)
            ->with('success', ucfirst($type).' assessment recorded.');
    }

    public function show(RiskAssessment $riskAssessment)
    {
        $riskAssessment->load([
            'risk:id,title,risk_id_code,status,inherent_score,residual_score',
            'assessor:id,name,email,job_title',
        ]);

        return Inertia::render('Risks/Assessments/Show', [
            'assessment' => $riskAssessment,
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
        ]);
    }

    public function edit(RiskAssessment $riskAssessment)
    {
        return Inertia::render('Risks/Assessments/Edit', [
            'assessment' => $riskAssessment->load('risk:id,title,risk_id_code'),
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
        ]);
    }

    public function update(Request $request, RiskAssessment $riskAssessment)
    {
        $validated = $request->validate([
            'likelihood' => 'nullable|integer|min:1|max:5',
            'impact' => 'nullable|integer|min:1|max:5',
            'impact_financial' => 'nullable|integer|min:1|max:5',
            'impact_operational' => 'nullable|integer|min:1|max:5',
            'impact_reputational' => 'nullable|integer|min:1|max:5',
            'impact_regulatory' => 'nullable|integer|min:1|max:5',
            'impact_safety' => 'nullable|integer|min:1|max:5',
            'justification' => 'nullable|string',
            'notes' => 'nullable|string',
            'next_review_date' => 'nullable|date',
        ]);

        if (! empty($validated['likelihood']) && ! empty($validated['impact'])) {
            $validated['score'] = $validated['likelihood'] * $validated['impact'];
            $validated['rating'] = Risk::calculateRating($validated['score']);
        }

        $riskAssessment->update($validated);

        // Revising the latest assessment re-scores the risk so the register stays in step.
        if ($riskAssessment->methodology === 'qualitative' && $riskAssessment->score && $this->isLatest($riskAssessment)) {
            $this->scoringService->scoreRisk(
                $riskAssessment->risk, $riskAssessment->assessment_type,
                $riskAssessment->likelihood, $riskAssessment->impact,
                ucfirst($riskAssessment->assessment_type).' assessment revised'
            );
        }

        return redirect()->route('risk-assessments.show', $riskAssessment)
            ->with('success', 'Assessment updated successfully.');
    }

    public function destroy(RiskAssessment $riskAssessment)
    {
        $risk = $riskAssessment->risk;
        $wasLatest = $riskAssessment->methodology === 'qualitative' && $this->isLatest($riskAssessment);
        $type = $riskAssessment->assessment_type;

        $riskAssessment->delete();

        // Removing the assessment that set the current score falls back to the previous one.
        if ($risk && $wasLatest) {
            $previous = $this->latestFor($risk->id, $type);
            if ($previous?->likelihood && $previous?->impact) {
                $this->scoringService->scoreRisk($risk, $type, $previous->likelihood, $previous->impact,
                    ucfirst($type).' assessment withdrawn — reverted to assessment of '.$previous->assessment_date?->format('d M Y'));
            }
        }

        return redirect()->route($risk ? 'risks.show' : 'risk-assessments.index', $risk ?: [])
            ->with('success', 'Assessment deleted.');
    }

    private function latestFor(int $riskId, string $type): ?RiskAssessment
    {
        return RiskAssessment::where('risk_id', $riskId)
            ->where('assessment_type', $type)
            ->where('methodology', 'qualitative')
            ->latest('assessment_date')->latest('id')
            ->first();
    }

    private function isLatest(RiskAssessment $assessment): bool
    {
        return $this->latestFor($assessment->risk_id, $assessment->assessment_type)?->is($assessment) ?? false;
    }
}
