<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsBudgets;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetTarget;
use App\Models\Finance\Category;
use App\Models\LegalEntity;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\FinanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** CapEx / OpEx ceilings per cycle (FIN-02.03). */
class BudgetTargetController extends Controller
{
    use PresentsBudgets;

    public function __construct(private readonly BudgetCycleService $cycles) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', BudgetTarget::class);
        $all = BudgetCycle::query()->orderByDesc('opens_on')->get();
        $cycle = $request->integer('cycle_id') ? $all->firstWhere('id', $request->integer('cycle_id')) : ($all->firstWhere('status', 'open') ?? $all->first());

        $rows = [];
        $visible = FinancePolicy::visibleCostCentreIds($user); // null = bank-wide
        if ($cycle) {
            $usage = collect($this->cycles->targetUsage($cycle))->keyBy('id');
            $rows = BudgetTarget::query()->where('cycle_id', $cycle->id)->with(['costCentre:id,code,name', 'category:id,code,name,kind', 'legalEntity:id,code,name', 'setBy:id,name'])
                ->orderBy('id')->get()
                ->filter(fn (BudgetTarget $t) => $visible === null || ($t->cost_centre_id !== null && in_array((int) $t->cost_centre_id, $visible, true)))
                ->map(function (BudgetTarget $t) use ($usage) {
                    $u = $usage[$t->id];

                    return [
                        'id' => $t->id, 'scope_label' => $t->scope_label,
                        'cost_centre' => $t->costCentre ? ['id' => $t->costCentre->id, 'code' => $t->costCentre->code, 'name' => $t->costCentre->name] : null,
                        'category' => $t->category ? ['id' => $t->category->id, 'code' => $t->category->code, 'name' => $t->category->name, 'kind' => $t->category->kind] : null,
                        'legal_entity' => $t->legalEntity ? ['id' => $t->legalEntity->id, 'code' => $t->legalEntity->code, 'name' => $t->legalEntity->name] : null,
                        'capex_ceiling' => $u['capex_ceiling'], 'opex_ceiling' => $u['opex_ceiling'],
                        'capex_requested' => $u['capex_requested'], 'opex_requested' => $u['opex_requested'], 'capex_pct' => $u['capex_pct'], 'opex_pct' => $u['opex_pct'],
                        'over' => $u['over'], 'notes' => $t->notes, 'set_by' => $this->userMini($t->setBy), 'updated_at' => $t->updated_at?->toIso8601String(),
                    ];
                })->values()->all();
        }

        return $this->renderMasked('Finance/Budgets/Targets', [
            'cycle' => $cycle ? ['id' => $cycle->id, 'cycle_code' => $cycle->cycle_code, 'name' => $cycle->name] : null,
            'cycles' => $all->map(fn ($c) => ['id' => $c->id, 'cycle_code' => $c->cycle_code, 'name' => $c->name, 'type' => $c->type, 'status' => $c->status, 'fiscal_year_id' => $c->fiscal_year_id])->values(),
            'targets' => $rows,
            'costCentres' => $this->visibleCostCentres($user),
            'categories' => Category::query()->whereIn('kind', ['cost_pool', 'tower'])->where('is_active', true)->orderBy('kind')->orderBy('code')->get()
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind])->values(),
            'legalEntities' => LegalEntity::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->name])->values(),
            'baseCurrency' => app(FinanceSettings::class)->baseCurrency((int) $user->organization_id),
            'can' => ['manage' => $user->can('manage it-finance-settings')],
        ]);
    }

    private function data(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'cycle_id' => [$creating ? 'required' : 'sometimes', 'integer'], 'cost_centre_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'], 'legal_entity_id' => ['nullable', 'integer'],
            'capex_ceiling' => ['nullable', 'numeric', 'min:0'], 'opex_ceiling' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('managePlanning', BudgetTarget::class);
        $data = $this->data($request, true);
        $cycle = BudgetCycle::query()->findOrFail($data['cycle_id']);
        $this->cycles->saveTarget($cycle, $data, $request->user());

        return back()->with('success', 'Target saved.');
    }

    public function update(Request $request, BudgetTarget $target)
    {
        Gate::authorize('managePlanning', $target);
        $data = $this->data($request);
        $this->cycles->saveTarget($target->cycle, $data, $request->user(), $target);

        return back()->with('success', 'Target saved.');
    }

    public function destroy(BudgetTarget $target)
    {
        Gate::authorize('managePlanning', $target);
        $this->cycles->deleteTarget($target);

        return back()->with('success', 'Target removed.');
    }
}
