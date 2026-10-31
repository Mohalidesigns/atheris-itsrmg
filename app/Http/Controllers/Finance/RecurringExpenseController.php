<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsExpenses;
use App\Http\Requests\Finance\RecurringExpenseFormRequest;
use App\Models\Finance\RecurringExpense;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RecurringExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Recurring expenses (FIN-03.07). */
class RecurringExpenseController extends Controller
{
    use PresentsExpenses;

    public function __construct(private readonly RecurringExpenseService $recurring, private readonly FinanceSettings $settings, private readonly CurrencyService $fx) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', RecurringExpense::class);
        $orgId = (int) $user->organization_id;
        $today = FinanceClock::today();
        $visible = FinancePolicy::visibleCostCentreIds($user);
        $filters = [
            'q' => trim((string) $request->query('q', '')) ?: null, 'active' => $request->has('active') ? $request->boolean('active') : null,
            'frequency' => array_key_exists((string) $request->query('frequency'), RecurringExpense::FREQUENCIES) ? $request->query('frequency') : null,
            'due' => in_array((int) $request->query('due'), [7, 30, 90], true) ? (int) $request->query('due') : null,
        ];
        $base = fn () => RecurringExpense::query()->when($visible !== null && ! $user->hasRole('Procurement Officer'), fn ($q) => $q->whereIn('cost_centre_id', $visible ?: [0]));
        $items = $base()->when($filters['q'], fn ($q, $v) => $q->whereRaw('lower(title) like ?', ['%'.mb_strtolower($v).'%']))
            ->when($filters['active'] !== null, fn ($q) => $q->where('is_active', $filters['active']))->when($filters['frequency'], fn ($q, $v) => $q->where('frequency', $v))
            ->when($filters['due'], fn ($q, $v) => $q->whereDate('next_due_on', '<=', $today->addDays($v)->toDateString()))->orderBy('next_due_on')->orderBy('id')->get();
        $items->loadMissing(['contract:id,contract_code,title', 'vendor:id,name', 'budgetLine', 'costCentre:id,code,name', 'category', 'glAccount', 'taxType']); // one query per relation, not per row
        $this->primeLines($items->pluck('budget_line_id'));
        $all = $base()->get();
        $active = $all->where('is_active', true);
        $annual = $active->reduce(function ($c, $r) use ($orgId, $today) {
            try {
                $base = $this->fx->convert($orgId, (string) $r->amount, $r->currency, $today, 'spot')['base_amount'];
            } catch (\Throwable) {
                $base = '0.00';
            }

            return bcadd($c, bcmul($base, (string) (12 / RecurringExpense::FREQUENCIES[$r->frequency]), 2), 2);
        }, '0.00');
        $rows = $items->map(function ($r) use ($orgId, $today) {
            $row = $this->recurringRow($r);
            try {
                $row['base_amount_est'] = $this->fx->convert($orgId, (string) $r->amount, $r->currency, $today, 'spot')['base_amount'];
            } catch (\Throwable) {
                $row['base_amount_est'] = null;
            }

            return $row;
        })->values();

        return $this->renderExpenses('Finance/Recurring/Index', [
            'filters' => $filters, 'items' => $rows,
            'summary' => ['active' => $active->count(), 'due_30' => $active->filter(fn ($r) => $r->next_due_on->toDateString() <= $today->addDays(30)->toDateString())->count(), 'annual_run_rate_base' => $annual],
            'options' => ['frequencies' => collect(array_keys(RecurringExpense::FREQUENCIES))->map(fn ($f) => ['value' => $f, 'label' => ucwords(str_replace('_', ' ', $f))])->values(),
                'autoGenerate' => collect(RecurringExpense::AUTO_GENERATE)->map(fn ($f) => ['value' => $f, 'label' => ucwords(str_replace('_', ' ', $f))])->values()],
            'contracts' => $this->contractOptions($user), 'vendors' => $this->vendorOptions(), 'budgetLines' => $this->budgetLineOptions($user), 'costCentres' => $this->costCentreOptions($user),
            'categories' => $this->categoryOptions(), 'glAccounts' => $this->glOptions(), 'taxTypes' => $this->taxTypeOptions(), 'currencies' => $this->currencyOptions(),
            'can' => ['create' => $user->can('create it-finance'), 'edit' => $user->can('edit it-finance'), 'generate' => $user->can('edit it-finance')],
        ]);
    }

    public function store(RecurringExpenseFormRequest $request)
    {
        Gate::authorize('createExpense', RecurringExpense::class);
        $r = $this->recurring->save(null, $request->validated(), $request->user());

        return back()->with('success', "Recurring expense {$r->recurring_code} created.");
    }

    public function update(RecurringExpenseFormRequest $request, RecurringExpense $recurringExpense)
    {
        Gate::authorize('updateExpense', $recurringExpense);
        $this->recurring->save($recurringExpense, $request->validated(), $request->user());

        return back()->with('success', 'Recurring expense saved.');
    }

    public function destroy(Request $request, RecurringExpense $recurringExpense)
    {
        Gate::authorize('updateExpense', $recurringExpense);
        $this->recurring->delete($recurringExpense, $request->user());

        return back()->with('success', 'Recurring expense deleted.');
    }

    public function generate(Request $request, RecurringExpense $recurringExpense)
    {
        Gate::authorize('updateExpense', $recurringExpense);
        $made = $this->recurring->generateFor($recurringExpense, FinanceClock::today());

        return back()->with('success', $made ? "{$made} item generated." : 'Nothing is due yet.');
    }
}
