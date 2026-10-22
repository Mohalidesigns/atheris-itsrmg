<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsMonitoring;
use App\Http\Requests\Finance\MonitorFilterRequest;
use App\Http\Requests\Finance\ReopenPeriodRequest;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\User;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\PeriodCloseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Period close, lock and reopen (FIN-04.05): the checklist page and its actions. */
class PeriodCloseController extends Controller
{
    use PresentsMonitoring;

    public function __construct(private readonly PeriodCloseService $closes, private readonly MonitoringService $monitoring, private readonly FinanceApprovals $approvals) {}

    public function index(MonitorFilterRequest $request)
    {
        Gate::authorize('viewAny', Period::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $fy = $this->monitoring->resolveFiscalYear($orgId, $request->validated('fiscal_year_id'));
        $periods = $fy ? $this->monitoring->periodsOf($orgId, $fy->id)->values() : collect();
        $canClose = $user->can('close it-finance-periods');
        $latestNonOpen = Period::withoutGlobalScopes()->where('fin_periods.organization_id', $orgId)->where('fin_periods.status', '!=', 'open')
            ->join('fin_fiscal_years as y', 'y.id', '=', 'fin_periods.fiscal_year_id')->orderByDesc('y.starts_on')->orderByDesc('fin_periods.sequence')->value('fin_periods.id');
        $names = User::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('name', 'id');
        $selectedId = $request->validated('period_id') ?? ($periods->firstWhere('status', 'open')?->id ?? $periods->last()?->id);
        $sel = $periods->firstWhere('id', $selectedId);

        $selected = null;
        if ($sel) {
            $history = PeriodClose::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $sel->id)->orderByDesc('id')->get();
            $pending = $history->firstWhere('reopen_status', 'submitted');
            $reopen = null;
            if ($pending) {
                $isRequester = (int) $pending->reopen_requested_by_id === (int) $user->id;
                $mayDecide = $user->can('decideReopen', $pending) && ! in_array((int) $user->id, $pending->sodExcludedIds(), true);
                $reopen = [
                    'id' => $pending->id, 'status' => $pending->reopen_status, 'reason' => $pending->reopen_reason, 'requested_by' => $names[$pending->reopen_requested_by_id] ?? null,
                    'trail' => $this->approvals->trail(PeriodCloseService::PROCESS, $pending, $user),
                    'can' => ['approve' => $mayDecide, 'reject' => $mayDecide, 'withdraw' => $isRequester],
                ];
            }
            $selected = [
                'period' => ['id' => $sel->id, 'name' => $sel->name, 'status' => $sel->status, 'ends_on' => $sel->ends_on->toDateString()],
                'checklist' => $this->closes->checklist($sel),
                'history' => $history->map(fn (PeriodClose $c) => [
                    'id' => $c->id, 'status' => $c->status, 'closed_by' => $names[$c->closed_by_id] ?? null, 'closed_at' => $c->closed_at?->toIso8601String(), 'locked_at' => $c->locked_at?->toIso8601String(),
                    'reopen_status' => $c->reopen_status, 'reopen_reason' => $c->reopen_reason, 'requested_by' => $names[$c->reopen_requested_by_id] ?? null,
                    'requested_at' => $c->reopen_requested_at?->toIso8601String(), 'decided_by' => $names[$c->reopen_decided_by_id] ?? null, 'decided_at' => $c->reopen_decided_at?->toIso8601String(),
                    'rejection_reason' => $c->reopen_rejection_reason,
                ])->values()->all(),
                'reopen' => $reopen,
            ];
        }

        return $this->renderMonitoring('Finance/Monitor/PeriodClose', [
            'fiscalYears' => $this->fiscalYearsMini($orgId), 'filters' => ['fiscal_year_id' => $fy?->id, 'period_id' => $sel?->id],
            'periods' => $periods->map(fn (Period $p) => [
                'id' => $p->id, 'name' => $p->name, 'sequence' => $p->sequence, 'status' => $p->status, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(),
                'closed_at' => $p->closed_at?->toIso8601String(), 'closed_by' => $names[$p->closed_by_id] ?? null, 'locked_at' => $p->locked_at?->toIso8601String(),
                'can' => ['close' => $canClose && $p->status === 'open', 'lock' => $canClose && $p->status === 'closed', 'reopen' => $canClose && $p->status !== 'open' && (int) $p->id === (int) $latestNonOpen],
            ])->values()->all(),
            'selected' => $selected, 'can' => ['closePeriods' => $canClose, 'approve' => $user->can('approve it-finance')],
        ]);
    }

    public function checklist(Request $request, Period $period)
    {
        Gate::authorize('view', $period);

        return response()->json($this->closes->checklist($period));
    }

    public function requestReopen(ReopenPeriodRequest $request, Period $period)
    {
        Gate::authorize('requestReopen', $period);
        $this->closes->requestReopen($period, $request->validated('reason'), $request->user());

        return back()->with('success', "Re-open of {$period->name} requested.");
    }

    public function approve(Request $request, PeriodClose $periodClose)
    {
        Gate::authorize('decideReopen', $periodClose);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        return back()->with('success', $this->closes->approveReopen($periodClose, $request->user(), $note));
    }

    public function reject(Request $request, PeriodClose $periodClose)
    {
        Gate::authorize('decideReopen', $periodClose);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->closes->rejectReopen($periodClose, $request->user(), $note);

        return back()->with('success', 'Re-open request rejected.');
    }

    public function withdraw(Request $request, PeriodClose $periodClose)
    {
        Gate::authorize('requestReopen', Period::withoutGlobalScopes()->findOrFail($periodClose->period_id));
        $this->closes->withdrawReopen($periodClose, $request->user());

        return back()->with('success', 'Re-open request withdrawn.');
    }

    /** Approval notifications link here. */
    public function show(Request $request, PeriodClose $periodClose)
    {
        Gate::authorize('view', $periodClose);

        return redirect()->route('finance.monitor.period-close', ['fiscal_year_id' => $periodClose->fiscal_year_id, 'period_id' => $periodClose->period_id]);
    }
}
