<?php

namespace App\Services\Kri;

use App\Services\Finance\FinanceKriSource;
use Illuminate\Support\Facades\DB;

/**
 * KRIs in the Nigerian pack that the platform can measure from the bank's own records
 * (ATH-VAL-014). Each returns [value, commentary]. The rest of the pack (channel availability,
 * fraud, SIEM, DLP, phishing…) comes from systems Atheris does not hold and is recorded by the
 * KRI owner.
 */
class KriMeasures
{
    public const MEASURES = [
        'KRI-IT-PATCH' => 'overdueCriticalVulnerabilities',
        'KRI-VUL-CRIT' => 'openCriticalVulnerabilities',
        'KRI-IT-EOL' => 'endOfLifeAssets',
        'KRI-POL-ATT' => 'attestationCoverage',
        'KRI-POL-EXP' => 'policiesPastReview',
        'KRI-INC-OPEN' => 'openCriticalIncidents',
        'KRI-INC-MTTR' => 'criticalIncidentMttr',
        'KRI-TPR-GRADE' => 'vendorsBelowC',
        'KRI-TPR-BREACH' => 'vendorBreachEvents',
        'KRI-COMP-CBN' => 'openCircularActions',
        'KRI-BCP-RTO' => 'drExercisesOverdue',
        'KRI-OPS-CHG' => 'failedChanges',
        'KRI-FIN-VAR' => 'financeBudgetVariance',
        'KRI-FIN-UNB' => 'financeUnbudgetedSpend',
        'KRI-FIN-INV' => 'financeOverdueInvoices',
        'KRI-FIN-NOT' => 'financeContractsPastNotice',
        'KRI-FIN-CLD' => 'financeCloudAnomalies',
    ];

    public function supports(string $code): bool
    {
        return isset(self::MEASURES[$code]);
    }

    /** @return array{0: float, 1: string} */
    public function measure(string $code, int $orgId, $at = null): array
    {
        return $this->{self::MEASURES[$code]}($orgId);
    }

    private function overdueCriticalVulnerabilities(int $orgId): array
    {
        $n = DB::table('vulnerabilities')->where('organization_id', $orgId)->whereNull('deleted_at')->where('severity', 'critical')
            ->whereIn('status', ['open', 'in_progress'])->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->count();

        return [$n, "{$n} critical vulnerabilities open past their remediation due date (vulnerability register)."];
    }

    private function openCriticalVulnerabilities(int $orgId): array
    {
        $n = DB::table('vulnerabilities')->where('organization_id', $orgId)->whereNull('deleted_at')->where('severity', 'critical')
            ->whereIn('status', ['open', 'in_progress'])->count();

        return [$n, "{$n} critical vulnerabilities open (vulnerability register)."];
    }

    private function endOfLifeAssets(int $orgId): array
    {
        $names = DB::table('assets')->where('organization_id', $orgId)->whereNull('deleted_at')->where('status', 'active')
            ->whereNotNull('end_of_life')->where('end_of_life', '<', today()->toDateString())->pluck('name');

        return [$names->count(), $names->isEmpty() ? 'No active asset is past end of life.' : 'Past end of life and still active: '.$names->take(5)->implode(', ').'.'];
    }

    private function attestationCoverage(int $orgId): array
    {
        $rows = DB::table('policy_attestations')->where('organization_id', $orgId)->whereIn('status', ['pending', 'completed'])->pluck('status');
        $pct = $rows->count() ? round(100 * $rows->filter(fn ($s) => $s === 'completed')->count() / $rows->count(), 1) : 100;

        return [$pct, "{$rows->filter(fn ($s) => $s === 'completed')->count()} of {$rows->count()} attestations completed (waivers excluded)."];
    }

    private function policiesPastReview(int $orgId): array
    {
        $n = DB::table('policies')->where('organization_id', $orgId)->whereNull('deleted_at')->where('status', 'active')
            ->whereNotNull('review_date')->where('review_date', '<', today()->toDateString())->count();

        return [$n, "{$n} active policies past their review date."];
    }

