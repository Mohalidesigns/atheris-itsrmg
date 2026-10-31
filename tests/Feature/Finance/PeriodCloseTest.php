<?php

namespace Tests\Feature\Finance;

use App\Events\Finance\PeriodCloseBlocked;
use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\Forecast;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\TaxType;
use App\Models\Finance\VarianceNote;
use App\Models\User;
use App\Services\Finance\AccrualService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PeriodCloseService;
use App\Services\Finance\ProcurementService;
use App\Services\Finance\VarianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/**
 * ATH-FIN-001 §12 FIN-04.05 (close checklist, lock, re-open with approval), FIN-01 (close refused while actuals are unposted),
 * FIN-07.01 (default approver) and the P3 carry-overs M7, M8 and M9. Fixture as MonitoringAndVarianceTest: today is 2027-04-05,
 * January to March have ended, three 12m lines.
 */
class PeriodCloseTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
    }

    private function svc(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    /** Posts the budget exactly on every line of the period, so it has actuals and no variance. */
    private function fill(int $seq, array $lines = [0, 1, 2]): Period
    {
        foreach ($lines as $i) {
            $this->postActual($this->monLines[$i], $this->periodSeq($seq), '1000000.00');
        }

        return $this->periodSeq($seq);
    }

    /** Fills and closes periods 1 to $n in order. */
    private function closeThrough(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->svc()->close($this->fill($i), $this->fin);
        }
    }

    private function item(array $checklist, string $key): array
    {
        return collect($checklist['items'])->firstWhere('key', $key);
    }

    private function stateOf(Period $p): string
    {
        return Period::withoutGlobalScopes()->find($p->id)->status;
    }

    /** FIN-04.05 (P0 D10): a period cannot close before it has ended. */
    public function test_close_is_refused_while_the_period_has_not_ended(): void
    {
        $p4 = $this->periodSeq(4); // April, 2027-04-30
        $this->assertSame('fail', $this->item($this->svc()->checklist($p4), 'period_ended')['status']);
        $e = $this->assertRefused(fn () => $this->svc()->close($p4, $this->fin), 'checklist', 'Period has ended');
        $this->assertSame('open', $this->stateOf($p4));
        $this->assertNotEmpty($e->errors()['checklist']);
        // the early-close switch is a config value
        config(['finance.monitoring.allow_early_close' => true]);
        $this->assertSame('pass', $this->item($this->svc()->checklist($p4), 'period_ended')['status']);
    }

    /** FIN-01, FIN-04.05: a period with no actuals, or with an unmatched actual, cannot close. */
    public function test_close_refused_while_actuals_unposted(): void
    {
        $p1 = $this->periodSeq(1);
        $c = $this->svc()->checklist($p1);
        $this->assertSame('fail', $this->item($c, 'actuals_posted')['status']);
        $this->assertFalse($c['can_close']);
        $this->assertRefused(fn () => $this->svc()->close($p1, $this->fin), 'checklist', 'No actuals have been posted');

        $this->fill(1);
        $this->postActual($this->monLines[0], $p1, '5000.00', ['match_status' => 'unmatched', 'budget_line_id' => null]);
        $item = $this->item($this->svc()->checklist($p1), 'actuals_posted');
        $this->assertSame(['fail', 1], [$item['status'], $item['count']]);
        $this->assertRefused(fn () => $this->svc()->close($p1, $this->fin), 'checklist', '1 unmatched');
        $this->assertSame('open', $this->stateOf($p1));

        Actual::withoutGlobalScopes()->where('match_status', 'unmatched')->update(['match_status' => 'duplicate']); // a duplicate is not counted
        $this->assertSame('pass', $this->item($this->svc()->checklist($p1), 'actuals_posted')['status']);
    }

    /** FIN-04.03, FIN-04.05: an unexplained red variance blocks the close; explained, it passes; amber only warns. */
    public function test_close_blocked_by_unexplained_red_variance(): void
    {
        $this->closeThrough(2);
        $p3 = $this->fill(3, [1, 2]);
        $this->postActual($this->monLines[0], $p3, '1400000.00'); // YTD 3.4m against 3m: +13.3 %, red
        Event::fake([PeriodCloseBlocked::class]);
        $this->assertRefused(fn () => $this->svc()->close($p3, $this->fin), 'checklist', 'Variances are explained');
        Event::assertDispatched(PeriodCloseBlocked::class, fn ($e) => $e->periodId === $p3->id && $e->byUserId === $this->fin->id && $e->blockers !== []);
        $this->assertSame('open', $this->stateOf($p3));
        $this->assertSame('fail', $this->item($this->svc()->checklist($p3), 'variances_explained')['status']);

        $this->assertSame(1, $this->explainAll($p3));
        // P7-5: explained is not enough for a red note; a second person must acknowledge it.
        $item = $this->item($this->svc()->checklist($p3), 'variances_explained');
        $this->assertSame('fail', $item['status']);
        $this->assertStringContainsString('1 red variance(s) await acknowledgement', $item['detail']);
        foreach (VarianceNote::withoutGlobalScopes()->where('period_id', $p3->id)->where('status', 'explained')->get() as $note) {
            app(VarianceService::class)->acknowledge($note, $this->fin);
        }
        $this->assertSame('pass', $this->item($this->svc()->checklist($p3), 'variances_explained')['status']);
        $this->svc()->close($p3, $this->fin);
        $this->assertSame('closed', $this->stateOf($p3));
    }

    /** FIN-04.03: amber variances only warn at close (and fail when the bank asks for it). */
    public function test_amber_only_warns(): void
    {
        $this->closeThrough(2);
        $p3 = $this->fill(3, [1, 2]);
        $this->postActual($this->monLines[0], $p3, '1180000.00'); // YTD 3.18m against 3m: +6 %, amber
        app(VarianceService::class)->evaluate($p3, $this->fin); // the checklist reads notes, which the close (or a sweep) evaluates first
        $c = $this->svc()->checklist($p3);
        $this->assertSame('warn', $this->item($c, 'variances_explained')['status']);
        $this->assertTrue($c['can_close']);

        config(['finance.monitoring.explain_amber_at_close' => true]);
        $this->assertSame('fail', $this->item($this->svc()->checklist($p3), 'variances_explained')['status']);
        config(['finance.monitoring.explain_amber_at_close' => false]);
        $closed = $this->svc()->close($p3, $this->fin);
        $this->assertSame('warn', $this->item(['items' => $closed->checklist], 'variances_explained')['status']);
    }

    /** M9, FIN-04.05: proposed accruals, proposed prepayment releases and accruals nobody has proposed all block the close. */
    public function test_close_blocked_by_pending_accruals_and_releases(): void
    {
        $this->closeThrough(2);
        $p3 = $this->periodSeq(3);
        $po = $this->po(); // 1m on line 0, received 1 March, not invoiced
        $this->grn($po);
        $this->fill(3, [1, 2]);

        // an accrual that has not been proposed yet
        $this->assertSame(['accruals' => 0, 'releases' => 0, 'candidates' => 1, 'reversals' => 0], app(AccrualService::class)->pendingFor($p3));
        $this->assertSame('fail', $this->item($this->svc()->checklist($p3), 'accruals_done')['status']);
        $this->assertRefused(fn () => $this->svc()->close($p3, $this->fin), 'checklist', 'not yet proposed');

        // proposed, not posted
        $this->assertSame(1, app(AccrualService::class)->propose($p3, $this->fin));
        $this->assertSame(['accruals' => 1, 'releases' => 0, 'candidates' => 0, 'reversals' => 0], app(AccrualService::class)->pendingFor($p3));
        $this->assertRefused(fn () => $this->svc()->close($p3, $this->fin), 'checklist', '1 proposed accrual');

        // a proposed prepayment release as well
        $inv = app(InvoiceService::class)->submit($this->invoice(['vendor_invoice_no' => 'PRE-9', 'subtotal' => '300000.00', 'prepayment_months' => 3]), $this->procurement);
        app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);
        $this->assertSame(1, app(AccrualService::class)->pendingFor($p3)['releases']);
        $this->assertRefused(fn () => $this->svc()->close($p3, $this->fin), 'checklist', '1 proposed release');

        // post everything: nothing is outstanding
        app(AccrualService::class)->postAll($p3, $this->fin);
        app(AccrualService::class)->releasePrepayments($p3, $this->fin);
        $this->assertSame(['accruals' => 0, 'releases' => 0, 'candidates' => 0, 'reversals' => 0], app(AccrualService::class)->pendingFor($p3));
        $this->assertSame('pass', $this->item($this->svc()->checklist($p3), 'accruals_done')['status']);
        $this->assertSame(0, Accrual::where('period_id', $p3->id)->where('status', 'proposed')->count());
    }

    /** FIN-04.05: an invoice still draft or submitted in the period blocks the close; disputed only warns. */
    public function test_close_refused_while_an_invoice_is_draft_or_submitted(): void
    {
        $p3 = $this->periodSeq(3);
        $inv = $this->invoice(['invoice_date' => '2027-03-15']);
        $item = $this->item($this->svc()->checklist($p3), 'invoices_approved');
        $this->assertSame(['fail', 1], [$item['status'], $item['count']]);
        app(InvoiceService::class)->submit($inv, $this->procurement);
        $this->assertSame('fail', $this->item($this->svc()->checklist($p3), 'invoices_approved')['status']);
        app(InvoiceService::class)->approve($inv->refresh(), $this->cfo);
        $this->assertSame('pass', $this->item($this->svc()->checklist($p3), 'invoices_approved')['status']);
        Invoice::withoutGlobalScopes()->whereKey($inv->id)->update(['status' => 'disputed']);
        $this->assertSame('warn', $this->item($this->svc()->checklist($p3), 'invoices_approved')['status']);
    }

    /** FIN-04.05: a successful close writes the PeriodClose record with its checklist and a forecast snapshot; lock only follows a close. */
    public function test_close_records_the_checklist_and_lock_follows_close(): void
    {
        $p1 = $this->fill(1);
        $this->assertRefused(fn () => $this->svc()->lock($p1, $this->fin)); // still open
        $c = $this->svc()->close($p1, $this->fin);
        $this->assertSame(['closed', $this->fin->id], [$c->status, $c->closed_by_id]);
        $this->assertCount(9, $c->checklist);
        $this->assertSame(['period_ended', 'earlier_periods_closed', 'actuals_posted', 'duplicates_resolved', 'accruals_done', 'accruals_cancelled', 'invoices_approved', 'variances_explained', 'cloud_imported'], array_column($c->checklist, 'key'));
        $this->assertNotContains('fail', array_column($c->checklist, 'status'));
        $this->assertSame('n_a', $this->item(['items' => $c->checklist], 'cloud_imported')['status']);
        $this->assertSame(3, Forecast::withoutGlobalScopes()->where('period_id', $p1->id)->where('is_snapshot', true)->whereNull('superseded_at')->count());
        $this->assertRefused(fn () => $this->svc()->close($p1, $this->fin), null, 'cannot be closed'); // closing twice

        $l = $this->svc()->lock($p1, $this->fin);
        $this->assertSame(['locked', $this->fin->id], [$l->status, $l->locked_by_id]);
        $this->assertSame('locked', $this->stateOf($p1));
        $this->assertSame(1, PeriodClose::withoutGlobalScopes()->where('period_id', $p1->id)->count(), 'the same close record moves on');
        $this->assertRefused(fn () => $this->svc()->close($this->periodSeq(3), $this->fin), 'checklist', 'Earlier periods are closed'); // period 2 is still open
    }

    /** FIN-04.05: the earlier periods must be closed first. */
    public function test_earlier_periods_must_be_closed_first(): void
    {
        $p2 = $this->fill(2);
        $item = $this->item($this->svc()->checklist($p2), 'earlier_periods_closed');
        $this->assertSame(['fail', 1], [$item['status'], $item['count']]);
        $this->assertRefused(fn () => $this->svc()->close($p2, $this->fin), 'checklist');
    }

    /** FIN-04.05, FIN-07.01: without configured stages one other approver decides; neither the requester nor the closer may. */
    public function test_unconfigured_default_approver_and_reopen_flow(): void
    {
        $this->closeThrough(2);
        $this->fin->givePermissionTo('approve it-finance'); // so that only segregation of duties stops the closer
        $p2 = $this->periodSeq(2);
        $closeId = PeriodClose::withoutGlobalScopes()->where('period_id', $p2->id)->value('id');

        $this->assertRefused(fn () => $this->svc()->requestReopen($p2, 'short', $this->cfo), 'reason');
        $req = $this->svc()->requestReopen($p2, 'The February invoice for the Interswitch link was posted to the wrong line.', $this->cfo);
        $this->assertSame([$closeId, 'submitted', $this->cfo->id], [$req->id, $req->reopen_status, $req->reopen_requested_by_id]);
        $this->assertRefused(fn () => $this->svc()->requestReopen($p2, 'Another request for the very same period.', $this->cfo), null, 'already has a re-open request');

        $this->assertRefused(fn () => $this->svc()->approveReopen($req->refresh(), $this->cfo), null); // the requester
        $this->assertRefused(fn () => $this->svc()->approveReopen($req->refresh(), $this->fin), null); // whoever created the close record
        $this->assertRefused(fn () => $this->svc()->approveReopen($req->refresh(), $this->stranger), 'approval', 'approve it-finance'); // no permission
        $this->assertSame('closed', $this->stateOf($p2));

        $this->svc()->approveReopen($req->refresh(), $this->head);
        $this->assertSame('open', $this->stateOf($p2));
        $req->refresh();
        $this->assertSame(['reopened', 'approved', $this->head->id], [$req->status, $req->reopen_status, $req->reopen_decided_by_id]);
        $period = Period::withoutGlobalScopes()->find($p2->id);
        $this->assertNull($period->closed_at);
        $this->assertNull($period->closed_by_id);

        // the history stays: closing again writes a second record
        $this->svc()->close($this->periodSeq(2), $this->fin);
        $this->assertSame(['reopened', 'closed'], PeriodClose::withoutGlobalScopes()->where('period_id', $p2->id)->orderBy('id')->pluck('status')->all());
    }

    /** FIN-04.05: with the seeded stage the CFO decides, and nobody else does (not the requester, not another approver). */
    public function test_reopen_with_configured_cfo_stage(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $this->closeThrough(1);
        $p1 = $this->periodSeq(1);
        $req = $this->svc()->requestReopen($p1, 'The January accrual was posted to the wrong cost centre.', $this->fin);
        foreach ([$this->fin, $this->head, $this->md] as $wrong) {
            $this->assertRefused(fn () => $this->svc()->approveReopen($req->refresh(), $wrong), null);
        }
        $this->assertSame('closed', $this->stateOf($p1));
        $this->svc()->approveReopen($req->refresh(), $this->cfo);
        $this->assertSame('open', $this->stateOf($p1));
        $this->assertSame('approved', $req->refresh()->reopen_status);
    }

    /** C116: only the bank's latest closed period can be re-opened. */
    public function test_only_the_latest_non_open_period_can_be_reopened(): void
    {
        $this->closeThrough(2);
        $this->assertRefused(fn () => $this->svc()->requestReopen($this->periodSeq(1), 'January needs a correction, please.', $this->fin), 'reason', 'latest closed period');
        $this->assertRefused(fn () => $this->svc()->requestReopen($this->periodSeq(3), 'March is still open so there is nothing.', $this->fin), null, 'needs no re-open');
        $req = $this->svc()->requestReopen($this->periodSeq(2), 'February needs a correction, please.', $this->fin);
        $this->assertSame('submitted', $req->reopen_status);
        // a later period that closes while the request is pending blocks the approval (re-checked at the decision)
        $this->svc()->close($this->fill(3), $this->fin);
        $this->assertRefused(fn () => $this->svc()->approveReopen($req->refresh(), $this->cfo), 'reason', 'latest closed period');
        $this->assertSame('closed', $this->stateOf($this->periodSeq(2)));
    }

    /** FIN-04.05: reject needs a reason and leaves the period closed; withdraw is the requester's; either allows a fresh request. */
    public function test_reject_and_withdraw(): void
    {
        $this->closeThrough(1);
        $p1 = $this->periodSeq(1);
        $req = $this->svc()->requestReopen($p1, 'January needs a correction, please.', $this->fin);
        $this->assertRefused(fn () => $this->svc()->rejectReopen($req->refresh(), $this->cfo, ' '), 'note');
        $this->assertRefused(fn () => $this->svc()->rejectReopen($req->refresh(), $this->stranger, 'No'), 'approval');
        $r = $this->svc()->rejectReopen($req->refresh(), $this->cfo, 'The correction can go into February.');
        $this->assertSame(['rejected', $this->cfo->id, 'The correction can go into February.'], [$r->reopen_status, $r->reopen_decided_by_id, $r->reopen_rejection_reason]);
        $this->assertSame('closed', $this->stateOf($p1));
        $this->assertRefused(fn () => $this->svc()->approveReopen($r->refresh(), $this->cfo), null, 'not awaiting');

        $second = $this->svc()->requestReopen($p1, 'A second, better argued, request for January.', $this->fin);
        $this->assertRefused(fn () => $this->svc()->withdrawReopen($second->refresh(), $this->cfo), null, 'Only the requester');
        $this->assertSame('withdrawn', $this->svc()->withdrawReopen($second->refresh(), $this->fin)->reopen_status);
        $this->assertSame('closed', $this->stateOf($p1));
    }

    /** FIN-04.05 over HTTP: the Finance Controller closes and requests, the CFO decides; the requester has no way to decide. */
    public function test_close_and_reopen_over_http(): void
    {
        $p1 = $this->fill(1);
        $this->actingAs($this->fin)->post(route('finance.periods.close', $this->periodSeq(3)))->assertSessionHasErrors('checklist');
        $this->actingAs($this->fin)->post(route('finance.periods.close', $p1))->assertSessionHasNoErrors();
        $this->assertSame('closed', $this->stateOf($p1));
        $this->actingAs($this->requester)->post(route('finance.periods.reopen', $p1), ['reason' => 'Not allowed to ask for this one.'])->assertForbidden();
        $this->actingAs($this->fin)->post(route('finance.periods.reopen', $p1), ['reason' => 'Late settlement invoice must be added to January.'])->assertRedirect()->assertSessionHasNoErrors();
        $close = PeriodClose::withoutGlobalScopes()->where('reopen_status', 'submitted')->sole();
        $this->actingAs($this->fin)->post(route('finance.period-closes.approve', $close))->assertSessionHasErrors(); // the requester holds the permission but may not decide
        $this->actingAs($this->requester)->post(route('finance.period-closes.approve', $close))->assertForbidden();
        $this->actingAs($this->cfo)->post(route('finance.period-closes.approve', $close))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('open', $this->stateOf($p1));
        $this->actingAs($this->fin)->get(route('finance.period-closes.show', $close))->assertRedirect();
        $this->actingAs($this->fin)->post(route('finance.periods.lock', $this->periodSeq(2)))->assertSessionHasErrors();
    }

    /** M8: a submitted invoice no longer reduces the accrual, and neither does an approved invoice of a later period. */
    public function test_accrual_cutoff(): void
    {
        $po = $this->po(); // 2 x 500,000, received in full on 1 March
        $this->grn($po);
        $svc = app(AccrualService::class);
        $p3 = $this->periodSeq(3);
        $this->assertSame('1000000.00', $svc->candidates($p3)[0]['amount']);
        $line = $po->lines()->first();
        $bill = fn (string $date, string $no) => $this->invoice(['po_id' => $po->id, 'vendor_invoice_no' => $no, 'invoice_date' => $date,
            'lines' => [['po_line_id' => $line->id, 'description' => 'Core switch', 'quantity' => '1', 'unit_cost' => '500000.00']]]);

        $submitted = app(InvoiceService::class)->submit($bill('2027-03-20', 'CUT-1'), $this->procurement);
        $this->assertSame('1000000.00', $svc->candidates($p3)[0]['amount'], 'a submitted invoice is not yet a cost');

        $later = app(InvoiceService::class)->submit($bill('2027-04-02', 'CUT-2'), $this->procurement);
        app(InvoiceService::class)->approve($later->refresh(), $this->cfo);
        $this->assertSame('approved', $later->refresh()->status);
        $this->assertSame('1000000.00', $svc->candidates($p3)[0]['amount'], 'an April invoice does not reduce a March accrual');

        app(InvoiceService::class)->approve($submitted->refresh(), $this->cfo);
        $this->assertSame('500000.00', $svc->candidates($p3)[0]['amount'], 'an approved March invoice does');
    }

    /** M7: the accrual carries VAT at the purchase order's tax type, on the same basis as the invoice. */
    public function test_accrual_includes_vat(): void
    {
        $vat = TaxType::where('code', 'VAT')->firstOrFail();
        $e = $this->approvedExpense(['amount' => '1075000.00']); // the PO total includes VAT
        $po = app(ProcurementService::class)->commit($e, ['po_date' => '2027-03-01', 'tax_type_id' => $vat->id,
            'lines' => [['description' => 'Core switch', 'quantity' => '2', 'unit_cost' => '500000.00']]], $this->procurement);
        $this->grn($po);
        $c = app(AccrualService::class)->candidates($this->periodSeq(3));
        $this->assertCount(1, $c);
        $this->assertSame(['1075000.00', '75000.00', '1075000.00'], [$c[0]['amount'], $c[0]['tax_amount'], $c[0]['base_amount']]);
        app(AccrualService::class)->propose($this->periodSeq(3), $this->fin);
        $this->assertSame('1075000.00', (string) Accrual::sole()->base_amount);
    }

    /** Tenancy: another bank gets a 404 on this bank's periods and close records. */
    public function test_other_bank_gets_404(): void
    {
        $p1 = $this->fill(1);
        $this->svc()->close($p1, $this->fin);
        $close = PeriodClose::withoutGlobalScopes()->where('period_id', $p1->id)->firstOrFail();
        $other = $this->otherBank();
        $fin = $this->userWith('IT Finance Controller', [], $other->id);
        $cfo = $this->userWith('Finance Approver', ['job_title' => 'Chief Financial Officer'], $other->id);
        $this->actingAs($fin);
        $this->post(route('finance.periods.close', $this->periodSeq(2)))->assertNotFound();
        $this->post(route('finance.periods.lock', $p1))->assertNotFound();
        $this->post(route('finance.periods.reopen', $p1), ['reason' => 'A reason of sufficient length.'])->assertNotFound();
        $this->post(route('finance.periods.evaluate-variances', $p1))->assertNotFound();
        $this->get(route('finance.periods.close-checklist', $p1))->assertNotFound();
        $this->get(route('finance.period-closes.show', $close))->assertNotFound();
        $this->post(route('finance.period-closes.withdraw', $close))->assertNotFound();
        $this->actingAs($cfo);
        $this->post(route('finance.period-closes.approve', $close))->assertNotFound();
        $this->post(route('finance.period-closes.reject', $close), ['note' => 'No'])->assertNotFound();
        // and the service refuses a cross-bank actor outright
        $this->assertRefused(fn () => $this->svc()->close($this->periodSeq(2), User::find($fin->id)), null, 'another bank');
    }
}
