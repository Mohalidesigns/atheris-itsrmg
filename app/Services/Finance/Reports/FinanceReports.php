<?php

namespace App\Services\Finance\Reports;

use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\Reports\Definitions\BenefitsRegisterReport;
use App\Services\Finance\Reports\Definitions\BudgetBookReport;
use App\Services\Finance\Reports\Definitions\BvaReport;
use App\Services\Finance\Reports\Definitions\CommitmentsReport;
use App\Services\Finance\Reports\Definitions\ContractRegisterReport;
use App\Services\Finance\Reports\Definitions\InvestmentPortfolioReport;
use App\Services\Finance\Reports\Definitions\InvoiceAgeingReport;
use App\Services\Finance\Reports\Definitions\UnbudgetedSpendReport;
use App\Services\Finance\Reports\Definitions\VarianceCommentaryReport;
use App\Services\Finance\Reports\Definitions\VendorSpendReport;

/** Registry and runner of the finance reports (FIN-07.07). Applies cost-centre visibility, the row cap and amount masking. */
class FinanceReports
{
    public const DEFINITIONS = [
        'budget_book' => BudgetBookReport::class, 'bva' => BvaReport::class,
        'variance_commentary' => VarianceCommentaryReport::class, 'commitments' => CommitmentsReport::class,
        'invoice_ageing' => InvoiceAgeingReport::class, 'unbudgeted' => UnbudgetedSpendReport::class,
        'contract_register' => ContractRegisterReport::class, 'vendor_spend' => VendorSpendReport::class,
        'investment_portfolio' => InvestmentPortfolioReport::class, 'benefits_register' => BenefitsRegisterReport::class,
    ];

    /** @return array<string, class-string<FinanceReport>> the definitions that exist (others land with later work packages) */
    public function available(): array
    {
        return array_filter(self::DEFINITIONS, fn ($c) => class_exists($c));
    }

    public function get(string $key): ?FinanceReport
    {
        $class = $this->available()[$key] ?? null;

        return $class ? new $class : null;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,mixed>>,totals:?array<string,mixed>,row_count:int,truncated:bool}
     */
    public function run(string $key, int $orgId, array $filters, User $viewer): array
    {
        $def = $this->get($key) ?? throw new \InvalidArgumentException("Unknown finance report {$key}.");

        return $this->execute($def, $orgId, $filters, FinancePolicy::visibleCostCentreIds($viewer), $viewer->can('view it-finance-amounts'));
    }

    /** Summary stored by a scheduled report run: the report key and row count only. No money is ever stored on a run, since run summaries are shown to every viewer of reports. @return array<string,mixed> */
    public function figures(string $key, int $orgId): array
    {
        $def = $this->get($key);
        if (! $def) {
            return ['report' => $key, 'rows' => 0, 'generated_at' => now()->toIso8601String()];
        }
        $viewer = auth()->user();
        $r = $this->execute($def, $orgId, [], $viewer ? FinancePolicy::visibleCostCentreIds($viewer) : null, $viewer ? $viewer->can('view it-finance-amounts') : true);

        return ['report' => $key, 'rows' => $r['row_count'], 'generated_at' => now()->toIso8601String()];
    }

    /** @param array<string,mixed> $filters @param array<int,int>|null $ccIds */
    private function execute(FinanceReport $def, int $orgId, array $filters, ?array $ccIds, bool $amounts): array
    {
        $max = (int) config('finance.reports.max_rows', 20000);
        $rows = [];
        $truncated = false;
        foreach ($def->rows($orgId, $filters, $ccIds) as $row) {
            if (count($rows) >= $max) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }
        $totals = $def->totals($rows);
        $cols = $def->columns();
        if (! $amounts) {
            $money = array_keys(array_filter($cols, fn ($c) => in_array($c['type'], ['money', 'pct'], true)));
            $blank = fn (array $r) => array_merge($r, array_fill_keys($money, null));
            $rows = array_map(fn (array $r) => array_merge($blank($r), array_fill_keys($def->rowMoneyKeys($r), null)), $rows);
            $totals = $totals ? $blank($totals) : null;
        }

        return [
            'columns' => array_map(fn ($k, $c) => ['key' => $k, 'label' => $c['label'], 'type' => $c['type']], array_keys($cols), array_values($cols)),
            'rows' => $rows, 'totals' => $totals, 'row_count' => count($rows), 'truncated' => $truncated,
        ];
    }
}
