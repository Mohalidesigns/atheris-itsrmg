<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Contract;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Finance\ContractDeadlinePassedNotification;
use App\Notifications\Finance\ContractRenewalDueNotification;
use App\Notifications\Finance\ContractRenewalEscalationNotification;
use App\Notifications\Finance\ContractRenewedNotification;
use App\Notifications\Finance\FinanceDailyDigestNotification;
use App\Services\Finance\ContractService;
use App\Services\Finance\FinanceCalendarService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceDigest;
use App\Services\Finance\FinanceSettings;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** WP-C wiring smoke: sweeps, notifications, digest and calendar feed. QA owns the exhaustive suites. */
class ContractWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $fin;

    private User $owner;

    private User $member;

    private User $stranger;

    private User $cio;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2027-03-01 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create(['currency' => 'NGN']);
        $mk = fn (string $role, array $extra = []) => tap(User::factory()->create(['organization_id' => $this->org->id] + $extra), fn ($u) => $u->assignRole($role));
        $this->fin = $mk('IT Finance Controller');
        $this->owner = $mk('IT Budget Owner');
        $this->member = $mk('IT Budget Owner');
        $this->stranger = $mk('IT Budget Owner');
        $this->cio = $mk('Finance Approver', ['job_title' => 'Chief Information Officer']);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $this->actingAs($this->fin);
        $this->vendor = Vendor::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Cloud']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contract(string $endsOn, array $over = []): Contract
    {
        $c = app(ContractService::class)->create($over + [
            'title' => 'Support', 'type' => 'support_maintenance', 'vendor_id' => $this->vendor->id, 'owner_id' => $this->owner->id,
            'commenced_on' => '2026-01-01', 'ends_on' => $endsOn, 'notice_period_days' => 30, 'contract_value' => '1000000', 'activate' => true,
        ], $this->fin);
        app(ContractService::class)->addTeamMember($c, $this->member->id, 'member', $this->fin);

        return $c->refresh();
    }

    private function sweep(): void
    {
        Artisan::call('fin:contracts-sweep');
    }

    public function test_t_minus_sends_once_per_offset_to_team_owner_and_nobody_else(): void
    {
        Notification::fake();
        $c = $this->contract('2027-03-31', ['notification_offsets' => [30, 7]]); // ends in 30 days
        $this->sweep();
        $this->sweep();
        Notification::assertSentToTimes($this->owner, ContractRenewalDueNotification::class, 1);
        Notification::assertSentToTimes($this->member, ContractRenewalDueNotification::class, 1);
        Notification::assertSentToTimes($this->fin, ContractRenewalDueNotification::class, 1); // FIN-06.04: Finance is a T-minus recipient
        Notification::assertNotSentTo($this->stranger, ContractRenewalDueNotification::class);
        $this->assertSame(3, DB::table('fin_notification_logs')->where('notification_key', 'renewal_due.end.2027-03-31')->where('offset_days', 30)->count());
        $this->assertSame(30, (int) $c->refresh()->last_notified_offset);

        // within the catch-up tolerance nothing repeats, and the next offset fires on its day
        Carbon::setTestNow('2027-03-03 09:00:00');
        $this->sweep();
        Notification::assertSentToTimes($this->owner, ContractRenewalDueNotification::class, 1);
        Carbon::setTestNow('2027-03-24 09:00:00');
        $this->sweep();
        Notification::assertSentToTimes($this->owner, ContractRenewalDueNotification::class, 2);
    }

    public function test_escalation_and_passed_notices_fire_once(): void
    {
        Notification::fake();
        $this->contract('2027-03-31', ['notification_offsets' => [7]]); // notice deadline 2027-03-01 + 0 -> 2027-03-01
        Carbon::setTestNow('2027-02-25 09:00:00'); // notice in 4 days
        $this->sweep();
        $this->sweep();
        Notification::assertSentToTimes($this->cio, ContractRenewalEscalationNotification::class, 1);

        Carbon::setTestNow('2027-03-05 09:00:00');
        $this->sweep();
        $this->sweep();
        Notification::assertSentToTimes($this->owner, ContractDeadlinePassedNotification::class, 1);
        Notification::assertSentToTimes($this->fin, ContractDeadlinePassedNotification::class, 1);
        Notification::assertNotSentTo($this->stranger, ContractDeadlinePassedNotification::class);
    }

    public function test_auto_renew_rolls_once_and_notifies_team_and_finance(): void
    {
        Notification::fake();
        $c = $this->contract('2027-02-28', ['renewal_type' => 'auto', 'auto_renew' => true]);
        $this->sweep();
        $this->sweep();
        $this->assertSame('renewed', $c->refresh()->status);
        $this->assertSame(1, Contract::where('renewed_from_id', $c->id)->count());
        Notification::assertSentToTimes($this->owner, ContractRenewedNotification::class, 1);
        Notification::assertSentToTimes($this->fin, ContractRenewedNotification::class, 1);
    }

    public function test_digest_sends_once_at_the_digest_hour_to_opted_in_users(): void
    {
        Notification::fake();
        $this->contract('2027-03-20');
        $this->owner->forceFill(['notification_preferences' => ['finance_daily_digest' => true]])->save();
        $hour = (int) app(FinanceSettings::class)->for($this->org->id)->digest_hour;
        $at = FinanceClock::today()->setTime($hour, 5);
        $this->assertSame(0, app(FinanceDigest::class)->send($at->setTime(($hour + 1) % 24, 0)));
        $this->assertSame(1, app(FinanceDigest::class)->send($at));
        $this->assertSame(0, app(FinanceDigest::class)->send($at));
        Notification::assertSentToTimes($this->owner, FinanceDailyDigestNotification::class, 1);
        Notification::assertNotSentTo($this->member, FinanceDailyDigestNotification::class);
    }

    public function test_calendar_feed_events_ics_and_passed(): void
    {
        $c = $this->contract('2027-03-31', ['renewal_type' => 'auto', 'auto_renew' => true]);
        $svc = app(FinanceCalendarService::class);
        $from = Carbon::parse('2026-01-01');
        $to = Carbon::parse('2027-12-31');
        $events = $svc->events($this->org->id, $from, $to, [], $this->fin);
        $types = collect($events)->where('meta.contract_code', $c->contract_code)->pluck('type')->all();
        $this->assertEqualsCanonicalizing(['contract_commencement', 'contract_window_opens', 'contract_notice_deadline', 'contract_end', 'contract_auto_renew'], array_values(array_unique($types)));
        $this->assertCount(0, $svc->events($this->org->id, $from, $to, ['vendor_id' => 999999], $this->fin));

        $ics = $svc->ics($this->fin);
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('VALARM', $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
        $this->assertStringNotContainsString('1000000', $ics);

        Carbon::setTestNow('2027-04-10 09:00:00');
        $c->forceFill(['status' => 'expired'])->save();
        $this->assertSame($c->id, $svc->passed($this->org->id, [], $this->fin)[0]['id']);
        $this->assertStringStartsWith('%PDF', $svc->pdf([], $svc->passed($this->org->id, [], $this->fin), 'T'));
    }

    public function test_schedules_are_registered(): void
    {
        $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();
        $this->assertContains('finance.contracts-sweep', $names);
        $this->assertContains('finance.digest', $names);
    }
}
