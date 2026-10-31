<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Assets\AssetDiscoveryController;
use App\Http\Controllers\Assets\BusinessServiceMapController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\Auth\SamlController;
use App\Http\Controllers\Auth\ScimController;
use App\Http\Controllers\BcpController;
use App\Http\Controllers\BusinessAssetController;
use App\Http\Controllers\CapaController;
use App\Http\Controllers\Change\ChangeCabController;
use App\Http\Controllers\Change\ChangePolicyController;
use App\Http\Controllers\Change\ChangeRequestController as ChangeManagementController;
use App\Http\Controllers\Change\ChangeTaskController;
use App\Http\Controllers\ChangeRequestController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\ControlController;
use App\Http\Controllers\ControlStandardController;
use App\Http\Controllers\CoreBankingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataBreachController;
use App\Http\Controllers\DrController;
use App\Http\Controllers\Ea\DecisionRecordController;
use App\Http\Controllers\Ea\DepthController;
use App\Http\Controllers\Ea\MaturityController as EaMaturityController;
use App\Http\Controllers\Ea\PortalController;
use App\Http\Controllers\Ea\SecurityAssuranceController as EaSecurityAssuranceController;
use App\Http\Controllers\Ea\StewardshipController;
use App\Http\Controllers\Ea\SurveyController;
use App\Http\Controllers\Ea\WedgeController;
use App\Http\Controllers\EaController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\Finance\AccrualController;
use App\Http\Controllers\Finance\ActualsController;
use App\Http\Controllers\Finance\AllocationController;
use App\Http\Controllers\Finance\BenefitController;
use App\Http\Controllers\Finance\BudgetController;
use App\Http\Controllers\Finance\BudgetCycleController;
use App\Http\Controllers\Finance\BudgetLineController;
use App\Http\Controllers\Finance\BudgetScenarioController;
use App\Http\Controllers\Finance\BudgetTargetController;
use App\Http\Controllers\Finance\BudgetTransferController;
use App\Http\Controllers\Finance\BudgetWorkbookController;
use App\Http\Controllers\Finance\BusinessCaseController;
use App\Http\Controllers\Finance\CapitalisationController;
use App\Http\Controllers\Finance\ChargebackController;
use App\Http\Controllers\Finance\CloudAccountController;
use App\Http\Controllers\Finance\CloudAnomalyController;
use App\Http\Controllers\Finance\CloudCostController;
use App\Http\Controllers\Finance\CloudImportController;
use App\Http\Controllers\Finance\CloudTagRuleController;
use App\Http\Controllers\Finance\ContractController;
use App\Http\Controllers\Finance\ContractRelationsController;
use App\Http\Controllers\Finance\DoaBandController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\FinanceCalendarController;
use App\Http\Controllers\Finance\FinanceCurrencyController;
use App\Http\Controllers\Finance\FinanceDashboardController;
use App\Http\Controllers\Finance\FinanceDimensionsController;
use App\Http\Controllers\Finance\FinanceDocumentController;
use App\Http\Controllers\Finance\FinanceImportController;
use App\Http\Controllers\Finance\FinanceReportController;
use App\Http\Controllers\Finance\FinanceSettingsController;
use App\Http\Controllers\Finance\FiscalCalendarController;
use App\Http\Controllers\Finance\InvestmentChangeController;
use App\Http\Controllers\Finance\InvestmentController;
use App\Http\Controllers\Finance\InvoiceController;
use App\Http\Controllers\Finance\MonitorController;
use App\Http\Controllers\Finance\PeriodCloseController;
use App\Http\Controllers\Finance\PortfolioController;
use App\Http\Controllers\Finance\PurchaseOrderController;
use App\Http\Controllers\Finance\RecurringExpenseController;
use App\Http\Controllers\Finance\RenewalController;
use App\Http\Controllers\Finance\ShowbackController;
use App\Http\Controllers\Finance\StageGateController;
use App\Http\Controllers\Finance\SupplementaryRequestController;
use App\Http\Controllers\Finance\TcoController;
use App\Http\Controllers\Finance\TerminationController;
use App\Http\Controllers\Finance\ValueReviewController;
use App\Http\Controllers\GapController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\IsmsController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\Kri\KriController;
use App\Http\Controllers\Monitoring\AccessReviewController;
use App\Http\Controllers\Monitoring\CcmController;
use App\Http\Controllers\Monitoring\DriftController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PciController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PolicyAttestationController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\PolicyExceptionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionLibraryController;
use App\Http\Controllers\RegulatoryIntelligenceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Reports\BoardPackController;
use App\Http\Controllers\ResponseProcedureController;
use App\Http\Controllers\Returns\RegulatoryReturnController;
use App\Http\Controllers\RiskAssessmentController;
use App\Http\Controllers\RiskController;
use App\Http\Controllers\RiskTreatmentController;
use App\Http\Controllers\SecurityAlertController;
use App\Http\Controllers\SecurityOperationsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ThreatController;
use App\Http\Controllers\VendorAssessmentController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\Vendors\SecurityRatingController;
use App\Http\Controllers\Vendors\SharedDirectoryController;
use App\Http\Controllers\VulnerabilityController;
use App\Http\Controllers\VulnerabilityTicketController;
use App\Http\Controllers\Workflows\WorkflowStudioController;
use App\Modules\CBNCSAT\Http\Controllers\CsatAssessmentController;
use App\Services\Finance\Reports\FinanceReports;
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

// SIEM / SOAR webhook ingest — HMAC-signed per connector, verified inside SiemIngestService
Route::post('/siem/ingest/{integration}', [SecurityOperationsController::class, 'siemIngest'])
    ->whereNumber('integration')->middleware('throttle:120,1')->name('siem.ingest');

