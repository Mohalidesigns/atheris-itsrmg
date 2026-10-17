<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsExpenses;
use App\Models\Finance\Accrual;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\AccrualService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Accruals and prepayment releases (FIN-03.06). Managing them needs `close it-finance-periods`. */
class AccrualController extends Controller
{
    use PresentsExpenses;

    public function __construct(private readonly AccrualService $accruals) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Accrual::class);
        $fys = FiscalYear::query()->orderByDesc('starts_on')->get(['id', 'year_label', 'is_current']);
        $fy = $request->integer('fiscal_year_id') ?: ($fys->firstWhere('is_current', true)?->id ?? $fys->first()?->id);
        $filters = [
            'fiscal_year_id' => $fy, 'period_id' => $request->integer('period_id') ?: null,
            'type' => in_array($request->query('type'), Accrual::TYPES, true) ? $request->query('type') : null,
            'status' => in_array($request->query('status'), Accrual::STATUSES, true) ? $request->query('status') : null,
        ];
        $visible = FinancePolicy::visibleCostCentreIds($user);
        $scope = fn () => Accrual::query()->where('fiscal_year_id', $fy)->when($visible !== null, fn ($q) => $q->whereIn('cost_centre_id', $visible ?: [0]));
        $rows = $scope()->with(['period', 'reversalPeriod', 'po:id,po_code', 'invoice:id,invoice_code', 'recurringExpense:id,recurring_code', 'budgetLine', 'costCentre:id,code,name'])
            ->when($filters['period_id'], fn ($q, $v) => $q->where('period_id', $v))->when($filters['type'], fn ($q, $v) => $q->where('type', $v))->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->orderBy('period_id')->orderBy('id')->limit(500)->get();
        $this->primeLines($rows->pluck('budget_line_id'));
        $sum = fn ($status, $type = null) => bcadd((string) $scope()->where('status', $status)->when($type, fn ($q) => $q->where('type', $type))->sum('base_amount'), '0', 2);
        $prepaid = bcsub((string) $scope()->where('type', 'prepayment_release')->whereIn('status', ['proposed', 'posted'])->sum('base_amount'), (string) $scope()->where('type', 'prepayment_release')->where('status', 'posted')->sum('base_amount'), 2);

        return $this->renderExpenses('Finance/Accruals/Index', [
            'filters' => $filters, 'accruals' => $rows->map(fn ($a) => $this->accrualRow($a))->values(),
            'summary' => ['proposed_base' => $sum('proposed'), 'posted_base' => $sum('posted'), 'reversed_base' => $sum('reversed'), 'prepaid_balance_base' => $prepaid],
            'periods' => Period::query()->where('fiscal_year_id', $fy)->where('is_adjustment', false)->orderBy('sequence')->get()->map(fn ($p) => $this->periodMini($p))->values(),
            'fiscalYears' => $fys->map(fn ($f) => ['id' => $f->id, 'year_label' => $f->year_label])->values(),
            'budgetLines' => $this->budgetLineOptions($user), 'costCentres' => $this->costCentreOptions($user), 'glAccounts' => $this->glOptions(), 'currencies' => $this->currencyOptions(),
            'can' => ['manage' => Gate::forUser($user)->allows('manageAccruals', Accrual::class)],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manageAccruals', Accrual::class);
        $d = $request->validate([
            'period_id' => ['required', 'integer'], 'cost_centre_id' => ['required', 'integer'], 'budget_line_id' => ['nullable', 'integer'], 'gl_account_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:255'], 'currency' => ['nullable', 'string', 'size:3'], 'amount' => ['required', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
        ]);
        $this->accruals->store($d, $request->user());

        return back()->with('success', 'Accrual proposed.');
    }

    public function propose(Request $request)
    {
        Gate::authorize('manageAccruals', Accrual::class);
        $p = Period::query()->findOrFail($request->validate(['period_id' => ['required', 'integer']])['period_id']);
        $n = $this->accruals->propose($p, $request->user());

        return back()->with('success', "{$n} accruals proposed for {$p->name}.");
    }

    public function releasePrepayments(Request $request)
    {
        Gate::authorize('manageAccruals', Accrual::class);
        $p = Period::query()->findOrFail($request->validate(['period_id' => ['required', 'integer']])['period_id']);
        $n = $this->accruals->releasePrepayments($p, $request->user());

        return back()->with('success', "{$n} prepayment releases posted to {$p->name}.");
    }

    public function post(Request $request, Accrual $accrual)
    {
        Gate::authorize('manageAccruals', Accrual::class);
        $this->accruals->post($accrual, $request->user());

        return back()->with('success', 'Accrual posted.');
    }

    public function reverse(Request $request, Accrual $accrual)
    {
        Gate::authorize('manageAccruals', Accrual::class);
        $this->accruals->reverse($accrual, $request->user());

        return back()->with('success', 'Accrual reversed.');
    }

    public function cancel(Request $request, Accrual $accrual)
    {
        Gate::authorize('manageAccruals', Accrual::class);
        $this->accruals->cancel($accrual, $request->user());

        return back()->with('success', 'Accrual cancelled.');
    }
}
