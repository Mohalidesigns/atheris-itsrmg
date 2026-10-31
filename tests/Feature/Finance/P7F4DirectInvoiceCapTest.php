<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Invoice;
use App\Models\Finance\Setting;
use App\Services\Finance\FinanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/** P7 review F4 M2: a rejected or deleted invoice no longer counts against the direct-invoice cap. */
class P7F4DirectInvoiceCapTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['match_tolerance_pct' => '0', 'match_tolerance_abs' => '0']);
        app(FinanceSettings::class)->flush();
    }

    public function test_a_rejected_full_amount_invoice_does_not_block_its_corrected_replacement(): void // FIN-03 / P7-10, F4 M2
    {
        $e = $this->approvedExpense(['amount' => '1000000.00']);
        $first = $this->invoice(['expense_request_id' => $e->id, 'subtotal' => '1000000.00', 'budget_line_id' => null]);
        $this->assertRefused(fn () => $this->invoice(['expense_request_id' => $e->id, 'subtotal' => '1000000.00', 'budget_line_id' => null, 'vendor_invoice_no' => 'INV-X2']), 'subtotal', 'more than the approved amount');

        Invoice::withoutGlobalScopes()->whereKey($first->id)->update(['status' => 'rejected']);
        $second = $this->invoice(['expense_request_id' => $e->id, 'subtotal' => '1000000.00', 'budget_line_id' => null, 'vendor_invoice_no' => 'INV-X3']);
        $this->assertSame('1000000.00', (string) $second->subtotal);

        $second->delete(); // soft-deleted rows are not counted either
        $third = $this->invoice(['expense_request_id' => $e->id, 'subtotal' => '1000000.00', 'budget_line_id' => null, 'vendor_invoice_no' => 'INV-X4']);
        $this->assertSame('1000000.00', (string) $third->subtotal);
    }
}
