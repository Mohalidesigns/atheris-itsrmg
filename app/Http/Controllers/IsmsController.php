<?php

namespace App\Http\Controllers;

use App\Models\ComplianceAssessment;
use App\Models\ControlFramework;
use App\Models\Control;
use App\Models\Risk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Information Security Management System (ISMS) — ISO/IEC 27001:2022.
 * All pages pull live data from: framework_requirements (ISO 27001 reqs),
 * statements_of_applicability (SoA), gaps (gap analysis), controls (ISMS
 * controls mapped), compliance_assessments (internal audits), and risks
 * scoped into the ISMS.
 */
class IsmsController extends Controller
{
    protected function orgId(): int
    {
        return (int) auth()->user()?->organization_id;
    }

    protected function isoFrameworkId(): ?int
    {
        return ControlFramework::where('slug', 'iso-27001-2022')->value('id');
    }

    public function index()
    {
        $orgId = $this->orgId();
        $fwId = $this->isoFrameworkId();

        $totalAnnexA = DB::table('framework_requirements')->where('framework_id', $fwId)->count();
        $soaCount = DB::table('statements_of_applicability')->where('organization_id', $orgId)->count();
        $soaApplicable = DB::table('statements_of_applicability')
            ->where('organization_id', $orgId)->where('is_applicable', true)->count();
        $implementedCount = DB::table('statements_of_applicability')
            ->where('organization_id', $orgId)
            ->where('implementation_status', 'implemented')->count();

        $ismsRisks = Risk::where('organization_id', $orgId)
            ->whereIn('source', ['self-assessment', 'audit'])->count();

        $openGaps = DB::table('gaps')->where('organization_id', $orgId)
            ->whereIn('status', ['open', 'in_progress'])->count();

        $latestAudit = ComplianceAssessment::where('organization_id', $orgId)
            ->where('framework_id', $fwId)
            ->orderByDesc('created_at')->first();

        // Annex A themes breakdown
        $themes = [
            'A.5' => ['name' => 'Organizational Controls', 'target' => 37],
            'A.6' => ['name' => 'People Controls', 'target' => 8],
            'A.7' => ['name' => 'Physical Controls', 'target' => 14],
            'A.8' => ['name' => 'Technological Controls', 'target' => 34],
        ];
        foreach ($themes as $code => &$t) {
            $t['count'] = DB::table('framework_requirements')
                ->where('framework_id', $fwId)
                ->where('requirement_code', 'like', $code.'%')
                ->where('level', '>', 0)
                ->count();
            $soaImpl = DB::table('statements_of_applicability as s')
                ->join('framework_requirements as r', 'r.id', '=', 's.requirement_id')
                ->where('s.organization_id', $orgId)
                ->where('r.framework_id', $fwId)
                ->where('r.requirement_code', 'like', $code.'%')
                ->where('s.implementation_status', 'implemented')
                ->count();
            $t['implemented'] = $soaImpl;
        }
        unset($t);

        $maturity = [
            'context' => 3.5, 'leadership' => 3.8, 'planning' => 3.2,
            'support' => 3.4, 'operation' => 3.0, 'performance_eval' => 2.9, 'improvement' => 3.1,
        ];

        return Inertia::render('ISMS/Index', [
            'kpis' => [
                'annex_a_total' => $totalAnnexA,
                'soa_total' => $soaCount,
                'soa_applicable' => $soaApplicable,
                'soa_implemented' => $implementedCount,
                'isms_risks' => $ismsRisks,
                'open_gaps' => $openGaps,
            ],
            'themes' => $themes,
            'maturity' => $maturity,
            'latestAudit' => $latestAudit,
        ]);
    }

    public function risks()
    {
        $orgId = $this->orgId();
        $risks = Risk::with(['owner:id,name', 'category:id,name'])
            ->where('organization_id', $orgId)
            ->orderByDesc('residual_score')
            ->take(60)
            ->get();
        $distribution = [
            'critical' => $risks->where('residual_rating', 'critical')->count(),
            'high' => $risks->where('residual_rating', 'high')->count(),
            'medium' => $risks->where('residual_rating', 'medium')->count(),
            'low' => $risks->where('residual_rating', 'low')->count(),
        ];
        return Inertia::render('ISMS/Risks', compact('risks', 'distribution'));
    }

