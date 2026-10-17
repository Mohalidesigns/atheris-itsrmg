<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsExpenses;
use App\Http\Requests\Finance\CommitPurchaseOrderRequest;
use App\Http\Requests\Finance\ExpenseFormRequest;
use App\Models\Asset;
use App\Models\Finance\Actual;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Invoice;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\BudgetService;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FinanceTransactionImporter;
use App\Services\Finance\ProcurementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Expense requests (ATH-FIN-001 §3.3, FIN-03.01 to .03, .08, .15). */
class ExpenseController extends Controller
{
    use PresentsExpenses;

    public const TABS = ['overview', 'approval', 'procurement', 'documents', 'activity'];

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly ProcurementService $procurement,
        private readonly FinanceApprovals $approvals,
        private readonly FinanceSettings $settings,
        private readonly BudgetAvailability $availability,
        private readonly BudgetService $budgets,
        private readonly CurrencyService $fx,
        private readonly FinanceTransactionImporter $importer,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', ExpenseRequest::class);
        $orgId = (int) $user->organization_id;
        $fiscalYears = FiscalYear::query()->orderByDesc('starts_on')->get(['id', 'year_label', 'is_current', 'status']);
        $selected = $request->integer('fiscal_year_id') ?: ($fiscalYears->firstWhere('is_current', true)?->id ?? $fiscalYears->first()?->id);
        $filters = [
            'q' => trim((string) $request->query('q', '')) ?: null, 'status' => in_array($request->query('status'), ExpenseRequest::STATUSES, true) ? $request->query('status') : null,
            'cost_centre_id' => $request->integer('cost_centre_id') ?: null, 'vendor_id' => $request->integer('vendor_id') ?: null, 'fiscal_year_id' => $selected,
            'unbudgeted' => $request->boolean('unbudgeted'), 'emergency' => $request->boolean('emergency'), 'mine' => $request->boolean('mine'), 'awaiting_me' => $request->boolean('awaiting_me'),
        ];
        $base = fn () => ExpenseRequest::query()->visibleTo($user)->when($selected, fn ($q) => $q->where('fiscal_year_id', $selected));
        $q = $base()->with(['costCentre:id,code,name', 'budgetLine', 'vendor:id,name', 'requestedBy:id,name', 'purchaseOrder:id,expense_request_id,po_code,status'])
            ->when($filters['q'], fn ($q, $v) => $q->where(fn ($w) => $w->whereRaw('lower(title) like ?', ['%'.mb_strtolower($v).'%'])->orWhereRaw('lower(request_code) like ?', ['%'.mb_strtolower($v).'%'])))
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))->when($filters['cost_centre_id'], fn ($q, $v) => $q->where('cost_centre_id', $v))
            ->when($filters['vendor_id'], fn ($q, $v) => $q->where('vendor_id', $v))->when($filters['unbudgeted'], fn ($q) => $q->whereNull('budget_line_id'))
            ->when($filters['emergency'], fn ($q) => $q->where('is_emergency', true))->when($filters['mine'], fn ($q) => $q->where('requested_by_id', $user->id));
        $rows = $q->orderByDesc('id')->limit(501)->get();
        $truncated = $rows->count() > 500;
        $rows = $rows->take(500);
        $this->primeStages(ExpenseService::PROCESS, ExpenseRequest::class, $rows->where('status', 'submitted')->pluck('id'));
        $this->primeLines($rows->pluck('budget_line_id'));
        $awaiting = $this->awaitingIds($rows->where('status', 'submitted'), $user);
        $filters['awaiting_me'] && $rows = $rows->whereIn('id', $awaiting)->values();

        $all = $base()->get(['id', 'status', 'budget_line_id', 'is_emergency', 'over_budget', 'base_total_amount']);
        $sum = fn ($items) => $items->reduce(fn ($c, $e) => bcadd($c, (string) $e->base_total_amount, 2), '0.00');
        $unb = $all->whereNull('budget_line_id')->whereNotIn('status', ['rejected', 'cancelled', 'withdrawn', 'draft']);

        return $this->renderExpenses('Finance/Expenses/Index', [
            'filters' => $filters, 'expenses' => $rows->map(fn ($e) => $this->expenseRow($e))->values(), 'truncated' => $truncated,
            'summary' => [
                'count' => $all->count(), 'draft' => $all->where('status', 'draft')->count(), 'submitted' => $all->where('status', 'submitted')->count(), 'approved' => $all->where('status', 'approved')->count(),
                'committed' => $all->whereIn('status', ['committed', 'partially_received', 'received', 'invoiced'])->count(), 'unbudgeted_count' => $unb->count(), 'unbudgeted_base' => $sum($unb),
                'emergency_count' => $all->where('is_emergency', true)->count(), 'over_budget_count' => $all->where('over_budget', true)->count(),
                'committed_base' => $sum($all->whereIn('status', ExpenseRequest::OPEN_COMMITMENT)), 'awaiting_me' => count($awaiting),
            ],
            'options' => ['statuses' => collect(ExpenseRequest::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => ucwords(str_replace('_', ' ', $s))])->values()],
            'costCentres' => $this->costCentreOptions($user), 'vendors' => $this->vendorOptions(), 'fiscalYears' => $fiscalYears->map(fn ($f) => ['id' => $f->id, 'year_label' => $f->year_label, 'status' => $f->status])->values(),
            'selectedFiscalYear' => $selected, 'baseCurrency' => $this->settings->baseCurrency($orgId),
            'can' => ['create' => Gate::forUser($user)->allows('createExpense', ExpenseRequest::class), 'import' => Gate::forUser($user)->allows('importActuals', ExpenseRequest::class) && $user->can('import it-finance')],
        ]);
    }

    /** @return list<int> ids of submitted requests the user can decide now */
    private function awaitingIds($submitted, $user): array
    {
        $out = [];
        foreach ($submitted as $e) {
            if (Gate::forUser($user)->allows('decideExpense', $e) && $this->canDecideNow($this->approvals, $e, $user, $this->approvals->trail(ExpenseService::PROCESS, $e, $user), 'approve it-finance', 'requested_by_id')) {
                $out[] = $e->id;
            }
        }

        return $out;
    }

    public function availability(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', ExpenseRequest::class);
        $orgId = (int) $user->organization_id;
        $line = BudgetLine::query()->find($request->integer('budget_line_id'));
        abort_unless($line, 404);
        Gate::authorize('view', $line);
        $current = $this->budgets->resolveBaselineLine($line) ?? $line;
        $avail = $this->availability->forLine($current, null, $request->integer('exclude') ?: null);
        $amount = (string) $request->query('amount', '0');
        $currency = strtoupper((string) $request->query('currency', $this->settings->baseCurrency($orgId)));
        $requested = '0.00';
        if (preg_match('/^\d{1,16}(\.\d{1,2})?$/', $amount) && bccomp($amount, '0', 2) > 0) {
            try {
                $requested = $this->fx->convert($orgId, $amount, $currency, FinanceClock::today(), 'spot')['base_amount'];
            } catch (ValidationException) {
                $requested = '0.00';
            }
        }
        $props = $avail + ['requested_base' => $requested, 'would_exceed' => bccomp($requested, $avail['available'], 2) > 0, 'mode' => $this->settings->for($orgId)->overbudget_mode];
        if (! $user->can('view it-finance-amounts')) {
            $props = $this->maskExpenseMoney($props) + ['would_exceed' => $props['would_exceed'], 'mode' => $props['mode']];
        }

        return response()->json($props);
    }

    public function create(Request $request)
    {
        Gate::authorize('createExpense', ExpenseRequest::class);

        return $this->renderExpenses('Finance/Expenses/Create', ['expense' => null] + $this->formProps($request->user()));
    }

    public function store(ExpenseFormRequest $request)
    {
        Gate::authorize('createExpense', ExpenseRequest::class);
        $e = $this->expenses->create($this->input($request), $request->user());

        return redirect()->route('finance.expenses.show', $e)->with('success', "Expense request {$e->request_code} saved as a draft.");
    }

    public function edit(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('updateExpense', $expense);
        $expense->status === 'draft' || abort(403, 'Only a draft expense request can be edited.');

        return $this->renderExpenses('Finance/Expenses/Create', ['expense' => $this->expenseFull($expense)] + $this->formProps($request->user()));
    }

    public function update(ExpenseFormRequest $request, ExpenseRequest $expense)
    {
        Gate::authorize('updateExpense', $expense);
        $this->expenses->update($expense, $this->input($request), $request->user());

        return redirect()->route('finance.expenses.show', $expense)->with('success', 'Expense request saved.');
    }

    public function destroy(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('updateExpense', $expense);
        $this->expenses->delete($expense, $request->user());

        return redirect()->route('finance.expenses.index')->with('success', 'Draft expense request deleted.');
    }

    public function show(Request $request, ExpenseRequest $expense)
    {
        $user = $request->user();
        Gate::authorize('view', $expense);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $this->primeStages(ExpenseService::PROCESS, ExpenseRequest::class, [$expense->id]);
        $line = $expense->budgetLine;
        $avail = $line ? $this->availability->forLine($this->budgets->resolveBaselineLine($line) ?? $line, null, $expense->id) : null;
        $trail = $this->approvals->trail(ExpenseService::PROCESS, $expense, $user);
        $po = $expense->purchaseOrder()->first();
        $invoices = Invoice::query()->where('expense_request_id', $expense->id)->orderBy('id')->get();
        $this->primeLines($invoices->pluck('budget_line_id'));
        $submitted = $expense->status === 'submitted';
        $deciding = $submitted && Gate::forUser($user)->allows('decideExpense', $expense) && $this->canDecideNow($this->approvals, $expense, $user, $trail, 'approve it-finance', 'requested_by_id');
        $author = in_array((int) $user->id, $expense->sodExcludedIds(), true);
        $edit = Gate::forUser($user)->allows('updateExpense', $expense);

        return $this->renderExpenses('Finance/Expenses/Show', [
            'expense' => $this->expenseFull($expense), 'availability' => $avail, 'approval' => $trail, 'po' => $po ? $this->poFull($po) : null,
            'invoices' => $invoices->map(fn ($i) => $this->invoiceRow($i))->values(),
            'actuals' => Actual::query()->whereIn('invoice_id', $invoices->pluck('id'))->orderBy('id')->get()->map(fn ($a) => $this->actualRow($a))->values(),
            'documents' => $this->documentRows($expense), 'activity' => $this->activityRows($expense), 'tab' => $tab,
            'options' => ['documentKinds' => collect(FinanceDocument::KINDS)->map(fn ($k) => ['value' => $k, 'label' => ucwords(str_replace('_', ' ', $k))])->values(), 'taxTypes' => $this->taxTypeOptions()],
            'can' => [
                'edit' => $edit && $expense->status === 'draft', 'delete' => $edit && $expense->status === 'draft' && $author, 'submit' => $expense->status === 'draft' && $author,
                'withdraw' => $submitted && $author, 'approve' => $deciding, 'return' => $deciding, 'reject' => $deciding,
                'cancel' => in_array($expense->status, ['draft', 'approved'], true) && ($author || $user->can('approve it-finance')),
                'close' => $edit && in_array($expense->status, ExpenseService::TRANSITIONS['close']['from'], true),
                'commit' => $expense->status === 'approved' && Gate::forUser($user)->allows('procure', $expense) && (int) $expense->requested_by_id !== (int) $user->id,
                'attach' => $edit || $author, 'captureInvoice' => Gate::forUser($user)->allows('captureInvoice', Invoice::class) && in_array($expense->status, ['approved', 'committed', 'partially_received', 'received', 'invoiced'], true),
            ],
        ]);
    }

    public function submit(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('updateExpense', $expense);
        $this->expenses->submit($expense, $request->user());

        return back()->with('success', "Expense request {$expense->request_code} submitted for approval.");
    }

    public function withdraw(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('updateExpense', $expense);
        $this->expenses->withdraw($expense, $request->user());

        return back()->with('success', 'Expense request withdrawn.');
    }

    public function cancel(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('updateExpense', $expense);
        $this->expenses->cancel($expense, $request->user(), (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']);

        return back()->with('success', 'Expense request cancelled.');
    }

    public function close(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('updateExpense', $expense);
        $this->expenses->close($expense, $request->user(), $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null);

        return back()->with('success', 'Expense request closed; its remaining commitment is released.');
    }

    public function approve(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('decideExpense', $expense);
        $message = $this->expenses->approve($expense, $request->user(), $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null);

        return back()->with('success', $message);
    }

    public function return(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('decideExpense', $expense);
        $this->expenses->returnToDraft($expense, $request->user(), (string) $request->validate(['note' => ['required', 'string', 'max:2000']])['note']);

        return back()->with('success', 'Returned to the requester.');
    }

    public function reject(Request $request, ExpenseRequest $expense)
    {
        Gate::authorize('decideExpense', $expense);
        $this->expenses->reject($expense, $request->user(), (string) $request->validate(['note' => ['required', 'string', 'max:2000']])['note']);

        return back()->with('success', 'Expense request rejected.');
    }

    public function commit(CommitPurchaseOrderRequest $request, ExpenseRequest $expense)
    {
        Gate::authorize('procure', $expense);
        $po = $this->procurement->commit($expense, $request->validated(), $request->user());

        return redirect()->route('finance.purchase-orders.show', $po)->with('success', "Purchase order {$po->po_code} issued.");
    }

    public function import(Request $request)
    {
        Gate::authorize('importActuals', ExpenseRequest::class);
        $d = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('finance.actuals.max_kb')], 'mode' => ['required', 'in:preview,apply']]);
        $report = $this->importer->expenses($request->file('file'), $request->user(), $d['mode'] === 'apply');

        return back()->with('success', $d['mode'] === 'apply' ? "{$report['accepted']} draft expense requests created." : 'Preview ready.')->with('import_report', $report);
    }

    /** @return array<string,mixed> */
    private function input(ExpenseFormRequest $request): array
    {
        return $request->validated();
    }

    /** @return array<string,mixed> */
    private function formProps(User $user): array
    {
        $orgId = (int) $user->organization_id;
        $s = $this->settings->for($orgId);
        $fys = $this->openFiscalYears();

        return [
            'budgetLines' => $this->budgetLineOptions($user), 'costCentres' => $this->costCentreOptions($user), 'categories' => $this->categoryOptions(), 'glAccounts' => $this->glOptions(),
            'vendors' => $this->vendorOptions(), 'contracts' => $this->contractOptions($user), 'taxTypes' => $this->taxTypeOptions(), 'currencies' => $this->currencyOptions(),
            'legalEntities' => LegalEntity::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($l) => $this->codeMini($l))->values(),
            'assets' => Asset::query()->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($a) => ['id' => $a->id, 'label' => $a->name])->values(),
            'fiscalYears' => $fys, 'settings' => ['overbudget_mode' => $s->overbudget_mode, 'justification_min' => (int) config('finance.expenses.justification_min')],
            'defaults' => ['currency' => $this->settings->baseCurrency($orgId), 'cost_centre_id' => collect($this->costCentreOptions($user))->first()['id'] ?? null,
                'fiscal_year_id' => collect($fys)->firstWhere('is_current', true)['id'] ?? ($fys[0]['id'] ?? null)],
            'baseCurrency' => $this->settings->baseCurrency($orgId),
        ];
    }
}
