<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsBudgets;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetTarget;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\FinanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Budget cycles (FIN-02.01). */
class BudgetCycleController extends Controller
{
    use PresentsBudgets;

    public function __construct(private readonly BudgetCycleService $cycles) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', BudgetCycle::class);
        $budgets = Budget::query()->visibleTo($user)->whereNotIn('status', ['withdrawn'])->get()->groupBy('cycle_id');
        $targets = BudgetTarget::query()->get()->groupBy('cycle_id');

        return $this->renderMasked('Finance/Budgets/Cycles', [
            'cycles' => BudgetCycle::query()->with(['fiscalYear:id,year_label', 'coordinator:id,name'])->orderByDesc('opens_on')->get()->map(function (BudgetCycle $c) use ($budgets, $targets) {
                $latest = $budgets->get($c->id, collect())->groupBy('budget_code')->map(fn ($g) => $g->sortByDesc('version_no')->first());

                return [
                    'id' => $c->id, 'cycle_code' => $c->cycle_code, 'name' => $c->name, 'type' => $c->type, 'status' => $c->status,
                    'fiscal_year' => ['id' => $c->fiscalYear->id, 'year_label' => $c->fiscalYear->year_label],
                    'opens_on' => $c->opens_on->toDateString(), 'closes_on' => $c->closes_on->toDateString(), 'instructions' => $c->instructions,
                    'coordinator' => $this->userMini($c->coordinator),
                    'budgets_count' => $latest->count(), 'in_approval_count' => $latest->whereIn('status', Budget::IN_APPROVAL)->count(),
                    'approved_count' => $latest->where('status', 'approved')->count(), 'baseline_count' => $latest->where('status', 'baseline')->count(),
                    'requested_base' => $latest->reduce(fn ($carry, $b) => bcadd($carry, (string) $b->total_base_amount, 2), '0'),
                    'ceiling_total' => $targets->has($c->id) ? $targets->get($c->id)->reduce(fn ($carry, $t) => bcadd(bcadd($carry, (string) ($t->capex_ceiling ?? 0), 2), (string) ($t->opex_ceiling ?? 0), 2), '0') : null,
                ];
            })->values(),
            'fiscalYears' => $this->fiscalYearsPayload(),
            'users' => $this->usersPayload((int) $user->organization_id),
            'baseCurrency' => app(FinanceSettings::class)->baseCurrency((int) $user->organization_id),
            'types' => BudgetCycle::TYPES, 'statuses' => BudgetCycle::STATUSES,
            'can' => ['manage' => $user->can('manage it-finance-settings')],
        ]);
    }

    private function data(Request $request): array
    {
        $orgId = (int) $request->user()->organization_id;

        return $request->validate([
            'fiscal_year_id' => ['required', 'integer', Rule::exists('fin_fiscal_years', 'id')->where('organization_id', $orgId)],
            'name' => ['required', 'string', 'max:160'], 'type' => ['required', Rule::in(BudgetCycle::TYPES)],
            'opens_on' => ['required', 'date'], 'closes_on' => ['required', 'date', 'after_or_equal:opens_on'],
            'instructions' => ['nullable', 'string', 'max:10000'], 'coordinator_id' => ['nullable', 'integer'],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('managePlanning', BudgetCycle::class);
        $cycle = $this->cycles->create((int) $request->user()->organization_id, $this->data($request), $request->user());

        return back()->with('success', "Cycle {$cycle->cycle_code} created.");
    }

    public function update(Request $request, BudgetCycle $cycle)
    {
        Gate::authorize('managePlanning', $cycle);
        $this->cycles->update($cycle, $this->data($request));

        return back()->with('success', 'Cycle saved.');
    }

    public function transition(Request $request, BudgetCycle $cycle)
    {
        Gate::authorize('managePlanning', $cycle);
        $action = $request->validate(['action' => ['required', Rule::in(array_keys(BudgetCycleService::TRANSITIONS))]])['action'];
        $this->cycles->transition($cycle, $action, $request->user());

        return back()->with('success', "Cycle {$cycle->cycle_code} is now {$cycle->status}.");
    }
}
