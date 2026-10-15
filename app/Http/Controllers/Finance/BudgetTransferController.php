<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsBudgets;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\Category;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\BudgetLedger;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Budget transfers / virements (FIN-02.10). */
class BudgetTransferController extends Controller
{
    use PresentsBudgets;

    public function __construct(
        private readonly BudgetTransferService $transfers,
        private readonly BudgetLedger $ledger,
        private readonly FinanceSettings $settings,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', BudgetTransfer::class);
        $orgId = (int) $user->organization_id;
        $fys = FiscalYear::query()->orderByDesc('starts_on')->get();
        $fyId = $request->integer('fiscal_year_id') ?: ($fys->firstWhere('is_current', true)?->id ?? $fys->first()?->id);
        $status = in_array($request->query('status'), BudgetTransfer::STATUSES, true) ? $request->query('status') : null;

        $rows = BudgetTransfer::query()->where('fiscal_year_id', $fyId)->when($status, fn ($q, $s) => $q->where('status', $s))
            ->with(['fiscalYear:id,year_label', 'fromLine.budget:id,budget_code,version_no', 'fromLine.costCentre:id,code,name', 'toLine.budget:id,budget_code,version_no',
                'toLine.costCentre:id,code,name', 'toCostCentre:id,code,name', 'toCostPool:id,code,name', 'toTower:id,code,name', 'fromPeriod:id,name', 'toPeriod:id,name',
                'requestedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('id')->get()->filter(fn ($t) => Gate::forUser($user)->allows('view', $t))->values();
        $all = BudgetTransfer::query()->where('fiscal_year_id', $fyId)->get();

        return $this->renderMasked('Finance/Budgets/Transfers', [
            'filters' => ['fiscal_year_id' => $fyId, 'status' => $status, 'highlight' => $request->integer('highlight') ?: null],
            'transfers' => $rows->map(fn (BudgetTransfer $t) => $this->item($t, $user))->values(),
            'summary' => [
                'draft' => $all->where('status', 'draft')->count(), 'pending' => $all->where('status', 'submitted')->count(),
                'approved_unapplied' => $all->where('status', 'approved')->count(), 'applied_count' => $all->where('status', 'applied')->count(),
                'applied_base' => $all->where('status', 'applied')->reduce(fn ($c, $t) => bcadd($c, (string) $t->base_amount, 2), '0'),
            ],
            'lineOptions' => $this->lineOptions($user, (int) $fyId),
            'periods' => $this->periodsMini(Period::query()->where('fiscal_year_id', $fyId)->where('is_adjustment', false)->where('status', 'open')->orderBy('sequence')->get()),
            'costCentres' => $this->visibleCostCentres($user),
            'costPools' => Category::query()->where('kind', 'cost_pool')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'towers' => Category::query()->where('kind', 'tower')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'fiscalYears' => $this->fiscalYearsPayload(),
            'baseCurrency' => $this->settings->baseCurrency($orgId),
            'can' => ['create' => $user->can('create it-finance')],
        ]);
    }

    private function item(BudgetTransfer $t, User $u): array
    {
        $mini = fn ($c) => $c ? ['id' => $c->id, 'code' => $c->code, 'name' => $c->name] : null;
        $line = fn (?BudgetLine $l) => $l ? ['id' => $l->id, 'line_no' => $l->line_no, 'description' => $l->description,
            'budget' => ['id' => $l->budget?->id, 'budget_code' => $l->budget?->budget_code, 'version_no' => $l->budget?->version_no], 'cost_centre' => $mini($l->costCentre)] : null;
        $mine = (int) $t->requested_by_id === (int) $u->id;
        $approver = Gate::forUser($u)->allows('decidePlan', $t) && ! $mine;

        return [
            'id' => $t->id, 'transfer_code' => $t->transfer_code, 'status' => $t->status,
            'fiscal_year' => $t->fiscalYear ? ['id' => $t->fiscalYear->id, 'year_label' => $t->fiscalYear->year_label] : null,
            'from_line' => $line($t->fromLine), 'to_line' => $line($t->toLine), 'to_cost_centre' => $mini($t->toCostCentre), 'to_cost_pool' => $mini($t->toCostPool),
            'to_tower' => $mini($t->toTower), 'to_description' => $t->to_description,
            'from_period' => $t->fromPeriod ? ['id' => $t->fromPeriod->id, 'name' => $t->fromPeriod->name] : null,
            'to_period' => $t->toPeriod ? ['id' => $t->toPeriod->id, 'name' => $t->toPeriod->name] : null,
            'amount' => (string) $t->amount, 'base_amount' => (string) $t->base_amount, 'currency' => $t->currency, 'reason' => $t->reason,
            'amount_band' => $t->amount_band, 'amount_band_label' => app(DoaBandService::class)->bandFor((int) $t->organization_id, (string) $t->base_amount)['label'], 'crosses_cost_centre' => $t->crosses_cost_centre, 'capex_opex' => $t->capex_opex,
            'requested_by' => $this->userMini($t->requestedBy), 'approved_by' => $this->userMini($t->approvedBy),
            'approved_at' => $t->approved_at?->toIso8601String(), 'applied_at' => $t->applied_at?->toIso8601String(), 'rejection_reason' => $t->rejection_reason,
            'can' => [
                'edit' => $t->status === 'draft' && $mine, 'submit' => $t->status === 'draft' && $mine,
                'withdraw' => in_array($t->status, ['draft', 'submitted'], true) && $mine,
                'approve' => $t->status === 'submitted' && $approver, 'reject' => $t->status === 'submitted' && $approver,
                'apply' => $t->status === 'approved' && Gate::forUser($u)->allows('decidePlan', $t),
            ],
        ];
    }

    /** @return array<int, array<string,mixed>> baseline lines of the fiscal year that the user can see */
    private function lineOptions(User $user, int $fyId): array
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);
        $budgets = Budget::query()->where('fiscal_year_id', $fyId)->where('status', 'baseline')->when($ids !== null, fn ($q) => $q->whereIn('cost_centre_id', $ids ?: [0]))
            ->with('costCentre:id,code')->get()->keyBy('id');

        return BudgetLine::query()->whereIn('budget_id', $budgets->keys())->orderBy('budget_id')->orderBy('line_no')->get()->map(function (BudgetLine $l) use ($budgets) {
            $b = $budgets[$l->budget_id];
            $by = $this->ledger->currentByPeriod($l);

            return ['id' => $l->id, 'label' => "{$b->budget_code} {$b->version_label} · #{$l->line_no} {$l->description} ({$b->costCentre?->code})",
                'cost_centre_id' => $l->cost_centre_id, 'is_capex' => $l->is_capex, 'current_base_amount' => $l->current_base_amount, 'available_by_period' => $by];
        })->values()->all();
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'fiscal_year_id' => ['required', 'integer'], 'from_line_id' => ['required', 'integer'], 'to_line_id' => ['nullable', 'integer'],
            'to_cost_centre_id' => ['nullable', 'integer'], 'to_cost_pool_id' => ['nullable', 'integer'], 'to_tower_id' => ['nullable', 'integer'],
            'to_category_id' => ['nullable', 'integer'], 'to_description' => ['nullable', 'string', 'max:255'], 'from_period_id' => ['nullable', 'integer'],
            'to_period_id' => ['nullable', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:5000'],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('createPlan', BudgetTransfer::class);
        $t = $this->transfers->create($this->data($request), $request->user());

        return back()->with('success', "Transfer {$t->transfer_code} drafted.");
    }

    public function update(Request $request, BudgetTransfer $transfer)
    {
        Gate::authorize('updatePlan', $transfer);
        $this->transfers->update($transfer, $this->data($request), $request->user());

        return back()->with('success', 'Transfer saved.');
    }

    public function submit(Request $request, BudgetTransfer $transfer)
    {
        Gate::authorize('updatePlan', $transfer);
        $this->transfers->submit($transfer, $request->user());

        return back()->with('success', "Transfer {$transfer->transfer_code} submitted for approval.");
    }

    public function withdraw(Request $request, BudgetTransfer $transfer)
    {
        Gate::authorize('updatePlan', $transfer);
        $this->transfers->withdraw($transfer, $request->user());

        return back()->with('success', 'Transfer withdrawn.');
    }

    public function approve(Request $request, BudgetTransfer $transfer)
    {
        Gate::authorize('decidePlan', $transfer);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return back()->with('success', $this->transfers->approve($transfer, $request->user(), $note));
    }

    public function reject(Request $request, BudgetTransfer $transfer)
    {
        Gate::authorize('decidePlan', $transfer);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->transfers->reject($transfer, $request->user(), $note);

        return back()->with('success', 'Transfer rejected.');
    }

    public function apply(Request $request, BudgetTransfer $transfer)
    {
        Gate::authorize('decidePlan', $transfer);
        $this->transfers->apply($transfer, $request->user());

        return back()->with('success', "Transfer {$transfer->transfer_code} applied.");
    }
}
