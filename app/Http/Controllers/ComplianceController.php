<?php

namespace App\Http\Controllers;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceResult;
use App\Models\ControlFramework;
use App\Models\FrameworkRequirement;
use App\Models\Gap;
use App\Models\User;
use App\Services\ComplianceScoreService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    public function __construct(
        private ComplianceScoreService $scoreService
    ) {}

    public function dashboard()
    {
        $orgId = auth()->user()->organization_id;
        $stats = $this->scoreService->getComplianceStats($orgId);
        $controlStats = $this->scoreService->getControlEffectivenessStats($orgId);

        $recentAssessments = ComplianceAssessment::with('framework:id,short_name,slug')
            ->latest()->limit(5)->get();

        $openGaps = Gap::where('status', '!=', 'closed')
            ->with(['requirement:id,requirement_code,title', 'assignee:id,name'])
            ->orderBy('priority')->limit(10)->get();

        return Inertia::render('Compliance/Dashboard', [
            'stats' => $stats,
            'controlStats' => $controlStats,
            'recentAssessments' => $recentAssessments,
            'openGaps' => $openGaps,
        ]);
    }

    // Framework browser
    public function frameworkIndex()
    {
        $frameworks = ControlFramework::withCount('requirements')
            ->where('is_active', true)->orderBy('sort_order')->get();

        return Inertia::render('Compliance/Frameworks/Index', [
            'frameworks' => $frameworks,
        ]);
    }

    public function frameworkShow(ControlFramework $framework)
    {
        $framework->load(['topLevelRequirements' => fn ($q) => $q->withCount('children')->with('children')]);

        return Inertia::render('Compliance/Frameworks/Show', [
            'framework' => $framework,
        ]);
    }

    // Compliance Assessments
    public function assessmentIndex()
    {
        $assessments = ComplianceAssessment::with([
            'framework:id,short_name,slug',
            'leadAssessor:id,name',
        ])->latest()->paginate(15)->withQueryString();

        return Inertia::render('Compliance/Assessments/Index', [
            'assessments' => $assessments,
        ]);
    }

    public function assessmentCreate()
    {
        return Inertia::render('Compliance/Assessments/Create', [
            'frameworks' => ControlFramework::where('is_active', true)->withCount('requirements')->get(),
            'users' => User::where('organization_id', auth()->user()->organization_id)->select('id', 'name')->get(),
        ]);
    }

    public function assessmentStore(Request $request)
    {
        $validated = $request->validate([
            'framework_id' => 'required|exists:control_frameworks,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'lead_assessor_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['status'] = 'in_progress';
        $validated['start_date'] = now();

        $assessment = ComplianceAssessment::create($validated);

        // Pre-populate results for each requirement
        $requirements = FrameworkRequirement::where('framework_id', $validated['framework_id'])->get();
        foreach ($requirements as $req) {
            ComplianceResult::create([
                'assessment_id' => $assessment->id,
                'requirement_id' => $req->id,
                'status' => 'not_assessed',
            ]);
        }

        $assessment->update(['total_requirements' => $requirements->count()]);

        return redirect()->route('compliance-assessments.show', $assessment)
            ->with('success', 'Assessment started.');
    }

    public function assessmentShow(ComplianceAssessment $complianceAssessment)
    {
        $complianceAssessment->load([
            'framework:id,name,short_name,slug',
            'leadAssessor:id,name',
            'results' => fn ($q) => $q->with([
                'requirement:id,requirement_code,title,section,parent_id',
                'assessor:id,name',
            ])->orderBy('requirement_id'),
        ]);

        return Inertia::render('Compliance/Assessments/Show', [
            'assessment' => $complianceAssessment,
            'resultStatuses' => ComplianceResult::STATUSES,
        ]);
    }

    public function updateResult(Request $request, ComplianceResult $result)
    {
        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', ComplianceResult::STATUSES),
            'findings' => 'nullable|string',
            'recommendations' => 'nullable|string',
        ]);

        $validated['assessed_by'] = auth()->id();
        $validated['assessed_at'] = now();
        $result->update($validated);

        // Recalculate assessment score
        $result->assessment->recalculateScore();

        // Auto-create gap for non-compliant results
        if (in_array($validated['status'], ['non_compliant', 'partially_compliant'])) {
            Gap::firstOrCreate(
                [
                    'organization_id' => auth()->user()->organization_id,
                    'assessment_id' => $result->assessment_id,
                    'requirement_id' => $result->requirement_id,
                ],
                [
                    'gap_code' => Gap::generateNextCode(auth()->user()->organization_id),
                    'title' => 'Gap: ' . $result->requirement->title,
                    'severity' => $validated['status'] === 'non_compliant' ? 'high' : 'medium',
                    'status' => 'identified',
                    'priority' => $validated['status'] === 'non_compliant' ? 2 : 3,
                ]
            );
        }

        return back()->with('success', 'Result updated.');
    }

    // Evidence
    public function evidenceIndex()
    {
        $evidence = \App\Models\Evidence::with(['uploader:id,name', 'reviewer:id,name'])
            ->latest()->paginate(20)->withQueryString();

        return Inertia::render('Compliance/Evidence/Index', ['evidence' => $evidence]);
    }

    public function evidenceStore(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:document,screenshot,url,attestation,log',
            'evidenceable_type' => 'required|string',
            'evidenceable_id' => 'required|integer',
            'url' => 'nullable|url',
            'file' => 'nullable|file|max:20480',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['uploaded_by'] = auth()->id();

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $validated['file_path'] = $file->store('evidence', 'public');
            $validated['file_name'] = $file->getClientOriginalName();
            $validated['file_size'] = $file->getSize();
            $validated['mime_type'] = $file->getMimeType();
        }

        \App\Models\Evidence::create($validated);

        return back()->with('success', 'Evidence uploaded.');
    }

    // Gap Analysis
    public function gapIndex()
    {
        $gaps = Gap::with([
            'requirement:id,requirement_code,title',
            'assignee:id,name',
            'assessment:id,title',
        ])->orderBy('priority')->paginate(20)->withQueryString();

        return Inertia::render('Compliance/GapAnalysis/Index', ['gaps' => $gaps]);
    }
}
