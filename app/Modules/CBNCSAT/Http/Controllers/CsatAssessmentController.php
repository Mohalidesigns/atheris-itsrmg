<?php

namespace App\Modules\CBNCSAT\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatIrQuestion;
use App\Modules\CBNCSAT\Models\CsatIrResponse;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use App\Modules\CBNCSAT\Models\CsatMaResponse;
use App\Modules\CBNCSAT\Models\CsatMaCompensatingControl;
use App\Modules\CBNCSAT\Models\CsatMaNarrative;
use App\Modules\CBNCSAT\Models\CsatThreat;
use App\Modules\CBNCSAT\Models\CsatThreatCatalogue;
use App\Modules\CBNCSAT\Models\CsatVulnerability;
use App\Modules\CBNCSAT\Models\CsatInstitutionProfile;
use App\Modules\CBNCSAT\Models\CsatStakeholderEngagement;
use App\Modules\CBNCSAT\Models\CsatIrNarrative;
use App\Modules\CBNCSAT\Models\CsatMaScore;
use App\Modules\CBNCSAT\Models\CsatApprovalRecord;
use App\Modules\CBNCSAT\Models\CsatAiRecommendation;
use App\Modules\CBNCSAT\Services\InherentRiskScoringService;
use App\Modules\CBNCSAT\Services\MaturityScoringService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CsatAssessmentController extends Controller
{
    public function __construct(
        private InherentRiskScoringService $irScoring,
        private MaturityScoringService $maScoring,
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
            'assessment_year' => 'required|integer|min:2020|max:' . (date('Y') + 1),
            'submission_deadline' => 'nullable|date',
        ]);

        $exists = CsatAssessment::where('organization_id', auth()->user()->organization_id)
            ->where('assessment_year', $validated['assessment_year'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['assessment_year' => 'An assessment for this year already exists.']);
        }

        $assessment = CsatAssessment::create([
            'organization_id' => auth()->user()->organization_id,
            'assessment_year' => $validated['assessment_year'],
            'submission_deadline' => $validated['submission_deadline'] ?? null,
            'created_by' => auth()->id(),
            'status' => 'draft',
        ]);

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
        ]);
    }

    public function saveInstitutionProfile(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

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

        $validated = $request->validate([
            'role_key' => 'required|string|max:100',
            'engagement_status' => 'required|in:yes,no,na,yes_with_comment',
            'comment' => 'nullable|string',
            'name_of_person' => 'nullable|string|max:255',
        ]);

        $roleLabel = CsatStakeholderEngagement::ROLES[$validated['role_key']] ?? $validated['role_key'];

        CsatStakeholderEngagement::updateOrCreate(
            ['assessment_id' => $assessment->id, 'role_key' => $validated['role_key']],
            ['role_label' => $roleLabel, 'engagement_status' => $validated['engagement_status'], 'comment' => $validated['comment'], 'name_of_person' => $validated['name_of_person']]
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
            'questions' => $questions,
            'responses' => $responses,
        ]);
    }

    public function saveIrResponse(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $validated = $request->validate([
            'question_id' => 'required|exists:csat_ir_questions,id',
            'selected_level' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
        ]);

        CsatIrResponse::updateOrCreate(
            ['assessment_id' => $assessment->id, 'question_id' => $validated['question_id']],
            [
                'selected_level' => $validated['selected_level'],
                'comment' => $validated['comment'],
                'completed_by' => auth()->id(),
                'completed_at' => now(),
            ]
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
            'narratives' => $narratives,
            'narrativeFields' => $narrativeFields,
        ]);
    }

    public function saveIrNarrative(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $validated = $request->validate([
            'category_code' => 'required|integer|min:1|max:5',
            'narrative_key' => 'required|string|max:100',
            'narrative_value' => 'nullable|string',
        ]);

        $fields = collect($this->getIrNarrativeFields())->flatMap(fn($cat) => $cat['fields']);
        $field = $fields->firstWhere('key', $validated['narrative_key']);

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

        $domainScores = $assessment->maScores()->where('score_type', 'domain')->get();
        $componentScores = $assessment->maScores()->where('score_type', 'component')->get();
        $irScores = $assessment->irCategoryScores()->get();

        return Inertia::render('CSAT/Maturity/Dashboard', [
            'assessment' => $assessment,
            'domainScores' => $domainScores,
            'componentScores' => $componentScores,
            'irScores' => $irScores,
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
            'maturityLevels' => CsatMaStatement::MATURITY_LEVELS,
        ]);
    }

    public function maturityAssessment(CsatAssessment $assessment, Request $request)
    {
        $this->authorizeAssessment($assessment);

        $domainFilter = $request->get('domain');

        $query = CsatMaStatement::where('is_active', true);
        if ($domainFilter) {
            $query->where('domain_code', $domainFilter);
        }
        $statements = $query->orderBy('domain_code')->orderBy('factor_code')
            ->orderBy('component_code')->orderBy('maturity_level')->orderBy('sequence')->get();

        $responses = $assessment->maResponses()->get()->keyBy('statement_id');

        return Inertia::render('CSAT/Maturity/Assessment', [
            'assessment' => $assessment,
            'statements' => $statements,
            'responses' => $responses,
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
            'maturityLevels' => CsatMaStatement::MATURITY_LEVELS,
            'currentDomain' => $domainFilter ? (int) $domainFilter : null,
        ]);
    }

    public function saveMaResponse(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

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
                'comment' => $validated['comment'],
                'responded_by' => auth()->id(),
                'responded_at' => now(),
            ]
        );

        $this->maScoring->recalculateAndPersist($assessment->id);

        if ($assessment->status === 'draft') {
            $assessment->update(['status' => 'in_progress']);
        }

        return back()->with('success', 'Response saved.');
    }

    public function saveCompensatingControl(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $validated = $request->validate([
            'response_id' => 'required|exists:csat_ma_responses,id',
            'control_name' => 'required|string|max:500',
            'control_description' => 'required|string',
            'effectiveness_level' => 'required|in:high,medium,low',
            'planned_permanent_date' => 'nullable|date',
        ]);

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
            'narratives' => $narratives,
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
        ]);
    }

    public function saveMaNarrative(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $validated = $request->validate([
            'id' => 'required|exists:csat_ma_narratives,id',
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

        $gapStatements = CsatMaResponse::where('assessment_id', $assessment->id)
            ->where('response', 'no')
            ->with('statement')
            ->get();

        return Inertia::render('CSAT/Maturity/Targets', [
            'assessment' => $assessment,
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

        $validated = $request->validate([
            'scope_code' => 'required|string|max:20',
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

        $threats = $assessment->threats()->with('createdBy:id,name')->orderByDesc('inherent_risk_score')->get();
        $catalogue = CsatThreatCatalogue::where('is_active', true)->orderBy('threat_name')->get();

        return Inertia::render('CSAT/Threats', [
            'assessment' => $assessment,
            'threats' => $threats,
            'catalogue' => $catalogue,
        ]);
    }

    public function storeThreat(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

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
        $threat->delete();
        return back()->with('success', 'Threat removed.');
    }

    // ===== Vulnerabilities =====

    public function vulnerabilities(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $vulnerabilities = $assessment->vulnerabilities()->with(['assignee:id,name', 'createdBy:id,name'])->orderByDesc('composite_score')->get();
        $users = User::where('organization_id', auth()->user()->organization_id)->select('id', 'name')->get();

        return Inertia::render('CSAT/Vulnerabilities', [
            'assessment' => $assessment,
            'vulnerabilities' => $vulnerabilities,
            'users' => $users,
        ]);
    }

    public function storeVulnerability(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $validated = $request->validate([
            'vulnerability_name' => 'required|string|max:500',
            'description' => 'nullable|string',
            'vulnerability_category' => 'required|in:people,process,technology',
            'likelihood_of_exploit' => 'required|in:high,moderate,low',
            'impact_if_exploited' => 'required|in:high,moderate,low',
            'mitigants_in_place' => 'required|boolean',
            'existing_mitigants' => 'nullable|string',
            'planned_mitigants' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'comment' => 'nullable|string',
        ]);

        $assessment->vulnerabilities()->create(array_merge($validated, ['created_by' => auth()->id()]));

        return back()->with('success', 'Vulnerability added.');
    }

    public function updateVulnerability(Request $request, CsatAssessment $assessment, CsatVulnerability $vulnerability)
    {
        $this->authorizeAssessment($assessment);

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
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'comment' => 'nullable|string',
        ]);

        $vulnerability->update($validated);

        return back()->with('success', 'Vulnerability updated.');
    }

    // ===== Workflow =====

    public function workflow(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $approvalRecords = $assessment->approvalRecords()->orderBy('actioned_at')->get();

        return Inertia::render('CSAT/Workflow', [
            'assessment' => $assessment,
            'approvalRecords' => $approvalRecords,
        ]);
    }

    public function submitForApproval(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $assessment->update(['status' => 'pending_approval']);

        return back()->with('success', 'Assessment submitted for approval.');
    }

    public function approve(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $validated = $request->validate([
            'comments' => 'nullable|string',
        ]);

        $user = auth()->user();
        $stageNumber = $assessment->approvalRecords()->max('stage_number') ?? 0;
        $stageNumber++;

        CsatApprovalRecord::create([
            'assessment_id' => $assessment->id,
            'stage_number' => $stageNumber,
            'action' => 'approved',
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'approver_role' => $user->getRoleNames()->first() ?? 'User',
            'digital_signature_token' => hash('sha256', $user->id . $assessment->id . $stageNumber . now()->toISOString()),
            'comments' => $validated['comments'],
            'actioned_at' => now(),
        ]);

        if ($stageNumber >= 2) {
            $assessment->update(['status' => 'approved']);
        }

        return back()->with('success', 'Assessment approved.');
    }

    public function reject(Request $request, CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $validated = $request->validate([
            'comments' => 'required|string',
        ]);

        $user = auth()->user();
        $stageNumber = $assessment->approvalRecords()->max('stage_number') ?? 0;

        CsatApprovalRecord::create([
            'assessment_id' => $assessment->id,
            'stage_number' => $stageNumber + 1,
            'action' => 'rejected',
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'approver_role' => $user->getRoleNames()->first() ?? 'User',
            'comments' => $validated['comments'],
            'actioned_at' => now(),
        ]);

        $assessment->update(['status' => 'in_progress']);

        return back()->with('success', 'Assessment returned for revision.');
    }

    // ===== Reports =====

    public function reports(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $irScores = $this->irScoring->calculateCompositeRisk($assessment->id);
        $domainScores = $assessment->maScores()->where('score_type', 'domain')->get();
        $componentScores = $assessment->maScores()->where('score_type', 'component')->get();
        $gapCount = CsatMaResponse::where('assessment_id', $assessment->id)->where('response', 'no')->count();
        $aiRecs = $assessment->aiRecommendations()->where('is_dismissed', false)->orderBy('priority_rank')->get();

        return Inertia::render('CSAT/Reports', [
            'assessment' => $assessment,
            'irScores' => $irScores,
            'domainScores' => $domainScores,
            'componentScores' => $componentScores,
            'gapCount' => $gapCount,
            'recommendations' => $aiRecs,
            'domainNames' => CsatMaStatement::DOMAIN_NAMES,
            'maturityLevels' => CsatMaStatement::MATURITY_LEVELS,
        ]);
    }

    // ===== AI Insights =====

    public function aiInsights(CsatAssessment $assessment)
    {
        $this->authorizeAssessment($assessment);

        $recommendations = $assessment->aiRecommendations()->orderBy('priority_rank')->get();

        return Inertia::render('CSAT/AIInsights', [
            'assessment' => $assessment,
            'recommendations' => $recommendations,
        ]);
    }

    public function dismissRecommendation(CsatAssessment $assessment, CsatAiRecommendation $recommendation)
    {
        $this->authorizeAssessment($assessment);
        $recommendation->update(['is_dismissed' => true]);
        return back()->with('success', 'Recommendation dismissed.');
    }

    // ===== Helpers =====

    private function authorizeAssessment(CsatAssessment $assessment): void
    {
        abort_if($assessment->organization_id !== auth()->user()->organization_id, 403);
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
