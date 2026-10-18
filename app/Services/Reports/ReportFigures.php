<?php

namespace App\Services\Reports;

use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Services\ComplianceScoreService;
use App\Services\Finance\FinanceMetrics;
use App\Services\Finance\Reports\FinanceReports;

/**
 * Key report figures, computed under the signed-in user's tenant scope. Shared by the report
 * pages and the scheduled-report runner so both always agree.
 */
class ReportFigures
{
    /** Risk statuses that still need work (mitigated / accepted / closed / archived do not). */
    public const OPEN_RISK_STATUSES = ['identified', 'assessed', 'treating', 'under_review'];

    /** Treatments that exist but have not started execution. */
    public const NOT_STARTED_TREATMENT_STATUSES = ['draft', 'submitted', 'approved'];

    public static function riskStats(): array
    {
        return [
            'total' => Risk::active()->count(),
            'critical' => Risk::active()->where('inherent_rating', 'critical')->count(),
            'high' => Risk::active()->where('inherent_rating', 'high')->count(),
            'medium' => Risk::active()->where('inherent_rating', 'medium')->count(),
            'low' => Risk::active()->where('inherent_rating', 'low')->count(),
            'open' => Risk::whereIn('status', self::OPEN_RISK_STATUSES)->count(),
            'treating' => Risk::where('status', 'treating')->count(),
        ];
    }

    public static function treatmentStats(): array
    {
        return [
            'not_started' => RiskTreatment::whereIn('status', self::NOT_STARTED_TREATMENT_STATUSES)->count(),
            'in_progress' => RiskTreatment::where('status', 'in_progress')->count(),
            'completed' => RiskTreatment::where('status', 'completed')->count(),
            'overdue' => RiskTreatment::where('status', '!=', 'completed')->where('due_date', '<', now())->count(),
        ];
    }

    /** Run summaries are visible to every viewer of reports: keep period and counts, never an amount or a percentage. */
    private static function financeRunSummary(array $board): array
    {
        return ['report' => 'finance', 'fiscal_year' => $board['fiscal_year'] ?? null, 'as_of_period' => $board['as_of_period'] ?? null,
            'red' => $board['red'] ?? 0, 'amber' => $board['amber'] ?? 0, 'unexplained_red' => $board['unexplained_red'] ?? 0, 'generated_at' => now()->toIso8601String()];
    }

    public static function summary(string $type, int $orgId): array
    {
        if (str_starts_with($type, 'finance.')) { // a match arm cannot test a prefix
            return app(FinanceReports::class)->figures(substr($type, 8), $orgId);
        }
        $risk = self::riskStats();

        return match ($type) {
            'risks' => ['risks' => $risk, 'treatments' => self::treatmentStats()],
            'compliance' => ['overall_compliance_score' => app(ComplianceScoreService::class)->getOverallComplianceScore($orgId)],
            'finance' => self::financeRunSummary(app(FinanceMetrics::class)->boardFigures($orgId)),
            default => [
                'risks' => $risk,
                'overall_compliance_score' => app(ComplianceScoreService::class)->getOverallComplianceScore($orgId),
            ],
        };
    }
}
