<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\CcmTenantTest;
use App\Models\ComplianceAssessment;
use App\Models\Control;
use App\Models\Gap;
use App\Models\Incident;
use App\Models\Kri;
use App\Models\Obligation;
use App\Models\Policy;
use App\Models\Risk;
use App\Models\TprmSecurityRating;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use App\Services\ComplianceScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()?->organization_id;

        // Heat map: 5×5 matrix of the active register (closed/archived excluded, as on the IT Risk dashboard)
        $heatmapRisks = Risk::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->active()
            ->whereNotNull('inherent_likelihood')
            ->whereNotNull('inherent_impact')
            ->select('id', 'risk_id_code', 'title', 'inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact', 'inherent_rating', 'residual_rating', 'status')
            ->get();

        $top10Risks = Risk::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->active()
            ->whereNotNull('inherent_score')
            ->with('owner:id,name,email')
            ->orderByDesc('inherent_score')
            ->orderByDesc('residual_score')
            ->take(10)
            ->get();

        // KRI latest readings
        $kris = Kri::with(['readings' => fn ($q) => $q->orderByDesc('recorded_at')->limit(12)])
            ->take(12)
            ->get();

        // Control effectiveness donut — operational controls, in a fixed order so the colours match
        // (effective, partially effective, ineffective, not assessed).
        $effRaw = Control::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->operational()
            ->select('effectiveness', DB::raw('count(*) as c'))
            ->groupBy('effectiveness')->pluck('c', 'effectiveness');
        $ctrlEff = [
            'effective' => (int) ($effRaw['effective'] ?? 0),
            'partially_effective' => (int) ($effRaw['partially_effective'] ?? 0),
            'ineffective' => (int) ($effRaw['ineffective'] ?? 0),
            'not_assessed' => (int) (($effRaw['not_assessed'] ?? 0) + ($effRaw[''] ?? 0)),
        ];

        // CCM pass/fail
        $ccmByStatus = CcmTenantTest::select('last_status', DB::raw('count(*) as c'))
            ->groupBy('last_status')->pluck('c', 'last_status');

        // Incidents last 90 days
        $incidents = Incident::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('detected_at', '>=', now()->subDays(90))
            ->select('id', 'incident_id_code', 'title', 'severity', 'status', 'detected_at', 'is_data_breach')
            ->orderByDesc('detected_at')
            ->take(20)
            ->get();

        // Vulnerability funnel
        $vulnsBySeverity = Vulnerability::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'open')
            ->select('severity', DB::raw('count(*) as c'))
            ->groupBy('severity')->pluck('c', 'severity');

        // Obligations calendar — next 90 days (drop by review_cycle_days modulo effective_date)
        $obligations = Obligation::orderBy('regulator_code')->get()->map(fn ($o) => [
            'id' => $o->id,
            'regulator' => $o->regulator_code,
            'reference' => $o->reference_code,
            'title' => $o->title,
            'owner_role' => $o->owner_role,
            'next_due' => $o->nextDue()->toDateString(),
            'days_to_due' => (int) today()->diffInDays($o->nextDue(), false),
        ])->sortBy('next_due')->values()->take(15);

        // Vendor scoreboard — top 10 vendors with latest ratings
        $vendorScoreboard = Vendor::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->limit(10)->get()
            ->map(function ($v) {
                $r = TprmSecurityRating::where('vendor_id', $v->id)->orderByDesc('captured_at')->first();

                return [
                    'id' => $v->id, 'name' => $v->name,
                    'risk_level' => $v->risk_level,
                    'grade' => $r?->grade ?? '—',
                    'rating_value' => $r?->rating_value,
                    'delta' => $r?->delta_from_previous ?? 0,
                ];
            });

        // Policy attestation coverage per department
        $attestationByDept = DB::table('policy_attestations as pa')
            ->join('users as u', 'u.id', '=', 'pa.user_id')
            ->when($orgId, fn ($q) => $q->where('pa.organization_id', $orgId))
            ->select('u.department',
                DB::raw("SUM(CASE WHEN pa.status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw('count(*) as total'))
            ->groupBy('u.department')
            ->get()
            ->map(function ($r) {
                $pct = $r->total > 0 ? round(($r->completed / $r->total) * 100) : 0;

                return ['department' => $r->department ?: 'Unassigned', 'coverage' => $pct, 'completed' => $r->completed, 'total' => $r->total];
            });

        // CBN-CSAT maturity radar (5 domains × 5 levels)
        // CBN-CSAT radar from the organisation's latest cycle. A domain plots as its achieved level plus
        // its fractional progress towards the next level, so movement shows before a level is attained.
        $csat = null;
        $latestCsat = CsatAssessment::when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderByDesc('assessment_year')->orderByDesc('id')->first();
        if ($latestCsat) {
            $levelColumns = [1 => 'baseline_score', 2 => 'evolving_score', 3 => 'intermediate_score', 4 => 'advanced_score', 5 => 'innovative_score'];
            $domains = $latestCsat->maScores()->where('score_type', 'domain')->orderBy('scope_code')->get();
            $statementTotal = CsatMaStatement::where('is_active', true)->count();
            $csat = [
                'assessment_id' => $latestCsat->id,
                'year' => $latestCsat->assessment_year,
                'status' => $latestCsat->status,
                'domains' => $domains->map(fn ($d) => $d->scope_code)->all(),
                'domain_names' => $domains->pluck('scope_name')->all(),
                'current' => $domains->map(fn ($d) => round($d->achieved_maturity_level
                    + ($d->achieved_maturity_level < 5 ? (float) $d->{$levelColumns[$d->achieved_maturity_level + 1]} : 0), 2))->all(),
                'target' => $domains->map(fn ($d) => (int) ($d->target_maturity_level ?? 0))->all(),
                'overall' => $latestCsat->overall_maturity_level,
                'inherent_risk' => $latestCsat->composite_risk_level,
                'completion_percent' => $statementTotal ? (int) round($latestCsat->maResponses()->whereNotNull('response')->count() / $statementTotal * 100) : 0,
                'readiness' => $latestCsat->ai_readiness_score,
                'readiness_rag' => $latestCsat->ai_readiness_rag,
            ];
        }

        // Aggregate counts
        $counts = [
            'risks' => Risk::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->active()->count(),
            'risks_above_appetite' => Risk::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->active()->where('risk_appetite', 'above')->count(),
            'controls' => Control::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'assets' => Asset::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'vendors' => Vendor::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'policies' => Policy::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'incidents' => Incident::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'vulns' => Vulnerability::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'compliance_assessments' => ComplianceAssessment::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->count(),
            'compliance_score' => $orgId ? app(ComplianceScoreService::class)->getOverallComplianceScore((int) $orgId) : null,
            'open_gaps' => Gap::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->open()->count(),
        ];

        return Inertia::render('Dashboard', [
            'orgName' => $request->user()?->organization?->name ?? 'Kano Heritage Bank Plc',
            'counts' => $counts,
            'heatmapRisks' => $heatmapRisks,
            'top10Risks' => $top10Risks,
            'kris' => $kris,
            'ctrlEff' => $ctrlEff,
            'ccmByStatus' => $ccmByStatus,
            'incidents' => $incidents,
            'vulnsBySeverity' => $vulnsBySeverity,
            'obligations' => $obligations,
            'vendorScoreboard' => $vendorScoreboard,
            'attestationByDept' => $attestationByDept,
            'csat' => $csat,
        ]);
    }

    public function boardPackExport()
    {
        // Stubbed export — create a BoardPackRun row and redirect back
        $run = BoardPackRun::create([
            'organization_id' => 1,
            'template_id' => BoardPackTemplate::first()?->id ?? 1,
            'period' => now()->format('Y-m'),
            'status' => 'generated',
            'pptx_path' => 'board-packs/dashboard-export-'.now()->timestamp.'.pptx',
            'pdf_path' => 'board-packs/dashboard-export-'.now()->timestamp.'.pdf',
            'generated_at' => now(),
        ]);

        return redirect()->route('board-packs.index')->with('success', 'Board pack queued for generation (navy/gold PPTX + PDF)');
    }
}
