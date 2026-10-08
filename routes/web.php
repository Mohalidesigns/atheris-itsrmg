<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\Auth\SamlController;
use App\Http\Controllers\Auth\ScimController;
use App\Http\Controllers\BcpController;
use App\Http\Controllers\BusinessAssetController;
use App\Http\Controllers\ChangeRequestController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\ControlController;
use App\Http\Controllers\ControlStandardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataBreachController;
use App\Http\Controllers\Ea\DecisionRecordController;
use App\Http\Controllers\Ea\DepthController;
use App\Http\Controllers\Ea\PortalController;
use App\Http\Controllers\Ea\StewardshipController;
use App\Http\Controllers\Ea\SurveyController;
use App\Http\Controllers\Ea\WedgeController;
use App\Http\Controllers\EaController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\IsmsController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PciController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PolicyAttestationController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\PolicyExceptionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionLibraryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResponseProcedureController;
use App\Http\Controllers\Returns\RegulatoryReturnController;
use App\Http\Controllers\RiskAssessmentController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\RiskTreatmentController;
use App\Http\Controllers\SecurityAlertController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ThreatController;
use App\Http\Controllers\VendorAssessmentController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VulnerabilityController;
use App\Http\Controllers\VulnerabilityTicketController;
use App\Modules\CBNCSAT\Http\Controllers\CsatAssessmentController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// SAML 2.0 Service Provider endpoints (no auth middleware — IdP-initiated)
Route::prefix('auth/saml')->group(function () {
    Route::get('/{tenant}/metadata', [SamlController::class, 'metadata'])->name('saml.metadata');
    Route::post('/{tenant}/acs', [SamlController::class, 'acs'])->name('saml.acs');
    Route::post('/{tenant}/sls', [SamlController::class, 'sls'])->name('saml.sls');
});

// SCIM v2 (RFC 7644) — bearer-token authenticated inside the controller
Route::prefix('scim/v2')->group(function () {
    Route::get('/ServiceProviderConfig', [ScimController::class, 'serviceProviderConfig']);
    Route::get('/ResourceTypes', [ScimController::class, 'resourceTypes']);
    Route::get('/Schemas', [ScimController::class, 'schemas']);
    Route::get('/Users', [ScimController::class, 'listUsers']);
    Route::post('/Users', [ScimController::class, 'createUser']);
    Route::get('/Users/{id}', [ScimController::class, 'showUser']);
    Route::patch('/Users/{id}', [ScimController::class, 'patchUser']);
    Route::put('/Users/{id}', [ScimController::class, 'putUser']);
    Route::delete('/Users/{id}', [ScimController::class, 'deleteUser']);
});

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/board-pack-export', [DashboardController::class, 'boardPackExport'])->name('dashboard.board-pack-export');
});

