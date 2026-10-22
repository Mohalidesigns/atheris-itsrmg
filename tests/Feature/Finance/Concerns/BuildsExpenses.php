<?php

namespace Tests\Feature\Finance\Concerns;

use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\GoodsReceipt;
use App\Models\Finance\Invoice;
use App\Models\Finance\PurchaseOrder;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\ProcurementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Shared fixtures for the P3 expense, procurement, invoice and actuals tests: the P2 bank (NGN, FY2027, "today" 2027-03-01) plus a
 * requester, a Managing Director and a funded baseline budget line. Everything runs through the services.
 */
trait BuildsExpenses
{
    use BuildsContracts;

    protected User $requester;

    protected User $md;

    protected CostCentre $ccChild;

    /** The requester's own line (cost centre CC-1A, a child of $this->cc owned by the budget owner). */
    protected BudgetLine $line;

    protected BudgetLine $parentLine;

    protected function bootExpenses(string $lineAmount = '12000000.00', string $now = '2027-03-01 09:00:00'): void
    {
        $this->bootContracts($now);
        $this->requester = $this->userWith('IT Budget Owner');
        $this->md = $this->userWith('Finance Approver', ['job_title' => 'Managing Director']);
        $this->ccChild = CostCentre::create(['code' => 'CC-1A', 'name' => 'Ops Support', 'owner_id' => $this->requester->id, 'parent_id' => $this->cc->id]);
        $this->parentLine = $this->firstLine($this->baselined($this->cc, $lineAmount));
        $this->line = $this->firstLine($this->baselined($this->ccChild, $lineAmount));
        $this->actingAs($this->fin);
    }

    /** @return array<string,User> */
    protected function people(): array
    {
        return ['requester' => $this->requester, 'owner' => $this->owner, 'headOfIt' => $this->head, 'cfo' => $this->cfo, 'md' => $this->md, 'financeController' => $this->fin, 'procurement' => $this->procurement];
    }

    protected function approverFor(string $role): User
    {
        return $this->people()[$role];
    }

    protected function expense(array $over = [], ?User $by = null): ExpenseRequest
    {
        return app(ExpenseService::class)->create($over + [
            'title' => 'Core switch refresh', 'fiscal_year_id' => $this->fy->id, 'budget_line_id' => $this->line->id, 'amount' => '1000000.00', 'currency' => 'NGN',
            'vendor_id' => $this->vendor->id, 'needed_by' => '2027-03-15',
        ], $by ?? $this->requester);
    }

    /**
     * Submitted by the requester and approved. Without configured stages the CFO alone approves (the unconfigured default); once
     * ensureDefaults has run, the chain is walked in order (owner, Head of IT, CFO, MD) and each approver is tried only at their own stage.
     */
    protected function approvedExpense(array $over = [], ?User $by = null): ExpenseRequest
    {
        $svc = app(ExpenseService::class);
        $e = $svc->submit($this->expense($over, $by), $by ?? $this->requester);
        $configured = ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', ExpenseService::PROCESS)->whereHas('stages')->exists();
        if (! $configured) {
            $svc->approve($e->refresh(), $this->cfo);

            return $e->refresh();
        }
        foreach ([$this->owner, $this->head, $this->cfo, $this->md] as $approver) {
            if ($e->refresh()->status === 'approved') {
                break;
            }
            try {
                $svc->approve($e, $approver);
            } catch (ValidationException) {
                // not this approver's stage
            }
        }

        return $e->refresh();
    }

    protected function po(?ExpenseRequest $e = null, ?array $lines = null, ?User $by = null): PurchaseOrder
    {
        $e ??= $this->approvedExpense();

        return app(ProcurementService::class)->commit($e, [
            'po_date' => '2027-03-01', 'lines' => $lines ?? [['description' => 'Core switch', 'quantity' => '2', 'unit_cost' => bcdiv((string) $e->amount, '2', 2)]],
        ], $by ?? $this->procurement);
    }

    protected function grn(PurchaseOrder $po, ?array $lines = null, ?User $by = null): GoodsReceipt
    {
        return app(ProcurementService::class)->receive($po, [
            'received_on' => '2027-03-01',
            'lines' => $lines ?? $po->lines()->get()->map(fn ($l) => ['po_line_id' => $l->id, 'quantity' => (string) $l->quantity, 'accepted' => true])->all(),
        ], $by ?? $this->procurement);
    }

    /** A draft invoice for a PO (lines prefilled from what was received) or a bare non-PO invoice. */
    protected function invoice(array $over = [], ?User $by = null): Invoice
    {
        return app(InvoiceService::class)->capture($over + [
            'vendor_invoice_no' => 'VI-'.random_int(1000, 999999), 'vendor_id' => $this->vendor->id, 'invoice_date' => '2027-03-01', 'currency' => 'NGN',
            'cost_centre_id' => $this->ccChild->id, 'budget_line_id' => $this->line->id, 'subtotal' => '500000.00',
        ], $by ?? $this->procurement);
    }

    protected function glCsv(array $rows, string $delimiter = ',', array $header = ['date', 'gl', 'cc', 'amount', 'memo', 'ref']): UploadedFile
    {
        $lines = [implode($delimiter, $header)];
        foreach ($rows as $r) {
            $lines[] = implode($delimiter, $r);
        }

        return UploadedFile::fake()->createWithContent('gl-'.random_int(1, 99999).'.csv', implode("\n", $lines)."\n");
    }

    /** @return array<string,mixed> */
    protected function glMapping(array $over = []): array
    {
        return $over + ['columns' => ['posted_on' => 'date', 'gl_code' => 'gl', 'cost_centre_code' => 'cc', 'amount' => 'amount', 'description' => 'memo', 'reference' => 'ref'], 'delimiter' => ',', 'date_format' => 'Y-m-d'];
    }
}
