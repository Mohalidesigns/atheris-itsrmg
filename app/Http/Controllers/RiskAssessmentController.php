<?php

namespace App\Http\Controllers;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Services\FairQuantificationService;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
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
            ->latest('assessment_date')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Risks/Assessments/Index', [
            'assessments' => $assessments,
        ]);
    }

    public function create(Request $request)
    {
        $risk = $request->has('risk_id') ? Risk::findOrFail($request->risk_id) : null;

        return Inertia::render('Risks/Assessments/Create', [
            'risk' => $risk,
            'risks' => Risk::select('id', 'title', 'risk_id_code')->get(),
            'likelihoodLabels' => RiskScoringService::LIKELIHOOD_LABELS,
            'impactLabels' => RiskScoringService::IMPACT_LABELS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'risk_id' => 'required|exists:risks,id',
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
            'fair_tef' => 'required_if:methodology,fair|nullable|numeric|min:0',
            'fair_vul' => 'required_if:methodology,fair|nullable|numeric|min:0|max:1',
            'fair_plm' => 'required_if:methodology,fair|nullable|numeric|min:0',
            'fair_slm' => 'nullable|numeric|min:0',
            'fair_currency' => 'nullable|string|size:3',

            'justification' => 'nullable|string',
            'notes' => 'nullable|string',
            'next_review_date' => 'nullable|date',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['assessed_by'] = auth()->id();
        $validated['assessment_date'] = now();

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

        $assessment = RiskAssessment::create($validated);

        // Update risk scores
        $risk = Risk::findOrFail($validated['risk_id']);
        $type = $validated['assessment_type'];

        if ($validated['methodology'] === 'qualitative') {
            $this->scoringService->scoreRisk(
                $risk, $type,
                $validated['likelihood'],
                $validated['impact'],
                'Assessment completed'
            );
        }

        if ($validated['methodology'] === 'fair') {
            $risk->update([
                'fair_annual_loss_expectancy' => $fairResult['ale'],
                'fair_single_loss_expectancy' => $fairResult['sle'],
            ]);
        }

        return redirect()->route('risks.show', $risk)
            ->with('success', ucfirst($type) . ' assessment recorded.');
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

        return redirect()->route('risk-assessments.show', $riskAssessment)
            ->with('success', 'Assessment updated successfully.');
    }

    public function destroy(RiskAssessment $riskAssessment)
    {
        $riskAssessment->delete();

        return redirect()->route('risk-assessments.index')
            ->with('success', 'Assessment deleted.');
    }
}
