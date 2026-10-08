<?php

namespace App\Http\Controllers;

use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Models\User;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RiskTreatmentController extends Controller
{
    public function __construct(
        private RiskScoringService $scoringService
    ) {}

    public function index(Request $request)
    {
        $query = RiskTreatment::with([
            'risk:id,title,risk_id_code',
            'assignee:id,name',
        ]);

        if ($request->filled('status')) {
            $request->status === 'overdue'
                ? $query->overdue()
                : $query->where('status', $request->status);
        }
        if ($request->filled('strategy')) {
            $query->where('strategy', $request->strategy);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhereHas('risk', fn ($r) => $r->where('risk_id_code', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%")));
        }

        $treatments = $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->latest('id')
            ->paginate(15)->withQueryString();

        return Inertia::render('Risks/Treatments/Index', [
            'treatments' => $treatments,
            'filters' => $request->only('status', 'strategy', 'search'),
            'statuses' => RiskTreatment::STATUSES,
            'strategies' => RiskTreatment::STRATEGIES,
            'summary' => [
                'open' => RiskTreatment::whereNotIn('status', RiskTreatment::CLOSED_STATUSES)->count(),
                'overdue' => RiskTreatment::overdue()->count(),
                'completed' => RiskTreatment::where('status', 'completed')->count(),
                'awaiting_approval' => RiskTreatment::where('status', 'submitted')->count(),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $risk = $request->filled('risk_id') ? Risk::findOrFail($request->risk_id) : null;

        return Inertia::render('Risks/Treatments/Create', [
            'risk' => $risk,
            'risks' => Risk::active()->select('id', 'title', 'risk_id_code', 'risk_owner_id', 'treatment_strategy')->orderBy('risk_id_code')->get(),
            'users' => $this->orgUsers(),
            'strategies' => RiskTreatment::STRATEGIES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules() + [
            'risk_id' => ['required', Rule::exists('risks', 'id')
                ->where('organization_id', auth()->user()->organization_id)->whereNull('deleted_at')],
        ]);

        $risk = Risk::findOrFail($validated['risk_id']);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['status'] = 'draft';
        $validated['target_score'] = $this->targetScore($validated);

        DB::transaction(function () use ($validated, $risk) {
            RiskTreatment::create($validated);

            // A first treatment plan moves the risk into treatment.
            if (in_array($risk->status, ['identified', 'assessed'], true)) {
                $risk->update([
                    'status' => 'treating',
                    'treatment_strategy' => $validated['strategy'],
                    'treatment_due_date' => $validated['due_date'] ?? $risk->treatment_due_date,
                ]);
            }
        });

        return redirect()->route('risks.show', $risk)
            ->with('success', 'Treatment plan created.');
    }

    public function show(RiskTreatment $riskTreatment)
    {
        $riskTreatment->load([
            'risk:id,title,risk_id_code,status,inherent_score,residual_score,residual_likelihood,residual_impact',
            'assignee:id,name,email,job_title',
            'approver:id,name',
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
            'users' => $this->orgUsers(),
            'strategies' => RiskTreatment::STRATEGIES,
            'statuses' => RiskTreatment::STATUSES,
        ]);
    }

    public function update(Request $request, RiskTreatment $riskTreatment)
    {
        $validated = $request->validate($this->rules() + [
            'status' => ['nullable', Rule::in(RiskTreatment::STATUSES)],
            'completion_percentage' => 'nullable|integer|min:0|max:100',
        ]);

        $validated['target_score'] = $this->targetScore($validated);
        $status = $validated['status'] ?? null;
        unset($validated['status']);

        $riskTreatment->fill($validated);
        $this->transition($riskTreatment, $status ?: $riskTreatment->status);

        return redirect()->route('risk-treatments.show', $riskTreatment)
            ->with('success', 'Treatment updated successfully.');
    }

    public function destroy(RiskTreatment $riskTreatment)
    {
        $risk = $riskTreatment->risk;
        $riskTreatment->delete();

        return redirect()->route($risk ? 'risks.show' : 'risk-treatments.index', $risk ?: [])
            ->with('success', 'Treatment deleted.');
    }

    public function updateStatus(Request $request, RiskTreatment $treatment)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(RiskTreatment::STATUSES)],
            'completion_percentage' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $treatment->fill(collect($validated)->except('status')->filter(fn ($v) => $v !== null)->all());
        $this->transition($treatment, $validated['status']);

        return back()->with('success', 'Treatment status updated to '.str_replace('_', ' ', $validated['status']).'.');
    }

    /**
     * One place for status side effects so Edit and the quick status control behave the same:
     * approval is stamped once, completion is stamped on the transition (and cleared if re-opened),
     * and completing the last open plan moves the risk to "mitigated" at its target residual score.
     */
    private function transition(RiskTreatment $treatment, string $status): void
    {
        $previous = $treatment->getOriginal('status');
        $treatment->status = $status;

        if ($status === 'approved' && ! $treatment->approved_at) {
            $treatment->approved_by = auth()->id();
            $treatment->approved_at = now();
        }

        if ($status === 'completed' && $previous !== 'completed') {
            $treatment->completed_at = now();
            $treatment->completion_percentage = 100;
        } elseif ($status !== 'completed' && $previous === 'completed') {
            $treatment->completed_at = null;
        }

        DB::transaction(function () use ($treatment, $status, $previous) {
            $treatment->save();

            if ($status === 'completed' && $previous !== 'completed') {
                $this->applyCompletionToRisk($treatment);
            }
        });
    }

    private function applyCompletionToRisk(RiskTreatment $treatment): void
    {
        $risk = $treatment->risk;
        if (! $risk) {
            return;
        }

        $stillOpen = $risk->treatments()
            ->whereKeyNot($treatment->id)
            ->whereNotIn('status', RiskTreatment::CLOSED_STATUSES)
            ->exists();

        if ($treatment->target_likelihood && $treatment->target_impact) {
            $this->scoringService->scoreRisk($risk, 'residual', $treatment->target_likelihood, $treatment->target_impact,
                "Treatment completed: {$treatment->title}");
        }

        if (! $stillOpen && in_array($risk->status, ['identified', 'assessed', 'treating'], true)) {
            $risk->update(['status' => 'mitigated']);
        }
    }

    private function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'strategy' => ['required', Rule::in(RiskTreatment::STRATEGIES)],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id)],
            'due_date' => 'nullable|date',
            'priority' => 'nullable|integer|min:1|max:5',
            'estimated_cost' => 'nullable|numeric|min:0',
            'cost_currency' => 'nullable|string|size:3',
            'target_likelihood' => 'nullable|integer|min:1|max:5|required_with:target_impact',
            'target_impact' => 'nullable|integer|min:1|max:5|required_with:target_likelihood',
            'notes' => 'nullable|string',
        ];
    }

    private function targetScore(array $validated): ?int
    {
        return ! empty($validated['target_likelihood']) && ! empty($validated['target_impact'])
            ? $validated['target_likelihood'] * $validated['target_impact']
            : null;
    }

    private function orgUsers()
    {
        return User::where('organization_id', auth()->user()->organization_id)
            ->orderBy('name')->select('id', 'name')->get();
    }
}
