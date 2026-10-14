<?php

namespace App\Services\Reports;

use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\Risk;
use App\Models\User;
use App\Services\ComplianceScoreService;
use App\Services\Kri\KriMetrics;
use App\Services\Monitoring\MonitoringMetrics;
use App\Services\RiskScoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds board packs from the bank's own figures (ATH-VAL-014).
 *
 * Generation takes a snapshot of every section in the template, so the pack the board read can be
 * reproduced after the underlying records move on. The pack is rendered as a print-ready page
 * (Navy/Gold, one section per page) that the browser saves as PDF; no PPTX/PDF library is
 * installed, and the previous runs only recorded file paths that were never written.
 *
 * Workflow: a pack is prepared as a draft and approved for the board by someone other than the
 * person who prepared it.
 */
class BoardPackBuilder
{
    public const SECTIONS = [
        'exec_summary' => 'Executive summary',
        'risk_overview' => 'Risk overview',
        'risk_heat_map' => 'Inherent risk heat map',
        'top_10_risks' => 'Top 10 risks',
        'kri_panel' => 'Key risk indicators',
        'risk_appetite' => 'Risk appetite by KRI category',
        'control_status' => 'Control effectiveness',
        'ccm_results' => 'Continuous controls monitoring',
        'cbn_return_readiness' => 'CBN return readiness',
        'incident_summary' => 'Incidents (90 days)',
        'incidents' => 'Incidents (90 days)',
        'vulnerabilities' => 'Vulnerabilities',
        'tprm_summary' => 'Third-party risk',
        'fair_summary' => 'Quantified risk (FAIR)',
        'fair_ale' => 'Quantified risk (FAIR)',
        'threat_advisories' => 'Threat advisories',
    ];

    public function __construct(
        private RiskScoringService $risks,
        private ComplianceScoreService $compliance,
        private KriMetrics $kris,
        private MonitoringMetrics $monitoring,
    ) {}

