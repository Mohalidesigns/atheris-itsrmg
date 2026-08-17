<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BcpPlan;
use App\Models\Control;
use App\Models\DataBreach;
use App\Models\Gap;
use App\Models\Incident;
use App\Models\Policy;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\RiskTreatment;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Services\ComplianceScoreService;
use App\Services\RiskScoringService;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function __construct(
        private RiskScoringService $riskScoringService,
        private ComplianceScoreService $complianceScoreService
    ) {}

    public function executive()
    {
        $orgId = auth()->user()->organization_id;

        // Risk stats
        $riskStats = [
            'total' => Risk::count(),
            'critical' => Risk::where('inherent_rating', 'critical')->count(),
            'high' => Risk::where('inherent_rating', 'high')->count(),
            'medium' => Risk::where('inherent_rating', 'medium')->count(),
            'low' => Risk::where('inherent_rating', 'low')->count(),
            'open' => Risk::whereNotIn('status', ['closed', 'archived'])->count(),
        ];

        // Compliance stats
        $complianceStats = $this->complianceScoreService->getComplianceStats($orgId);

        // Security stats
        $securityStats = [
            'open_vulnerabilities' => Vulnerability::whereNotIn('status', ['remediated', 'closed', 'accepted'])->count(),
            'critical_vulnerabilities' => Vulnerability::where('severity', 'critical')
                ->whereNotIn('status', ['remediated', 'closed', 'accepted'])->count(),
            'active_incidents' => Incident::whereNotIn('status', ['resolved', 'closed'])->count(),
            'active_breaches' => DataBreach::whereNotIn('status', ['closed', 'resolved'])->count(),
        ];

        // Control stats
        $controlStats = $this->complianceScoreService->getControlEffectivenessStats($orgId);

        // Asset & vendor stats
        $assetStats = [
            'total_assets' => Asset::count(),
            'total_vendors' => Vendor::count(),
        ];

        // Policy stats
        $policyStats = [
            'published' => Policy::where('status', 'published')->count(),
        ];

        // BCP stats
        $bcpStats = [
            'total_plans' => BcpPlan::count(),
        ];

        // Heat map data for executive view
        $heatMapData = $this->riskScoringService->getHeatMapData($orgId, 'inherent');

        // Risk distribution
        $distribution = $this->riskScoringService->getRiskDistribution($orgId);

        // Open gaps
        $openGaps = Gap::whereNotIn('status', ['remediated', 'closed', 'accepted'])->count();

        return Inertia::render('Reports/Executive', [
            'riskStats' => $riskStats,
            'complianceStats' => $complianceStats,
            'securityStats' => $securityStats,
            'controlStats' => $controlStats,
            'assetStats' => $assetStats,
            'policyStats' => $policyStats,
            'bcpStats' => $bcpStats,
            'heatMapData' => $heatMapData,
            'distribution' => $distribution,
            'openGaps' => $openGaps,
        ]);
    }

    public function riskReport()
    {
        $orgId = auth()->user()->organization_id;

        // Distribution by rating
        $distribution = $this->riskScoringService->getRiskDistribution($orgId);

        // Distribution by category
        $categoryDistribution = Risk::whereNotIn('status', ['closed', 'archived'])
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as count')
            ->groupBy('category_id')
            ->get()
            ->map(function ($item) {
                $category = RiskCategory::find($item->category_id);
                return [
                    'name' => $category?->name ?? 'Uncategorized',
                    'count' => $item->count,
                    'color' => $category?->color ?? '#718096',
                ];
            });

        // Distribution by status
        $statusDistribution = [];
        foreach (Risk::STATUSES as $status) {
            $count = Risk::where('status', $status)->count();
            if ($count > 0) {
                $statusDistribution[] = [
                    'name' => ucfirst($status),
                    'count' => $count,
                ];
            }
        }

        // Heat map data
        $heatMapData = $this->riskScoringService->getHeatMapData($orgId, 'inherent');

        // Top 10 risks by score
        $topRisks = Risk::with(['owner:id,name', 'category:id,name,color'])
            ->whereNotNull('inherent_score')
            ->whereNotIn('status', ['closed', 'archived'])
            ->orderByDesc('inherent_score')
            ->limit(10)
            ->get(['id', 'risk_id_code', 'title', 'inherent_score', 'inherent_rating', 'status', 'category_id', 'risk_owner_id']);

        // Treatment progress
        $treatmentStats = [
            'not_started' => RiskTreatment::where('status', 'not_started')->count(),
            'in_progress' => RiskTreatment::where('status', 'in_progress')->count(),
            'completed' => RiskTreatment::where('status', 'completed')->count(),
            'overdue' => RiskTreatment::where('status', '!=', 'completed')
                ->where('due_date', '<', now())->count(),
        ];

        // Stats summary
        $stats = [
            'total' => Risk::count(),
            'critical' => Risk::where('inherent_rating', 'critical')->count(),
            'high' => Risk::where('inherent_rating', 'high')->count(),
            'medium' => Risk::where('inherent_rating', 'medium')->count(),
            'low' => Risk::where('inherent_rating', 'low')->count(),
            'treating' => Risk::where('status', 'treating')->count(),
        ];

        return Inertia::render('Reports/RiskReport', [
            'stats' => $stats,
            'distribution' => $distribution,
            'categoryDistribution' => $categoryDistribution,
            'statusDistribution' => $statusDistribution,
            'heatMapData' => $heatMapData,
            'topRisks' => $topRisks,
            'treatmentStats' => $treatmentStats,
        ]);
    }

    public function scheduled()
    {
        return Inertia::render('Reports/Scheduled/Index');
    }

    public function complianceReport()
    {
        $orgId = auth()->user()->organization_id;

        // Framework posture
        $frameworkPosture = $this->complianceScoreService->getFrameworkPosture($orgId);

        // Gap summary by severity
        $gapSummary = [];
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $gapSummary[] = [
                'severity' => ucfirst($severity),
                'open' => Gap::where('severity', $severity)
                    ->whereNotIn('status', ['remediated', 'closed', 'accepted'])->count(),
                'closed' => Gap::where('severity', $severity)
                    ->whereIn('status', ['remediated', 'closed'])->count(),
            ];
        }

        // Control effectiveness
        $controlStats = $this->complianceScoreService->getControlEffectivenessStats($orgId);

        // Overall compliance score
        $overallScore = $this->complianceScoreService->getOverallComplianceScore($orgId);

        return Inertia::render('Reports/ComplianceReport', [
            'frameworkPosture' => $frameworkPosture,
            'gapSummary' => $gapSummary,
            'controlStats' => $controlStats,
            'overallScore' => $overallScore,
        ]);
    }
}
