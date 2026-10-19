<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsFinOps;
use App\Http\Requests\Finance\AllocationRequest;
use App\Http\Requests\Finance\AllocationRunRequest;
use App\Models\Finance\Allocation;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Driver;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Models\LegalEntity;
use App\Services\Finance\AllocationService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Allocation rules, their coverage and the run that computes their results (FIN-02.12). */
class AllocationController extends Controller
{
    use PresentsFinOps;

    public function __construct(private readonly AllocationService $allocations, private readonly MonitoringService $monitoring, private readonly FinanceSettings $settings) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Allocation::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $v = $request->validate([
            'fiscal_year_id' => ['nullable', 'integer'], 'target_type' => ['nullable', 'in:'.implode(',', Allocation::TARGET_TYPES)], 'source_type' => ['nullable', 'in:'.implode(',', Allocation::SOURCE_TYPES)],
            'method' => ['nullable', 'in:'.implode(',', Allocation::METHODS)], 'active' => ['nullable', 'in:0,1'],
        ]);
        $fy = $this->monitoring->resolveFiscalYear($orgId, ! empty($v['fiscal_year_id']) ? (int) $v['fiscal_year_id'] : null);
        $periods = $fy ? $this->finPeriods($orgId, $fy->id) : [];
        $today = FinanceClock::today()->toDateString();
        $current = collect($periods)->first(fn ($p) => $p['starts_on'] <= $today && $p['ends_on'] >= $today) ?? collect($periods)->first(fn ($p) => $p['status'] === 'open');
        $currentPeriod = $current ? Period::withoutGlobalScopes()->find($current['id']) : null;

