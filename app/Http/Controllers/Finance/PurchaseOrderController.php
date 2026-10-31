<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsExpenses;
use App\Http\Requests\Finance\ReceiveGoodsRequest;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\PurchaseOrder;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\ProcurementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Purchase orders and goods receipts (FIN-03.03). Raised from an approved expense request; see ExpenseController::commit. */
class PurchaseOrderController extends Controller
{
    use PresentsExpenses;

    public function __construct(private readonly ProcurementService $procurement, private readonly BudgetAvailability $availability) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', PurchaseOrder::class);
        $filters = [
            'q' => trim((string) $request->query('q', '')) ?: null, 'status' => in_array($request->query('status'), PurchaseOrder::STATUSES, true) ? $request->query('status') : null,
            'vendor_id' => $request->integer('vendor_id') ?: null, 'cost_centre_id' => $request->integer('cost_centre_id') ?: null,
        ];
        $visible = fn () => PurchaseOrder::query()->whereIn('expense_request_id', ExpenseRequest::query()->visibleTo($user)->select('id'));
        $orders = $visible()->with(['vendor:id,name', 'expenseRequest:id,request_code,title', 'costCentre:id,code,name', 'issuedBy:id,name', 'lines'])
            ->when($filters['q'], fn ($q, $v) => $q->whereRaw('lower(po_code) like ?', ['%'.mb_strtolower($v).'%']))->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['vendor_id'], fn ($q, $v) => $q->where('vendor_id', $v))->when($filters['cost_centre_id'], fn ($q, $v) => $q->where('cost_centre_id', $v))
            ->orderByDesc('id')->limit(500)->get();
        $all = $visible()->get(['id', 'status', 'base_total_amount', 'fx_rate']);
        $open = $all->whereIn('status', PurchaseOrder::OPEN);
        $this->primePoSums($orders->concat($open));

        return $this->renderExpenses('Finance/PurchaseOrders/Index', [
            'filters' => $filters, 'orders' => $orders->map(fn ($po) => $this->poRow($po))->values(),
            'summary' => [
                'open' => $all->where('status', 'open')->count(), 'partially_received' => $all->where('status', 'partially_received')->count(), 'received' => $all->where('status', 'received')->count(),
                'closed' => $all->where('status', 'closed')->count(), 'open_commitment_base' => $open->reduce(fn ($c, $po) => bcadd($c, $this->availability->remainingForPo($po, $this->poSums($po)), 2), '0.00'),
            ],
            'options' => ['statuses' => collect(PurchaseOrder::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => ucwords(str_replace('_', ' ', $s))])->values()],
            'vendors' => $this->vendorOptions(), 'costCentres' => $this->costCentreOptions($user),
        ]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $user = $request->user();
        Gate::authorize('view', $purchaseOrder);
        $invoices = Invoice::query()->where('po_id', $purchaseOrder->id)->orderBy('id')->get();
        $this->primeLines(array_merge($invoices->pluck('budget_line_id')->all(), [$purchaseOrder->budget_line_id]));
        $e = $purchaseOrder->expenseRequest;
        $edit = Gate::forUser($user)->allows('procure', $purchaseOrder);
        $open = in_array($purchaseOrder->status, PurchaseOrder::OPEN, true);

        return $this->renderExpenses('Finance/PurchaseOrders/Show', [
            'po' => $this->poFull($purchaseOrder), 'expense' => $this->expenseRow($e), 'invoices' => $invoices->map(fn ($i) => $this->invoiceRow($i))->values(),
            'documents' => array_merge($this->documentRows($purchaseOrder), ...$purchaseOrder->receipts->map(fn ($g) => $this->documentRows($g))->all()),
            'activity' => $this->activityRows($purchaseOrder),
            'can' => [
                'edit' => $edit && $open, 'receive' => $edit && in_array($purchaseOrder->status, ProcurementService::TRANSITIONS['receive']['from'], true),
                'close' => $edit && $open, 'cancel' => $edit && $purchaseOrder->status === 'open',
                'captureInvoice' => Gate::forUser($user)->allows('captureInvoice', Invoice::class) && $open, 'attach' => $edit,
            ],
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        Gate::authorize('updateExpense', $purchaseOrder);
        $d = $request->validate(['expected_delivery_on' => ['nullable', 'date'], 'external_reference' => ['nullable', 'string', 'max:64']]);
        $this->procurement->updateHeader($purchaseOrder, $d, $request->user());

        return back()->with('success', 'Purchase order updated.');
    }

    public function receive(ReceiveGoodsRequest $request, PurchaseOrder $purchaseOrder)
    {
        Gate::authorize('updateExpense', $purchaseOrder);
        $grn = $this->procurement->receive($purchaseOrder, $request->validated(), $request->user());

        return back()->with('success', "Goods receipt {$grn->grn_code} recorded.");
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder)
    {
        Gate::authorize('updateExpense', $purchaseOrder);
        $this->procurement->close($purchaseOrder, $request->user(), $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null);

        return back()->with('success', 'Purchase order closed; the remaining commitment is released.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        Gate::authorize('updateExpense', $purchaseOrder);
        $this->procurement->cancel($purchaseOrder, $request->user(), (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason']);

        return back()->with('success', 'Purchase order cancelled; the request is approved again.');
    }
}