Route::middleware('auth')->group(function () {
    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Risk Management
    Route::get('/risks/dashboard', [RiskController::class, 'dashboard'])
        ->middleware('permission:view risks')->name('risks.dashboard');
    Route::resource('risks', RiskController::class)
        ->middleware('permission:view risks')
        ->middlewareFor(['create', 'store'], 'permission:create risks')
        ->middlewareFor(['edit', 'update'], 'permission:edit risks')
        ->middlewareFor('destroy', 'permission:delete risks');

    // Risk Assessments
    Route::resource('risk-assessments', RiskAssessmentController::class)
        ->middleware('permission:view risk-assessments')
        ->middlewareFor(['create', 'store'], 'permission:create risk-assessments')
        ->middlewareFor(['edit', 'update'], 'permission:edit risk-assessments')
        ->middlewareFor('destroy', 'permission:delete risk-assessments');

    // Risk Treatments
    Route::resource('risk-treatments', RiskTreatmentController::class)
        ->middleware('permission:view risk-treatments')
        ->middlewareFor(['create', 'store'], 'permission:create risk-treatments')
        ->middlewareFor(['edit', 'update'], 'permission:edit risk-treatments')
        ->middlewareFor('destroy', 'permission:delete risk-treatments');
    Route::patch('/risk-treatments/{treatment}/status', [RiskTreatmentController::class, 'updateStatus'])
        ->middleware('permission:edit risk-treatments')
        ->name('risk-treatments.update-status');

    // Threat Register
    Route::resource('threats', ThreatController::class)
        ->middleware('permission:view threats')
        ->middlewareFor(['create', 'store'], 'permission:create threats')
        ->middlewareFor(['edit', 'update'], 'permission:edit threats')
        ->middlewareFor('destroy', 'permission:delete threats');
    Route::post('/threats/{threat}/assessments', [ThreatController::class, 'storeAssessment'])
        ->middleware('permission:edit threats')->name('threats.assessments.store');

    // Question Library
    Route::get('/question-libraries', [QuestionLibraryController::class, 'index'])
        ->middleware('permission:view risks')->name('question-libraries.index');
    Route::post('/question-libraries', [QuestionLibraryController::class, 'store'])
        ->middleware('permission:create risks')->name('question-libraries.store');
    Route::put('/question-libraries/{questionLibrary}', [QuestionLibraryController::class, 'update'])
        ->middleware('permission:edit risks')->name('question-libraries.update');
    Route::delete('/question-libraries/{questionLibrary}', [QuestionLibraryController::class, 'destroy'])
        ->middleware('permission:delete risks')->name('question-libraries.destroy');

    // Compliance Module
    Route::get('/compliance/dashboard', [ComplianceController::class, 'dashboard'])
        ->middleware('permission:view compliance-assessments')->name('compliance.dashboard');

    // Control Library
    Route::resource('controls', ControlController::class)
        ->middleware('permission:view controls')
        ->middlewareFor(['create', 'store'], 'permission:create controls')
        ->middlewareFor(['edit', 'update'], 'permission:edit controls')
        ->middlewareFor('destroy', 'permission:delete controls');

    // Regulatory Frameworks
    Route::middleware('permission:view frameworks')->group(function () {
        Route::get('/frameworks', [ComplianceController::class, 'frameworkIndex'])->name('frameworks.index');
        Route::get('/frameworks/{framework}', [ComplianceController::class, 'frameworkShow'])->name('frameworks.show');
    });

    // Compliance Assessments
    Route::middleware('permission:view compliance-assessments')->group(function () {
        Route::get('/compliance-assessments', [ComplianceController::class, 'assessmentIndex'])->name('compliance-assessments.index');
        Route::get('/compliance-assessments/create', [ComplianceController::class, 'assessmentCreate'])
            ->middleware('permission:create compliance-assessments')->name('compliance-assessments.create');
        Route::post('/compliance-assessments', [ComplianceController::class, 'assessmentStore'])
            ->middleware('permission:create compliance-assessments')->name('compliance-assessments.store');
        Route::get('/compliance-assessments/{complianceAssessment}', [ComplianceController::class, 'assessmentShow'])->name('compliance-assessments.show');
        Route::patch('/compliance-results/{result}', [ComplianceController::class, 'updateResult'])
            ->middleware('permission:edit compliance-assessments')->name('compliance-results.update');
    });

    // Evidence
    Route::get('/evidence', [ComplianceController::class, 'evidenceIndex'])
        ->middleware('permission:view evidence')->name('evidence.index');
    Route::post('/evidence', [ComplianceController::class, 'evidenceStore'])
        ->middleware('permission:create evidence')->name('evidence.store');

    // Gap Analysis
    Route::get('/gap-analysis', [ComplianceController::class, 'gapIndex'])
        ->middleware('permission:view gap-analysis')->name('gap-analysis.index');

    // Security Operations
    Route::get('/security-ops/dashboard', [VulnerabilityController::class, 'dashboard'])
        ->middleware('permission:view vulnerabilities')->name('security-ops.dashboard');
    Route::resource('vulnerabilities', VulnerabilityController::class)
        ->middleware('permission:view vulnerabilities')
        ->middlewareFor(['create', 'store'], 'permission:create vulnerabilities')
        ->middlewareFor(['edit', 'update'], 'permission:edit vulnerabilities')
        ->middlewareFor('destroy', 'permission:delete vulnerabilities');

    // Incidents
    Route::resource('incidents', IncidentController::class)
        ->middleware('permission:view incidents')
        ->middlewareFor(['create', 'store'], 'permission:create incidents')
        ->middlewareFor(['edit', 'update'], 'permission:edit incidents')
        ->middlewareFor('destroy', 'permission:delete incidents');
    Route::post('/incidents/{incident}/events', [IncidentController::class, 'addEvent'])
        ->middleware('permission:edit incidents')->name('incidents.add-event');
    Route::patch('/incidents/{incident}/status', [IncidentController::class, 'updateStatus'])
        ->middleware('permission:edit incidents')->name('incidents.update-status');

    // Vulnerability Tickets
    Route::resource('vulnerability-tickets', VulnerabilityTicketController::class)
        ->middleware('permission:view vulnerability-tickets')
        ->middlewareFor(['create', 'store'], 'permission:create vulnerability-tickets')
        ->middlewareFor(['edit', 'update'], 'permission:edit vulnerability-tickets')
        ->middlewareFor('destroy', 'permission:delete vulnerability-tickets');
    Route::patch('/vulnerability-tickets/{vulnerability_ticket}/status', [VulnerabilityTicketController::class, 'updateStatus'])
        ->middleware('permission:edit vulnerability-tickets')->name('vulnerability-tickets.update-status');

    // Security Alerts
    Route::resource('security-alerts', SecurityAlertController::class)
        ->middleware('permission:view security-alerts')
        ->middlewareFor(['create', 'store'], 'permission:create security-alerts')
        ->middlewareFor(['edit', 'update'], 'permission:edit security-alerts')
        ->middlewareFor('destroy', 'permission:delete security-alerts');
    Route::post('/security-alerts/{security_alert}/promote', [SecurityAlertController::class, 'promote'])
        ->middleware('permission:create incidents')->name('security-alerts.promote');

    // Data Breaches
    Route::resource('data-breaches', DataBreachController::class)
        ->parameters(['data-breaches' => 'breach'])
        ->middleware('permission:view data-breaches')
        ->middlewareFor(['create', 'store'], 'permission:create data-breaches')
        ->middlewareFor(['edit', 'update'], 'permission:edit data-breaches')
        ->middlewareFor('destroy', 'permission:delete data-breaches');

    // Response Procedures (share the incidents permission set)
    Route::resource('response-procedures', ResponseProcedureController::class)
        ->middleware('permission:view incidents')
        ->middlewareFor(['create', 'store'], 'permission:create incidents')
        ->middlewareFor(['edit', 'update'], 'permission:edit incidents')
        ->middlewareFor('destroy', 'permission:delete incidents');

    // Asset Management
    Route::resource('assets', AssetController::class)
        ->middleware('permission:view assets')
        ->middlewareFor(['create', 'store'], 'permission:create assets')
        ->middlewareFor(['edit', 'update'], 'permission:edit assets')
        ->middlewareFor('destroy', 'permission:delete assets');

    // Business Assets (share the assets permission set)
    Route::resource('business-assets', BusinessAssetController::class)
        ->middleware('permission:view assets')
        ->middlewareFor(['create', 'store'], 'permission:create assets')
        ->middlewareFor(['edit', 'update'], 'permission:edit assets')
        ->middlewareFor('destroy', 'permission:delete assets');

    // Vendor Management
    Route::resource('vendors', VendorController::class)
        ->middleware('permission:view vendors')
        ->middlewareFor(['create', 'store'], 'permission:create vendors')
        ->middlewareFor(['edit', 'update'], 'permission:edit vendors')
        ->middlewareFor('destroy', 'permission:delete vendors');

    // Vendor Assessments
    Route::resource('vendor-assessments', VendorAssessmentController::class)
        ->middleware('permission:view vendor-assessments')
        ->middlewareFor(['create', 'store'], 'permission:create vendor-assessments')
        ->middlewareFor(['edit', 'update'], 'permission:edit vendor-assessments')
        ->middlewareFor('destroy', 'permission:delete vendor-assessments');

    // Policy Management
    Route::resource('policies', PolicyController::class)
        ->middleware('permission:view policies')
        ->middlewareFor(['create', 'store'], 'permission:create policies')
        ->middlewareFor(['edit', 'update'], 'permission:edit policies')
        ->middlewareFor('destroy', 'permission:delete policies');
    Route::post('/policies/{policy}/publish', [PolicyController::class, 'publish'])
        ->middleware('permission:approve policies')->name('policies.publish');
    Route::post('/policies/{policy}/attest', [PolicyController::class, 'attest'])
        ->middleware('permission:view policies')->name('policies.attest');

    // Control Standards
    Route::get('/control-standards', [ControlStandardController::class, 'index'])
        ->middleware('permission:view controls')->name('control-standards.index');

    // Policy Attestations (read-only register — writes go through policies.attest)
    Route::resource('policy-attestations', PolicyAttestationController::class)->only(['index', 'show'])
        ->middleware('permission:view policy-attestations');

    // Change Requests
    Route::get('/change-requests', [ChangeRequestController::class, 'index'])
        ->middleware('permission:view policies')->name('change-requests.index');

    // Policy Exceptions
    Route::get('/policy-exceptions', [PolicyExceptionController::class, 'index'])
        ->middleware('permission:view policies')->name('policy-exceptions.index');

    // ISMS Module
    Route::middleware('permission:view isms')->group(function () {
        Route::get('/isms', [IsmsController::class, 'index'])->name('isms.index');
        Route::get('/isms/risks', [IsmsController::class, 'risks'])->name('isms.risks');
        Route::get('/isms/controls', [IsmsController::class, 'controls'])->name('isms.controls');
        Route::get('/isms/audit', [IsmsController::class, 'audit'])->name('isms.audit');
        Route::get('/isms/soa', [IsmsController::class, 'soa'])->name('isms.soa');
        Route::get('/isms/gap-analysis', [IsmsController::class, 'gapAnalysis'])->name('isms.gap-analysis');
    });

    // PCI Management
    Route::middleware('permission:view pci')->group(function () {
        Route::get('/pci/dashboard', [PciController::class, 'dashboard'])->name('pci.dashboard');
        Route::get('/pci/cde', [PciController::class, 'cde'])->name('pci.cde');
        Route::get('/pci/controls', [PciController::class, 'controls'])->name('pci.controls');
        Route::get('/pci/saq', [PciController::class, 'saq'])->name('pci.saq');
        Route::get('/pci/matrix', [PciController::class, 'matrix'])->name('pci.matrix');
        Route::get('/pci/evidence', [PciController::class, 'evidence'])->name('pci.evidence');
    });

    // BCP/DR
    Route::middleware('permission:view bcp-plans')->group(function () {
        Route::get('/bcp/plans', [BcpController::class, 'planIndex'])->name('bcp.plans');
        Route::get('/bcp/plans/create', [BcpController::class, 'planCreate'])
            ->middleware('permission:create bcp-plans')->name('bcp.plans.create');
        Route::post('/bcp/plans', [BcpController::class, 'planStore'])
            ->middleware('permission:create bcp-plans')->name('bcp.plans.store');
        Route::get('/bcp/plans/{bcpPlan}', [BcpController::class, 'planShow'])->name('bcp.plans.show');
        Route::get('/bcp/bia', [BcpController::class, 'biaIndex'])->name('bcp.bia');
        Route::post('/bcp/bia', [BcpController::class, 'biaStore'])
            ->middleware('permission:create bcp-plans')->name('bcp.bia.store');

        // DR Plans
        Route::get('/bcp/dr-plans', [BcpController::class, 'drPlanIndex'])->name('bcp.dr-plans');

        // BCP Tests & Exercises
        Route::get('/bcp/tests', [BcpController::class, 'testIndex'])->name('bcp.tests');
    });

    // Continuous Monitoring
    Route::middleware('permission:view monitoring')->group(function () {
        Route::get('/monitoring/dashboard', [MonitoringController::class, 'dashboard'])->name('monitoring.dashboard');
        Route::get('/monitoring/controls', [MonitoringController::class, 'controls'])->name('monitoring.controls');
        Route::get('/monitoring/drift', [MonitoringController::class, 'drift'])->name('monitoring.drift');
        Route::get('/monitoring/access-reviews', [MonitoringController::class, 'accessReviews'])->name('monitoring.access-reviews');
    });

    // CBN-CSAT Module
    Route::prefix('csat')->name('csat.')->middleware('permission:view csat')->group(function () {
        Route::get('/', [CsatAssessmentController::class, 'index'])->name('index');
        Route::get('/create', [CsatAssessmentController::class, 'create'])
            ->middleware('permission:create csat')->name('create');
        Route::post('/', [CsatAssessmentController::class, 'store'])
            ->middleware('permission:create csat')->name('store');

        Route::prefix('{assessment}')->group(function () {
            Route::get('/overview', [CsatAssessmentController::class, 'overview'])->name('overview');

            // Institution Profile
            Route::get('/institution-profile', [CsatAssessmentController::class, 'institutionProfile'])->name('institution-profile');
            Route::post('/institution-profile', [CsatAssessmentController::class, 'saveInstitutionProfile'])->name('institution-profile.save');
            Route::post('/stakeholder', [CsatAssessmentController::class, 'saveStakeholder'])->name('stakeholder.save');

            // Inherent Risk
            Route::get('/inherent-risk', [CsatAssessmentController::class, 'inherentRiskDashboard'])->name('ir.dashboard');
            Route::get('/inherent-risk/questions', [CsatAssessmentController::class, 'inherentRiskQuestions'])->name('ir.questions');
            Route::post('/inherent-risk/response', [CsatAssessmentController::class, 'saveIrResponse'])->name('ir.response.save');
            Route::get('/inherent-risk/narratives', [CsatAssessmentController::class, 'inherentRiskNarratives'])->name('ir.narratives');
            Route::post('/inherent-risk/narrative', [CsatAssessmentController::class, 'saveIrNarrative'])->name('ir.narrative.save');

            // Maturity Assessment
            Route::get('/maturity', [CsatAssessmentController::class, 'maturityDashboard'])->name('ma.dashboard');
            Route::get('/maturity/assessment', [CsatAssessmentController::class, 'maturityAssessment'])->name('ma.assessment');
            Route::post('/maturity/response', [CsatAssessmentController::class, 'saveMaResponse'])->name('ma.response.save');
            Route::post('/maturity/compensating-control', [CsatAssessmentController::class, 'saveCompensatingControl'])->name('ma.cc.save');
            Route::get('/maturity/narratives', [CsatAssessmentController::class, 'maturityNarratives'])->name('ma.narratives');
            Route::post('/maturity/narrative', [CsatAssessmentController::class, 'saveMaNarrative'])->name('ma.narrative.save');
            Route::get('/maturity/targets', [CsatAssessmentController::class, 'maturityTargets'])->name('ma.targets');
            Route::post('/maturity/target', [CsatAssessmentController::class, 'saveTarget'])->name('ma.target.save');

            // Threats
            Route::get('/threats', [CsatAssessmentController::class, 'threats'])->name('threats');
            Route::post('/threats', [CsatAssessmentController::class, 'storeThreat'])->name('threats.store');
            Route::put('/threats/{threat}', [CsatAssessmentController::class, 'updateThreat'])->name('threats.update');
            Route::delete('/threats/{threat}', [CsatAssessmentController::class, 'destroyThreat'])->name('threats.destroy');

            // Vulnerabilities
            Route::get('/vulnerabilities', [CsatAssessmentController::class, 'vulnerabilities'])->name('vulnerabilities');
            Route::post('/vulnerabilities', [CsatAssessmentController::class, 'storeVulnerability'])->name('vulnerabilities.store');
            Route::put('/vulnerabilities/{vulnerability}', [CsatAssessmentController::class, 'updateVulnerability'])->name('vulnerabilities.update');

            // Workflow
            Route::get('/workflow', [CsatAssessmentController::class, 'workflow'])->name('workflow');
            Route::post('/workflow/submit', [CsatAssessmentController::class, 'submitForApproval'])->name('workflow.submit');
            Route::post('/workflow/approve', [CsatAssessmentController::class, 'approve'])
                ->middleware('permission:approve csat')->name('workflow.approve');
            Route::post('/workflow/reject', [CsatAssessmentController::class, 'reject'])
                ->middleware('permission:approve csat')->name('workflow.reject');

            // Reports
            Route::get('/reports', [CsatAssessmentController::class, 'reports'])->name('reports');

            // AI Insights
            Route::get('/ai-insights', [CsatAssessmentController::class, 'aiInsights'])->name('ai-insights');
            Route::put('/ai-insights/{recommendation}', [CsatAssessmentController::class, 'dismissRecommendation'])->name('ai.dismiss');
        });
    });

    // Reports & Analytics
    Route::middleware('permission:view reports')->group(function () {
        Route::get('/reports/executive', [ReportController::class, 'executive'])->name('reports.executive');
        Route::get('/reports/risks', [ReportController::class, 'riskReport'])->name('reports.risks');
        Route::get('/reports/compliance', [ReportController::class, 'complianceReport'])->name('reports.compliance');
        Route::get('/reports/scheduled', [ReportController::class, 'scheduled'])->name('reports.scheduled');
    });

    // Phase 2: Risk graph (risk module, lives with platform controller)
    Route::get('/risks-graph', [PlatformController::class, 'risksGraph'])
        ->middleware('permission:view risks')->name('risks.graph');

    // Phase 2: Issues Console
    Route::middleware('permission:view issues')->group(function () {
        Route::get('/issues', [PlatformController::class, 'issuesIndex'])->name('issues.index');
        Route::get('/issues/sla-policies', [PlatformController::class, 'issuesSlaPolicies'])->name('issues.sla-policies');
    });

    /* ========================================================
     |  Atheris Platform Routes (Phases 0-8)
     |========================================================*/
    Route::middleware('permission:view platform')->group(function () {
        // Phase 4: Copilot
        Route::get('/copilot', [PlatformController::class, 'copilotIndex'])->name('copilot.index');
        Route::post('/copilot/send', [PlatformController::class, 'copilotSend'])->name('copilot.send');

        // Phase 4: Regulatory Intelligence
        Route::get('/regulatory-intel', [PlatformController::class, 'regIntelIndex'])->name('reg-intel.index');
        Route::get('/regulatory-intel/{circular}', [PlatformController::class, 'regIntelShow'])->name('reg-intel.show');
        Route::get('/obligations', [PlatformController::class, 'obligationsIndex'])->name('obligations.index');

        // Phase 2: AUCS Browser
        Route::get('/aucs', [PlatformController::class, 'aucsIndex'])->name('aucs.index');

        // Phase 2: Asset Discovery
        Route::get('/asset-discovery', [PlatformController::class, 'assetDiscoveryIndex'])->name('asset-discovery.index');
        Route::post('/asset-discovery/sync', [PlatformController::class, 'assetDiscoverySync'])->name('asset-discovery.sync');

        // Phase 2: Business Services & graph
        Route::get('/business-services', [PlatformController::class, 'businessServicesIndex'])->name('business-services.index');
        Route::get('/business-services/graph', [PlatformController::class, 'businessServicesGraph'])->name('business-services.graph');

        // Phase 2: ITSM
        Route::get('/itsm', [PlatformController::class, 'itsmIndex'])->name('itsm.index');

        // Phase 3: CCM
        Route::get('/ccm', [PlatformController::class, 'ccmIndex'])->name('ccm.index');
        Route::post('/ccm/{tenantTest}/run', [PlatformController::class, 'ccmRun'])->name('ccm.run');

        // Phase 3: Evidence Vault (WORM)
        Route::get('/evidence-vault', [PlatformController::class, 'evidenceVaultIndex'])->name('evidence-vault.index');

        // Phase 3: KRI
        Route::get('/kri', [PlatformController::class, 'kriIndex'])->name('kri.index');

        // Phase 3: Board Packs
        Route::get('/board-packs', [PlatformController::class, 'boardPacksIndex'])->name('board-packs.index');
        Route::post('/board-packs/generate', [PlatformController::class, 'boardPacksGenerate'])->name('board-packs.generate');

        // Phase 3: Pricing
        Route::get('/pricing', [PlatformController::class, 'pricingIndex'])->name('pricing.index');

        // Phase 4: TPRM
        Route::get('/security-ratings', [PlatformController::class, 'securityRatingsIndex'])->name('security-ratings.index');
        Route::get('/shared-vendors', [PlatformController::class, 'sharedVendorsIndex'])->name('shared-vendors.index');

        // Phase 5: SIEM
        Route::get('/siem', [PlatformController::class, 'siemIndex'])->name('siem.index');

        // Phase 5: Regulatory Notifications
        Route::get('/notifications', [PlatformController::class, 'notificationsIndex'])->name('notifications.index');
        Route::post('/notifications/draft', [PlatformController::class, 'notificationsDraft'])->name('notifications.draft');

        // Phase 5: FAIR
        Route::get('/fair', [PlatformController::class, 'fairIndex'])->name('fair.index');
        Route::post('/fair/{scenario}/run', [PlatformController::class, 'fairRun'])->name('fair.run')->middleware('permission:edit risks');
        Route::patch('/fair/{scenario}/link', [PlatformController::class, 'fairLink'])->name('fair.link')->middleware('permission:edit risks');

        // Phase 5: Vuln Prioritiser + Advisories
        Route::get('/vuln-prioritiser', [PlatformController::class, 'vulnPrioritiserIndex'])->name('vuln-prioritiser.index');
        Route::get('/threat-advisories', [PlatformController::class, 'threatAdvisoriesIndex'])->name('threat-advisories.index');

        // Phase 6: Doc Intelligence & Returns
        Route::get('/doc-intel', [PlatformController::class, 'docIntelIndex'])->name('doc-intel.index');
        Route::post('/doc-intel/upload', [PlatformController::class, 'docIntelUpload'])->name('doc-intel.upload');
        Route::get('/returns', [PlatformController::class, 'returnsIndex'])->name('returns.index');
        Route::post('/returns/generate', [PlatformController::class, 'returnsGenerate'])->name('returns.generate');

        // Phase 7: Workflows
        Route::get('/workflows', [PlatformController::class, 'workflowsIndex'])->name('workflows.index');
        Route::get('/workflows/marketplace', [PlatformController::class, 'workflowsMarketplace'])->name('workflows.marketplace');
        Route::get('/workflows/instances', [PlatformController::class, 'workflowsInstances'])->name('workflows.instances');
        Route::get('/workflows/{workflow}', [PlatformController::class, 'workflowShow'])->name('workflows.show');

        // Phase 7: Core Banking
        Route::get('/core-banking', [PlatformController::class, 'coreBankingIndex'])->name('core-banking.index');
        Route::get('/core-banking/snapshots', [PlatformController::class, 'coreBankingSnapshots'])->name('core-banking.snapshots');

        // Phase 7: DR
        Route::get('/dr/runbooks', [PlatformController::class, 'drRunbooksIndex'])->name('dr.runbooks');
        Route::get('/dr/runbooks/{runbook}', [PlatformController::class, 'drRunbookShow'])->name('dr.runbook.show');
        Route::get('/dr/exercises', [PlatformController::class, 'drExercisesIndex'])->name('dr.exercises');

        // Phase 8: Marketplace
        Route::get('/marketplace', [PlatformController::class, 'marketplaceIndex'])->name('marketplace.index');
        Route::get('/marketplace/installs', [PlatformController::class, 'marketplaceInstalls'])->name('marketplace.installs');
        Route::post('/marketplace/{item}/install', [PlatformController::class, 'marketplaceInstall'])->name('marketplace.install');

        // Integrations Hub (per-category connector pages)
        Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
        Route::get('/integrations/{key}', [IntegrationsController::class, 'show'])->name('integrations.show');
        Route::post('/integrations/{key}/test', [IntegrationsController::class, 'testConnection'])->name('integrations.test');
        Route::get('/integrations/{key}/sample.csv', [IntegrationsController::class, 'downloadSample'])->name('integrations.sample');

        // Phase 1: Identity & Access (SSO/SCIM/API)
        Route::get('/identity/sso', [PlatformController::class, 'identitySso'])->name('identity.sso');
        Route::get('/identity/scim', [PlatformController::class, 'identityScim'])->name('identity.scim');
        Route::get('/identity/api', [PlatformController::class, 'identityApi'])->name('identity.api');

        // Phase 8: Settings extras
        Route::get('/settings/theme', [PlatformController::class, 'settingsTheme'])->name('settings.theme');
        Route::get('/settings/custom-fields', [PlatformController::class, 'settingsCustomFields'])->name('settings.custom-fields');
        Route::get('/settings/feature-flags', [PlatformController::class, 'settingsFeatureFlags'])->name('settings.feature-flags');
        Route::post('/settings/feature-flags/{flag}/toggle', [PlatformController::class, 'settingsFeatureFlagToggle'])
            ->middleware('permission:edit platform')->name('settings.feature-flags.toggle');
    });

    /* ========================================================
     |  Enterprise Architecture (EA-Studio) — per ATH-PIP-EA-001
     |  Authorisation model — ATH-EAR-002 §2.2 (RC-2) remediation.
     |
     |  The group middleware grants read access only. Every write route
     |  carries its own permission, matching the pattern the Risks module
     |  already uses (`->middlewareFor('destroy', 'permission:delete risks')`).
     |  Previously all 44 write routes inherited `permission:view ea` alone,
     |  which meant a Viewer could POST /ea/applications and
     |  DELETE /ea/tech-components/{id}.
     |
     |    create ea   — new catalogue entries and new governance objects
     |    edit ea     — mutating an existing entry, and operational actions
     |                  (recompute, anomaly triage) that change stored state
     |    delete ea   — destructive operations, plus the external feed syncs
     |                  which overwrite catalogue records in bulk
     |    approve ea  — the governance gate: ARB decisions, exception renewal
     |    export ea   — artefact generation (evidence packs, ArchiMate export)
     |========================================================*/
    Route::prefix('ea')->name('ea.')->middleware('permission:view ea')->group(function () {
        // Phase 1 — reads
        Route::get('/', [EaController::class, 'commandCentre'])->name('command-centre');
        Route::get('/capabilities', [EaController::class, 'capabilityMap'])->name('capabilities');
        Route::get('/capabilities/{capability}', [EaController::class, 'capabilityShow'])->name('capabilities.show');
        Route::post('/capabilities', [EaController::class, 'capabilityStore'])->middleware('permission:create ea')->name('capabilities.store');
        Route::put('/capabilities/{capability}', [EaController::class, 'capabilityUpdate'])->middleware('permission:edit ea')->name('capabilities.update');
        Route::delete('/capabilities/{capability}', [EaController::class, 'capabilityDestroy'])->middleware('permission:delete ea')->name('capabilities.destroy');

        Route::get('/value-streams', [EaController::class, 'valueStreams'])->name('value-streams');

        Route::get('/applications', [EaController::class, 'applicationPortfolio'])->name('applications');
        Route::get('/applications/{application}', [EaController::class, 'applicationShow'])->name('applications.show');
        Route::post('/applications', [EaController::class, 'applicationStore'])->middleware('permission:create ea')->name('applications.store');
        Route::put('/applications/{application}', [EaController::class, 'applicationUpdate'])->middleware('permission:edit ea')->name('applications.update');
        Route::delete('/applications/{application}', [EaController::class, 'applicationDestroy'])->middleware('permission:delete ea')->name('applications.destroy');

        Route::get('/technology-radar', [EaController::class, 'technologyRadar'])->name('technology-radar');
        Route::post('/tech-components', [EaController::class, 'techStore'])->middleware('permission:create ea')->name('tech.store');
        Route::put('/tech-components/{tech}', [EaController::class, 'techUpdate'])->middleware('permission:edit ea')->name('tech.update');
        Route::delete('/tech-components/{tech}', [EaController::class, 'techDestroy'])->middleware('permission:delete ea')->name('tech.destroy');

        Route::get('/information-domains', [EaController::class, 'infoDomains'])->name('information-domains');
        Route::get('/logical-entities', [EaController::class, 'logicalEntities'])->name('logical-entities');
        Route::get('/data-flows', [EaController::class, 'dataFlows'])->name('data-flows');
        Route::get('/cbn-maturity', [EaController::class, 'cbnMaturity'])->name('cbn-maturity');

        // Phase 2 — reads + ARB workflow
        Route::get('/interfaces', [EaController::class, 'interfaces'])->name('interfaces');
        Route::post('/interfaces', [EaController::class, 'interfaceStore'])->middleware('permission:create ea')->name('interfaces.store');
        Route::put('/interfaces/{interface}', [EaController::class, 'interfaceUpdate'])->middleware('permission:edit ea')->name('interfaces.update');
        Route::delete('/interfaces/{interface}', [EaController::class, 'interfaceDestroy'])->middleware('permission:delete ea')->name('interfaces.destroy');

        Route::get('/apis', [EaController::class, 'apis'])->name('apis');
        Route::get('/blast-radius', [EaController::class, 'blastRadius'])->name('blast-radius');
        Route::get('/security-zones', [EaController::class, 'securityZones'])->name('security-zones');
        Route::get('/control-mappings', [EaController::class, 'controlMappings'])->name('control-mappings');
        Route::get('/processes', [EaController::class, 'processes'])->name('processes');

        Route::get('/principles', [EaController::class, 'principles'])->name('principles');
        Route::post('/principles', [EaController::class, 'principleStore'])->middleware('permission:create ea')->name('principles.store');
        Route::put('/principles/{principle}', [EaController::class, 'principleUpdate'])->middleware('permission:edit ea')->name('principles.update');
        Route::delete('/principles/{principle}', [EaController::class, 'principleDestroy'])->middleware('permission:delete ea')->name('principles.destroy');

        Route::get('/standards', [EaController::class, 'standards'])->name('standards');
        Route::post('/standards', [EaController::class, 'standardStore'])->middleware('permission:create ea')->name('standards.store');
        Route::put('/standards/{standard}', [EaController::class, 'standardUpdate'])->middleware('permission:edit ea')->name('standards.update');
        Route::delete('/standards/{standard}', [EaController::class, 'standardDestroy'])->middleware('permission:delete ea')->name('standards.destroy');

        Route::get('/arb', [EaController::class, 'arb'])->name('arb');
        Route::get('/arb/wizard', [EaController::class, 'arbWizard'])->name('arb.wizard');
        Route::get('/arb/{submission}', [EaController::class, 'arbShow'])->name('arb.show');
        Route::post('/arb', [EaController::class, 'arbStore'])->middleware('permission:create ea')->name('arb.store');
        Route::post('/arb/{submission}/decide', [EaController::class, 'arbDecide'])->middleware('permission:approve ea')->name('arb.decide');

        Route::get('/exceptions', [EaController::class, 'exceptions'])->name('exceptions');
        Route::post('/exceptions', [EaController::class, 'exceptionStore'])->middleware('permission:create ea')->name('exceptions.store');
        Route::post('/exceptions/{exception}/renew', [EaController::class, 'exceptionRenew'])->middleware('permission:approve ea')->name('exceptions.renew');

        Route::get('/vendor-concentration', [EaController::class, 'vendorConcentration'])->name('vendor-concentration');

        // Phase 3 — reads
        Route::get('/plateaux', [EaController::class, 'plateaux'])->name('plateaux');
        Route::get('/initiatives', [EaController::class, 'initiatives'])->name('initiatives');
        Route::post('/initiatives/dependencies', [EaController::class, 'initiativeDependencyStore'])->middleware('permission:create ea')->name('initiatives.dependencies.store');
        Route::post('/initiatives/{initiative}/deliverables', [EaController::class, 'admDeliverableStore'])->middleware('permission:create ea')->name('initiatives.deliverables.store');
        Route::put('/deliverables/{deliverable}', [EaController::class, 'admDeliverableUpdate'])->middleware('permission:edit ea')->name('deliverables.update');

        Route::get('/roadmap', [EaController::class, 'roadmap'])->name('roadmap');
        Route::get('/adm-tracker', [EaController::class, 'admTracker'])->name('adm-tracker');
        Route::get('/patterns', [EaController::class, 'patterns'])->name('patterns');
        Route::get('/solutions', [EaController::class, 'solutions'])->name('solutions');

        Route::get('/kri', [EaController::class, 'kri'])->name('kri');
        Route::post('/kri/recompute', [EaController::class, 'kriRecompute'])->middleware('permission:edit ea')->name('kri.recompute');

        Route::get('/exchange', [EaController::class, 'exchange'])->name('exchange');
        Route::post('/exchange/queue', [EaController::class, 'exchangeQueue'])->middleware('permission:create ea')->name('exchange.queue');
        Route::get('/exchange/{job}/download', [EaController::class, 'exchangeDownload'])->middleware('permission:export ea')->name('exchange.download');

        Route::get('/evidence-packs', [EaController::class, 'evidencePacks'])->name('evidence-packs');
        Route::post('/evidence-packs/generate', [EaController::class, 'evidencePackGenerate'])->middleware('permission:export ea')->name('evidence-packs.generate');
        Route::get('/evidence-packs/{pack}/download/{kind?}', [EaController::class, 'evidencePackDownload'])->middleware('permission:export ea')->name('evidence-packs.download');

        /* ---------- Phase 4 (ATH-EAR-002 §9) — depth and scale ----------
         | WS 4.1 real diagram editor (B13) · WS 4.2 n-hop impact (B14)
         | WS 4.3 plateau diff (B15) · WS 4.4 ArchiMate round-trip gate
         | WS 4.5 GraphQL + MCP scoped writes · WS 4.7 cost & TCO (B17).
         |
         | §2.1 on what WS 4.1 replaces: `DiagramEditor.jsx` added nodes via
         | `window.prompt()` — "a demo, and it will be seen as one". */

        // WS 4.1 — diagrams. The editor is a canvas over the repository, so
        // these all moved to DepthController, which owns DiagramService.
        Route::get('/diagrams', [DepthController::class, 'diagramIndex'])->name('diagrams');
        Route::get('/diagrams/{diagram}', [DepthController::class, 'diagramShow'])->name('diagrams.show');
        Route::post('/diagrams', [DepthController::class, 'diagramStore'])->middleware('permission:create ea')->name('diagrams.store');
        Route::put('/diagrams/{diagram}', [DepthController::class, 'diagramUpdate'])->middleware('permission:edit ea')->name('diagrams.update');
        Route::delete('/diagrams/{diagram}', [DepthController::class, 'diagramDestroy'])->middleware('permission:delete ea')->name('diagrams.destroy');
        Route::post('/diagrams/{diagram}/layout', [DepthController::class, 'diagramLayout'])->middleware('permission:edit ea')->name('diagrams.layout');
        Route::post('/diagrams/validate', [DepthController::class, 'diagramValidate'])->middleware('permission:edit ea')->name('diagrams.validate');
        Route::post('/diagrams/expand', [DepthController::class, 'diagramExpand'])->middleware('permission:edit ea')->name('diagrams.expand');
        // Approving a topology diagram is the RBCF App. II §1.1(i) artefact, so
        // it rides `approve ea` rather than `edit ea`.
        Route::post('/diagrams/{diagram}/approve', [DepthController::class, 'diagramApprove'])->middleware('permission:approve ea')->name('diagrams.approve');
        Route::post('/diagrams/{diagram}/restore/{version}', [DepthController::class, 'diagramRestore'])->middleware('permission:edit ea')->name('diagrams.restore');
        Route::get('/diagrams/{diagram}/export/{format?}', [DepthController::class, 'diagramExport'])->middleware('permission:export ea')->name('diagrams.export');

        Route::get('/viewpoints/{viewpoint}', [EaController::class, 'viewpoint'])->name('viewpoint');
        Route::get('/viewpoints/{viewpoint}/data', [EaController::class, 'viewpointJson'])->name('viewpoint.json');

        // WS 4.2 — n-hop change impact from any entity.
        Route::get('/impact', [DepthController::class, 'impact'])->name('impact');
        Route::get('/impact/json', [DepthController::class, 'impactJson'])->name('impact.json');
        Route::get('/impact/path', [DepthController::class, 'impactPath'])->name('impact.path');

        // WS 4.3 — plateau diff and scenario authoring.
        Route::get('/plateau-diff', [DepthController::class, 'plateauDiff'])->name('plateau-diff');
        Route::get('/plateaux/{plateau}/membership', [DepthController::class, 'plateauMembership'])->name('plateaux.membership');
        Route::post('/plateaux/{plateau}/dispositions', [DepthController::class, 'plateauDispositionStore'])->middleware('permission:edit ea')->name('plateaux.dispositions');
        Route::post('/plateaux/{plateau}/seed', [DepthController::class, 'plateauSeed'])->middleware('permission:edit ea')->name('plateaux.seed');
        Route::delete('/plateaux/{plateau}/members/{member}', [DepthController::class, 'plateauMemberDestroy'])->middleware('permission:edit ea')->name('plateaux.members.destroy');

        // WS 4.7 — cost, TCO and technical debt.
        Route::get('/cost-model', [DepthController::class, 'costModel'])->name('cost-model');

        // WS 4.4 — the interchange release gate.
        Route::get('/round-trip', [DepthController::class, 'roundTrip'])->name('round-trip');
        Route::post('/round-trip/inspect', [DepthController::class, 'roundTripInspect'])->middleware('permission:create ea')->name('round-trip.inspect');

        // WS 4.5 — GraphQL surface and the draft-and-approve queue. The API
        // itself only ever records drafts, so `graphql` needs no write grant;
        // approving one does.
        Route::get('/api', [DepthController::class, 'apiConsole'])->name('api');
        Route::post('/graphql', [DepthController::class, 'graphql'])->name('graphql');
        Route::get('/change-proposals', [DepthController::class, 'drafts'])->name('drafts');
        Route::post('/change-proposals/{draft}/approve', [DepthController::class, 'draftApprove'])->middleware('permission:approve ea')->name('drafts.approve');
        Route::post('/change-proposals/{draft}/reject', [DepthController::class, 'draftReject'])->middleware('permission:approve ea')->name('drafts.reject');

        // §10 — materialised index maintenance.
        Route::post('/reindex', [DepthController::class, 'reindex'])->middleware('permission:edit ea')->name('reindex');
        Route::get('/index-status', [DepthController::class, 'indexStatus'])->name('index-status');

        // Phase 4 — DPIA / Glossary / Motivation
        Route::get('/dpia', [EaController::class, 'dpia'])->name('dpia');
        Route::post('/dpia', [EaController::class, 'dpiaStore'])->middleware('permission:create ea')->name('dpia.store');
        Route::get('/glossary', [EaController::class, 'glossary'])->name('glossary');
        Route::post('/glossary', [EaController::class, 'glossaryStore'])->middleware('permission:create ea')->name('glossary.store');
        Route::get('/motivation', [EaController::class, 'motivation'])->name('motivation');
        Route::post('/motivation', [EaController::class, 'motivationStore'])->middleware('permission:create ea')->name('motivation.store');

        // Phase 4 — threats / anomalies
        Route::get('/threats', [EaController::class, 'threats'])->name('threats');
        Route::post('/threats', [EaController::class, 'threatStore'])->middleware('permission:create ea')->name('threats.store');
        Route::post('/threats/{model}/techniques', [EaController::class, 'threatTechniqueStore'])->middleware('permission:create ea')->name('threats.techniques.store');
        Route::get('/anomalies', [EaController::class, 'anomalies'])->name('anomalies');
        Route::post('/anomalies/run', [EaController::class, 'anomaliesRun'])->middleware('permission:edit ea')->name('anomalies.run');
        Route::post('/anomalies/{finding}/ack', [EaController::class, 'anomalyAck'])->middleware('permission:edit ea')->name('anomalies.ack');
        Route::post('/anomalies/{finding}/resolve', [EaController::class, 'anomalyResolve'])->middleware('permission:edit ea')->name('anomalies.resolve');

        // Phase 4 — search + MCP
        Route::get('/search', [EaController::class, 'search'])->name('search');
        Route::get('/search/json', [EaController::class, 'searchJson'])->name('search.json');
        Route::get('/mcp', [EaController::class, 'mcpInfo'])->name('mcp');
        Route::post('/mcp/rpc', [EaController::class, 'mcpRpc'])->middleware('permission:create ea')->name('mcp.rpc');

        // Phase 4 — scenarios + ops
        Route::get('/scenarios', [EaController::class, 'scenarios'])->name('scenarios');
        Route::get('/audit-trail', [EaController::class, 'auditTrail'])->name('audit-trail');

        // Phase 0 (ATH-EAR-002 WS 0.4) — Data Sources: one screen for every
        // inbound path, with feed provenance so a bundled fixture is never
        // presented as live data.
        Route::get('/data-sources', [EaController::class, 'dataSources'])->name('data-sources');

        // Phase 4 — bulk import + syncs
        Route::get('/bulk-import', [EaController::class, 'bulkImportPage'])->name('bulk-import');
        Route::post('/bulk-import', [EaController::class, 'bulkImport'])->middleware('permission:create ea')->name('bulk-import.run');
        Route::get('/bulk-import/template/{type}', [EaController::class, 'bulkTemplate'])->name('bulk-import.template');
        Route::post('/sync/assets', [EaController::class, 'assetSync'])->middleware('permission:delete ea')->name('sync.assets');
        Route::post('/sync/eol', [EaController::class, 'eolSync'])->middleware('permission:delete ea')->name('sync.eol');
        Route::post('/sync/cve', [EaController::class, 'cveSync'])->middleware('permission:delete ea')->name('sync.cve');

        /* ---------- Phase 1 (ATH-EAR-002) — stewardship ----------
         | WS 1.1 ownership & subscriptions · WS 1.3 quality seal.
         | §4 rows 13/14/33 score these 0 and call them "the emergency":
         | the three mechanics that keep an EA repository alive. */
        /* ---------- Phase 3 (ATH-EAR-002 §6.3) — the African wedge ----------
         | §4 rows 39–48 score every global competitor 0 or 1 on this axis.
         | "None of them will be built by a global vendor because the
         | addressable market outside Africa does not justify it." */
        Route::get('/residency', [WedgeController::class, 'residency'])->name('residency');          // A2
        Route::get('/fx-exposure', [WedgeController::class, 'fx'])->name('fx-exposure');             // A3
        Route::get('/concentration', [WedgeController::class, 'concentration'])->name('concentration'); // A4
        Route::get('/sites', [WedgeController::class, 'sites'])->name('sites');                      // A5
        Route::get('/legal-entities', [WedgeController::class, 'entities'])->name('legal-entities'); // A6
        Route::get('/channels', [WedgeController::class, 'channels'])->name('channels');             // A7

        // WS 2.5 — Architecture Completeness Score Card (B5).
        Route::get('/score-card', [EaController::class, 'scoreCard'])->name('score-card');
        Route::get('/my-architecture', [StewardshipController::class, 'myArchitecture'])->name('stewardship.my-architecture');
        Route::get('/ownership', [StewardshipController::class, 'ownership'])->name('stewardship.ownership');
        Route::get('/stewardship/panel', [StewardshipController::class, 'panel'])->name('stewardship.panel');
        Route::post('/stewardship/subscribe', [StewardshipController::class, 'subscribe'])->middleware('permission:edit ea')->name('stewardship.subscribe');
        Route::delete('/stewardship/subscriptions/{subscription}', [StewardshipController::class, 'unsubscribe'])->middleware('permission:edit ea')->name('stewardship.unsubscribe');

        // Approving a seal asserts the record is trustworthy enough to cite in
        // a regulatory return, so it rides `approve ea`, not `edit ea`.
        Route::post('/seals/approve', [StewardshipController::class, 'approveSeal'])->middleware('permission:approve ea')->name('seals.approve');
        Route::post('/seals/flag', [StewardshipController::class, 'flagSeal'])->middleware('permission:edit ea')->name('seals.flag');
        Route::post('/seals/reject', [StewardshipController::class, 'rejectSeal'])->middleware('permission:approve ea')->name('seals.reject');
        Route::get('/seal-policies', [StewardshipController::class, 'policies'])->name('seals.policies');
        Route::post('/seal-policies', [StewardshipController::class, 'savePolicy'])->middleware('permission:delete ea')->name('seals.policies.save');

        /* ---------- WS 1.2 — surveys & campaigns ---------- */
        Route::get('/surveys', [SurveyController::class, 'index'])->name('surveys');
        Route::post('/surveys', [SurveyController::class, 'store'])->middleware('permission:create ea')->name('surveys.store');
        Route::put('/surveys/{survey}', [SurveyController::class, 'update'])->middleware('permission:edit ea')->name('surveys.update');
        Route::delete('/surveys/{survey}', [SurveyController::class, 'destroy'])->middleware('permission:delete ea')->name('surveys.destroy');
        Route::get('/surveys/{survey}/preview', [SurveyController::class, 'preview'])->name('surveys.preview');
        Route::post('/surveys/{survey}/launch', [SurveyController::class, 'launch'])->middleware('permission:create ea')->name('surveys.launch');
        Route::get('/campaigns/{campaign}', [SurveyController::class, 'campaign'])->name('surveys.campaign');
        Route::post('/campaigns/{campaign}/remind', [SurveyController::class, 'remind'])->middleware('permission:edit ea')->name('surveys.remind');
        Route::post('/campaigns/{campaign}/close', [SurveyController::class, 'close'])->middleware('permission:edit ea')->name('surveys.close');

        /* ---------- WS 1.4 — Architecture Decision Records ---------- */
        Route::get('/decisions', [DecisionRecordController::class, 'index'])->name('decisions');
        Route::get('/decisions/{record}', [DecisionRecordController::class, 'show'])->name('decisions.show');
        Route::post('/decisions', [DecisionRecordController::class, 'store'])->middleware('permission:create ea')->name('decisions.store');
        Route::put('/decisions/{record}', [DecisionRecordController::class, 'update'])->middleware('permission:edit ea')->name('decisions.update');
        Route::delete('/decisions/{record}', [DecisionRecordController::class, 'destroy'])->middleware('permission:delete ea')->name('decisions.destroy');
        Route::post('/arb/{submission}/decision-record', [DecisionRecordController::class, 'fromArb'])->middleware('permission:create ea')->name('decisions.from-arb');

        /* ---------- WS 1.6 — My Tasks (authenticated portal) ---------- */
        Route::get('/my-tasks', [PortalController::class, 'myTasks'])->name('portal.my-tasks');
    });

    /* ========================================================
     |  Regulatory Returns (ATH-EAR-002 A1)
     |
     |  §5.5 promotes this OUT of the EA module deliberately: "The CSAT return,
     |  NDPC CAR, localisation gap report and quarterly board pack draw on EA,
     |  CSAT, Risk, Control, Vendor, Incident and BCP data. Burying them inside
     |  EA hides the product's best feature from the CISO who buys it."
     |========================================================*/
    Route::prefix('regulatory-returns')->name('regulatory-returns.')->middleware('permission:view ea')->group(function () {
        Route::get('/', [RegulatoryReturnController::class, 'index'])->name('index');
        Route::get('/{return}', [RegulatoryReturnController::class, 'show'])->name('show');
        Route::post('/compile', [RegulatoryReturnController::class, 'compile'])
            ->middleware('permission:create ea')->name('compile');
        Route::post('/{return}/sign', [RegulatoryReturnController::class, 'sign'])
            ->middleware('permission:approve ea')->name('sign');
        Route::post('/{return}/submit', [RegulatoryReturnController::class, 'submit'])
            ->middleware('permission:approve ea')->name('submit');
        Route::get('/{return}/download', [RegulatoryReturnController::class, 'download'])
            ->middleware('permission:export ea')->name('download');
    });

    /* ========================================================
     |  Administration — Users, Roles & Permissions
     |========================================================*/
    Route::prefix('admin')->name('admin.')->group(function () {
        // User Management
        Route::middleware('permission:view users')->group(function () {
            Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserManagementController::class, 'create'])
                ->middleware('permission:create users')->name('users.create');
            Route::post('/users', [UserManagementController::class, 'store'])
                ->middleware('permission:create users')->name('users.store');
            Route::get('/users/{user}/edit', [UserManagementController::class, 'edit'])
                ->middleware('permission:edit users')->name('users.edit');
            Route::put('/users/{user}', [UserManagementController::class, 'update'])
                ->middleware('permission:edit users')->name('users.update');
            Route::patch('/users/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])
                ->middleware('permission:edit users')->name('users.toggle-active');
            Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])
                ->middleware('permission:delete users')->name('users.destroy');
        });

        // Roles & Permissions
        Route::middleware('permission:view roles')->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/roles/create', [RoleController::class, 'create'])
                ->middleware('permission:create roles')->name('roles.create');
            Route::post('/roles', [RoleController::class, 'store'])
                ->middleware('permission:create roles')->name('roles.store');
            Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
            Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])
                ->middleware('permission:edit roles')->name('roles.edit');
            Route::put('/roles/{role}', [RoleController::class, 'update'])
                ->middleware('permission:edit roles')->name('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
                ->middleware('permission:delete roles')->name('roles.destroy');
        });
    });

    // Settings
    Route::get('/settings/organization', [SettingsController::class, 'organization'])
        ->middleware('permission:view organizations')->name('settings.organization');
    Route::put('/settings/organization', [SettingsController::class, 'updateOrganization'])
        ->middleware('permission:edit organizations')->name('settings.organization.update');
    Route::get('/settings/users', [SettingsController::class, 'users'])
        ->middleware('permission:view users')->name('settings.users');
    Route::get('/settings/notifications', [SettingsController::class, 'notifications'])->name('settings.notifications');
    Route::get('/settings/audit-trail', [AuditTrailController::class, 'index'])
        ->middleware('permission:view audit-trail')->name('settings.audit-trail');
});

/* ========================================================
 |  EA survey portal — UNAUTHENTICATED by design
 |
 |  ATH-EAR-002 §5.4 B2 requires "magic-link responses **from non-licensed
 |  users**". §3.4 records that LeanIX "can only send surveys to active
 |  licensed users, not to contacts — a structural crowdsourcing ceiling made
 |  worse by Viewer licences costing money". Beating that means the respondent
 |  cannot be required to hold an account.
 |
 |  The token is the credential and the blast radius is deliberately tiny:
 |    · 64 random chars, minted per (recipient × entity);
 |    · grants no read access to the repository — only the fields the survey
 |      declared, on the one record it was issued for;
 |    · SurveyEngine::submit() rejects any attribute the survey did not declare,
 |      so a token holder cannot write arbitrary columns;
 |    · stops working once submitted, declined, or the campaign closes.
 |========================================================*/
Route::prefix('ea/respond')->name('ea.portal.')->group(function () {
    Route::get('/{token}', [PortalController::class, 'respond'])->name('respond');
    Route::post('/{token}', [PortalController::class, 'submit'])->name('submit');
    Route::post('/{token}/decline', [PortalController::class, 'decline'])->name('decline');
});

require __DIR__.'/auth.php';
