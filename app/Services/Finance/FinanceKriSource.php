<?php

namespace App\Services\Finance;

use App\Models\Finance\Actual;
use App\Models\Finance\Contract;

/** Measures of the finance KRIs (FIN-04.08); KriMeasures registers them. Each returns [value, note]. */
class FinanceKriSource
{
    public function __construct(private readonly FinanceMetrics $metrics, private readonly MonitoringService $monitoring) {}

    /** @return array{0:float,1:string} absolute YTD variance % of the current fiscal year */
    public function budgetVariancePct(int $orgId): array
    {
        $fy = $this->monitoring->resolveFiscalYear($orgId, null);
        if (! $fy) {
            return [0.0, 'No fiscal year.'];
        }
        $s = $this->metrics->summary($orgId, $fy->id);

        return [abs((float) ($s['variance_pct'] ?? 0)), "Year-to-date variance of {$fy->year_label} against budget."];
    }

    /** @return array{0:float,1:string} unbudgeted actual spend as a % of actual year to date */
    public function unbudgetedSpendPct(int $orgId): array
    {
        $fy = $this->monitoring->resolveFiscalYear($orgId, null);
        if (! $fy) {
            return [0.0, 'No fiscal year.'];
        }
        $asOf = $this->monitoring->asOfPeriod($orgId, $fy->id);
        $ids = $asOf ? $this->monitoring->periodsOf($orgId, $fy->id)->filter(fn ($p) => $p->sequence <= $asOf->sequence)->keys()->all() : [];
        // Unbudgeted postings over the same periods and ledger as the dashboard's actual_ytd (one denominator).
        $unb = (string) Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fy->id)->where('match_status', 'unbudgeted')
            ->whereIn('period_id', $ids ?: [0])->sum('base_amount');
        $ytd = $this->metrics->summary($orgId, $fy->id)['actual_ytd'];
        $pct = bccomp($ytd, '0', 2) > 0 ? (float) bcmul(bcdiv($unb, $ytd, 8), '100', 4) : 0.0;

        return [$pct, 'Unbudgeted actuals as a share of year-to-date spend.'];
    }

    /** @return array{0:float,1:string} */
    public function overdueInvoices(int $orgId): array
    {
        return [(float) $this->metrics->overdueInvoices($orgId), 'Unpaid, undisputed invoices past their due date.'];
    }

    /** @return array{0:float,1:string} live contracts past their notice deadline with no decision */
    public function contractsPastNotice(int $orgId): array
    {
        $n = Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->passed(FinanceClock::today())->count();

        return [(float) $n, 'Contracts whose notice deadline or end date has passed with no decision recorded.'];
    }
}
