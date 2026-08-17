<?php

namespace App\Http\Controllers;

use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RiskTreatmentController extends Controller
{
    public function index(Request $request)
    {
        $query = RiskTreatment::with([
            'risk:id,title,risk_id_code',
            'assignee:id,name',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $treatments = $query->latest()->paginate(15)->withQueryString();

        return Inertia::render('Risks/Treatments/Index', [
            'treatments' => $treatments,
            'filters' => $request->only('status'),
            'statuses' => RiskTreatment::STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        $risk = $request->has('risk_id') ? Risk::findOrFail($request->risk_id) : null;

        return Inertia::render('Risks/Treatments/Create', [
            'risk' => $risk,
            'risks' => Risk::select('id', 'title', 'risk_id_code')->get(),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'strategies' => RiskTreatment::STRATEGIES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'risk_id' => 'required|exists:risks,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'strategy' => 'required|in:' . implode(',', RiskTreatment::STRATEGIES),
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'priority' => 'nullable|integer|min:1|max:5',
            'estimated_cost' => 'nullable|numeric|min:0',
            'cost_currency' => 'nullable|string|size:3',
            'target_likelihood' => 'nullable|integer|min:1|max:5',
            'target_impact' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|string',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;

        if (isset($validated['target_likelihood']) && isset($validated['target_impact'])) {
            $validated['target_score'] = $validated['target_likelihood'] * $validated['target_impact'];
        }

        $treatment = RiskTreatment::create($validated);

        // Update risk status
        $risk = Risk::findOrFail($validated['risk_id']);
        if (in_array($risk->status, ['identified', 'assessed'])) {
            $risk->update([
                'status' => 'treating',
                'treatment_strategy' => $validated['strategy'],
                'treatment_due_date' => $validated['due_date'] ?? null,
            ]);
        }

        return redirect()->route('risks.show', $risk)
            ->with('success', 'Treatment plan created.');
    }

    public function show(RiskTreatment $riskTreatment)
    {
        $riskTreatment->load([
            'risk:id,title,risk_id_code,status,inherent_score,residual_score',
            'assignee:id,name,email,job_title',
        ]);

        return Inertia::render('Risks/Treatments/Show', [
            'treatment' => $riskTreatment,
            'statuses' => RiskTreatment::STATUSES,
        ]);
    }

    public function edit(RiskTreatment $riskTreatment)
    {
        return Inertia::render('Risks/Treatments/Edit', [
            'treatment' => $riskTreatment->load('risk:id,title,risk_id_code'),
            'users' => User::where('organization_id', auth()->user()->organization_id)
                ->select('id', 'name')->get(),
            'strategies' => RiskTreatment::STRATEGIES,
            'statuses' => RiskTreatment::STATUSES,
        ]);
    }

    public function update(Request $request, RiskTreatment $riskTreatment)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'strategy' => 'required|in:' . implode(',', RiskTreatment::STRATEGIES),
            'status' => 'nullable|in:' . implode(',', RiskTreatment::STATUSES),
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'priority' => 'nullable|integer|min:1|max:5',
            'estimated_cost' => 'nullable|numeric|min:0',
            'cost_currency' => 'nullable|string|size:3',
            'completion_percentage' => 'nullable|integer|min:0|max:100',
            'target_likelihood' => 'nullable|integer|min:1|max:5',
            'target_impact' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|string',
        ]);

        if (empty($validated['status'])) {
            unset($validated['status']);
        }

        if (isset($validated['target_likelihood']) && isset($validated['target_impact'])) {
            $validated['target_score'] = $validated['target_likelihood'] * $validated['target_impact'];
        }

        if (($validated['status'] ?? null) === 'completed' && $riskTreatment->status !== 'completed') {
            $validated['completed_at'] = now();
            $validated['completion_percentage'] = 100;
        }

        $riskTreatment->update($validated);

        return redirect()->route('risk-treatments.show', $riskTreatment)
            ->with('success', 'Treatment updated successfully.');
    }

    public function destroy(RiskTreatment $riskTreatment)
    {
        $riskTreatment->delete();

        return redirect()->route('risk-treatments.index')
            ->with('success', 'Treatment deleted.');
    }

    public function updateStatus(Request $request, RiskTreatment $treatment)
    {
        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', RiskTreatment::STATUSES),
            'completion_percentage' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        if ($validated['status'] === 'approved') {
            $validated['approved_by'] = auth()->id();
            $validated['approved_at'] = now();
        }

        if ($validated['status'] === 'completed') {
            $validated['completed_at'] = now();
            $validated['completion_percentage'] = 100;
        }

        $treatment->update($validated);

        return back()->with('success', 'Treatment status updated.');
    }
}
