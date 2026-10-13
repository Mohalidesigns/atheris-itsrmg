<?php

namespace App\Services\Change;

use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeRequest;

/**
 * Change Management KPIs for the signed-in bank (ATH-CHG-001 §9), shared by the register, the
 * calendar and the executive dashboard. Relies on the tenant scopes.
 */
class ChangeMetrics
{
    public function summary(): array
    {
        $since90 = now()->subDays(90);
        $since30 = now()->subDays(30);

        $raised90 = ChangeRequest::whereNotNull('submitted_at')->where('submitted_at', '>=', $since90);
        $closedWithAttempt = ChangeRequest::where('status', 'closed')->where('closed_at', '>=', $since90)
            ->whereIn('closure_code', ['implemented', 'failed', 'backed_out']);
        $successful = (clone $closedWithAttempt)->where('closure_code', 'implemented')
            ->where(fn ($q) => $q->whereNull('pir_outcome')->orWhere('pir_outcome', 'successful'));

        $leadTimes = ChangeRequest::whereNotNull('submitted_at')->whereNotNull('approved_at')
            ->where('approved_at', '>=', $since90)->get(['submitted_at', 'approved_at'])
            ->map(fn ($c) => max(0, $c->submitted_at->diffInMinutes($c->approved_at)) / 1440);

        $pendingCurrent = $this->currentPendingApprovals();

        return [
            'open' => ChangeRequest::whereIn('status', ChangeRequest::OPEN_STATUSES)->count(),
            'awaiting_approval' => ChangeRequest::where('status', 'in_approval')->count(),
            'scheduled_7d' => ChangeRequest::whereIn('status', ['approved', 'scheduled'])
                ->whereBetween('scheduled_start_at', [now(), now()->addDays(7)])->count(),
            'implementing' => ChangeRequest::where('status', 'implementing')->count(),
            'emergency_30d' => ChangeRequest::where('change_type', 'emergency')->where('created_at', '>=', $since30)->count(),
            'emergency_ratio_pct' => ($all = (clone $raised90)->count()) ? (int) round(100 * (clone $raised90)->where('change_type', 'emergency')->count() / $all) : null,
            'success_rate_pct' => ($attempted = (clone $closedWithAttempt)->count()) ? (int) round(100 * $successful->count() / $attempted) : null,
            'failed_or_backed_out_90d' => ChangeRequest::whereIn('closure_code', ['failed', 'backed_out'])
                ->whereIn('status', ['review', 'closed'])->where('actual_end_at', '>=', $since90)->count(),
            'overdue_approvals' => $pendingCurrent->filter(fn (ChangeApproval $a) => $a->due_at && $a->due_at->lt(now()))->count(),
            'unauthorised_emergency' => ChangeRequest::where('started_before_approval', true)->whereNull('approved_at')
                ->whereIn('status', ChangeRequest::OPEN_STATUSES)
                ->where('actual_start_at', '<', now()->subHours(24))
                ->whereHas('approvals', fn ($q) => $q->where('status', 'pending'))->count(),
            'mean_lead_time_days' => $leadTimes->isEmpty() ? null : round($leadTimes->avg(), 1),
            'cab_pending' => $pendingCurrent->where('approver_type', 'board')->count(),
            'in_freeze_overrides_90d' => ChangeRequest::whereNotNull('freeze_override_reason')->where('updated_at', '>=', $since90)->count(),
        ];
    }

    /** The next changes on the forward schedule. */
    public function upcoming(int $limit = 5): array
    {
        return ChangeRequest::upcoming()->with('implementer:id,name')->limit($limit)->get()
            ->map(fn (ChangeRequest $c) => [
                'id' => $c->id, 'change_code' => $c->change_code, 'title' => $c->title, 'change_type' => $c->change_type,
                'risk_level' => $c->risk_level, 'status' => $c->status, 'category' => $c->category,
                'scheduled_start_at' => $c->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $c->scheduled_end_at?->toIso8601String(),
                'outage_required' => $c->outage_required, 'implementer' => $c->implementer?->name,
            ])->all();
    }

    public function byType(): array
    {
        return ChangeRequest::whereIn('status', ChangeRequest::OPEN_STATUSES)
            ->selectRaw('change_type, count(*) as c')->groupBy('change_type')->pluck('c', 'change_type')->all();
    }

    public function byRisk(): array
    {
        return ChangeRequest::whereIn('status', ChangeRequest::OPEN_STATUSES)
            ->selectRaw('risk_level, count(*) as c')->groupBy('risk_level')->pluck('c', 'risk_level')->all();
    }

    /** The current (lowest-sequence) pending approval of every change still in approval. */
    private function currentPendingApprovals()
    {
        return ChangeApproval::where('status', 'pending')
            ->whereHas('change', fn ($q) => $q->where(fn ($o) => $o->where('status', 'in_approval')->orWhere(fn ($w) => $w->where('started_before_approval', true)->whereNull('approved_at'))))
            ->orderBy('sequence')->orderBy('id')->get()
            ->groupBy('change_request_id')->map->first()->values();
    }
}
