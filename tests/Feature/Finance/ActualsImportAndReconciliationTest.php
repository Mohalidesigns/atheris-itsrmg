<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\GlAccount;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Invoice;
use App\Models\Finance\MatchRule;
use App\Models\Finance\Period;
use App\Models\User;
use App\Notifications\Finance\ImportCompletedNotification;
use App\Services\Finance\AccrualService;
use App\Services\Finance\ActualsService;
use App\Services\Finance\BudgetAvailability;
use App\Services\Finance\BudgetService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\ReconciliationService;
use App\Services\Finance\RecurringExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Finance\Concerns\BuildsExpenses;
use Tests\TestCase;

/** ATH-FIN-001 §12 FIN-03.05, FIN-03.06, FIN-03.07, FIN-03.15, FIN-07.03, FIN-02.05 and tenancy: actuals, GL import and reconciliation. */
class ActualsImportAndReconciliationTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    private GlAccount $gl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootExpenses();
        $this->gl = GlAccount::firstOrCreate(['code' => '6202'], ['name' => 'Maintenance', 'type' => 'opex']);
        DB::table('fin_budget_lines')->where('id', $this->line->id)->update(['gl_account_id' => $this->gl->id]);
    }

    private function row(string $ref, string $amount = '250000.00', string $date = '2027-01-31', string $cc = 'CC-1A', string $gl = '6202', string $memo = 'Finacle AMC'): array
    {
        return [$date, $gl, $cc, $amount, $memo, $ref];
    }

    private function avail(): array
    {
        return app(BudgetAvailability::class)->forLine($this->line);
    }

    /** FIN-03.05 / FIN-03.15: a preview writes nothing; applying creates the batch, the rows and a report in the flash shape. */
    public function test_gl_import_preview_does_not_write_and_apply_creates_batch(): void
    {
        $svc = app(ActualsService::class);
        $csv = $this->glCsv([$this->row('GL-1'), $this->row('GL-2', '100000.00')]);
        $preview = $svc->previewGl($csv, $this->glMapping(), $this->org->id);
        foreach (['rows', 'accepted', 'rejected', 'duplicates', 'matched', 'unmatched', 'errors', 'sample'] as $k) {
            $this->assertArrayHasKey($k, $preview);
        }
        $this->assertSame([2, 2, 0], [$preview['rows'], $preview['accepted'], $preview['rejected']]);
        $this->assertSame(0, Actual::count());
        $this->assertSame(0, ImportBatch::count());

        $batch = $svc->importGl($csv, $this->glMapping(), $this->fin);
        $this->assertSame('completed', $batch->status);
        $this->assertSame([2, 2, 2], [$batch->row_count, $batch->accepted_count, $batch->matched_count]);
        $this->assertSame(2, Actual::where('import_batch_id', $batch->id)->where('source', 'gl_import')->where('match_status', 'matched')->count());
        $this->assertSame('350000.00', $this->avail()['actual']);
        $this->assertSame($batch->id, $batch->report['batch_id']);
    }

    /** FIN-03.05: a saved mapping with another delimiter, date format and decimal comma reads the same ledger. */
    public function test_saved_mapping_with_other_delimiter_date_format_and_decimal_comma(): void
    {
        $mapping = ImportMapping::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'name' => 'Oracle EBS extract', 'type' => 'actuals', 'delimiter' => ';', 'date_format' => 'd/m/Y',
            'decimal_comma' => true, 'has_header' => true, 'columns' => $this->glMapping()['columns'],
        ]);
        $csv = $this->glCsv([['31/01/2027', '6202', 'CC-1A', '250000,50', 'Finacle AMC', 'EBS-1']], ';');
        $batch = app(ActualsService::class)->importGl($csv, $mapping, $this->fin);
        $this->assertSame('completed', $batch->status);
        $a = Actual::sole();
        $this->assertSame('250000.50', (string) $a->amount);
        $this->assertSame('2027-01-31', $a->posted_on->toDateString());
        $this->assertSame($mapping->id, $batch->mapping_id);
    }

    /** FIN-03.05: rules match first, then the GL/vendor/category cascade; an ambiguous row stays in the queue. */
    public function test_auto_match_by_rules_then_cascade_leaves_ambiguous_in_queue(): void
    {
        // CC-2 gets a baseline with two lines on the same GL account: the cascade cannot choose between them
        $svc = app(BudgetService::class);
        $b = $this->draft($this->cc2, '600000.00', 'NGN', null, ['gl_account_id' => $this->gl->id]);
        $svc->addLine($b, $this->lineData(['description' => 'Second licence line', 'annual_amount' => '300000.00', 'gl_account_id' => $this->gl->id]), $this->owner2);
        $svc->submit($b->refresh(), $this->owner2);
        $svc->approve($b->refresh(), $this->cfo);
        $svc->baseline($b->refresh(), $this->fin);
        $second = $b->lines()->orderByDesc('line_no')->first();

        $import = app(ActualsService::class)->importGl($this->glCsv([
            $this->row('GL-1'),                                                  // CC-1A has one line on 6202: cascade
            $this->row('GL-2', '90000.00', '2027-01-31', 'CC-2', '6202', 'Licence true-up'), // two lines on 6202: ambiguous
            $this->row('GL-3', '40000.00', '2027-01-31', 'CC-2', '6202', 'Special support'),
        ]), $this->glMapping(), $this->fin);
        $this->assertSame([1, 2], [$import->matched_count, $import->unmatched_count]);
        $queue = app(ReconciliationService::class)->queue($this->org->id, [])->get();
        $this->assertCount(2, $queue);
        $this->assertSame(['ambiguous'], $queue->pluck('match_reason')->unique()->values()->all());

        $rule = app(ReconciliationService::class)->saveRule(['name' => 'Special support', 'description_contains' => 'special support', 'budget_line_id' => $second->id], $this->fin);
        $this->assertSame(1, app(ReconciliationService::class)->runQueue($this->org->id, $this->fin));
        $this->assertSame('rule', Actual::where('source_ref', 'GL-3')->first()->match_reason);
        $this->assertSame(1, $rule->refresh()->hits);
        $this->assertSame(1, app(ReconciliationService::class)->queue($this->org->id, [])->count());
    }

    /** FIN-03.05: matching by hand, un-matching and remembering the choice as a rule. */
    public function test_manual_match_unmatch_and_remember_as_rule(): void
    {
        $svc = app(ActualsService::class);
        $svc->importGl($this->glCsv([$this->row('GL-9', '120000.00', '2027-01-31', 'CC-1A', '6202', 'Maintenance call-out')]), $this->glMapping(), $this->fin);
        $a = Actual::sole();
        $unmatched = $svc->unmatch($a, $this->fin);
        $this->assertSame(['unmatched', null], [$unmatched->match_status, $unmatched->budget_line_id]);
        $this->assertSame('0.00', $this->avail()['actual']);
        $matched = $svc->match($unmatched, $this->line, $this->fin, true);
        $this->assertSame(['matched', 'manual', $this->line->id], [$matched->match_status, $matched->match_reason, $matched->budget_line_id]);
        $this->assertSame('120000.00', $this->avail()['actual']);
        $this->assertSame(1, MatchRule::where('budget_line_id', $this->line->id)->count());
        // a line outside the actual's cost-centre tree is refused
        $other = $this->firstLine($this->baselined($this->cc2, '500000.00'));
        $this->assertRefused(fn () => $svc->match($svc->unmatch($matched, $this->fin), $other, $this->fin), 'budget_line_id');
    }

    /** FIN-03.15: bad rows reject the whole file unless the importer chooses to skip them. */
    public function test_invalid_rows_reject_whole_file_unless_skip_invalid(): void
    {
        $svc = app(ActualsService::class);
        $csv = $this->glCsv([$this->row('GL-1'), $this->row('GL-2', 'abc'), $this->row('GL-3', '10.00', '2027-01-31', 'CC-1A', '9999')]);
        $failed = $svc->importGl($csv, $this->glMapping(), $this->fin);
        $this->assertSame('failed', $failed->status);
        $this->assertSame(0, Actual::count());
        $this->assertSame(2, $failed->report['rejected']);
        $this->assertEqualsCanonicalizing(['amount', 'gl_code'], array_column($failed->report['errors'], 'field'));
        $ok = $svc->importGl($csv, $this->glMapping(), $this->fin, true);
        $this->assertSame('completed_with_errors', $ok->status);
        $this->assertSame(1, Actual::count());
    }

    /** FIN-03.15: the same file is refused a second time unless forced. */
    public function test_same_file_refused_unless_forced(): void
    {
        $svc = app(ActualsService::class);
        $csv = $this->glCsv([$this->row('GL-1')]);
        $svc->importGl($csv, $this->glMapping(), $this->fin);
        $this->assertRefused(fn () => $svc->importGl($csv, $this->glMapping(), $this->fin), 'file', 'already imported');
        $again = $svc->importGl($csv, $this->glMapping(), $this->fin, false, true);
        $this->assertSame('completed', $again->status);
        $this->assertSame(1, $again->duplicate_count, 'a forced re-import still skips rows already in the ledger');
        $this->assertSame(1, Actual::count());
    }

    /** P3-13: rows dated in a closed period are rejected. */
    public function test_rows_in_closed_period_rejected(): void
    {
        Period::where('fiscal_year_id', $this->fy->id)->where('sequence', 1)->update(['status' => 'closed']);
        $batch = app(ActualsService::class)->importGl($this->glCsv([$this->row('GL-1', '10000.00', '2027-01-31'), $this->row('GL-2', '20000.00', '2027-02-28')]), $this->glMapping(), $this->fin, true);
        $this->assertSame([1, 1], [$batch->accepted_count, $batch->rejected_count]);
        $this->assertStringContainsString('closed', $batch->report['errors'][0]['message']);
        $this->assertSame('2027-02-28', Actual::sole()->posted_on->toDateString());
    }

    /** P3-12: a GL row that repeats an approved invoice's posting is a duplicate, excluded from the totals. */
    public function test_gl_row_duplicating_invoice_actual_is_excluded_from_totals(): void
    {
        $inv = app(InvoiceService::class)->submit($this->invoice(['subtotal' => '400000.00']), $this->procurement);
        app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);
        $this->assertSame('400000.00', $this->avail()['actual']);
        $batch = app(ActualsService::class)->importGl($this->glCsv([$this->row($inv->invoice_code, '400000.00', '2027-03-01'), $this->row('GL-77', '50000.00', '2027-03-01')]), $this->glMapping(), $this->fin);
        $this->assertSame([1, 1], [$batch->duplicate_count, $batch->accepted_count]);
        $dup = Actual::where('source_ref', $inv->invoice_code)->where('source', 'gl_import')->sole();
        $this->assertSame('duplicate', $dup->match_status);
        $this->assertNotNull($dup->duplicate_of_id);
        $this->assertSame('450000.00', $this->avail()['actual'], 'only the invoice and the genuine GL row count');
    }

    /** FIN-07.03: the importer is told when a batch completes, once. */
    public function test_import_completed_notification_sent_to_importer(): void
    {
        Notification::fake();
        app(ActualsService::class)->importGl($this->glCsv([$this->row('GL-1')]), $this->glMapping(), $this->fin);
        Notification::assertSentToTimes($this->fin, ImportCompletedNotification::class, 1);
        Notification::assertNotSentTo($this->procurement, ImportCompletedNotification::class);
    }

    /** FIN-03.07: a recurring expense produces one expected invoice per due date and is idempotent. */
    public function test_recurring_expense_generates_expected_invoice_on_due_date(): void
    {
        $svc = app(RecurringExpenseService::class);
        $r = $svc->save(null, ['title' => 'Airtel link', 'vendor_id' => $this->vendor->id, 'cost_centre_id' => $this->ccChild->id, 'budget_line_id' => $this->line->id, 'frequency' => 'monthly',
            'amount' => '300000.00', 'currency' => 'NGN', 'starts_on' => '2027-03-01', 'auto_generate' => 'expected_invoice'], $this->fin);
        $this->assertSame(1, $svc->generateDue(FinanceClock::today(), $this->org->id));
        $this->assertSame(0, $svc->generateDue(FinanceClock::today(), $this->org->id));
        $inv = Invoice::where('recurring_expense_id', $r->id)->sole();
        $this->assertTrue($inv->is_expected);
        $this->assertSame('draft', $inv->status);
        $this->assertSame('2027-04-01', $r->refresh()->next_due_on->toDateString());
    }

    /** FIN-03.06: an accrual is proposed for received-not-invoiced goods, posts as an actual and reverses next period. */
    public function test_accrual_propose_post_reverse_and_prepayment_release(): void
    {
        $po = $this->po();
        $this->grn($po);
        $svc = app(AccrualService::class);
        $march = Period::where('fiscal_year_id', $this->fy->id)->where('sequence', 3)->firstOrFail();
        $this->assertSame(1, $svc->propose($march, $this->fin));
        $this->assertSame(0, $svc->propose($march, $this->fin), 'proposing twice never duplicates');
        $a = Accrual::sole();
        $this->assertSame('proposed', $a->status);
        $svc->post($a, $this->fin);
        $this->assertSame('1000000.00', $this->avail()['actual']);
        $svc->reverse($a->refresh(), $this->fin);
        $this->assertSame('reversed', $a->refresh()->status);
        $this->assertSame('0.00', $this->avail()['actual']);
        $this->assertSame('-1000000.00', (string) Actual::where('source', 'accrual_reversal')->sole()->base_amount);

        $inv = app(InvoiceService::class)->submit($this->invoice(['vendor_invoice_no' => 'PRE-1', 'subtotal' => '300000.00', 'prepayment_months' => 3]), $this->procurement);
        app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);
        $releases = Accrual::where('invoice_id', $inv->id)->orderBy('period_id')->get();
        $this->assertCount(3, $releases);
        $svc->post($releases->first(), $this->fin);
        $this->assertSame('100000.00', $this->avail()['actual']);
    }

    /** FIN-02.05, FIN-02.16 (C26): a budget built from actuals uses the prior year, and the prior-year actual follows. */
    public function test_from_actuals_mode_and_py_actual_populate(): void
    {
        $inv = app(InvoiceService::class)->submit($this->invoice(['subtotal' => '400000.00']), $this->procurement);
        app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);
        $fy2 = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2028', 'starts_on' => '2028-01-01', 'status' => 'planning'], $this->fin);
        Period::where('fiscal_year_id', $this->fy->id)->update(['status' => 'closed']);
        FiscalYear::whereKey($this->fy->id)->update(['status' => 'closed']);
        $b = app(BudgetService::class)->createVersion($this->openCycle('annual', $fy2), $this->ccChild, 'from_actuals', $this->requester);
        $l = $this->firstLine($b);
        $this->assertSame('400000.00', (string) $l->base_annual_amount);
        $this->assertSame('400000.00', (string) $l->py_actual);
    }

    /** Tenancy: another bank sees none of this bank's actuals, batches, mappings or rules, and cannot match them. */
    public function test_cross_tenant_rows_and_mappings_are_invisible(): void
    {
        $batch = app(ActualsService::class)->importGl($this->glCsv([$this->row('GL-1')]), $this->glMapping(), $this->fin);
        ImportMapping::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'name' => 'Mine', 'type' => 'actuals', 'columns' => $this->glMapping()['columns']]);
        $other = $this->otherBank();
        $intruder = User::factory()->create(['organization_id' => $other->id]);
        $intruder->assignRole('IT Finance Controller');
        $this->actingAs($intruder);
        $this->assertSame(0, Actual::count());
        $this->assertSame(0, ImportBatch::count());
        $this->assertSame(0, ImportMapping::count());
        $this->get(route('finance.imports.show', $batch))->assertNotFound();
        $this->get(route('finance.actuals.index'))->assertOk()->assertInertia(fn ($p) => $p->has('actuals', 0)->has('batches', 0));
        $actual = Actual::withoutGlobalScopes()->sole();
        $this->assertRefused(fn () => app(ActualsService::class)->unmatch($actual, $intruder));
    }
}
