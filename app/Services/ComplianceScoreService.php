<?php

namespace App\Services;

use App\Models\ComplianceAssessment;
use App\Models\ControlFramework;

class ComplianceScoreService
{
    public function getFrameworkPosture(int $organizationId): array
    {
        $frameworks = ControlFramework::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $posture = [];

        foreach ($frameworks as $framework) {
            $latestAssessment = ComplianceAssessment::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('framework_id', $framework->id)
                ->where('status', 'completed')
                ->latest()
                ->first();

            $posture[] = [
                'framework_id' => $framework->id,
                'name' => $framework->name,
                'short_name' => $framework->short_name,
                'slug' => $framework->slug,
                'category' => $framework->category,
                'jurisdiction' => $framework->jurisdiction,
                'total_requirements' => $framework->requirements()->count(),
                'score' => $latestAssessment?->overall_score,
                'compliant' => $latestAssessment?->compliant_count ?? 0,
                'partial' => $latestAssessment?->partial_count ?? 0,
                'non_compliant' => $latestAssessment?->non_compliant_count ?? 0,
                'not_applicable' => $latestAssessment?->not_applicable_count ?? 0,
                'last_assessed' => $latestAssessment?->end_date?->format('Y-m-d'),
                'assessment_id' => $latestAssessment?->id,
            ];
        }

        return $posture;
    }

    public function getOverallComplianceScore(int $organizationId): ?float
    {
        $posture = $this->getFrameworkPosture($organizationId);
        $assessed = collect($posture)->whereNotNull('score');

        if ($assessed->isEmpty()) {
            return null;
        }

        return round($assessed->avg('score'), 1);
    }

    public function getComplianceStats(int $organizationId): array
    {
        $posture = $this->getFrameworkPosture($organizationId);
        $assessed = collect($posture)->whereNotNull('score');

        return [
            'total_frameworks' => count($posture),
            'assessed_frameworks' => $assessed->count(),
            'average_score' => $assessed->isNotEmpty() ? round($assessed->avg('score'), 1) : null,
            'highest_score' => $assessed->max('score'),
            'lowest_score' => $assessed->min('score'),
            'frameworks' => $posture,
        ];
    }

    public function getControlEffectivenessStats(int $organizationId): array
    {
        $controls = \App\Models\Control::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get();

        return [
            'total' => $controls->count(),
            'effective' => $controls->where('effectiveness', 'effective')->count(),
            'partially_effective' => $controls->where('effectiveness', 'partially_effective')->count(),
            'ineffective' => $controls->where('effectiveness', 'ineffective')->count(),
            'not_assessed' => $controls->whereNull('effectiveness')->count(),
        ];
    }
}
