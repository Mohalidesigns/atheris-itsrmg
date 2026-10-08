<?php

namespace App\Modules\CBNCSAT\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CBNCSAT\Models\CsatAiRecommendation;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatInstitutionProfile;
use App\Modules\CBNCSAT\Models\CsatIrNarrative;
use App\Modules\CBNCSAT\Models\CsatIrQuestion;
use App\Modules\CBNCSAT\Models\CsatIrResponse;
use App\Modules\CBNCSAT\Models\CsatMaCompensatingControl;
use App\Modules\CBNCSAT\Models\CsatMaNarrative;
use App\Modules\CBNCSAT\Models\CsatMaResponse;
use App\Modules\CBNCSAT\Models\CsatMaScore;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use App\Modules\CBNCSAT\Models\CsatStakeholderEngagement;
use App\Modules\CBNCSAT\Models\CsatThreat;
use App\Modules\CBNCSAT\Models\CsatThreatCatalogue;
use App\Modules\CBNCSAT\Models\CsatVulnerability;
use App\Modules\CBNCSAT\Services\CsatInsightService;
use App\Modules\CBNCSAT\Services\CsatWorkflowService;
use App\Modules\CBNCSAT\Services\InherentRiskScoringService;
use App\Modules\CBNCSAT\Services\MaturityScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CsatAssessmentController extends Controller
{
    public function __construct(
        private InherentRiskScoringService $irScoring,
        private MaturityScoringService $maScoring,
        private CsatWorkflowService $workflowService,
        private CsatInsightService $insights,
    ) {}

    // ===== Assessment CRUD =====

    public function index()
    {
        $assessments = CsatAssessment::where('organization_id', auth()->user()->organization_id)
            ->with('creator:id,name')
            ->orderByDesc('assessment_year')
            ->get();

        return Inertia::render('CSAT/Index', [
            'assessments' => $assessments,
        ]);
    }

    public function create()
    {
        return Inertia::render('CSAT/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'assessment_year' => 'required|integer|min:2020|max:'.(date('Y') + 1),
            'submission_deadline' => 'nullable|date',
        ]);

        // The unique index (org, year, framework) also covers archived cycles.
        $exists = CsatAssessment::withTrashed()
            ->where('organization_id', auth()->user()->organization_id)
            ->where('assessment_year', $validated['assessment_year'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['assessment_year' => 'An assessment for this year already exists.']);
        }

        $assessment = DB::transaction(function () use ($validated) {
            $assessment = CsatAssessment::create([
                'organization_id' => auth()->user()->organization_id,
                'assessment_year' => $validated['assessment_year'],
                'submission_deadline' => $validated['submission_deadline'] ?? null,
                'created_by' => auth()->id(),
                'status' => 'draft',
            ]);
            CsatMaNarrative::provisionFor($assessment->id);

            return $assessment;
        });

        return redirect()->route('csat.overview', $assessment)->with('success', 'Assessment created.');
    }

    public function overview(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $irScores = $assessment->irCategoryScores()->get();
        $maScores = $assessment->maScores()->where('score_type', 'domain')->get();
        $profile = $assessment->institutionProfile;

        $irTotal = CsatIrQuestion::where('is_active', true)->count();
        $irAnswered = $assessment->irResponses()->count();
        $maTotal = CsatMaStatement::where('is_active', true)->count();
        $maAnswered = $assessment->maResponses()->whereNotNull('response')->count();

        return Inertia::render('CSAT/Overview', [
            'assessment' => $assessment,
            'editable' => $this->workflowService->isEditable($assessment),
            'checklist' => $this->workflowService->checklist($assessment),
            'profile' => $profile,
            'irScores' => $irScores,
            'maScores' => $maScores,
            'stats' => [
                'ir_total' => $irTotal,
                'ir_answered' => $irAnswered,
                'ir_pct' => $irTotal > 0 ? round(($irAnswered / $irTotal) * 100) : 0,
                'ma_total' => $maTotal,
                'ma_answered' => $maAnswered,
                'ma_pct' => $maTotal > 0 ? round(($maAnswered / $maTotal) * 100) : 0,
                'threats_count' => $assessment->threats()->count(),
                'vulnerabilities_count' => $assessment->vulnerabilities()->count(),
                'ir_categories' => count(CsatIrQuestion::CATEGORY_NAMES),
                'ma_domains' => count(CsatMaStatement::DOMAIN_NAMES),
            ],
        ]);
    }

    // ===== Institution Profile =====

    public function institutionProfile(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $profile = $assessment->institutionProfile;
        $stakeholders = $assessment->stakeholderEngagement()->get();

        return Inertia::render('CSAT/InstitutionProfile', [
            'assessment' => $assessment,
            'profile' => $profile,
            'stakeholders' => $stakeholders,
            'stakeholderRoles' => CsatStakeholderEngagement::ROLES,
            'editable' => $this->workflowService->isEditable($assessment),
        ]);
    }

    public function saveInstitutionProfile(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'institution_name' => 'required|string|max:255',
            'cbn_licence_type' => 'required|in:dmb,mfb,mortgage_bank,psb,merchant_bank,development_finance',
            'head_office_address' => 'required|string',
            'ciso_name' => 'required|string|max:255',
            'ciso_email' => 'required|email',
            'ciso_phone' => 'required|string|max:20',
            'ciso_grade' => 'nullable|string|max:100',
            'ciso_reporting_line' => 'nullable|string|max:255',
            'parent_bank_name' => 'nullable|string|max:255',
        ]);

        CsatInstitutionProfile::updateOrCreate(
            ['assessment_id' => $assessment->id],
            $validated
        );

        return back()->with('success', 'Institution profile saved.');
    }

    public function saveStakeholder(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'role_key' => ['required', Rule::in(array_keys(CsatStakeholderEngagement::ROLES))],
            'engagement_status' => 'required|in:yes,no,na,yes_with_comment',
            'comment' => 'nullable|string|max:2000|required_if:engagement_status,no,yes_with_comment',
            'name_of_person' => 'nullable|string|max:255',
        ]);

        $roleKey = $validated['role_key'];
        // Only overwrite fields the client actually sent, so changing the status keeps the name/comment.
        $values = ['role_label' => CsatStakeholderEngagement::ROLES[$roleKey], 'engagement_status' => $validated['engagement_status']]
            + collect($validated)->only(['comment', 'name_of_person'])->all();

        CsatStakeholderEngagement::updateOrCreate(
            ['assessment_id' => $assessment->id, 'role_key' => $roleKey],
            $values
        );

        return back()->with('success', 'Stakeholder engagement saved.');
    }

    // ===== Inherent Risk =====

    public function inherentRiskDashboard(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $scores = $this->irScoring->calculateCompositeRisk($assessment->id);

        return Inertia::render('CSAT/InherentRisk/Dashboard', [
            'assessment' => $assessment,
            'scores' => $scores,
        ]);
    }

    public function inherentRiskQuestions(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $questions = CsatIrQuestion::where('is_active', true)->orderBy('category_code')->orderBy('question_number')->get();
        $responses = $assessment->irResponses()->get()->keyBy('question_id');

        return Inertia::render('CSAT/InherentRisk/Questions', [
            'assessment' => $assessment,
            'editable' => $this->workflowService->isEditable($assessment),
            'questions' => $questions,
            'responses' => $responses,
        ]);
    }

    public function saveIrResponse(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'question_id' => 'required|exists:csat_ir_questions,id',
            'selected_level' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
        ]);

        CsatIrResponse::updateOrCreate(
            ['assessment_id' => $assessment->id, 'question_id' => $validated['question_id']],
            [
                'selected_level' => $validated['selected_level'],
                'completed_by' => auth()->id(),
                'completed_at' => now(),
            ] + ($request->has('comment') ? ['comment' => $validated['comment']] : [])
        );

        $this->irScoring->recalculateAndPersist($assessment->id);

        if ($assessment->status === 'draft') {
            $assessment->update(['status' => 'in_progress']);
        }

        return back()->with('success', 'Response saved.');
    }

    public function inherentRiskNarratives(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $narratives = $assessment->irNarratives()->get();

        $narrativeFields = $this->getIrNarrativeFields();

        return Inertia::render('CSAT/InherentRisk/Narratives', [
            'assessment' => $assessment,
            'editable' => $this->workflowService->isEditable($assessment),
            'narratives' => $narratives,
            'narrativeFields' => $narrativeFields,
        ]);
    }

    public function saveIrNarrative(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'category_code' => 'required|integer|min:1|max:5',
            'narrative_key' => 'required|string|max:100',
            'narrative_value' => 'nullable|string',
        ]);

        $field = collect($this->getIrNarrativeFields())
            ->firstWhere('category_code', $validated['category_code'])['fields'] ?? [];
        $field = collect($field)->firstWhere('key', $validated['narrative_key']);
        abort_unless($field, 422, 'Unknown narrative field for this category.');

        CsatIrNarrative::updateOrCreate(
            ['assessment_id' => $assessment->id, 'category_code' => $validated['category_code'], 'narrative_key' => $validated['narrative_key']],
            ['narrative_label' => $field['label'] ?? $validated['narrative_key'], 'narrative_value' => $validated['narrative_value']]
        );

        return back()->with('success', 'Narrative saved.');
    }

    // ===== Maturity Assessment =====

    public function maturityDashboard(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $domainScores = $assessment->maScores()->where('score_type', 'domain')->orderBy('scope_code')->get();
        $factorScores = $assessment->maScores()->where('score_type', 'factor')->orderBy('scope_code')->get();
        $componentScores = $assessment->maScores()->where('score_type', 'component')->orderBy('scope_code')->get();

        return Inertia::render('CSAT/Maturity/Dashboard', [
            'assessment' => $assessment,
            'domainScores' => $domainScores,
            'factorScores' => $factorScores,
            'componentScores' => $componentScores,
            'irComposite' => $this->irScoring->calculateCompositeRisk($assessment->id),
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
            'maturityLevels' => CsatMaStatement::MATURITY_LEVELS,
        ]);
    }

    public function maturityAssessment(CsatAssessment $assessment, Request $request)
    {
        $this->authorizeAssessment($assessment);

        $domainFilter = (int) ($request->get('domain') ?: 1);

        $statements = CsatMaStatement::where('is_active', true)->where('domain_code', $domainFilter)
            ->orderBy('factor_code')->orderBy('component_code')->orderBy('maturity_level')->orderBy('sequence')->get();

        $responses = $assessment->maResponses()->with('compensatingControl')->get()->keyBy('statement_id');

        // Sidebar progress for every domain, not just the one being viewed.
        $answeredByDomain = $assessment->maResponses()->whereNotNull('response')
            ->join('csat_ma_statements as s', 's.id', '=', 'csat_ma_responses.statement_id')
            ->where('s.is_active', true)
            ->selectRaw('s.domain_code, count(*) as n')->groupBy('s.domain_code')->pluck('n', 'domain_code');
        $totalByDomain = CsatMaStatement::where('is_active', true)
            ->selectRaw('domain_code, count(*) as n')->groupBy('domain_code')->pluck('n', 'domain_code');

        return Inertia::render('CSAT/Maturity/Assessment', [
            'assessment' => $assessment,
            'editable' => $this->workflowService->isEditable($assessment),
            'statements' => $statements,
            'responses' => $responses,
            'domainProgress' => collect(CsatMaStatement::DOMAIN_NAMES)->map(fn ($name, $code) => [
                'answered' => (int) ($answeredByDomain[$code] ?? 0), 'total' => (int) ($totalByDomain[$code] ?? 0),
            ]),
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
            'maturityLevels' => CsatMaStatement::MATURITY_LEVELS,
            'currentDomain' => $domainFilter,
        ]);
    }

    public function saveMaResponse(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'statement_id' => 'required|exists:csat_ma_statements,id',
            'response' => 'required|in:yes,yes_cc,no,na',
            'comment' => 'nullable|string',
        ]);

        $maResponse = CsatMaResponse::updateOrCreate(
            ['assessment_id' => $assessment->id, 'statement_id' => $validated['statement_id']],
            [
                'response' => $validated['response'],
                'has_compensating_control' => $validated['response'] === 'yes_cc',
                'responded_by' => auth()->id(),
                'responded_at' => now(),
            ] + ($request->has('comment') ? ['comment' => $validated['comment']] : [])
        );
        if ($validated['response'] !== 'yes_cc') {
            $maResponse->compensatingControl()->delete();
        }

        $this->maScoring->recalculateAndPersist($assessment->id);

        if ($assessment->status === 'draft') {
            $assessment->update(['status' => 'in_progress']);
        }

        return back()->with('success', $validated['response'] === 'yes_cc' && ! $maResponse->compensatingControl()->exists()
            ? 'Saved — document the compensating control for this Yes [CC] answer.'
            : 'Response saved.');
    }

    public function saveCompensatingControl(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'response_id' => ['required', Rule::exists('csat_ma_responses', 'id')
                ->where('assessment_id', $assessment->id)->where('response', 'yes_cc')],
            'control_name' => 'required|string|max:500',
            'control_description' => 'required|string',
            'effectiveness_level' => 'required|in:high,medium,low',
            'planned_permanent_date' => 'required|date',
        ], ['response_id.exists' => 'A compensating control can only be recorded against a Yes [CC] answer in this assessment.']);

        CsatMaCompensatingControl::updateOrCreate(
            ['response_id' => $validated['response_id']],
            [
                'control_name' => $validated['control_name'],
                'control_description' => $validated['control_description'],
                'effectiveness_level' => $validated['effectiveness_level'],
                'planned_permanent_date' => $validated['planned_permanent_date'],
                'created_by' => auth()->id(),
            ]
        );

        return back()->with('success', 'Compensating control saved.');
    }

    public function maturityNarratives(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $narratives = $assessment->maNarratives()->get();

        return Inertia::render('CSAT/Maturity/Narratives', [
            'assessment' => $assessment,
            'editable' => $this->workflowService->isEditable($assessment),
            'narratives' => $narratives,
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
        ]);
    }

    public function saveMaNarrative(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'id' => ['required', Rule::exists('csat_ma_narratives', 'id')->where('assessment_id', $assessment->id)],
            'response_text' => 'nullable|string',
        ]);

        CsatMaNarrative::where('id', $validated['id'])
            ->where('assessment_id', $assessment->id)
            ->update([
                'response_text' => $validated['response_text'],
                'responded_by' => auth()->id(),
            ]);

        return back()->with('success', 'Narrative saved.');
    }

    public function maturityTargets(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $domainScores = $assessment->maScores()->where('score_type', 'domain')->get();
        $componentScores = $assessment->maScores()->where('score_type', 'component')->get();

        // A gap = a "No" at or below its domain's target level (all "No" answers when no target is set).
        $targets = $domainScores->mapWithKeys(fn ($d) => [(int) ltrim($d->scope_code, 'D') => $d->target_maturity_level]);
        $gapStatements = CsatMaResponse::where('assessment_id', $assessment->id)
            ->where('response', 'no')
            ->with('statement')
            ->get()
            ->filter(fn ($r) => $r->statement && (! ($targets[$r->statement->domain_code] ?? null)
                || $r->statement->maturity_level <= $targets[$r->statement->domain_code]))
            ->sortBy(fn ($r) => [$r->statement->domain_code, $r->statement->maturity_level])
            ->values();

        return Inertia::render('CSAT/Maturity/Targets', [
            'assessment' => $assessment,
            'editable' => $this->workflowService->isEditable($assessment),
            'domainScores' => $domainScores,
            'componentScores' => $componentScores,
            'gapStatements' => $gapStatements,
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
            'maturityLevels' => CsatMaStatement::MATURITY_LEVELS,
        ]);
    }

    public function saveTarget(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'scope_code' => ['required', Rule::exists('csat_ma_scores', 'scope_code')->where('assessment_id', $assessment->id)],
            'target_maturity_level' => 'required|integer|min:1|max:5',
        ]);

        CsatMaScore::where('assessment_id', $assessment->id)
            ->where('scope_code', $validated['scope_code'])
            ->update(['target_maturity_level' => $validated['target_maturity_level']]);

        return back()->with('success', 'Target saved.');
    }

    // ===== Threats =====

    public function threats(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $threats = $assessment->threats()->with('createdBy:id,name')->orderByDesc('inherent_risk_score')->get()
            ->map(fn ($t) => $t->toArray() + ['created_by_name' => $t->createdBy?->name]);
        $catalogue = CsatThreatCatalogue::where('is_active', true)->orderBy('threat_name')->get();

        return Inertia::render('CSAT/Threats', [
            'assessment' => $assessment,
            'editable' => $this->workflowService->isEditable($assessment),
            'threats' => $threats,
            'catalogue' => $catalogue,
        ]);
    }

    public function storeThreat(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'threat_name' => 'required|string|max:500',
            'catalogue_threat_id' => 'nullable|exists:csat_threat_catalogue,id',
            'description' => 'nullable|string',
            'threat_source' => 'required|in:internal,external,natural',
            'threat_category' => 'required|in:technical,human,environmental',
            'likelihood' => 'required|in:high,moderate,low',
            'impact' => 'required|in:high,moderate,low',
            'mitigating_controls_desc' => 'nullable|string',
            'residual_risk_score' => 'nullable|integer|min:1|max:9',
            'comment' => 'nullable|string',
        ]);

        $assessment->threats()->create(array_merge($validated, ['created_by' => auth()->id()]));

        return back()->with('success', 'Threat added.');
    }

    public function updateThreat(Request $request, CsatAssessment $assessment, CsatThreat $threat)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureOwned($assessment, $threat->assessment_id);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'threat_name' => 'required|string|max:500',
            'description' => 'nullable|string',
            'threat_source' => 'required|in:internal,external,natural',
            'threat_category' => 'required|in:technical,human,environmental',
            'likelihood' => 'required|in:high,moderate,low',
            'impact' => 'required|in:high,moderate,low',
            'mitigating_controls_desc' => 'nullable|string',
            'residual_risk_score' => 'nullable|integer|min:1|max:9',
            'comment' => 'nullable|string',
        ]);

        $threat->update($validated);

        return back()->with('success', 'Threat updated.');
    }

    public function destroyThreat(CsatAssessment $assessment, CsatThreat $threat)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureOwned($assessment, $threat->assessment_id);
        $this->ensureEditable($assessment);
        $threat->delete();

        return back()->with('success', 'Threat removed.');
    }

    // ===== Vulnerabilities =====

    public function vulnerabilities(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $vulnerabilities = $assessment->vulnerabilities()->with(['assignee:id,name', 'createdBy:id,name'])->orderByDesc('composite_score')->get()
            ->map(fn ($v) => $v->toArray() + ['created_by_name' => $v->createdBy?->name, 'due_date_input' => $v->due_date?->format('Y-m-d')]);
        $users = User::where('organization_id', auth()->user()->organization_id)->orderBy('name')->select('id', 'name')->get();

        return Inertia::render('CSAT/Vulnerabilities', [
            'assessment' => $assessment,
            'editable' => $this->workflowService->isEditable($assessment),
            'vulnerabilities' => $vulnerabilities,
            'users' => $users,
        ]);
    }

    public function storeVulnerability(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'vulnerability_name' => 'required|string|max:500',
            'description' => 'nullable|string',
            'vulnerability_category' => 'required|in:people,process,technology',
            'likelihood_of_exploit' => 'required|in:high,moderate,low',
            'impact_if_exploited' => 'required|in:high,moderate,low',
            'mitigants_in_place' => 'required|boolean',
            'existing_mitigants' => 'nullable|string',
            'planned_mitigants' => 'nullable|string',
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id)],
            'due_date' => 'nullable|date',
            'comment' => 'nullable|string',
        ]);

        $assessment->vulnerabilities()->create(array_merge($validated, ['created_by' => auth()->id()]));

        return back()->with('success', 'Vulnerability added.');
    }

    public function updateVulnerability(Request $request, CsatAssessment $assessment, CsatVulnerability $vulnerability)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureOwned($assessment, $vulnerability->assessment_id);
        $this->ensureEditable($assessment);

        $validated = $request->validate([
            'vulnerability_name' => 'sometimes|string|max:500',
            'description' => 'nullable|string',
            'vulnerability_category' => 'sometimes|in:people,process,technology',
            'likelihood_of_exploit' => 'sometimes|in:high,moderate,low',
            'impact_if_exploited' => 'sometimes|in:high,moderate,low',
            'mitigants_in_place' => 'sometimes|boolean',
            'existing_mitigants' => 'nullable|string',
            'planned_mitigants' => 'nullable|string',
            'remediation_status' => 'sometimes|in:identified,assigned,in_progress,remediated,verified',
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id)],
            'due_date' => 'nullable|date',
            'comment' => 'nullable|string',
        ]);

        $vulnerability->update($validated);

        return back()->with('success', 'Vulnerability updated.');
    }

    public function destroyVulnerability(CsatAssessment $assessment, CsatVulnerability $vulnerability)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureOwned($assessment, $vulnerability->assessment_id);
        $this->ensureEditable($assessment);
        $vulnerability->delete();

        return back()->with('success', 'Vulnerability removed.');
    }

    // ===== Workflow =====

    public function workflow(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $user = auth()->user();
        $next = $this->workflowService->nextStage($assessment);
        $signed = $this->workflowService->currentCycle($assessment)->where('action', 'approved')->pluck('approver_id');

        return Inertia::render('CSAT/Workflow', [
            'assessment' => $assessment,
            'approvalRecords' => $assessment->approvalRecords()->orderBy('actioned_at')->orderBy('id')->get(),
            'stages' => $this->workflowService->stages($assessment),
            'nextStage' => $next,
            'checklist' => $this->workflowService->checklist($assessment),
            'editable' => $this->workflowService->isEditable($assessment),
            'can' => [
                'submit' => $user->can('edit csat') && $this->workflowService->isEditable($assessment),
                'approve' => $next !== null && ($user->hasRole('Super Admin') || $user->can($next['permission'])) && ! $signed->contains($user->id),
                'signedEarlierStage' => $next !== null && $signed->contains($user->id),
                'submitToCbn' => $assessment->status === 'approved' && $user->can('approve csat'),
                'export' => $user->can('export csat'),
            ],
        ]);
    }

    public function submitForApproval(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $comments = $request->validate(['comments' => 'nullable|string|max:2000'])['comments'] ?? null;
        $this->workflowService->submit($assessment, $request->user(), $comments);

        return back()->with('success', 'Submitted for approval — awaiting '.$this->workflowService->nextStage($assessment->fresh())['name'].'.');
    }

    public function approve(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $validated = $request->validate(['comments' => 'nullable|string|max:2000']);

        $stage = $this->workflowService->approve($assessment, $request->user(), $validated['comments'] ?? null);
        $next = $this->workflowService->nextStage($assessment->fresh());

        return back()->with('success', "Stage {$stage['number']} ({$stage['name']}) signed."
            .($next ? " Awaiting {$next['name']}." : ' Assessment fully approved — ready to submit to the CBN.'));
    }

    public function reject(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $validated = $request->validate(['comments' => 'required|string|max:2000']);

        $stage = $this->workflowService->returnForRevision($assessment, $request->user(), $validated['comments']);

        return back()->with('success', "Returned for revision at stage {$stage['number']} ({$stage['name']}).");
    }

    public function submitToCbn(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->workflowService->submitToCbn($assessment, $request->user());

        return back()->with('success', 'Assessment recorded as submitted to the CBN on '.now()->format('d M Y').'.');
    }

    public function updateDeadline(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureEditable($assessment);
        $validated = $request->validate(['submission_deadline' => 'required|date']);
        $assessment->update($validated);

        return back()->with('success', 'Submission deadline updated.');
    }

    /** CBN submission package (BR-AW-03) — printable summary of every section. */
    public function submissionPackage(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        return Inertia::render('CSAT/SubmissionPackage', [
            'assessment' => $assessment,
            'profile' => $assessment->institutionProfile,
            'stakeholders' => $assessment->stakeholderEngagement()->get(),
            'ir' => $this->irScoring->calculateCompositeRisk($assessment->id),
            'domainScores' => $assessment->maScores()->where('score_type', 'domain')->orderBy('scope_code')->get(),
            'factorScores' => $assessment->maScores()->where('score_type', 'factor')->orderBy('scope_code')->get(),
            'threats' => $assessment->threats()->orderByDesc('inherent_risk_score')->get(),
            'vulnerabilities' => $assessment->vulnerabilities()->with('assignee:id,name')->orderByDesc('composite_score')->get(),
            'approvalRecords' => $assessment->approvalRecords()->orderBy('actioned_at')->orderBy('id')->get(),
            'stages' => $this->workflowService->stages($assessment),
            'checklist' => $this->workflowService->checklist($assessment),
            'maturityLevels' => CsatMaStatement::MATURITY_LEVELS,
        ]);
    }

    // ===== Reports =====

    public function reports(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $irScores = $this->irScoring->calculateCompositeRisk($assessment->id);
        $domainScores = $assessment->maScores()->where('score_type', 'domain')->orderBy('scope_code')->get();
        $componentScores = $assessment->maScores()->where('score_type', 'component')->orderBy('scope_code')->get();
        $gapCount = CsatMaResponse::where('assessment_id', $assessment->id)->where('response', 'no')->count();
        $aiRecs = $assessment->aiRecommendations()->where('is_dismissed', false)->orderBy('priority_rank')->get();

        return Inertia::render('CSAT/Reports', [
            'assessment' => $assessment,
            'irScores' => $irScores,
            'domainScores' => $domainScores,
            'componentScores' => $componentScores,
            'gapCount' => $gapCount,
            'recommendations' => $aiRecs,
            'readiness' => $this->insights->readiness($assessment),
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
            'maturityLevels' => CsatMaStatement::MATURITY_LEVELS,
        ]);
    }

    // ===== AI Insights =====

    public function aiInsights(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $recommendations = $assessment->aiRecommendations()->orderBy('is_dismissed')->orderBy('priority_rank')->get();

        return Inertia::render('CSAT/AIInsights', [
            'assessment' => $assessment,
            'recommendations' => $recommendations,
            'readiness' => $this->insights->readiness($assessment),
            'engine' => CsatInsightService::ENGINE,
        ]);
    }

    public function generateInsights(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);
        $count = $this->insights->generate($assessment);

        return back()->with('success', "Insights refreshed — {$count} recommendation(s).");
    }

    public function dismissRecommendation(CsatAssessment $assessment, CsatAiRecommendation $recommendation)
    {
        $this->authorizeAssessment($assessment);
        $this->ensureOwned($assessment, $recommendation->assessment_id);
        $recommendation->update(['is_dismissed' => true]);

        return back()->with('success', 'Recommendation dismissed.');
    }

    // ===== Helpers =====

    private function authorizeAssessment(CsatAssessment $assessment): void
    {
        abort_unless((int) $assessment->organization_id === (int) auth()->user()->organization_id, 403);
    }

    /** Child records are bound globally by id — make sure they belong to this assessment. */
    private function ensureOwned(CsatAssessment $assessment, ?int $childAssessmentId): void
    {
        abort_unless((int) $childAssessmentId === (int) $assessment->id, 404);
    }

    /** Answers and registers are frozen once the cycle is submitted for approval. */
    private function ensureEditable(CsatAssessment $assessment): void
    {
        if (! $this->workflowService->isEditable($assessment)) {
            throw ValidationException::withMessages([
                'workflow' => 'This assessment is '.str_replace('_', ' ', $assessment->status).' and locked for editing. It must be returned for revision before changes can be made.',
            ]);
        }
    }

    private function getIrNarrativeFields(): array
    {
        return [
            ['category_code' => 1, 'category_name' => 'Technologies and Connection Types', 'fields' => [
                ['key' => 'isp_provider_names', 'label' => 'List all ISP providers and connection types'],
                ['key' => 'third_party_names', 'label' => 'List all third parties with system access and the nature of access'],
                ['key' => 'cloud_providers', 'label' => 'List all cloud service providers and services hosted'],
                ['key' => 'eol_systems', 'label' => 'List all end-of-life systems and compensating controls'],
                ['key' => 'fintech_partners', 'label' => 'List all FinTech integration partners'],
            ]],
            ['category_code' => 2, 'category_name' => 'Delivery Channels', 'fields' => [
                ['key' => 'online_banking_url', 'label' => 'Internet banking platform URL and vendor'],
                ['key' => 'mobile_app_details', 'label' => 'Mobile banking app details (platforms, download count)'],
                ['key' => 'pos_network', 'label' => 'PoS network details (count, processor, management model)'],
                ['key' => 'atm_network', 'label' => 'ATM network details (count, vendor, management model)'],
            ]],
            ['category_code' => 3, 'category_name' => 'Online/Mobile Products', 'fields' => [
                ['key' => 'card_programmes', 'label' => 'Card programmes details (schemes, volumes)'],
                ['key' => 'payment_channels', 'label' => 'Payment channels and transaction volumes'],
                ['key' => 'agent_banking', 'label' => 'Agent banking network details'],
            ]],
            ['category_code' => 4, 'category_name' => 'Organisational Characteristics', 'fields' => [
                ['key' => 'dr_facility', 'label' => 'DR facility location, ownership, and management details'],
                ['key' => 'offsite_backup', 'label' => 'Off-site backup location and recovery testing frequency'],
                ['key' => 'branch_locations', 'label' => 'Branch distribution and geographic coverage'],
                ['key' => 'it_staff_details', 'label' => 'IT/cybersecurity staffing levels and certifications held'],
            ]],
            ['category_code' => 5, 'category_name' => 'External Threats', 'fields' => [
                ['key' => 'incident_summary', 'label' => 'Summary of cybersecurity incidents in the past 12 months'],
                ['key' => 'threat_landscape', 'label' => 'Assessment of current threat landscape facing the institution'],
            ]],
        ];
    }
}