    public function generate(BoardPackTemplate $template, User $actor, ?string $period = null, $at = null): BoardPackRun
    {
        $orgId = (int) $actor->organization_id;
        $period ??= now()->format('Y-m');

        return BoardPackRun::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'template_id' => $template->id,
            'title' => "{$template->name} — ".now()->parse($period.'-01')->format('F Y'),
            'period' => $period,
            'content' => $this->snapshot($template, $orgId),
            'status' => 'draft',
            'prepared_by' => $actor->id,
            'generated_at' => $at ?? now(),
        ]);
    }

    public function approve(BoardPackRun $run, User $actor, ?string $notes = null): void
    {
        $run->status === 'draft' || throw ValidationException::withMessages(['pack' => 'Only a draft pack can be approved.']);
        (int) $run->prepared_by === (int) $actor->id
            && throw ValidationException::withMessages(['pack' => 'The person who prepared the pack cannot approve it for the board.']);
        $run->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'notes' => $notes]);
    }

    /** Section key => ['title' => ..., 'data' => ...] for each section the template lists. */
    public function snapshot(BoardPackTemplate $template, int $orgId): array
    {
        $out = [];
        foreach ($template->sections as $key) {
            $method = 'section'.str_replace('_', '', ucwords($key, '_'));
            $out[] = ['key' => $key, 'title' => self::SECTIONS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'data' => method_exists($this, $method) ? $this->{$method}($orgId) : null];
        }

        return $out;
    }

    private function activeRisks(int $orgId)
    {
        return Risk::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereNotIn('status', Risk::INACTIVE_STATUSES);
    }

    private function sectionExecSummary(int $orgId): array
    {
        $risks = $this->activeRisks($orgId)->get(['inherent_rating', 'residual_rating']);
        $issues = DB::table('issues')->where('organization_id', $orgId)->whereIn('status', ['open', 'in_progress', 'blocked', 'escalated'])->get(['severity', 'sla_due_at']);

        return [
            'risks_open' => $risks->count(),
            'risks_critical_high' => $risks->whereIn('inherent_rating', ['critical', 'high'])->count(),
            'residual_critical_high' => $risks->whereIn('residual_rating', ['critical', 'high'])->count(),
            'compliance_score' => $this->compliance->getOverallComplianceScore($orgId),
            'kri' => $this->kris->summary($orgId),
            'monitoring' => array_intersect_key($this->monitoring->summary($orgId), array_flip(['tests', 'pass', 'warn', 'fail', 'drift_critical', 'reviews_overdue'])),
            'issues_open' => $issues->count(),
            'issues_overdue' => $issues->filter(fn ($i) => $i->sla_due_at && $i->sla_due_at < now()->toDateTimeString())->count(),
        ];
    }

    private function sectionRiskOverview(int $orgId): array
    {
        $risks = $this->activeRisks($orgId)->get(['inherent_rating', 'residual_rating', 'status']);

        return [
            'by_inherent' => $risks->countBy('inherent_rating')->all(),
            'by_residual' => $risks->countBy('residual_rating')->all(),
            'by_status' => $risks->countBy('status')->all(),
            'treatments_overdue' => DB::table('risk_treatments')->where('organization_id', $orgId)->where('status', '!=', 'completed')
                ->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->count(),
        ];
    }

    private function sectionRiskHeatMap(int $orgId): array
    {
        return collect($this->risks->getHeatMapData($orgId, 'inherent'))
            ->map(fn ($c) => ['likelihood' => $c['likelihood'], 'impact' => $c['impact'], 'rating' => $c['rating'], 'count' => $c['count']])->values()->all();
    }

    private function sectionTop10Risks(int $orgId): array
    {
        return $this->activeRisks($orgId)->with('owner:id,name')->whereNotNull('inherent_score')->orderByDesc('inherent_score')->limit(10)
            ->get(['id', 'risk_id_code', 'title', 'inherent_score', 'inherent_rating', 'residual_rating', 'status', 'risk_owner_id'])
            ->map(fn ($r) => ['code' => $r->risk_id_code, 'title' => $r->title, 'score' => $r->inherent_score, 'inherent' => $r->inherent_rating,
                'residual' => $r->residual_rating, 'status' => $r->status, 'owner' => $r->owner?->name])->all();
    }

    private function sectionKriPanel(int $orgId): array
    {
        return ['summary' => $this->kris->summary($orgId), 'kris' => $this->kris->panel($orgId)];
    }

    private function sectionRiskAppetite(int $orgId): array
    {
        $rank = ['green' => 0, 'amber' => 1, 'red' => 2];

        return collect($this->kris->panel($orgId, 500))->groupBy('category')
            ->map(fn ($g, $cat) => ['category' => $cat, 'kris' => $g->count(),
                'worst' => $g->sortByDesc(fn ($k) => $rank[$k['status']] ?? 0)->first()['status'],
                'outside' => $g->whereIn('status', ['amber', 'red'])->pluck('name')->values()->all()])
            ->sortByDesc(fn ($c) => $rank[$c['worst']] ?? 0)->values()->all();
    }

    private function sectionControlStatus(int $orgId): array
    {
        return $this->compliance->getControlEffectivenessStats($orgId) + ['disagreements' => count($this->monitoring->disagreements($orgId))];
    }

    private function sectionCcmResults(int $orgId): array
    {
        $failing = DB::table('ccm_tenant_tests as tt')->join('ccm_tests as t', 't.id', '=', 'tt.ccm_test_id')
            ->where('tt.organization_id', $orgId)->where('tt.status', 'enabled')->where('tt.last_status', 'fail')
            ->orderBy('t.code')->get(['t.code', 't.title', 'tt.last_value']);

        return ['summary' => $this->monitoring->summary($orgId), 'failing' => $failing->map(fn ($f) => (array) $f)->all()];
    }

    private function sectionCbnReturnReadiness(int $orgId): array
    {
        return DB::table('regulatory_returns')->where('organization_id', $orgId)->orderBy('due_date')
            ->get(['name', 'period', 'regulator', 'due_date', 'state', 'completeness', 'signed_at'])
            ->map(fn ($r) => (array) $r + ['overdue' => ! $r->signed_at && $r->due_date && $r->due_date < today()->toDateString()])->all();
    }

    private function sectionIncidentSummary(int $orgId): array
    {
        $rows = DB::table('incidents')->where('organization_id', $orgId)->whereNull('deleted_at')->where('detected_at', '>=', now()->subDays(90))
            ->orderByDesc('detected_at')->get(['incident_id_code', 'title', 'severity', 'status', 'detected_at', 'is_data_breach']);

        return ['total' => $rows->count(), 'by_severity' => $rows->countBy('severity')->all(), 'open' => $rows->where('status', '!=', 'closed')->count(),
            'breaches' => $rows->where('is_data_breach', true)->count(),
            'recent' => $rows->whereIn('severity', ['critical', 'high'])->take(6)->map(fn ($i) => (array) $i)->values()->all()];
    }

    private function sectionIncidents(int $orgId): array
    {
        return $this->sectionIncidentSummary($orgId);
    }

    private function sectionVulnerabilities(int $orgId): array
    {
        $open = DB::table('vulnerabilities')->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('status', ['open', 'in_progress'])->get(['severity', 'due_date']);

        return ['open' => $open->count(), 'by_severity' => $open->countBy('severity')->all(),
            'overdue' => $open->filter(fn ($v) => $v->due_date && $v->due_date < today()->toDateString())->count()];
    }

    private function sectionTprmSummary(int $orgId): array
    {
        $vendors = DB::table('vendors')->where('organization_id', $orgId)->where('status', '!=', 'terminated')->get(['tier', 'next_review_date']);
        $floor = (int) config('tprm.grades.C', 70);

        return [
            'vendors' => $vendors->count(),
            'by_tier' => $vendors->countBy('tier')->all(),
            'reviews_overdue' => $vendors->filter(fn ($v) => $v->next_review_date && $v->next_review_date < today()->toDateString())->count(),
            'below_c' => DB::table('tprm_security_ratings')->where('organization_id', $orgId)->orderByDesc('captured_at')->get(['vendor_id', 'rating_value'])
                ->unique('vendor_id')->filter(fn ($r) => $r->rating_value < $floor)->count(),
            'breach_events_30d' => DB::table('tprm_breach_events')->where('organization_id', $orgId)->where('status', '!=', 'dismissed')
                ->where('discovered_at', '>=', now()->subDays(30))->count(),
        ];
    }

    private function sectionFairSummary(int $orgId): array
    {
        return DB::table('fair_scenarios as s')->where('s.organization_id', $orgId)
            ->leftJoin('risks as r', 'r.id', '=', 's.risk_id')
            ->get(['s.id', 's.name', 'r.risk_id_code'])
            ->map(function ($s) {
                $run = DB::table('fair_runs')->where('scenario_id', $s->id)->orderByDesc('ran_at')->first(['ale_mean_ngn', 'ale_p95_ngn', 'ran_at']);

                return ['name' => $s->name, 'risk' => $s->risk_id_code, 'ale_mean_ngn' => $run?->ale_mean_ngn, 'ale_p95_ngn' => $run?->ale_p95_ngn, 'ran_at' => $run?->ran_at];
            })->sortByDesc('ale_mean_ngn')->values()->all();
    }

    private function sectionFairAle(int $orgId): array
    {
        return $this->sectionFairSummary($orgId);
    }

    private function sectionThreatAdvisories(int $orgId): array
    {
        return DB::table('threat_advisories')->orderByDesc('published_at')->limit(5)->get(['source', 'advisory_id', 'title', 'published_at'])
            ->map(fn ($a) => (array) $a)->all();
    }
}
