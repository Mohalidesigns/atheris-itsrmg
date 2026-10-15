<?php

namespace App\Services\Issues;

use App\Models\Capa\CapaAction;
use App\Models\Issue;

/**
 * CAPA KPIs (IT-GOV-CAPA-001 §6) for the signed-in bank; relies on the tenant scopes of Issue and
 * CapaAction. Reviewed at the monthly governance meeting and rolled up for the ISO 27001
 * management review and CBN compliance reporting.
 */
class CapaMetrics
{
    public function summary(): array
    {
        $targets = config('capa.kpi_targets');

        // Overdue rate: action items past their deadline (still open after it, or finished late).
        $actions = CapaAction::whereNotNull('due_date')->where('status', '!=', 'cancelled')
            ->get(['id', 'status', 'due_date', 'completed_at']);
        $overdueActions = $actions->filter(fn (CapaAction $a) => $a->isOverdue())->count();

        // Recurrence rate: CAPAs closed in the last 12 months that came back within 12 months of closure.
        $closed = Issue::capa()->whereNotNull('closed_at')->where('closed_at', '>=', now()->subMonths(12))
            ->with('recurrences:id,recurrence_of_id,created_at')->get(['id', 'closed_at']);
        $recurred = $closed->filter(fn (Issue $i) => $i->recurrences
            ->contains(fn (Issue $r) => $r->created_at->gte($i->closed_at) && $r->created_at->lte($i->closed_at->copy()->addMonths(12))))->count();

        // MTTR: calendar days from identification (raised) to implementation (stage 5 done).
        $implemented = Issue::capa()->whereNotNull('implemented_at')->get(['id', 'created_at', 'implemented_at']);

        $byStage = Issue::capa()->selectRaw('capa_stage, count(*) as c')->groupBy('capa_stage')->pluck('c', 'capa_stage');

        return [
            'total' => Issue::capa()->count(),
            'open' => Issue::capa()->where('capa_stage', '!=', 'closed')->count(),
            'actions_total' => $actions->count(),
            'actions_overdue' => $overdueActions,
            'overdue_rate_pct' => $actions->isEmpty() ? null : round(100 * $overdueActions / $actions->count(), 1),
            'overdue_rate_target_pct' => $targets['overdue_rate_pct'],
            'closed_12m' => $closed->count(),
            'recurred_12m' => $recurred,
            'recurrence_rate_pct' => $closed->isEmpty() ? null : round(100 * $recurred / $closed->count(), 1),
            'recurrence_rate_target_pct' => $targets['recurrence_rate_pct'],
            'mttr_days' => $implemented->isEmpty() ? null
                : round($implemented->avg(fn (Issue $i) => max(0, $i->created_at->diffInHours($i->implemented_at)) / 24), 1),
            'by_framework' => collect(config('capa.frameworks'))->map(fn ($f, $key) => 0)
                ->merge(Issue::capa()->selectRaw('capa_framework, count(*) as c')->groupBy('capa_framework')->pluck('c', 'capa_framework')->map(fn ($c) => (int) $c))
                ->all(),
            'by_stage' => collect(config('capa.stages'))->map(fn ($label, $key) => (int) ($byStage[$key] ?? 0))->all(),
            'verifications_due' => Issue::capa()->where('capa_stage', 'verification')->where('verification_due_at', '<=', now())->count(),
            'in_settling' => Issue::capa()->where('capa_stage', 'verification')->where('verification_due_at', '>', now())->count(),
        ];
    }
}
