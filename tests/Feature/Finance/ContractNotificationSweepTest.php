<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Contract;
use App\Models\Finance\ContractEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Finance\ContractDeadlinePassedNotification;
use App\Notifications\Finance\ContractRenewalDueNotification;
use App\Notifications\Finance\ContractRenewalEscalationNotification;
use App\Notifications\Finance\ContractRenewedNotification;
use App\Notifications\Finance\FinanceDailyDigestNotification;
use App\Services\Finance\ContractService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceDigest;
use App\Services\Finance\FinanceSettings;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Finance\Concerns\BuildsContracts;
use Tests\TestCase;

/**
 * P2 notifications (FIN-06.04, FIN-07.03): the daily contract sweep sends every T-minus offset once to the contract's
 * people, escalates once at notice minus seven, announces passed deadlines and auto-rolls, and the digest goes only to
 * opted-in users. Idempotency is asserted by running every sweep twice.
 */
class ContractNotificationSweepTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    private User $tech;

    private User $member;

    private User $inactive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootContracts();
        $this->tech = $this->userWith('IT Budget Owner');
        $this->member = $this->userWith('IT Budget Owner');
        $this->inactive = $this->userWith('IT Budget Owner', ['is_active' => false]);
        Notification::fake();
    }

    private function sweepOn(string $date, int $times = 2): void
    {
        Carbon::setTestNow("{$date} 09:00:00");
        for ($i = 0; $i < $times; $i++) {
            Artisan::call('fin:contracts-sweep');
        }
    }

    /** A contract with the full cast: owner, technical owner, procurement contact, a team member and a deactivated member. */
    private function cast(array $over = []): Contract
    {
        $c = $this->contract($over + ['technical_owner_id' => $this->tech->id, 'commenced_on' => '2026-01-01', 'ends_on' => '2027-12-31']);
        app(ContractService::class)->addTeamMember($c, $this->member->id, 'member', $this->fin);
        app(ContractService::class)->addTeamMember($c, $this->inactive->id, 'member', $this->fin);

        return $c->refresh();
    }

    /** @return Collection<int,string> "series.offset" of every due notice the user got */
    private function due(User $u): Collection
    {
        return Notification::sent($u, ContractRenewalDueNotification::class)->map(fn ($n) => "{$n->series}.{$n->offset}")->values();
    }

    /** FIN-06.04: the end series sends each offset exactly once, to team, owner, technical owner and procurement, and nobody else. */
    public function test_end_series_once_per_offset_to_whole_team(): void
    {
        $c = $this->cast();
        foreach ([180, 90, 60, 30, 14, 7, 0] as $offset) {
            $this->sweepOn(Carbon::parse($c->ends_on)->subDays($offset)->toDateString());
        }

        $expected = ['end.180', 'end.90', 'end.60', 'end.30', 'end.14', 'end.7', 'end.0'];
        foreach ([$this->owner, $this->tech, $this->procurement, $this->member] as $recipient) {
            $got = $this->due($recipient);
            foreach ($expected as $key) {
                $this->assertSame(1, $got->filter(fn ($k) => $k === $key)->count(), "{$recipient->id} got {$key} exactly once");
            }
        }
        Notification::assertNotSentTo($this->stranger, ContractRenewalDueNotification::class);
        Notification::assertNotSentTo($this->inactive, ContractRenewalDueNotification::class);
        Notification::assertSentTo($this->fin, ContractRenewalDueNotification::class); // FIN-06.04: Finance is a recipient
        Notification::assertNotSentTo($this->head, ContractRenewalDueNotification::class);
        $this->assertSame(0, (int) $c->refresh()->last_notified_offset);
        $this->assertSame(5, DB::table('fin_notification_logs')->where('notifiable_id', $c->id)->where('notification_key', 'like', 'renewal_due.end.%')->where('offset_days', 90)->count());
        $this->assertSame(7, ContractEvent::where('contract_id', $c->id)->where('type', 'notified')->where('payload->series', 'end')->count());
    }

    /** FIN-06.04: the notice series is its own countdown to the notice deadline. */
    public function test_notice_series_once_per_offset(): void
    {
        $c = $this->cast(['notice_period_days' => 60, 'notification_offsets' => [30, 7, 0]]); // deadline 2027-11-01
        foreach ([30, 7, 0] as $offset) {
            $this->sweepOn(Carbon::parse($c->notice_deadline_on)->subDays($offset)->toDateString());
        }
        $notice = $this->due($this->owner)->filter(fn ($k) => str_starts_with($k, 'notice.'))->sort()->values();
        $this->assertSame(['notice.0', 'notice.30', 'notice.7'], $notice->all());
        $this->assertSame(['notice.0', 'notice.30', 'notice.7'], $this->due($this->member)->filter(fn ($k) => str_starts_with($k, 'notice.'))->sort()->values()->all());
        Notification::assertNotSentTo($this->stranger, ContractRenewalDueNotification::class);
    }

    /** Recipients are exactly the contract's people in this bank: another bank's contract notifies only its own people. */
    public function test_recipients_are_exact_and_never_cross_banks(): void
    {
        $mine = $this->cast();
        $other = $this->otherBank();
        app(FinanceSettings::class)->ensureDefaults($other->id);
        $theirOwner = $this->userWith('IT Budget Owner', [], $other->id);
        $this->actingAs($this->userWith('IT Finance Controller', [], $other->id));
        $theirs = app(ContractService::class)->create([
            'title' => 'Other bank AMC', 'type' => 'other', 'owner_id' => $theirOwner->id, 'commenced_on' => '2026-01-01', 'ends_on' => '2027-12-31', 'notice_period_days' => 30, 'activate' => true,
            'vendor_id' => Vendor::factory()->create(['organization_id' => $other->id])->id,
        ], $theirOwner);
        $this->actingAs($this->fin);

        $this->sweepOn('2027-09-30'); // ends in 92 days: nothing yet
        $this->sweepOn('2027-10-02'); // 90 days to the end of both contracts
        $end = fn (User $u) => $this->due($u)->filter(fn ($k) => str_starts_with($k, 'end.'))->values()->all();
        $this->assertSame(['end.90'], $end($this->owner));
        $this->assertSame(['end.90'], $end($theirOwner));
        $labels = Notification::sent($this->owner, ContractRenewalDueNotification::class)->pluck('contractLabel')->unique()->values()->all();
        $this->assertSame([$mine->label], $labels);
        $this->assertSame([$theirs->label], Notification::sent($theirOwner, ContractRenewalDueNotification::class)->pluck('contractLabel')->unique()->values()->all());
    }

    /** A per-contract offset list replaces the bank default for that contract. */
    public function test_per_contract_offset_override(): void
    {
        $this->cast(['notification_offsets' => [45]]); // notice deadline 2027-12-01
        $this->sweepOn('2027-10-02'); // end minus 90: a bank default, not this contract's
        $this->assertSame([], $this->due($this->owner)->all());
        $this->sweepOn('2027-11-16'); // end minus 45
        $this->sweepOn('2027-10-17'); // notice minus 45
        $this->assertEqualsCanonicalizing(['end.45', 'notice.45'], $this->due($this->owner)->all());
    }

    /** C60: a missed sweep is made up within 3 days of the offset, and nothing is sent outside that window. */
    public function test_catch_up_within_three_days_and_nothing_outside(): void
    {
        $this->cast(['notice_period_days' => 0, 'notification_offsets' => [90]]);   // notice = end
        $this->sweepOn('2027-09-26'); // 96 days to go: before the window
        $this->assertSame([], $this->due($this->owner)->all());
        $this->sweepOn('2027-10-04'); // 88 days to go: 2 days after the 90-day offset, the catch-up applies
        $this->assertSame(['end.90', 'notice.90'], $this->due($this->owner)->sort()->values()->all());
        $this->sweepOn('2027-10-05'); // 87 days: still inside the window but already sent
        $this->sweepOn('2027-10-06'); // 86 days: outside, nothing new
        $this->assertCount(2, $this->due($this->owner));
    }

    /** FIN-06.04: escalation at notice minus seven goes once to Head of IT, CIO and CFO, and not when a renewal is already submitted. */
    public function test_escalation_at_notice_minus_seven_once(): void
    {
        $quiet = $this->cast(['title' => 'No decision yet', 'commenced_on' => '2026-07-01', 'ends_on' => '2027-06-30']); // notice 2027-05-31
        $handled = $this->cast(['title' => 'Renewal submitted', 'commenced_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->submittedRenewal($handled);

        $this->sweepOn('2027-05-23'); // 8 days to the notice deadline: too early
        Notification::assertNotSentTo($this->cio, ContractRenewalEscalationNotification::class);
        $this->sweepOn('2027-05-24');
        $this->sweepOn('2027-05-25');
        foreach ([$this->head, $this->cio, $this->cfo] as $exec) {
            Notification::assertSentToTimes($exec, ContractRenewalEscalationNotification::class, 1);
        }
        Notification::assertNotSentTo($this->owner, ContractRenewalEscalationNotification::class);
        Notification::assertNotSentTo($this->stranger, ContractRenewalEscalationNotification::class);
        $this->assertStringContainsString($quiet->contract_code, Notification::sent($this->cio, ContractRenewalEscalationNotification::class)->first()->contractLabel);
        $this->assertNotNull($quiet->refresh()->escalated_at);
        $this->assertNull($handled->refresh()->escalated_at);
    }

    /** The deadline-passed notice goes once per `which` (notice, then end) to the people, Finance and the executives. */
    public function test_deadline_passed_notice_once_per_which(): void
    {
        $this->cast(['commenced_on' => '2026-04-10', 'ends_on' => '2027-04-09']); // notice deadline 2027-03-10
        $this->sweepOn('2027-03-11');
        $recipients = [$this->owner, $this->tech, $this->procurement, $this->member, $this->fin, $this->head, $this->cio, $this->cfo];
        foreach ($recipients as $u) {
            Notification::assertSentToTimes($u, ContractDeadlinePassedNotification::class, 1);
        }
        Notification::assertNotSentTo($this->stranger, ContractDeadlinePassedNotification::class);
        Notification::assertNotSentTo($this->inactive, ContractDeadlinePassedNotification::class);
        $this->assertSame('notice', Notification::sent($this->owner, ContractDeadlinePassedNotification::class)->first()->which);

        $this->sweepOn('2027-03-20'); // still the same passed deadline: no repeat
        Notification::assertSentToTimes($this->owner, ContractDeadlinePassedNotification::class, 1);
        $this->sweepOn('2027-04-10'); // the end date has passed too
        $this->assertSame(['notice', 'end'], Notification::sent($this->owner, ContractDeadlinePassedNotification::class)->pluck('which')->all());
    }

    /** An auto-renewing contract past its notice is flagged "will auto-renew" in the passed notice. */
    public function test_passed_notice_flags_auto_renewing_contracts(): void
    {
        $this->cast(['commenced_on' => '2026-04-10', 'ends_on' => '2027-04-09', 'renewal_type' => 'auto', 'auto_renew' => true]);
        $this->sweepOn('2027-03-11');
        $this->assertTrue(Notification::sent($this->owner, ContractDeadlinePassedNotification::class)->first()->autoRenew);
    }

    /** The auto-roll tells the team and Finance once, and nobody else. */
    public function test_auto_roll_sends_renewed_notification_to_team_and_finance(): void
    {
        $c = $this->cast(['commenced_on' => '2026-03-01', 'ends_on' => '2027-02-28', 'renewal_type' => 'auto', 'auto_renew' => true]);
        $this->sweepOn('2027-03-01');
        $this->assertSame('renewed', $c->refresh()->status);
        foreach ([$this->owner, $this->tech, $this->procurement, $this->member, $this->fin] as $u) {
            Notification::assertSentToTimes($u, ContractRenewedNotification::class, 1);
        }
        Notification::assertNotSentTo($this->stranger, ContractRenewedNotification::class);
        $this->assertSame('auto_renewed', Notification::sent($this->owner, ContractRenewedNotification::class)->first()->outcome);
    }

    /** C63: the digest goes once a day, at the bank's digest hour, to opted-in users only. */
    public function test_digest_once_at_the_digest_hour_to_opted_in_users_only(): void
    {
        $this->cast(['commenced_on' => '2026-03-20', 'ends_on' => '2027-03-20']);
        foreach ([$this->owner, $this->member] as $u) {
            $u->forceFill(['notification_preferences' => ['finance_daily_digest' => true]])->save();
        }
        $digest = app(FinanceDigest::class);
        $at = fn (int $h, string $day = '2027-03-01') => Carbon::parse("{$day} {$h}:05:00", config('finance.timezone'));

        $this->assertSame(0, $digest->send($at(6)), 'before the digest hour');
        $this->assertSame(0, $digest->send($at(8)), 'after the digest hour');
        $this->assertSame(2, $digest->send($at(7)));
        $this->assertSame(0, $digest->send($at(7)), 'the same day is never repeated');
        Notification::assertSentToTimes($this->owner, FinanceDailyDigestNotification::class, 1);
        Notification::assertSentToTimes($this->member, FinanceDailyDigestNotification::class, 1);
        Notification::assertNotSentTo($this->tech, FinanceDailyDigestNotification::class);
        Notification::assertNotSentTo($this->procurement, FinanceDailyDigestNotification::class);
        $this->assertSame(2, $digest->send($at(7, '2027-03-02')), 'the next day sends again');
    }

    /** The --date option runs the sweep as if it were that day (demo and testing). */
    public function test_sweep_date_option(): void
    {
        $c = $this->cast(['commenced_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'notification_offsets' => [7]]);
        Artisan::call('fin:contracts-sweep', ['--date' => '2027-03-24']);
        $this->assertSame(['end.7'], $this->due($this->owner)->all());
        $this->assertSame('notice_deadline_passed', $c->refresh()->status);
        Artisan::call('fin:contracts-sweep', ['--date' => '2027-04-05']);
        $this->assertSame('expired', $c->refresh()->status);
        $this->assertSame('2027-03-01', FinanceClock::today()->toDateString(), 'the real clock is untouched');
    }

    /** The sweep runs at 06:30 Lagos time and the digest check every hour. */
    public function test_schedules_are_registered(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);
        $sweep = $events->get('finance.contracts-sweep');
        $this->assertNotNull($sweep);
        $this->assertSame('30 6 * * *', $sweep->expression);
        $this->assertSame('Africa/Lagos', (string) $sweep->timezone);
        $this->assertTrue($sweep->withoutOverlapping);
        $digest = $events->get('finance.digest');
        $this->assertNotNull($digest);
        $this->assertSame('0 * * * *', $digest->expression);
    }

    /** FIN-07.03: every contract notification renders both channels, carries a link and no amounts. */
    public function test_contract_notifications_render_mail_and_database(): void
    {
        $url = route('finance.contracts.index');
        $all = [
            new ContractRenewalDueNotification('CON-0001 Finacle AMC', 'Infosys', 'end', 30, 28, '31 Mar 2027', $url),
            new ContractRenewalEscalationNotification('CON-0001 Finacle AMC', 'Infosys', '01 Mar 2027', 6, 'Ngozi Eze', $url),
            new ContractDeadlinePassedNotification('CON-0001 Finacle AMC', 'Infosys', 'notice', '01 Mar 2027', 3, true, $url),
            new ContractRenewedNotification('CON-0001 Finacle AMC', 'CON-0002 Finacle AMC', 'auto_renewed', '28 Feb 2028', $url),
            new ContractRenewedNotification('CON-0001 Finacle AMC', null, 'replaced', null, $url),
            new FinanceDailyDigestNotification([['title' => 'Renewals due in 30 days', 'lines' => ['CON-0001: ends in 3 day(s)']]], $url, '01 Mar 2027'),
        ];
        foreach ($all as $n) {
            $this->assertSame(['database', 'mail'], $n->via($this->owner));
            $mail = $n->toMail($this->owner);
            $this->assertInstanceOf(MailMessage::class, $mail);
            $this->assertNotSame('', (string) $mail->subject);
            $data = $n->toArray($this->owner);
            $this->assertSame('IT Financial Management', $data['module']);
            $this->assertSame($url, $data['action_url']);
            $this->assertNotSame('', $data['message']);
            $this->assertStringStartsWith('finance.', $data['type']);
            $this->assertDoesNotMatchRegularExpression('/\d{1,3}(,\d{3}){2,}/', $data['message'], 'no amounts in notification text');
        }
    }
}
