<?php

namespace App\Services\Reports;

use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Services\ComplianceScoreService;

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

    public static function summary(string $type, int $orgId): array
    {
        $risk = self::riskStats();

        return match ($type) {
            'risks' => ['risks' => $risk, 'treatments' => self::treatmentStats()],
            'compliance' => ['overall_compliance_score' => app(ComplianceScoreService::class)->getOverallComplianceScore($orgId)],
            default => [
                'risks' => $risk,
                'overall_compliance_score' => app(ComplianceScoreService::class)->getOverallComplianceScore($orgId),
            ],
        };
    }
}
