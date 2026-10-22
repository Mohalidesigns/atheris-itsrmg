<?php

namespace Tests\Feature\Finance;

use App\Events\Finance\ContractRenewed;
use App\Http\Controllers\Finance\Concerns\PresentsContracts;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Evidence;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\Renewal;
use App\Notifications\Finance\ContractRenewalDueNotification;
use App\Services\Finance\ContractService;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceCalendarService;
use App\Services\Finance\RenewalService;
use App\Services\Finance\TerminationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Finance\Concerns\BuildsContracts;
use Tests\TestCase;

/**
 * Regressions for the P2 security and domain reviews (S-H1, S-M1 to S-M7, D-H1 to D-H4, D-M2, D-M3, D-M7, D-L2).
 * Acceptance IDs: FIN-06.01 to .10, FIN-07.01, FIN-07.10.
 */
class ContractReviewRegressionTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootContracts();
        Notification::fake();
    }

    private function renewals(): RenewalService
    {
        return app(RenewalService::class);
    }

    private function recommendation(array $over = []): array
    {
        return $over + ['recommendation' => 'renew_as_is', 'proposed_term_months' => 12, 'proposed_value' => '12000000', 'proposed_currency' => 'NGN', 'justification' => 'Business-critical.'];
    }

    /** S-H1 (FIN-06.08): contract documents are not reachable through the general evidence vault. */
    public function test_contract_documents_are_hidden_from_the_evidence_vault(): void
    {
        Storage::fake('local');
        $c = $this->contract();
        $doc = app(ContractService::class)->addDocument($c, ['kind' => 'contract', 'title' => 'Signed MSA'], UploadedFile::fake()->createWithContent('msa.pdf', '%PDF-1.4 secret'), $this->procurement);
        $ev = Evidence::withoutGlobalScopes()->findOrFail($doc->evidence_id);
        $viewer = $this->userWith('Viewer');
        $viewer->givePermissionTo(['view evidence', 'approve evidence', 'delete evidence']);

        $page = $this->actingAs($viewer)->get(route('evidence.index'))->assertOk();
        $this->assertStringNotContainsString('Signed MSA', $page->getContent());
        $this->actingAs($viewer)->get(route('evidence.download', $ev))->assertNotFound();
        $this->actingAs($viewer)->patch(route('evidence.review', $ev), ['decision' => 'rejected', 'review_notes' => 'x'])->assertNotFound();
        $this->actingAs($viewer)->delete(route('evidence.destroy', $ev))->assertNotFound();
        $this->assertSame('approved', $ev->refresh()->status);
        Storage::disk('local')->assertExists($ev->file_path);

        $this->actingAs($this->procurement)->get(route('finance.contracts.documents.download', [$c, $doc]))->assertOk();
    }

    /** D-H1 (FIN-06.03): with the 90/90 defaults the window opens 90 days BEFORE the notice deadline. */
    public function test_renewal_window_opens_lead_days_before_the_notice_deadline(): void
    {
        $c = $this->contract(['notice_period_days' => 90, 'renewal_lead_days' => 90, 'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31']);

        $this->assertSame('2027-10-02', $c->notice_deadline_on->toDateString());
        $this->assertSame('2027-07-04', $c->renewal_window_opens_on->toDateString());
        $this->assertSame(90, (int) $c->renewal_window_opens_on->diffInDays($c->notice_deadline_on));

        $noNotice = $this->contract(['notice_period_days' => 0, 'renewal_lead_days' => 90, 'title' => 'No notice']);
        $this->assertSame('2027-12-31', $noNotice->notice_deadline_on->toDateString());
        $this->assertSame('2027-10-02', $noNotice->renewal_window_opens_on->toDateString());
    }

    /** D-H3, S-M2 (FIN-07.01): the recommender and the opener cannot approve; the engine path and the default path agree. */
    public function test_recommender_and_opener_cannot_approve(): void
    {
        $svc = $this->renewals();
        $this->head->givePermissionTo('approve it-finance-contracts');

        // default path: head recommends, procurement opens and submits
        $r = $svc->open($this->contract(), $this->procurement);
        $svc->recommend($r, $this->recommendation(), $this->head);
        $this->assertSame($this->head->id, $r->refresh()->recommended_by_id);
        $svc->submit($r, $this->procurement);
        $this->assertSame($this->head->id, $r->refresh()->recommended_by_id, 'submit never overwrites the recommender');
        $this->refuses(fn () => $svc->approve($r, $this->head), 'approval', 'segregation');
        $this->refuses(fn () => $svc->approve($r, $this->procurement), 'approval');
        $svc->approve($r, $this->cio);
        $this->assertSame('approved', $r->refresh()->status);

        // Workflow Studio path: the Head of IT stage still refuses the recommender, and the opener
        $this->configureRenewalStages();
        $b = $svc->open($this->contract(['title' => 'Second']), $this->procurement);
        $svc->recommend($b, $this->recommendation(), $this->head);
        $svc->submit($b, $this->procurement);
        $ctx = ApprovalRequest::withoutGlobalScopes()->where('subject_id', $b->id)->latest('id')->firstOrFail()->context;
        $this->assertEqualsCanonicalizing([$this->procurement->id, $this->head->id, $this->owner->id], $ctx['sod_excluded_ids']);
        $this->refuses(fn () => $svc->approve($b, $this->head), 'approval', 'segregation');

        $opener = $this->userWith('Finance Approver', ['job_title' => 'Head of IT Operations']);
        $c = $svc->open($this->contract(['title' => 'Third']), $opener);
        $svc->recommend($c, $this->recommendation(), $this->procurement);
        $svc->submit($c, $this->procurement);
        $this->refuses(fn () => $svc->approve($c, $opener), 'approval', 'segregation');
        $this->assertSame('submitted', $c->refresh()->status);
    }

    /** The renewal approval trail is readable with `view it-finance` (the derived `view it-finance-contracts` does not exist). */
    public function test_renewal_approval_trail_is_readable_by_finance_viewers(): void
    {
        $r = $this->submittedRenewal($this->contract());

        $this->actingAs($this->head)->getJson(route('workflows.trail', ['process' => 'finance.contract_renewal', 'id' => $r->id]))->assertOk();
        $this->actingAs($this->procurement)->getJson(route('workflows.trail', ['process' => 'finance.contract_renewal', 'id' => $r->id]))->assertOk();
    }

    /** S-M1, D-M2 (FIN-07.01): the owner, value, dates and auto-renew terms are frozen while a renewal is submitted or approved. */
    public function test_contract_terms_locked_while_a_renewal_is_in_flight(): void
    {
        $svc = app(ContractService::class);
        $c = $this->contract();
        $r = $this->submittedRenewal($c);

        $this->refuses(fn () => $svc->update($c, ['owner_id' => $this->stranger->id], $this->fin), 'owner_id', 'locked');
        $this->refuses(fn () => $svc->update($c, ['contract_value' => '99000000'], $this->fin), 'contract_value', 'locked');
        $this->refuses(fn () => $svc->update($c, ['ends_on' => '2028-06-30'], $this->fin), 'ends_on', 'locked');
        $this->refuses(fn () => $svc->update($c, ['notice_period_days' => 10], $this->fin), 'notice_period_days', 'locked');
        $this->refuses(fn () => $svc->update($c, ['auto_renew' => true], $this->fin), 'auto_renew', 'locked');
        $c = $svc->update($c, ['title' => 'Renamed, same terms', 'owner_id' => $this->owner->id, 'ends_on' => '2027-12-31'], $this->fin);
        $this->assertSame('Renamed, same terms', $c->title);

        $ctx = ApprovalRequest::withoutGlobalScopes()->where('subject_id', $r->id)->latest('id')->first()?->context
            ?? app(FinanceApprovals::class)->snapshot(RenewalService::PROCESS, $r);
        $this->assertSame($this->owner->id, (int) $ctx['contract_owner_id'], 'the owner at submit time is snapshotted');
        $this->assertContains($this->owner->id, $ctx['sod_excluded_ids']);

        $this->renewals()->withdraw($r, $this->procurement); // once the renewal is withdrawn the terms unlock
        $this->assertSame($this->stranger->id, $svc->update($c, ['owner_id' => $this->stranger->id], $this->fin)->owner_id);
    }

    /** D-H2, S-M3 (FIN-06.05): termination SoD, a bounded termination date and a mandatory reason. */
    public function test_termination_segregation_dates_and_reason(): void
    {
        $svc = new class(app(TerminationService::class))
        {
            public function __construct(private TerminationService $t) {}

            public function terminate($c, $reason, $on, $by)
            {
                return $this->t->request($c, ['reason' => $reason, 'terminated_on' => $on], $by);
            }
        };
        $c = $this->contract(['commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31']);

        $this->refuses(fn () => $svc->terminate($c, 'Exit', null, $this->owner), 'terminated_on', 'segregation');   // owner
        $this->refuses(fn () => $svc->terminate($c, 'Exit', null, $this->fin), 'terminated_on', 'segregation');     // creator
        $r = $this->renewals()->open($c, $this->procurement);
        $this->renewals()->recommend($r, $this->recommendation(), $this->procurement);
        $this->refuses(fn () => $svc->terminate($c, 'Exit', null, $this->procurement), 'terminated_on', 'segregation'); // requester / recommender
        $this->refuses(fn () => $svc->terminate($c, '  ', null, $this->cfo), 'reason');
        $this->refuses(fn () => $svc->terminate($c, 'Exit', '2026-12-31', $this->cfo), 'terminated_on', 'before');      // before commencement
        $this->refuses(fn () => $svc->terminate($c, 'Exit', '2027-01-15', $this->cfo), 'terminated_on', 'before');      // beyond the 30-day back-date
        $this->refuses(fn () => $svc->terminate($c, 'Exit', '2028-01-01', $this->cfo), 'terminated_on', 'after');       // after the end date

        $t = $svc->terminate($c, 'Vendor exit', '2027-02-15', $this->cfo);
        $this->assertSame('submitted', $t->status);
        $this->assertSame('active', $c->refresh()->status, 'requesting applies nothing');
        $this->refuses(fn () => $svc->terminate($c, 'Again', '2027-02-15', $this->cfo), 'workflow', 'awaiting approval');
        app(TerminationService::class)->approve($t, $this->head);
        $done = $c->refresh();
        $this->assertSame('terminated', $done->status);
        $this->assertSame('2027-02-15', $done->terminated_on->toDateString());
        $this->assertSame(30, (int) config('finance.contracts.terminate_backdate_days'));

        $this->actingAs($this->cfo)->post(route('finance.contracts.terminate', $this->contract(['title' => 'Other'])), ['reason' => ''])->assertSessionHasErrors('reason');
    }

    /** D-H4 (FIN-06.04): moving the end date re-arms the reminder series, clears the escalation and logs the change. */
    public function test_date_change_rearms_reminders(): void
    {
        $svc = app(ContractService::class);
        $c = $this->contract(['commenced_on' => '2026-04-10', 'ends_on' => '2027-03-31', 'notice_period_days' => 10, 'notification_offsets' => [30]]);
        Artisan::call('fin:contracts-sweep');
        $this->assertSame(1, DB::table('fin_notification_logs')->where('notifiable_id', $c->id)->where('notification_key', 'renewal_due.end.2027-03-31')->where('offset_days', 30)->distinct()->count('offset_days'));

        $c->forceFill(['escalated_at' => now(), 'last_notified_offset' => 30])->save();
        Carbon::setTestNow('2027-03-10 09:00:00');
        $c = $svc->update($c->refresh(), ['ends_on' => '2027-04-09'], $this->fin);
        $this->assertNull($c->escalated_at);
        $this->assertNull($c->last_notified_offset);
        $event = ContractEvent::where('contract_id', $c->id)->where('type', 'dates_changed')->latest('id')->firstOrFail();
        $this->assertSame('2027-04-09', $event->payload['ends_on']['to']);
        $this->assertSame('2027-03-31', $event->payload['ends_on']['from']);

        Artisan::call('fin:contracts-sweep');
        Artisan::call('fin:contracts-sweep');
        $this->assertTrue(DB::table('fin_notification_logs')->where('notifiable_id', $c->id)->where('notification_key', 'renewal_due.end.2027-04-09')->where('offset_days', 30)->exists(), 'the new date has its own series');
        Notification::assertSentTo($this->owner, ContractRenewalDueNotification::class, fn ($n) => true);
    }

    /** S-M4, D-M3 (FIN-06.05): execute cannot re-date an approved term and recommend refuses an overlapping start. */
    public function test_execute_cannot_redate_and_recommend_refuses_overlap(): void
    {
        Event::fake([ContractRenewed::class]);
        $svc = $this->renewals();
        $c = $this->contract(['commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $r = $svc->open($c, $this->procurement);
        $this->refuses(fn () => $svc->recommend($r, $this->recommendation(['proposed_commences_on' => '2027-11-30']), $this->procurement), 'proposed_commences_on', 'before');
        $svc->recommend($r, $this->recommendation(['proposed_commences_on' => '2028-01-01']), $this->procurement);
        $svc->submit($r, $this->procurement);
        $svc->approve($r, $this->cio);

        $this->refuses(fn () => $svc->execute($r->refresh(), $this->procurement, ['commenced_on' => '2028-06-01']), 'commenced_on', 'approved');
        $this->assertSame('approved', $r->refresh()->status);
        $new = $svc->execute($r, $this->procurement, ['commenced_on' => '2028-01-01']);
        $this->assertSame('2028-01-01', $new->commenced_on->toDateString());
    }

    /** S-M5 (FIN-07.10): a cost centre or budget line outside the actor's visible cost centres is refused. */
    public function test_cost_centre_and_budget_line_must_be_visible_to_the_actor(): void
    {
        $svc = app(ContractService::class);
        $base = ['title' => 'Scoped', 'type' => 'other', 'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31'];
        $line = $this->baselined($this->cc)->lines()->firstOrFail();

        $this->refuses(fn () => $svc->create($base + ['cost_centre_id' => $this->cc->id], $this->owner2), 'cost_centre_id', 'outside');
        $this->refuses(fn () => $svc->create($base + ['budget_line_id' => $line->id], $this->owner2), 'budget_line_id', 'outside');
        $this->assertSame($this->cc->id, $svc->create($base + ['cost_centre_id' => $this->cc->id, 'budget_line_id' => $line->id], $this->owner)->cost_centre_id);
        $this->assertSame($this->cc2->id, $svc->create($base + ['cost_centre_id' => $this->cc2->id], $this->fin)->cost_centre_id, 'a user who sees every cost centre is not restricted');
    }

    /** S-M6, D-L2 (FIN-07.10): bands, uplift and the CFO flag are money-derived and masked; the calendar hides the band and ignores its filter. */
    public function test_value_bands_and_uplift_are_masked_without_the_amounts_permission(): void
    {
        $viewer = $this->userWith('Viewer');
        $masker = new class
        {
            use PresentsContracts;

            public function mask(array $d): array
            {
                return $this->maskContractMoney($d);
            }
        };
        $masked = $masker->mask(['renewal' => ['value_band' => 'x', 'uplift_pct' => '9.00', 'uplift_band' => '5-10', 'requires_cfo' => 'yes', 'price_escalation_pct' => '3.00', 'title' => 'keep']]);
        $this->assertSame(['value_band' => null, 'uplift_pct' => null, 'uplift_band' => null, 'requires_cfo' => null, 'price_escalation_pct' => null, 'title' => 'keep'], $masked['renewal']);

        $this->contract(['owner_id' => $viewer->id, 'commenced_on' => '2027-01-01', 'ends_on' => '2027-03-31', 'contract_value' => '5000000']);
        $cal = app(FinanceCalendarService::class);
        $from = Carbon::parse('2027-01-01');
        $to = Carbon::parse('2027-12-31');
        $blind = $cal->events($this->org->id, $from, $to, ['value_band' => 'no-such-band'], $viewer);
        $this->assertNotEmpty($blind, 'the band filter is ignored without the amounts permission');
        foreach ($blind as $e) {
            $this->assertNull($e['meta']['value_band'] ?? null);
        }
        $this->assertSame([], $cal->options($this->org->id, $viewer)['valueBands']);
        $this->assertNotEmpty($cal->options($this->org->id, $this->fin)['valueBands']);
        $this->assertNotEmpty($cal->events($this->org->id, $from, $to, [], $this->fin)[0]['meta']['value_band']);
    }

    /** S-M7: spreadsheet formulae in exported cells are neutralised. */
    public function test_csv_export_neutralises_formula_injection(): void
    {
        $events = [['start' => '2027-03-01', 'type' => 'contract_end', 'title' => '=HYPERLINK("http://evil","x")', 'status' => 'active', 'meta' => [
            'contract_code' => '+CMD', 'vendor' => '@SUM(A1)', 'owner' => "\tTab", 'cost_centre' => '-1+2', 'days_left' => -5, 'source' => 'contracts',
        ]]];
        $csv = app(FinanceCalendarService::class)->csv($events);

        $this->assertStringContainsString("\"'=HYPERLINK(", $csv);
        $this->assertStringContainsString("'+CMD", $csv);
        $this->assertStringContainsString("'@SUM(A1)", $csv);
        $this->assertStringContainsString("'-1+2", $csv);
        $this->assertStringContainsString(',-5,', $csv, 'plain negative numbers stay numbers');
    }

    /** D-M7 (FIN-06.04): Finance receives the T-minus notices alongside the team, owner and procurement. */
    public function test_finance_receives_t_minus_notices(): void
    {
        $this->contract(['commenced_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'notification_offsets' => [30]]);
        Artisan::call('fin:contracts-sweep');

        Notification::assertSentTo($this->fin, ContractRenewalDueNotification::class);
        Notification::assertSentTo($this->owner, ContractRenewalDueNotification::class);
        Notification::assertNotSentTo($this->stranger, ContractRenewalDueNotification::class);
    }
}
