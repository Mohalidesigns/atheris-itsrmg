<?php

namespace App\Services\Finance;

use App\Events\Finance\PeriodCloseBlocked;
use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\VarianceNote;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Facades\DB;

/**
 * Period close, lock and reopen (FIN-04.05, plan P4-5, P4-6). Closing runs the checklist (and refuses on any failed item);
 * reopening needs approval through `finance.period_reopen` with the PeriodClose record as the subject.
 */
class PeriodCloseService
{
    use LogsFinanceEvents;

    public const PROCESS = 'finance.period_reopen';

    public const PERMISSION = 'approve it-finance';

    public const TRANSITIONS = [
        'close' => ['from' => ['open'], 'to' => 'closed'],
        'lock' => ['from' => ['closed'], 'to' => 'locked'],
        'request_reopen' => ['from' => ['closed', 'locked'], 'to' => 'submitted'],
        'approve_reopen' => ['from' => ['submitted'], 'to' => 'approved'],
        'reject_reopen' => ['from' => ['submitted'], 'to' => 'rejected'],
        'withdraw_reopen' => ['from' => ['submitted'], 'to' => 'withdrawn'],
    ];

    public function __construct(
        private readonly FiscalCalendarService $calendar,
        private readonly VarianceService $variances,
        private readonly ForecastService $forecasts,
        private readonly AccrualService $accruals,
        private readonly FinanceApprovals $approvals,
        private readonly MonitoringService $monitoring,
    ) {}

    /** @return array{period:array{id:int,name:string,status:string},items:list<array<string,mixed>>,can_close:bool,blockers:list<string>} */
    public function checklist(Period $p): array
    {
        $orgId = (int) $p->organization_id;
        $today = FinanceClock::today()->toDateString();
        $item = fn (string $key, string $label, string $status, int $count, string $detail, ?string $href = null) => compact('key', 'label', 'status', 'count', 'detail', 'href');
        $items = [];

        $ended = $p->ends_on->toDateString() < $today;
        $allow = (bool) config('finance.monitoring.allow_early_close');
        $items[] = $item('period_ended', 'Period has ended', $ended || $allow ? 'pass' : 'fail', 0,
            $ended ? 'Ended on '.$p->ends_on->toDateString().'.' : 'Ends on '.$p->ends_on->toDateString().'; it cannot close before then.', route('finance.settings', ['tab' => 'calendar'], false));

        $earlier = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'open')->where(function ($q) use ($p) {
            $q->where(fn ($w) => $w->where('fiscal_year_id', $p->fiscal_year_id)->where('sequence', '<', $p->sequence));
            if ((int) $p->sequence === 1) {
                $fy = FiscalYear::withoutGlobalScopes()->find($p->fiscal_year_id);
                $q->orWhereIn('fiscal_year_id', FiscalYear::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('ends_on', '<', $fy->starts_on)->select('id'));
            }
        })->orderBy('id')->pluck('name');
        $items[] = $item('earlier_periods_closed', 'Earlier periods are closed', $earlier->isEmpty() ? 'pass' : 'fail', $earlier->count(),
            $earlier->isEmpty() ? 'Every earlier period is closed.' : 'Still open: '.$earlier->implode(', ').'.', route('finance.monitor.period-close', ['fiscal_year_id' => $p->fiscal_year_id], false));