// ITSM ticket-status webhook — HMAC-signed per connector, verified inside ItsmConnector
Route::post('/itsm/webhook/{connection}', [IssueController::class, 'itsmWebhook'])
    ->whereNumber('connection')->middleware('throttle:120,1')->name('itsm.webhook');

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
    Route::post('/controls/{control}/mappings', [ControlController::class, 'storeMapping'])
        ->middleware('permission:edit controls')->name('controls.mappings.store');
    Route::delete('/controls/{control}/mappings/{requirement}', [ControlController::class, 'destroyMapping'])
        ->middleware('permission:edit controls')->name('controls.mappings.destroy');

    // Regulatory Frameworks
    Route::middleware('permission:view frameworks')->group(function () {
        Route::get('/frameworks', [ComplianceController::class, 'frameworkIndex'])->name('frameworks.index');
        Route::get('/frameworks/{framework}', [ComplianceController::class, 'frameworkShow'])->name('frameworks.show');
        Route::get('/frameworks/{framework}/requirements', [ControlController::class, 'requirementOptions'])->name('frameworks.requirements');
    });

    // Compliance Assessments
    Route::middleware('permission:view compliance-assessments')->group(function () {
        Route::get('/compliance-assessments', [ComplianceController::class, 'assessmentIndex'])->name('compliance-assessments.index');
        Route::get('/compliance-assessments/create', [ComplianceController::class, 'assessmentCreate'])
            ->middleware('permission:create compliance-assessments')->name('compliance-assessments.create');
        Route::post('/compliance-assessments', [ComplianceController::class, 'assessmentStore'])
            ->middleware('permission:create compliance-assessments')->name('compliance-assessments.store');
        Route::get('/compliance-assessments/{complianceAssessment}', [ComplianceController::class, 'assessmentShow'])->name('compliance-assessments.show');
        Route::put('/compliance-assessments/{complianceAssessment}', [ComplianceController::class, 'assessmentUpdate'])
            ->middleware('permission:edit compliance-assessments')->name('compliance-assessments.update');
        Route::post('/compliance-assessments/{complianceAssessment}/transition', [ComplianceController::class, 'assessmentTransition'])
            ->middleware('permission:edit compliance-assessments')->name('compliance-assessments.transition');
        Route::delete('/compliance-assessments/{complianceAssessment}', [ComplianceController::class, 'assessmentDestroy'])
            ->middleware('permission:delete compliance-assessments')->name('compliance-assessments.destroy');
        Route::patch('/compliance-results/{result}', [ComplianceController::class, 'updateResult'])
            ->middleware('permission:edit compliance-assessments')->name('compliance-results.update');
    });

    // Evidence
    Route::middleware('permission:view evidence')->group(function () {
        Route::get('/evidence', [EvidenceController::class, 'index'])->name('evidence.index');
        Route::post('/evidence', [EvidenceController::class, 'store'])
            ->middleware('permission:create evidence')->name('evidence.store');
        Route::get('/evidence/{evidence}/download', [EvidenceController::class, 'download'])->name('evidence.download');
        Route::patch('/evidence/{evidence}/review', [EvidenceController::class, 'review'])
            ->middleware('permission:approve evidence')->name('evidence.review');
        Route::delete('/evidence/{evidence}', [EvidenceController::class, 'destroy'])
            ->middleware('permission:delete evidence')->name('evidence.destroy');
    });

    // Gap Analysis
    Route::middleware('permission:view gap-analysis')->group(function () {
        Route::get('/gap-analysis', [GapController::class, 'index'])->name('gap-analysis.index');
        Route::post('/gap-analysis', [GapController::class, 'store'])
            ->middleware('permission:create gap-analysis')->name('gap-analysis.store');
        Route::put('/gap-analysis/{gap}', [GapController::class, 'update'])
            ->middleware('permission:edit gap-analysis')->name('gap-analysis.update');
        Route::delete('/gap-analysis/{gap}', [GapController::class, 'destroy'])
            ->middleware('permission:delete gap-analysis')->name('gap-analysis.destroy');
    });

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
    Route::post('/incidents/{incident}/procedure', [IncidentController::class, 'assignProcedure'])
        ->middleware('permission:edit incidents')->name('incidents.procedure');

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
    Route::post('/security-alerts/{security_alert}/link', [SecurityAlertController::class, 'linkIncident'])
        ->middleware('permission:edit security-alerts')->name('security-alerts.link');

    // Data Breaches
    Route::resource('data-breaches', DataBreachController::class)
        ->parameters(['data-breaches' => 'breach'])
        ->middleware('permission:view data-breaches')
        ->middlewareFor(['create', 'store'], 'permission:create data-breaches')
        ->middlewareFor(['edit', 'update'], 'permission:edit data-breaches')
        ->middlewareFor('destroy', 'permission:delete data-breaches');
    Route::post('/data-breaches/{breach}/notified', [DataBreachController::class, 'recordNotification'])
        ->middleware('permission:edit data-breaches')->name('data-breaches.notified');

    // Response Procedures (share the incidents permission set)
    Route::resource('response-procedures', ResponseProcedureController::class)
        ->middleware('permission:view incidents')
        ->middlewareFor(['create', 'store'], 'permission:create incidents')
        ->middlewareFor(['edit', 'update'], 'permission:edit incidents')
        ->middlewareFor('destroy', 'permission:delete incidents');

    // Asset Management (ATH-VAL-007). Literal paths come before the resource so they are not
    // captured as {asset}.
    Route::middleware('permission:view assets')->group(function () {
        Route::get('/assets/export', [AssetController::class, 'export'])->middleware('permission:export assets')->name('assets.export');
        Route::put('/assets/{asset}/services', [AssetController::class, 'services'])->middleware('permission:edit assets')->name('assets.services');
        Route::post('/assets/{asset}/review', [AssetController::class, 'review'])->middleware('permission:edit assets')->name('assets.review');

        Route::get('/asset-discovery', [AssetDiscoveryController::class, 'index'])->name('asset-discovery.index');
        Route::post('/asset-discovery/sync', [AssetDiscoveryController::class, 'sync'])->middleware('permission:create assets')->name('asset-discovery.sync');
        Route::post('/asset-discovery/import', [AssetDiscoveryController::class, 'import'])->middleware('permission:create assets')->name('asset-discovery.import');
        Route::get('/asset-discovery/template', [AssetDiscoveryController::class, 'template'])->name('asset-discovery.template');

        Route::get('/business-services', [BusinessServiceMapController::class, 'index'])->name('business-services.index');
        Route::get('/business-services/graph', [BusinessServiceMapController::class, 'graph'])->name('business-services.graph');

        Route::put('/business-assets/{business_asset}/assets', [BusinessAssetController::class, 'assets'])->middleware('permission:edit assets')->name('business-assets.assets');
        Route::post('/business-assets/{business_asset}/dependencies', [BusinessAssetController::class, 'storeDependency'])->middleware('permission:edit assets')->name('business-assets.dependencies.store');
        Route::delete('/business-assets/{business_asset}/dependencies/{relationship}', [BusinessAssetController::class, 'destroyDependency'])->middleware('permission:edit assets')->whereNumber('relationship')->name('business-assets.dependencies.destroy');
    });

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

    // Vendor Management (ATH-VAL-008)
    Route::middleware('permission:view vendors')->group(function () {
        Route::get('/security-ratings', [SecurityRatingController::class, 'index'])->name('security-ratings.index');
        Route::post('/security-ratings/refresh', [SecurityRatingController::class, 'refresh'])->middleware('permission:edit vendors')->name('security-ratings.refresh');
        Route::post('/security-ratings/events/{event}/triage', [SecurityRatingController::class, 'triage'])->middleware('permission:edit vendors')->name('security-ratings.triage');
        Route::get('/shared-vendors', [SharedDirectoryController::class, 'index'])->name('shared-vendors.index');
        Route::post('/shared-vendors/{entry}/link', [SharedDirectoryController::class, 'link'])->middleware('permission:edit vendors')->name('shared-vendors.link');
    });
    Route::post('/vendor-assessments/{vendor_assessment}/complete', [VendorAssessmentController::class, 'complete'])
        ->middleware('permission:edit vendor-assessments')->name('vendor-assessments.complete');

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
    // Workflow actions; the controller checks edit vs approve per action (ATH-VAL-009).
    Route::post('/policies/{policy}/transition', [PolicyController::class, 'transition'])
        ->middleware('permission:edit policies|approve policies')->name('policies.transition');
    // Attestation is for all staff: the controller allows anyone asked to attest, or with view policies.
    Route::post('/policies/{policy}/attest', [PolicyController::class, 'attest'])->name('policies.attest');
    Route::get('/my-attestations', [PolicyAttestationController::class, 'mine'])->name('my-attestations.index');

    // Control Standards
    Route::get('/control-standards', [ControlStandardController::class, 'index'])
        ->middleware('permission:view controls')->name('control-standards.index');

    // Policy Attestations (read-only register — writes go through policies.attest)
    Route::resource('policy-attestations', PolicyAttestationController::class)->only(['index', 'show'])
        ->middleware('permission:view policy-attestations');
    Route::post('/policy-attestations/{policy_attestation}/waive', [PolicyAttestationController::class, 'waive'])
        ->middleware('permission:approve policy-attestations')->name('policy-attestations.waive');

    // Change Requests (legacy stub) — redirects to the Change Management module at /changes
    Route::get('/change-requests', [ChangeRequestController::class, 'index'])
        ->middleware('permission:view policies')->name('change-requests.index');

    // Policy Exceptions
    Route::get('/policy-exceptions', [PolicyExceptionController::class, 'index'])
        ->middleware('permission:view policies')->name('policy-exceptions.index');
    Route::post('/policy-exceptions', [PolicyExceptionController::class, 'store'])
        ->middleware('permission:view policies')->name('policy-exceptions.store');
    Route::post('/policy-exceptions/{exception}/decide', [PolicyExceptionController::class, 'decide'])
        ->middleware('permission:approve policies')->name('policy-exceptions.decide');

    // ISMS Module
    Route::middleware('permission:view isms')->group(function () {
        Route::get('/isms', [IsmsController::class, 'index'])->name('isms.index');
        Route::get('/isms/risks', [IsmsController::class, 'risks'])->name('isms.risks');
        Route::get('/isms/controls', [IsmsController::class, 'controls'])->name('isms.controls');
        Route::get('/isms/audit', [IsmsController::class, 'audit'])->name('isms.audit');
        Route::get('/isms/soa', [IsmsController::class, 'soa'])->name('isms.soa');
        Route::get('/isms/soa/export', [IsmsController::class, 'soaExport'])->middleware('permission:export isms')->name('isms.soa.export');
        Route::put('/isms/soa/{requirement}', [IsmsController::class, 'soaUpdate'])->middleware('permission:edit isms')->whereNumber('requirement')->name('isms.soa.update');
        Route::post('/isms/soa/versions', [IsmsController::class, 'soaSubmit'])->middleware('permission:edit isms')->name('isms.soa.submit');
        Route::post('/isms/soa/versions/{version}/decide', [IsmsController::class, 'soaDecide'])->middleware('permission:approve isms')->name('isms.soa.decide');
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
        Route::put('/pci/cde/{asset}', [PciController::class, 'scope'])->middleware('permission:edit pci')->name('pci.cde.scope');
        Route::post('/pci/controls/mappings', [PciController::class, 'mapControl'])->middleware('permission:edit pci')->name('pci.controls.map');
        Route::delete('/pci/controls/mappings/{mapping}', [PciController::class, 'unmapControl'])->middleware('permission:edit pci')->whereNumber('mapping')->name('pci.controls.unmap');
    });

    // BCP/DR
    Route::middleware('permission:view bcp-plans')->group(function () {
        Route::get('/bcp/plans', [BcpController::class, 'planIndex'])->name('bcp.plans');
        Route::get('/bcp/plans/create', [BcpController::class, 'planCreate'])
            ->middleware('permission:create bcp-plans')->name('bcp.plans.create');
        Route::post('/bcp/plans', [BcpController::class, 'planStore'])
            ->middleware('permission:create bcp-plans')->name('bcp.plans.store');
        Route::get('/bcp/plans/{bcpPlan}', [BcpController::class, 'planShow'])->name('bcp.plans.show');
        Route::get('/bcp/plans/{bcpPlan}/edit', [BcpController::class, 'planEdit'])->middleware('permission:edit bcp-plans')->name('bcp.plans.edit');
        Route::put('/bcp/plans/{bcpPlan}', [BcpController::class, 'planUpdate'])->middleware('permission:edit bcp-plans')->name('bcp.plans.update');
        Route::post('/bcp/plans/{bcpPlan}/approve', [BcpController::class, 'planApprove'])->middleware('permission:approve bcp-plans')->name('bcp.plans.approve');
        Route::post('/bcp/plans/{bcpPlan}/tests', [BcpController::class, 'planTest'])->middleware('permission:edit bcp-plans')->name('bcp.plans.tests.store');
        Route::put('/bcp/bia/{biaRecord}', [BcpController::class, 'biaUpdate'])->middleware('permission:edit bcp-plans')->name('bcp.bia.update');
        Route::post('/bcp/bia/{biaRecord}/apply', [BcpController::class, 'biaApply'])->middleware('permission:approve bcp-plans')->name('bcp.bia.apply');

        // DR runbooks and exercises (moved from the platform group; ATH-VAL-012)
        Route::get('/dr/runbooks', [DrController::class, 'runbooks'])->name('dr.runbooks');
        Route::get('/dr/runbooks/{runbook}', [DrController::class, 'show'])->name('dr.runbook.show');
        Route::post('/dr/runbooks/{runbook}/exercises', [DrController::class, 'record'])->middleware('permission:edit bcp-plans')->name('dr.exercises.store');
        Route::get('/dr/exercises', [DrController::class, 'exercises'])->name('dr.exercises');
        Route::get('/bcp/bia', [BcpController::class, 'biaIndex'])->name('bcp.bia');
        Route::post('/bcp/bia', [BcpController::class, 'biaStore'])
            ->middleware('permission:create bcp-plans')->name('bcp.bia.store');

        // DR Plans
        Route::get('/bcp/dr-plans', [BcpController::class, 'drPlanIndex'])->name('bcp.dr-plans');

        // BCP Tests & Exercises
        Route::get('/bcp/tests', [BcpController::class, 'testIndex'])->name('bcp.tests');
    });

    // Continuous Monitoring
    // (ATH-VAL-013: CCM moved here from the platform group; drift and access reviews are registers.)
    Route::middleware('permission:view monitoring')->group(function () {
        Route::get('/monitoring/dashboard', [MonitoringController::class, 'dashboard'])->name('monitoring.dashboard');
        Route::get('/monitoring/controls', [MonitoringController::class, 'controls'])->name('monitoring.controls');

        Route::get('/ccm', [CcmController::class, 'index'])->name('ccm.index');
        Route::get('/ccm/{tenantTest}', [CcmController::class, 'show'])->name('ccm.show');
        Route::post('/ccm/{tenantTest}/run', [CcmController::class, 'run'])->middleware('permission:edit monitoring')->name('ccm.run');
        Route::put('/ccm/{tenantTest}', [CcmController::class, 'update'])->middleware('permission:edit monitoring')->name('ccm.update');

        Route::get('/monitoring/drift', [DriftController::class, 'index'])->name('monitoring.drift');
        Route::post('/monitoring/drift/scan', [DriftController::class, 'scan'])->middleware('permission:edit monitoring')->name('drift.scan');
        Route::post('/monitoring/drift', [DriftController::class, 'store'])->middleware('permission:create monitoring')->name('drift.store');
        Route::get('/monitoring/drift/{drift}', [DriftController::class, 'show'])->name('drift.show');
        Route::post('/monitoring/drift/{drift}/transition', [DriftController::class, 'transition'])->middleware('permission:edit monitoring')->name('drift.transition');

        Route::get('/monitoring/access-reviews', [AccessReviewController::class, 'index'])->name('monitoring.access-reviews');
        Route::post('/monitoring/access-reviews', [AccessReviewController::class, 'store'])->middleware('permission:create monitoring')->name('access-reviews.store');
        Route::get('/monitoring/access-reviews/{campaign}', [AccessReviewController::class, 'show'])->name('access-reviews.show');
        Route::post('/monitoring/access-reviews/{campaign}/items', [AccessReviewController::class, 'addItem'])->middleware('permission:edit monitoring')->name('access-reviews.items.store');
        Route::post('/monitoring/access-reviews/{campaign}/complete', [AccessReviewController::class, 'complete'])->middleware('permission:approve monitoring')->name('access-reviews.complete');
        Route::post('/monitoring/access-review-items/{item}/decide', [AccessReviewController::class, 'decide'])->middleware('permission:edit monitoring')->name('access-reviews.items.decide');
        Route::post('/monitoring/access-review-items/{item}/confirm', [AccessReviewController::class, 'confirm'])->middleware('permission:edit monitoring')->name('access-reviews.items.confirm');
    });

    // KRIs & Dashboards (ATH-VAL-014: KRIs sit with risk management, board packs with reporting;
    // both moved here from the platform group)
    Route::middleware('permission:view risks')->group(function () {
        Route::get('/kri', [KriController::class, 'index'])->name('kri.index');
        Route::post('/kri', [KriController::class, 'store'])->middleware('permission:create risks')->name('kri.store');
        Route::get('/kri/{kri}', [KriController::class, 'show'])->name('kri.show');
        Route::put('/kri/{kri}', [KriController::class, 'update'])->middleware('permission:edit risks')->name('kri.update');
        Route::post('/kri/{kri}/readings', [KriController::class, 'record'])->middleware('permission:edit risks')->name('kri.readings.store');
        Route::post('/kri/{kri}/measure', [KriController::class, 'measure'])->middleware('permission:edit risks')->name('kri.measure');
        Route::post('/kri-breaches/{breach}/acknowledge', [KriController::class, 'acknowledge'])->middleware('permission:edit risks')->name('kri.breaches.acknowledge');
    });
    Route::middleware('permission:view reports')->group(function () {
        Route::get('/board-packs', [BoardPackController::class, 'index'])->name('board-packs.index');
        Route::post('/board-packs/generate', [BoardPackController::class, 'generate'])->middleware('permission:create reports')->name('board-packs.generate');
        Route::get('/board-packs/{run}', [BoardPackController::class, 'show'])->name('board-packs.show');
        Route::post('/board-packs/{run}/approve', [BoardPackController::class, 'approve'])->middleware('permission:approve reports')->name('board-packs.approve');
    });

    // Workflow Studio: who starts and who approves each process in the platform (ATH-VAL-016).
    // The marketplace and pricing pages were removed from the product.
    // A record page's approval trail: gated on the record's module in the controller, so anyone who
    // can see the record sees where its approval stands.
    Route::get('/workflows/trail', [WorkflowStudioController::class, 'trail'])->name('workflows.trail');
    Route::middleware('permission:view workflows')->prefix('workflows')->name('workflows.')->group(function () {
        Route::get('/', [WorkflowStudioController::class, 'index'])->name('index');
        Route::get('/inbox', [WorkflowStudioController::class, 'inbox'])->name('inbox');
        Route::put('/csat-stages', [WorkflowStudioController::class, 'csatUpdate'])->middleware('permission:edit workflows')->name('csat.update');
        Route::get('/process/{process}', [WorkflowStudioController::class, 'show'])->name('process');
        Route::put('/process/{process}', [WorkflowStudioController::class, 'update'])->middleware('permission:edit workflows')->name('process.update');
        Route::post('/process/{process}/stages', [WorkflowStudioController::class, 'stageStore'])->middleware('permission:edit workflows')->name('stages.store');
        Route::put('/stages/{stage}', [WorkflowStudioController::class, 'stageUpdate'])->middleware('permission:edit workflows')->name('stages.update');
        Route::delete('/stages/{stage}', [WorkflowStudioController::class, 'stageDestroy'])->middleware('permission:edit workflows')->name('stages.destroy');
        Route::post('/stages/{stage}/move', [WorkflowStudioController::class, 'stageMove'])->middleware('permission:edit workflows')->name('stages.move');
    });

    // CBN-CSAT Module
    Route::prefix('csat')->name('csat.')->middleware('permission:view csat')->group(function () {
        Route::get('/', [CsatAssessmentController::class, 'index'])->name('index');
        Route::get('/create', [CsatAssessmentController::class, 'create'])
            ->middleware('permission:create csat')->name('create');
        Route::post('/', [CsatAssessmentController::class, 'store'])
            ->middleware('permission:create csat')->name('store');

        Route::prefix('{assessment}')->group(function () {
            // Reads — `view csat` (group middleware).
            Route::get('/overview', [CsatAssessmentController::class, 'overview'])->name('overview');
            Route::get('/institution-profile', [CsatAssessmentController::class, 'institutionProfile'])->name('institution-profile');
            Route::get('/inherent-risk', [CsatAssessmentController::class, 'inherentRiskDashboard'])->name('ir.dashboard');
            Route::get('/inherent-risk/questions', [CsatAssessmentController::class, 'inherentRiskQuestions'])->name('ir.questions');
            Route::get('/inherent-risk/narratives', [CsatAssessmentController::class, 'inherentRiskNarratives'])->name('ir.narratives');
            Route::get('/maturity', [CsatAssessmentController::class, 'maturityDashboard'])->name('ma.dashboard');
            Route::get('/maturity/assessment', [CsatAssessmentController::class, 'maturityAssessment'])->name('ma.assessment');
            Route::get('/maturity/narratives', [CsatAssessmentController::class, 'maturityNarratives'])->name('ma.narratives');
            Route::get('/maturity/targets', [CsatAssessmentController::class, 'maturityTargets'])->name('ma.targets');
            Route::get('/threats', [CsatAssessmentController::class, 'threats'])->name('threats');
            Route::get('/vulnerabilities', [CsatAssessmentController::class, 'vulnerabilities'])->name('vulnerabilities');
            Route::get('/workflow', [CsatAssessmentController::class, 'workflow'])->name('workflow');
            Route::get('/reports', [CsatAssessmentController::class, 'reports'])->name('reports');
            Route::get('/submission-package', [CsatAssessmentController::class, 'submissionPackage'])
                ->middleware('permission:export csat')->name('submission-package');
            Route::get('/ai-insights', [CsatAssessmentController::class, 'aiInsights'])->name('ai-insights');

            // Writes — answering, registers and narratives need `edit csat`; the
            // controller additionally locks everything once the cycle leaves draft/in_progress.
            Route::middleware('permission:edit csat')->group(function () {
                Route::post('/institution-profile', [CsatAssessmentController::class, 'saveInstitutionProfile'])->name('institution-profile.save');
                Route::post('/stakeholder', [CsatAssessmentController::class, 'saveStakeholder'])->name('stakeholder.save');
                Route::post('/inherent-risk/response', [CsatAssessmentController::class, 'saveIrResponse'])->name('ir.response.save');
                Route::post('/inherent-risk/narrative', [CsatAssessmentController::class, 'saveIrNarrative'])->name('ir.narrative.save');
                Route::post('/maturity/response', [CsatAssessmentController::class, 'saveMaResponse'])->name('ma.response.save');
                Route::post('/maturity/compensating-control', [CsatAssessmentController::class, 'saveCompensatingControl'])->name('ma.cc.save');
                Route::post('/maturity/narrative', [CsatAssessmentController::class, 'saveMaNarrative'])->name('ma.narrative.save');
                Route::post('/maturity/target', [CsatAssessmentController::class, 'saveTarget'])->name('ma.target.save');
                Route::post('/threats', [CsatAssessmentController::class, 'storeThreat'])->name('threats.store');
                Route::put('/threats/{threat}', [CsatAssessmentController::class, 'updateThreat'])->name('threats.update');
                Route::post('/vulnerabilities', [CsatAssessmentController::class, 'storeVulnerability'])->name('vulnerabilities.store');
                Route::put('/vulnerabilities/{vulnerability}', [CsatAssessmentController::class, 'updateVulnerability'])->name('vulnerabilities.update');
                Route::put('/deadline', [CsatAssessmentController::class, 'updateDeadline'])->name('deadline.update');
                Route::post('/workflow/submit', [CsatAssessmentController::class, 'submitForApproval'])->name('workflow.submit');
                Route::post('/ai-insights/generate', [CsatAssessmentController::class, 'generateInsights'])->name('ai.generate');
                Route::put('/ai-insights/{recommendation}', [CsatAssessmentController::class, 'dismissRecommendation'])->name('ai.dismiss');
            });
            Route::middleware('permission:delete csat')->group(function () {
                Route::delete('/threats/{threat}', [CsatAssessmentController::class, 'destroyThreat'])->name('threats.destroy');
                Route::delete('/vulnerabilities/{vulnerability}', [CsatAssessmentController::class, 'destroyVulnerability'])->name('vulnerabilities.destroy');
            });
            Route::middleware('permission:approve csat')->group(function () {
                Route::post('/workflow/approve', [CsatAssessmentController::class, 'approve'])->name('workflow.approve');
                Route::post('/workflow/reject', [CsatAssessmentController::class, 'reject'])->name('workflow.reject');
                Route::post('/workflow/submit-to-cbn', [CsatAssessmentController::class, 'submitToCbn'])->name('workflow.submit-cbn');
            });
        });
    });

    // Reports & Analytics
    Route::middleware('permission:view reports')->group(function () {
        Route::get('/reports/executive', [ReportController::class, 'executive'])->name('reports.executive');
        Route::get('/reports/risks', [ReportController::class, 'riskReport'])->name('reports.risks');
        Route::get('/reports/compliance', [ReportController::class, 'complianceReport'])->name('reports.compliance');
        Route::get('/reports/scheduled', [ReportController::class, 'scheduled'])->name('reports.scheduled');
        Route::post('/reports/scheduled', [ReportController::class, 'scheduledStore'])->middleware('permission:create reports')->name('reports.scheduled.store');
        Route::put('/reports/scheduled/{id}', [ReportController::class, 'scheduledUpdate'])->whereNumber('id')->middleware('permission:edit reports')->name('reports.scheduled.update');
        Route::post('/reports/scheduled/{id}/toggle', [ReportController::class, 'scheduledToggle'])->whereNumber('id')->middleware('permission:edit reports')->name('reports.scheduled.toggle');
        Route::delete('/reports/scheduled/{id}', [ReportController::class, 'scheduledDestroy'])->whereNumber('id')->middleware('permission:edit reports')->name('reports.scheduled.destroy');
    });

    // Phase 2: Risk graph (risk module, lives with platform controller)
    Route::get('/risks-graph', [PlatformController::class, 'risksGraph'])
        ->middleware('permission:view risks')->name('risks.graph');

    // Issues & Remediation: console, SLA policies, ITSM connectors (see ATH-VAL-006)
    Route::middleware('permission:view issues')->group(function () {
        Route::get('/issues', [IssueController::class, 'index'])->name('issues.index');
        Route::get('/issues/sla-policies', [IssueController::class, 'slaPolicies'])->name('issues.sla-policies');
        Route::get('/itsm', [IssueController::class, 'itsm'])->name('itsm.index');
        Route::post('/issues', [IssueController::class, 'store'])->middleware('permission:create issues')->name('issues.store');
        Route::get('/issues/{issue}', [IssueController::class, 'show'])->whereNumber('issue')->name('issues.show');
        // Owners may work their own issues without edit issues; the controller checks.
        Route::put('/issues/{issue}', [IssueController::class, 'update'])->name('issues.update');
        Route::post('/issues/{issue}/transition', [IssueController::class, 'transition'])->name('issues.transition');
        Route::post('/issues/{issue}/escalate', [IssueController::class, 'escalate'])->name('issues.escalate');
        Route::post('/issues/{issue}/comment', [IssueController::class, 'comment'])->name('issues.comment');
        Route::post('/issues/{issue}/push', [IssueController::class, 'push'])->name('issues.push');
        // Integrated CAPA stages (IT-GOV-CAPA-001). Owners or edit issues work a CAPA; sign-off and
        // verification are gated on the RACI Accountable role inside CapaWorkflow.
        Route::get('/issues/capa/export', [CapaController::class, 'export'])->middleware('permission:export issues')->name('issues.capa.export');
        Route::prefix('/issues/{issue}/capa')->whereNumber('issue')->name('issues.capa.')->group(function () {
            Route::put('/', [CapaController::class, 'update'])->name('update');
            Route::post('/stage', [CapaController::class, 'advance'])->name('stage');
            Route::post('/signoff', [CapaController::class, 'signoff'])->name('signoff');
            Route::post('/containment', [CapaController::class, 'containmentStore'])->name('containment.store');
            Route::delete('/containment/{action}', [CapaController::class, 'containmentDestroy'])->whereNumber('action')->name('containment.destroy');
            Route::put('/root-cause', [CapaController::class, 'rootCause'])->name('root-cause');
            Route::post('/actions', [CapaController::class, 'actionStore'])->name('actions.store');
            Route::put('/actions/{action}', [CapaController::class, 'actionUpdate'])->whereNumber('action')->name('actions.update');
            Route::delete('/actions/{action}', [CapaController::class, 'actionDestroy'])->whereNumber('action')->name('actions.destroy');
            Route::post('/verify', [CapaController::class, 'verify'])->name('verify');
        });
        Route::middleware('permission:approve issues')->group(function () {
            Route::put('/issues/sla-policies/{severity}', [IssueController::class, 'slaPolicyUpdate'])->name('issues.sla-policies.update');
            Route::post('/itsm', [IssueController::class, 'itsmStore'])->name('itsm.store');
            Route::put('/itsm/{connection}', [IssueController::class, 'itsmUpdate'])->name('itsm.update');
            Route::post('/itsm/{connection}/test', [IssueController::class, 'itsmTest'])->name('itsm.test');
            Route::post('/itsm/{connection}/secret', [IssueController::class, 'itsmRotateSecret'])->name('itsm.secret');
        });
    });

    // Change Management: register, approvals, forward schedule, CAB/ECAB, policy, templates, freeze windows (ATH-CHG-001 §7)
    Route::middleware('permission:view changes')->prefix('changes')->name('changes.')->group(function () {
        Route::get('/', [ChangeManagementController::class, 'index'])->name('index');
        Route::get('/create', [ChangeManagementController::class, 'create'])->middleware('permission:create changes')->name('create');
        Route::post('/', [ChangeManagementController::class, 'store'])->middleware('permission:create changes')->name('store');
        Route::get('/approvals', [ChangeManagementController::class, 'approvals'])->name('approvals');
        Route::get('/calendar', [ChangeManagementController::class, 'calendar'])->name('calendar');
        Route::get('/export', [ChangeManagementController::class, 'export'])->middleware('permission:export changes')->name('export');

        // CAB & ECAB
        Route::get('/cab', [ChangeCabController::class, 'index'])->name('cab.index');
        Route::get('/cab/meetings/{meeting}', [ChangeCabController::class, 'meetingsShow'])->whereNumber('meeting')->name('cab.meetings.show');
        Route::middleware('permission:approve changes')->group(function () {
            Route::post('/cab/boards', [ChangeCabController::class, 'boardsStore'])->name('cab.boards.store');
            Route::put('/cab/boards/{board}', [ChangeCabController::class, 'boardsUpdate'])->whereNumber('board')->name('cab.boards.update');
            Route::post('/cab/boards/{board}/members', [ChangeCabController::class, 'membersStore'])->whereNumber('board')->name('cab.members.store');
            Route::delete('/cab/members/{member}', [ChangeCabController::class, 'membersDestroy'])->whereNumber('member')->name('cab.members.destroy');
            Route::post('/cab/meetings', [ChangeCabController::class, 'meetingsStore'])->name('cab.meetings.store');
            Route::put('/cab/meetings/{meeting}', [ChangeCabController::class, 'meetingsUpdate'])->whereNumber('meeting')->name('cab.meetings.update');
            Route::post('/cab/meetings/{meeting}/items', [ChangeCabController::class, 'itemsStore'])->whereNumber('meeting')->name('cab.meetings.items.store');
            Route::put('/cab/meeting-items/{item}', [ChangeCabController::class, 'itemsUpdate'])->whereNumber('item')->name('cab.meetings.items.update');
        });

        // Approval policy
        Route::get('/policy', [ChangePolicyController::class, 'policy'])->name('policy');
        Route::middleware('permission:approve changes')->group(function () {
            Route::post('/policy', [ChangePolicyController::class, 'policyStore'])->name('policy.store');
            Route::put('/policy/{stage}', [ChangePolicyController::class, 'policyUpdate'])->whereNumber('stage')->name('policy.update');
            Route::delete('/policy/{stage}', [ChangePolicyController::class, 'policyDestroy'])->whereNumber('stage')->name('policy.destroy');
        });

        // Standard change templates
        Route::get('/templates', [ChangePolicyController::class, 'templates'])->name('templates');
        Route::post('/templates', [ChangePolicyController::class, 'templatesStore'])->middleware('permission:create changes')->name('templates.store');
        Route::put('/templates/{template}', [ChangePolicyController::class, 'templatesUpdate'])->whereNumber('template')->middleware('permission:edit changes')->name('templates.update');
        Route::post('/templates/{template}/approve', [ChangePolicyController::class, 'templatesApprove'])->whereNumber('template')->middleware('permission:approve changes')->name('templates.approve');

        // Freeze windows
        Route::get('/freeze-windows', [ChangePolicyController::class, 'freezeIndex'])->name('freeze.index');
        Route::middleware('permission:approve changes')->group(function () {
            Route::post('/freeze-windows', [ChangePolicyController::class, 'freezeStore'])->name('freeze.store');
            Route::put('/freeze-windows/{window}', [ChangePolicyController::class, 'freezeUpdate'])->whereNumber('window')->name('freeze.update');
            Route::delete('/freeze-windows/{window}', [ChangePolicyController::class, 'freezeDestroy'])->whereNumber('window')->name('freeze.destroy');
        });

        // One change (static paths above are matched first; {change} is numeric)
        Route::get('/{change}', [ChangeManagementController::class, 'show'])->whereNumber('change')->name('show');
        Route::put('/{change}', [ChangeManagementController::class, 'update'])->whereNumber('change')->name('update');
        Route::post('/{change}/transition', [ChangeManagementController::class, 'transition'])->whereNumber('change')->name('transition');
        Route::post('/{change}/decide', [ChangeManagementController::class, 'decide'])->whereNumber('change')->name('decide');
        Route::post('/{change}/vote', [ChangeManagementController::class, 'vote'])->whereNumber('change')->name('vote');
        Route::post('/{change}/schedule', [ChangeManagementController::class, 'schedule'])->whereNumber('change')->name('schedule');
        Route::post('/{change}/tasks', [ChangeTaskController::class, 'store'])->whereNumber('change')->name('tasks.store');
        Route::put('/{change}/tasks/{task}', [ChangeTaskController::class, 'update'])->whereNumber('change')->whereNumber('task')->name('tasks.update');
        Route::post('/{change}/comment', [ChangeManagementController::class, 'comment'])->whereNumber('change')->name('comment');
    });

    // IT Financial Management: foundation & configuration (ATH-FIN-001 §3.1, §9)
    Route::middleware('permission:view it-finance')->prefix('it-finance')->name('finance.')->group(function () {
        Route::get('/settings', [FinanceSettingsController::class, 'show'])->name('settings');

        Route::middleware('permission:manage it-finance-settings')->group(function () {
            Route::put('/settings', [FinanceSettingsController::class, 'update'])->name('settings.update');

            Route::post('/fiscal-years', [FiscalCalendarController::class, 'store'])->name('fiscal-years.store');
            Route::put('/fiscal-years/{fiscalYear}', [FiscalCalendarController::class, 'update'])->whereNumber('fiscalYear')->name('fiscal-years.update');
            Route::delete('/fiscal-years/{fiscalYear}', [FiscalCalendarController::class, 'destroy'])->whereNumber('fiscalYear')->name('fiscal-years.destroy');

            Route::middleware('permission:import it-finance')->group(function () {
                Route::post('/cost-centres/import', [FinanceDimensionsController::class, 'costCentreImport'])->name('cost-centres.import');
                Route::post('/fx-rates/import', [FinanceCurrencyController::class, 'fxRateImport'])->name('fx-rates.import');
            });
            Route::post('/cost-centres', [FinanceDimensionsController::class, 'costCentreStore'])->name('cost-centres.store');
            Route::put('/cost-centres/{costCentre}', [FinanceDimensionsController::class, 'costCentreUpdate'])->whereNumber('costCentre')->name('cost-centres.update');
            Route::delete('/cost-centres/{costCentre}', [FinanceDimensionsController::class, 'costCentreDestroy'])->whereNumber('costCentre')->name('cost-centres.destroy');

            Route::post('/categories', [FinanceDimensionsController::class, 'categoryStore'])->name('categories.store');
            Route::put('/categories/{category}', [FinanceDimensionsController::class, 'categoryUpdate'])->whereNumber('category')->name('categories.update');
            Route::delete('/categories/{category}', [FinanceDimensionsController::class, 'categoryDestroy'])->whereNumber('category')->name('categories.destroy');

            Route::post('/gl-accounts', [FinanceDimensionsController::class, 'glAccountStore'])->name('gl-accounts.store');
            Route::put('/gl-accounts/{glAccount}', [FinanceDimensionsController::class, 'glAccountUpdate'])->whereNumber('glAccount')->name('gl-accounts.update');
            Route::delete('/gl-accounts/{glAccount}', [FinanceDimensionsController::class, 'glAccountDestroy'])->whereNumber('glAccount')->name('gl-accounts.destroy');

            Route::post('/currencies', [FinanceCurrencyController::class, 'currencyStore'])->name('currencies.store');
            Route::put('/currencies/{currency}', [FinanceCurrencyController::class, 'currencyUpdate'])->whereNumber('currency')->name('currencies.update');
            Route::post('/fx-rates', [FinanceCurrencyController::class, 'fxRateStore'])->name('fx-rates.store');
            Route::delete('/fx-rates/{fxRate}', [FinanceCurrencyController::class, 'fxRateDestroy'])->whereNumber('fxRate')->name('fx-rates.destroy');

            Route::post('/tax-types', [FinanceCurrencyController::class, 'taxTypeStore'])->name('tax-types.store');
            Route::put('/tax-types/{taxType}', [FinanceCurrencyController::class, 'taxTypeUpdate'])->whereNumber('taxType')->name('tax-types.update');

            Route::post('/drivers', [FinanceDimensionsController::class, 'driverStore'])->name('drivers.store');
            Route::put('/drivers/{driver}', [FinanceDimensionsController::class, 'driverUpdate'])->whereNumber('driver')->name('drivers.update');
            Route::delete('/drivers/{driver}', [FinanceDimensionsController::class, 'driverDestroy'])->whereNumber('driver')->name('drivers.destroy');
            Route::put('/drivers/{driver}/values', [FinanceDimensionsController::class, 'driverValuesUpdate'])->whereNumber('driver')->name('drivers.values.update');
        });

        // P1 budgeting (ATH-FIN-001 §3.2, §9)
        Route::get('/budgets', [BudgetController::class, 'index'])->name('budgets.index');
        Route::middleware('permission:create it-finance')->group(function () {
            Route::get('/budgets/create', [BudgetController::class, 'create'])->name('budgets.create');
            Route::post('/budgets', [BudgetController::class, 'store'])->name('budgets.store');
            Route::post('/transfers', [BudgetTransferController::class, 'store'])->name('transfers.store');
            Route::post('/supplementary', [SupplementaryRequestController::class, 'store'])->name('supplementary.store');
        });
        Route::get('/budgets/{budget}', [BudgetController::class, 'show'])->whereNumber('budget')->name('budgets.show');
        Route::get('/budgets/{budget}/edit', [BudgetController::class, 'edit'])->whereNumber('budget')->name('budgets.edit');
        Route::get('/budgets/{budget}/diff', [BudgetController::class, 'diff'])->whereNumber('budget')->name('budgets.diff');
        Route::get('/cycles', [BudgetCycleController::class, 'index'])->name('cycles.index');
        Route::get('/targets', [BudgetTargetController::class, 'index'])->name('targets.index');
        Route::get('/transfers', [BudgetTransferController::class, 'index'])->name('transfers.index');
        Route::get('/supplementary', [SupplementaryRequestController::class, 'index'])->name('supplementary.index');
        Route::get('/budgets/{budget}/workbook', [BudgetWorkbookController::class, 'export'])->whereNumber('budget')->middleware('permission:export it-finance')->name('budgets.workbook');

        Route::middleware('permission:edit it-finance')->group(function () {
            Route::put('/budgets/{budget}', [BudgetController::class, 'update'])->whereNumber('budget')->name('budgets.update');
            Route::scopeBindings()->group(function () {
                Route::post('/budgets/{budget}/lines', [BudgetLineController::class, 'store'])->whereNumber('budget')->name('budgets.lines.store');
                Route::put('/budgets/{budget}/lines/bulk', [BudgetLineController::class, 'bulk'])->whereNumber('budget')->name('budgets.lines.bulk');
                Route::post('/budgets/{budget}/lines/reorder', [BudgetLineController::class, 'reorder'])->whereNumber('budget')->name('budgets.lines.reorder');
                Route::put('/budgets/{budget}/lines/{line}', [BudgetLineController::class, 'update'])->whereNumber(['budget', 'line'])->name('budgets.lines.update');
                Route::delete('/budgets/{budget}/lines/{line}', [BudgetLineController::class, 'destroy'])->whereNumber(['budget', 'line'])->name('budgets.lines.destroy');
                Route::post('/budgets/{budget}/lines/{line}/phase', [BudgetLineController::class, 'phase'])->whereNumber(['budget', 'line'])->name('budgets.phase');
                Route::post('/budgets/{budget}/scenarios', [BudgetScenarioController::class, 'store'])->whereNumber('budget')->name('budgets.scenarios.store');
                Route::put('/budgets/{budget}/scenarios/{scenario}', [BudgetScenarioController::class, 'update'])->whereNumber(['budget', 'scenario'])->name('budgets.scenarios.update');
                Route::delete('/budgets/{budget}/scenarios/{scenario}', [BudgetScenarioController::class, 'destroy'])->whereNumber(['budget', 'scenario'])->name('budgets.scenarios.destroy');
                Route::post('/budgets/{budget}/scenarios/{scenario}/promote', [BudgetScenarioController::class, 'promote'])->whereNumber(['budget', 'scenario'])->name('budgets.scenarios.promote');
            });
            Route::post('/budgets/{budget}/workbook', [BudgetWorkbookController::class, 'import'])->whereNumber('budget')->name('budgets.workbook.import');
            Route::post('/budgets/{budget}/comments', [BudgetController::class, 'comment'])->whereNumber('budget')->name('budgets.comments.store');
            Route::post('/budgets/{budget}/submit', [BudgetController::class, 'submit'])->whereNumber('budget')->name('budgets.submit');
            Route::post('/budgets/{budget}/withdraw', [BudgetController::class, 'withdraw'])->whereNumber('budget')->name('budgets.withdraw');
            Route::post('/budgets/{budget}/revise', [BudgetController::class, 'revise'])->whereNumber('budget')->name('budgets.revise');
            Route::put('/transfers/{transfer}', [BudgetTransferController::class, 'update'])->whereNumber('transfer')->name('transfers.update');
            Route::post('/transfers/{transfer}/submit', [BudgetTransferController::class, 'submit'])->whereNumber('transfer')->name('transfers.submit');
            Route::post('/transfers/{transfer}/withdraw', [BudgetTransferController::class, 'withdraw'])->whereNumber('transfer')->name('transfers.withdraw');
            Route::put('/supplementary/{supplementary}', [SupplementaryRequestController::class, 'update'])->whereNumber('supplementary')->name('supplementary.update');
            Route::post('/supplementary/{supplementary}/submit', [SupplementaryRequestController::class, 'submit'])->whereNumber('supplementary')->name('supplementary.submit');
            Route::post('/supplementary/{supplementary}/withdraw', [SupplementaryRequestController::class, 'withdraw'])->whereNumber('supplementary')->name('supplementary.withdraw');
        });

        Route::middleware('permission:approve it-finance')->group(function () {
            Route::post('/budgets/{budget}/approve', [BudgetController::class, 'approve'])->whereNumber('budget')->name('budgets.approve');
            Route::post('/budgets/{budget}/return', [BudgetController::class, 'returnToDraft'])->whereNumber('budget')->name('budgets.return');
            Route::post('/budgets/{budget}/reject', [BudgetController::class, 'reject'])->whereNumber('budget')->name('budgets.reject');
            Route::post('/budgets/{budget}/baseline', [BudgetController::class, 'baseline'])->whereNumber('budget')->name('budgets.baseline');
            foreach (['approve', 'reject', 'apply'] as $action) {
                Route::post("/transfers/{transfer}/{$action}", [BudgetTransferController::class, $action])->whereNumber('transfer')->name("transfers.{$action}");
                Route::post("/supplementary/{supplementary}/{$action}", [SupplementaryRequestController::class, $action])->whereNumber('supplementary')->name("supplementary.{$action}");
            }
        });

        Route::middleware('permission:manage it-finance-settings')->group(function () {
            Route::post('/cycles', [BudgetCycleController::class, 'store'])->name('cycles.store');
            Route::put('/cycles/{cycle}', [BudgetCycleController::class, 'update'])->whereNumber('cycle')->name('cycles.update');
            Route::post('/cycles/{cycle}/transition', [BudgetCycleController::class, 'transition'])->whereNumber('cycle')->name('cycles.transition');
            Route::post('/targets', [BudgetTargetController::class, 'store'])->name('targets.store');
            Route::put('/targets/{target}', [BudgetTargetController::class, 'update'])->whereNumber('target')->name('targets.update');
            Route::delete('/targets/{target}', [BudgetTargetController::class, 'destroy'])->whereNumber('target')->name('targets.destroy');
            Route::post('/doa-bands', [DoaBandController::class, 'store'])->name('doa-bands.store');
            Route::post('/doa-bands/{doaBandVersion}/withdraw', [DoaBandController::class, 'withdraw'])->whereNumber('doaBandVersion')->name('doa-bands.withdraw');
        });
        Route::middleware('permission:approve it-finance|manage it-finance-settings')->group(function () {
            Route::post('/doa-bands/{doaBandVersion}/approve', [DoaBandController::class, 'approve'])->whereNumber('doaBandVersion')->name('doa-bands.approve');
            Route::post('/doa-bands/{doaBandVersion}/reject', [DoaBandController::class, 'reject'])->whereNumber('doaBandVersion')->name('doa-bands.reject');
        });

        // P2 contracts, subscriptions, renewals and calendar (ATH-FIN-001 §3.6, §9)
        Route::middleware('permission:create it-finance')->group(function () {
            Route::get('/contracts/create', [ContractController::class, 'create'])->name('contracts.create');
            Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
        });
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::scopeBindings()->group(function () {
            Route::get('/contracts/{contract}', [ContractController::class, 'show'])->whereNumber('contract')->name('contracts.show');
            Route::get('/contracts/{contract}/documents/{document}/download', [ContractRelationsController::class, 'documentDownload'])->whereNumber(['contract', 'document'])->name('contracts.documents.download');
        });
        Route::get('/renewals', [RenewalController::class, 'index'])->name('renewals.index');
        Route::get('/renewals/{renewal}', [RenewalController::class, 'show'])->whereNumber('renewal')->name('renewals.show');
        Route::get('/terminations/{termination}', [TerminationController::class, 'show'])->whereNumber('termination')->name('terminations.show');
        Route::get('/calendar', [FinanceCalendarController::class, 'index'])->name('calendar');
        Route::post('/calendar/feed/rotate', [FinanceCalendarController::class, 'rotateFeed'])->name('calendar.feed.rotate');
        Route::get('/calendar/export', [FinanceCalendarController::class, 'export'])->middleware('permission:export it-finance')->name('calendar.export');

        Route::middleware('permission:edit it-finance')->group(function () {
            Route::get('/contracts/{contract}/edit', [ContractController::class, 'edit'])->whereNumber('contract')->name('contracts.edit');
            Route::put('/contracts/{contract}', [ContractController::class, 'update'])->whereNumber('contract')->name('contracts.update');
            Route::delete('/contracts/{contract}', [ContractController::class, 'destroy'])->whereNumber('contract')->name('contracts.destroy');
            Route::post('/contracts/{contract}/activate', [ContractController::class, 'activate'])->whereNumber('contract')->name('contracts.activate');
            Route::post('/contracts/{contract}/recompute', [ContractController::class, 'recompute'])->whereNumber('contract')->name('contracts.recompute');
            Route::scopeBindings()->group(function () {
                Route::post('/contracts/{contract}/team', [ContractRelationsController::class, 'teamStore'])->whereNumber('contract')->name('contracts.team.store');
                Route::delete('/contracts/{contract}/team/{member}', [ContractRelationsController::class, 'teamDestroy'])->whereNumber(['contract', 'member'])->name('contracts.team.destroy');
                Route::post('/contracts/{contract}/links', [ContractRelationsController::class, 'linkStore'])->whereNumber('contract')->name('contracts.links.store');
                Route::delete('/contracts/{contract}/links/{link}', [ContractRelationsController::class, 'linkDestroy'])->whereNumber(['contract', 'link'])->name('contracts.links.destroy');
                Route::post('/contracts/{contract}/documents', [ContractRelationsController::class, 'documentStore'])->whereNumber('contract')->name('contracts.documents.store');
            });
            Route::post('/contracts/{contract}/renewals', [RenewalController::class, 'open'])->whereNumber('contract')->name('renewals.open');
            Route::put('/renewals/{renewal}', [RenewalController::class, 'recommend'])->whereNumber('renewal')->name('renewals.recommend');
            foreach (['submit', 'withdraw', 'execute'] as $action) {
                Route::post("/renewals/{renewal}/{$action}", [RenewalController::class, $action])->whereNumber('renewal')->name("renewals.{$action}");
            }
        });
        Route::middleware('permission:approve it-finance-contracts')->group(function () {
            foreach (['approve', 'reject'] as $action) {
                Route::post("/renewals/{renewal}/{$action}", [RenewalController::class, $action])->whereNumber('renewal')->name("renewals.{$action}");
            }
            Route::post('/contracts/{contract}/terminate', [ContractController::class, 'terminate'])->whereNumber('contract')->name('contracts.terminate');
            foreach (['approve', 'reject', 'withdraw'] as $action) {
                Route::post("/terminations/{termination}/{$action}", [TerminationController::class, $action])->whereNumber('termination')->name("terminations.{$action}");
            }
        });

        // P3 expenses, procurement, invoices, actuals, recurring, accruals and imports (ATH-FIN-001 §3.3, §9)
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/availability', [ExpenseController::class, 'availability'])->name('expenses.availability');
        Route::middleware('permission:create it-finance')->group(function () {
            Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
            Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::post('/expenses/{expense}/commit', [ExpenseController::class, 'commit'])->whereNumber('expense')->name('expenses.commit');
            Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
            Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
            Route::post('/recurring', [RecurringExpenseController::class, 'store'])->name('recurring.store');
        });
        Route::middleware('permission:import it-finance')->group(function () {
            Route::post('/expenses/import', [ExpenseController::class, 'import'])->name('expenses.import');
            Route::post('/invoices/import', [InvoiceController::class, 'import'])->name('invoices.import');
            Route::post('/actuals/import', [ActualsController::class, 'import'])->name('actuals.import');
            Route::post('/actuals/auto-match', [ActualsController::class, 'autoMatch'])->name('actuals.auto-match');
            Route::post('/actuals/mappings', [ActualsController::class, 'mappingStore'])->name('actuals.mappings.store');
            Route::delete('/actuals/mappings/{mapping}', [ActualsController::class, 'mappingDestroy'])->whereNumber('mapping')->name('actuals.mappings.destroy');
            Route::post('/actuals/{actual}/confirm-duplicate', [ActualsController::class, 'confirmDuplicate'])->whereNumber('actual')->name('actuals.confirm-duplicate');
            Route::post('/actuals/{actual}/clear-duplicate', [ActualsController::class, 'clearDuplicate'])->whereNumber('actual')->name('actuals.clear-duplicate');
            Route::post('/actuals/rules', [ActualsController::class, 'ruleStore'])->name('actuals.rules.store');
            Route::delete('/actuals/rules/{rule}', [ActualsController::class, 'ruleDestroy'])->whereNumber('rule')->name('actuals.rules.destroy');
            foreach (['match', 'unmatch', 'unbudgeted'] as $action) {
                Route::post("/actuals/{actual}/{$action}", [ActualsController::class, $action])->whereNumber('actual')->name("actuals.{$action}");
            }
        });
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->name('expenses.show');
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder')->name('purchase-orders.show');
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
        Route::get('/actuals', [ActualsController::class, 'index'])->name('actuals.index');
        Route::get('/actuals/{actual}/candidates', [ActualsController::class, 'candidates'])->whereNumber('actual')->name('actuals.candidates');
        Route::get('/recurring', [RecurringExpenseController::class, 'index'])->name('recurring.index');
        Route::get('/accruals', [AccrualController::class, 'index'])->name('accruals.index');
        Route::get('/imports', [FinanceImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/{importBatch}', [FinanceImportController::class, 'show'])->whereNumber('importBatch')->name('imports.show');
        Route::get('/documents/{document}/download', [FinanceDocumentController::class, 'download'])->whereNumber('document')->name('documents.download');
        Route::post('/documents', [FinanceDocumentController::class, 'store'])->middleware('permission:create it-finance|edit it-finance')->name('documents.store');
        foreach (['approve', 'return', 'reject'] as $action) { // gated by FinancePolicy::decideExpense / decideInvoice (the permission, or being the owner the stage awaits)
            Route::post("/expenses/{expense}/{$action}", [ExpenseController::class, $action])->whereNumber('expense')->name("expenses.{$action}");
            Route::post("/invoices/{invoice}/{$action}", [InvoiceController::class, $action])->whereNumber('invoice')->name("invoices.{$action}");
        }
        Route::middleware('permission:edit it-finance')->group(function () {
            Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->whereNumber('expense')->name('expenses.edit');
            Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('expenses.update');
            Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('expenses.destroy');
            foreach (['submit', 'withdraw', 'cancel', 'close'] as $action) {
                Route::post("/expenses/{expense}/{$action}", [ExpenseController::class, $action])->whereNumber('expense')->name("expenses.{$action}");
            }
            Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->whereNumber('purchaseOrder')->name('purchase-orders.update');
            foreach (['receive', 'close', 'cancel'] as $action) {
                Route::post("/purchase-orders/{purchaseOrder}/{$action}", [PurchaseOrderController::class, $action])->whereNumber('purchaseOrder')->name("purchase-orders.{$action}");
            }
            Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->whereNumber('invoice')->name('invoices.edit');
            Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice')->name('invoices.update');
            Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->whereNumber('invoice')->name('invoices.destroy');
            foreach (['match', 'override', 'submit', 'withdraw', 'dispute', 'resolve', 'cancel'] as $action) {
                Route::post("/invoices/{invoice}/{$action}", [InvoiceController::class, $action])->whereNumber('invoice')->name("invoices.{$action}");
            }
            Route::put('/recurring/{recurringExpense}', [RecurringExpenseController::class, 'update'])->whereNumber('recurringExpense')->name('recurring.update');
            Route::delete('/recurring/{recurringExpense}', [RecurringExpenseController::class, 'destroy'])->whereNumber('recurringExpense')->name('recurring.destroy');
            Route::post('/recurring/{recurringExpense}/generate', [RecurringExpenseController::class, 'generate'])->whereNumber('recurringExpense')->name('recurring.generate');
        });
        Route::middleware('permission:approve it-finance-invoices')->group(function () {
            foreach (['schedule', 'pay'] as $action) {
                Route::post("/invoices/{invoice}/{$action}", [InvoiceController::class, $action])->whereNumber('invoice')->name("invoices.{$action}");
            }
        });
        Route::middleware('permission:close it-finance-periods')->group(function () {
            Route::post('/accruals', [AccrualController::class, 'store'])->name('accruals.store');
            Route::post('/accruals/propose', [AccrualController::class, 'propose'])->name('accruals.propose');
            Route::post('/accruals/release-prepayments', [AccrualController::class, 'releasePrepayments'])->name('accruals.release-prepayments');
            foreach (['post', 'reverse', 'cancel'] as $action) {
                Route::post("/accruals/{accrual}/{$action}", [AccrualController::class, $action])->whereNumber('accrual')->name("accruals.{$action}");
            }
        });

        Route::middleware('permission:close it-finance-periods')->group(function () {
            Route::post('/periods/{period}/close', [FiscalCalendarController::class, 'closePeriod'])->whereNumber('period')->name('periods.close');
            Route::post('/periods/{period}/lock', [FiscalCalendarController::class, 'lockPeriod'])->whereNumber('period')->name('periods.lock');
        });

        // P4: monitoring, variance, period close and reports (ATH-FIN-001 §3.4, §9)
        Route::get('/', [FinanceDashboardController::class, 'index'])->name('dashboard');
        Route::get('/monitor/bva', [MonitorController::class, 'bva'])->name('monitor.bva');
        Route::get('/monitor/bva/export', [MonitorController::class, 'bvaExport'])->middleware('permission:export it-finance')->name('monitor.bva.export');
        Route::get('/monitor/lines/{line}/transactions', [MonitorController::class, 'transactions'])->whereNumber('line')->name('monitor.transactions');
        Route::get('/monitor/forecast', [MonitorController::class, 'forecast'])->name('monitor.forecast');
        Route::get('/monitor/variances', [MonitorController::class, 'variances'])->name('monitor.variances');
        Route::get('/monitor/period-close', [PeriodCloseController::class, 'index'])->name('monitor.period-close');
        Route::get('/periods/{period}/close-checklist', [PeriodCloseController::class, 'checklist'])->whereNumber('period')->name('periods.close-checklist');
        Route::get('/period-closes/{periodClose}', [PeriodCloseController::class, 'show'])->whereNumber('periodClose')->name('period-closes.show');
        Route::get('/reports', [FinanceReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{report}', [FinanceReportController::class, 'show'])->whereIn('report', array_keys(FinanceReports::DEFINITIONS))
            ->middleware('permission:export it-finance')->name('reports.show');
        Route::middleware('permission:edit it-finance')->group(function () {
            Route::post('/monitor/forecast/{line}', [MonitorController::class, 'forecastOverride'])->whereNumber('line')->name('monitor.forecast.override');
            Route::delete('/monitor/forecast/{line}', [MonitorController::class, 'forecastClear'])->whereNumber('line')->name('monitor.forecast.clear');
            Route::post('/monitor/variances/{note}/explain', [MonitorController::class, 'explain'])->whereNumber('note')->name('monitor.variances.explain');
            Route::post('/monitor/variances/{note}/comment', [MonitorController::class, 'comment'])->whereNumber('note')->name('monitor.variances.comment');
            Route::post('/monitor/variances/{note}/raise-issue', [MonitorController::class, 'raiseIssue'])->whereNumber('note')->name('monitor.variances.raise-issue');
        });
        Route::middleware('permission:close it-finance-periods')->group(function () {
            Route::post('/monitor/variances/{note}/acknowledge', [MonitorController::class, 'acknowledge'])->whereNumber('note')->name('monitor.variances.acknowledge');
            Route::post('/periods/{period}/evaluate-variances', [MonitorController::class, 'evaluate'])->whereNumber('period')->name('periods.evaluate-variances');
            Route::post('/periods/{period}/reopen', [PeriodCloseController::class, 'requestReopen'])->whereNumber('period')->name('periods.reopen');
            Route::post('/period-closes/{periodClose}/withdraw', [PeriodCloseController::class, 'withdraw'])->whereNumber('periodClose')->name('period-closes.withdraw');
        });
        Route::middleware('permission:approve it-finance')->group(function () {
            Route::post('/period-closes/{periodClose}/approve', [PeriodCloseController::class, 'approve'])->whereNumber('periodClose')->name('period-closes.approve');
            Route::post('/period-closes/{periodClose}/reject', [PeriodCloseController::class, 'reject'])->whereNumber('periodClose')->name('period-closes.reject');
        });

        // P5: IT investment and value realisation (ATH-FIN-001 §3.5, §9)
        Route::get('/investments', [InvestmentController::class, 'index'])->name('investments.index');
        Route::get('/investments/create', [InvestmentController::class, 'create'])->middleware('permission:create it-finance')->name('investments.create');
        Route::get('/investments/{investment}', [InvestmentController::class, 'show'])->whereNumber('investment')->name('investments.show');
        Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio');
        Route::get('/stage-gates/{stageGate}', [StageGateController::class, 'show'])->whereNumber('stageGate')->name('stage-gates.show');
        Route::get('/business-cases/{businessCase}', [BusinessCaseController::class, 'show'])->whereNumber('businessCase')->name('business-cases.show');
        Route::get('/value-reviews/{valueReview}', [ValueReviewController::class, 'show'])->whereNumber('valueReview')->name('value-reviews.show');
        Route::post('/business-cases/evaluate', [BusinessCaseController::class, 'evaluate'])->name('business-cases.evaluate');
        Route::post('/investments/{investment}/score', [InvestmentController::class, 'score'])->whereNumber('investment')->name('investments.score');
        Route::post('/benefits/{benefit}/readings', [BenefitController::class, 'storeReading'])->whereNumber('benefit')->name('benefits.readings.store');
        Route::post('/value-reviews/{valueReview}/complete', [ValueReviewController::class, 'complete'])->whereNumber('valueReview')->name('value-reviews.complete');
        Route::middleware('permission:create it-finance')->group(function () {
            Route::post('/investments', [InvestmentController::class, 'store'])->name('investments.store');
        });
        Route::middleware('permission:edit it-finance')->group(function () {
            Route::put('/investments/{investment}', [InvestmentController::class, 'update'])->whereNumber('investment')->name('investments.update');
            Route::delete('/investments/{investment}', [InvestmentController::class, 'destroy'])->whereNumber('investment')->name('investments.destroy');
            foreach (['advance-gate' => 'advanceGate', 'hold' => 'hold', 'resume' => 'resume', 'cancel' => 'cancel', 'progress' => 'progress', 'budget-lines' => 'syncLines'] as $uri => $method) {
                Route::post("/investments/{investment}/{$uri}", [InvestmentController::class, $method])->whereNumber('investment')->name('investments.'.($uri === 'budget-lines' ? 'lines.sync' : $uri));
            }
            Route::post('/investments/{investment}/links', [InvestmentController::class, 'storeLink'])->whereNumber('investment')->name('investments.links.store');
            Route::delete('/investments/{investment}/links/{link}', [InvestmentController::class, 'destroyLink'])->whereNumber('investment')->whereNumber('link')->name('investments.links.destroy');
            Route::post('/investments/{investment}/business-cases', [BusinessCaseController::class, 'store'])->whereNumber('investment')->name('business-cases.store');
            Route::put('/business-cases/{businessCase}', [BusinessCaseController::class, 'update'])->whereNumber('businessCase')->name('business-cases.update');
            Route::post('/business-cases/{businessCase}/submit', [BusinessCaseController::class, 'submit'])->whereNumber('businessCase')->name('business-cases.submit');
            Route::post('/investments/{investment}/benefits', [BenefitController::class, 'store'])->whereNumber('investment')->name('benefits.store');
            Route::put('/benefits/{benefit}', [BenefitController::class, 'update'])->whereNumber('benefit')->name('benefits.update');
            Route::post('/benefits/{benefit}/cancel', [BenefitController::class, 'cancel'])->whereNumber('benefit')->name('benefits.cancel');
            Route::post('/investments/{investment}/value-reviews', [ValueReviewController::class, 'store'])->whereNumber('investment')->name('value-reviews.store');
            Route::put('/value-reviews/{valueReview}', [ValueReviewController::class, 'update'])->whereNumber('valueReview')->name('value-reviews.update');
            Route::post('/investments/{investment}/changes', [InvestmentChangeController::class, 'store'])->whereNumber('investment')->name('investment-changes.store');
            Route::post('/investment-changes/{investmentChange}/withdraw', [InvestmentChangeController::class, 'withdraw'])->whereNumber('investmentChange')->name('investment-changes.withdraw');
            Route::post('/stage-gates/{stageGate}/withdraw', [StageGateController::class, 'withdraw'])->whereNumber('stageGate')->name('stage-gates.withdraw');
        });
        Route::middleware('permission:approve it-finance-investments')->group(function () {
            Route::post('/stage-gates/{stageGate}/approve', [StageGateController::class, 'approve'])->whereNumber('stageGate')->name('stage-gates.approve');
            Route::post('/stage-gates/{stageGate}/reject', [StageGateController::class, 'reject'])->whereNumber('stageGate')->name('stage-gates.reject');
        });

        // P6: cloud cost / FinOps, allocation and showback, capitalisation, application TCO (ATH-FIN-001 §3.3, §9)
        Route::get('/cloud/accounts', [CloudAccountController::class, 'index'])->name('cloud.accounts.index');
        Route::get('/cloud/costs', [CloudCostController::class, 'index'])->name('cloud.costs');
        Route::get('/cloud/anomalies', [CloudAnomalyController::class, 'index'])->name('cloud.anomalies.index');
        Route::get('/allocations', [AllocationController::class, 'index'])->name('allocations.index');
        Route::get('/monitor/showback', [ShowbackController::class, 'index'])->name('monitor.showback');
        Route::get('/monitor/tco', [TcoController::class, 'index'])->name('monitor.tco');
        Route::get('/monitor/tco/detail', [TcoController::class, 'detail'])->name('monitor.tco.detail');
        Route::get('/capitalisations', [CapitalisationController::class, 'index'])->name('capitalisations.index');
        Route::get('/capitalisations/{capitalisation}', [CapitalisationController::class, 'show'])->whereNumber('capitalisation')->name('capitalisations.show');
        Route::middleware('permission:export it-finance')->group(function () {
            Route::get('/monitor/showback/export', [ShowbackController::class, 'export'])->name('monitor.showback.export');
        });
        Route::middleware('permission:close it-finance-periods')->group(function () {
            Route::post('/chargeback-runs', [ChargebackController::class, 'store'])->name('chargeback.store');
            Route::get('/chargeback-runs/{chargebackRun}/download', [ChargebackController::class, 'download'])->whereNumber('chargebackRun')->name('chargeback.download');
        });
        Route::post('/chargeback-runs/{chargebackRun}/void', [ChargebackController::class, 'void'])->whereNumber('chargebackRun')->middleware('permission:close it-finance-periods')->name('chargeback.void');
        Route::middleware('permission:import it-finance')->group(function () {
            Route::post('/cloud/accounts', [CloudAccountController::class, 'store'])->name('cloud.accounts.store');
            Route::put('/cloud/accounts/{cloudAccount}', [CloudAccountController::class, 'update'])->whereNumber('cloudAccount')->name('cloud.accounts.update');
            Route::delete('/cloud/accounts/{cloudAccount}', [CloudAccountController::class, 'destroy'])->whereNumber('cloudAccount')->name('cloud.accounts.destroy');
            Route::post('/cloud/accounts/{cloudAccount}/reallocate', [CloudAccountController::class, 'reallocate'])->whereNumber('cloudAccount')->name('cloud.accounts.reallocate');
            Route::post('/cloud/import', [CloudImportController::class, 'store'])->name('cloud.import');
            Route::post('/cloud/tag-rules', [CloudTagRuleController::class, 'store'])->name('cloud.tag-rules.store');
            Route::put('/cloud/tag-rules/{cloudTagRule}', [CloudTagRuleController::class, 'update'])->whereNumber('cloudTagRule')->name('cloud.tag-rules.update');
            Route::delete('/cloud/tag-rules/{cloudTagRule}', [CloudTagRuleController::class, 'destroy'])->whereNumber('cloudTagRule')->name('cloud.tag-rules.destroy');
            Route::post('/cloud/untagged/assign', [CloudCostController::class, 'assignUntagged'])->name('cloud.untagged.assign');
        });
        Route::middleware('permission:edit it-finance')->group(function () {
            foreach (['acknowledge', 'resolve', 'dismiss'] as $action) {
                Route::post("/cloud/anomalies/{cloudAnomaly}/{$action}", [CloudAnomalyController::class, $action])->whereNumber('cloudAnomaly')->name("cloud.anomalies.{$action}");
            }
            Route::post('/budgets/lines/classify', [TcoController::class, 'classify'])->name('budgets.lines.classify');
            Route::post('/capitalisations', [CapitalisationController::class, 'store'])->name('capitalisations.store');
            Route::put('/capitalisations/{capitalisation}', [CapitalisationController::class, 'update'])->whereNumber('capitalisation')->name('capitalisations.update');
            foreach (['submit', 'withdraw', 'cancel'] as $action) {
                Route::post("/capitalisations/{capitalisation}/{$action}", [CapitalisationController::class, $action])->whereNumber('capitalisation')->name("capitalisations.{$action}");
            }
        });
        Route::middleware('permission:manage it-finance-settings')->group(function () {
            Route::post('/allocations', [AllocationController::class, 'store'])->name('allocations.store');
            Route::post('/allocations/run', [AllocationController::class, 'run'])->name('allocations.run');
            Route::put('/allocations/{allocation}', [AllocationController::class, 'update'])->whereNumber('allocation')->name('allocations.update');
            Route::post('/allocations/{allocation}/end', [AllocationController::class, 'end'])->whereNumber('allocation')->name('allocations.end');
            Route::delete('/allocations/{allocation}', [AllocationController::class, 'destroy'])->whereNumber('allocation')->name('allocations.destroy');
        });
        Route::middleware('permission:approve it-finance')->group(function () {
            Route::post('/capitalisations/{capitalisation}/approve', [CapitalisationController::class, 'approve'])->whereNumber('capitalisation')->name('capitalisations.approve');
            Route::post('/capitalisations/{capitalisation}/reject', [CapitalisationController::class, 'reject'])->whereNumber('capitalisation')->name('capitalisations.reject');
        });
    });

    /* ========================================================
     |  Atheris Platform Routes (Phases 0-8)
     |========================================================*/
    Route::middleware('permission:view platform')->group(function () {
        // Phase 4: Copilot
        Route::get('/copilot', [PlatformController::class, 'copilotIndex'])->name('copilot.index');
        Route::post('/copilot/send', [PlatformController::class, 'copilotSend'])->name('copilot.send');

        // Phase 4: Regulatory Intelligence
        Route::get('/regulatory-intel', [RegulatoryIntelligenceController::class, 'regIntelIndex'])->name('reg-intel.index');
        Route::get('/regulatory-intel/{circular}', [RegulatoryIntelligenceController::class, 'regIntelShow'])->name('reg-intel.show');
        Route::post('/regulatory-intel/{circular}/assess', [RegulatoryIntelligenceController::class, 'regIntelAssess'])
            ->middleware('permission:edit platform')->name('reg-intel.assess');
        Route::post('/regulatory-intel/{circular}/impacts', [RegulatoryIntelligenceController::class, 'regIntelImpactStore'])
            ->middleware('permission:edit platform')->name('reg-intel.impacts.store');
        Route::delete('/regulatory-intel/{circular}/impacts/{impact}', [RegulatoryIntelligenceController::class, 'regIntelImpactDestroy'])
            ->middleware('permission:edit platform')->name('reg-intel.impacts.destroy');
        Route::post('/regulatory-intel/{circular}/editorial', [RegulatoryIntelligenceController::class, 'regIntelEditorial'])
            ->middleware('permission:approve platform')->name('reg-intel.editorial');
        Route::get('/obligations', [PlatformController::class, 'obligationsIndex'])->name('obligations.index');

        // Phase 2: AUCS Browser
        Route::get('/aucs', [PlatformController::class, 'aucsIndex'])->name('aucs.index');

        // Phase 3: Evidence Vault (WORM)
        Route::get('/evidence-vault', [PlatformController::class, 'evidenceVaultIndex'])->name('evidence-vault.index');

        // Phase 4: TPRM

        // Phase 5: SIEM (signed webhook ingest is registered outside auth, below SCIM)
        Route::get('/siem', [SecurityOperationsController::class, 'siem'])->name('siem.index');
        Route::post('/siem/signals/{signal}/alert', [SecurityOperationsController::class, 'siemRaiseAlert'])
            ->middleware('permission:create security-alerts')->name('siem.signals.alert');
        Route::post('/siem/integrations/{integration}/secret', [SecurityOperationsController::class, 'siemRotateSecret'])
            ->middleware('permission:edit platform')->name('siem.integrations.secret');

        // Phase 5: Regulatory Notifications (draft → in review → approved → submitted)
        Route::get('/notifications', [SecurityOperationsController::class, 'notifications'])->name('notifications.index');
        Route::post('/notifications/draft', [SecurityOperationsController::class, 'notificationDraft'])
            ->middleware('permission:create incidents')->name('notifications.draft');
        Route::get('/notifications/{notification}', [SecurityOperationsController::class, 'notificationShow'])->name('notifications.show');
        Route::put('/notifications/{notification}', [SecurityOperationsController::class, 'notificationUpdate'])
            ->middleware('permission:edit incidents')->name('notifications.update');
        Route::post('/notifications/{notification}/transition', [SecurityOperationsController::class, 'notificationTransition'])
            ->middleware('permission:edit incidents')->name('notifications.transition');

        // Phase 5: FAIR
        Route::get('/fair', [PlatformController::class, 'fairIndex'])->name('fair.index');
        Route::post('/fair/{scenario}/run', [PlatformController::class, 'fairRun'])->name('fair.run')->middleware('permission:edit risks');
        Route::patch('/fair/{scenario}/link', [PlatformController::class, 'fairLink'])->name('fair.link')->middleware('permission:edit risks');

        // Phase 5: Vuln Prioritiser + Advisories
        Route::get('/vuln-prioritiser', [SecurityOperationsController::class, 'prioritiser'])->name('vuln-prioritiser.index');
        Route::get('/threat-advisories', [SecurityOperationsController::class, 'advisories'])->name('threat-advisories.index');

        // Phase 6: Doc Intelligence & Returns
        Route::get('/doc-intel', [RegulatoryIntelligenceController::class, 'docIntelIndex'])->name('doc-intel.index');
        Route::post('/doc-intel/upload', [RegulatoryIntelligenceController::class, 'docIntelUpload'])
            ->middleware('permission:create platform')->name('doc-intel.upload');
        Route::get('/doc-intel/{job}', [RegulatoryIntelligenceController::class, 'docIntelShow'])->name('doc-intel.show');
        Route::get('/doc-intel/{job}/download', [RegulatoryIntelligenceController::class, 'docIntelDownload'])->name('doc-intel.download');
        Route::patch('/doc-intel/{job}', [RegulatoryIntelligenceController::class, 'docIntelUpdate'])
            ->middleware('permission:edit platform')->name('doc-intel.update');
        Route::post('/doc-intel/{job}/accept', [RegulatoryIntelligenceController::class, 'docIntelAccept'])
            ->middleware('permission:edit platform')->name('doc-intel.accept');
        Route::post('/doc-intel/{job}/reject', [RegulatoryIntelligenceController::class, 'docIntelReject'])
            ->middleware('permission:edit platform')->name('doc-intel.reject');

        Route::get('/returns', [RegulatoryIntelligenceController::class, 'returnsIndex'])->name('returns.index');
        Route::post('/returns/generate', [RegulatoryIntelligenceController::class, 'returnsGenerate'])
            ->middleware('permission:create platform')->name('returns.generate');
        Route::get('/returns/{run}', [RegulatoryIntelligenceController::class, 'returnsShow'])->name('returns.show');
        Route::get('/returns/{run}/export', [RegulatoryIntelligenceController::class, 'returnsExport'])->name('returns.export');
        Route::post('/returns/{run}/override', [RegulatoryIntelligenceController::class, 'returnsOverride'])
            ->middleware('permission:edit platform')->name('returns.override');
        Route::post('/returns/{run}/refresh', [RegulatoryIntelligenceController::class, 'returnsRefresh'])
            ->middleware('permission:edit platform')->name('returns.refresh');
        Route::post('/returns/{run}/transition', [RegulatoryIntelligenceController::class, 'returnsTransition'])
            ->name('returns.transition');

        // Phase 7: Core Banking
        Route::get('/core-banking', [CoreBankingController::class, 'index'])->name('core-banking.index');
        Route::get('/core-banking/snapshots', [CoreBankingController::class, 'snapshots'])->name('core-banking.snapshots');
        Route::post('/core-banking', [CoreBankingController::class, 'store'])->middleware('permission:create platform')->name('core-banking.store');
        Route::get('/core-banking/{id}', [CoreBankingController::class, 'show'])->whereNumber('id')->name('core-banking.show');
        Route::put('/core-banking/{id}', [CoreBankingController::class, 'update'])->whereNumber('id')->middleware('permission:edit platform')->name('core-banking.update');
        Route::post('/core-banking/{id}/sync', [CoreBankingController::class, 'sync'])->whereNumber('id')->middleware('permission:edit platform')->name('core-banking.sync');

        // Phase 7: DR

        // Integrations Hub (per-category connector pages)
        Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
        Route::get('/integrations/{key}', [IntegrationsController::class, 'show'])->name('integrations.show');
        Route::post('/integrations/{key}/test', [IntegrationsController::class, 'testConnection'])->middleware('permission:edit platform')->name('integrations.test');
        Route::post('/integrations/{key}/save', [IntegrationsController::class, 'save'])->middleware('permission:edit platform')->name('integrations.save');
        Route::get('/integrations/{key}/sample.csv', [IntegrationsController::class, 'downloadSample'])->name('integrations.sample');

        // Phase 1: Identity & Access (SSO/SCIM/API)
        Route::get('/identity/sso', [IdentityController::class, 'sso'])->name('identity.sso');
        Route::post('/identity/sso', [IdentityController::class, 'ssoStore'])->middleware('permission:edit platform')->name('identity.sso.store');
        Route::put('/identity/sso/{id}', [IdentityController::class, 'ssoUpdate'])->whereNumber('id')->middleware('permission:edit platform')->name('identity.sso.update');
        Route::get('/identity/sso/{id}/metadata', [IdentityController::class, 'ssoMetadata'])->whereNumber('id')->middleware('permission:edit platform')->name('identity.sso.metadata');
        Route::get('/identity/scim', [IdentityController::class, 'scim'])->name('identity.scim');
        Route::post('/identity/scim', [IdentityController::class, 'scimStore'])->middleware('permission:edit platform')->name('identity.scim.store');
        Route::post('/identity/scim/{id}/revoke', [IdentityController::class, 'scimRevoke'])->whereNumber('id')->middleware('permission:edit platform')->name('identity.scim.revoke');
        Route::get('/identity/api', [IdentityController::class, 'api'])->name('identity.api');

        // Phase 8: Settings extras
        Route::get('/settings/theme', [PlatformController::class, 'settingsTheme'])->name('settings.theme');
        Route::put('/settings/theme', [PlatformController::class, 'settingsThemeUpdate'])->middleware('permission:edit platform')->name('settings.theme.update');
        Route::get('/settings/custom-fields', [PlatformController::class, 'settingsCustomFields'])->name('settings.custom-fields');
        Route::post('/settings/custom-fields', [PlatformController::class, 'settingsCustomFieldStore'])->middleware('permission:edit platform')->name('settings.custom-fields.store');
        Route::delete('/settings/custom-fields/{id}', [PlatformController::class, 'settingsCustomFieldDestroy'])->whereNumber('id')->middleware('permission:edit platform')->name('settings.custom-fields.destroy');
        Route::get('/settings/feature-flags', [PlatformController::class, 'settingsFeatureFlags'])->name('settings.feature-flags');
        Route::post('/settings/feature-flags/{flag}/toggle', [PlatformController::class, 'settingsFeatureFlagToggle'])
            ->whereNumber('flag')->middleware('permission:edit platform')->name('settings.feature-flags.toggle');
        Route::post('/settings/feature-flags/{flag}/toggle-global', [PlatformController::class, 'settingsFeatureFlagToggleGlobal'])
            ->whereNumber('flag')->middleware('permission:edit platform')->name('settings.feature-flags.toggle-global');
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
        // CBN EA maturity: five blueprint domains on the CMMI scale (ATH-VAL-015)
        Route::get('/cbn-maturity', [EaMaturityController::class, 'index'])->name('cbn-maturity');
        Route::post('/maturity', [EaMaturityController::class, 'store'])->middleware('permission:create ea')->name('maturity.store');
        Route::get('/maturity/{assessment}', [EaMaturityController::class, 'show'])->name('maturity.show');
        Route::post('/maturity/{assessment}/practices/{question}', [EaMaturityController::class, 'answer'])->middleware('permission:edit ea')->name('maturity.answer');
        Route::put('/maturity/{assessment}/targets', [EaMaturityController::class, 'targets'])->middleware('permission:edit ea')->name('maturity.targets');
        Route::post('/maturity/{assessment}/submit', [EaMaturityController::class, 'submit'])->middleware('permission:edit ea')->name('maturity.submit');
        Route::post('/maturity/{assessment}/approve', [EaMaturityController::class, 'approve'])->middleware('permission:approve ea')->name('maturity.approve');
        Route::post('/maturity/{assessment}/return', [EaMaturityController::class, 'returnToDraft'])->middleware('permission:approve ea')->name('maturity.return');
        Route::get('/security-assurance', [EaSecurityAssuranceController::class, 'index'])->name('security-assurance');

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
    Route::patch('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
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
// IT Finance calendar feed: outside auth by design; signed per-user URL (plan C55), tenant resolved from the signed user in the controller.
Route::get('/it-finance/calendar.ics', [FinanceCalendarController::class, 'ics'])
    ->middleware(['signed', 'throttle:30,1'])->name('finance.calendar.ics');

Route::prefix('ea/respond')->name('ea.portal.')->group(function () {
    Route::get('/{token}', [PortalController::class, 'respond'])->name('respond');
    Route::post('/{token}', [PortalController::class, 'submit'])->name('submit');
    Route::post('/{token}/decline', [PortalController::class, 'decline'])->name('decline');
});

require __DIR__.'/auth.php';
