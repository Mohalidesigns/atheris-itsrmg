import {
    ShieldCheckIcon,
    ChartBarIcon,
    ExclamationTriangleIcon,
    ClipboardDocumentCheckIcon,
    BugAntIcon,
    FireIcon,
    ServerStackIcon,
    BuildingOfficeIcon,
    DocumentTextIcon,
    ArrowPathIcon,
    CreditCardIcon,
    Cog6ToothIcon,
    HomeIcon,
    ShieldExclamationIcon,
    FingerPrintIcon,
    SparklesIcon,
    BookOpenIcon,
    GlobeAltIcon,
    CircleStackIcon,
    CpuChipIcon,
    BoltIcon,
    ChatBubbleLeftRightIcon,
    NewspaperIcon,
    PuzzlePieceIcon,
    CurrencyDollarIcon,
    BriefcaseIcon,
    ScaleIcon,
    BuildingLibraryIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';

/**
 * Navigation config.
 * `permission` on an item (or group) hides it from users lacking that
 * permission — see Sidebar.jsx. Items without `permission` are always shown.
 * A `permission` on a group applies to the whole group; children may
 * additionally declare their own `permission`.
 */
const navigation = [
    {
        name: 'Dashboard',
        href: 'dashboard',
        icon: HomeIcon,
    },
    {
        name: 'Atheris Copilot',
        href: 'copilot.index',
        icon: SparklesIcon,
        badge: 'AI',
        permission: 'view platform',
    },
    {
        name: 'IT Risk Management',
        icon: ExclamationTriangleIcon,
        children: [
            { name: 'Dashboard', href: 'risks.dashboard', permission: 'view risks' },
            { name: 'Risk Register', href: 'risks.index', permission: 'view risks' },
            { name: 'Risk Graph', href: 'risks.graph', permission: 'view risks' },
            { name: 'Risk Assessments', href: 'risk-assessments.index', permission: 'view risk-assessments' },
            { name: 'Risk Treatments', href: 'risk-treatments.index', permission: 'view risk-treatments' },
            { name: 'Threat Register', href: 'threats.index', permission: 'view threats' },
            { name: 'FAIR Quantification', href: 'fair.index', permission: 'view platform' },
            { name: 'Question Library', href: 'question-libraries.index', permission: 'view risks' },
        ],
    },
    {
        name: 'CBN Cyber Assessment',
        icon: FingerPrintIcon,
        permission: 'view csat',
        children: [
            { name: 'Assessments', href: 'csat.index' },
        ],
    },
    {
        name: 'Compliance',
        icon: ClipboardDocumentCheckIcon,
        children: [
            { name: 'Dashboard', href: 'compliance.dashboard', permission: 'view compliance-assessments' },
            { name: 'Control Library', href: 'controls.index', permission: 'view controls' },
            { name: 'AUCS Browser', href: 'aucs.index', permission: 'view platform' },
            { name: 'Regulatory Frameworks', href: 'frameworks.index', permission: 'view frameworks' },
            { name: 'Compliance Assessments', href: 'compliance-assessments.index', permission: 'view compliance-assessments' },
            { name: 'Evidence Repository', href: 'evidence.index', permission: 'view evidence' },
            { name: 'Evidence Vault (WORM)', href: 'evidence-vault.index', permission: 'view platform' },
            { name: 'Gap Analysis', href: 'gap-analysis.index', permission: 'view gap-analysis' },
            { name: 'Obligations Register', href: 'obligations.index', permission: 'view platform' },
        ],
    },
    {
        name: 'Regulatory Intelligence',
        icon: NewspaperIcon,
        permission: 'view platform',
        children: [
            { name: 'Circular Feed', href: 'reg-intel.index' },
            { name: 'Document Intelligence', href: 'doc-intel.index' },
            { name: 'Returns Centre', href: 'returns.index' },
            // ATH-EAR-002 §5.5 deliberately promotes this OUT of the EA
            // module: "Burying them inside EA hides the product's best feature
            // from the CISO who buys it." §1.4 — "Sell the regulatory
            // architecture return, and ship an EA repository as the machine
            // that produces it."
            { name: 'Architecture Returns', href: 'regulatory-returns.index', permission: 'view ea' },
        ],
    },
    {
        name: 'Security Operations',
        icon: ShieldExclamationIcon,
        children: [
            { name: 'Dashboard', href: 'security-ops.dashboard', permission: 'view vulnerabilities' },
            { name: 'Vulnerabilities', href: 'vulnerabilities.index', permission: 'view vulnerabilities' },
            { name: 'Vulnerability Prioritiser', href: 'vuln-prioritiser.index', permission: 'view platform' },
            { name: 'Threat Advisories', href: 'threat-advisories.index', permission: 'view platform' },
            { name: 'Vulnerability Tickets', href: 'vulnerability-tickets.index', permission: 'view vulnerability-tickets' },
            { name: 'Incidents', href: 'incidents.index', permission: 'view incidents' },
            { name: 'SIEM Integrations', href: 'siem.index', permission: 'view platform' },
            { name: 'Regulatory Notifications', href: 'notifications.index', permission: 'view platform' },
            { name: 'Security Alerts', href: 'security-alerts.index', permission: 'view security-alerts' },
            { name: 'Data Breaches', href: 'data-breaches.index', permission: 'view data-breaches' },
            { name: 'Response Procedures', href: 'response-procedures.index', permission: 'view incidents' },
        ],
    },
    {
        name: 'Issues & Remediation',
        icon: BoltIcon,
        children: [
            { name: 'Issues Console', href: 'issues.index', permission: 'view issues' },
            { name: 'SLA Policies', href: 'issues.sla-policies', permission: 'view issues' },
            { name: 'ITSM Integrations', href: 'itsm.index', permission: 'view platform' },
        ],
    },
    {
        name: 'Asset Management',
        icon: ServerStackIcon,
        children: [
            { name: 'IT Assets', href: 'assets.index', permission: 'view assets' },
            { name: 'Asset Discovery', href: 'asset-discovery.index', permission: 'view platform' },
            { name: 'Business Services', href: 'business-services.index', permission: 'view platform' },
            { name: 'Business Assets', href: 'business-assets.index', permission: 'view assets' },
        ],
    },
    {
        name: 'Vendor Management',
        icon: BriefcaseIcon,
        children: [
            { name: 'Vendor Register', href: 'vendors.index', permission: 'view vendors' },
            { name: 'Vendor Assessments', href: 'vendor-assessments.index', permission: 'view vendor-assessments' },
            { name: 'Security Ratings', href: 'security-ratings.index', permission: 'view platform' },
            { name: 'Shared Vendor Directory', href: 'shared-vendors.index', permission: 'view platform' },
        ],
    },
    {
        name: 'Policy Management',
        icon: DocumentTextIcon,
        children: [
            { name: 'Policies', href: 'policies.index', permission: 'view policies' },
            { name: 'Control Standards', href: 'control-standards.index', permission: 'view controls' },
            { name: 'Policy Attestations', href: 'policy-attestations.index', permission: 'view policy-attestations' },
            { name: 'Change Requests', href: 'change-requests.index', permission: 'view policies' },
            { name: 'Exceptions', href: 'policy-exceptions.index', permission: 'view policies' },
        ],
    },
    {
        name: 'ISMS',
        icon: ShieldCheckIcon,
        permission: 'view isms',
        children: [
            { name: 'ISMS Overview', href: 'isms.index' },
            { name: 'ISMS Risks', href: 'isms.risks' },
            { name: 'ISMS Controls', href: 'isms.controls' },
            { name: 'ISMS Audit', href: 'isms.audit' },
            { name: 'Statement of Applicability', href: 'isms.soa' },
            { name: 'ISO 27001 Gap Analysis', href: 'isms.gap-analysis' },
        ],
    },
    {
        name: 'PCI Management',
        icon: CreditCardIcon,
        permission: 'view pci',
        children: [
            { name: 'Dashboard', href: 'pci.dashboard' },
            { name: 'Cardholder Data Env', href: 'pci.cde' },
            { name: 'PCI Controls', href: 'pci.controls' },
            { name: 'Self-Assessment (SAQ)', href: 'pci.saq' },
            { name: 'Control Matrix', href: 'pci.matrix' },
            { name: 'Evidence Repository', href: 'pci.evidence' },
        ],
    },
    {
        name: 'Business Continuity',
        icon: ArrowPathIcon,
        children: [
            { name: 'BCP Plans', href: 'bcp.plans', permission: 'view bcp-plans' },
            { name: 'DR Plans', href: 'bcp.dr-plans', permission: 'view bcp-plans' },
            { name: 'DR Runbooks', href: 'dr.runbooks', permission: 'view platform' },
            { name: 'DR Exercises', href: 'dr.exercises', permission: 'view platform' },
            { name: 'Business Impact Analysis', href: 'bcp.bia', permission: 'view bcp-plans' },
            { name: 'Tests & Exercises', href: 'bcp.tests', permission: 'view bcp-plans' },
        ],
    },
    {
        name: 'Continuous Monitoring',
        icon: ChartBarIcon,
        children: [
            { name: 'Dashboard', href: 'monitoring.dashboard', permission: 'view monitoring' },
            { name: 'CCM Console', href: 'ccm.index', permission: 'view platform' },
            { name: 'Controls Monitoring', href: 'monitoring.controls', permission: 'view monitoring' },
            { name: 'Drift Detection', href: 'monitoring.drift', permission: 'view monitoring' },
            { name: 'Access Reviews', href: 'monitoring.access-reviews', permission: 'view monitoring' },
        ],
    },
    {
        name: 'KRIs & Dashboards',
        icon: ChartBarIcon,
        children: [
            { name: 'KRI Dashboard', href: 'kri.index', permission: 'view platform' },
            { name: 'Board Packs', href: 'board-packs.index', permission: 'view platform' },
            { name: 'Executive Dashboard', href: 'reports.executive', permission: 'view reports' },
        ],
    },
    {
        name: 'Workflow Studio',
        icon: PuzzlePieceIcon,
        permission: 'view platform',
        children: [
            { name: 'Workflows', href: 'workflows.index' },
            { name: 'Marketplace', href: 'workflows.marketplace' },
            { name: 'Active Instances', href: 'workflows.instances' },
        ],
    },
    {
        name: 'Core Banking',
        icon: BuildingLibraryIcon,
        permission: 'view platform',
        children: [
            { name: 'Integrations', href: 'core-banking.index' },
            { name: 'Snapshots', href: 'core-banking.snapshots' },
        ],
    },
    {
        name: 'Reports',
        icon: ChartBarIcon,
        permission: 'view reports',
        children: [
            { name: 'Executive Dashboard', href: 'reports.executive' },
            { name: 'Risk Reports', href: 'reports.risks' },
            { name: 'Compliance Reports', href: 'reports.compliance' },
            { name: 'Scheduled Reports', href: 'reports.scheduled' },
        ],
    },
    {
        name: 'Marketplace',
        icon: CurrencyDollarIcon,
        permission: 'view platform',
        children: [
            { name: 'Content Marketplace', href: 'marketplace.index' },
            { name: 'Installed Packs', href: 'marketplace.installs' },
            { name: 'Pricing', href: 'pricing.index' },
        ],
    },
    {
        name: 'Identity & Access',
        icon: UserGroupIcon,
        permission: 'view platform',
        children: [
            { name: 'SSO Connections', href: 'identity.sso' },
            { name: 'SCIM Tokens', href: 'identity.scim' },
            { name: 'API Portal', href: 'identity.api' },
        ],
    },
    {
        name: 'Integrations Hub',
        href: 'integrations.index',
        icon: PuzzlePieceIcon,
        permission: 'view platform',
    },
    {
        // ATH-EAR-002 §5.5 / Appendix C — 40 flat sibling links replaced by 8
        // workspaces organised by job-to-be-done rather than by metamodel
        // entity. Each entry lands on the workspace's primary surface; the peer
        // surfaces are rendered as tabs on the page itself, from the shared
        // definitions in Config/eaWorkspaces.js.
        name: 'Enterprise Architecture',
        icon: BuildingLibraryIcon,
        badge: 'EA',
        permission: 'view ea',
        children: [
            { name: 'Command Centre', href: 'ea.command-centre' },
            // Phase 1 (ATH-EAR-002 WS 1.1 / 1.6). "My Architecture" is the
            // personal work list that makes maintaining the repository
            // somebody's visible, bounded job rather than a policy nobody owns.
            { name: 'My Architecture', href: 'ea.stewardship.my-architecture' },
            { name: 'Residency & Localisation', href: 'ea.residency' },
            { name: 'FX Exposure', href: 'ea.fx-exposure' },
            { name: 'My Tasks', href: 'ea.portal.my-tasks' },
            { name: 'Business Architecture', href: 'ea.capabilities' },
            { name: 'Portfolio', href: 'ea.applications' },
            { name: 'Integration Architecture', href: 'ea.interfaces' },
            { name: 'Data & Privacy Architecture', href: 'ea.logical-entities' },
            { name: 'Security Architecture', href: 'ea.security-zones' },
            { name: 'Transformation', href: 'ea.plateaux' },
            { name: 'Architecture Governance', href: 'ea.principles' },
            // Utility surfaces: a canvas, an artefact generator and the admin
            // screen for inbound data. Not workspaces, but they need a way in.
            { name: 'Diagrams & Viewpoints', href: 'ea.diagrams' },
            { name: 'CBN Evidence Packs', href: 'ea.evidence-packs' },
            // Phase 4 (WS 4.4 / 4.5): the interchange gate and the repository
            // API. Both are utility surfaces — an architect visits them once to
            // prove a claim, not daily.
            { name: 'ArchiMate Round-Trip Gate', href: 'ea.round-trip' },
            { name: 'Repository API', href: 'ea.api' },
            { name: 'Data Sources', href: 'ea.data-sources' },
        ],
    },
    {
        name: 'Administration',
        icon: UserGroupIcon,
        children: [
            { name: 'User Management', href: 'admin.users.index', permission: 'view users' },
            { name: 'Roles & Permissions', href: 'admin.roles.index', permission: 'view roles' },
        ],
    },
    {
        name: 'Settings',
        icon: Cog6ToothIcon,
        children: [
            { name: 'Organization', href: 'settings.organization', permission: 'view organizations' },
            { name: 'Tenant Theme', href: 'settings.theme', permission: 'view platform' },
            { name: 'Custom Fields', href: 'settings.custom-fields', permission: 'view platform' },
            { name: 'Feature Flags', href: 'settings.feature-flags', permission: 'view platform' },
            { name: 'Audit Trail', href: 'settings.audit-trail', permission: 'view audit-trail' },
            { name: 'Notifications', href: 'settings.notifications' },
        ],
    },
];

export default navigation;
