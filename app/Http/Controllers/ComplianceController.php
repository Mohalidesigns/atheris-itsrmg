<?php

namespace App\Http\Controllers;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceResult;
use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\Evidence;
use App\Models\Gap;
use App\Models\User;
use App\Services\ComplianceGapService;
use App\Services\ComplianceScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    public function __construct(
        private ComplianceScoreService $scoreService,
        private ComplianceGapService $gapService,
    ) {}

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    public function dashboard()
    {
        $orgId = $this->orgId();
        $stats = $this->scoreService->getComplianceStats($orgId);
        $controlStats = $this->scoreService->getControlEffectivenessStats($orgId);

        $recentAssessments = ComplianceAssessment::with('framework:id,short_name,slug')
            ->latest('updated_at')->limit(5)
            ->get(['id', 'framework_id', 'title', 'status', 'overall_score', 'total_requirements', 'compliant_count', 'partial_count', 'non_compliant_count', 'not_applicable_count', 'due_date', 'updated_at']);

        $openGapQuery = Gap::open();
        $gapStats = [
            'open' => (clone $openGapQuery)->count(),
            'critical_high' => (clone $openGapQuery)->whereIn('severity', ['critical', 'high'])->count(),
            'overdue' => (clone $openGapQuery)->whereNotNull('due_date')->whereDate('due_date', '<', today())->count(),
            'unassigned' => (clone $openGapQuery)->whereNull('assigned_to')->count(),
        ];

        $openGaps = Gap::open()
            ->with(['requirement:id,requirement_code,title', 'assignee:id,name'])
            ->orderBy('priority')->orderBy('due_date')->limit(6)->get();

        $evidence = Evidence::query()->get(['id', 'status', 'valid_until']);
        $evidenceStats = [
            'total' => $evidence->count(),
            'approved' => $evidence->where('status', 'approved')->filter(fn ($e) => ! $e->is_expired)->count(),
            'pending' => $evidence->where('status', 'pending')->count(),
            'expired' => $evidence->filter(fn ($e) => $e->is_expired)->count(),
        ];

        return Inertia::render('Compliance/Dashboard', [
            'stats' => $stats,
            'controlStats' => $controlStats,
            'recentAssessments' => $recentAssessments,
            'openGaps' => $openGaps,
            'gapStats' => $gapStats,
            'evidenceStats' => $evidenceStats,
        ]);
    }

    /* ----------------------------- Frameworks ----------------------------- */

    public function frameworkIndex()
    {
        $posture = collect($this->scoreService->getFrameworkPosture($this->orgId()))->keyBy('framework_id');

        $frameworks = ControlFramework::withCount('requirements')
            ->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn ($fw) => array_merge($fw->toArray(), [
                'posture' => $posture->get($fw->id),
            ]));

        return Inertia::render('Compliance/Frameworks/Index', [
            'frameworks' => $frameworks,
        ]);
    }

    public function frameworkShow(ControlFramework $framework)
    {
        $orgId = $this->orgId();
        $framework->load(['topLevelRequirements' => fn ($q) => $q->with('children')]);

        // Requirement → the organisation's controls mapped to it.
        $mapped = DB::table('control_framework_mappings as m')
            ->join('controls as c', 'c.id', '=', 'm.control_id')
            ->join('framework_requirements as r', 'r.id', '=', 'm.requirement_id')
            ->where('r.framework_id', $framework->id)
            ->where('c.organization_id', $orgId)
            ->whereNull('c.deleted_at')
            ->get(['m.requirement_id', 'c.id', 'c.control_code', 'c.title', 'c.effectiveness', 'm.coverage'])
            ->groupBy('requirement_id');

        // Latest result per requirement from the most recent completed (else in-progress) assessment.
        $latest = ComplianceAssessment::where('framework_id', $framework->id)
            ->whereIn('status', ['completed', 'in_progress'])
            ->orderByRaw("case when status = 'completed' then 0 else 1 end")
            ->orderByDesc('end_date')->orderByDesc('id')
            ->first(['id', 'title', 'status', 'overall_score', 'end_date']);

        $results = $latest
            ? $latest->results()->pluck('status', 'requirement_id')
            : collect();

        $posture = collect($this->scoreService->getFrameworkPosture($orgId))->firstWhere('framework_id', $framework->id);

        return Inertia::render('Compliance/Frameworks/Show', [
            'framework' => $framework,
            'mappedControls' => $mapped,
            'latestAssessment' => $latest,
            'latestResults' => $results,
            'posture' => $posture,
        ]);
    }

    /* ----------------------------- Assessments ----------------------------- */

    public function assessmentIndex(Request $request)
    {
        $assessments = ComplianceAssessment::with([
            'framework:id,short_name,slug',
            'leadAssessor:id,name',
        ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('framework'), fn ($q) => $q->where('framework_id', $request->framework))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'))
            ->latest()->paginate(15)->withQueryString();

        $counts = ComplianceAssessment::select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        return Inertia::render('Compliance/Assessments/Index', [
            'assessments' => $assessments,
            'filters' => $request->only(['status', 'framework', 'search']),
            'frameworks' => ControlFramework::where('is_active', true)->orderBy('sort_order')->get(['id', 'short_name']),
            'statusCounts' => $counts,
            'can' => ['create' => auth()->user()->can('create compliance-assessments')],
        ]);
    }

    public function assessmentCreate(Request $request)
    {
        return Inertia::render('Compliance/Assessments/Create', [
            'frameworks' => ControlFramework::where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn ($fw) => $fw->toArray() + ['assessable_count' => ComplianceAssessment::assessableRequirements($fw->id)->count()]),
            'users' => User::where('organization_id', $this->orgId())->orderBy('name')->get(['id', 'name']),
            'preselect' => $request->integer('framework') ?: null,
        ]);
    }

    public function assessmentStore(Request $request)
    {
        $orgId = $this->orgId();
        $validated = $request->validate([
            'framework_id' => ['required', Rule::exists('control_frameworks', 'id')->where('is_active', true)],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'lead_assessor_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $start = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : today();
        $validated['organization_id'] = $orgId;
        $validated['start_date'] = $start;
        $validated['status'] = $start->isFuture() ? 'planned' : 'in_progress';

        $assessment = DB::transaction(function () use ($validated) {
            $assessment = ComplianceAssessment::create($validated);

            // Results for the assessable (leaf) requirements only; domain headings are not assessed.
            foreach (ComplianceAssessment::assessableRequirements($validated['framework_id']) as $req) {
                ComplianceResult::create([
                    'assessment_id' => $assessment->id,
                    'requirement_id' => $req->id,
                    'control_id' => $this->primaryControlFor($req->id),
                    'status' => 'not_assessed',
                ]);
            }
            $assessment->recalculateScore();

            return $assessment;
        });

        return redirect()->route('compliance-assessments.show', $assessment)
            ->with('success', $assessment->status === 'planned' ? 'Assessment scheduled.' : 'Assessment started.');
    }

    public function assessmentShow(ComplianceAssessment $complianceAssessment)
    {
        $complianceAssessment->load([
            'framework:id,name,short_name,slug',
            'leadAssessor:id,name',
            'results' => fn ($q) => $q->with([
                'requirement:id,requirement_code,title,description,section,parent_id',
                'requirement.parent:id,requirement_code,title',
                'assessor:id,name',
                'control:id,control_code,title,effectiveness',
            ])->withCount('evidence')->orderBy('requirement_id'),
        ]);

        $gapsByRequirement = Gap::where('assessment_id', $complianceAssessment->id)
            ->get(['id', 'gap_code', 'requirement_id', 'status', 'severity'])
            ->keyBy('requirement_id');

        $controls = Control::orderBy('control_code')->get(['id', 'control_code', 'title']);

        $user = auth()->user();

        return Inertia::render('Compliance/Assessments/Show', [
            'assessment' => $complianceAssessment,
            'resultStatuses' => ComplianceResult::STATUSES,
            'gapsByRequirement' => $gapsByRequirement,
            'controls' => $controls,
            'users' => User::where('organization_id', $this->orgId())->orderBy('name')->get(['id', 'name']),
            'can' => [
                'assess' => $user->can('edit compliance-assessments'),
                'delete' => $user->can('delete compliance-assessments'),
                'evidence' => $user->can('create evidence'),
            ],
        ]);
    }

    public function assessmentUpdate(Request $request, ComplianceAssessment $complianceAssessment)
    {
        $orgId = $this->orgId();
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'lead_assessor_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'summary' => 'nullable|string|max:5000',
        ]);

        $complianceAssessment->update($validated);

        return back()->with('success', 'Assessment details updated.');
    }

    /** Lifecycle: start (planned → in progress), complete, cancel, reopen (completed/cancelled → in progress). */
    public function assessmentTransition(Request $request, ComplianceAssessment $complianceAssessment)
    {
        $validated = $request->validate([
            'action' => 'required|in:start,complete,cancel,reopen',
            'summary' => 'nullable|string|max:5000',
        ]);
        $a = $complianceAssessment;

        $allowed = [
            'start' => ['planned'],
            'complete' => ['in_progress'],
            'cancel' => ['planned', 'in_progress'],
            'reopen' => ['completed', 'cancelled'],
        ][$validated['action']];

        if (! in_array($a->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "A {$a->status} assessment cannot be {$validated['action']}ed."]);
        }

        switch ($validated['action']) {
            case 'start':
                $a->update(['status' => 'in_progress', 'start_date' => $a->start_date && $a->start_date->isPast() ? $a->start_date : today()]);
                $message = 'Assessment started.';
                break;
            case 'complete':
                $remaining = $a->notAssessedCount();
                if ($remaining > 0) {
                    throw ValidationException::withMessages(['status' => "{$remaining} requirement(s) are still not assessed. Assess them (or mark them not applicable) before completing."]);
                }
                $a->recalculateScore();
                $a->update(['status' => 'completed', 'end_date' => today(), 'summary' => $validated['summary'] ?? $a->summary]);
                $message = 'Assessment completed — the compliance posture now reflects this result.';
                break;
            case 'cancel':
                $a->update(['status' => 'cancelled', 'end_date' => today()]);
                $message = 'Assessment cancelled.';
                break;
            default:
                $a->update(['status' => 'in_progress', 'end_date' => null]);
                $message = 'Assessment reopened.';
        }

        return back()->with('success', $message);
    }

    public function assessmentDestroy(ComplianceAssessment $complianceAssessment)
    {
        if ($complianceAssessment->status === 'completed') {
            throw ValidationException::withMessages(['status' => 'A completed assessment is an audit record and cannot be deleted. Cancel or reopen it instead.']);
        }

        $title = $complianceAssessment->title;
        $complianceAssessment->delete();

        return redirect()->route('compliance-assessments.index')->with('success', "Assessment \"{$title}\" deleted.");
    }

    public function updateResult(Request $request, ComplianceResult $result)
    {
        // Results are not tenant-scoped themselves; the parent assessment is. Resolving it through
        // the scoped model 404s for another organisation's result.
        $assessment = ComplianceAssessment::findOrFail($result->assessment_id);

        if (! $assessment->isEditable()) {
            throw ValidationException::withMessages(['status' => "This assessment is {$assessment->status} — reopen it to change results."]);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(ComplianceResult::STATUSES)],
            'findings' => [Rule::requiredIf(in_array($request->status, ['non_compliant', 'partially_compliant'], true)), 'nullable', 'string', 'max:5000'],
            'recommendations' => 'nullable|string|max:5000',
            'control_id' => ['nullable', Rule::exists('controls', 'id')->where('organization_id', $assessment->organization_id)->whereNull('deleted_at')],
        ], [
            'findings.required' => 'Record the finding that supports a non- or partially-compliant result.',
        ]);

        $validated['assessed_by'] = $validated['status'] === 'not_assessed' ? null : auth()->id();
        $validated['assessed_at'] = $validated['status'] === 'not_assessed' ? null : now();

        DB::transaction(function () use ($result, $validated, $assessment) {
            $result->update($validated);
            $result->setRelation('assessment', $assessment);

            if ($assessment->status === 'planned') {
                $assessment->update(['status' => 'in_progress', 'start_date' => $assessment->start_date ?? today()]);
            }
            $assessment->recalculateScore();
            $this->gapService->syncForResult($result->fresh('requirement'), auth()->user());
        });

        return back()->with('success', 'Result recorded.');
    }

    /** The first live control mapped (fully, then partially) to a requirement — a sensible default for "tested control". */
    private function primaryControlFor(int $requirementId): ?int
    {
        return DB::table('control_framework_mappings as m')
            ->join('controls as c', 'c.id', '=', 'm.control_id')
            ->where('m.requirement_id', $requirementId)
            ->where('c.organization_id', $this->orgId())
            ->whereNull('c.deleted_at')
            ->orderByRaw("case m.coverage when 'full' then 0 when 'partial' then 1 else 2 end")
            ->value('c.id');
    }
}
