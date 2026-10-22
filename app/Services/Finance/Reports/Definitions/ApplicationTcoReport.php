<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Ea\EaApplication;
use App\Models\User;
use App\Services\Finance\ApplicationTcoService;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReport;

/** Total cost of ownership per application or business service from the finance ledger, beside the EA modelled figure. */
class ApplicationTcoReport extends FinanceReport
{
    public function key(): string
    {
        return 'application_tco';
    }

    public function title(): string
    {
        return 'Application TCO';
    }

    public function description(): string
    {
        return 'Direct, allocated and cloud cost per application or service, with labour, the contract memo and the EA modelled TCO.';
    }

    public function group(): string
    {
        return 'Cloud & FinOps';
    }

    public function filters(): array
    {
        return ['fiscal_year_id', 'subject', 'basis'];
    }

    public function columns(): array
    {
        return [
            'subject' => ['label' => 'Application / service', 'type' => 'text'], 'direct' => ['label' => 'Direct', 'type' => 'money'], 'allocated' => ['label' => 'Allocated', 'type' => 'money'],
            'cloud' => ['label' => 'Cloud', 'type' => 'money'], 'total' => ['label' => 'Total', 'type' => 'money'], 'labour' => ['label' => 'Labour', 'type' => 'money'],
            'contracts_memo' => ['label' => 'Contracts (memo)', 'type' => 'money'], 'ea_modelled' => ['label' => 'EA modelled', 'type' => 'money'], 'coverage' => ['label' => 'Coverage %', 'type' => 'pct'],
        ];
    }

    public function totals(array $rows): ?array
    {
        $t = parent::totals($rows);
        if ($t) {
            $t['coverage'] = null;
            $t['ea_modelled'] = null;
        }

        return $t;
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $mon = app(MonitoringService::class);
        $fy = $mon->resolveFiscalYear($orgId, ! empty($filters['fiscal_year_id']) ? (int) $filters['fiscal_year_id'] : null);
        if (! $fy) {
            return [];
        }
        $subject = in_array($filters['subject'] ?? null, ['ea_application', 'business_service'], true) ? $filters['subject']
            : (EaApplication::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->exists() ? 'ea_application' : 'business_service');
        $viewer = auth()->user() ?? User::withoutGlobalScopes()->where('organization_id', $orgId)->first();
        $p = app(ApplicationTcoService::class)->portfolio($orgId, $fy->id, $subject, ($filters['basis'] ?? 'actual') === 'budget' ? 'budget' : 'actual', $costCentreIds, $viewer);

        return array_map(fn ($r) => [
            'subject' => trim(($r['code'] ? $r['code'].' ' : '').$r['label']), 'direct' => $r['direct'], 'allocated' => $r['allocated'], 'cloud' => $r['cloud'], 'total' => $r['total'],
            'labour' => $r['labour'], 'contracts_memo' => $r['contracts_memo'], 'ea_modelled' => $r['ea_modelled'], 'coverage' => $r['share_pct'],
        ], $p['rows']);
    }
}
