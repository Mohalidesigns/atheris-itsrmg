<?php

namespace App\Services\Issues;

use App\Models\Issue;

/**
 * Issues & Remediation KPIs for the signed-in bank, shared by the Issues Console and the
 * executive dashboard. Relies on Issue's tenant scope.
 */
class IssueMetrics
{
    public function summary(): array
    {
        $open = Issue::whereIn('status', Issue::OPEN_STATUSES);
        $done = Issue::whereIn('status', Issue::DONE_STATUSES)->whereNotNull('remediated_at')->whereNotNull('sla_due_at')
            ->where('remediated_at', '>=', now()->subDays(180))
            ->get(['created_at', 'remediated_at', 'sla_due_at']);

        return [
            'open' => (clone $open)->count(),
            'open_critical' => (clone $open)->where('severity', 'critical')->count(),
            'overdue' => Issue::overdue()->count(),
            'escalated' => (clone $open)->where('escalation_level', '>', 0)->count(),
            'blocked' => Issue::where('status', 'blocked')->count(),
            'awaiting_verification' => Issue::where('status', 'remediated')->count(),
            'unassigned' => (clone $open)->whereNull('owner_id')->count(),
            'ticketed' => (clone $open)->whereNotNull('external_ticket_id')->count(),
            // Of issues remediated in the last 180 days, the share fixed within their SLA.
            'sla_compliance_pct' => $done->isEmpty() ? null
                : (int) round(100 * $done->filter(fn ($i) => $i->remediated_at->lte($i->sla_due_at))->count() / $done->count()),
            'mean_days_to_remediate' => $done->isEmpty() ? null
                : round($done->avg(fn ($i) => max(0, $i->created_at->diffInHours($i->remediated_at)) / 24), 1),
        ];
    }

    /** Open issues by source type, for the "where do findings come from" bar. */
    public function openBySource(): array
    {
        return Issue::whereIn('status', Issue::OPEN_STATUSES)
            ->selectRaw('source_type, count(*) as c')->groupBy('source_type')->orderByDesc('c')
            ->pluck('c', 'source_type')
            ->mapWithKeys(fn ($c, $type) => [Issue::SOURCES[$type]['label'] ?? $type => $c])
            ->all();
    }

    /** The most overdue open issues. */
    public function mostOverdue(int $limit = 5): array
    {
        return Issue::overdue()->with('owner:id,name')->orderBy('sla_due_at')->limit($limit)
            ->get(['id', 'issue_code', 'title', 'severity', 'owner_id', 'sla_due_at', 'escalation_level'])
            ->map(fn (Issue $i) => [
                'id' => $i->id, 'code' => $i->issue_code, 'title' => $i->title, 'severity' => $i->severity,
                'owner' => $i->owner?->name, 'days_overdue' => (int) $i->sla_due_at->diffInDays(now()),
                'escalation_level' => $i->escalation_level,
            ])->all();
    }
}
