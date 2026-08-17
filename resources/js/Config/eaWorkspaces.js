/**
 * EA workspace definitions — ATH-EAR-002 §5.5 and Appendix C.
 *
 * The module previously exposed 40 sibling sidebar links organised around
 * metamodel entities ("Logical Entities", "Motivation Layer"). Every Leader in
 * the 2025 Gartner MQ organises around jobs-to-be-done instead: Ardoq ships
 * three outcome suites, LeanIX three modules, Orbus four. Nobody exposes a
 * menu item called "Logical Entities".
 *
 * This is the single source of truth for the 8 workspaces. `navigation.js`
 * renders it in the sidebar and `EaWorkspaceTabs` renders the peer surfaces
 * inside a workspace, so the two cannot drift apart.
 */

export const EA_WORKSPACES = [
    {
        key: 'command-centre',
        name: 'Command Centre',
        blurb: 'Executive landing — KPIs, assurance and data quality.',
        tabs: [
            { name: 'Overview', href: 'ea.command-centre' },
            // Phase 1 (WS 1.1 / 1.2 / 1.3): the mechanics that keep the
            // repository true belong next to the executive view, because
            // "how much of this can I trust" is an executive question.
            // WS 2.5 (B5) — §3.6's open white space: no competitor ships an
            // architecture-completeness instrument.
            { name: 'Score Card', href: 'ea.score-card' },
            { name: 'Ownership & Freshness', href: 'ea.stewardship.ownership' },
            { name: 'Surveys', href: 'ea.surveys' },
            { name: 'CBN EA Maturity', href: 'ea.cbn-maturity' },
            { name: 'EA KRIs', href: 'ea.kri' },
            { name: 'Anomaly Inbox', href: 'ea.anomalies' },
            { name: 'Audit Trail', href: 'ea.audit-trail' },
        ],
    },
    {
        key: 'business',
        name: 'Business Architecture',
        blurb: 'Capabilities, value streams and processes — the BIZBOK triad.',
        tabs: [
            { name: 'Capability Map', href: 'ea.capabilities' },
            { name: 'Value Streams', href: 'ea.value-streams' },
            { name: 'Process Inventory', href: 'ea.processes' },
        ],
    },
    {
        key: 'portfolio',
        name: 'Portfolio',
        blurb: 'The APM flagship plus its technology and vendor lenses.',
        tabs: [
            { name: 'Application Portfolio', href: 'ea.applications' },
            { name: 'Technology Radar', href: 'ea.technology-radar' },
            // Phase 4 WS 4.7 (B17) — §11 makes the CFO the third buyer, and
            // this is the screen sold to them.
            { name: 'Cost & TCO', href: 'ea.cost-model' },
            // Phase 3 wedge lenses (§6.3 A3/A4/A5) — the axes §4 rows 39–48
            // score every global competitor at 0 or 1.
            { name: 'FX Exposure', href: 'ea.fx-exposure' },
            { name: 'Concentration', href: 'ea.concentration' },
            { name: 'Sites & Resilience', href: 'ea.sites' },
        ],
    },
    {
        key: 'integration',
        name: 'Integration Architecture',
        blurb: 'The CBN App. II §1.1(i)–(k) connection catalogue, and impact analysis over it.',
        tabs: [
            { name: 'Interfaces', href: 'ea.interfaces' },
            { name: 'API Register', href: 'ea.apis' },
            { name: 'Channels & Rails', href: 'ea.channels' },
            { name: 'Blast Radius', href: 'ea.blast-radius' },
            // Phase 4 WS 4.2 (B14) — §5.4 records n-hop impact as Orbus's
            // weakest rated feature, so it is worth being visibly better at.
            { name: 'Change Impact', href: 'ea.impact' },
        ],
    },
    {
        key: 'data',
        name: 'Data & Privacy Architecture',
        blurb: 'Know your data — entities, flows, domains, glossary and DPIA.',
        tabs: [
            { name: 'Logical Entities', href: 'ea.logical-entities' },
            { name: 'Data Flows', href: 'ea.data-flows' },
            { name: 'Information Domains', href: 'ea.information-domains' },
            { name: 'Business Glossary', href: 'ea.glossary' },
            { name: 'DPIA & Privacy', href: 'ea.dpia' },
            { name: 'Residency & Localisation', href: 'ea.residency' },
        ],
    },
    {
        key: 'security',
        name: 'Security Architecture',
        blurb: 'Zones, control mappings and threat models — the CISO’s three views of one graph.',
        tabs: [
            { name: 'Security Zones', href: 'ea.security-zones' },
            { name: 'Control Mappings', href: 'ea.control-mappings' },
            { name: 'Threat Models', href: 'ea.threats' },
        ],
    },
    {
        key: 'transformation',
        name: 'Transformation',
        blurb: 'Baseline → target plateaux, the initiatives that move between them, and ADM phases.',
        tabs: [
            { name: 'Plateaux', href: 'ea.plateaux' },
            // Phase 4 WS 4.3 (B15) — §5.1 deleted the old read-only Scenarios
            // page and asked for this in the Roadmap workspace instead.
            { name: 'Plateau Diff', href: 'ea.plateau-diff' },
            { name: 'Initiatives', href: 'ea.initiatives' },
            { name: 'Roadmap', href: 'ea.roadmap' },
            { name: 'ADM Tracker', href: 'ea.adm-tracker' },
            { name: 'Reference Patterns', href: 'ea.patterns' },
        ],
    },
    {
        key: 'governance',
        name: 'Architecture Governance',
        blurb: 'The governance loop: principle → standard → submission → decision → exception.',
        tabs: [
            { name: 'Principles', href: 'ea.principles' },
            { name: 'Standards', href: 'ea.standards' },
            { name: 'ARB', href: 'ea.arb' },
            { name: 'Decision Records', href: 'ea.decisions' },
            // Phase 4 WS 4.5 — an agent's proposal is a governance artefact, so
            // the review queue belongs beside ARB and decision records.
            { name: 'Change Proposals', href: 'ea.drafts' },
            { name: 'Exceptions', href: 'ea.exceptions' },
            { name: 'Motivation', href: 'ea.motivation' },
        ],
    },
];

/**
 * Surfaces that are reachable but do not belong in the primary IA:
 * diagrams (a canvas, not a catalogue), evidence packs (promoted to
 * Regulatory Returns in a later phase) and the Data Sources admin screen.
 */
export const EA_UTILITY = [
    { name: 'Diagrams & Viewpoints', href: 'ea.diagrams' },
    { name: 'ArchiMate Round-Trip Gate', href: 'ea.round-trip' },
    { name: 'Repository API', href: 'ea.api' },
    { name: 'CBN Evidence Packs', href: 'ea.evidence-packs' },
    { name: 'Legal Entities', href: 'ea.legal-entities' },
    { name: 'Data Sources', href: 'ea.data-sources' },
    { name: 'Seal Policy', href: 'ea.seals.policies' },
];

/** Find the workspace that owns a route name, for tab rendering. */
export function workspaceFor(routeName) {
    return EA_WORKSPACES.find((w) => w.tabs.some((t) => t.href === routeName));
}

/** Tabs to render on a page, given its own route name. */
export function tabsFor(routeName) {
    return workspaceFor(routeName)?.tabs || [];
}