    private function openCriticalIncidents(int $orgId): array
    {
        $n = DB::table('incidents')->where('organization_id', $orgId)->whereNull('deleted_at')->where('severity', 'critical')
            ->whereNull('resolved_at')->where('status', '!=', 'closed')->count();

        return [$n, "{$n} critical incidents not yet resolved."];
    }

    private function criticalIncidentMttr(int $orgId): array
    {
        $rows = DB::table('incidents')->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('severity', ['critical', 'high'])
            ->whereNotNull('detected_at')->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(90))->get(['detected_at', 'resolved_at']);
        if ($rows->isEmpty()) {
            return [0, 'No critical or high incident resolved in the last 90 days.'];
        }
        $hours = round($rows->avg(fn ($i) => now()->parse($i->detected_at)->diffInMinutes(now()->parse($i->resolved_at)) / 60), 1);

        return [$hours, "Mean {$hours}h from detection to resolution over {$rows->count()} critical/high incidents (90 days)."];
    }

    private function vendorsBelowC(int $orgId): array
    {
        $floor = (int) config('tprm.grades.C', 70);
        $low = DB::table('tprm_security_ratings')->where('organization_id', $orgId)->orderByDesc('captured_at')->get(['vendor_id', 'rating_value'])
            ->unique('vendor_id')->filter(fn ($r) => $r->rating_value < $floor);

        return [$low->count(), "{$low->count()} vendors whose latest security rating is below {$floor} (grade D or F)."];
    }

    private function vendorBreachEvents(int $orgId): array
    {
        $n = DB::table('tprm_breach_events')->where('organization_id', $orgId)->where('status', '!=', 'dismissed')
            ->where('discovered_at', '>=', now()->subDays(30))->count();

        return [$n, "{$n} vendor breach events in the last 30 days (dismissed events excluded)."];
    }

    private function openCircularActions(int $orgId): array
    {
        $n = DB::table('circular_assessments')->where('organization_id', $orgId)->where('status', 'assessed')->whereNull('actioned_at')->count();

        return [$n, "{$n} applicable CBN circulars assessed but not yet actioned."];
    }

    private function drExercisesOverdue(int $orgId): array
    {
        $runbooks = DB::table('dr_runbooks')->where('organization_id', $orgId)->pluck('id');
        $recent = DB::table('dr_exercises')->whereIn('runbook_id', $runbooks)->whereNotNull('rto_achieved_min')
            ->where('started_at', '>=', now()->subDays(92))->pluck('runbook_id')->unique();
        $n = $runbooks->diff($recent)->count();

        return [$n, "{$n} of {$runbooks->count()} DR runbooks not exercised in the last quarter."];
    }

    private function financeBudgetVariance(int $orgId): array
    {
        return app(FinanceKriSource::class)->budgetVariancePct($orgId);
    }

    private function financeUnbudgetedSpend(int $orgId): array
    {
        return app(FinanceKriSource::class)->unbudgetedSpendPct($orgId);
    }

    private function financeOverdueInvoices(int $orgId): array
    {
        return app(FinanceKriSource::class)->overdueInvoices($orgId);
    }

    private function financeContractsPastNotice(int $orgId): array
    {
        return app(FinanceKriSource::class)->contractsPastNotice($orgId);
    }

    private function financeCloudAnomalies(int $orgId): array
    {
        return app(FinanceKriSource::class)->cloudAnomalies($orgId);
    }

    private function failedChanges(int $orgId): array
    {
        $n = DB::table('change_requests')->where('organization_id', $orgId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('status', ['failed', 'backed_out'])->orWhereIn('closure_code', ['failed', 'backed_out']))
            ->where(fn ($q) => $q->where('actual_end_at', '>=', now()->subDays(30))->orWhere('closed_at', '>=', now()->subDays(30)))->count();

        return [$n, "{$n} changes failed or backed out in the last 30 days."];
    }
}
