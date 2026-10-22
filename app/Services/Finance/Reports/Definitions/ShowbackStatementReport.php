<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Allocation;
use App\Models\User;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReport;
use App\Services\Finance\ShowbackService;

/** IT cost shown back to each business unit or service: direct, allocated and total, with the retained IT cost as the closing row. */
class ShowbackStatementReport extends FinanceReport
{
    public function key(): string
    {
        return 'showback_statement';
    }

    public function title(): string
    {
        return 'Showback statement';
    }

    public function description(): string
    {
        return 'Direct and allocated IT cost per target over a period range, by cost pool, with the cost IT retains.';
    }

    public function group(): string
    {
        return 'Cloud & FinOps';
    }

    public function filters(): array
    {
        return ['fiscal_year_id', 'period_range', 'target_type', 'basis'];
    }

    public function columns(): array
    {
        return [
            'target' => ['label' => 'Target', 'type' => 'text'], 'direct' => ['label' => 'Direct', 'type' => 'money'], 'allocated' => ['label' => 'Allocated', 'type' => 'money'],
            'total' => ['label' => 'Total', 'type' => 'money'], 'by_pool' => ['label' => 'By cost pool', 'type' => 'text'],
        ];
    }

    /** The retained row is part of the rows, so the sum of the rows is the IT total. */
    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $mon = app(MonitoringService::class);
        $fy = $mon->resolveFiscalYear($orgId, ! empty($filters['fiscal_year_id']) ? (int) $filters['fiscal_year_id'] : null);
        if (! $fy) {
            return [];
        }
        $periods = $mon->periodsOf($orgId, $fy->id)->sortBy('sequence')->values();
        if ($periods->isEmpty()) {
            return [];
        }
        $asOf = $mon->asOfPeriod($orgId, $fy->id);
        $from = ! empty($filters['from_period_id']) ? (int) $filters['from_period_id'] : (int) $periods->first()->id;
        $to = ! empty($filters['to_period_id']) ? (int) $filters['to_period_id'] : (int) ($asOf?->id ?? $periods->last()->id);
        $type = in_array($filters['target_type'] ?? null, Allocation::TARGET_TYPES, true) ? $filters['target_type'] : Allocation::TARGET_TYPES[0];
        $viewer = auth()->user() ?? User::withoutGlobalScopes()->where('organization_id', $orgId)->first();
        $s = app(ShowbackService::class)->statement($orgId, $type, $fy->id, $from, $to, ($filters['basis'] ?? 'actual') === 'budget' ? 'budget' : 'actual', $costCentreIds, $viewer);

        $rows = [];
        foreach ($s['rows'] as $r) {
            $pools = collect($r['by_pool'])->map(fn ($amt, $code) => "{$code}: {$amt}")->implode('; ');
            $rows[] = ['target' => $r['label'], 'direct' => $r['direct'], 'allocated' => $r['allocated'], 'total' => $r['total'], 'by_pool' => $pools];
        }
        $rows[] = ['target' => 'Retained by IT', 'direct' => $s['totals']['retained'], 'allocated' => '0.00', 'total' => $s['totals']['retained'], 'by_pool' => ''];

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function totals(array $rows): ?array
    {
        $t = parent::totals($rows);
        $t && $t['target'] = 'IT total';

        return $t;
    }

    /** The pool breakdown embeds amounts. */
    public function rowMoneyKeys(array $row): array
    {
        return ['by_pool'];
    }
}
