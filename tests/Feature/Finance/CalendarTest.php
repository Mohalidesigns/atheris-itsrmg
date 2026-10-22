<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\CalendarFeed;
use App\Models\Finance\Contract;
use App\Models\Finance\Period;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ContractService;
use App\Services\Finance\FinanceCalendarService;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RenewalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Finance\Concerns\BuildsContracts;
use Tests\TestCase;

/**
 * P2 unified calendar (FIN-06.06, FIN-07.05, FIN-07.10): events for every source, colours, ranges, filters, the Passed
 * list, the signed per-user .ics feed, CSV/PDF export and the empty state.
 */
class CalendarTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    private FinanceCalendarService $cal;

    private Contract $active;

    private Contract $window;

    private Contract $noticePassed;

    private Contract $expired;

    private Contract $closed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootContracts();
        Notification::fake();
        $this->cal = app(FinanceCalendarService::class);
        $this->openCycle(); // 2026-09-01 to 2026-11-30

        $other = Vendor::factory()->create(['organization_id' => $this->org->id, 'name' => 'Cisco Systems']);
        $this->active = $this->contract(['title' => 'Active', 'commenced_on' => '2026-10-01', 'ends_on' => '2027-09-30', 'renewal_type' => 'auto', 'auto_renew' => true]); // notice 2027-08-31
        $this->window = $this->contract(['title' => 'Window', 'vendor_id' => $other->id, 'cost_centre_id' => $this->cc2->id, 'type' => 'telecom', 'contract_value' => '90000000', 'commenced_on' => '2026-05-01', 'ends_on' => '2027-04-30']);
        $this->noticePassed = $this->contract(['title' => 'Notice passed', 'commenced_on' => '2026-03-21', 'ends_on' => '2027-03-20', 'notice_period_days' => 60]); // notice 2027-01-19
        $this->expired = $this->contract(['title' => 'Expired', 'commenced_on' => '2026-02-11', 'ends_on' => '2027-02-10']);
        $this->closed = $this->contract(['title' => 'Closed', 'commenced_on' => '2026-11-01', 'ends_on' => '2027-10-31']);
        $this->terminateViaWorkflow($this->closed);
    }

    private function events(array $filters = [], string $from = '2026-01-01', string $to = '2028-12-31', ?User $viewer = null): array
    {
        return $this->cal->events($this->org->id, Carbon::parse($from), Carbon::parse($to), $filters, $viewer ?? $this->fin);
    }

    private function titles(array $events): array
    {
        return collect($events)->pluck('meta.contract_code')->filter()->unique()->sort()->values()->all();
    }

    /** FIN-06.06: every contract milestone is an event on its date, including the auto-renewal day. */
    public function test_events_for_every_contract_milestone(): void
    {
        $mine = collect($this->events())->where('meta.contract_code', $this->active->contract_code);
        $this->assertSame([
            'contract_commencement' => '2026-10-01', 'contract_window_opens' => '2027-06-02', 'contract_notice_deadline' => '2027-08-31',
            'contract_end' => '2027-09-30', 'contract_auto_renew' => '2027-10-01',
        ], $mine->pluck('start', 'type')->all());
        $this->assertSame(['contracts'], $mine->pluck('meta.source')->unique()->values()->all());
        $this->assertStringContainsString('/it-finance/contracts/'.$this->active->id, $mine->first()['href']);
        $draft = app(ContractService::class)->create(['title' => 'A draft', 'type' => 'other', 'commenced_on' => '2027-06-01', 'ends_on' => '2027-12-31'], $this->procurement);
        $this->assertNotContains($draft->contract_code, $this->titles($this->events()), 'drafts are not on the calendar');

        // a date range only returns what falls inside it
        $this->assertSame(['contract_window_opens'], collect($this->events([], '2027-06-01', '2027-06-10'))->where('meta.contract_code', $this->active->contract_code)->pluck('type')->all());
    }

    /** FIN-07.05: budget cycles and fiscal periods share the calendar, closed periods included. */
    public function test_cycle_and_period_milestones(): void
    {
        $period = Period::where('is_adjustment', false)->orderBy('starts_on')->first();
        $period->forceFill(['status' => 'closed', 'closed_at' => Carbon::parse('2027-02-03 10:00:00')])->save();

        $events = collect($this->events());
        $this->assertSame('2026-09-01', $events->firstWhere('type', 'budget_cycle_opens')['start']);
        $this->assertSame('2026-11-30', $events->firstWhere('type', 'budget_cycle_closes')['start']);
        $this->assertSame('budget', $events->firstWhere('type', 'budget_cycle_opens')['meta']['source']);
        $ends = $events->where('type', 'period_end');
        $this->assertGreaterThanOrEqual(12, $ends->count());
        $this->assertSame('periods', $ends->first()['meta']['source']);
        $this->assertSame('2027-02-03', $events->firstWhere('type', 'period_closed')['start']);

        // source filter: periods only, then cycles only
        $this->assertEqualsCanonicalizing(['period_end', 'period_closed'], collect($this->events(['sources' => ['periods']]))->pluck('type')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['budget_cycle_opens', 'budget_cycle_closes'], collect($this->events(['sources' => ['budget']]))->pluck('type')->unique()->values()->all());
    }

    /** The colour of an event follows the contract's status: green, amber, red, grey, with milestones blue. */
    public function test_colours_by_status(): void
    {
        $color = fn (Contract $c, string $type = 'contract_end') => collect($this->events())->where('meta.contract_code', $c->contract_code)->firstWhere('type', $type)['color'];
        $this->assertSame('green', $color($this->active));
        $this->assertSame('amber', $color($this->window));
        $this->assertSame('red', $color($this->noticePassed));
        $this->assertSame('red', $color($this->expired));
        $this->assertSame('grey', $color($this->closed));
        $this->assertSame('blue', $color($this->active, 'contract_commencement'));
        $this->assertSame('blue', $color($this->active, 'contract_auto_renew'));
        $this->assertSame(['notice_deadline_passed', 'expired'], [$this->noticePassed->status, $this->expired->status]);
    }

    /** Month, quarter, year and timeline ranges, on the service and on the page. */
    public function test_month_quarter_year_and_timeline_ranges(): void
    {
        $d = Carbon::parse('2027-03-15');
        $r = fn (string $v) => array_map(fn ($x) => $x->toDateString(), $this->cal->range($v, $d));
        $this->assertSame(['2027-03-01', '2027-04-04'], $r('month'));   // the weeks that touch March, Monday to Sunday
        $this->assertSame(['2027-01-01', '2027-03-31'], $r('quarter'));
        $this->assertSame(['2027-01-01', '2027-12-31'], $r('year'));
        $this->assertSame(['2027-02-01', '2028-02-29'], $r('timeline'));

        foreach (['month', 'quarter', 'year', 'timeline'] as $view) {
            [$from, $to] = $r($view);
            $this->actingAs($this->fin)->get(route('finance.calendar', ['view' => $view, 'date' => '2027-03-15']))->assertOk()->assertInertia(fn (Assert $p) => $p
                ->component('Finance/Calendar')->where('view', $view)->where('from', $from)->where('to', $to)
                ->where('events', fn ($events) => collect($events)->every(fn ($e) => $e['start'] >= $from && $e['start'] <= $to))
                ->where('today', '2027-03-01')->has('options.vendors')->has('legend')->where('can.export', true)->has('feed.url'));
        }
        $this->actingAs($this->fin)->get(route('finance.calendar', ['view' => 'timeline', 'date' => '2027-03-15']))->assertInertia(fn (Assert $p) => $p
            ->has('timeline', 5)->where('timeline.0.contract_code', $this->expired->contract_code)); // overlapping the range, ordered by end date
        $this->actingAs($this->fin)->get(route('finance.calendar', ['view' => 'fortnight']))->assertSessionHasErrors('view');
    }

    /** Each filter narrows the contract events: vendor, owner, me, cost centre, type, value band and status. */
    public function test_each_filter(): void
    {
        $codes = fn (array $f, ?User $v = null) => $this->titles($this->events($f, '2026-01-01', '2028-12-31', $v));
        $all = $this->titles($this->events());
        $this->assertContains($this->active->contract_code, $all);

        $this->assertSame([$this->window->contract_code], $codes(['vendor_id' => $this->window->vendor_id]));
        $this->assertSame([$this->window->contract_code], $codes(['cost_centre_id' => $this->cc2->id]));
        $this->assertSame([$this->window->contract_code], $codes(['type' => 'telecom']));
        $this->assertSame([$this->window->contract_code], $codes(['value_band' => $this->window->amount_band]));
        $this->assertSame([$this->window->contract_code], $codes(['status' => 'in_renewal_window']));
        $this->assertSame([$this->active->contract_code], $codes(['status' => 'active']));
        $this->assertCount(5, $codes(['owner_id' => $this->owner->id]));
        $this->assertSame([], $codes(['owner_id' => $this->owner2->id]));

        // "me": owner, technical owner, procurement contact or team member
        $this->assertCount(5, $codes(['member' => 'me'], $this->procurement));
        $this->assertSame([], $codes(['member' => 'me'], $this->fin));
        app(ContractService::class)->addTeamMember($this->expired, $this->owner2->id, 'member', $this->fin);
        $this->assertSame([$this->expired->contract_code], $codes(['member' => 'me'], $this->owner2));

        // a contract-only filter hides cycles and periods, which it cannot apply to
        $this->assertSame(['contracts'], collect($this->events(['vendor_id' => $this->window->vendor_id]))->pluck('meta.source')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['contracts', 'budget', 'periods'], collect($this->events())->pluck('meta.source')->unique()->values()->all());
        $this->assertSame(['contracts'], collect($this->events(['sources' => ['contracts']]))->pluck('meta.source')->unique()->values()->all());

        // the same filters arrive from the query string
        $this->actingAs($this->fin)->get(route('finance.calendar', ['view' => 'year', 'date' => '2027-06-01', 'vendor_id' => $this->window->vendor_id]))->assertInertia(fn (Assert $p) => $p
            ->where('filters.vendor_id', $this->window->vendor_id)->where('events', fn ($e) => collect($e)->every(fn ($x) => $x['meta']['contract_code'] === $this->window->contract_code)));
    }

    /** FIN-06.06: Passed holds contracts past a deadline with no decision, longest overdue first, and clears once a decision is recorded. */
    public function test_passed_list_sorted_by_days_overdue_and_cleared_by_decision(): void
    {
        $passed = fn () => $this->cal->passed($this->org->id, [], $this->fin);
        $rows = $passed();
        $this->assertSame([$this->noticePassed->contract_code, $this->expired->contract_code], array_column($rows, 'contract_code'));
        $this->assertSame(['notice', 'end'], array_column($rows, 'which'));
        $this->assertSame([41, 19], array_column($rows, 'days_overdue'));
        $this->assertNotContains($this->closed->contract_code, array_column($rows, 'contract_code'), 'terminated contracts are not passed');
        $this->assertNotContains($this->window->contract_code, array_column($rows, 'contract_code'));

        $r = $this->submittedRenewal($this->expired);
        app(RenewalService::class)->approve($r, $this->cfo);
        $this->assertSame([$this->noticePassed->contract_code], array_column($passed(), 'contract_code'), 'a recorded decision clears the contract');
        $this->assertNull($this->cal->passed($this->org->id, [], $this->userWith('Viewer'))[0]['base_value'] ?? null, 'masked without the amounts permission');
        $this->assertSame([$this->noticePassed->contract_code], array_column($this->cal->passed($this->org->id, ['vendor_id' => $this->vendor->id], $this->fin), 'contract_code'));
    }

    /** Colours and the page agree: the Passed panel is in the page props. */
    public function test_page_props_carry_the_passed_panel(): void
    {
        $this->actingAs($this->fin)->get(route('finance.calendar'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('passed', 2)->where('passed.0.contract_code', $this->noticePassed->contract_code)->where('passed.0.href', route('finance.contracts.show', $this->noticePassed->id)));
    }

    /** FIN-06.06: the .ics feed is signed per user and version; tampering, rotation and deactivation all return 403. */
    public function test_ics_signing_rotation_and_inactive_user(): void
    {
        $url = $this->feedUrl($this->fin);
        Auth::forgetGuards();
        $this->get(route('finance.calendar.ics', ['u' => $this->fin->id, 'v' => 1]))->assertForbidden();                       // unsigned
        $this->get(preg_replace('/u=\d+/', 'u='.$this->procurement->id, $url))->assertForbidden();                              // tampered user
        $this->get(preg_replace('/v=\d+/', 'v=2', $url))->assertForbidden();                                                   // tampered version
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertNotNull(CalendarFeed::where('user_id', $this->fin->id)->value('last_used_at'));

        // rotating the link invalidates the old URL, and the page hands out the new one
        $this->actingAs($this->fin)->post(route('finance.calendar.feed.rotate'))->assertRedirect();
        $this->assertSame(2, (int) CalendarFeed::where('user_id', $this->fin->id)->value('version'));
        $fresh = $this->feedUrl($this->fin);
        $this->assertNotSame($url, $fresh);
        Auth::forgetGuards();
        $this->get($url)->assertForbidden();
        $this->get($fresh)->assertOk();

        // a deactivated user, and a user who lost the permission, are refused
        $this->fin->forceFill(['is_active' => false])->save();
        $this->get($fresh)->assertForbidden();
        $viewer = $this->userWith('Viewer');
        $this->actingAs($this->owner)->get(route('finance.calendar'))->assertOk();
        $this->get(URL::signedRoute('finance.calendar.ics', ['u' => $viewer->id, 'v' => 1]))->assertForbidden();
    }

    /** FIN-06.06, FIN-07.10: the feed body is valid iCalendar, carries only this bank's events and never an amount. */
    public function test_ics_body_is_valid_and_has_no_amounts_or_other_bank_events(): void
    {
        $other = $this->otherBank();
        app(FinanceSettings::class)->ensureDefaults($other->id);
        $theirs = $this->userWith('IT Finance Controller', [], $other->id);
        $this->actingAs($theirs);
        app(ContractService::class)->create(['title' => 'OTHERBANK-SECRET-CONTRACT', 'type' => 'other', 'owner_id' => $theirs->id, 'vendor_id' => Vendor::factory()->create(['organization_id' => $other->id])->id,
            'commenced_on' => '2026-06-01', 'ends_on' => '2027-06-30', 'contract_value' => '98765432', 'activate' => true], $theirs);
        $this->actingAs($this->fin);
        $this->contract(['title' => 'A very long contract title that has to be folded because it goes well beyond the seventy-five octet limit of RFC 5545 — naïve café', 'contract_value' => '12345678']);

        $body = $this->feedBody($this->fin);
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $body);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $body);
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $body), 'every line break is CRLF');
        $lines = explode("\r\n", rtrim($body, "\r\n"));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "line over 75 octets: {$line}");
        }
        $this->assertSame(substr_count($body, 'BEGIN:VEVENT'), substr_count($body, 'END:VEVENT'));
        $this->assertSame(1, substr_count($body, 'BEGIN:VCALENDAR'));
        $this->assertGreaterThan(10, substr_count($body, 'BEGIN:VEVENT'));
        $unfolded = preg_replace("/\r\n[ \t]/", '', $body);
        foreach (explode('BEGIN:VEVENT', $unfolded) as $i => $event) {
            if ($i === 0) {
                continue;
            }
            foreach (['UID:', 'DTSTAMP:', 'DTSTART;VALUE=DATE:', 'SUMMARY:'] as $prop) {
                $this->assertStringContainsString($prop, $event, 'every VEVENT carries '.$prop);
            }
        }
        $this->assertSame(substr_count($body, 'BEGIN:VALARM'), substr_count($body, 'END:VALARM'));
        $this->assertStringNotContainsString('OTHERBANK', $unfolded);
        foreach (['12000000', '12345678', '90000000', '98765432'] as $amount) {
            $this->assertStringNotContainsString($amount, $unfolded, 'no amounts in the feed');
        }
        $this->assertStringContainsString('Notice deadline', $unfolded);
    }

    /** The feed only shows what the signed user can see: a budget owner gets their cost centre and contracts, not the rest. */
    public function test_ics_respects_the_users_visibility(): void
    {
        $body = $this->feedBody($this->owner2); // owns the second cost centre only
        $this->assertStringContainsString($this->window->contract_code, $body);
        $this->assertStringNotContainsString($this->active->contract_code, $body);
    }

    /** CSV and PDF export work, XLSX is refused with an error on `format`, and exporting needs the export permission. */
    public function test_csv_and_pdf_export_and_xlsx_error(): void
    {
        $query = ['view' => 'year', 'date' => '2027-06-01'];
        $csv = $this->actingAs($this->fin)->get(route('finance.calendar.export', $query + ['format' => 'csv']));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('attachment; filename="it-finance-calendar.csv"', $csv->headers->get('Content-Disposition'));
        $body = $csv->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFDate,Type,Title,Status,Contract,Vendor,Owner", $body);
        $this->assertStringContainsString($this->active->contract_code, $body);
        $this->assertGreaterThan(5, substr_count($body, "\n"));

        $pdf = $this->actingAs($this->fin)->get(route('finance.calendar.export', $query + ['format' => 'pdf']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->fin)->get(route('finance.calendar.export', $query + ['format' => 'xlsx']))->assertSessionHasErrors('format');
        $this->actingAs($this->userWith('Viewer'))->get(route('finance.calendar.export', $query + ['format' => 'csv']))->assertForbidden();
    }

    /** A bank with no data renders an empty calendar, not an error. */
    public function test_a_bank_with_no_data_renders_an_empty_calendar(): void
    {
        $other = $this->otherBank();
        $empty = $this->userWith('IT Finance Controller', [], $other->id);
        foreach (['month', 'quarter', 'year', 'timeline'] as $view) {
            $this->actingAs($empty)->get(route('finance.calendar', ['view' => $view]))->assertOk()->assertInertia(fn (Assert $p) => $p
                ->has('events', 0)->has('passed', 0)->has('timeline', 0)->has('options.vendors', 0));
        }
        $this->get(route('finance.calendar.export', ['format' => 'csv']))->assertOk();
        Auth::forgetGuards();
        $this->get(route('finance.calendar'))->assertRedirect(route('login'));
    }

    private function feedUrl(User $u): string
    {
        return $this->actingAs($u)->get(route('finance.calendar'))->assertOk()->viewData('page')['props']['feed']['url'];
    }

    private function feedBody(User $u): string
    {
        $url = $this->feedUrl($u);
        Auth::forgetGuards();
        $res = $this->get($url)->assertOk();
        $this->actingAs($this->fin);

        return $res->getContent();
    }
}
