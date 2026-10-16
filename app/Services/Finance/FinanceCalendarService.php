<?php

namespace App\Services\Finance;

use App\Models\Finance\BudgetCycle;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Period;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Approvals\ApprovalProcesses;
use App\Services\Ea\PdfWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The unified IT finance calendar (FIN-06.06, FIN-07.05): one event stream merged from a registry of sources.
 * P2 ships contracts, budget cycles and periods; later phases register invoices, gates and value reviews (plan C54).
 */
class FinanceCalendarService
{
    public const SOURCES = ['contracts' => 'Contracts and renewals', 'budget' => 'Budget cycles', 'periods' => 'Fiscal periods'];

    private const EVENT_TYPES = [
        'contract_commencement' => ['Contract commences', 'blue'],
        'contract_window_opens' => ['Renewal window opens', 'amber'],
        'contract_notice_deadline' => ['Notice deadline', 'red'],
        'contract_end' => ['Contract ends', 'red'],
        'contract_auto_renew' => ['Auto-renewal', 'blue'],
        'budget_cycle_opens' => ['Budget cycle opens', 'blue'],
        'budget_cycle_closes' => ['Budget cycle closes', 'blue'],
        'period_end' => ['Period ends', 'blue'],
        'period_closed' => ['Period closed', 'blue'],
        'period_locked' => ['Period locked', 'blue'],
    ];

    private const LEGEND = [
        ['color' => 'green', 'label' => 'Active contract'], ['color' => 'amber', 'label' => 'In renewal window'],
        ['color' => 'red', 'label' => 'Notice deadline passed or lapsed'], ['color' => 'grey', 'label' => 'Decided, renewed or closed'],
        ['color' => 'blue', 'label' => 'Milestone, cycle or period'],
    ];

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    public function range(string $view, CarbonInterface $anchor): array
    {
        $a = CarbonImmutable::parse($anchor->toDateString());

        return match ($view) {
            'quarter' => [$a->firstOfQuarter(), $a->lastOfQuarter()],
            'year' => [$a->startOfYear(), $a->endOfYear()->startOfDay()],
            'timeline' => [$a->startOfMonth()->subMonth(), $a->startOfMonth()->addMonths(11)->endOfMonth()->startOfDay()],
            default => [$a->startOfMonth()->startOfWeek(CarbonInterface::MONDAY), $a->endOfMonth()->startOfDay()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()],
        };
    }

