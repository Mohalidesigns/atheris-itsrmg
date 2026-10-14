<?php

namespace App\Http\Controllers\Ea;

use App\Http\Controllers\Controller;
use App\Models\Ea\MaturityAssessment;
use App\Models\Ea\MaturityQuestion;
use App\Models\Ea\MaturityResponse;
use App\Services\Ea\Maturity\Cmmi;
use App\Services\Ea\Maturity\DomainEvidence;
use App\Services\Ea\Maturity\MaturityAssessmentService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * CBN EA maturity (ATH-VAL-015): the five blueprint domains rated on the CMMI scale. Replaces the
 * read-only page over placeholder questions and random answers.
 */
class MaturityController extends Controller
{
    public function __construct(private MaturityAssessmentService $service) {}

    public function index(Request $request, DomainEvidence $evidence)
    {
        $orgId = (int) $request->user()->organization_id;
        $assessments = MaturityAssessment::query()->with(['creator:id,name', 'submitter:id,name', 'approver:id,name'])
            ->where('framework', MaturityAssessmentService::FRAMEWORK)->orderByDesc('year')->orderByDesc('id')->get();
        $approved = $assessments->firstWhere('status', 'approved');
        $open = $assessments->first(fn ($a) => in_array($a->status, ['draft', 'submitted'], true));

        return Inertia::render('Ea/Maturity/Index', [
            'domains' => $this->service->domains()->map(fn ($d) => ['code' => $d->code, 'name' => $d->name, 'description' => $d->description]),
            'current' => $approved ? ['id' => $approved->id, 'title' => $approved->title, 'year' => $approved->year, 'approved_at' => $approved->approved_at,
                'approver' => $approved->approver?->name, 'results' => $approved->results] : null,
            'open' => $open ? ['id' => $open->id, 'title' => $open->title, 'status' => $open->status, 'progress' => $this->service->results($open)] : null,
            'history' => $assessments->where('status', 'approved')->merge($assessments->where('status', 'superseded'))->sortBy('year')
                ->map(fn ($a) => ['id' => $a->id, 'year' => $a->year, 'status' => $a->status, 'overall' => $a->overall_score,
                    'domains' => collect($a->results['domains'] ?? [])->map(fn ($d) => $d['level'])->all()])->values(),
            'assessments' => $assessments->map(fn ($a) => $a->only(['id', 'title', 'year', 'status', 'overall_score', 'submitted_at', 'approved_at'])
                + ['creator' => $a->creator?->name, 'approver' => $a->approver?->name]),
            'evidence' => $evidence->domains($orgId),
            'levels' => Cmmi::LEVELS,
            'defaultTarget' => $this->service->defaultTarget($orgId),
            'can' => ['start' => $request->user()->can('create ea') && ! $open],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['year' => 'required|integer|min:2020|max:'.(now()->year + 1), 'title' => 'nullable|string|max:255']);
        $assessment = $this->service->start($request->user(), (int) $data['year'], $data['title'] ?? null);

        return redirect()->route('ea.maturity.show', $assessment)->with('success', 'Assessment started. Rate each practice area; levels 3 and above need evidence.');
    }

    public function show(Request $request, MaturityAssessment $assessment, DomainEvidence $evidence)
    {
        $assessment->load(['creator:id,name', 'submitter:id,name', 'approver:id,name']);
        $user = $request->user();

        return Inertia::render('Ea/Maturity/Show', [
            'assessment' => $assessment,
            'domains' => $this->service->domains(),
            'responses' => MaturityResponse::where('assessment_id', $assessment->id)->with('answerer:id,name')->get()->keyBy('question_id'),
            'results' => $assessment->status === 'approved' && $assessment->results ? $assessment->results : $this->service->results($assessment),
            'evidence' => $evidence->practices((int) $assessment->organization_id),
            'levels' => Cmmi::LEVELS,
            'rules' => ['note' => Cmmi::EVIDENCE_NOTE, 'measured' => Cmmi::MEASURED, 'overstatement' => Cmmi::OVERSTATEMENT_GAP],
            'can' => [
                'answer' => $assessment->status === 'draft' && $user->can('edit ea'),
                'approve' => $assessment->status === 'submitted' && $user->can('approve ea') && (int) $assessment->submitted_by !== (int) $user->id,
            ],
        ]);
    }

    public function answer(Request $request, $assessment, $question)
    {
        [$assessment, $question] = [$this->assessment($assessment), MaturityQuestion::findOrFail($question)];
        abort_unless($question->domain?->framework === MaturityAssessmentService::FRAMEWORK, 404);
        $data = $request->validate([
            'level' => 'required|integer|between:1,5',
            'comment' => 'nullable|string|max:2000',
            'evidence_note' => 'nullable|string|max:2000',
            'evidence_refs' => 'nullable|array',
            'evidence_refs.*' => 'string|max:64',
        ]);
        $this->service->answer($assessment, $question, $data, $request->user());

        return back()->with('success', "{$question->code} rated level {$data['level']} (".Cmmi::name((int) $data['level']).').');
    }

    public function targets(Request $request, $assessment)
    {
        $assessment = $this->assessment($assessment);
        $data = $request->validate(['targets' => 'required|array', 'targets.*' => 'integer|between:1,5']);
        $this->service->setTargets($assessment, $data['targets']);

        return back()->with('success', 'Targets updated.');
    }

    public function submit(Request $request, $assessment)
    {
        $assessment = $this->assessment($assessment);
        $this->service->submit($assessment, $request->user());

        return back()->with('success', 'Submitted for approval.');
    }

    public function approve(Request $request, $assessment)
    {
        $assessment = $this->assessment($assessment);
        $data = $request->validate(['approval_note' => 'nullable|string|max:2000']);
        $this->service->approve($assessment, $request->user(), $data['approval_note'] ?? null);

        return back()->with('success', 'Assessment approved. It is now the bank\'s rated maturity.');
    }

    public function returnToDraft(Request $request, $assessment)
    {
        $assessment = $this->assessment($assessment);
        $data = $request->validate(['approval_note' => 'required|string|min:10|max:2000']);
        $this->service->returnToDraft($assessment, $request->user(), $data['approval_note']);

        return back()->with('success', 'Returned to the assessor.');
    }

    /**
     * Write routes resolve the assessment here, after the permission middleware, so a user without
     * the permission is refused (403) whether or not the record exists, rather than learning
     * which ids exist from a 404 (EaAuthorizationTest). The tenant scope still applies.
     */
    private function assessment($id): MaturityAssessment
    {
        return MaturityAssessment::findOrFail($id);
    }
}
