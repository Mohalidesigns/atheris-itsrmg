<?php

namespace App\Http\Controllers;

use App\Models\ComplianceAssessment;
use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\Gap;
use App\Models\Risk;
use App\Models\SoaVersion;
use App\Models\StatementOfApplicability;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Isms\StatementOfApplicabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function index(StatementOfApplicabilityService $soa)
    {
        $orgId = $this->orgId();
        $fwId = $this->isoFrameworkId();
        $rows = $soa->rows($orgId);
        $summary = $soa->summary($rows);

        // Per Annex A theme: what the SoA claims, and what the latest ISO assessment found.
        $themes = collect([
            'A.5' => 'Organizational controls', 'A.6' => 'People controls', 'A.7' => 'Physical controls', 'A.8' => 'Technological controls',
        ])->map(function ($name, $code) use ($rows) {
            $theme = $rows->filter(fn ($r) => str_starts_with($r['requirement_code'], $code.'.'));
            $applicable = $theme->where('is_applicable', true);
            $assessed = $applicable->whereNotNull('assessment_result')->where('assessment_result', '!=', 'not_assessed')->where('assessment_result', '!=', 'not_applicable');

            return [
                'name' => $name,
                'count' => $theme->count(),
                'applicable' => $applicable->count(),
                'implemented' => $applicable->where('implementation_status', 'implemented')->count(),
                'compliant' => $assessed->where('assessment_result', 'compliant')->count(),
                'assessed' => $assessed->count(),
                'flagged' => $theme->filter(fn ($r) => $r['flags'])->count(),
            ];
        })->all();

        $approved = SoaVersion::query()->where('status', 'approved')->orderByDesc('version_no')->first(['id', 'version_no', 'decided_at', 'decided_by']);
        $openNc = $this->isoGaps($orgId, $fwId)->whereNotIn('g.status', Gap::RESOLVED_STATUSES);

        return Inertia::render('ISMS/Index', [
            'kpis' => [
                'annex_a_total' => $summary['total'],
                'soa_applicable' => $summary['applicable'],
                'soa_implemented' => $summary['implemented'],
                'soa_flagged' => $summary['flagged'],
                'isms_risks' => $this->ismsRisks()->count(),
                'isms_risks_above_appetite' => $this->ismsRisks()->where('risk_appetite', 'above')->count(),
                'open_gaps' => (clone $openNc)->count(),
                'overdue_gaps' => (clone $openNc)->whereNotNull('g.due_date')->whereDate('g.due_date', '<', today())->count(),
            ],
            'themes' => $themes,
            'soaVersion' => $approved ? ['version_no' => $approved->version_no, 'approved_at' => $approved->decided_at,
                'changes' => count($soa->changesSinceApproved($orgId, $rows))] : null,
            'pendingVersion' => SoaVersion::query()->where('status', 'submitted')->exists(),
            // The assessment the SoA evidence is read from: the latest completed one.
            'latestAudit' => ComplianceAssessment::where('organization_id', $orgId)->where('framework_id', $fwId)
                ->where('status', 'completed')->orderByDesc('end_date')->orderByDesc('id')->first(),
        ]);
    }

    /** Risks inside the ISMS scope (config isms.risk_categories), with the controls treating them. */
    public function risks()
    {
        $risks = $this->ismsRisks()->with(['owner:id,name', 'category:id,name', 'controls:id,control_code,title,effectiveness'])
            ->orderByDesc('residual_score')->get();

        return Inertia::render('ISMS/Risks', [
            'risks' => $risks,
            // Five-point rating scale shown as four bands: "very low" counts as low.
            'distribution' => collect(['critical', 'high', 'medium'])->mapWithKeys(fn ($r) => [$r => $risks->where('residual_rating', $r)->count()])
                ->put('low', $risks->whereIn('residual_rating', ['low', 'very_low'])->count())->all(),
            'scope' => DB::table('risk_categories')->where('organization_id', $this->orgId())->whereIn('slug', config('isms.risk_categories'))->orderBy('name')->pluck('name'),
            'untreated' => $risks->filter(fn ($r) => $r->controls->isEmpty())->count(),
        ]);
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

        $gapRows = $this->isoGaps($orgId, $fwId)
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

    public function soa(StatementOfApplicabilityService $soa)
    {
        $orgId = $this->orgId();
        $rows = $soa->rows($orgId);
        $user = request()->user();

        return Inertia::render('ISMS/Soa', [
            'rows' => $rows->values(),
            'summary' => $soa->summary($rows),
            'changes' => $soa->changesSinceApproved($orgId, $rows),
            'versions' => SoaVersion::query()->with(['preparer:id,name', 'decider:id,name'])->orderByDesc('version_no')
                ->get(['id', 'version_no', 'status', 'summary', 'notes', 'prepared_by', 'prepared_at', 'decided_by', 'decided_at', 'decision_notes']),
            'controls' => Control::query()->orderBy('control_code')->get(['id', 'control_code', 'title']),
            'implementation' => StatementOfApplicability::IMPLEMENTATION,
            'can' => [
                'edit' => $user->can('edit isms'),
                'approve' => $user->can('approve isms'),
                'export' => $user->can('export isms'),
            ],
            'me' => $user->id,
        ]);
    }

    /** Record (or change) the bank's decision for one Annex A control. */
    public function soaUpdate(Request $request, int $requirement)
    {
        $orgId = $this->orgId();
        abort_unless(DB::table('framework_requirements')->where('id', $requirement)->where('framework_id', $this->isoFrameworkId())->where('level', '>', 0)->exists(), 404);

        $data = $request->validate([
            'is_applicable' => 'required|boolean',
            'justification' => 'required|string|min:10|max:2000',
            'implementation_status' => ['required', Rule::in(StatementOfApplicability::IMPLEMENTATION)],
            'control_id' => ['nullable', 'integer', Rule::exists('controls', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
        ], ['justification.required' => 'ISO 27001 requires a reason for including or excluding every Annex A control.']);

        // An excluded control is not implemented; an applicable one cannot be "not applicable".
        if (! $data['is_applicable']) {
            $data['implementation_status'] = 'not_applicable';
        } elseif ($data['implementation_status'] === 'not_applicable') {
            throw ValidationException::withMessages(['implementation_status' => 'Choose how far an applicable control is implemented.']);
        }

        $entry = StatementOfApplicability::query()->firstOrNew(['organization_id' => $orgId, 'requirement_id' => $requirement]);
        $entry->fill($data + ['updated_by' => $request->user()->id])->save();

        return back()->with('success', 'Statement of Applicability updated.');
    }

    /** Freeze the current SoA as a new version for management approval. */
    public function soaSubmit(Request $request, StatementOfApplicabilityService $soa)
    {
        $orgId = $this->orgId();
        abort_if(SoaVersion::query()->where('status', 'submitted')->exists(), 422, 'A version is already awaiting approval.');
        app(ApprovalEngine::class)->assertCanInitiate('isms.soa', $request->user());
        $rows = $soa->rows($orgId);
        $summary = $soa->summary($rows);
        if ($summary['pending'] > 0) {
            throw ValidationException::withMessages(['soa' => "{$summary['pending']} Annex A controls have no decision yet."]);
        }

        $version = SoaVersion::create([
            'organization_id' => $orgId,
            'version_no' => (int) SoaVersion::query()->max('version_no') + 1,
            'status' => 'submitted',
            'snapshot' => $soa->snapshot($rows),
            'summary' => $summary + ['changes' => count($soa->changesSinceApproved($orgId, $rows))],
            'notes' => $request->input('notes'),
            'prepared_by' => $request->user()->id,
            'prepared_at' => now(),
        ]);

        app(ApprovalEngine::class)->start('isms.soa', $version, $request->user());

        return back()->with('success', "SoA version {$version->version_no} submitted for approval.");
    }

    /** Approve or return a submitted version. Whoever prepared it cannot approve it. */
    public function soaDecide(Request $request, SoaVersion $version)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'notes' => 'required_if:decision,return|nullable|string|max:2000',
        ]);
        abort_unless($version->status === 'submitted', 422, 'This version has already been decided.');
        if ($request->user()->id === (int) $version->prepared_by) {
            throw ValidationException::withMessages(['decision' => 'The person who prepared a version cannot approve it.']);
        }

        // Workflow Studio stages (ATH-VAL-016): the version stays submitted until the last stage approves.
        $approvals = app(ApprovalEngine::class);
        if ($data['decision'] === 'approve' && ($pending = $approvals->gate('isms.soa', $version, $request->user(), $data['notes'] ?? null))) {
            return back()->with('success', "SoA version {$version->version_no}: {$pending}");
        }
        if ($data['decision'] === 'return') {
            $approvals->reject('isms.soa', $version, $request->user(), $data['notes']);
        }

        $version->update([
            'status' => $data['decision'] === 'approve' ? 'approved' : 'returned',
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', "SoA version {$version->version_no} ".($version->status === 'approved' ? 'approved.' : 'returned.'));
    }

    /** The SoA as CSV, with the evidence beside each entry (`export isms`). */
    public function soaExport(StatementOfApplicabilityService $soa): StreamedResponse
    {
        $rows = $soa->rows($this->orgId());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['control', 'title', 'applicable', 'justification', 'implementation', 'implementing_controls', 'latest_assessment', 'flags']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['requirement_code'], $r['title'], $r['is_applicable'] === null ? 'undecided' : ($r['is_applicable'] ? 'yes' : 'no'),
                    $r['justification'], $r['implementation_status'], collect($r['controls'])->pluck('control_code')->implode('; '),
                    $r['assessment_result'], implode(' ', $r['flags'])]);
            }
            fclose($out);
        }, 'statement-of-applicability-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function ismsRisks()
    {
        $categories = DB::table('risk_categories')->where('organization_id', $this->orgId())
            ->whereIn('slug', config('isms.risk_categories'))->pluck('id');

        return Risk::query()->active()->whereIn('category_id', $categories);
    }

    public function gapAnalysis()
    {
        $orgId = $this->orgId();
        $fwId = $this->isoFrameworkId();

        $gaps = $this->isoGaps($orgId, $fwId)
            ->leftJoin('users as u', 'u.id', '=', 'g.assigned_to')
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

    /** Gaps against ISO 27001 requirements, plus audit non-conformities not tied to any framework. */
    private function isoGaps(int $orgId, ?int $fwId)
    {
        return DB::table('gaps as g')
            ->where('g.organization_id', $orgId)
            ->leftJoin('framework_requirements as r', 'r.id', '=', 'g.requirement_id')
            ->where(fn ($q) => $q->whereNull('g.requirement_id')->orWhere('r.framework_id', $fwId));
    }
}
