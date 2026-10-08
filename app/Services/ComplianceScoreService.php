<?php

namespace App\Services;

use App\Models\ComplianceAssessment;
use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\FrameworkRequirement;
use Illuminate\Support\Facades\DB;

class ComplianceScoreService
{
    /**
     * Per-framework posture: the latest completed assessment's score, the assessment in flight
     * (if any), and control coverage — how many assessable requirements at least one of the
     * organisation's controls is mapped to.
     */
    public function getFrameworkPosture(int $organizationId): array
    {
        $frameworks = ControlFramework::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $coverage = $this->controlCoverage($organizationId);

        $posture = [];

        foreach ($frameworks as $framework) {
            $latestAssessment = ComplianceAssessment::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('framework_id', $framework->id)
                ->where('status', 'completed')
                ->orderByDesc('end_date')->orderByDesc('id')
                ->first();

            $inFlight = ComplianceAssessment::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('framework_id', $framework->id)
                ->where('status', 'in_progress')
                ->latest()->first(['id', 'title']);

            $assessable = FrameworkRequirement::where('framework_id', $framework->id)->whereDoesntHave('children')->count();

            $posture[] = [
                'framework_id' => $framework->id,
                'name' => $framework->name,
                'short_name' => $framework->short_name,
                'slug' => $framework->slug,
                'category' => $framework->category,
                'jurisdiction' => $framework->jurisdiction,
                'total_requirements' => $latestAssessment?->total_requirements ?: $assessable,
                'assessable_requirements' => $assessable,
                'score' => $latestAssessment?->overall_score !== null ? (float) $latestAssessment->overall_score : null,
                'compliant' => $latestAssessment?->compliant_count ?? 0,
                'partial' => $latestAssessment?->partial_count ?? 0,
                'non_compliant' => $latestAssessment?->non_compliant_count ?? 0,
                'not_applicable' => $latestAssessment?->not_applicable_count ?? 0,
                'last_assessed' => $latestAssessment?->end_date?->format('Y-m-d'),
                'assessment_id' => $latestAssessment?->id,
                'in_progress_id' => $inFlight?->id,
                'covered_requirements' => $coverage[$framework->id] ?? 0,
                'coverage_percent' => $assessable > 0 ? (int) round((($coverage[$framework->id] ?? 0) / $assessable) * 100) : 0,
            ];
        }

        return $posture;
    }

    /** framework_id => number of distinct requirements mapped to at least one of the org's live controls. */
    public function controlCoverage(int $organizationId): array
    {
        return DB::table('control_framework_mappings as m')
            ->join('controls as c', 'c.id', '=', 'm.control_id')
            ->join('framework_requirements as r', 'r.id', '=', 'm.requirement_id')
            ->where('c.organization_id', $organizationId)
            ->whereNull('c.deleted_at')
            ->where('m.coverage', '!=', 'planned')
            ->groupBy('r.framework_id')
            ->select('r.framework_id', DB::raw('count(distinct m.requirement_id) as covered'))
            ->pluck('covered', 'framework_id')
            ->map(fn ($v) => (int) $v)
            ->all();
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

    /** Effectiveness of controls in operation (active / under review). NULL effectiveness counts as not assessed. */
    public function getControlEffectivenessStats(int $organizationId): array
    {
        $controls = Control::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->operational()
            ->get(['effectiveness']);

        return [
            'total' => $controls->count(),
            'effective' => $controls->where('effectiveness', 'effective')->count(),
            'partially_effective' => $controls->where('effectiveness', 'partially_effective')->count(),
            'ineffective' => $controls->where('effectiveness', 'ineffective')->count(),
            'not_assessed' => $controls->filter(fn ($c) => in_array($c->effectiveness, [null, '', 'not_assessed'], true))->count(),
        ];
    }
}