        $hasBaseline = $this->monitoring->baselineLines($orgId, (int) $p->fiscal_year_id)->isNotEmpty();
        $counted = Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)->where('match_status', '!=', 'duplicate');
        $posted = (clone $counted)->count();
        $unmatched = (clone $counted)->where('match_status', 'unmatched')->count();
        if (! $hasBaseline) {
            $items[] = $item('actuals_posted', 'Actuals are posted and matched', 'n_a', 0, 'The fiscal year has no baseline budget.');
        } elseif ($posted === 0 || $unmatched > 0) {
            $items[] = $item('actuals_posted', 'Actuals are posted and matched', 'fail', $unmatched, $posted === 0 ? 'No actuals have been posted for this period.' : "{$unmatched} unmatched actual(s) need a budget line.",
                route('finance.actuals.index', ['tab' => 'queue'], false));
        } else {
            $items[] = $item('actuals_posted', 'Actuals are posted and matched', 'pass', $posted, "{$posted} actual(s) posted and matched.", route('finance.actuals.index', [], false));
        }

        $pending = $this->accruals->pendingFor($p);
        $n = array_sum($pending);
        $items[] = $item('accruals_done', 'Accruals and prepayment releases are done', $n === 0 ? 'pass' : 'fail', $n,
            $n === 0 ? 'No accrual work is outstanding.' : "{$pending['accruals']} proposed accrual(s), {$pending['releases']} proposed release(s), {$pending['candidates']} accrual(s) not yet proposed, {$pending['reversals']} posted accrual(s) awaiting reversal.",
            route('finance.accruals.index', [], false));

        $cancelled = Accrual::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)->where('status', 'cancelled')->orderBy('id')->get(['id', 'description', 'cancel_reason']);
        $items[] = $item('accruals_cancelled', 'Cancelled accruals are reviewed', $cancelled->isEmpty() ? 'pass' : 'warn', $cancelled->count(),
            $cancelled->isEmpty() ? 'No accrual was cancelled in this period.' : $cancelled->count().' accrual(s) were cancelled: '.$cancelled->map(fn ($a) => "#{$a->id} ({$a->cancel_reason})")->implode('; ').'.',
            route('finance.accruals.index', ['status' => 'cancelled'], false));

        $inv = Invoice::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)->whereNull('deleted_at');
        $open = (clone $inv)->whereIn('status', ['draft', 'submitted'])->count();
        $disputed = (clone $inv)->where('status', 'disputed')->count();
        $items[] = $item('invoices_approved', 'Invoices of the period are approved', $open > 0 ? 'fail' : ($disputed > 0 ? 'warn' : 'pass'), $open > 0 ? $open : $disputed,
            $open > 0 ? "{$open} invoice(s) are still draft or awaiting approval." : ($disputed > 0 ? "{$disputed} invoice(s) are disputed." : 'Every invoice of the period is approved.'),
            route('finance.invoices.index', [], false));

        $notes = VarianceNote::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)->where('is_breaching', true)->where('status', 'open');
        $red = (clone $notes)->where('severity', 'red')->count();
        $amber = (clone $notes)->where('severity', 'amber')->count();
        $amberFails = (bool) config('finance.monitoring.explain_amber_at_close');
        $vs = $red > 0 || ($amberFails && $amber > 0) ? 'fail' : ($amber > 0 ? 'warn' : 'pass');
        $items[] = $item('variances_explained', 'Variances are explained', $vs, $red + $amber,
            $vs === 'pass' ? 'No unexplained variance.' : "{$red} red and {$amber} amber variance(s) await an explanation.",
            route('finance.monitor.variances', ['fiscal_year_id' => $p->fiscal_year_id, 'period_id' => $p->id, 'needs_explanation' => 1], false));

        $items[] = $item('cloud_imported', 'Cloud costs are imported', 'n_a', 0, 'Cloud cost import arrives with a later phase.');

        $blockers = [];
        foreach ($items as $i) {
            $i['status'] === 'fail' && $blockers[] = $i['label'].': '.$i['detail'];
        }

        return [
            'period' => ['id' => (int) $p->id, 'name' => $p->name, 'status' => $p->status], 'items' => $items,
            'can_close' => $p->status === 'open' && $blockers === [], 'blockers' => $blockers,
        ];
    }

    public function close(Period $p, User $by): PeriodClose
    {
        $p = Period::withoutGlobalScopes()->findOrFail($p->id);
        in_array($p->status, self::TRANSITIONS['close']['from'], true) || $this->fail("A {$p->status} period cannot be closed.");
        (int) $by->organization_id === (int) $p->organization_id || $this->fail('This period belongs to another bank.');
        $early = $p->ends_on->toDateString() >= FinanceClock::today()->toDateString();
        $allowEarly = (bool) config('finance.monitoring.allow_early_close');
        // A period that has not ended is refused before anything is evaluated (no false "under" notes or breach notifications).
        $list = $early && ! $allowEarly ? $this->checklist($p) : null;
        if ($list === null) {
            $this->variances->evaluate($p, $by, $early);
            $list = $this->checklist($p);
        }
        if (! $list['can_close']) {
            event(new PeriodCloseBlocked((int) $p->id, $list['blockers'], (int) $by->id));
            $this->fail(implode('; ', $list['blockers']), 'checklist');
        }

        return DB::transaction(function () use ($p, $by, $list) {
            $closed = $this->calendar->closePeriod($p, $by);
            $c = PeriodClose::withoutGlobalScopes()->create([
                'organization_id' => $p->organization_id, 'created_by_id' => $by->id, 'fiscal_year_id' => $p->fiscal_year_id, 'period_id' => $p->id,
                'status' => 'closed', 'checklist' => $list['items'], 'closed_by_id' => $by->id, 'closed_at' => now(),
            ]);
            $this->forecasts->snapshot($closed, $by, $c);
            $this->audit($c, $by, 'closed', "{$p->name} closed");

            return $c;
        });
    }

    public function lock(Period $p, User $by): PeriodClose
    {
        return DB::transaction(function () use ($p, $by) {
            $locked = $this->calendar->lockPeriod($p, $by);
            $c = $this->latestClose($locked);
            if ($c && $c->status === 'closed') {
                $c->forceFill(['status' => 'locked', 'locked_by_id' => $by->id, 'locked_at' => now()])->save();
            } else {
                $c = $this->legacyClose($locked, 'locked', $by);
                $c->forceFill(['locked_by_id' => $by->id, 'locked_at' => now()])->save();
            }
            $this->audit($c, $by, 'locked', "{$locked->name} locked");

            return $c;
        });
    }

    public function requestReopen(Period $p, string $reason, User $by): PeriodClose
    {
        $p = Period::withoutGlobalScopes()->findOrFail($p->id);
        (int) $by->organization_id === (int) $p->organization_id || $this->fail('This period belongs to another bank.');
        in_array($p->status, self::TRANSITIONS['request_reopen']['from'], true) || $this->fail("A {$p->status} period needs no re-open.");
        $reason = trim($reason);
        mb_strlen($reason) >= 20 || $this->fail('Give the reason for re-opening (at least 20 characters).', 'reason');
        $this->assertLatestNonOpen($p);
        PeriodClose::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('period_id', $p->id)->where('reopen_status', 'submitted')->exists()
            && $this->fail('This period already has a re-open request awaiting approval.');

        return DB::transaction(function () use ($p, $reason, $by) {
            $c = $this->latestClose($p);
            $c = $c && in_array($c->status, ['closed', 'locked'], true) ? $c : $this->legacyClose($p, $p->status, $by);
            $c->forceFill([
                'reopen_status' => 'submitted', 'reopen_reason' => $reason, 'reopen_requested_by_id' => $by->id, 'reopen_requested_at' => now(),
                'reopen_decided_by_id' => null, 'reopen_decided_at' => null, 'reopen_rejection_reason' => null,
            ])->save();
            $request = $this->approvals->start(self::PROCESS, $c, $by);
            $request && $c->forceFill(['reopen_approval_request_id' => $request->id])->save();
            $this->audit($c, $by, 'reopen_requested', "Re-open of {$p->name} requested", ['reason' => $reason]);

            return $c->refresh();
        });
    }

    /** Returns a message while stages remain; the final approval puts the period back to open. */
    public function approveReopen(PeriodClose $c, User $by, ?string $note = null): string
    {
        $this->assertSubmitted($c);
        $this->assertApprover($c, $by);

        return DB::transaction(function () use ($c, $by, $note) {
            $c = PeriodClose::withoutGlobalScopes()->lockForUpdate()->findOrFail($c->id);
            $this->assertSubmitted($c);
            $this->approvals->assertZeroStepSoD(self::PROCESS, $c, $by, 'reopen_requested_by_id', null, self::PERMISSION);
            if (! $this->approvals->configured(self::PROCESS, (int) $c->organization_id)) {
                $this->approvals->assertDefaultApprover($c, $by, 'reopen_requested_by_id', null, self::PERMISSION);
            } elseif ($message = $this->approvals->approve(self::PROCESS, $c, $by, $note)) {
                $this->audit($c, $by, 'stage_approved', $message);

                return $message;
            }
            $p = Period::withoutGlobalScopes()->lockForUpdate()->findOrFail($c->period_id);
            in_array($p->status, ['closed', 'locked'], true) || $this->fail("{$p->name} is already {$p->status}.");
            $this->assertLatestNonOpen($p);
            FiscalYear::withoutGlobalScopes()->whereKey($p->fiscal_year_id)->value('status') === 'closed' && $this->fail('The fiscal year is closed; its periods cannot be re-opened.');
            $p->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by_id' => null, 'locked_at' => null, 'locked_by_id' => null])->save();
            $c->forceFill(['reopen_status' => 'approved', 'status' => 'reopened', 'reopen_decided_by_id' => $by->id, 'reopen_decided_at' => now(), 'reopened_at' => now()])->save();
            $this->approvals->finalised(self::PROCESS, $c, $by, $note);
            $this->audit($c, $by, 'reopen_approved', "{$p->name} re-opened");

            return "{$p->name} is open again.";
        });
    }

    public function rejectReopen(PeriodClose $c, User $by, string $note): PeriodClose
    {
        $this->assertSubmitted($c);
        trim($note) === '' && $this->fail('Give a reason.', 'note');
        $this->assertApprover($c, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $c->organization_id)) {
            $this->approvals->assertDefaultApprover($c, $by, 'reopen_requested_by_id', null, self::PERMISSION);
        }
        DB::transaction(function () use ($c, $by, $note) {
            $this->approvals->decline(self::PROCESS, $c, $by, $note, 'rejected');
            $c->forceFill(['reopen_status' => 'rejected', 'reopen_rejection_reason' => $note, 'reopen_decided_by_id' => $by->id, 'reopen_decided_at' => now()])->save();
            $this->audit($c, $by, 'reopen_rejected', 'Period re-open rejected', ['note' => $note]);
        });

        return $c->refresh();
    }

    public function withdrawReopen(PeriodClose $c, User $by): PeriodClose
    {
        $this->assertSubmitted($c);
        (int) $c->reopen_requested_by_id === (int) $by->id || $this->fail('Only the requester can withdraw a re-open request.');
        DB::transaction(function () use ($c, $by) {
            $c->forceFill(['reopen_status' => 'withdrawn'])->save();
            $this->approvals->withdraw(self::PROCESS, $c, $by);
            $this->audit($c, $by, 'reopen_withdrawn', 'Period re-open request withdrawn');
        });

        return $c->refresh();
    }

    /** Only the bank's latest non-open period can be re-opened: nothing later (including the next fiscal year) may be closed or locked. */
    private function assertLatestNonOpen(Period $p): void
    {
        $fy = FiscalYear::withoutGlobalScopes()->find($p->fiscal_year_id);
        $laterSame = Period::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('fiscal_year_id', $p->fiscal_year_id)
            ->where('sequence', '>', $p->sequence)->where('status', '!=', 'open')->exists();
        $laterYear = Period::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('status', '!=', 'open')
            ->whereIn('fiscal_year_id', FiscalYear::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('starts_on', '>', $fy->starts_on)->select('id'))->exists();
        ($laterSame || $laterYear) && $this->fail('Only the latest closed period can be re-opened; a later period is already closed or locked.', 'reason');
    }

    private function latestClose(Period $p): ?PeriodClose
    {
        return PeriodClose::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('period_id', $p->id)->orderByDesc('id')->first();
    }

    /** A close record for a period that was closed before close records existed (seeded or imported calendars). */
    private function legacyClose(Period $p, string $status, User $by): PeriodClose
    {
        return PeriodClose::withoutGlobalScopes()->create([
            'organization_id' => $p->organization_id, 'created_by_id' => $by->id, 'fiscal_year_id' => $p->fiscal_year_id, 'period_id' => $p->id, 'status' => $status,
            'checklist' => [], 'closed_by_id' => $p->closed_by_id, 'closed_at' => $p->closed_at,
        ]);
    }

    private function assertSubmitted(PeriodClose $c): void
    {
        $c->reopen_status === 'submitted' || $this->fail('This re-open request is not awaiting a decision.');
    }

    private function assertApprover(PeriodClose $c, User $by): void
    {
        (int) $by->organization_id === (int) $c->organization_id || $this->fail('This record belongs to another bank.', 'approval');
        $by->can(self::PERMISSION) || $this->fail('You need the "'.self::PERMISSION.'" permission to decide a re-open.', 'approval');
    }
}
