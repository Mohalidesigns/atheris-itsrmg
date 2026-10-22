<?php

namespace Tests\Feature\Finance;

use App\Models\Evidence;
use App\Models\Finance\Actual;
use App\Models\Finance\FinanceDocument;
use App\Models\User;
use App\Services\Finance\ActualsService;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Finance\Concerns\BuildsExpenses;
use Tests\TestCase;

/**
 * P3 backend slice smoke (FIN-03.01 to .05, .14, FIN-06.10, FIN-07.10): the new pages render for the right people, other banks and
 * unprivileged users are kept out, amounts are masked, documents are hashed and kept out of the vault, and the ledger is immutable.
 * The full acceptance suite is the QA work package.
 */
class ExpenseBackendSmokeTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootExpenses();
    }

    private function props($response): array
    {
        return $response->original->getData()['page']['props'];
    }

    /** Runs a request to the approved-and-invoiced state so every page has something to show. */
    private function estate(): array
    {
        $e = $this->approvedExpense();
        $po = $this->po($e);
        $this->grn($po);
        $inv = $this->invoice(['po_id' => $po->id]);

        return [$e->refresh(), $po->refresh(), $inv->refresh()];
    }

    public function test_every_new_get_route_renders_for_finance_and_is_403_without_view(): void // FIN-07.10, FIN-03.01
    {
        [$e, $po, $inv] = $this->estate();
        $urls = [
            route('finance.expenses.index'), route('finance.expenses.create'), route('finance.expenses.show', $e), route('finance.expenses.edit', $this->expense()),
            route('finance.purchase-orders.index'), route('finance.purchase-orders.show', $po), route('finance.invoices.index'), route('finance.invoices.create', ['po_id' => $po->id]),
            route('finance.invoices.show', $inv), route('finance.invoices.edit', $inv), route('finance.actuals.index'), route('finance.actuals.index', ['tab' => 'queue']),
            route('finance.actuals.index', ['tab' => 'rules']), route('finance.actuals.index', ['tab' => 'mappings']), route('finance.recurring.index'), route('finance.accruals.index'),
            route('finance.imports.index'), route('finance.expenses.availability', ['budget_line_id' => $this->line->id, 'amount' => '100']),
        ];
        foreach ($urls as $url) {
            $this->actingAs($this->fin)->get($url)->assertOk();
        }
        $nobody = User::factory()->create(['organization_id' => $this->org->id]);
        foreach ($urls as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
        $this->assertSame($this->line->id, $this->props($this->actingAs($this->fin)->get(route('finance.expenses.show', $e)))['availability']['line_id']);
    }

    public function test_other_banks_cannot_see_or_change_records(): void // tenancy
    {
        [$e, $po, $inv] = $this->estate();
        $other = $this->userWith('IT Finance Controller', [], $this->otherBank()->id);
        $this->actingAs($other);
        foreach ([route('finance.expenses.show', $e), route('finance.purchase-orders.show', $po), route('finance.invoices.show', $inv)] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post(route('finance.expenses.submit', $e))->assertNotFound();
        $this->post(route('finance.invoices.approve', $inv))->assertNotFound();
        $this->post(route('finance.purchase-orders.close', $po))->assertNotFound();
        $this->assertCount(0, $this->props($this->get(route('finance.expenses.index')))['expenses']);
    }

    public function test_amounts_are_masked_and_import_refused_without_the_amounts_permission(): void // FIN-07.10
    {
        [$e] = $this->estate();
        $blind = User::factory()->create(['organization_id' => $this->org->id]);
        $blind->givePermissionTo(['view it-finance', 'import it-finance']);
        $this->ccChild->update(['owner_id' => $blind->id]); // the blind user owns the request's cost centre, so the page is visible but amount-free
        $props = $this->props($this->actingAs($blind)->get(route('finance.expenses.show', $e)));
        $this->assertNull($props['expense']['amount']);
        $this->assertNull($props['expense']['base_total_amount']);
        $this->assertNull($props['availability']['available']);
        $this->assertFalse($props['can']['viewAmounts']);
        $this->actingAs($blind)->post(route('finance.actuals.import'), ['file' => $this->glCsv([]), 'mode' => 'preview'] + $this->glMapping())->assertForbidden();
        $this->assertNotNull($this->props($this->actingAs($this->fin)->get(route('finance.expenses.show', $e)))['expense']['amount']);
    }

    public function test_documents_are_hashed_in_the_vault_and_hidden_from_it(): void // FIN-03.14
    {
        Storage::fake('local');
        [$e] = $this->estate();
        $file = UploadedFile::fake()->createWithContent('quote.pdf', 'quotation body');
        $this->actingAs($this->fin)->post(route('finance.documents.store'), ['subject' => 'expense', 'subject_id' => $e->id, 'kind' => 'quote', 'title' => 'Vendor quote', 'file' => $file])->assertSessionHasNoErrors();
        $doc = FinanceDocument::first();
        $this->assertSame(hash('sha256', 'quotation body'), $doc->sha256);
        $ev = Evidence::withoutGlobalScopes()->find($doc->evidence_id);
        $this->assertSame($e::class, $ev->evidenceable_type);
        $this->get(route('finance.documents.download', $doc))->assertOk();
        $vault = $this->userWith('IT Finance Controller');
        $vault->givePermissionTo('view evidence');
        $this->actingAs($vault)->get(route('evidence.download', $ev->id))->assertNotFound();
        $this->assertStringNotContainsString('Vendor quote', $this->get(route('evidence.index'))->getContent());
        $this->actingAs($this->fin);
        $this->actingAs($this->userWith('IT Finance Controller', [], $this->otherBank()->id))->get(route('finance.documents.download', $doc))->assertNotFound();
    }

    public function test_vendor_spend_includes_expense_actuals(): void // FIN-06.10
    {
        $this->contract(['vendor_id' => $this->vendor->id]);
        $inv = $this->invoice(['subtotal' => '750000.00', 'contract_id' => null]);
        $svc = app(InvoiceService::class);
        $svc->submit($inv, $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $spend = $this->props($this->actingAs($this->fin)->get(route('finance.contracts.index')))['vendorSpend'];
        $this->assertSame('750000.00', $spend[0]['expense_actual_base']);
    }

    public function test_transfer_is_refused_above_the_source_lines_availability(): void // C89
    {
        $this->approvedExpense(['amount' => '11000000.00']); // commits 11m of the 12m line
        $to = $this->firstLine($this->baselined($this->cc2, '1000000.00'));
        $svc = app(BudgetTransferService::class);
        $this->assertRefused(fn () => $svc->create(['fiscal_year_id' => $this->fy->id, 'from_line_id' => $this->line->id, 'to_line_id' => $to->id, 'amount' => '2000000.00', 'reason' => 'Move budget'], $this->fin), 'amount', 'committed or spent');
        $t = $svc->create(['fiscal_year_id' => $this->fy->id, 'from_line_id' => $this->line->id, 'to_line_id' => $to->id, 'amount' => '500000.00', 'reason' => 'Move budget'], $this->fin);
        $this->assertSame('draft', $t->status);
    }

    public function test_lifecycle_posts_one_immutable_actual_and_relieves_the_commitment(): void // FIN-03.03 to .05
    {
        [$e, $po, $inv] = $this->estate();
        $this->assertSame('received', $po->status);
        $this->assertSame('matched', $inv->match_status);
        $svc = app(InvoiceService::class);
        $svc->submit($inv, $this->procurement);
        $svc->approve($inv->refresh(), $this->cfo);
        $actual = Actual::where('invoice_id', $inv->id)->sole();
        $this->assertSame('1000000.00', (string) $actual->base_amount);
        $this->assertSame('matched', $actual->match_status);
        $this->assertSame('invoiced', $e->refresh()->status);
        $this->assertSame($actual->id, app(ActualsService::class)->postInvoice($inv->refresh(), $this->cfo)->id); // posting key makes it idempotent
        try {
            $actual->forceFill(['amount' => '5.00'])->save();
            $this->fail('A posted actual must be immutable.');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('workflow', $ex->errors());
        }
        $avail = app(BudgetAvailability::class)->forLine($this->line);
        $this->assertSame('1000000.00', $avail['actual']);
        $this->assertSame('0.00', $avail['committed']);
        $this->assertSame('11000000.00', $avail['available']);
        $this->assertSame('approved', app(ExpenseService::class)->approverIds($e)[0] ? 'approved' : '');
    }
}
