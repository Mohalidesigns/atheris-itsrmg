<?php

namespace App\Services\Finance;

use App\Models\Finance\Actual;
use App\Models\Finance\CloudAnomaly;
use App\Models\Finance\Contract;
use App\Models\Kri;
use App\Models\User;

/** Measures of the finance KRIs (FIN-04.08); KriMeasures registers them. Each returns [value, note]. */
class FinanceKriSource
{
    public function __construct(private readonly FinanceMetrics $metrics, private readonly MonitoringService $monitoring) {}

    /** Finance KRI definitions, provisioned for every bank by FinanceSettings::ensureDefaults (P7-18). Idempotent. */
    public static function ensureDefinitions(int $orgId): void
    {
        $defs = [
            ['KRI-FIN-VAR', 'IT budget variance (YTD)', 'Absolute year-to-date variance of IT spend against budget.', '%', 5, 10, 20],
            ['KRI-FIN-UNB', 'Unbudgeted IT spend', 'Unbudgeted actual spend as a share of year-to-date IT spend.', '%', 2, 5, 10],
            ['KRI-FIN-INV', 'Overdue IT invoices', 'Unpaid, undisputed IT invoices past their due date.', 'count', 0, 3, 10],
            ['KRI-FIN-NOT', 'Contracts past notice deadline', 'Live contracts whose notice deadline has passed with no renewal decision.', 'count', 0, 1, 3],
            ['KRI-FIN-CLD', 'Cloud cost anomalies (30 days)', 'Cloud cost anomaly events detected in the last 30 days that are still open or acknowledged.', 'count', 0, 2, 5],
        ];
        $existing = Kri::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('code', array_column($defs, 0))->pluck('code')->all();
        if (count($existing) === count($defs)) {
            return;
        }
        $cfo = User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)
            ->where(fn ($q) => $q->where('job_title', 'like', '%Chief Financial%')->orWhere('job_title', 'like', '%CFO%'))->orderBy('id')->first();
        foreach ($defs as [$code, $name, $desc, $unit, $g, $a, $r]) {
            if (in_array($code, $existing, true)) {
                continue;
            }
            $kri = Kri::withoutGlobalScopes()->firstOrNew(['organization_id' => $orgId, 'code' => $code]);
            if ($kri->exists) {
                continue; // never overwrite a bank's tuned thresholds or owner
            }
            $kri->fill([
                'name' => $name, 'description' => $desc, 'category' => 'finance', 'unit' => $unit, 'direction' => 'higher_worse', 'threshold_green' => $g, 'threshold_amber' => $a,
                'threshold_red' => $r, 'frequency' => 'monthly', 'source' => 'platform', 'owner_id' => $cfo?->id,
            ])->save();
        }
    }

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

    /** @return array{0:float,1:string} open and acknowledged cloud anomalies detected in the KRI window */
    public function cloudAnomalies(int $orgId): array
    {
        $days = (int) config('finance.cloud.anomaly.kri_window_days', 30);
        // Distinct (account, detected_on) events: one spike raises several anomalies (service, tag, total) but is one event.
        $n = CloudAnomaly::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', CloudAnomaly::OPEN)
            ->whereDate('detected_on', '>=', FinanceClock::today()->subDays($days)->toDateString())->get(['cloud_account_id', 'detected_on'])
            ->map(fn ($x) => $x->cloud_account_id.'|'.$x->detected_on->toDateString())->unique()->count();

        return [(float) $n, "{$n} open cloud cost anomaly events in the last {$days} days (FinOps)."];
    }
}
