<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetScenario;
use App\Services\Finance\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** What-if scenarios on a draft budget and their promotion (FIN-02.07). */
class BudgetScenarioController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    private function data(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'adjustment_pct' => ['required', 'numeric', 'between:-100,500'],
            'line_adjustments' => ['nullable', 'array'], 'line_adjustments.*' => ['nullable', 'numeric', 'between:-100,500'],
        ]);
    }

    public function store(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        $this->budgets->scenarioSave($budget, $this->data($request));

        return back()->with('success', 'Scenario added.');
    }

    public function update(Request $request, Budget $budget, BudgetScenario $scenario)
    {
        Gate::authorize('updatePlan', $budget);
        $this->budgets->scenarioSave($budget, $this->data($request), $scenario);

        return back()->with('success', 'Scenario saved.');
    }

    public function destroy(Budget $budget, BudgetScenario $scenario)
    {
        Gate::authorize('updatePlan', $budget);
        $this->budgets->scenarioDestroy($scenario);

        return back()->with('success', 'Scenario deleted.');
    }

    public function promote(Request $request, Budget $budget, BudgetScenario $scenario)
    {
        Gate::authorize('updatePlan', $budget);
        $this->budgets->promoteScenario($scenario, $request->user());

        return back()->with('success', "Scenario \"{$scenario->name}\" promoted; the lines were re-scaled.");
    }
}
