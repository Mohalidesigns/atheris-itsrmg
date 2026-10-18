<?php

namespace App\Services\Finance;

use App\Models\Finance\BudgetCycle;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Investment;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\Renewal;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
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
    public const SOURCES = ['contracts' => 'Contracts and renewals', 'budget' => 'Budget cycles', 'periods' => 'Fiscal periods', 'invoices' => 'Invoice due dates', 'investments' => 'Investments and value reviews'];

    private const EVENT_TYPES = [
        'contract_commencement' => ['Contract commences', 'blue'],
        'contract_window_opens' => ['Renewal window opens', 'amber'],
        'contract_notice_deadline' => ['Notice deadline', 'red'],
        'contract_end' => ['Contract ends', 'red'],
        'contract_end_unexecuted' => ['Contract ends: approved renewal not executed', 'red'],
        'contract_auto_renew' => ['Auto-renewal', 'blue'],
        'budget_cycle_opens' => ['Budget cycle opens', 'blue'],
        'budget_cycle_closes' => ['Budget cycle closes', 'blue'],
        'period_end' => ['Period ends', 'blue'],
        'period_closed' => ['Period closed', 'blue'],
        'period_locked' => ['Period locked', 'blue'],
        'invoice_due' => ['Invoice due', 'blue'],
        'investment_start' => ['Investment starts', 'blue'],
        'investment_planned_end' => ['Investment planned end', 'amber'],
        'investment_delivered' => ['Investment delivered', 'blue'],
        'investment_gate_passed' => ['Stage gate passed', 'blue'],
        'investment_gate_pending' => ['Stage gate awaiting approval', 'amber'],
        'value_review_due' => ['Value review due', 'amber'],
    ];

    private const LEGEND = [
        ['color' => 'green', 'label' => 'Active contract'], ['color' => 'amber', 'label' => 'In renewal window'],
        ['color' => 'red', 'label' => 'Notice deadline passed or lapsed'], ['color' => 'grey', 'label' => 'Decided, renewed or closed'],
        ['color' => 'red', 'label' => 'Approved renewal not yet executed'], ['color' => 'blue', 'label' => 'Milestone, cycle or period'],
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

        $rows = $this->contractQuery($orgId, $filters, $viewer)->whereNot('status', 'draft')
            ->where('commenced_on', '<=', $t)->where('ends_on', '>=', $f)->with('vendor:id,name')->orderBy('ends_on')->get();
        $unexecuted = $this->unexecutedIds($rows->pluck('id')->all());

        return $rows
            ->filter(fn (Contract $c) => $this->bandMatches($c, $filters))
            ->map(fn (Contract $c) => [
                'id' => $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title, 'vendor' => $c->vendor?->name,
                'start' => $c->commenced_on->toDateString(), 'end' => $c->ends_on->toDateString(),
                'window_opens_on' => $c->renewal_window_opens_on->toDateString(), 'notice_deadline_on' => $c->notice_deadline_on->toDateString(),
                'status' => in_array($c->id, $unexecuted, true) ? 'approved_unexecuted' : $c->status,
                'color' => in_array($c->id, $unexecuted, true) ? 'red' : $this->color($c), 'approved_unexecuted' => in_array($c->id, $unexecuted, true),
                'href' => route('finance.contracts.show', $c->id),
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

        if ($contractOnly && array_keys($contractOnly) === ['member']) { // "mine" also applies to investments and value reviews
            return array_values(array_intersect($wanted, ['contracts', 'investments']));
        }

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

        $unexecuted = $this->unexecutedIds($contracts->pluck('id')->all());
        foreach ($contracts->filter(fn (Contract $c) => $this->bandMatches($c, $filters)) as $c) {
            $pending = in_array($c->id, $unexecuted, true);
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
                $status = $c->status;
                if ($pending) { // an approved renewal that was never executed stays red until it is (plan M1)
                    $color = $type === 'contract_commencement' ? $color : 'red';
                    $type === 'contract_end' && $status = 'approved_unexecuted';
                    $type === 'contract_end' && $type = 'contract_end_unexecuted';
                }
                $out->push([
                    'id' => "contract-{$c->id}-{$type}", 'type' => $type, 'title' => $c->contract_code.' · '.$this->label($type).' · '.$c->title,
                    'start' => $d, 'end' => $d, 'color' => $color, 'status' => $status, 'href' => route('finance.contracts.show', $c->id),
                    'meta' => [
                        'source' => 'contracts', 'contract_code' => $c->contract_code, 'vendor' => $c->vendor?->name, 'owner' => $c->owner?->name,
                        'cost_centre' => $c->costCentre?->code, 'contract_type' => $c->type, 'value_band' => $canAmounts ? $c->amount_band : null,
                        'base_value' => $canAmounts ? (string) $c->base_contract_value : null,
                        'days_left' => FinanceClock::daysUntil($date), 'auto_renew' => $c->is_auto, 'approved_unexecuted' => $pending,
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

    private function sourceInvoices(int $orgId, CarbonInterface $from, CarbonInterface $to, array $filters, User $viewer): Collection
    {
        $canAmounts = $viewer->can('view it-finance-amounts');
        $today = FinanceClock::today();
        $invoices = Invoice::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereNot('status', 'draft')
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])->visibleTo($viewer)->with(['vendor:id,name', 'costCentre:id,code'])->get();

        return $invoices->map(function (Invoice $i) use ($today, $canAmounts) {
            $left = FinanceClock::daysUntil($i->due_on, $today);
            $closed = in_array($i->status, ['paid', 'cancelled'], true);
            $color = $closed ? 'grey' : ($left < 0 ? 'red' : ($left <= 7 ? 'amber' : 'blue'));

            return [
                'id' => "invoice-{$i->id}-invoice_due", 'type' => 'invoice_due', 'title' => "{$i->invoice_code} ".($i->vendor?->name ?? '').' due',
                'start' => $i->due_on->toDateString(), 'end' => $i->due_on->toDateString(), 'color' => $color, 'status' => $i->status,
                'href' => route('finance.invoices.show', $i->id),
                'meta' => [
                    'source' => 'invoices', 'invoice_code' => $i->invoice_code, 'vendor' => $i->vendor?->name, 'cost_centre' => $i->costCentre?->code,
                    'days_left' => $left, 'base_value' => $canAmounts ? (string) $i->base_total_amount : null,
                ],
            ];
        })->values();
    }

    private function sourceInvestments(int $orgId, CarbonInterface $from, CarbonInterface $to, array $filters, User $viewer): Collection
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $today = FinanceClock::today()->toDateString();
        $mine = ($filters['member'] ?? null) === 'me' ? (int) $viewer->id : null;
        $out = collect();
        $in = fn (?string $d) => $d !== null && $d >= $f && $d <= $t;
        $push = function (Investment $i, string $type, string $date, string $color, string $status, string $suffix = '', ?string $title = null) use ($out, $today) {
            $out->push([
                'id' => "investment-{$i->id}-{$type}{$suffix}", 'type' => $type, 'title' => $i->investment_code.' · '.self::EVENT_TYPES[$type][0].' · '.($title ?? $i->title),
                'start' => $date, 'end' => $date, 'color' => $color, 'status' => $status, 'href' => route('finance.investments.show', $i->id),
                'meta' => ['source' => 'investments', 'contract_code' => $i->investment_code, 'owner' => $i->manager?->name, 'days_left' => FinanceClock::daysUntil($date, CarbonImmutable::parse($today))],
            ]);
        };

        $invs = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereNot('stage', 'cancelled')->visibleTo($viewer)
            ->when($mine, fn ($q) => $q->where(fn ($w) => $w->where('manager_id', $mine)->orWhere('sponsor_id', $mine)
                ->orWhereIn('id', ValueReview::withoutGlobalScopes()->where('reviewer_id', $mine)->select('investment_id'))))
            ->with('manager:id,name')->get();
        foreach ($invs as $i) {
            $i->starts_on && $in($i->starts_on->toDateString()) && $push($i, 'investment_start', $i->starts_on->toDateString(), 'blue', $i->stage);
            if ($i->planned_ends_on && $in($i->planned_ends_on->toDateString()) && in_array($i->stage, ['idea', 'business_case', 'approved', 'in_delivery'], true)) {
                $push($i, 'investment_planned_end', $i->planned_ends_on->toDateString(), $i->planned_ends_on->toDateString() < $today && $i->is_late ? 'red' : 'amber', $i->stage);
            }
            $i->actual_ends_on && $in($i->actual_ends_on->toDateString()) && $push($i, 'investment_delivered', $i->actual_ends_on->toDateString(), 'blue', $i->stage);
        }
        $visible = $invs->keyBy('id');
        if ($visible->isEmpty()) {
            return $out;
        }
        $gates = StageGate::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('investment_id', $visible->keys())->whereIn('status', ['submitted', 'approved', 'passed'])->get();
        foreach ($gates as $g) {
            $i = $visible[$g->investment_id];
            $label = ucfirst(str_replace('_', ' ', (string) $g->gate));
            if ($g->status === 'submitted') {
                $d = ($g->requested_at ?? $g->created_at)?->timezone(config('finance.timezone'))->format('Y-m-d');
                $in($d) && $push($i, 'investment_gate_pending', $d, 'amber', 'submitted', "-{$g->id}", $label);
            } else {
                $d = ($g->passed_at ?? $g->decided_at)?->timezone(config('finance.timezone'))->format('Y-m-d');
                $in($d) && $push($i, 'investment_gate_passed', $d, 'blue', (string) $g->status, "-{$g->id}", $label);
            }
        }
        $reviews = ValueReview::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('investment_id', $visible->keys())->whereBetween('due_on', [$f, $t])
            ->when($mine, fn ($q) => $q->where(fn ($w) => $w->where('reviewer_id', $mine)->orWhereIn('investment_id', $visible->filter(fn ($i) => (int) $i->manager_id === $mine || (int) $i->sponsor_id === $mine)->keys())))->get();
        foreach ($reviews as $r) {
            $color = in_array($r->status, ['completed', 'cancelled'], true) ? 'grey' : ($r->status === 'overdue' ? 'red' : 'amber');
            $push($visible[$r->investment_id], 'value_review_due', $r->due_on->toDateString(), $color, (string) $r->status, "-{$r->id}", ValueReview::TYPE_LABELS[$r->review_type] ?? $r->review_type);
        }

        return $out;
    }

    /**
     * Late investments (planned end passed, not delivered) and overdue value reviews.
     *
     * @return array<int,array<string,mixed>>
     */
    public function passedInvestments(int $orgId, User $viewer, ?CarbonInterface $today = null): array
    {
        $today ??= FinanceClock::today();
        $td = $today->toDateString();
        $rows = collect();
        $invs = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->visibleTo($viewer)->with('manager:id,name')->get()->keyBy('id');
        foreach ($invs as $i) {
            if (in_array($i->stage, ['approved', 'in_delivery'], true) && $i->planned_ends_on && $i->planned_ends_on->toDateString() < $td) {
                $rows->push($this->passedRow($i, 'planned_end', $i->planned_ends_on, $today));
            }
        }
        $reviews = ValueReview::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('investment_id', $invs->keys())->whereIn('status', ValueReview::OPEN)->whereDate('due_on', '<', $td)->get();
        foreach ($reviews as $r) {
            $i = $invs[$r->investment_id];
            $i->is_open && $rows->push($this->passedRow($i, 'value_review', $r->due_on, $today));
        }

        return $rows->sortByDesc('days_overdue')->values()->all();
    }

    private function passedRow(Investment $i, string $which, CarbonInterface $date, CarbonInterface $today): array
    {
        return [
            'id' => $i->id, 'investment_code' => $i->investment_code, 'title' => $i->title, 'stage' => $i->stage, 'manager' => $i->manager?->name, 'which' => $which,
            'date' => $date->toDateString(), 'days_overdue' => abs((int) FinanceClock::daysUntil($date, $today)), 'href' => route('finance.investments.show', [$i->id, 'tab' => $which === 'value_review' ? 'reviews' : 'overview']),
        ];
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

    /** @param list<int> $ids  @return list<int> contracts holding an approved renewal that has not been executed */
    private function unexecutedIds(array $ids): array
    {
        return $ids ? Renewal::withoutGlobalScopes()->whereIn('contract_id', $ids)->where('status', 'approved')->pluck('contract_id')->map(fn ($i) => (int) $i)->unique()->values()->all() : [];
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
