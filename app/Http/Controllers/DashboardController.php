<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BoardPackTemplate;
use App\Models\CcmTenantTest;
use App\Models\CircularAssessment;
use App\Models\ComplianceAssessment;
use App\Models\Control;
use App\Models\DocIntelligenceJob;
use App\Models\Gap;
use App\Models\Incident;
use App\Models\Kri;
use App\Models\Obligation;
use App\Models\Policy;
use App\Models\RegulatoryCircular;
use App\Models\ReturnRun;
use App\Models\Returns\RegulatoryReturn;
use App\Models\Risk;
use App\Models\Vendor;
use App\Models\Vulnerability;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use App\Services\Assets\AssetMetrics;
use App\Services\Change\ChangeApprovalRouter;
use App\Services\Change\ChangeMetrics;
use App\Services\ComplianceScoreService;
use App\Services\Continuity\ContinuityMetrics;
use App\Services\Issues\IssueMetrics;
use App\Services\Monitoring\MonitoringMetrics;
use App\Services\Policies\PolicyMetrics;
use App\Services\Reports\BoardPackBuilder;
use App\Services\SecurityOps\SecurityOpsMetrics;
use App\Services\Vendors\VendorMetrics;
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

        // KRI panel: the bank's KRIs, worst latest reading first (ATH-VAL-014)
        $rank = ['red' => 0, 'amber' => 1, 'green' => 2];
        $kris = Kri::with(['readings' => fn ($q) => $q->orderByDesc('recorded_at')->limit(6)])->get()
            ->sortBy(fn ($k) => [$rank[$k->readings->first()?->status] ?? 3, $k->code])
            ->take(12)->values();

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

        // CCM: latest result of each enabled test in this bank
        $ccmByStatus = CcmTenantTest::where('status', 'enabled')->whereNotNull('last_status')->select('last_status', DB::raw('count(*) as c'))
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
            ->outstanding()
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

        // Vendor scoreboard: tier 1-2 vendors with their latest rating, worst first (tenant-scoped).
        $vendorScoreboard = $orgId ? app(VendorMetrics::class)->scoreboard(10) : [];

        // Policy attestation coverage per department
        // Policies in force only; waived requests are neither done nor outstanding (ATH-VAL-009).
        $attestationByDept = DB::table('policy_attestations as pa')
            ->join('users as u', 'u.id', '=', 'pa.user_id')
            ->join('policies as p', 'p.id', '=', 'pa.policy_id')
            ->where('p.status', 'active')->whereNull('p.deleted_at')
            ->whereIn('pa.status', ['pending', 'completed'])
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
            'assets' => Asset::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', '!=', 'decommissioned')->count(),
            'vendors' => Vendor::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', '!=', 'terminated')->count(),
            'policies' => Policy::when($orgId, fn ($q) => $q->where('organization_id', $orgId))->where('status', 'active')->count(),
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
            'regulatory' => $orgId ? $this->regulatoryPulse((int) $orgId) : null,
            'secops' => $orgId ? $this->secopsPulse() : null,
            'issues' => $orgId ? $this->issuesPulse() : null,
            'assets' => $orgId ? $this->assetsPulse() : null,
            'vendors' => $orgId ? app(VendorMetrics::class)->summary() : null,
            'policyPulse' => $orgId ? app(PolicyMetrics::class)->summary() : null,
            'continuity' => $orgId ? app(ContinuityMetrics::class)->summary() : null,
            'monitoring' => $orgId ? app(MonitoringMetrics::class)->summary((int) $orgId) : null,
            'changes' => $orgId ? $this->changesPulse($request->user()) : null,
        ]);
    }

    /** Change Management feed: approval backlog, the next scheduled changes and the user's own inbox (tenant-scoped models). */
    private function changesPulse($user): array
    {
        $metrics = app(ChangeMetrics::class);

        return [
            'stats' => $metrics->summary(),
            'upcoming' => $metrics->upcoming(5),
            'myApprovals' => app(ChangeApprovalRouter::class)->pendingFor($user)->count(),
        ];
    }

    /** Asset Management feed: register hygiene, inherited criticality and the end-of-life watch (tenant-scoped models). */
    private function assetsPulse(): array
    {
        $metrics = app(AssetMetrics::class);

        return ['stats' => $metrics->summary(), 'eol' => $metrics->eolWatch(5)];
    }

    /** Issues & Remediation feed: open CAPA load, SLA breaches and escalations (tenant-scoped model). */
    private function issuesPulse(): array
    {
        $metrics = app(IssueMetrics::class);

        return ['stats' => $metrics->summary(), 'overdue' => $metrics->mostOverdue(5), 'bySource' => $metrics->openBySource()];
    }

    /** Security Operations feed: exposure, live incidents and running regulatory clocks (tenant-scoped models). */
    private function secopsPulse(): array
    {
        $metrics = app(SecurityOpsMetrics::class);

        return ['stats' => $metrics->summary(), 'clocks' => $metrics->regulatoryClocks(5)];
    }

    /**
     * Regulatory Intelligence feed for the executive dashboard: circular triage backlog, documents
     * waiting on a reviewer, and returns still to be filed. Every query names the organisation.
     */
    private function regulatoryPulse(int $orgId): array
    {
        $visible = RegulatoryCircular::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('organization_id', $orgId)
                ->orWhere(fn ($p) => $p->whereNull('organization_id')->whereIn('status', RegulatoryCircular::TENANT_VISIBLE)));
        $triaged = CircularAssessment::withoutGlobalScopes()->where('organization_id', $orgId)
            ->whereIn('circular_id', (clone $visible)->select('id'));

        $openRuns = ReturnRun::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where('status', '!=', ReturnRun::SUBMITTED)->with('template:id,name,regulator_code')
            ->orderBy('period')->get();

        return [
            'unread' => (clone $visible)->count() - (clone $triaged)->where('status', '!=', CircularAssessment::UNREAD)->count(),
            'awaiting_action' => (clone $triaged)->where('status', CircularAssessment::ASSESSED)->count(),
            'overdue_actions' => (clone $triaged)->where('status', CircularAssessment::ASSESSED)
                ->whereDate('due_date', '<', today())->count(),
            'documents_to_review' => DocIntelligenceJob::withoutGlobalScopes()->where('organization_id', $orgId)
                ->whereIn('status', ['extraction_done', 'awaiting_review'])->count(),
            'returns_open' => $openRuns->count(),
            'architecture_returns_open' => RegulatoryReturn::withoutGlobalScopes()->where('organization_id', $orgId)
                ->whereNotIn('state', [RegulatoryReturn::SIGNED, RegulatoryReturn::SUBMITTED, RegulatoryReturn::ARCHIVED])->count(),
            'returns' => $openRuns->take(5)->map(fn (ReturnRun $r) => [
                'id' => $r->id,
                'name' => $r->template?->name,
                'regulator' => $r->template?->regulator_code,
                'period' => $r->period,
                'status' => $r->status,
            ])->values(),
        ];
    }

    /** "Export board pack": generate this month's BAC pack from current figures (ATH-VAL-014). */
    public function boardPackExport(Request $request, BoardPackBuilder $builder)
    {
        abort_unless($request->user()->can('create reports'), 403);
        $template = BoardPackTemplate::where('name', 'like', 'BAC%')->first() ?? BoardPackTemplate::firstOrFail();
        $run = $builder->generate($template, $request->user());

        return redirect()->route('board-packs.show', $run)->with('success', 'Board pack generated from current figures. Review it, then pass it to an approver.');
    }
}
