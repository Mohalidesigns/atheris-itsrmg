<?php

namespace Database\Seeders;

use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Events\Finance\PeriodCloseBlocked;
use App\Events\Finance\VarianceBreached;
use App\Models\BoardPackTemplate;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Forecast;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\VarianceNote;
use App\Models\Kri;
use App\Models\Organization;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
use App\Services\Finance\AccrualService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\ForecastService;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\PeriodCloseService;
use App\Services\Finance\VarianceService;
use App\Services\Reports\ReportFigures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * IT Financial Management P4 demo data (ATH-FIN-001 §13, plan P4 §5.1): budget monitoring, variance analysis and period close per bank.
 *
 * - Variances: every period of the current fiscal year that has ended is evaluated in order (YTD basis), after a top-up that guarantees
 *   the demo mix at the latest period (at least 3 red overspends, 1 red underspend and 3 amber, all above the bank's absolute floor);
 *   the seeder counts what it got and warns when it is short.
 * - Notes: red notes of earlier periods are explained by the cost-centre owner and acknowledged by the Finance Controller, most amber
 *   notes are explained, two stay open, two notes carry a comment thread and one red note per bank raised an Issue.
 * - Notification history is back-filled so the first real sweep does not resend it.
 * - Close history: a PeriodClose record and a forecast snapshot for every closed period. Kano's latest closed period was re-opened
 *   (Finance asked, the CFO approved) and is blocked again by a proposed accrual and an unexplained red variance (M9); First Bank's
 *   is still closed with a re-open request waiting at the CFO stage.
 * - Two manual forecast overrides, the four finance KRIs and the finance section of the BAC board pack.
 * - Second pass only: scheduled finance reports (the reports seeder skips a bank that already has a schedule, so these come after it).
 *
 * Separate from the P1 to P3 seeders because those skip a bank once their first record exists. Guarded per tenant on the variance
 * notes; the schedules step has its own guard. Failures are logged, never swallowed.
 */
class FinanceMonitoringDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['prefix' => 'KHB', 'domain' => 'kanoheritage.ng', 'reopen' => 'approved'],
        'first-bank-nigeria' => ['prefix' => 'FBN', 'domain' => 'firstbanknigeria.ng', 'reopen' => 'pending'],
    ];

    private const QUIET = [VarianceBreached::class, PeriodCloseBlocked::class, FinanceApprovalRequested::class, FinanceApprovalDecided::class];

    /** The demo mix guaranteed at the latest ended period. */
    private const TARGETS = ['red_over' => 3, 'red_under' => 1, 'amber' => 3];

    private const CHECKLIST = [
        'period_ended' => 'Period has ended', 'earlier_periods_closed' => 'Earlier periods are closed', 'actuals_posted' => 'Actuals are posted and matched',
        'accruals_done' => 'Accruals and prepayment releases are done', 'invoices_approved' => 'Invoices of the period are approved', 'variances_explained' => 'Variances are explained',
        'cloud_imported' => 'Cloud costs are imported',
    ];

    private const OVER = [
        'The USD-denominated licence is billed at the interbank rate, which ran well above the 2026 budget rate; Finance agreed to absorb it and re-forecast.',
        'Volume growth on the channel was ahead of plan after the Q1 campaign; the vendor invoiced the higher tier and the committee noted the pass-through.',
        'A CBN-driven change request brought forward the second-half capacity purchase; the budget phasing assumed it for Q4.',
        'The renewal landed with an 8 percent uplift above the planning assumption; the contract owner is negotiating a multi-year discount.',
        'Unplanned incident response work in the quarter was charged to this line; the corrective action is a managed service with a fixed fee.',
    ];

    private const UNDER = [
        'Delivery slipped to the fourth quarter after the vendor missed the staging milestone; the spend will land before year end.',
        'The procurement award took longer than planned following the extended due-diligence review; invoices start next period.',
        'The project was re-scoped after the architecture board review and part of the cost moved to the transformation portfolio.',
        'A vendor credit for service-level misses was applied in the period; the underlying run cost is unchanged.',
        'The hiring plan was not met, so contractor cost is lower than budgeted; backfill is approved for the next quarter.',
    ];

    private const ACTIONS = [
        'Re-forecast the remaining periods and agree the rate assumption with the CFO.',
        'Renegotiate the tier at the next renewal and add a usage cap.',
        'Move the phasing of the remaining spend and confirm at the next budget review.',
        'Track weekly at the IT cost committee until back within the threshold.',
    ];

    private int $orgId;

    private string $prefix;

    private string $domain;

    private CarbonImmutable $today;

    private int $fyId;

    /** @var array<string,User> */
    private array $who = [];

    /** @var array<int,User> cost centre id => owner */
    private array $owners = [];

    /** @var array<string,int> */
    private array $stats = [];

    private ?Period $last = null;

    private ?Period $reopenTarget = null;

    /** approved (Kano: the latest closed period was re-opened and is blocked again) or pending (First Bank: a re-open request waits at the CFO stage). */
    private string $reopenMode = 'approved';

    /** @var list<array<string,mixed>> lines with no breach at the latest period (top-up candidates) */
    private array $free = [];

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || ! CostCentre::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                continue;
            }
            $this->orgId = $org->id;
            $this->prefix = $bank['prefix'];
            $this->domain = $bank['domain'];
            $this->stats = [];
            mt_srand(4000 + $this->orgId);
            try {
                $this->people();
            } catch (Throwable $e) {
                $this->command?->warn("  [{$this->prefix}] finance monitoring seed skipped: ".$e->getMessage());

                continue;
            }
            Auth::setUser($this->who['finance']);
            if (! VarianceNote::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
                $this->seedMonitoring($bank['reopen']);
            }
            $this->schedules();
        }
        mt_srand();
        Auth::forgetUser();
    }

    /* ------------------------------------------------------------------ helpers */

    private function people(): void
    {
        foreach (['finance', 'cfo', 'headofit'] as $key) {
            $this->who[$key] = User::where('email', "{$key}@{$this->domain}")->firstOrFail();
        }
        $this->owners = [];
        foreach (CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereNotNull('owner_id')->get() as $cc) {
            $owner = User::withoutGlobalScopes()->find($cc->owner_id);
            $owner && $this->owners[(int) $cc->id] = $owner;
        }
    }

    /**
     * Variance notes of closed and locked periods are immutable in the services. The demo calendar is already closed when this seeder
     * runs, so the periods are opened for the duration of one write and put back exactly as they were.
     */
    private function whilePeriodsOpen(callable $fn): mixed
    {
        $closed = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->whereIn('status', ['closed', 'locked'])->pluck('status', 'id');
        Period::withoutGlobalScopes()->whereIn('id', $closed->keys())->update(['status' => 'open']);
        try {
            return $fn();
        } finally {
            foreach ($closed as $id => $status) {
                Period::withoutGlobalScopes()->whereKey($id)->update(['status' => $status]);
            }
        }
    }

    private function attempt(string $label, callable $fn): mixed
    {
        try {
            $out = null;
            Event::fakeFor(function () use ($fn, &$out) {
                $out = $fn();
            }, self::QUIET);

            return $out;
        } catch (Throwable $e) {
            $msg = $e instanceof ValidationException ? json_encode($e->errors()) : $e->getMessage();
            $this->command?->warn("  [{$this->prefix}] finance monitoring seed skipped {$label}: ".mb_substr((string) $msg, 0, 240));

            return null;
        }
    }

    private function bump(string $key, int $n = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $n;
    }

    private function pick(array $pool): string
    {
        return $pool[mt_rand(0, count($pool) - 1)];
    }

    private function ownerOf(VarianceNote $n): User
    {
        $o = $this->owners[(int) $n->cost_centre_id] ?? null;

        return $o && $o->id !== $this->who['finance']->id ? $o : $this->who['headofit'];
    }

    /* ------------------------------------------------------------------ main */

    private function seedMonitoring(string $reopen): void
    {
        app(FinanceSettings::class)->ensureDefaults($this->orgId);
        FinancePolicySeeder::ensureDefaults($this->orgId); // adds finance.period_reopen to banks seeded before P4
        $this->today = FinanceClock::today();
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_current', true)->first();
        $mon = app(MonitoringService::class);
        $ended = $fy ? Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $fy->id)->where('is_adjustment', false)
            ->whereDate('ends_on', '<', $this->today->toDateString())->orderBy('sequence')->get() : collect();
        if (! $fy || $ended->isEmpty() || $mon->baselineLines($this->orgId, $fy->id)->isEmpty()) {
            $this->command?->warn("  [{$this->prefix}] finance monitoring seed skipped: no current fiscal year with an ended period and a baseline budget.");

            return;
        }
        $this->fyId = $fy->id;
        $this->reopenMode = $reopen;
        $this->last = $ended->last();
        $this->reopenTarget = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $fy->id)->where('is_adjustment', false)
            ->whereIn('status', ['closed', 'locked'])->orderByDesc('sequence')->first();

        $this->attempt('variance top-up', fn () => $this->guaranteeMix());
        foreach ($ended as $p) {
            $this->attempt("variance evaluation {$p->name}", function () use ($p) {
                $r = $this->whilePeriodsOpen(fn () => app(VarianceService::class)->evaluate($p->refresh(), $this->who['finance'], true));
                $this->bump('notes', $r['created']);
                $end = CarbonImmutable::parse($p->ends_on)->addDay()->setTime(7, 0);
                VarianceNote::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('period_id', $p->id)->update(['created_at' => $end, 'evaluated_at' => $end]);
            });
        }
        $this->attempt('notification history', fn () => $this->notificationHistory());
        $this->attempt('explanations', fn () => $this->whilePeriodsOpen(fn () => $this->explanations($ended)));
        $this->attempt('comment threads and issue', fn () => $this->whilePeriodsOpen(fn () => $this->threadsAndIssue($ended)));
        $this->attempt('close history', fn () => $this->closeHistory($fy));
        $this->attempt('reopen', fn () => $this->reopen($reopen));
        $this->attempt('forecast overrides', fn () => $this->forecastOverrides());
        $this->attempt('finance KRIs and board pack', fn () => $this->krisAndBoardPack());
        $this->report();
    }

    private function report(): void
    {
        $count = fn (array $w) => VarianceNote::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where($w)->count();
        $this->command?->info(sprintf(
            '  [%s] finance monitoring: %d notes (%d red, %d amber; %d open), %d snapshots, %d manual forecasts, %d close records',
            $this->prefix, $count([]), $count(['severity' => 'red']), $count(['severity' => 'amber']), $count(['status' => 'open']),
            Forecast::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_snapshot', true)->count(),
            Forecast::withoutGlobalScopes()->where('organization_id', $this->orgId)->activeOverride()->count(),
            PeriodClose::withoutGlobalScopes()->where('organization_id', $this->orgId)->count(),
        ));
    }

    /* ------------------------------------------------------------------ variance mix */

    /** @return array<string,int> red_over, red_under and amber counts at the latest ended period */
    private function mix(): array
    {
        $mon = app(MonitoringService::class);
        $vs = app(VarianceService::class);
        $lines = $mon->baselineLines($this->orgId, $this->fyId);
        $fig = $mon->lineFigures($lines, $this->fyId, null, (int) $this->last->sequence);
        $floor = $vs->thresholds($this->orgId)['abs_floor'];
        $out = ['red_over' => 0, 'red_under' => 0, 'amber' => 0];
        $free = [];
        foreach ($lines as $l) {
            $t = $vs->thresholds($this->orgId, $l);
            $c = VarianceService::classify($fig[$l->id]['budget'], $fig[$l->id]['actual'], $t['amber_pct'], $t['red_pct'], $floor);
            if ($c['severity'] === 'red') {
                $out[$c['direction'] === 'over' ? 'red_over' : 'red_under']++;
            } elseif ($c['severity'] === 'amber') {
                $out['amber']++;
            } else {
                $free[] = ['line' => $l, 'budget' => $fig[$l->id]['budget'], 'actual' => $fig[$l->id]['actual'], 't' => $t];
            }
        }
        $this->free = $free;

        return $out;
    }

    /** Tops up named lines with direct adjustment postings in the latest ended period until the demo mix is reached. */
    private function guaranteeMix(): void
    {
        $have = $this->mix();
        $floor = app(VarianceService::class)->thresholds($this->orgId)['abs_floor'];
        $need = ['red_over' => max(0, self::TARGETS['red_over'] - $have['red_over']), 'red_under' => max(0, self::TARGETS['red_under'] - $have['red_under']), 'amber' => max(0, self::TARGETS['amber'] - $have['amber'])];
        usort($this->free, fn ($a, $b) => bccomp($b['budget'], $a['budget'], 2));
        $candidates = $this->free;
        $made = 0;
        foreach ($need as $kind => $n) {
            foreach ($candidates as $k => $c) {
                if ($n <= 0) {
                    break;
                }
                $b = $c['budget'];
                $red = bcmul($b, bcdiv($c['t']['red_pct'], '100', 6), 2);
                $amber = bcmul($b, bcdiv($c['t']['amber_pct'], '100', 6), 2);
                $current = bcsub($c['actual'], $b, 2);
                $threshold = bccomp($red, $floor, 2) >= 0 ? $red : $floor;
                if ($kind === 'red_over') {
                    $target = bcadd(bcadd($threshold, bcmul($b, '0.03', 2), 2), '1000.00', 2);
                } elseif ($kind === 'red_under') {
                    $target = '-'.bcadd(bcadd($threshold, bcmul($b, '0.03', 2), 2), '1000.00', 2);
                    if (bccomp(bcadd($b, $target, 2), '0', 2) < 0) {
                        continue;
                    }
                } else {
                    $lo = bccomp($amber, $floor, 2) >= 0 ? $amber : $floor;
                    if (bccomp(bcmul($lo, '1.10', 2), $red, 2) >= 0) {
                        continue; // no room between amber and red on this line
                    }
                    $target = bcadd($lo, bcdiv(bcsub($red, $lo, 2), '2', 2), 2);
                }
                $topUp = bcsub($target, $current, 2);
                $this->insertTopUp($c['line'], $topUp, $kind);
                unset($candidates[$k]);
                $n--;
                $made++;
            }
            if ($n > 0) {
                $this->command?->warn("  [{$this->prefix}] finance monitoring: short of {$n} {$kind} variance(s) above the floor of {$floor}; no further line had room.");
            }
        }
        $this->bump('topups', $made);
        if ($made > 0) {
            $after = $this->mix();
            foreach (self::TARGETS as $kind => $want) {
                $after[$kind] < $want && $this->command?->warn("  [{$this->prefix}] finance monitoring: only {$after[$kind]} {$kind} variance(s) after top-up (wanted {$want}).");
            }
        }
    }

    private function insertTopUp(BudgetLine $line, string $amount, string $kind): void
    {
        $p = $this->last;
        $credit = str_starts_with($amount, '-');
        $now = now();
        DB::table('fin_actuals')->insert([
            'organization_id' => $this->orgId, 'created_by_id' => $this->who['finance']->id, 'fiscal_year_id' => $this->fyId, 'period_id' => $p->id, 'posted_on' => $p->ends_on->toDateString(),
            'source' => 'adjustment', 'source_ref' => sprintf('ADJ/%s/%02d/%04d', $this->prefix, $p->sequence, $line->id), 'posting_key' => "p4-demo:{$line->id}:{$p->id}",
            'gl_account_id' => $line->gl_account_id, 'category_id' => $line->category_id, 'vendor_id' => $line->vendor_id, 'contract_id' => $line->contract_id,
            'cost_centre_id' => $line->cost_centre_id, 'budget_line_id' => $line->id,
            'description' => mb_substr(($credit ? 'Vendor credit note: ' : 'Ledger adjustment (catch-up of prior-month billing): ').$line->description, 0, 255),
            'amount' => $amount, 'currency' => 'NGN', 'fx_rate' => 1, 'fx_rate_type' => 'spot', 'fx_rate_date' => $p->ends_on->toDateString(), 'base_amount' => $amount,
            'is_capex' => (bool) $line->is_capex, 'is_lease' => false, 'match_status' => 'matched', 'match_reason' => 'manual', 'matched_by_id' => $this->who['finance']->id, 'matched_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /* ------------------------------------------------------------------ notes */

    private function notificationHistory(): void
    {
        $periods = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->pluck('ends_on', 'id');
        $rows = [];
        $now = now();
        foreach (VarianceNote::withoutGlobalScopes()->where('organization_id', $this->orgId)->get() as $n) {
            $to = ($this->owners[(int) $n->cost_centre_id] ?? $this->who['finance'])->id;
            $rows[] = [
                'organization_id' => $this->orgId, 'notifiable_type' => VarianceNote::class, 'notifiable_id' => $n->id, 'notification_key' => "variance:{$n->peak_severity}", 'offset_days' => 0,
                'sent_to_user_id' => $to, 'sent_at' => CarbonImmutable::parse($periods[$n->period_id])->addDay()->setTime(6, 0)->utc(), 'channel' => 'database', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('fin_notification_logs')->insertOrIgnore($chunk);
        }
        $this->bump('logs', count($rows));
    }

    /** Which notes are explained and acknowledged (plan P4 §5.1 step 4); the latest period keeps its open items. */
    private function explanations($ended): void
    {
        $vs = app(VarianceService::class);
        $lastId = (int) $this->last->id;
        $periods = $ended->keyBy('id');
        $keepOpenRed = null;
        $lastReds = VarianceNote::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('period_id', $lastId)->where('severity', 'red')->where('is_breaching', true)->get();
        if ($this->reopenMode === 'approved' && $this->reopenTarget && (int) $this->reopenTarget->id === $lastId) { // the re-opened period keeps one unexplained red (the largest overspend)
            $keepOpenRed = $lastReds->where('direction', 'over')->sortByDesc(fn ($n) => (float) $n->variance_amount)->first()?->id;
        }
        $openAmber = VarianceNote::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('period_id', $lastId)->where('severity', 'amber')->where('is_breaching', true)
            ->orderBy('id')->limit(2)->pluck('id')->all();
        $acked = 0;
        foreach (VarianceNote::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_breaching', true)->where('status', 'open')->orderBy('period_id')->orderBy('id')->get() as $n) {
            if ($n->id === $keepOpenRed || in_array($n->id, $openAmber, true)) {
                continue;
            }
            $owner = $this->ownerOf($n);
            $at = CarbonImmutable::parse($periods[$n->period_id]->ends_on ?? $this->today)->addDays(3 + $n->id % 4)->setTime(10, 0);
            $pool = $n->direction === 'over' ? self::OVER : self::UNDER;
            $note = $vs->explain($n, $this->pick($pool), $n->severity === 'red' ? $this->pick(self::ACTIONS) : null, $owner);
            $update = ['explained_at' => $at];
            // red notes of earlier periods are acknowledged by Finance; in the latest period only every other one is
            if ($n->severity === 'red' && ((int) $n->period_id !== $lastId || $n->id % 2 === 0)) {
                $vs->acknowledge($note, $this->who['finance']);
                $update['acknowledged_at'] = $at->addDays(2);
                $acked++;
            }
            VarianceNote::withoutGlobalScopes()->whereKey($n->id)->update($update);
            $this->bump('explained');
        }
        $this->bump('acknowledged', $acked);
    }

    private function threadsAndIssue($ended): void
    {
        $vs = app(VarianceService::class);
        $explainedReds = VarianceNote::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('severity', 'red')->whereIn('status', ['explained', 'acknowledged'])
            ->orderByDesc(DB::raw('abs(variance_amount)'))->limit(6)->get();
        foreach ($explainedReds->take(2) as $i => $n) {
            $owner = $this->ownerOf($n);
            $vs->comment($n, $i === 0 ? 'Can you confirm the rate used in the re-forecast and attach the vendor statement?' : 'Please confirm the deferral is reflected in the next phasing review.', $this->who['finance']);
            $vs->comment($n, $i === 0 ? 'Confirmed: the vendor statement is attached to the contract record and the re-forecast uses the rate agreed with Treasury.' : 'Confirmed. The phasing moves to the Q4 plan once the CIO signs off.', $owner);
            $vs->comment($n, 'Noted for the committee pack.', $this->who['cfo']);
            $this->bump('comments', 3);
        }
        // one red note per bank raised an Issue: the largest explained overspend of an earlier period when the latest one is re-opened
        $skipLatest = $this->reopenMode === 'approved' && $this->reopenTarget && (int) $this->reopenTarget->id === (int) $this->last->id;
        $issueNote = $explainedReds->first(fn ($n) => $n->direction === 'over' && (! $skipLatest || (int) $n->period_id !== (int) $this->last->id)) ?? $explainedReds->first();
        if ($issueNote) {
            $vs->raiseIssue($issueNote, $this->who['finance']);
            $this->bump('issues');
        }
    }

    /* ------------------------------------------------------------------ close history and reopen */

    private function closeHistory(FiscalYear $fy): void
    {
        $items = [];
        foreach (self::CHECKLIST as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label, 'status' => $key === 'cloud_imported' ? 'n_a' : 'pass', 'count' => 0, 'detail' => $key === 'cloud_imported' ? 'Cloud cost import arrives with a later phase.' : 'Passed when the period closed.', 'href' => null];
        }
        $forecasts = app(ForecastService::class);
        foreach (Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $fy->id)->whereIn('status', ['closed', 'locked'])->orderBy('sequence')->get() as $p) {
            PeriodClose::withoutGlobalScopes()->create([
                'organization_id' => $this->orgId, 'created_by_id' => $this->who['finance']->id, 'fiscal_year_id' => $fy->id, 'period_id' => $p->id, 'status' => $p->status, 'checklist' => $items,
                'closed_by_id' => $p->closed_by_id ?? $this->who['finance']->id, 'closed_at' => $p->closed_at ?? $p->ends_on->copy()->addDays(5)->setTime(10, 0),
                'locked_by_id' => $p->status === 'locked' ? ($p->locked_by_id ?? $this->who['finance']->id) : null, 'locked_at' => $p->status === 'locked' ? $p->locked_at : null,
            ]);
            $forecasts->snapshot($p, $this->who['finance']);
            $this->bump('closes');
        }
    }

    private function reopen(string $mode): void
    {
        $p = $this->reopenTarget;
        if (! $p) {
            $this->command?->warn("  [{$this->prefix}] finance monitoring: no closed period to re-open.");

            return;
        }
        $svc = app(PeriodCloseService::class);
        if ($mode === 'pending') {
            $svc->requestReopen($p, "The {$p->name} GL extract omitted the data-centre co-location recharge; Finance needs the period open to post it before the CBN return is finalised.", $this->who['finance']);

            return;
        }
        $c = $svc->requestReopen($p, "A late Interswitch settlement invoice for {$p->name} arrived after the close and has to be accrued before the board pack is finalised.", $this->who['finance']);
        for ($i = 0; $i < 3 && $c->refresh()->reopen_status === 'submitted'; $i++) {
            $svc->approveReopen($c, $this->who['cfo'], 'Approved: the invoice is confirmed and the accrual must sit in the right period.');
        }
        // the blocked close (M9): one proposed accrual in the re-opened period, and one red variance left unexplained
        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', 'like', '%NET')->first() ?? CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereNotNull('owner_id')->first();
        $line = BudgetLine::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('cost_centre_id', $cc->id)->orderByDesc('base_annual_amount')->first();
        app(AccrualService::class)->store([
            'period_id' => $p->id, 'cost_centre_id' => $cc->id, 'budget_line_id' => $line?->id, 'description' => "Interswitch settlement fees for {$p->name} (received, not yet invoiced)",
            'amount' => '4850000.00', 'currency' => 'NGN',
        ], $this->who['finance']);
        $this->bump('accruals');
    }

    /* ------------------------------------------------------------------ forecasts, KRIs, board pack */

    private function forecastOverrides(): void
    {
        $mon = app(MonitoringService::class);
        $svc = app(ForecastService::class);
        $lines = $mon->baselineLines($this->orgId, $this->fyId);
        $eac = $svc->eacForLines($lines, $this->fyId, $this->last);
        $ranked = $lines->sortByDesc(fn ($l) => (float) $eac[$l->id]['eac_remaining_budget'])->values();
        $reasons = [
            ['1.04', 'Assumes the naira at the 2026 average interbank rate for the remaining months; Treasury expects no further step devaluation.'],
            ['0.97', 'Vendor re-quote expected in Q4 with a volume discount; the contract owner holds a written indication of 3 percent.'],
        ];
        foreach ($reasons as $i => [$factor, $reason]) {
            $l = $ranked[$i + 2] ?? $ranked[$i] ?? null; // skip the two largest lines, which carry the red notes
            if (! $l) {
                continue;
            }
            $svc->override($l, bcmul($eac[$l->id]['eac_remaining_budget'], $factor, 2), $reason, $this->who['finance']);
            $this->bump('overrides');
        }
    }

    private function krisAndBoardPack(): void
    {
        $defs = [
            ['KRI-FIN-VAR', 'IT budget variance (YTD)', 'Absolute year-to-date variance of IT spend against budget.', '%', 5, 10, 20],
            ['KRI-FIN-UNB', 'Unbudgeted IT spend', 'Unbudgeted actual spend as a share of year-to-date IT spend.', '%', 2, 5, 10],
            ['KRI-FIN-INV', 'Overdue IT invoices', 'Unpaid, undisputed IT invoices past their due date.', 'count', 0, 3, 10],
            ['KRI-FIN-NOT', 'Contracts past notice deadline', 'Live contracts whose notice deadline has passed with no renewal decision.', 'count', 0, 1, 3],
        ];
        foreach ($defs as [$code, $name, $desc, $unit, $g, $a, $r]) {
            Kri::withoutGlobalScopes()->updateOrCreate(['organization_id' => $this->orgId, 'code' => $code], [
                'name' => $name, 'description' => $desc, 'category' => 'finance', 'unit' => $unit, 'direction' => 'higher_worse', 'threshold_green' => $g, 'threshold_amber' => $a, 'threshold_red' => $r,
                'frequency' => 'monthly', 'source' => 'platform', 'owner_id' => $this->who['cfo']->id,
            ]);
        }
        $bac = BoardPackTemplate::where('name', 'like', 'BAC%')->first();
        if ($bac && ! in_array('it_financial_position', (array) $bac->sections, true)) {
            $bac->update(['sections' => array_merge((array) $bac->sections, ['it_financial_position'])]);
        } elseif (! $bac) {
            $this->command?->warn("  [{$this->prefix}] finance monitoring: no BAC board pack template to extend.");
        }
    }

    /* ------------------------------------------------------------------ schedules (second pass) */

    private function schedules(): void
    {
        $other = ScheduledReport::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('report_type', 'not like', 'finance%')->exists();
        $finance = ScheduledReport::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('report_type', 'like', 'finance%')->exists();
        if (! $other || $finance) {
            return; // first pass (the reports seeder has not run yet), or already done
        }
        $this->attempt('finance schedules', function () {
            $recipients = [$this->who['finance']->email, $this->who['cfo']->email];
            foreach ([['Monthly IT BvA', 'finance.bva', 'monthly', 6], ['Weekly invoice ageing', 'finance.invoice_ageing', 'weekly', 2]] as [$name, $type, $freq, $daysAgo]) {
                $s = ScheduledReport::withoutGlobalScopes()->create([
                    'organization_id' => $this->orgId, 'name' => $name, 'report_type' => $type, 'frequency' => $freq, 'recipients' => $recipients, 'active' => true,
                    'created_by' => $this->who['finance']->id, 'next_run_at' => ScheduledReport::advance($freq, now()->subDays($daysAgo)), 'last_run_at' => now()->subDays($daysAgo),
                ]);
                ScheduledReportRun::withoutGlobalScopes()->create([
                    'organization_id' => $this->orgId, 'scheduled_report_id' => $s->id, 'ran_at' => now()->subDays($daysAgo), 'status' => 'recorded', 'summary' => ReportFigures::summary($type, $this->orgId),
                ]);
                $this->bump('schedules');
            }
        });
    }
}