        $rules = Allocation::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->with('driver:id,code,name,unit')->withCount('results')
            ->when(! empty($v['target_type']), fn ($q) => $q->where('target_type', $v['target_type']))->when(! empty($v['source_type']), fn ($q) => $q->where('source_type', $v['source_type']))
            ->when(! empty($v['method']), fn ($q) => $q->where('method', $v['method']))->when(isset($v['active']) && $v['active'] !== '', fn ($q) => $q->where('is_active', (bool) (int) $v['active']))
            ->orderBy('target_type')->orderBy('source_type')->orderBy('allocation_code')->get();
        $shares = [];
        if ($currentPeriod) {
            foreach ($rules->pluck('target_type')->unique() as $lens) {
                foreach ($this->allocations->shares($orgId, $lens, $currentPeriod) as $group) {
                    $shares += $group;
                }
            }
        }
        $manage = Gate::forUser($user)->allows('manageAllocations', Allocation::class);
        $coverage = [];
        foreach ($rules as $r) {
            $k = $r->target_type.'|'.$r->source_type.'|'.$r->source_id;
            $coverage[$k] ??= ['target_type' => $r->target_type, 'source_type' => $r->source_type, 'source_id' => (int) $r->source_id, 'label' => $r->source_label, 'allocated_pct' => '0.000000', 'rules' => 0];
            if (isset($shares[$r->id])) {
                $coverage[$k]['allocated_pct'] = bcadd($coverage[$k]['allocated_pct'], $shares[$r->id], 6);
                $coverage[$k]['rules']++;
            }
        }
        $runs = $fy ? AllocationRun::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fy->id)->with('period:id,name,status')->orderByDesc('computed_at')->orderByDesc('id')->limit(60)->get() : collect();

        $cc = $this->finCostCentres($user, true);
        $lines = $fy ? $this->monitoring->baselineLines($orgId, $fy->id)->take(500) : collect();
        $ref = fn ($rows, $label) => collect($rows)->map(fn ($r) => ['id' => $r['id'], 'label' => $label($r)])->values()->all();

        return $this->renderFinOps('Finance/Budgets/Allocations', [
            'filters' => ['fiscal_year_id' => $fy?->id, 'target_type' => $v['target_type'] ?? '', 'source_type' => $v['source_type'] ?? '', 'method' => $v['method'] ?? '', 'active' => $v['active'] ?? ''],
            'rules' => $rules->map(fn (Allocation $r) => [
                'id' => $r->id, 'allocation_code' => $r->allocation_code, 'name' => $r->name, 'source_type' => $r->source_type, 'source' => ['id' => (int) $r->source_id, 'label' => $r->source_label],
                'target_type' => $r->target_type, 'target' => ['id' => $r->target_id ? (int) $r->target_id : null, 'ref' => $r->target_ref, 'label' => $r->target_label], 'method' => $r->method,
                'percentage' => $r->percentage === null ? null : (string) $r->percentage, 'driver' => $r->driver ? ['id' => $r->driver->id, 'code' => $r->driver->code, 'name' => $r->driver->name, 'unit' => $r->driver->unit] : null,
                'driver_quantity' => $r->driver_quantity === null ? null : (string) $r->driver_quantity, 'share_pct' => $shares[$r->id] ?? null, 'effective_from' => $r->effective_from->toDateString(),
                'effective_to' => $r->effective_to?->toDateString(), 'is_active' => (bool) $r->is_active, 'journal_cost_centre_code' => $r->journal_cost_centre_code, 'has_results' => $r->results_count > 0,
                'can' => ['edit' => $manage, 'end' => $manage && $r->effective_to === null, 'delete' => $manage && $r->results_count === 0],
            ])->values(),
            'coverage' => array_values($coverage),
            'runs' => $runs->map(fn (AllocationRun $r) => [
                'id' => $r->id, 'period' => ['id' => $r->period?->id, 'name' => $r->period?->name, 'status' => $r->period?->status], 'amount_type' => $r->amount_type, 'target_type' => $r->target_type,
                'results_count' => (int) $r->results_count, 'pool_total' => (string) $r->pool_total, 'allocated_total' => (string) $r->allocated_total, 'computed_at' => $r->computed_at?->toIso8601String(), 'is_frozen' => (bool) $r->is_frozen,
            ])->values(),
            'sources' => [
                'cost_centre' => $ref($cc, fn ($c) => "{$c['code']} {$c['name']}"),
                'category' => Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('kind')->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'kind'])
                    ->map(fn ($c) => ['id' => $c->id, 'label' => "{$c->code} {$c->name}"])->values()->all(),
                'budget_line' => $lines->map(fn (BudgetLine $l) => ['id' => $l->id, 'label' => "#{$l->line_no} {$l->description}"])->values()->all(),
                'contract' => $this->finContracts($orgId),
            ],
            'targets' => [
                'business_unit' => collect($this->settings->showbackUnits($orgId))->map(fn ($u) => ['ref' => $u['code'], 'label' => $u['code'].' '.$u['name']])->values()->all(),
                'cost_centre' => $ref($cc, fn ($c) => "{$c['code']} {$c['name']}"), 'business_service' => $this->finBusinessServices($orgId), 'ea_application' => $this->finApplications($orgId),
                'investment' => Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('investment_code')->get(['id', 'investment_code', 'title'])
                    ->map(fn ($i) => ['id' => $i->id, 'label' => "{$i->investment_code} {$i->title}"])->values()->all(),
                'legal_entity' => LegalEntity::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($e) => ['id' => $e->id, 'label' => trim("{$e->code} {$e->name}")])->values()->all(),
            ],
            'drivers' => Driver::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'unit'])->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'unit' => $d->unit])->values(),
            'fiscalYears' => collect($this->finFiscalYears($orgId))->map(fn ($f) => ['id' => $f['id'], 'year_label' => $f['year_label']])->values(),
            'periods' => collect($periods)->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'status' => $p['status']])->values(), 'firstOpenDate' => $this->allocations->openFloor($orgId),
            'options' => [
                'sourceTypes' => $this->opts(['cost_centre' => 'Cost centre', 'category' => 'Category', 'budget_line' => 'Budget line', 'contract' => 'Contract']),
                'targetTypes' => $this->opts(['business_unit' => 'Business unit', 'cost_centre' => 'Cost centre', 'business_service' => 'Business service', 'ea_application' => 'EA application', 'investment' => 'Investment', 'legal_entity' => 'Legal entity']),
                'methods' => $this->opts(['direct' => 'Direct (100 %)', 'percentage' => 'Fixed percentage', 'driver' => 'By driver']), 'amountTypes' => $this->opts(['budget' => 'Budget', 'actual' => 'Actual']),
            ],
            'can' => ['manage' => $manage, 'run' => $manage],
        ]);
    }

    public function store(AllocationRequest $request)
    {
        Gate::authorize('manageAllocations', Allocation::class);
        $a = $this->allocations->store($request->validated(), $request->user());

        return back()->with('success', "Allocation rule {$a->allocation_code} created.");
    }

    public function update(AllocationRequest $request, Allocation $allocation)
    {
        Gate::authorize('manageAllocations', Allocation::class);
        $this->allocations->update($allocation, $request->validated(), $request->user());

        return back()->with('success', "Allocation rule {$allocation->allocation_code} saved.");
    }

    public function end(Request $request, Allocation $allocation)
    {
        Gate::authorize('manageAllocations', Allocation::class);
        $d = $request->validate(['effective_to' => ['required', 'date']]);
        $this->allocations->end($allocation, $d['effective_to'], $request->user());

        return back()->with('success', "Allocation rule {$allocation->allocation_code} ended.");
    }

    public function destroy(Request $request, Allocation $allocation)
    {
        Gate::authorize('manageAllocations', Allocation::class);
        $this->allocations->destroy($allocation, $request->user());

        return back()->with('success', 'Allocation rule deleted.');
    }

    public function run(AllocationRunRequest $request)
    {
        Gate::authorize('manageAllocations', Allocation::class);
        $d = $request->validated();
        FiscalYear::query()->findOrFail((int) $d['fiscal_year_id']);
        $out = $this->allocations->run((int) $request->user()->organization_id, (int) $d['fiscal_year_id'], $d['period_ids'] ?? null, $d['amount_types'], $request->user());
        $c = collect($out);

        return back()->with('success', "Allocations run: {$c->where('status', 'computed')->count()} computed, {$c->where('status', 'frozen')->count()} frozen periods left as they were.");
    }
}
