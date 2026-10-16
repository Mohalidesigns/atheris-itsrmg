<?php

namespace Tests\Feature\Finance;

use App\Http\Controllers\Finance\Concerns\PresentsContracts;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\FxRate;
use App\Models\Finance\Renewal;
use App\Models\Finance\Termination;
use App\Notifications\Finance\ContractApprovedNotExecutedNotification;
use App\Services\Finance\ContractService;
use App\Services\Finance\ContractSweeper;
use App\Services\Finance\FinanceCalendarService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RenewalService;
use App\Services\Finance\TerminationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Finance\Concerns\BuildsContracts;
use Tests\TestCase;

/**
 * P2.1 domain-review follow-ups: H2 (termination through Workflow Studio), M1 (approved-but-not-executed alerts),
 * M4 (post-deadline termination of auto-renewing contracts), M5 (per-bank CFO thresholds), M6 (uplift excluding FX).
 * Acceptance IDs: FIN-06.05, FIN-06.06, FIN-07.01, FIN-07.10.
 */
class ContractP21RegressionTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootContracts();
        Notification::fake();
    }

    private function terminations(): TerminationService
    {
        return app(TerminationService::class);
    }

    /** An approved, never-executed renewal for $c (no workflow stages: one other approver). */
    private function approvedUnexecuted(Contract $c): Renewal
    {
        $r = $this->submittedRenewal($c);
        app(RenewalService::class)->approve($r, $this->head);

        return $r->refresh();
    }

    /** H2 (FIN-06.05): a termination is requested, routed through finance.contract_termination and applied only on final approval. */
    public function test_h2_termination_is_applied_only_on_final_approval(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id); // Head of IT, then the CFO for a contract at or above 10m
        $c = $this->contract(['contract_value' => '12000000']);

        $t = $this->terminations()->request($c, ['reason' => 'Vendor exit', 'terminated_on' => '2027-03-15'], $this->cio);
        $this->assertSame('submitted', $t->status);
        $this->assertSame('active', $c->refresh()->status, 'requesting applies nothing');
        $this->assertTrue(ContractEvent::where('contract_id', $c->id)->where('type', 'termination_requested')->exists());
        $this->assertSame('yes', $t->requires_cfo);
        $this->assertContains($this->fin->id, $t->sodExcludedIds());
        $this->assertContains($this->owner->id, $t->sodExcludedIds());

        $this->refuses(fn () => $this->terminations()->approve($t, $this->cio), 'approval');   // the requester
        $this->refuses(fn () => $this->terminations()->approve($t, $this->owner), 'approval'); // the contract owner
        $msg = $this->terminations()->approve($t, $this->head);
        $this->assertStringContainsString('awaiting', strtolower($msg));
        $this->assertSame('active', $c->refresh()->status, 'a stage remains');

        $this->terminations()->approve($t, $this->cfo);
        $c->refresh();
        $this->assertSame('terminated', $c->status);
        $this->assertSame('2027-03-15', $c->terminated_on->toDateString());
        $this->assertSame('approved', $t->refresh()->status);
        $this->assertSame($this->cfo->id, $t->approved_by_id);
        $this->assertTrue(ContractEvent::where('contract_id', $c->id)->where('type', 'termination_approved')->exists());
    }

    /** H2 (FIN-06.05): a rejected or withdrawn request leaves the contract untouched and is recorded on the timeline. */
    public function test_h2_termination_rejected_and_withdrawn(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $a = $this->contract(['title' => 'A']);
        $b = $this->contract(['title' => 'B']);

        $ta = $this->terminations()->request($a, ['reason' => 'Exit'], $this->cio);
        $this->refuses(fn () => $this->terminations()->reject($ta, $this->head, ' '), 'note');
        $this->terminations()->reject($ta, $this->head, 'Not justified.');
        $this->assertSame('rejected', $ta->refresh()->status);
        $this->assertSame('active', $a->refresh()->status);
        $this->assertTrue(ContractEvent::where('contract_id', $a->id)->where('type', 'termination_rejected')->exists());
        $this->terminations()->request($a, ['reason' => 'Exit again'], $this->cio); // a rejected request does not block a new one

        $tb = $this->terminations()->request($b, ['reason' => 'Exit'], $this->cio);
        $this->refuses(fn () => $this->terminations()->withdraw($tb, $this->head), 'workflow'); // neither requester nor owner
        $this->terminations()->withdraw($tb, $this->cio);
        $this->assertSame('withdrawn', $tb->refresh()->status);
        $this->assertSame('active', $b->refresh()->status);
        $this->assertTrue(ContractEvent::where('contract_id', $b->id)->where('type', 'termination_withdrawn')->exists());
    }

    /** H2: the HTTP flow requests, then a different approver decides; only the approve group can reach the routes. */
    public function test_h2_termination_http_flow(): void
    {
        $c = $this->contract();
        $this->actingAs($this->cio)->post(route('finance.contracts.terminate', $c), ['reason' => 'Vendor exit'])->assertSessionHasNoErrors();
        $t = Termination::where('contract_id', $c->id)->firstOrFail();
        $this->assertSame('active', $c->refresh()->status);

        $this->actingAs($this->procurement)->post(route('finance.terminations.approve', $t))->assertForbidden();
        $this->actingAs($this->cio)->post(route('finance.terminations.approve', $t))->assertSessionHasErrors('approval');
        $this->actingAs($this->head)->post(route('finance.terminations.approve', $t))->assertSessionHasNoErrors();
        $this->assertSame('terminated', $c->refresh()->status);
        $this->actingAs($this->head)->get(route('finance.terminations.show', $t))->assertRedirect(route('finance.contracts.show', ['contract' => $c->id, 'tab' => 'renewal']));
    }

    /** H2: a renewal executed (or auto-rolled) while a termination is pending withdraws the pending request. */
    public function test_h2_pending_termination_is_withdrawn_when_the_contract_closes_another_way(): void
    {
        $c = $this->contract();
        $t = $this->terminations()->request($c, ['reason' => 'Exit'], $this->cio);
        $r = $this->approvedUnexecuted($c);
        app(RenewalService::class)->execute($r, $this->procurement);
        $this->assertSame('withdrawn', $t->refresh()->status);
    }

    /** M1 (FIN-07.10): an approved renewal that is never executed alerts at the end-date offsets, then daily once lapsed, once each. */
    public function test_m1_approved_but_not_executed_keeps_alerting(): void
    {
        $c = $this->contract(['commenced_on' => '2026-03-16', 'ends_on' => '2027-03-15']);
        $r = $this->approvedUnexecuted($c);
        $sweeper = app(ContractSweeper::class);

        $this->assertSame(1, $sweeper->sweep(FinanceClock::today())['unexecuted'], 'T-14 offset');
        Notification::assertSentTo($this->owner, ContractApprovedNotExecutedNotification::class, fn ($n) => $n->renewalCode === $r->renewal_code);
        $this->assertSame(0, $sweeper->sweep(FinanceClock::today())['unexecuted'], 'idempotent');

        Carbon::setTestNow('2027-03-20 09:00:00'); // five days past the end date
        $this->assertSame(1, $sweeper->sweep(FinanceClock::today())['unexecuted'], 'lapsed alert');
        $this->assertSame(0, $sweeper->sweep(FinanceClock::today())['unexecuted']);
        Carbon::setTestNow('2027-03-21 09:00:00');
        $this->assertSame(1, $sweeper->sweep(FinanceClock::today())['unexecuted'], 'and again the next day');
        $n = new ContractApprovedNotExecutedNotification('Label', 'Vendor', 'REN-1', '15 Mar 2027', -6, 'http://x');
        $this->assertDoesNotMatchRegularExpression('/\d{1,3}(,\d{3})+|NGN|USD/', json_encode($n->toArray($this->owner)));

        app(RenewalService::class)->execute($r->refresh(), $this->procurement);
        Carbon::setTestNow('2027-03-22 09:00:00');
        $this->assertSame(0, $sweeper->sweep(FinanceClock::today())['unexecuted'], 'executed: no more alerts');
    }

    /** M1 (FIN-06.06): the calendar paints an approved-unexecuted renewal red with its own event type until it is executed. */
    public function test_m1_calendar_flags_approved_unexecuted_renewals(): void
    {
        $c = $this->contract(['commenced_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
        $r = $this->approvedUnexecuted($c);
        $cal = app(FinanceCalendarService::class);
        $find = fn () => collect($cal->events($this->org->id, Carbon::parse('2027-03-01'), Carbon::parse('2027-04-30'), ['sources' => ['contracts']], $this->fin))
            ->firstWhere('meta.contract_code', $c->contract_code);

        $ends = collect($cal->events($this->org->id, Carbon::parse('2027-03-01'), Carbon::parse('2027-04-30'), ['sources' => ['contracts']], $this->fin))
            ->where('meta.contract_code', $c->contract_code)->firstWhere('start', '2027-03-31');
        $this->assertSame('contract_end_unexecuted', $ends['type']);
        $this->assertSame('red', $ends['color']);
        $this->assertSame('approved_unexecuted', $ends['status']);
        $this->assertTrue($ends['meta']['approved_unexecuted']);
        $this->assertNotNull($find());

        $line = collect($cal->timeline($this->org->id, Carbon::parse('2027-01-01'), Carbon::parse('2027-12-31'), [], $this->fin))->firstWhere('contract_code', $c->contract_code);
        $this->assertSame('red', $line['color']);
        $this->assertSame('approved_unexecuted', $line['status']);

        app(RenewalService::class)->execute($r, $this->procurement);
        $after = collect($cal->events($this->org->id, Carbon::parse('2027-03-01'), Carbon::parse('2027-04-30'), ['sources' => ['contracts']], $this->fin))
            ->where('meta.contract_code', $c->contract_code)->pluck('type');
        $this->assertNotContains('contract_end_unexecuted', $after->all());
    }

    /** M4 (FIN-06.05): terminating an auto-renewing contract after its notice deadline needs an override reason and one of its documents. */
    public function test_m4_post_deadline_termination_needs_override_reason_and_document(): void
    {
        $c = $this->contract(['renewal_type' => 'auto', 'auto_renew' => true, 'commenced_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'notice_period_days' => 60]); // notice 2027-01-30
        $other = $this->contract(['title' => 'Other']);
        $this->assertTrue($this->terminations()->needsOverride($c));
        $this->assertFalse($this->terminations()->needsOverride($other));

        $this->refuses(fn () => $this->terminations()->request($c, ['reason' => 'Exit'], $this->cfo), 'override_reason');
        $this->refuses(fn () => $this->terminations()->request($c, ['reason' => 'Exit', 'override_reason' => 'Vendor breached the SLA.'], $this->cfo), 'document_id');
        $foreign = app(ContractService::class)->addDocument($other, ['kind' => 'contract', 'title' => 'Foreign'], null, $this->procurement);
        $this->refuses(fn () => $this->terminations()->request($c, ['reason' => 'Exit', 'override_reason' => 'x', 'document_id' => $foreign->id], $this->cfo), 'document_id');

        $doc = app(ContractService::class)->addDocument($c, ['kind' => 'contract', 'title' => 'Release letter'], null, $this->procurement);
        $t = $this->terminations()->request($c, ['reason' => 'Exit', 'override_reason' => 'Vendor breached the SLA.', 'document_id' => $doc->id], $this->cfo);
        $this->assertTrue($t->is_post_deadline);
        $this->assertSame($doc->id, $t->override_document_id);
        $this->assertSame('yes', $t->requires_cfo, 'a post-deadline override always reaches the CFO stage');
        $event = ContractEvent::where('contract_id', $c->id)->where('type', 'termination_requested')->firstOrFail();
        $this->assertTrue($event->payload['override']);
        $this->assertSame('Vendor breached the SLA.', $event->payload['override_reason']);

        $this->terminations()->approve($t, $this->head);
        $term = ContractEvent::where('contract_id', $c->id)->where('type', 'terminated')->firstOrFail();
        $this->assertSame('Vendor breached the SLA.', $term->payload['override_reason'], 'the override is recorded on the terminated event');

        $plain = $this->terminations()->request($other, ['reason' => 'Exit'], $this->cfo); // not auto-renewing: no override needed
        $this->assertFalse($plain->is_post_deadline);

        $http = $this->contract(['title' => 'Http', 'renewal_type' => 'auto', 'auto_renew' => true, 'commenced_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'notice_period_days' => 60]);
        $this->actingAs($this->cfo)->post(route('finance.contracts.terminate', $http), ['reason' => 'Exit'])->assertSessionHasErrors('override_reason');
    }

    /** M5 (FIN-06.05): two banks with different CFO thresholds derive requires_cfo differently; null falls back to the config default. */
    public function test_m5_cfo_thresholds_are_per_bank(): void
    {
        $other = $this->otherBank();
        $settings = app(FinanceSettings::class);
        $settings->ensureDefaults($other->id);
        $settings->for($this->org->id)->forceFill(['renewal_cfo_from' => '5000000', 'renewal_cfo_uplift_pct' => '8'])->save();
        $settings->for($other->id)->forceFill(['renewal_cfo_from' => '50000000', 'renewal_cfo_uplift_pct' => '20'])->save();
        $settings->flush();

        $mine = new Renewal(['organization_id' => $this->org->id, 'proposed_base_value' => '6000000']);
        $theirs = new Renewal(['organization_id' => $other->id, 'proposed_base_value' => '6000000']);
        $this->assertSame('yes', $mine->requires_cfo);
        $this->assertSame('no', $theirs->requires_cfo);
        $this->assertSame('yes', (new Renewal(['organization_id' => $this->org->id, 'proposed_base_value' => '1000', 'uplift_pct' => '9']))->requires_cfo);
        $this->assertSame('no', (new Renewal(['organization_id' => $other->id, 'proposed_base_value' => '1000', 'uplift_pct' => '9']))->requires_cfo);

        $settings->for($other->id)->forceFill(['renewal_cfo_from' => null, 'renewal_cfo_uplift_pct' => null])->save();
        $settings->flush();
        $this->assertSame('yes', (new Renewal(['organization_id' => $other->id, 'proposed_base_value' => '10000000']))->requires_cfo, 'config default 10m');
        $this->assertSame('no', (new Renewal(['organization_id' => $other->id, 'proposed_base_value' => '9999999']))->requires_cfo);

        $admin = $this->userWith('Organization Admin');
        $this->actingAs($admin)->put(route('finance.settings.update'), [
            'section' => 'renewal', 'renewal_lead_days' => 90, 'renewal_notice_offsets' => [90, 30], 'escalation_role' => 'Head of IT',
            'renewal_cfo_from' => '75000000', 'renewal_cfo_uplift_pct' => '12.5',
        ])->assertSessionHasNoErrors();
        $row = DB::table('fin_settings')->where('organization_id', $this->org->id)->first();
        $this->assertSame('75000000.00', (string) $row->renewal_cfo_from);
        $this->assertSame('12.50', (string) $row->renewal_cfo_uplift_pct);
        $this->actingAs($admin)->put(route('finance.settings.update'), [
            'section' => 'renewal', 'renewal_lead_days' => 90, 'renewal_notice_offsets' => [90], 'renewal_cfo_uplift_pct' => '-1',
        ])->assertSessionHasErrors('renewal_cfo_uplift_pct');
    }

    /** M6 (FIN-06.05): a same-currency renewal's uplift is struck in the contract currency; the FX effect is stored apart and masked. */
    public function test_m6_uplift_excludes_fx(): void
    {
        $settings = app(FinanceSettings::class);
        $settings->for($this->org->id)->forceFill(['renewal_cfo_from' => '100000000'])->save();
        $settings->flush();
        $c = $this->contract(['currency' => 'USD', 'contract_value' => '10000.00']); // booked at 1500
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1800, 'rate_date' => '2027-02-20', 'rate_type' => 'spot']);

        $svc = app(RenewalService::class);
        $r = $svc->recommend($svc->open($c, $this->procurement), [
            'recommendation' => 'renew_as_is', 'proposed_term_months' => 12, 'proposed_value' => '10500', 'proposed_currency' => 'USD', 'justification' => 'Price held at +5%.',
        ], $this->procurement)->refresh();

        $this->assertSame('5.00', (string) $r->uplift_pct, 'like-for-like, contract currency');
        $this->assertSame('26.00', (string) $r->uplift_base_pct, 'base-currency change (1500 to 1800 and +5%)');
        $this->assertSame('21.00', (string) $r->fx_effect_pct);
        $this->assertSame('0-5', $r->uplift_band);
        $this->assertSame('no', $r->requires_cfo, 'the CFO test uses the like-for-like uplift, not the FX-inflated base change');

        // A different quote currency: valued at the rate on the contract's own rate date, so FX is stripped from uplift_pct.
        $r2 = $svc->recommend($r, [
            'recommendation' => 'renew_as_is', 'proposed_term_months' => 12, 'proposed_value' => '16000000', 'proposed_currency' => 'NGN', 'justification' => 'Billed in naira.',
        ], $this->procurement)->refresh();
        $this->assertSame('6.67', (string) $r2->uplift_pct);
        $this->assertSame('6.67', (string) $r2->uplift_base_pct);
        $this->assertSame('0.00', (string) $r2->fx_effect_pct);

        $masker = new class
        {
            use PresentsContracts;

            public function mask(array $d): array
            {
                return $this->maskContractMoney($d);
            }
        };
        $masked = $masker->mask(['renewal' => ['uplift_pct' => '5.00', 'uplift_base_pct' => '26.00', 'fx_effect_pct' => '21.00', 'title' => 'keep']]);
        $this->assertSame(['uplift_pct' => null, 'uplift_base_pct' => null, 'fx_effect_pct' => null, 'title' => 'keep'], $masked['renewal']);
    }
}
