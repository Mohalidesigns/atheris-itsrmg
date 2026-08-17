<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\ComplianceAssessment;
use App\Models\Control;
use App\Models\Incident;
use App\Models\Kri;
use App\Models\KriReading;
use App\Models\Obligation;
use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\Risk;
use App\Models\TprmSecurityRating;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Models\CcmTenantTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()?->organization_id;

        // Heat map: 5×5 matrix grouped by inherent_likelihood × inherent_impact
        $heatmapRisks = Risk::when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->whereNotNull('inherent_likelihood')
            ->whereNotNull('inherent_impact')
            ->select('id', 'risk_id_code', 'title', 'inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact', 'inherent_rating', 'residual_rating', 'status')
            ->get();

        $top10Risks = Risk::when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->with('owner:id,name,email')
            ->orderByDesc('inherent_score')
            ->take(10)
            ->get();

        // KRI latest readings
        $kris = Kri::with(['readings' => fn($q) => $q->orderByDesc('recorded_at')->limit(12)])
            ->take(12)
            ->get();

        // Control effectiveness donut
        $ctrlEff = Control::when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->select('effectiveness', DB::raw('count(*) as c'))
            ->groupBy('effectiveness')->pluck('c', 'effectiveness');

        // CCM pass/fail
        $ccmByStatus = CcmTenantTest::select('last_status', DB::raw('count(*) as c'))
            ->groupBy('last_status')->pluck('c', 'last_status');

        // Incidents last 90 days
        $incidents = Incident::when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where('detected_at', '>=', now()->subDays(90))
            ->select('id', 'incident_id_code', 'title', 'severity', 'status', 'detected_at', 'is_data_breach')
            ->orderByDesc('detected_at')
            ->take(20)
            ->get();

        // Vulnerability funnel
        $vulnsBySeverity = Vulnerability::when($orgId, fn($q) => $q->where('organization_id', $orgId))
            ->where('status', 'open')
            ->select('severity', DB::raw('count(*) as c'))
            ->groupBy('severity')->pluck('c', 'severity');

        // Obligations calendar — next 90 days (drop by review_cycle_days modulo effective_date)
        $obligations = Obligation::orderBy('regulator_code')->take(50)->get()->map(function ($o) {
            $next = $o->effective_date ? $o->effective_date->copy()->addDays($o->review_cycle_days) : now()->addDays(30);
            while ($next->lt(now())) $next->addDays($o->review_cycle_days ?: 365);
            return [
                'id' => $o->id,
                'regulator' => $o->regulator_code,
                'reference' => $o->reference_code,
                'title' => $o->title,
                'owner_role' => $o->owner_role,
                'next_due' => $next->toDateString(),
                'days_to_due' => (int) now()->diffInDays($next, false),
            ];
        })->sortBy('next_due')->values()->take(15);

        // Vendor scoreboard — top 10 vendors with latest ratings
        $vendorScoreboard = Vendor::when($orgId, fn($q) => $q->where('organization_id', $orgId))
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
            ->when($orgId, fn($q) => $q->where('pa.organization_id', $orgId))
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
        $csat = [
            'domains' => ['Cyber Risk Mgmt', 'Threat Intel', 'Cybersecurity Controls', 'External Dependency', 'Incident Mgmt'],
            'current' => [3.2, 2.8, 3.5, 2.9, 3.4],
            'target' => [4, 4, 4.5, 4, 4.5],
            'overall' => 3.16,
            'target_overall' => 4.2,
            'completion_percent' => 60,
        ];

        // Aggregate counts
        $counts = [
            'risks' => Risk::when($orgId, fn($q) => $q->where('organization_id', $orgId))->count(),
            'controls' => Control::when($orgId, fn($q) => $q->where('organization_id', $orgId))->count(),
            'assets' => Asset::when($orgId, fn($q) => $q->where('organization_id', $orgId))->count(),
            'vendors' => Vendor::when($orgId, fn($q) => $q->where('organization_id', $orgId))->count(),
            'policies' => Policy::when($orgId, fn($q) => $q->where('organization_id', $orgId))->count(),
            'incidents' => Incident::when($orgId, fn($q) => $q->where('organization_id', $orgId))->count(),
            'vulns' => Vulnerability::when($orgId, fn($q) => $q->where('organization_id', $orgId))->count(),
            'compliance_assessments' => ComplianceAssessment::when($orgId, fn($q) => $q->where('organization_id', $orgId))->count(),
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
        $run = \App\Models\BoardPackRun::create([
            'organization_id' => 1,
            'template_id' => \App\Models\BoardPackTemplate::first()?->id ?? 1,
            'period' => now()->format('Y-m'),
            'status' => 'generated',
            'pptx_path' => 'board-packs/dashboard-export-'.now()->timestamp.'.pptx',
            'pdf_path' => 'board-packs/dashboard-export-'.now()->timestamp.'.pdf',
            'generated_at' => now(),
        ]);
        return redirect()->route('board-packs.index')->with('success', 'Board pack queued for generation (navy/gold PPTX + PDF)');
    }
}
