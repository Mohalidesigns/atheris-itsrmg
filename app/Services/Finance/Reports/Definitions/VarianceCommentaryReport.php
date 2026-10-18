<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\VarianceNote;
use App\Models\Issue;
use App\Models\User;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReport;

/** Every variance note of a fiscal year or period with the explanation, corrective action and sign-off (FIN-04.10). */
class VarianceCommentaryReport extends FinanceReport
{
    public function key(): string
    {
        return 'variance_commentary';
    }

    public function title(): string
    {
        return 'Variance commentary';
    }

    public function description(): string
    {
        return 'Variances against budget with the explanation given, the corrective action, the linked issue and who acknowledged it.';
    }

    public function group(): string
    {
        return 'budget';
    }

    public function filters(): array
    {
        return ['fiscal_year_id', 'period_range', 'cost_centre_id'];
    }

    public function columns(): array
    {
        return [
            'period' => ['label' => 'Period', 'type' => 'text'], 'line' => ['label' => 'Budget line', 'type' => 'text'], 'cost_centre' => ['label' => 'Cost centre', 'type' => 'text'],
            'severity' => ['label' => 'Severity', 'type' => 'text'], 'direction' => ['label' => 'Direction', 'type' => 'text'], 'variance' => ['label' => 'Variance', 'type' => 'money'],
            'variance_pct' => ['label' => 'Variance %', 'type' => 'pct'], 'status' => ['label' => 'Status', 'type' => 'text'], 'explanation' => ['label' => 'Explanation', 'type' => 'text'],
            'corrective_action' => ['label' => 'Corrective action', 'type' => 'text'], 'issue' => ['label' => 'Issue', 'type' => 'text'], 'author' => ['label' => 'Explained by', 'type' => 'text'],
            'acknowledged_by' => ['label' => 'Acknowledged by', 'type' => 'text'],
        ];
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $mon = app(MonitoringService::class);
        $f = $mon->normalise($orgId, $filters);
        if (! $f['fiscal_year_id']) {
            return [];
        }
        $periods = $mon->periodsOf($orgId, (int) $f['fiscal_year_id']);
        $from = $periods->get((int) $f['from_period_id'])?->sequence;
        $to = $periods->get((int) $f['to_period_id'])?->sequence;
        $inRange = ! empty($filters['from_period_id']) || ! empty($filters['to_period_id']);
        $q = VarianceNote::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $f['fiscal_year_id'])
            ->when($costCentreIds !== null, fn ($w) => $w->whereIn('cost_centre_id', $costCentreIds ?: [0]))
            ->when(! empty($f['cost_centre_id']), fn ($w) => $w->whereIn('cost_centre_id', array_merge([(int) $f['cost_centre_id']], $mon->descendants($orgId, (int) $f['cost_centre_id']))));
        $notes = $q->get()->filter(function ($n) use ($periods, $from, $to, $inRange) {
            $seq = $periods->get($n->period_id)?->sequence;

            return ! $inRange || ($seq !== null && $seq >= $from && $seq <= $to);
        })->sortBy(fn ($n) => [$periods->get($n->period_id)?->sequence ?? 0, $n->id])->values();
        $lines = BudgetLine::withoutGlobalScopes()->whereIn('id', $notes->pluck('budget_line_id'))->get(['id', 'budget_id', 'line_no', 'description'])->keyBy('id');
        $codes = Budget::withoutGlobalScopes()->whereIn('id', $lines->pluck('budget_id'))->pluck('budget_code', 'id');
        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'code', 'name'])->keyBy('id');
        $users = User::withoutGlobalScopes()->whereIn('id', $notes->pluck('author_id')->merge($notes->pluck('acknowledged_by_id'))->filter()->unique())->pluck('name', 'id');
        $issues = Issue::withoutGlobalScopes()->whereIn('id', $notes->pluck('issue_id')->filter())->pluck('issue_code', 'id');

        return $notes->map(function ($n) use ($periods, $lines, $codes, $cc, $users, $issues) {
            $l = $lines[$n->budget_line_id] ?? null;

            return [
                'period' => $periods->get($n->period_id)?->name ?? '', 'line' => $l ? trim(($codes[$l->budget_id] ?? '')." #{$l->line_no} {$l->description}") : '',
                'cost_centre' => trim(($cc[$n->cost_centre_id]->code ?? '').' '.($cc[$n->cost_centre_id]->name ?? '')), 'severity' => (string) $n->severity, 'direction' => (string) $n->direction,
                'variance' => bcadd((string) $n->variance_amount, '0', 2), 'variance_pct' => $n->variance_pct === null ? null : bcadd((string) $n->variance_pct, '0', 2), 'status' => (string) $n->status,
                'explanation' => (string) $n->explanation, 'corrective_action' => (string) $n->corrective_action, 'issue' => (string) ($issues[$n->issue_id] ?? ''),
                'author' => (string) ($users[$n->author_id] ?? ''), 'acknowledged_by' => (string) ($users[$n->acknowledged_by_id] ?? ''),
            ];
        })->all();
    }

    public function totals(array $rows): ?array
    {
        return null;
    }
}