    /** @return array<int,array<string,mixed>> */
    public function events(int $orgId, CarbonInterface $from, CarbonInterface $to, array $filters, User $viewer): array
    {
        $filters = $this->blind($filters, $viewer);
        $sources = $this->sources($filters);
        $events = collect();
        foreach ($sources as $source) {
            $events = $events->merge($this->{'source'.ucfirst($source)}($orgId, $from, $to, $filters, $viewer));
        }

        return $events->sortBy(fn ($e) => $e['start'].'|'.$e['id'])->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function timeline(int $orgId, CarbonInterface $from, CarbonInterface $to, array $filters, User $viewer): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $filters = $this->blind($filters, $viewer);

        return $this->contractQuery($orgId, $filters, $viewer)->whereNot('status', 'draft')
            ->where('commenced_on', '<=', $t)->where('ends_on', '>=', $f)->with('vendor:id,name')->orderBy('ends_on')->get()
            ->filter(fn (Contract $c) => $this->bandMatches($c, $filters))
            ->map(fn (Contract $c) => [
                'id' => $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title, 'vendor' => $c->vendor?->name,
                'start' => $c->commenced_on->toDateString(), 'end' => $c->ends_on->toDateString(),
                'window_opens_on' => $c->renewal_window_opens_on->toDateString(), 'notice_deadline_on' => $c->notice_deadline_on->toDateString(),
                'status' => $c->status, 'color' => $this->color($c), 'href' => route('finance.contracts.show', $c->id),
            ])->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function passed(int $orgId, array $filters, User $viewer, ?CarbonInterface $today = null): array
    {
        $today ??= FinanceClock::today();
        $canAmounts = $viewer->can('view it-finance-amounts');
        $filters = $this->blind($filters, $viewer);

        return $this->contractQuery($orgId, $filters, $viewer)->passed($today)->with(['vendor:id,name', 'owner:id,name'])->get()
            ->filter(fn (Contract $c) => $this->bandMatches($c, $filters))
            ->map(function (Contract $c) use ($today, $canAmounts) {
                $endPassed = $c->ends_on->toDateString() < $today->toDateString();
                $date = $endPassed ? $c->ends_on : $c->notice_deadline_on;

                return [
                    'id' => $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title, 'vendor' => $c->vendor?->name, 'owner' => $c->owner?->name,
                    'which' => $endPassed ? 'end' : 'notice', 'date' => $date->toDateString(),
                    'days_overdue' => abs((int) FinanceClock::daysUntil($date, $today)), 'status' => $c->status,
                    'base_value' => $canAmounts ? (string) $c->base_contract_value : null, 'auto_renew' => $c->is_auto, 'href' => route('finance.contracts.show', $c->id),
                ];
            })->sortByDesc('days_overdue')->values()->all();
    }

    /** @return array<string,mixed> */
    public function options(int $orgId, ?User $viewer = null): array
    {
        $canAmounts = $viewer?->can('view it-finance-amounts') ?? false;
        $opt = fn (array $v, array $labels = []) => collect($v)->map(fn ($x) => ['value' => $x, 'label' => $labels[$x] ?? ucfirst(str_replace('_', ' ', $x))])->values()->all();

        return [
            'vendors' => Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotNull('vendor_id')->select('vendor_id'))
                ->orderBy('name')->get(['id', 'name'])->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->all(),
            'owners' => User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'job_title'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title])->all(),
            'costCentres' => CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->all(),
            'types' => $opt(Contract::TYPES, ApprovalProcesses::CONTRACT_TYPE_LABELS),
            'valueBands' => $canAmounts ? collect(app(DoaBandService::class)->active($orgId)->bands)->map(fn ($b) => ['key' => $b['key'], 'label' => $b['label'] ?? $b['key']])->values()->all() : [],
            'statuses' => $opt(Contract::STATUSES, ['in_renewal_window' => 'In renewal window', 'notice_deadline_passed' => 'Notice deadline passed']),
            'sources' => collect(self::SOURCES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'eventTypes' => collect(self::EVENT_TYPES)->map(fn ($m, $type) => ['type' => $type, 'label' => $m[0], 'color' => $m[1]])->values()->all(),
            'legend' => self::LEGEND,
        ];
    }

    /** Subscribable feed of the viewer's visible events: today - ics_past_days to + ics_future_days. Never carries amounts. */
    public function ics(User $user): string
    {
        $cfg = config('finance.contracts.calendar');
        $today = FinanceClock::today();
        $orgId = (int) $user->organization_id;
        $events = $this->events($orgId, $today->subDays((int) $cfg['ics_past_days']), $today->addDays((int) $cfg['ics_future_days']), [], $user);
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'atheris.local';
        $stamp = gmdate('Ymd\THis\Z');
        $org = Organization::withoutGlobalScopes()->whereKey($orgId)->value('name') ?? 'Atheris';

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Atheris//IT Finance//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.$this->esc('IT Finance – '.$org)];
        foreach ($events as $e) {
            $meta = $e['meta'];
            $desc = collect([$meta['contract_code'] ?? null, $meta['vendor'] ?? null, isset($meta['owner']) ? 'Owner '.$meta['owner'] : null])->filter()->implode(' · ');
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$e['id'].'@'.$host;
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = 'DTSTART;VALUE=DATE:'.str_replace('-', '', $e['start']);
            $lines[] = 'DTEND;VALUE=DATE:'.CarbonImmutable::parse($e['end'])->addDay()->format('Ymd');
            $lines[] = 'SUMMARY:'.$this->esc($e['title']);
            $lines[] = 'DESCRIPTION:'.$this->esc($desc);
            $lines[] = 'URL:'.$e['href'];
            $lines[] = 'CATEGORIES:'.$this->esc(self::EVENT_TYPES[$e['type']][0] ?? $e['type']);
            $lines[] = 'STATUS:CONFIRMED';
            if ($e['type'] === 'contract_notice_deadline') {
                array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->esc($e['title']), 'TRIGGER:-P1D', 'END:VALARM');
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn ($l) => $this->fold($l), $lines))."\r\n";
    }

    /** @param array<int,array<string,mixed>> $events */
    public function csv(array $events): string
    {
        $h = fopen('php://temp', 'r+');
        $cell = fn ($v) => ($s = (string) ($v ?? '')) !== '' && ! is_numeric($s) && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
        fputcsv($h, ['Date', 'Type', 'Title', 'Status', 'Contract', 'Vendor', 'Owner', 'Cost centre', 'Contract type', 'Value band', 'Base value', 'Days left', 'Auto-renew', 'Source'], ',', '"', '\\');
        foreach ($events as $e) {
            $m = $e['meta'];
            fputcsv($h, array_map($cell, [$e['start'], self::EVENT_TYPES[$e['type']][0] ?? $e['type'], $e['title'], $e['status'], $m['contract_code'] ?? '', $m['vendor'] ?? '', $m['owner'] ?? '',
                $m['cost_centre'] ?? '', $m['contract_type'] ?? '', $m['value_band'] ?? '', $m['base_value'] ?? '', $m['days_left'] ?? '', ! empty($m['auto_renew']) ? 'yes' : '', $m['source'] ?? '']), ',', '"', '\\');
        }
        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);

        return "\xEF\xBB\xBF".$csv;
    }

    /** Landscape table of the events and the Passed list. */
    public function pdf(array $events, array $passed, string $title): string
    {
        $pdf = new PdfWriter;
        $pdf->setPageSize(842, 595);
        $pdf->newPage();
        $pdf->heading($title, 16);
        $pdf->space(4);
        if ($passed) {
            $pdf->paragraph('Passed, no decision recorded ('.count($passed).')', 11);
            $pdf->table(['Contract', 'Title', 'Vendor', 'Owner', 'Passed', 'Date', 'Days overdue'], array_map(fn ($p) => [
                $p['contract_code'], $p['title'], $p['vendor'] ?? '', $p['owner'] ?? '', $p['which'] === 'end' ? 'End date' : 'Notice', $p['date'], $p['days_overdue'].($p['auto_renew'] ? ' (auto-renews)' : ''),
            ], $passed), 8);
            $pdf->space(6);
        }
        $pdf->paragraph('Events ('.count($events).')', 11);
        $pdf->table(['Date', 'Event', 'Title', 'Status', 'Vendor', 'Owner', 'Days left'], array_map(fn ($e) => [
            $e['start'], self::EVENT_TYPES[$e['type']][0] ?? $e['type'], $e['title'], $e['status'], $e['meta']['vendor'] ?? '', $e['meta']['owner'] ?? '', $e['meta']['days_left'] ?? '',
        ], $events), 8);

        return $pdf->render();
    }

    // ---- sources -------------------------------------------------------------------------------------------------

    /** Without `view it-finance-amounts` the value-band filter is ignored: filtering by band would reveal the amount. */
    private function blind(array $filters, User $viewer): array
    {
        $viewer->can('view it-finance-amounts') || $filters['value_band'] = null;

        return $filters;
    }

    /** Contract-only filters hide the budget and period sources, which they cannot apply to. */
    private function sources(array $filters): array
    {
        $wanted = array_values(array_intersect(array_keys(self::SOURCES), (array) ($filters['sources'] ?? []))) ?: array_keys(self::SOURCES);
        $contractOnly = array_filter(Arr::only($filters, ['vendor_id', 'owner_id', 'member', 'cost_centre_id', 'type', 'value_band', 'status']), fn ($v) => $v !== null && $v !== '');

        return $contractOnly ? array_values(array_intersect($wanted, ['contracts'])) : $wanted;
    }

    private function sourceContracts(int $orgId, CarbonInterface $from, CarbonInterface $to, array $filters, User $viewer): Collection
    {
        $canAmounts = $viewer->can('view it-finance-amounts');
        $f = $from->toDateString();
        $t = $to->toDateString();
        $out = collect();
        $contracts = $this->contractQuery($orgId, $filters, $viewer)->whereNot('status', 'draft')->with(['vendor:id,name', 'owner:id,name', 'costCentre:id,code,name'])
            ->where(fn ($w) => $w->whereBetween('commenced_on', [$f, $t])->orWhereBetween('renewal_window_opens_on', [$f, $t])
                ->orWhereBetween('notice_deadline_on', [$f, $t])->orWhereBetween('ends_on', [$f, $t])
                ->orWhereBetween('ends_on', [CarbonImmutable::parse($f)->subDay()->toDateString(), CarbonImmutable::parse($t)->subDay()->toDateString()]))->get();

        foreach ($contracts->filter(fn (Contract $c) => $this->bandMatches($c, $filters)) as $c) {
            $dates = [
                'contract_commencement' => $c->commenced_on, 'contract_window_opens' => $c->renewal_window_opens_on,
                'contract_notice_deadline' => $c->notice_deadline_on, 'contract_end' => $c->ends_on,
            ];
            if ($c->is_auto) {
                $dates['contract_auto_renew'] = $c->ends_on->addDay();
            }
            foreach ($dates as $type => $date) {
                $d = $date->toDateString();
                if ($d < $f || $d > $t) {
                    continue;
                }
                $color = $type === 'contract_commencement' || $type === 'contract_auto_renew' ? 'blue' : $this->color($c);
                $out->push([
                    'id' => "contract-{$c->id}-{$type}", 'type' => $type, 'title' => $c->contract_code.' · '.$this->label($type).' · '.$c->title,
                    'start' => $d, 'end' => $d, 'color' => $color, 'status' => $c->status, 'href' => route('finance.contracts.show', $c->id),
                    'meta' => [
                        'source' => 'contracts', 'contract_code' => $c->contract_code, 'vendor' => $c->vendor?->name, 'owner' => $c->owner?->name,
                        'cost_centre' => $c->costCentre?->code, 'contract_type' => $c->type, 'value_band' => $canAmounts ? $c->amount_band : null,
                        'base_value' => $canAmounts ? (string) $c->base_contract_value : null,
                        'days_left' => FinanceClock::daysUntil($date), 'auto_renew' => $c->is_auto,
                    ],
                ]);
            }
        }

        return $out;
    }

    private function sourceBudget(int $orgId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $out = collect();
        $cycles = BudgetCycle::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereBetween('opens_on', [$f, $t])->orWhereBetween('closes_on', [$f, $t]))->get();
        foreach ($cycles as $c) {
            foreach (['budget_cycle_opens' => $c->opens_on, 'budget_cycle_closes' => $c->closes_on] as $type => $date) {
                $d = $date->toDateString();
                if ($d >= $f && $d <= $t) {
                    $out->push($this->milestone("cycle-{$c->id}-{$type}", $type, $c->name, $d, $c->status, route('finance.cycles.index'), 'budget', $c->cycle_code));
                }
            }
        }

        return $out;
    }

    private function sourcePeriods(int $orgId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $out = collect();
        $periods = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false)->get();
        foreach ($periods as $p) {
            foreach (['period_end' => $p->ends_on, 'period_closed' => $p->closed_at?->timezone(config('finance.timezone')), 'period_locked' => $p->locked_at?->timezone(config('finance.timezone'))] as $type => $date) {
                if (! $date) {
                    continue;
                }
                $d = $date->format('Y-m-d');
                if ($d >= $f && $d <= $t) {
                    $out->push($this->milestone("period-{$p->id}-{$type}", $type, $p->name, $d, $p->status, route('finance.settings'), 'periods', null));
                }
            }
        }

        return $out;
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    private function milestone(string $id, string $type, string $name, string $date, string $status, string $href, string $source, ?string $code): array
    {
        return [
            'id' => $id, 'type' => $type, 'title' => $this->label($type).' · '.$name, 'start' => $date, 'end' => $date, 'color' => 'blue',
            'status' => $status, 'href' => $href,
            'meta' => ['source' => $source, 'contract_code' => $code, 'days_left' => FinanceClock::daysUntil($date)],
        ];
    }

    private function label(string $type): string
    {
        return self::EVENT_TYPES[$type][0] ?? $type;
    }

    /** Colour by contract status (plan §4.5). */
    private function color(Contract $c): string
    {
        return match (true) {
            $c->status === 'active' => 'green',
            $c->status === 'in_renewal_window' => 'amber',
            $c->status === 'notice_deadline_passed' => 'red',
            $c->status === 'expired' && $c->decision === null => 'red',
            default => 'grey',
        };
    }

    /** Organisation, visibility and the contract filters, with global scopes off (the .ics route has no session). */
    private function contractQuery(int $orgId, array $filters, User $viewer): Builder
    {
        $q = Contract::withoutGlobalScopes()->where('fin_contracts.organization_id', $orgId)->whereNull('fin_contracts.deleted_at')->visibleTo($viewer);
        foreach (['vendor_id', 'owner_id', 'cost_centre_id', 'type', 'status'] as $k) {
            if (isset($filters[$k]) && $filters[$k] !== '') {
                $q->where("fin_contracts.{$k}", $filters[$k]);
            }
        }
        if (($filters['member'] ?? null) === 'me') {
            $id = $viewer->id;
            $q->where(fn ($w) => $w->where('fin_contracts.owner_id', $id)->orWhere('fin_contracts.technical_owner_id', $id)->orWhere('fin_contracts.procurement_contact_id', $id)
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('fin_contract_team')->whereColumn('fin_contract_team.contract_id', 'fin_contracts.id')->where('fin_contract_team.user_id', $id)));
        }

        return $q;
    }

    private function bandMatches(Contract $c, array $filters): bool
    {
        return empty($filters['value_band']) || $c->amount_band === $filters['value_band'];
    }

    private function esc(string $s): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
    }

    /** RFC 5545 line folding at 75 octets, never splitting a multibyte character. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $cur = '';
        $limit = 75;
        foreach (mb_str_split($line) as $ch) {
            if (strlen($cur) + strlen($ch) > $limit) {
                $out .= $cur."\r\n";
                $cur = ' ';
                $limit = 75;
            }
            $cur .= $ch;
        }

        return $out.$cur;
    }
}