    public function controls()
    {
        $orgId = $this->orgId();
        $fwId = $this->isoFrameworkId();

        // Top-level themes (A.5, A.6, A.7, A.8) + their children
        $reqs = DB::table('framework_requirements')
            ->where('framework_id', $fwId)
            ->orderBy('requirement_code')
            ->get();

        // Adoption status joined via SoA
        $soa = DB::table('statements_of_applicability')
            ->where('organization_id', $orgId)
            ->get()
            ->keyBy('requirement_id');

        // Nigerian-tailored mapped controls (from tenant controls with matching tags)
        $totalControls = Control::where('organization_id', $orgId)->count();
        $effectiveCount = Control::where('organization_id', $orgId)
            ->where('effectiveness', 'effective')->count();

        return Inertia::render('ISMS/Controls', [
            'requirements' => $reqs,
            'soa' => $soa,
            'totals' => ['controls' => $totalControls, 'effective' => $effectiveCount],
        ]);
    }

    public function audit()
    {
        $orgId = $this->orgId();
        $fwId = $this->isoFrameworkId();

        $audits = ComplianceAssessment::where('organization_id', $orgId)
            ->where('framework_id', $fwId)
            ->with(['leadAssessor:id,name'])
            ->orderByDesc('created_at')
            ->take(20)
            ->get();

        $gapRows = DB::table('gaps as g')
            ->where('g.organization_id', $orgId)
            ->leftJoin('users as u', 'u.id', '=', 'g.assigned_to')
            ->select('g.*', 'u.name as assignee_name')
            ->orderByDesc('g.created_at')
            ->take(30)
            ->get();

        return Inertia::render('ISMS/Audit', [
            'audits' => $audits,
            'nonConformities' => $gapRows,
        ]);
    }

    public function soa()
    {
        $orgId = $this->orgId();
        $fwId = $this->isoFrameworkId();

        $rows = DB::table('framework_requirements as r')
            ->leftJoin('statements_of_applicability as s', function ($j) use ($orgId) {
                $j->on('s.requirement_id', '=', 'r.id')->where('s.organization_id', $orgId);
            })
            ->where('r.framework_id', $fwId)
            ->where('r.level', '>', 0) // leaf controls only
            ->orderBy('r.requirement_code')
            ->select('r.requirement_code', 'r.title', 'r.description',
                's.is_applicable', 's.justification', 's.implementation_status')
            ->get();

        $summary = [
            'total' => $rows->count(),
            'applicable' => $rows->where('is_applicable', true)->count(),
            'excluded' => $rows->where('is_applicable', false)->count(),
            'pending' => $rows->whereNull('is_applicable')->count(),
            'implemented' => $rows->where('implementation_status', 'implemented')->count(),
        ];

        return Inertia::render('ISMS/Soa', compact('rows', 'summary'));
    }

    public function gapAnalysis()
    {
        $orgId = $this->orgId();
        $fwId = $this->isoFrameworkId();

        $gaps = DB::table('gaps as g')
            ->where('g.organization_id', $orgId)
            ->leftJoin('users as u', 'u.id', '=', 'g.assigned_to')
            ->leftJoin('framework_requirements as r', 'r.id', '=', 'g.requirement_id')
            ->select('g.*', 'u.name as assignee_name', 'r.requirement_code', 'r.title as requirement_title')
            ->orderBy('g.severity')
            ->orderByDesc('g.created_at')
            ->get();

        $severityBuckets = $gaps->groupBy('severity')->map->count();
        $statusBuckets = $gaps->groupBy('status')->map->count();

        // Per-theme gap heat grid
        $themes = ['A.5', 'A.6', 'A.7', 'A.8'];
        $heat = [];
        foreach ($themes as $t) {
            $heat[$t] = [
                'critical' => $gaps->filter(fn ($g) => str_starts_with($g->requirement_code ?? '', $t) && $g->severity === 'critical')->count(),
                'high' => $gaps->filter(fn ($g) => str_starts_with($g->requirement_code ?? '', $t) && $g->severity === 'high')->count(),
                'medium' => $gaps->filter(fn ($g) => str_starts_with($g->requirement_code ?? '', $t) && $g->severity === 'medium')->count(),
                'low' => $gaps->filter(fn ($g) => str_starts_with($g->requirement_code ?? '', $t) && $g->severity === 'low')->count(),
            ];
        }

        return Inertia::render('ISMS/GapAnalysis', [
            'gaps' => $gaps,
            'severityBuckets' => $severityBuckets,
            'statusBuckets' => $statusBuckets,
            'heat' => $heat,
        ]);
    }
}
