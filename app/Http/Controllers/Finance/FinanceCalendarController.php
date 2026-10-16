<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsContracts;
use App\Http\Requests\Finance\CalendarRequest;
use App\Models\Finance\CalendarFeed;
use App\Models\Finance\Contract;
use App\Models\User;
use App\Services\Finance\FinanceCalendarService;
use App\Services\Finance\FinanceClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/** The unified IT finance calendar (FIN-06.06, FIN-07.05): page, CSV/PDF export and the signed per-user .ics feed. */
class FinanceCalendarController extends Controller
{
    use PresentsContracts;

    public function __construct(private readonly FinanceCalendarService $calendar) {}

    public function index(CalendarRequest $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Contract::class);
        $orgId = (int) $user->organization_id;
        $view = $request->validated('view') ?? 'month';
        $anchor = $this->anchor($request);
        [$from, $to] = $this->calendar->range($view, $anchor);
        $filters = $this->filters($request);
        $can = $user->can('view it-finance-amounts');
        $options = $this->calendar->options($orgId, $request->user());
        $feed = CalendarFeed::for($user);
        $query = ['view' => $view, 'date' => $anchor->toDateString()] + array_filter($filters, fn ($v) => $v !== null && $v !== []);

        $props = [
            'view' => $view, 'anchor' => $anchor->toDateString(), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'today' => FinanceClock::today()->toDateString(),
            'events' => $this->calendar->events($orgId, $from, $to, $filters, $user),
            'timeline' => $view === 'timeline' ? $this->calendar->timeline($orgId, $from, $to, $filters, $user) : [],
            'passed' => $this->calendar->passed($orgId, $filters, $user),
            'filters' => $filters, 'options' => $options, 'legend' => $options['legend'] ?? [],
            'feed' => ['url' => URL::signedRoute('finance.calendar.ics', ['u' => $user->id, 'v' => $feed->version]), 'rotate_url' => route('finance.calendar.feed.rotate')],
            'exportUrls' => ['csv' => route('finance.calendar.export', $query + ['format' => 'csv']), 'pdf' => route('finance.calendar.export', $query + ['format' => 'pdf'])],
            'can' => ['export' => $user->can('export it-finance'), 'viewAmounts' => $can],
        ];

        return $this->renderContracts('Finance/Calendar', $props);
    }

    public function export(CalendarRequest $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Contract::class);
        $format = strtolower((string) $request->validated('format', 'csv'));
        if (! in_array($format, ['csv', 'pdf'], true)) {
            return back()->withErrors(['format' => 'Only CSV and PDF exports are available. Use CSV to open the data in a spreadsheet.']);
        }
        $orgId = (int) $user->organization_id;
        [$from, $to] = $this->calendar->range($request->validated('view') ?? 'month', $this->anchor($request));
        $filters = $this->filters($request);
        $events = $this->calendar->events($orgId, $from, $to, $filters, $user);

        if ($format === 'csv') {
            return response($this->calendar->csv($events), 200, [
                'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="it-finance-calendar.csv"',
            ]);
        }
        $pdf = $this->calendar->pdf($events, $this->calendar->passed($orgId, $filters, $user), 'IT Finance Calendar '.$from->toDateString().' to '.$to->toDateString());

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="it-finance-calendar.pdf"']);
    }

    /** Unauthenticated, signed feed: the signed user (and nothing else) decides the tenant and what is visible. */
    public function ics(Request $request)
    {
        $userId = (int) $request->query('u');
        $version = (int) $request->query('v');
        abort_unless($userId > 0 && $version > 0, 403);
        $user = User::withoutGlobalScopes()->find($userId);
        abort_unless($user && $user->is_active && $user->organization_id, 403);
        $feed = CalendarFeed::withoutGlobalScopes()->where('user_id', $user->id)->first();
        abort_unless($feed && (int) $feed->version === $version && (int) $feed->organization_id === (int) $user->organization_id, 403);
        abort_unless($user->can('view it-finance'), 403);
        $feed->forceFill(['last_used_at' => now()])->save();

        return response($this->calendar->ics($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="it-finance.ics"', 'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function rotateFeed(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Contract::class);
        $feed = CalendarFeed::for($user);
        $feed->forceFill(['version' => $feed->version + 1])->save();

        return back()->with('success', 'Calendar link reset. Subscribe again with the new link; the old one no longer works.');
    }

    private function anchor(CalendarRequest $request): CarbonImmutable
    {
        $date = $request->validated('date');

        return $date ? CarbonImmutable::parse($date, config('finance.timezone'))->startOfDay() : FinanceClock::today();
    }

    /** @return array<string,mixed> */
    private function filters(CalendarRequest $request): array
    {
        $v = $request->validated();

        return [
            'vendor_id' => isset($v['vendor_id']) ? (int) $v['vendor_id'] : null, 'owner_id' => isset($v['owner_id']) ? (int) $v['owner_id'] : null,
            'member' => $v['member'] ?? null, 'cost_centre_id' => isset($v['cost_centre_id']) ? (int) $v['cost_centre_id'] : null,
            'type' => $v['type'] ?? null, 'value_band' => $v['value_band'] ?? null, 'status' => $v['status'] ?? null, 'sources' => $v['sources'] ?? [],
        ];
    }
}
