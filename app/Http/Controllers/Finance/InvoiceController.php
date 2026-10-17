<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsExpenses;
use App\Http\Requests\Finance\InvoiceFormRequest;
use App\Models\Finance\Actual;
use App\Models\Finance\Contract;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceLine;
use App\Models\Finance\Period;
use App\Models\Finance\PurchaseOrder;
use App\Models\User;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FinanceTransactionImporter;
use App\Services\Finance\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Vendor invoices (ATH-FIN-001 §3.3, FIN-03.04 to .06). */
class InvoiceController extends Controller
{
    use PresentsExpenses;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly FinanceApprovals $approvals,
        private readonly FinanceSettings $settings,
        private readonly FinanceTransactionImporter $importer,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Invoice::class);
        $today = FinanceClock::today();
        $filters = [
            'q' => trim((string) $request->query('q', '')) ?: null, 'status' => in_array($request->query('status'), Invoice::STATUSES, true) ? $request->query('status') : null,
            'match_status' => in_array($request->query('match_status'), Invoice::MATCH_STATUSES, true) ? $request->query('match_status') : null,
            'vendor_id' => $request->integer('vendor_id') ?: null, 'cost_centre_id' => $request->integer('cost_centre_id') ?: null, 'overdue' => $request->boolean('overdue'),
            'due' => in_array((int) $request->query('due'), [7, 14, 30], true) ? (int) $request->query('due') : null, 'mine' => $request->boolean('mine'), 'awaiting_me' => $request->boolean('awaiting_me'),
        ];
        $base = fn () => Invoice::query()->visibleTo($user);
        $unpaidStatuses = ['submitted', 'approved', 'scheduled'];
        $rows = $base()->with(['vendor:id,name', 'po:id,po_code', 'contract:id,contract_code,title', 'expenseRequest:id,request_code', 'costCentre:id,code,name', 'budgetLine', 'period', 'submittedBy:id,name', 'approvedBy:id,name'])
            ->when($filters['q'], fn ($q, $v) => $q->where(fn ($w) => $w->whereRaw('lower(invoice_code) like ?', ['%'.mb_strtolower($v).'%'])->orWhereRaw('lower(vendor_invoice_no) like ?', ['%'.mb_strtolower($v).'%'])))
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))->when($filters['match_status'], fn ($q, $v) => $q->where('match_status', $v))
            ->when($filters['vendor_id'], fn ($q, $v) => $q->where('vendor_id', $v))->when($filters['cost_centre_id'], fn ($q, $v) => $q->where('cost_centre_id', $v))
            ->when($filters['overdue'], fn ($q) => $q->whereIn('status', $unpaidStatuses)->whereDate('due_on', '<', $today->toDateString()))
            ->when($filters['due'], fn ($q, $v) => $q->whereIn('status', $unpaidStatuses)->whereDate('due_on', '>=', $today->toDateString())->whereDate('due_on', '<=', $today->addDays($v)->toDateString()))
            ->when($filters['mine'], fn ($q) => $q->where('submitted_by_id', $user->id))->orderBy('due_on')->orderBy('id')->limit(501)->get();
        $truncated = $rows->count() > 500;
        $rows = $rows->take(500);
        $this->primeStages(InvoiceService::PROCESS, Invoice::class, $rows->where('status', 'submitted')->pluck('id'));
        $this->primeLines($rows->pluck('budget_line_id'));
        $awaiting = [];
        foreach ($rows->where('status', 'submitted') as $i) {
            Gate::forUser($user)->allows('decideInvoice', $i) && $this->canDecideNow($this->approvals, $i, $user, $this->approvals->trail(InvoiceService::PROCESS, $i, $user), InvoiceService::PERMISSION, 'submitted_by_id') && ($awaiting[] = $i->id);
        }
        $filters['awaiting_me'] && $rows = $rows->whereIn('id', $awaiting)->values();

        $unpaid = $base()->whereIn('status', $unpaidStatuses)->get(['id', 'status', 'due_on', 'base_total_amount']);
        $sum = fn ($items) => $items->reduce(fn ($c, $i) => bcadd($c, (string) $i->base_total_amount, 2), '0.00');
        $overdue = $unpaid->filter(fn ($i) => $i->due_on->toDateString() < $today->toDateString());
        $late = fn (int $from, ?int $to) => $overdue->filter(fn ($i) => ($d = $i->due_on->diffInDays($today)) >= $from && ($to === null || $d <= $to));

        return $this->renderExpenses('Finance/Invoices/Index', [
            'filters' => $filters, 'invoices' => $rows->map(fn ($i) => $this->invoiceRow($i))->values(), 'truncated' => $truncated,
            'summary' => [
                'count' => $base()->count(), 'unpaid_base' => $sum($unpaid), 'overdue_count' => $overdue->count(), 'overdue_base' => $sum($overdue),
                'due_7_count' => $unpaid->filter(fn ($i) => ($d = FinanceClock::daysUntil($i->due_on)) >= 0 && $d <= 7)->count(),
                'variance_count' => $base()->where('status', 'draft')->where('match_status', 'variance')->count(), 'disputed_count' => $base()->where('status', 'disputed')->count(), 'awaiting_me' => count($awaiting),
            ],
            'ageing' => [
                'current' => $sum($unpaid->reject(fn ($i) => $i->due_on->toDateString() < $today->toDateString())), 'd1_30' => $sum($late(1, 30)), 'd31_60' => $sum($late(31, 60)),
                'd61_90' => $sum($late(61, 90)), 'd90_plus' => $sum($late(91, null)),
            ],
            'options' => ['statuses' => collect(Invoice::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => ucfirst($s)])->values(),
                'matchStatuses' => collect(Invoice::MATCH_STATUSES)->map(fn ($s) => ['value' => $s, 'label' => ucfirst($s)])->values()],
            'vendors' => $this->vendorOptions(), 'costCentres' => $this->costCentreOptions($user),
            'can' => ['create' => Gate::forUser($user)->allows('captureInvoice', Invoice::class), 'import' => Gate::forUser($user)->allows('importActuals', Invoice::class) && $user->can('import it-finance')],
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('captureInvoice', Invoice::class);

        return $this->renderExpenses('Finance/Invoices/Create', ['invoice' => null, 'prefill' => $this->prefill($request)] + $this->formProps($request->user()));
    }

    public function store(InvoiceFormRequest $request)
    {
        Gate::authorize('captureInvoice', Invoice::class);
        $i = $this->invoices->capture($request->validated(), $request->user());

        return redirect()->route('finance.invoices.show', $i)->with('success', "Invoice {$i->invoice_code} captured; match status: {$i->match_status}.");
    }

    public function edit(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $invoice->status === 'draft' || abort(403, 'Only a draft invoice can be edited.');

        return $this->renderExpenses('Finance/Invoices/Create', ['invoice' => $this->invoiceFull($invoice), 'prefill' => null] + $this->formProps($request->user()));
    }

    public function update(InvoiceFormRequest $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $this->invoices->update($invoice, $request->validated(), $request->user());

        return redirect()->route('finance.invoices.show', $invoice)->with('success', 'Invoice saved.');
    }

    public function destroy(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $this->invoices->delete($invoice, $request->user());

        return redirect()->route('finance.invoices.index')->with('success', 'Draft invoice deleted.');
    }

    public function show(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        Gate::authorize('view', $invoice);
        $this->primeStages(InvoiceService::PROCESS, Invoice::class, [$invoice->id]);
        $this->primeLines([$invoice->budget_line_id]);
        $trail = $this->approvals->trail(InvoiceService::PROCESS, $invoice, $user);
        $po = $invoice->po;
        $expense = $invoice->expenseRequest;
        $contract = $invoice->contract;
        $status = $invoice->status;
        $edit = Gate::forUser($user)->allows('updateExpense', $invoice);
        $deciding = $status === 'submitted' && Gate::forUser($user)->allows('decideInvoice', $invoice) && $this->canDecideNow($this->approvals, $invoice, $user, $trail, InvoiceService::PERMISSION, 'submitted_by_id');
        $payer = Gate::forUser($user)->allows('payInvoice', $invoice) && ! in_array((int) $user->id, array_filter([(int) $invoice->approved_by_id, (int) $invoice->submitted_by_id]), true);
        $this->primeLines($po ? [$po->budget_line_id] : []);
        $poApprovers = $invoice->high_value === 'yes' ? $this->invoices->poApproverIds($invoice) : [];
        $actual = Actual::query()->where('invoice_id', $invoice->id)->where('source', 'invoice')->first();

        return $this->renderExpenses('Finance/Invoices/Show', [
            'invoice' => $this->invoiceFull($invoice), 'po' => $po ? $this->poRow($po) : null, 'expense' => $expense ? $this->expenseRow($expense) : null,
            'contract' => $contract ? $this->contractMini($contract) + ['lease_assessment' => $contract->lease_assessment, 'is_ifrs16_lease' => $contract->is_ifrs16_lease] : null,
            'approval' => $trail, 'actual' => $actual ? $this->actualRow($actual) : null, 'prepayments' => $invoice->prepayments()->get()->map(fn ($a) => $this->accrualRow($a))->values(),
            'documents' => $this->documentRows($invoice), 'activity' => $this->activityRows($invoice),
            'sod' => ['applies' => $invoice->high_value === 'yes', 'po_approvers' => User::query()->whereIn('id', $poApprovers ?: [0])->get(['id', 'name'])->map(fn ($u) => $this->userMini($u))->values()],
            'periods' => $this->openPeriods(), 'options' => ['documentKinds' => collect(FinanceDocument::KINDS)->map(fn ($k) => ['value' => $k, 'label' => ucwords(str_replace('_', ' ', $k))])->values()],
            'can' => [
                'edit' => $edit && $status === 'draft', 'delete' => $edit && $status === 'draft', 'match' => $edit && $status === 'draft',
                'override' => $edit && $status === 'draft' && $invoice->match_status === 'variance', 'submit' => $edit && $status === 'draft' && $invoice->match_status !== 'variance',
                'withdraw' => $status === 'submitted' && in_array((int) $user->id, $invoice->sodExcludedIds(), true), 'approve' => $deciding, 'return' => $deciding, 'reject' => $deciding,
                'schedule' => $status === 'approved' && $payer, 'pay' => in_array($status, ['approved', 'scheduled'], true) && $payer,
                'dispute' => $edit && in_array($status, InvoiceService::TRANSITIONS['dispute']['from'], true), 'resolve' => $edit && $status === 'disputed',
                'cancel' => $edit && in_array($status, ['draft', 'disputed'], true) && ! $invoice->approved_at, 'attach' => $edit,
            ],
        ]);
    }

    public function match(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $i = $this->invoices->match($invoice, $request->user());

        return back()->with('success', "Match status: {$i->match_status}.");
    }

    public function override(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $this->invoices->override($invoice, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $request->user());

        return back()->with('success', 'Variance overridden; the invoice goes to Finance review when submitted.');
    }

    public function submit(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $this->invoices->submit($invoice, $request->user());

        return back()->with('success', "Invoice {$invoice->invoice_code} submitted for approval.");
    }

    public function withdraw(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $this->invoices->withdraw($invoice, $request->user());

        return back()->with('success', 'Invoice withdrawn to draft.');
    }

    public function dispute(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $this->invoices->dispute($invoice, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $request->user());

        return back()->with('success', 'Invoice marked as disputed.');
    }

    public function resolve(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $this->invoices->resolveDispute($invoice, $request->user(), $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null);

        return back()->with('success', 'Dispute resolved.');
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        Gate::authorize('updateExpense', $invoice);
        $this->invoices->cancel($invoice, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $request->user());

        return back()->with('success', 'Invoice cancelled.');
    }

    public function approve(Request $request, Invoice $invoice)
    {
        Gate::authorize('decideInvoice', $invoice);
        $message = $this->invoices->approve($invoice, $request->user(), $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null);

        return back()->with('success', $message);
    }

    public function return(Request $request, Invoice $invoice)
    {
        Gate::authorize('decideInvoice', $invoice);
        $this->invoices->returnToDraft($invoice, $request->user(), (string) $request->validate(['note' => ['required', 'string', 'max:2000']])['note']);

        return back()->with('success', 'Returned to the submitter.');
    }

    public function reject(Request $request, Invoice $invoice)
    {
        Gate::authorize('decideInvoice', $invoice);
        $this->invoices->reject($invoice, $request->user(), (string) $request->validate(['note' => ['required', 'string', 'max:2000']])['note']);

        return back()->with('success', 'Invoice rejected.');
    }

    public function schedule(Request $request, Invoice $invoice)
    {
        Gate::authorize('payInvoice', $invoice);
        $this->invoices->schedule($invoice, (string) $request->validate(['scheduled_on' => ['required', 'date']])['scheduled_on'], $request->user());

        return back()->with('success', 'Payment scheduled.');
    }

    public function pay(Request $request, Invoice $invoice)
    {
        Gate::authorize('payInvoice', $invoice);
        $d = $request->validate(['paid_on' => ['required', 'date'], 'payment_reference' => ['required', 'string', 'max:64']]);
        $this->invoices->markPaid($invoice, $d['paid_on'], $d['payment_reference'], $request->user());

        return back()->with('success', 'Payment recorded.');
    }

    public function import(Request $request)
    {
        Gate::authorize('importActuals', Invoice::class);
        $d = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('finance.actuals.max_kb')], 'mode' => ['required', 'in:preview,apply']]);
        $report = $this->importer->invoices($request->file('file'), $request->user(), $d['mode'] === 'apply');

        return back()->with('success', $d['mode'] === 'apply' ? "{$report['accepted']} draft invoices created." : 'Preview ready.')->with('import_report', $report);
    }

    /** @return list<array<string,mixed>> */
    private function openPeriods(): array
    {
        return Period::query()->where('status', 'open')->where('is_adjustment', false)->orderBy('starts_on')->get()->map(fn ($p) => $this->periodMini($p))->values()->all();
    }

    /** @return array<string,mixed>|null */
    private function prefill(Request $request): ?array
    {
        if ($id = $request->integer('po_id')) {
            $po = PurchaseOrder::query()->with('lines')->find($id);
            if ($po) {
                Gate::authorize('view', $po);

                return ['po_id' => $po->id, 'vendor_id' => $po->vendor_id, 'currency' => $po->currency, 'cost_centre_id' => $po->cost_centre_id, 'budget_line_id' => $po->budget_line_id,
                    'lines' => $po->lines->filter(fn ($l) => bccomp((string) $l->received_quantity, '0', 4) > 0)->map(fn ($l) => ['po_line_id' => $l->id, 'description' => $l->description,
                        'quantity' => (string) $l->received_quantity, 'unit_cost' => (string) $l->unit_cost])->values()->all()];
            }
        }
        if ($id = $request->integer('expense_id')) {
            $e = ExpenseRequest::query()->find($id);
            if ($e) {
                Gate::authorize('view', $e);

                return ['expense_request_id' => $e->id, 'vendor_id' => $e->vendor_id, 'currency' => $e->currency, 'cost_centre_id' => $e->cost_centre_id, 'budget_line_id' => $e->budget_line_id, 'subtotal' => (string) $e->amount];
            }
        }
        if ($id = $request->integer('contract_id')) {
            $c = Contract::query()->find($id);
            if ($c) {
                Gate::authorize('view', $c);

                return ['contract_id' => $c->id, 'vendor_id' => $c->vendor_id, 'currency' => $c->currency, 'cost_centre_id' => $c->cost_centre_id, 'budget_line_id' => $c->budget_line_id,
                    'subtotal' => $c->amount_per_billing === null ? null : (string) $c->amount_per_billing];
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function formProps(User $user): array
    {
        $orgId = (int) $user->organization_id;
        $taxes = collect($this->taxTypeOptions());
        $pos = PurchaseOrder::query()->whereIn('status', PurchaseOrder::OPEN)->whereIn('expense_request_id', ExpenseRequest::query()->visibleTo($user)->select('id'))->with('lines')->orderBy('po_code')->get();
        $invoicedQty = InvoiceLine::query()->whereIn('po_line_id', $pos->flatMap->lines->pluck('id'))
            ->whereIn('invoice_id', Invoice::query()->whereIn('status', ['submitted', 'approved', 'scheduled', 'paid'])->select('id'))->selectRaw('po_line_id, sum(quantity) as q')->groupBy('po_line_id')->pluck('q', 'po_line_id');

        return [
            'purchaseOrders' => $pos->map(fn ($po) => ['id' => $po->id, 'po_code' => $po->po_code, 'vendor_id' => $po->vendor_id, 'currency' => $po->currency, 'cost_centre_id' => $po->cost_centre_id,
                'budget_line_id' => $po->budget_line_id, 'lines' => $po->lines->map(fn ($l) => ['id' => $l->id, 'line_no' => $l->line_no, 'description' => $l->description, 'quantity' => (string) $l->quantity,
                    'unit_cost' => (string) $l->unit_cost, 'received_quantity' => (string) $l->received_quantity, 'invoiced_quantity' => bcadd((string) ($invoicedQty[$l->id] ?? '0'), '0', 4)])->values()->all()])->values(),
            'expenses' => ExpenseRequest::query()->visibleTo($user)->where('status', 'approved')->doesntHave('purchaseOrder')->orderBy('request_code')->get()
                ->map(fn ($e) => ['id' => $e->id, 'request_code' => $e->request_code, 'title' => $e->title, 'vendor_id' => $e->vendor_id, 'currency' => $e->currency, 'budget_line_id' => $e->budget_line_id])->values(),
            'contracts' => $this->contractOptions($user), 'vendors' => $this->vendorOptions(), 'budgetLines' => $this->budgetLineOptions($user), 'costCentres' => $this->costCentreOptions($user),
            'categories' => $this->categoryOptions(), 'glAccounts' => $this->glOptions(),
            'taxTypes' => ['vat' => $taxes->whereIn('kind', ['vat', 'sales_tax', 'none'])->values(), 'wht' => $taxes->where('kind', 'wht')->values()],
            'periods' => $this->openPeriods(), 'currencies' => $this->currencyOptions(),
            'defaults' => ['currency' => $this->settings->baseCurrency($orgId), 'payment_days' => (int) config('finance.invoices.default_payment_days')],
            'baseCurrency' => $this->settings->baseCurrency($orgId),
        ];
    }
}
