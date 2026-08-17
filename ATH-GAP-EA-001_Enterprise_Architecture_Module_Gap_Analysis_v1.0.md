# Enterprise Architecture (EA-Studio) Module — Gap Analysis & Fit-for-Purpose Benchmark

**Document ID:** ATH-GAP-EA-001
**Version:** 1.0
**Date:** 15 May 2026
**Product:** NexusRisk IRM — EA-Studio (`Modules/Ea`)
**Parent Implementation Plan:** ATH-PIP-EA-001 v1.0
**Owner:** Product & Engineering, Atheris Limited
**Audience:** Product Management, EA-Studio Engineering Team, Platform Architecture
**Classification:** Atheris Confidential

---

## 0. Reading Guide

This is a domain-by-domain technical gap analysis of the EA-Studio module against the leading global EA tools as positioned in the *2025 Gartner Magic Quadrant for Enterprise Architecture Tools* and against open-source and GRC-adjacent alternatives. It is written for the product and engineering team — not for executives — and is intended as backlog input, not a marketing piece.

The structure of the document is:

- §1 Executive snapshot — what state the module is in today and the one-line verdict per domain
- §2 Method, benchmark set and scoring rubric
- §3 Current-state inventory drawn directly from the codebase (migrations, controllers, pages, seeders)
- §4 Composite benchmark reference — the union of capabilities offered by the leaders
- §5 Domain-by-domain analysis — twelve domains, each with current state, benchmark, gap, severity and remediation
- §6 Cross-cutting engineering gaps that span all domains
- §7 Where NexusRisk should *not* try to reach parity (anti-goals)
- §8 Prioritised remediation roadmap (P0 / P1 / P2) and how it slots into the existing 18-sprint plan
- §9 Effort, team and dependency implications
- §10 Risks, assumptions and open decisions
- §A–§D Appendices: feature heatmap, ArchiMate conformance check, KRI catalogue, evidence pack contents

All claims about the current state are backed by file references in the form `app/Models/Ea/Capability.php`, `database/migrations/2026_11_01_000001_create_ea_phase1_tables.php`, etc. so every finding can be reproduced.

---

## 1. Executive Snapshot

### 1.1 One-line verdict

**The current EA-Studio module is a credible *read-only demo skin* over a well-designed metamodel — but it is not yet an EA tool.** It is roughly **30–35% feature-complete** against the global leader baseline and approximately **55–60% complete** against the Atheris implementation plan (`ATH-PIP-EA-001`). The most damaging finding is structural: there is **no write path through the application for any EA entity** — every page is render-only, every dataset is seeded, and the controller `app/Http/Controllers/EaController.php` (358 LOC) contains no `store/update/destroy` actions. Until the write surface, validation, and audit pipeline land, the module cannot be sold as an EA repository in the sense that LeanIX, Ardoq, MEGA or BiZZdesign use the term.

The module's *information model* is, however, very strong. The Phase 1/2/3 migrations cover ArchiMate's three core layers plus motivation and implementation, include a generic graph table (`ea_relationships`), and ship an evidence-pack and maturity scaffolding that competitor GRC suites do not. With six to nine engineering sprints of focused work, the module can move from "demo" to "fit for purpose at CBN-EA Level 3" and become a genuine differentiator against ServiceNow's APM and against pure-play EA tools that lack GRC integration.

### 1.2 Per-domain verdict (one line each)

| Domain | Verdict | Severity |
| :---- | :---- | :---- |
| EA-REP — Repository & Metamodel | Schema present, no write API, no relationship validation engine | **Critical** |
| EA-BA — Business Architecture | Capability tree renders; no editing, no heatmap overlays, no scoring | **High** |
| EA-APM — Application Portfolio | TIME grid + BFTF heatmap is the strongest area; lacks lifecycle automation and CMDB read-through | **Medium** |
| EA-TA — Technology Architecture | Radar table present; no proactive EOL/EOS alerting, no CVE feed | **High** |
| EA-DA — Data Architecture | Flows table present; no lineage, no DPIA workflow, no consent ties | **High** |
| EA-IA — Integration Architecture | Interfaces / APIs catalogued; blast radius limited to 1-hop, no impact propagation | **High** |
| EA-SEC — Security Architecture | Zones & control mappings table; no zero-trust pattern enforcement, no automatic control inheritance | **High** |
| EA-VIZ — Visualisation & Diagramming | Tree, gantt, kanban and React-Flow displays; no diagram authoring, no ArchiMate viewpoints | **Critical** |
| EA-MAT — Maturity & Evidence | CBN-EA self-assessment wired in; evidence pack generator is a stub | **Medium** |
| EA-GOV — Governance (Principles/Standards/ARB) | Models and read-only screens; no submission workflow, no principle-impact engine | **High** |
| EA-ROADMAP — Roadmap & ADM | Gantt + ADM tracker render; no plateau comparison, no scenario modelling | **High** |
| EA-AI — AI / Automation / Discovery | Effectively absent | **Critical for 2026 parity** |

### 1.3 Strategic recommendation in one paragraph

Stop building new read-only screens, treat Phase 1 as a stabilisation phase, and use sprints P1.S5 and P1.S6 of the existing plan to land four things: (1) a generic *write surface* — repositories, FormRequests, policies, audit hooks — for all existing tables; (2) an ArchiMate 3.2 relationship-validation engine on top of `ea_relationships`; (3) a real Asset/CMDB read-through (today's portfolio is seeded); and (4) a working ArchiMate Open Exchange round-trip so that the module is interoperable with Archi, Sparx EA and BiZZdesign. Everything else in Phase 2 and 3 — ARB workflow, evidence packs, KRI calculations, AI copilot — depends on these four foundations.

---

## 2. Method, Benchmark Set and Scoring Rubric

### 2.1 Benchmark set

Nine products were used as the reference set. The set was chosen to span the three viable comparison angles for NexusRisk: pure-play leaders, open-source/practitioner staples, and GRC-adjacent EA features.

**Gartner Magic Quadrant for Enterprise Architecture Tools (2025) — Leaders quadrant**

- **SAP LeanIX** — Leader for the fifth consecutive year; positioned furthest and highest for completeness of vision and ability to execute. SaaS, fact-sheet driven, deep AI integration through SAP Joule and an MCP server for LLM agents.
- **Ardoq** — Leader for the fifth consecutive year; graph-native architecture with a flexible metamodel that can be reshaped from the UI.
- **Orbus Software (OrbusInfinity / iServer365)** — Leader for the fourth consecutive year; deeply integrated with Microsoft 365 (Teams, SharePoint, Power BI, Power Automate), supports TOGAF, BPMN, ArchiMate, COBIT.
- **BiZZdesign Horizzon** — Leader; multi-language modelling (ArchiMate, BPMN, UML, C4, ERD, DMN) in one repository, strong simulation and AI portfolio governance.

**Challengers / Visionaries with relevance to the Atheris value proposition**

- **MEGA HOPEX** — Challenger; an integrated EA + GRC platform. The single most direct competitor to NexusRisk-with-EA, because it is the only well-known suite that ships both disciplines in one product.
- **Avolution ABACUS** — Visionary; strong analytics and simulation engine, 3D visualisations, comparison of future-state scenarios with side-by-side architecture metrics.

**Open-source / practitioner standards**

- **Archi** (open-source ArchiMate tool, ~6,000 downloads/month) — the de-facto baseline for ArchiMate 3.2 conformance and the most likely *file format* that a customer architect will arrive carrying.
- **Sparx Enterprise Architect (Unified Edition + Pro Cloud Server)** — UML/BPMN/ArchiMate/TOGAF/Zachman, BPSim execution engine, rich REST API; the most common existing tool inside Nigerian banks and government ministries.

**GRC-suite EA**

- **ServiceNow Enterprise Architecture** (formerly APM) — renamed in the Xanadu release; the most relevant comparator for a pilot bank evaluating "buy ServiceNow EA + GRC vs. NexusRisk".

### 2.2 Scoring rubric

Each capability in the reference model is scored on a 0–4 ordinal scale:

- **0 — Not present** — no schema, no UI, no API, not on the roadmap
- **1 — Stubbed** — schema or screen exists but is render-only, demo data, or never persists
- **2 — Functional** — works end-to-end for the happy path but lacks validation, scale, or polish
- **3 — Production-grade** — validation, RBAC, audit, performance budget, contract tests
- **4 — Differentiated** — production-grade *plus* a feature the benchmark set does not have (e.g. CBN evidence pack, regulator-facing posture)

A composite "fit-for-purpose" score is computed per domain as the unweighted mean of all capabilities in that domain. The leader-set baseline is taken as 3.0 (i.e. all leaders are *production-grade* across all capabilities they offer, by definition of being in the Leaders quadrant).

### 2.3 What "fit for purpose" means in this context

NexusRisk EA-Studio does **not** need to win the Gartner Magic Quadrant. It needs to:

1. Satisfy CBN's Risk-Based Cybersecurity Framework expectations for Enterprise Architecture (currently expressed via the CBN-CSAT EA-MAT domain in this module).
2. Out-perform ServiceNow EA on **GRC-EA integration depth** — i.e. capability ↔ control ↔ obligation traceability — which is the buying angle for Nigerian DMBs, PSPs and NMFBs.
3. Reach **functional parity (level 2) on ArchiMate 3.2 and TOGAF ADM artefacts** so that a CIO, CISO or Head of Architecture can run the bank's architecture practice end-to-end inside NexusRisk without exporting to a second tool.
4. Match Archi and Sparx EA on **file-format interoperability** (ArchiMate Open Exchange XML, CSV import/export) so customers can bring their existing models in and out without lock-in.

A domain-level score of 2.5 or higher meets this bar. A 3.0 or higher means the module is materially competitive against ServiceNow EA on the specific axis the customer cares about. Anything below 2.0 means a feature is *advertised* (it has a page in the side-nav) but not *deliverable* — and that is the worst possible state for a pilot.

---

## 3. Current-State Inventory (drawn from the codebase)

This section is a factual inventory of what exists in `Modules/Ea` today. Every line in this section can be reproduced by inspecting the named file.

### 3.1 Persistence layer

The module ships three migrations under `database/migrations/`:

- `2026_11_01_000001_create_ea_phase1_tables.php` — fifteen tables: `ea_capabilities`, `ea_value_streams`, `ea_applications_ext`, `ea_tech_components_ext`, `ea_standards`, `ea_info_domains`, `ea_logical_entities`, `ea_data_flows`, `ea_relationships`, `ea_audit_log`, `ea_maturity_domains`, `ea_maturity_questions`, `ea_maturity_assessments`, `ea_maturity_responses`, `ea_principles`.
- `2026_11_01_000002_create_ea_phase2_tables.php` — nine tables: `ea_interfaces`, `ea_apis`, `ea_zones`, `ea_zone_assignments`, `ea_control_mappings`, `ea_processes`, `ea_arb_submissions`, `ea_exceptions`, `ea_vendor_concentration`.
- `2026_11_01_000003_create_ea_phase3_tables.php` — eight tables: `ea_plateaux`, `ea_initiatives`, `ea_patterns`, `ea_solutions`, `ea_kri_definitions`, `ea_kri_values`, `ea_exchange_jobs`, `ea_evidence_packs`.

That is 32 tables of EA-specific persistence — a strong start. Tenancy is consistently modelled via a nullable `tenant_id` on every table, ArchiMate-style codes are reserved (`code`, `name`, `level`, `parent_id`), and the polymorphic graph table `ea_relationships` carries `source_type / source_id / target_type / target_id / relation_type / attrs`.

Notable schema strengths:

- Generic relationship table is the right design choice for a graph metamodel — it matches Ardoq's approach.
- `ea_audit_log` exists with append-only semantics (uses `useCurrent()` instead of full timestamps).
- Maturity tables (`ea_maturity_*`) generalise to any maturity framework, not just CBN-EA.
- KRI tables separate definitions from values and carry thresholds (green/amber/red) with direction.

Notable schema weaknesses:

- `capability_ids`, `application_ids`, `linked_capabilities`, `linked_applications` are stored as JSON arrays rather than via the proper many-to-many in `ea_relationships`. This duplicates the source of truth and forces `whereJsonContains` queries (see `EaController::applicationShow`), which cannot use an index.
- `ea_audit_log.before` / `after` are nullable JSON without any indexer (no `entity_id` index for trace lookups under load).
- No foreign keys on `tenant_id` anywhere — a global tenant scope is implied but not enforced at the database level.
- No `version` or `is_current` columns on capabilities, applications or interfaces — versioning of architecture states (current / target / transition plateau) is implicit and depends on the `ea_plateaux` reference, but no migration adds a `plateau_id` to `ea_capabilities` or `ea_applications_ext`.
- `ea_principles`, `ea_standards`, `ea_patterns` are tenant-scoped — they should arguably be platform-scoped with tenant-override semantics, since the value of reference content is that it is shared.

### 3.2 Domain model layer (`app/Models/Ea/`)

Thirty-one Eloquent models, each thin and following the pattern below:

```php
class Capability extends Model {
    protected $table = 'ea_capabilities';
    protected $guarded = [];
    protected $casts = ['last_verified_at' => 'date'];
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function parent()   { return $this->belongsTo(self::class, 'parent_id'); }
}
```

Observations:

- Models use `$guarded = []` everywhere — mass assignment is open. There are no FormRequests on the controller side, so this is a latent security gap, not just a stylistic one.
- Relationships are present only where strictly needed for the current read-only screens (e.g. `Capability::children/parent`, `LogicalEntity::domain`, `EaInterface::sourceApp/targetApp`).
- There is no `MorphTo` traversal through `ea_relationships` — i.e. the relationship table exists, but the Eloquent layer does not expose `Capability::relationships()` or `EaApplication::serves()`. All graph traversal today is hand-rolled in the controller.
- No global tenant scope is registered. The `tenant_id` column is on the table, but `app/Models/Ea/Capability.php` does not call `addGlobalScope` and no `App\Models\Concerns\TenantScope` trait is mixed in. This is the single most important security gap.

### 3.3 Controller layer

There is exactly one EA controller: `app/Http/Controllers/EaController.php`, 358 lines, containing 33 public methods (31 read, 2 stub writes). Each method:

1. Loads one or more models with eager-loaded relations (`Capability::orderBy(...)->get()`).
2. Optionally derives aggregates with `groupBy`/`count`/`pluck`.
3. Returns an Inertia render with a `compact(...)` payload.

There are **zero write actions** for any EA entity except two that exist purely to keep the demo alive:

- `EaController::exchangeQueue` — creates a fake `ExchangeJob` row marked `completed` with a synthetic file path; no XML is produced.
- `EaController::evidencePackGenerate` — creates a fake `EvidencePack` row with synthetic PDF/ZIP paths; no document is produced.

There are no FormRequests. There are no policies (`app/Policies/Ea/`). There are no service classes. There are no repositories. The plan in `ATH-PIP-EA-001 §P1.S2` specifies a `CapabilityService` + `CapabilityRepository` — none of these exist in the codebase yet.

### 3.4 Routing

The EA route group is registered in `routes/web.php` (lines 372–414):

- 11 GET routes for Phase 1 read-only screens
- 11 GET routes for Phase 2 read-only screens
- 9 GET routes for Phase 3 read-only screens
- 2 POST routes that fire-and-forget the fake exchange / evidence pack generators

There are **no API routes** (`routes/api.php`). The plan in `ATH-PIP-EA-001 §P1.S2.WP-002` requires `/api/v1/ea/capabilities` — this does not exist. There is **no OpenAPI specification** for EA. Without external endpoints, every other module (Asset Management, Policy Management, Workflow Studio, Reports) has no way to consume EA data programmatically.

### 3.5 Presentation layer (`resources/js/Pages/Ea/`)

Thirty-one Inertia/React pages, each between 60 and 250 lines, all following the same shape:

```jsx
export default function CapabilityMap({ tree = [], byParent = {}, counts = {} }) {
    return (<AuthenticatedLayout header="Capability Map">
        <PageHeader breadcrumbs={[...]} title="..." subtitle="..." />
        <KpiCards />
        <NodeOrTableOrGrid />
    </AuthenticatedLayout>);
}
```

The pages are well-styled, consistent with the AEGIS design tokens, and use only three diagramming idioms:

- **Indented tree** (`CapabilityMap.jsx`, `Processes.jsx`) — hand-rolled recursive JSX, no virtualisation.
- **Heat grid / matrix** (`ApplicationPortfolio.jsx` business-fit × technical-fit cells, `TechnologyRadar.jsx` adopt/trial/assess/hold quadrants).
- **Kanban / pipeline** (`Arb.jsx`).
- **Custom React-Flow canvas** (`BlastRadius.jsx` via `@/Components/AtherisFlow`) — but only used in one place.

There is no diagram authoring (drag a Business Process onto a canvas and link it to an Application Component), no ArchiMate viewpoint rendering (no Layered, Implementation & Migration, Goal Realisation views), and no diagram persistence (saved diagrams aren't a concept — every render is computed live from data).

### 3.6 Seeded reference data

Three seeders (`EaPhase1Seeder` 525 LOC, `EaPhase2Seeder` 311 LOC, `EaPhase3Seeder` 257 LOC) populate the entire module from scratch for the First Bank pilot. The data is realistic enough for demo purposes (BIAN-inspired top-level capabilities, named applications, technology radar entries with EOL dates, NDPA classification on logical entities). But: **all KPIs in the Command Centre are computed off seeded data**. There is no live ingestion.

### 3.7 Tests

There are no PHPUnit tests for the EA module under `tests/Feature` or `tests/Unit`. The Playwright spec suite (`tests/Playwright/specs/`) does not include an `ea-*.spec.js` file. The Phase Implementation Plan's Definition of Done (`ATH-PIP-EA-001 §2.2`) requires 80% service/repo coverage, 60% controller, 70% React — actual coverage for EA is 0%.

### 3.8 Diagramming dependency

`package.json` includes `reactflow@^11.11.4` and `recharts@^3.8.1`. There is no graph layout engine beyond what React-Flow provides natively. There is no `d3-force`, `cytoscape`, `vis-network`, `mxgraph` or `gojs`. The plan in `ATH-PIP-EA-001 §P1.S3.WP-001` requires a 2,000-node tree-map at ≤ 2.5s render — there is no virtualisation in `CapabilityMap.jsx` and the page will hard-fall over above ~500 nodes.

### 3.9 Summary

The module ships:

- 32 tables (production-shape)
- 31 models (thin, no relationships through the graph)
- 1 controller, 358 LOC, 31 read methods, 2 stub write methods
- 31 React pages (read-only)
- 31 GET routes + 2 stub POST routes
- 0 API endpoints, 0 OpenAPI spec
- 0 tests (Feature, Unit, Playwright)
- 3 seeders that populate everything

Against the Phase 1 plan (which scopes EA-REP, EA-BA, EA-APM, EA-TA, EA-DA partial, EA-VIZ command centre, EA-MAT baseline, RBAC, identity integration, CI/CD), the module is at:

- **Schema:** ≈ 90% of Phase 1 + 80% of Phase 2 + 75% of Phase 3 schema is present (ahead of schedule).
- **UI:** ≈ 100% of read screens for all three phases are present (ahead of schedule).
- **Persistence path (writes):** 0% of Phase 1 + 0% of Phase 2 + 0% of Phase 3 actually persists.
- **APIs:** 0% of Phase 1 (`/api/v1/ea/capabilities` is the only Phase 1 API and it's missing).
- **Integrations:** 0% (Asset Management read-through, Identity org-units, Policy domain reads).
- **Tests:** 0%.

This is the classic *"demo-ahead, plumbing-behind"* shape. It's a fixable shape, but only if the next two sprints are spent on plumbing rather than on new screens.

---

## 4. Benchmark Reference Model (Composite of Leaders)

Composite minimum capability set, derived from the published feature pages of the nine reference products. Capabilities are grouped by EA-Studio domain to make the per-domain gap analysis in §5 cleaner.

### 4.1 Repository & Metamodel (EA-REP)

A modern EA tool must offer:

- A **persisted, queryable metamodel** — not hard-coded element types in source.
- **Customisable metamodel** — add/rename/extend element types and relationship types from the UI without redeploying (Ardoq's headline feature; "all plans include unlimited users, full metamodel flexibility").
- **ArchiMate 3.2 conformance** — the 56 element types and 11 relationship types defined in the specification, with the relationship-derivation rules enforced.
- **Generic graph storage** — Ardoq's graph-native repository; LeanIX's fact-sheet + relations; BiZZdesign's multi-language coexistence (ArchiMate + BPMN + UML in one repository).
- **Versioning of architecture states** — current / target / transition (TOGAF Plateau / BiZZdesign baseline-vs-target / ABACUS scenario comparison).
- **Full audit history per element** with diff view (who changed what when).
- **Bulk import / export** — Excel, CSV, ArchiMate Open Exchange XML.
- **A REST API** that exposes the metamodel and all instances (Sparx Pro Cloud Server's RESTful API is a baseline expectation).

### 4.2 Business Architecture (EA-BA)

- **Business capability map**, multi-level, with **reference models** out of the box: BIAN for banking, APQC, Industry Reference Architectures.
- **Heatmaps overlaying any metric** (cost, maturity, risk, application count, redundancy, criticality).
- **Value streams** with stages, participants, supporting capabilities — and the ability to *link* value-stream stages to applications, processes, KPIs.
- **Strategy linkage** — goals, drivers, principles, OKRs — and propagation of strategic priority down to capabilities, applications and initiatives.
- **Business motivation model** (ArchiMate Motivation Layer): stakeholders, drivers, assessments, goals, outcomes, courses of action.
- **Customer journey** and process inventory at L1/L2/L3 with BPMN-2.0 diagrams.

### 4.3 Application Portfolio Management (EA-APM)

- **Single application inventory**, ingested by automatic discovery from CMDB / asset / cloud accounts (ServiceNow's "centralized inventory of business applications").
- **Scoring frameworks** — TIME, BFTF, 6R (Rehost/Replatform/Repurchase/Refactor/Retire/Retain), application indicators (cost, risk, alignment, business value, functional fit per ServiceNow).
- **Lifecycle management** — plan/build/live/sunset/retired with workflow transitions.
- **Rationalisation heatmap** — BFTF grid as a *first step*, but also overlaid with cost, user count, risk exposure.
- **Application-to-capability mapping** — a many-to-many that supports "Which applications support 'Customer Onboarding'?"
- **TCO / cost-roll-up** from underlying tech components and vendor contracts.
- **Out-of-the-box SaaS catalogue** (LeanIX ships a 6,500-entry SaaS catalogue).

### 4.4 Technology Architecture (EA-TA)

- **Technology radar** (adopt/trial/assess/hold) and **technology category** taxonomy (LeanIX Tech Category Catalog).
- **Lifecycle catalogue** with **automatic EOL/EOS tracking** against a third-party feed (LeanIX Lifecycle Catalog is the gold standard; ServiceNow's Technology Portfolio Management does this with automatic risk calculation).
- **CVE / vulnerability feed integration** — link tech components to known CVEs.
- **Reference architectures** and **technology standards** (preferred / restricted / banned) with exception lifecycle.
- **Component-to-application mapping** with TCO roll-up.

### 4.5 Data Architecture (EA-DA)

- **Information domains** and **logical entities** with classification (the module already has these).
- **Data lineage** — entity-to-entity flow with source-system, transform-step, target-system traceability.
- **NDPA / GDPR / privacy classification** with DPIA workflow and **cross-border flagging** with automatic DPO notification.
- **Consent and purpose limitation** ties (linking data flows to declared processing purposes).
- **Glossary and business definitions** — a business-glossary surface so non-technical stakeholders can read the model.

### 4.6 Integration Architecture (EA-IA)

- **Interface and API inventory** with provider/consumer roles.
- **N-hop blast radius** — not just direct neighbours. Ardoq's graph-native traversal is the benchmark.
- **Change impact propagation** — given a proposed change to component X, surface the chain of affected business services, processes and obligations.
- **API contract storage** — OpenAPI / AsyncAPI / WSDL versions.
- **Pattern-to-instance traceability** — which integrations follow which reference pattern.

### 4.7 Security Architecture (EA-SEC)

- **Security zone model** with explicit trust levels (the module has this).
- **Application-to-zone assignment** with proposed-vs-approved workflow.
- **Control-to-component mapping** — every application, interface, tech component has a control posture per framework (ISO 27001, PCI-DSS, CBN, NDPA).
- **Inheritance** — controls inherited from the zone, the underlying platform, or the vendor SOC 2 report.
- **Zero-trust patterns** and **deny-by-default policies** modelled as standards, with exception lifecycle.
- **Threat modelling integration** — STRIDE / LINDDUN per logical entity / per interface.

### 4.8 Visualisation & Diagramming (EA-VIZ)

- **Diagram authoring** — drag elements onto a canvas, connect them, save the diagram. (Archi's primary purpose.)
- **ArchiMate viewpoints** — Layered, Goal Realisation, Implementation & Deployment, Information Structure, Service Realisation, etc. The Open Group specification lists ~20 standard viewpoints; LeanIX, BiZZdesign and Orbus all ship at least the first dozen out of the box.
- **Auto-layout** for large graphs (hierarchical, force-directed, radial, grid).
- **3D visualisation** (ABACUS 5.1 ships this; differentiator but not table-stakes).
- **Live diagrams driven by data** — Ardoq's "dynamic visualisations from structured inputs instead of static diagrams" is the modern paradigm; static diagrams are anti-patterns.
- **Diagram export** — PNG, SVG, PDF, PPTX, ArchiMate Open Exchange XML.

### 4.9 Maturity, Evidence and Compliance (EA-MAT)

- **Self-assessment** against a maturity framework (CBN-EA, TOGAF MM, EAMM) — the module has this.
- **Evidence capture** — link maturity answers to documents, screenshots, exports.
- **Evidence pack generation** — PDF + ZIP with attestation signatures.
- **Multi-framework support** — same answers feed CBN-EA + COBIT + ISO 27001 + ISO 38500.
- **Maturity trend over time** — annual / quarterly snapshots with deltas.

### 4.10 Governance (EA-GOV)

- **Architecture principles** library with rationale, implications, status.
- **Standards catalogue** with adopt/trial/assess/hold.
- **ARB submission workflow** — submit, in-review, decision, signed minutes, audit trail.
- **Automatic principle-impact analysis** — given an ARB submission, surface which principles it touches and which exceptions it requires.
- **Exception lifecycle** — request, approval, expiry, renewal with reminders 30/60/90 days before expiry.
- **Vendor concentration** — auto-computed from application portfolio, not seeded with random numbers (`EaController::vendorConcentration` uses `$apps->random(...)` and `rand(5_000_000, 500_000_000)`).

### 4.11 Roadmap, Plateaux and ADM (EA-ROADMAP)

- **Plateaux** — current / target / transition states with valid-from/valid-to dates.
- **Initiatives** linked to plateaux, capabilities, applications, risks, obligations.
- **Gantt + dependency view** — multiple lanes, swim by ADM phase, programme, organisation unit.
- **Scenario modelling** — compare two target plateaux side-by-side (BiZZdesign and ABACUS do this; Avolution's "compare future-state scenarios with side-by-side architecture metrics" is the canonical example).
- **TOGAF ADM tracker** — preliminary, Phase A through Phase H, plus requirements management as a cross-cutting phase. Each ADM phase has named deliverables (Architecture Vision, Architecture Definition Document, etc.).

### 4.12 AI, Automation and Discovery (EA-AI)

- **Automatic discovery** of applications from CMDB / cloud accounts / SSO logs. LeanIX, ServiceNow EA, and Orbus all offer this; it is now table-stakes.
- **AI copilot / natural-language search** (LeanIX Joule, BiZZdesign AI Suite, Manarch as an AI-native alternative).
- **AI-assisted documentation** (Inventory Builder in LeanIX — "leverages AI to efficiently analyse documents, diagrams, images").
- **MCP server** for connecting external LLM agents to the EA repository (LeanIX shipped this in early 2026).
- **Automatic anomaly detection** — flag applications with no owner, capabilities with no application, interfaces with no API contract, EOL components within 12 months.
- **Recommendation engine** — pattern suggestions, rationalisation candidates, tech standard nudges.

---

## 5. Domain-by-Domain Gap Analysis

Format for each domain: **(a) capabilities expected from the benchmark set**, **(b) current state in EA-Studio**, **(c) gap statement**, **(d) severity**, **(e) remediation outline**, **(f) score 0–4 today, target score, time-to-target**.

### 5.1 EA-REP — Repository & Metamodel

**Benchmark expectation.** Persisted metamodel, customisable from the UI, ArchiMate 3.2 conformance, generic graph, versioning, audit, bulk import/export, REST API.

**Current state.**

- Generic graph table `ea_relationships` exists with `source_type / source_id / target_type / target_id / relation_type / attrs` — a strong foundation.
- ArchiMate 3.2 *relationship-permitted matrix* — referenced in `ATH-PIP-EA-001 §P1.S1.WP-004` as `RelationshipValidator` but **not present in the codebase**.
- `ea_audit_log` table exists; no controller actually writes to it. There is no `AuditLogger` service. Searches for `AuditLogger`, `App\Services\Ea` return empty.
- Bulk import via CSV — referenced in `ATH-PIP-EA-001 §P1.S2.WP-006` — not implemented. There is no `Imports/` directory under `app/Imports`.
- ArchiMate Open Exchange round-trip — `ea_exchange_jobs` table + `Exchange.jsx` UI exist, but `EaController::exchangeQueue` is a stub that creates a job row and immediately marks it `completed` without producing any XML.
- REST API — does not exist.
- Versioning of architecture states — `ea_plateaux` exists, but no FK from `ea_capabilities` or `ea_applications_ext` to plateau, so the model cannot represent "this application is part of the 2027 target plateau but not the current".
- Custom metamodel — element types are hard-coded as Eloquent models; cannot be extended from the UI.

**Gap statement.** The repository is **architecturally well-designed but operationally inert**. The schema looks like Ardoq's, but none of the validation, write-path, audit-write, or interop machinery sits on top of it. ArchiMate conformance cannot be claimed today.

**Severity.** Critical. This is the foundation every other domain stands on.

**Remediation.**

1. **Build a `RelationshipValidator` service** seeded from the ArchiMate 3.2 permitted-relationship matrix (published by The Open Group; ~600 rows for the 56×56×11 element-element-relationship cube). Enforce at the repository layer when writes are introduced.
2. **Implement `AuditLogger` and a `WritesAuditLog` trait** that every EA repository method calls. Capture actor, action, entity_type, entity_id, before, after.
3. **Add `BulkImporter` services** with CSV templates per element type (capability, application, tech-component, info-domain, logical-entity, interface, principle, standard).
4. **Implement the ArchiMate Open Exchange XML serializer and parser** — round-trip the entire `ea_*` table set into `ArchiMate3.0_Exchange_v3.2.xsd` and back. The Open Group publishes the XSD; the work is mechanical.
5. **Expose `/api/v1/ea/*` REST endpoints** for all 32 tables. Auto-generate OpenAPI from Laravel attributes (e.g. `dedoc/scramble` is a Composer package that does this with zero code).
6. **Add `plateau_id` and `version_no` to `ea_capabilities`, `ea_applications_ext`, `ea_tech_components_ext`, `ea_interfaces`** so element-level versioning is possible.

**Score today: 1 (stubbed). Target: 3 (production-grade). Time-to-target: 4 sprints (8 weeks).**

### 5.2 EA-BA — Business Architecture

**Benchmark expectation.** Capability map with reference models (BIAN), heatmap overlays, value streams linked to capabilities, business motivation model, customer journeys, process inventory with BPMN.

**Current state.**

- `Capability` model + `ea_capabilities` table support 5 levels and BIAN tagging.
- `EaController::capabilityMap` renders an indented tree of capabilities; `EaController::capabilityShow` shows linked applications via `whereJsonContains('capability_ids', $capability->id)`.
- `BIAN Service Landscape v12 importer` — referenced in `ATH-PIP-EA-001 §P1.S2.WP-003` — partially present in `EaPhase1Seeder` (eight top-level BIAN capabilities + sub-capabilities) but not a real importer; you cannot replay v12 against a fresh tenant.
- Value streams have `stages` (JSON), `participants` (JSON), `linked_capabilities` (JSON), but only a list screen — no editor, no stage-to-application linkage.
- Strategy linkage — `ATH-PIP-EA-001 §P1.S3.WP-006` notes "data model only, full UI in P2"; no goals / drivers tables exist yet. Motivation layer is absent.
- No heatmap *overlay* — the capability map page has no toggle for criticality vs application-count vs cost vs risk. It just colour-codes by `criticality`.
- Process inventory — `ea_processes` table exists with parent/level/RTO/RPO, and a read screen renders L1/L2/L3 lists, but there is no BPMN canvas.

**Gap statement.** The capability map is present in skeleton form. The deeper BA discipline — strategy alignment, motivation layer, value-stream-to-process-to-application chain — is not.

**Severity.** High. Without strategy linkage, the rest of the module cannot answer "which initiatives serve which strategic goals".

**Remediation.**

1. Add `ea_goals`, `ea_drivers`, `ea_stakeholders`, `ea_outcomes`, `ea_courses_of_action` (ArchiMate Motivation Layer).
2. Implement a true **BIAN v12 importer** as a console command (`php artisan ea:import-bian`) reading the BIAN-published JSON release.
3. Add **heatmap overlay metrics** to `CapabilityMap.jsx`: criticality, application count, average maturity, total annual cost, risk exposure, EOL component count.
4. Convert `capability_ids` JSON columns to proper many-to-many via the `ea_relationships` table (`capability_realisesByApplication`).
5. Build a **value-stream editor** with drag-and-drop stages and per-stage application linkage (the data model already supports it).
6. Defer BPMN-canvas process modelling to Phase 3 — it is a 4-sprint effort on its own and is not on the critical path for CBN-EA Level 3.

**Score today: 1.5. Target: 2.5. Time-to-target: 3 sprints.**

### 5.3 EA-APM — Application Portfolio Management

**Benchmark expectation.** Single inventory ingested from CMDB, TIME / BFTF / 6R scoring, lifecycle workflow, rationalisation heatmap with cost/user/risk overlays, capability mapping, TCO roll-up.

**Current state.**

- `ea_applications_ext` carries `time_score`, `business_fit`, `technical_fit`, `criticality`, `lifecycle`, `annual_cost_ngn`, `user_count`, `capability_ids`.
- `ApplicationPortfolio.jsx` renders both the TIME counts as KPIs and the 5×5 BFTF grid with colour-coded quadrants — this is the best-built screen in the module.
- `ApplicationShow.jsx` lists linked capabilities, tech components, interfaces and zone — the best detail view in the module.
- Asset Management read-through — `ATH-PIP-EA-001 §P1.S4.WP-001` requires `AssetApplicationClient` with circuit breaker and Redis cache. **Not present.** Applications are seeded.
- Lifecycle workflow — `lifecycle` is a string column. There is no Workflow Studio integration to gate transitions.
- TCO roll-up from tech components / vendors — not implemented.

**Gap statement.** The *display* of APM is the strongest part of the module. The *data ingestion* and *workflow* underneath are missing. Without live CMDB / asset feed, this is a static spreadsheet with nice colours.

**Severity.** Medium for Phase 1 (the seeded display is good enough for the pilot), High for production.

**Remediation.**

1. Implement `AssetApplicationClient` with read-through + 5-minute Redis TTL + event-driven invalidation as specified in the plan.
2. Bind the application lifecycle to Workflow Studio so `live → sunset → retired` becomes a governed transition with approver + evidence.
3. Add `6R` scoring alongside TIME (rehost, replatform, repurchase, refactor, retire, retain).
4. Add an annual cost roll-up: `Sum(tech_component.annual_cost) + Sum(vendor_contract.annual_cost) where mapped to application`.
5. Add cost/user/risk overlays on the BFTF grid — colour by cost instead of by quadrant.
6. Ship an out-of-the-box **NIBSS / CBN-classified application taxonomy** for Nigerian banks (this is the LeanIX SaaS-Catalog equivalent and a fast differentiator).

**Score today: 2. Target: 3. Time-to-target: 3 sprints.**

### 5.4 EA-TA — Technology Architecture

**Benchmark expectation.** Tech radar, lifecycle catalogue with auto-tracked EOL/EOS, CVE feed, reference architectures, technology standards, TCO roll-up.

**Current state.**

- `ea_tech_components_ext` has `radar_status`, `eol_date`, `eos_date`, `obsolescence_flag`, `application_ids` (JSON).
- `TechnologyRadar.jsx` renders the four quadrants with EOL badge when within 12 months — visually competent.
- `CommandCentre.jsx` includes a "Next-12-month EOL list" panel — visible to executives.
- No third-party EOL/EOS feed integration. Dates are hand-entered into the seeder.
- No CVE feed.
- No automatic *risk score* per tech component — the `obsolescence_flag` is set by a seeder, not derived from `eol_date <= today + 12 months`.

**Gap statement.** Display is fine; **calculation and ingestion are absent**. The closest competitor (ServiceNow Technology Portfolio Management) automates the EOL risk calculation; NexusRisk does not.

**Severity.** High. Tech obsolescence is the single most common audit finding from CBN examiners.

**Remediation.**

1. Add a daily scheduled job `App\Jobs\Ea\RecomputeTechObsolescence` that flips `obsolescence_flag` based on `eol_date` and writes an audit-log entry.
2. Integrate **endoflife.date** (free, MIT-licensed JSON feed at `https://endoflife.date/api/`) for major products. Roughly 200 products covered out of the box.
3. Integrate the **NVD CVE feed** (`https://services.nvd.nist.gov/rest/json/cves/2.0`) for tech components that carry a CPE identifier.
4. Compute a per-component **technical-debt score** combining EOL distance, CVE count, and unsupported-version flag. Surface it on the radar and roll it up to applications.
5. Standards table: enforce `radar_status` semantics — a tech component flagged `hold` should auto-create an exception request when an application instantiates it.

**Score today: 1.5. Target: 3. Time-to-target: 2 sprints.**

### 5.5 EA-DA — Data Architecture

**Benchmark expectation.** Information domains, logical entities, classification, lineage, NDPA / privacy classification with DPIA, cross-border flagging, glossary.

**Current state.**

- `ea_info_domains`, `ea_logical_entities` (with `classification`, `pii_flag`, `attributes` JSON), `ea_data_flows` (with `cross_border`, `protocol`, `classification`) — schema is in place.
- `DataFlows.jsx` and `LogicalEntities.jsx` render lists with classification badges.
- Cross-border counter is on the dashboard.
- DPIA workflow — not implemented.
- Data lineage at the field level — not implemented (only entity-to-entity flows).
- Business glossary — not present.
- No NDPA-specific outputs (Data Subject Rights register, controller/processor schedule, DPIA template).

**Gap statement.** The data inventory is present and NDPA-classifiable. **The privacy *workflow* — DPIA, consent register, DPO notifications, NDPA Article 32 evidence — is not.**

**Severity.** High. NDPA enforcement (NDPC fines) is the second most common GRC trigger for Nigerian banks behind CBN. EA-Studio is the natural home for the data inventory that underlies the privacy programme.

**Remediation.**

1. Add `ea_dpia_assessments` and `ea_consent_purposes` tables and tie data flows to declared purposes.
2. When a `data_flow` with `cross_border = true` is created, fire an event subscribed to by Notifications so the DPO is alerted.
3. Build a one-screen DPIA wizard (10 questions per NDPA Schedule) producing a downloadable PDF.
4. Add `ea_glossary_terms` and surface a Glossary screen for non-technical stakeholders.
5. Tie logical entities to the NDPC categories (Personal / Sensitive Personal / Special Categories) explicitly.

**Score today: 2. Target: 3. Time-to-target: 3 sprints.**

### 5.6 EA-IA — Integration Architecture

**Benchmark expectation.** Interface + API inventory, n-hop blast radius with propagation, API contract storage, pattern-to-instance traceability.

**Current state.**

- `ea_interfaces` (source app, target app, protocol, pattern, classification, pii_carrying, status) and `ea_apis` (interface_id, base_url, version, auth_method).
- `BlastRadius.jsx` shows a 1-hop radial graph via React-Flow — visually fine, but `EaController::blastRadius` literally does 1 hop and stops.
- No N-hop traversal.
- No API contract files stored — just URL strings.
- No upstream/downstream impact propagation.

**Gap statement.** The interface inventory is *catalogued*. The thing that customers actually want — *"if I change Application X, what breaks?"* — is not built.

**Severity.** High. This is the headline use-case for an EA tool in a bank ("what happens if Finacle goes down?") and the natural sibling of BCM / RTO-RPO analysis.

**Remediation.**

1. Implement a **graph-traversal service** on top of `ea_relationships` + `ea_interfaces`. Use a depth-limited BFS (cap at 4 hops with budget gating) and return the affected set grouped by element type.
2. Persist API contract files (OpenAPI / WSDL / AsyncAPI) on `ea_apis`; render the spec in the API detail screen using Stoplight Elements (CDN-hosted, no build step).
3. Add **interface → reference-pattern** linkage so that "all batch-file transfers must follow Pattern P-INT-007" can be asserted and exceptions tracked.
4. Add a **change-impact tab** to the application detail screen that reuses the blast-radius service and groups affected items by criticality.

**Score today: 1.5. Target: 3. Time-to-target: 3 sprints.**

### 5.7 EA-SEC — Security Architecture

**Benchmark expectation.** Security zones with trust levels, application-to-zone assignment, control-to-component mapping with inheritance, zero-trust patterns, threat modelling integration.

**Current state.**

- `ea_zones` (trust_level 1–5) and `ea_zone_assignments` — good.
- `ea_control_mappings` (`component_type / component_id / control_id / framework / coverage`) — the right shape for ISO/PCI/CBN/NDPA mapping.
- `ControlMappings.jsx` and `SecurityZones.jsx` render the lists.
- No inheritance: an application in a zone does not automatically inherit the zone's controls. Each control mapping is a separate row.
- No zero-trust modelling.
- No STRIDE / LINDDUN threat models per interface or per entity.

**Gap statement.** The security architecture has a *control-mapping table* but does not have a *control-inheritance engine*. Coverage gaps (controls missing for a critical app) are not surfaced.

**Severity.** High. The whole point of NexusRisk's positioning is that EA-SEC and the GRC controls module are joined up; today they are joined by a table but not by logic.

**Remediation.**

1. Implement a **control inheritance graph**: `application → zone → platform → vendor SOC 2`. Render the resolved control set per application, with provenance.
2. Add a **coverage report**: for each application, "which CBN-RBC framework controls are unmapped, partially-mapped, or rated 'gap'". This is the single most useful CBN-prep output.
3. Add the **threat-modelling tables** (`ea_threat_models`, `ea_threat_techniques` mapped to MITRE ATT&CK) and wire them to interfaces and logical entities.
4. Add **zero-trust standards** to the seeded standards catalogue (microsegmentation, identity-aware proxy, mTLS-everywhere) and assert them as `must` against new interfaces.

**Score today: 2. Target: 3. Time-to-target: 3 sprints.**

### 5.8 EA-VIZ — Visualisation & Diagramming

**Benchmark expectation.** Diagram authoring, ArchiMate viewpoint rendering (~20 standard viewpoints), auto-layout for large graphs, live data-driven diagrams, diagram export to PNG/SVG/PDF/PPTX/ArchiMate-XML.

**Current state.**

- Three diagram idioms in use: indented tree, heat grid, kanban (cf. §3.5).
- One React-Flow canvas, used once, for 1-hop blast radius.
- No diagram authoring (no drag-elements-onto-canvas).
- No viewpoints. None of the ArchiMate standard viewpoints (Layered, Information Structure, Goal Realisation, Implementation & Deployment) are rendered.
- No auto-layout beyond what React-Flow ships.
- No export to PNG/PDF/PPTX. The roadmap page advertises a "Export PPTX" button that is a no-op (`Roadmap.jsx` line ~32 — the button has no onClick handler that does anything).

**Gap statement.** **This is the largest visible gap** because diagrams are what users *expect* an EA tool to be. A pilot bank evaluator who is used to Archi or Sparx will be unimpressed within five minutes if they cannot draw a Layered View.

**Severity.** Critical for perception even if the underlying data is fine.

**Remediation.**

1. **Adopt React-Flow as the authoring canvas** (already in dependencies). Build a generic `<EaDiagramEditor metamodel={…} elements={…} relationships={…} />` component that supports drag/connect/save with the ArchiMate icon set.
2. Persist diagrams in a new table `ea_diagrams` with `viewpoint`, `elements_json`, `edges_json`, `version`, `tenant_id`.
3. Ship **eight ArchiMate viewpoints out of the box**, auto-generated from data: Layered, Information Structure, Application Co-operation, Application Usage, Technology, Implementation & Deployment, Goal Realisation, Risk & Security. (The other ~12 can come later.)
4. Add **dagre** for hierarchical auto-layout and **d3-force** for force-directed — both <50 KB minified.
5. Add **export to PNG (html-to-image), SVG (React-Flow native), PDF (jsPDF), PPTX (pptxgenjs), and ArchiMate XML** (the same serializer built for EA-REP §5.1).
6. Defer 3D visualisation (ABACUS-style) until Phase 3 — it is a differentiator, not table-stakes.

**Score today: 1. Target: 3. Time-to-target: 4 sprints (this is the longest single piece of work).**

### 5.9 EA-MAT — Maturity, Evidence and Compliance

**Benchmark expectation.** Self-assessment against a maturity framework, evidence capture, evidence pack generation with attestation signatures, multi-framework support, trend over time.

**Current state.**

- `ea_maturity_domains / questions / assessments / responses` — schema present, seeded with the CBN-EA framework via `EaPhase1Seeder::seedMaturity`.
- `CbnMaturity.jsx` renders the by-domain progress and average levels.
- `ea_evidence_packs` table exists.
- `EaController::evidencePackGenerate` is a stub — it inserts a row with synthetic PDF and ZIP paths. No actual document is produced.
- No multi-framework support (CBN-EA only).
- No trend chart over time.

**Gap statement.** The maturity self-assessment is the **closest thing to fit-for-purpose** in the entire module. The evidence pack — which is the customer-facing artefact for a CBN examiner — is fake.

**Severity.** Medium for general use, *Critical for the CBN-CSAT pitch* (because this is the headline output of the EA module in regulator conversations).

**Remediation.**

1. Build a real **PDF evidence-pack generator** using `dompdf` or `barryvdh/laravel-snappy`. Template: cover page, executive summary with overall score, by-domain breakdown with answered/skipped/level distribution, evidence index, attestation page with digital signatures.
2. Build a real **ZIP exporter** that bundles the PDF + all linked evidence files (PDFs, screenshots, exported reports) from `evidence_refs`.
3. Add **multi-framework**: import COBIT 2019, ISO 38500, EAMM alongside CBN-EA, and let an organisation answer once and feed multiple frameworks (using a question-to-framework cross-reference table).
4. Add a **trend view**: year-over-year and quarter-over-quarter for each domain, with sparkline.
5. Add **digital-signature ceremony**: the maturity owner and the head-of-architecture sign the pack inside the app (DocuSign / native) before it is finalised.

**Score today: 2. Target: 3.5 (this is a differentiator vs. pure-play EA tools). Time-to-target: 3 sprints.**

### 5.10 EA-GOV — Governance (Principles / Standards / ARB / Exceptions)

**Benchmark expectation.** Architecture principles, standards catalogue, ARB submission workflow with audit and digital signatures, principle-impact analysis, exception lifecycle with renewal alerts.

**Current state.**

- `ea_principles`, `ea_standards`, `ea_arb_submissions`, `ea_exceptions` — schema present.
- `Principles.jsx`, `Standards.jsx`, `Arb.jsx` (kanban view), `Exceptions.jsx` — read screens.
- ARB submission has fields for `impact_blast_radius`, `impacted_principles`, `impacted_standards`, `digital_signature` — but no workflow to populate them.
- No "principle impact analyser": create an ARB submission, and the system should auto-derive which principles are touched. Today it doesn't.
- Exceptions have `effective_from`, `expires_at`, `status` — but no scheduled job to flip status to `expired` on expiry, no 30/60/90-day renewal reminder.

**Gap statement.** Governance is **fully scaffolded and entirely empty.** Every screen is a placeholder waiting for write actions.

**Severity.** High. ARB is the *workflow* an Atheris pilot bank will most expect to run inside the tool.

**Remediation.**

1. Build a real **ARB submission wizard**: subject, summary, attached deliverables, blast-radius auto-computed (depending on §5.6), principle / standard impact auto-derived from links, principle-by-principle assessment matrix.
2. Build an **ARB decision flow**: review → vote → decision → minutes → digital signature → audit-log entry. Implement minimally with the existing Workflow Studio module.
3. Add a scheduled job `App\Jobs\Ea\ExpireExceptions` and three notification windows (30/60/90 days).
4. Add a **principle-impact engine** that maps changes to ArchiMate elements onto principles via a `principle_applies_to_element_type` table.
5. Fix `vendor_concentration` — today `EaController::vendorConcentration` uses `Vendor::random()` and `rand(5_000_000, 500_000_000)`. Replace with a real query joining `vendors`, `vendor_assessments` and `ea_applications_ext` (vendor_id link to be added).

**Score today: 1. Target: 3. Time-to-target: 4 sprints.**

### 5.11 EA-ROADMAP — Roadmap, Plateaux, Initiatives, ADM

**Benchmark expectation.** Plateaux (current/target/transition), initiatives linked to plateaux/capabilities/applications/risks/obligations, Gantt + dependency view, scenario modelling, TOGAF ADM tracker.

**Current state.**

- `ea_plateaux`, `ea_initiatives` (with `adm_phase`, `linked_capabilities`, `linked_applications`, `linked_risks`, `linked_obligations`, `progress_percent`) — schema is very strong.
- `Roadmap.jsx` renders an 18-month Gantt — works for the demo.
- `AdmTracker.jsx` groups initiatives by ADM phase.
- `Plateaux.jsx`, `Initiatives.jsx` — list views.
- No scenario modelling (no way to compare two `target` plateaux side-by-side).
- No dependency graph between initiatives.
- No automatic propagation: changes in `linked_applications` should be reflected in roadmap card; today the JSON column is freezed at seed time.

**Gap statement.** Roadmap is *visually competent*, *data-completely-absent*. The most visible upgrade is dependency edges and scenario comparison, both of which the leaders ship out of the box.

**Severity.** High.

**Remediation.**

1. Add **dependency edges** between initiatives (`ea_initiative_dependencies` table) and render them as arrows on the Gantt.
2. Build a **scenario-comparison view**: pick two `target` plateaux, diff capabilities/applications/tech-components/costs.
3. Build a **TOGAF ADM deliverables tracker**: each initiative has a checklist of named deliverables per ADM phase (Architecture Vision document, Architecture Definition Document, Implementation & Migration Plan, etc.).
4. Add **risk-aware roadmap colouring**: initiatives with `linked_risks` over a threshold render in red.
5. Wire the "Export PPTX" button to a real PPTX generator (use `pptxgenjs` in the browser).

**Score today: 2. Target: 3. Time-to-target: 3 sprints.**

### 5.12 EA-AI — AI, Automation and Discovery

**Benchmark expectation.** Automated discovery from CMDB / cloud / SSO, AI copilot / natural-language search, AI-assisted document inventory, MCP server for external agents, anomaly detection, recommendation engine.

**Current state.**

- **None of the above exists.** There is no LLM integration anywhere in `app/Services/Ea`, no discovery jobs, no anomaly rules, no copilot.
- The wider platform may already have an LLM gateway elsewhere — needs confirmation from the platform team — but EA does not use it.

**Gap statement.** **Entirely absent.** This is the area where leaders are sprinting hardest (LeanIX shipped MCP support in early 2026; Joule copilot is now mainstream; Manarch is AI-native from the ground up; BiZZdesign and Orbus have AI suites). NexusRisk has no AI surface in EA at all.

**Severity.** Critical for 2026 parity narrative, but Medium for fit-for-purpose at CBN-EA Level 3 (none of the AI features are required for CBN compliance).

**Remediation.**

1. **Anomaly rules first**, AI second. Build five rule-based anomalies that ship in v1.0:
   - Applications with no owner
   - Capabilities with no application
   - Interfaces with `status='active'` whose source or target application is `retired`
   - Tech components with `eol_date` within 12 months and no replacement initiative
   - Logical entities with `pii_flag=true` whose owning info-domain has no DPO assigned
2. Add a **natural-language search** over the EA graph using the platform's existing LLM gateway. Query: "show me critical applications with no DR plan in the last 12 months" → SQL.
3. Add an **MCP server endpoint** following Anthropic's MCP spec so external Claude / GPT agents can query the EA repo. (LeanIX took this position in March 2026 and Atheris should match it.)
4. Add a **document-ingestion job** that reads an uploaded architecture deck (PPTX/Visio/PDF), extracts named entities, and pre-populates draft application / capability records.
5. Defer auto-discovery from cloud accounts to Phase 3+. It is high-value but high-effort.

**Score today: 0. Target: 2 (functional). Time-to-target: 3 sprints (anomaly rules are quick; copilot + MCP require platform-team coordination).**

---

## 6. Cross-Cutting Engineering Gaps

These gaps don't belong to a single domain — they affect every domain.

### 6.1 No service / repository layer

`app/Services/Ea/` and `app/Repositories/Ea/` do not exist. All logic lives in `EaController`. The implementation plan (`ATH-PIP-EA-001 §P1.S2.WP-001`) explicitly calls for `CapabilityService` + `CapabilityRepository` and treats this as the gatekeeper of writes, audit, and tenant scoping. None of it has been built.

**Remediation:** create the service+repository pair for every Phase 1 entity in P1.S5; for Phase 2 entities in P2.S5; for Phase 3 entities in P3.S5.

### 6.2 No tenant scoping enforcement

`tenant_id` exists as a column on every EA table. No model uses Laravel's `addGlobalScope` to filter by `auth()->user()->organization_id`. A user from tenant A could, today, query tenant B's data via any endpoint that bypasses the controller's hand-rolled filter (there are none currently, but as soon as APIs are added this becomes an immediate security incident).

**Remediation:** introduce a `BelongsToTenant` trait and apply it to every EA model. Add a cross-tenant integration test that asserts 404 on cross-read.

### 6.3 No FormRequests, no policies

No `app/Http/Requests/Ea/*.php`. No `app/Policies/Ea/*.php`. The Phase 1 plan requires RBAC (`ATH-PIP-EA-001 §P1.2`) — not built.

**Remediation:** define eight policies covering the Phase 1 entities (capability, value-stream, application-ext, tech-component, info-domain, logical-entity, data-flow, maturity-assessment). Bind to the existing `spatie/permission` role set.

### 6.4 No queued background jobs

`obsolescence_flag` recomputation, exception expiry, evidence-pack generation, KRI value computation, CMDB sync, OpenSearch indexing, ArchiMate exchange — all of these should be scheduled or queued. Today none of them are. There is no `App\Jobs\Ea\*` namespace.

**Remediation:** add Horizon + Redis queues; introduce the seven jobs listed.

### 6.5 No search index

`ATH-PIP-EA-001 §P1.S1` references OpenSearch indexing. There is no `Searchable` trait, no `Laravel Scout` driver, no `App\Search\Ea`. As soon as the capability count exceeds ~1,500 (BIAN v12 alone) the indented tree page will be unusable without search.

**Remediation:** add Laravel Scout + Meilisearch (lighter than OpenSearch for this scale), index five entity types, expose `/api/v1/ea/search?q=…`.

### 6.6 No tests at any level

No PHPUnit Feature/Unit tests. No Pact contract tests. No Playwright spec. Coverage = 0%.

**Remediation:** the Definition of Done in the plan requires coverage; honour it. Backfill at minimum: 60 PHPUnit Feature tests (one per endpoint), 12 Pact consumer tests, 6 Playwright happy-path specs.

### 6.7 No observability

No structured logs, no traces, no metrics, no error budget. The Command Centre page does not emit a "kpis.refresh" metric anywhere.

**Remediation:** add OpenTelemetry instrumentation through the existing platform middleware; surface NFR-P (P50/P95 latency) on each EA endpoint.

### 6.8 No feature flags

The plan (`ATH-PIP-EA-001 §2.3`) requires every capability to ship behind `ea.features.<name>` flags. The codebase contains the platform feature-flags screen (`PlatformController::settingsFeatureFlags`) but the EA module does not gate any of its routes by `Feature::isActive('ea.…')`. Every route is live.

**Remediation:** wrap the EA route group with a flag guard and seed twenty granular flags (`ea.capabilities`, `ea.tech-radar`, `ea.arb`, `ea.evidence-pack`, etc.).

### 6.9 N+1 queries and JSON-column anti-patterns

`capability_ids`, `application_ids`, `linked_capabilities`, `linked_applications`, `linked_risks`, `linked_obligations` are JSON arrays. Every lookup uses `whereJsonContains` — unindexed. At 5,000 applications the BFTF grid in `EaController::applicationPortfolio` will be O(n²) (25 nested gets inside the 5×5 grid loop).

**Remediation:** convert JSON arrays to proper joins through `ea_relationships`, add covering indexes, paginate the BFTF grid backend-side.

### 6.10 No design-system primitives for EA

There is no `<ArchiMateElement type="application-component">` component, no `<ViewpointFrame name="layered">`, no `<ElementCard>`. Every page open-codes its own table / card / KPI. The AEGIS tokens are applied per page rather than via shared primitives.

**Remediation:** introduce a `resources/js/Components/Ea/` set: `ArchiMateElement`, `ArchiMateEdge`, `ViewpointFrame`, `ElementCard`, `RelationshipBadge`, `LayerSwimlane`. Refactor pages to use them.

---

## 7. Anti-Goals — Where Not to Chase Parity

There are areas where competing against the leaders is wasteful. Stay out of these:

- **Full open metamodel UI** (Ardoq's headline) — it is a five-engineer-year build and not what banks ask for; ship a *seeded* ArchiMate 3.2 metamodel and a hard-coded extension story instead.
- **Multi-language modelling (BPMN + UML + C4 + ERD + DMN in one repo)** — BiZZdesign's strength but five-tools-in-one is incoherent for the GRC buyer. Ship ArchiMate well; defer BPMN to Phase 3 minimum.
- **3D visualisations** (Avolution ABACUS) — visually striking, low operational value; revisit in 2027 if customer demand emerges.
- **6,500-entry SaaS catalogue** (LeanIX) — instead, ship a *Nigerian-banking-tech-catalogue* with 200 curated entries (Finacle, Flexcube, BankWORLD, eFASS, NIBSS endpoints, MTN/Glo/Airtel APIs, etc.) that is more relevant to the buyer.
- **Native desktop client** (Sparx EA, Archi) — stay web-only.
- **Generic process simulation** (Sparx BPSim) — heavyweight; not in scope.
- **Generic GRC modules competing with HOPEX GRC** — Atheris already has these elsewhere in the suite; EA-Studio should integrate, not duplicate.

---

## 8. Prioritised Remediation Roadmap

Effort below is in *engineering-weeks for a team of three Backend + two Frontend + one Data/Graph + one QA* (the team sized in `ATH-PIP-EA-001 §3`). Sprint references follow the parent plan's `P{phase}.S{sprint}` notation.

### 8.1 P0 — Must land before Phase 1 exit (sprints P1.S5 → P1.S6 + spillover into P2.S1)

These are the items that turn the module from a demo into a usable repository.

| # | Item | Domain | Effort (eng-wks) | Slot |
| :---- | :---- | :---- | :---- | :---- |
| P0-1 | Write surface — services, repositories, FormRequests, policies for all Phase 1 entities | Cross-cut | 6 | P1.S5 |
| P0-2 | `RelationshipValidator` for ArchiMate 3.2 permitted matrix | EA-REP | 2 | P1.S5 |
| P0-3 | `AuditLogger` + `WritesAuditLog` trait + tests | Cross-cut | 1.5 | P1.S5 |
| P0-4 | Tenant global scope + cross-tenant test | Cross-cut | 1 | P1.S5 |
| P0-5 | Asset Management read-through (CMDB) + Redis cache | EA-APM | 3 | P1.S6 |
| P0-6 | Feature flag gating + RBAC bind-up | Cross-cut | 1 | P1.S5 |
| P0-7 | `BulkImporter` services + 8 CSV templates | EA-REP | 2 | P1.S6 |
| P0-8 | ArchiMate Open Exchange round-trip (real XML) | EA-REP | 3 | P1.S6 → P2.S1 |
| P0-9 | Phase 1 PHPUnit Feature tests (60 endpoints) | Cross-cut | 3 | P1.S5–S6 |
| P0-10 | Phase 1 Playwright happy-path specs (6 flows) | Cross-cut | 1.5 | P1.S6 |

**P0 total effort: ≈ 24 engineering-weeks.** Available in two sprints with the planned team: ≈ 24 engineering-weeks. **Fits.**

### 8.2 P1 — Should land during Phase 2 (sprints P2.S1 → P2.S6)

| # | Item | Domain | Effort | Slot |
| :---- | :---- | :---- | :---- | :---- |
| P1-1 | Diagram authoring canvas + 8 ArchiMate viewpoints | EA-VIZ | 8 | P2.S1–S3 |
| P1-2 | N-hop blast radius + change-impact propagation | EA-IA | 3 | P2.S2 |
| P1-3 | ARB submission wizard + decision workflow | EA-GOV | 4 | P2.S3–S4 |
| P1-4 | Exception lifecycle + 30/60/90 reminders | EA-GOV | 1 | P2.S3 |
| P1-5 | Control inheritance engine | EA-SEC | 3 | P2.S4 |
| P1-6 | Tech radar EOL/EOS auto-flag job + endoflife.date integration | EA-TA | 2 | P2.S2 |
| P1-7 | CVE feed integration (NVD) | EA-TA | 2 | P2.S4 |
| P1-8 | Heatmap overlays for capability map | EA-BA | 1.5 | P2.S2 |
| P1-9 | Motivation layer (goals/drivers/stakeholders/outcomes) | EA-BA | 2 | P2.S5 |
| P1-10 | DPIA workflow + cross-border DPO notification | EA-DA | 2 | P2.S5 |
| P1-11 | Real evidence-pack PDF/ZIP generator + digital signature | EA-MAT | 3 | P2.S6 |
| P1-12 | Vendor concentration real calculation | EA-GOV | 0.5 | P2.S2 |
| P1-13 | Anomaly rules (5) + scheduled job | EA-AI | 1.5 | P2.S6 |
| P1-14 | OpenAPI 3.1 spec + auto-generated docs | EA-REP | 1 | P2.S1 |
| P1-15 | Search index (Scout + Meilisearch) | Cross-cut | 1.5 | P2.S3 |

**P1 total effort: ≈ 36 engineering-weeks** — fits inside Phase 2 if the parallel feature-build stream in the parent plan is rebalanced toward these P1 items.

### 8.3 P2 — Phase 3 expansion / differentiation (sprints P3.S1 → P3.S6)

| # | Item | Domain | Effort | Slot |
| :---- | :---- | :---- | :---- | :---- |
| P2-1 | Scenario modelling (compare two target plateaux) | EA-ROADMAP | 3 | P3.S2 |
| P2-2 | Initiative dependencies + dependency-aware Gantt | EA-ROADMAP | 2 | P3.S2 |
| P2-3 | TOGAF ADM deliverables tracker per initiative | EA-ROADMAP | 2 | P3.S3 |
| P2-4 | Multi-framework maturity (COBIT, ISO 38500) | EA-MAT | 2 | P3.S1 |
| P2-5 | Maturity trend over time + sparklines | EA-MAT | 1 | P3.S1 |
| P2-6 | Reference architecture / pattern library expansion | EA-GOV | 2 | P3.S3 |
| P2-7 | Pattern-to-instance traceability + supersession alerts | EA-GOV | 2 | P3.S4 |
| P2-8 | Process inventory + BPMN canvas | EA-BA | 4 | P3.S4–S5 |
| P2-9 | NL search over EA graph + MCP server endpoint | EA-AI | 3 | P3.S3 |
| P2-10 | Document-ingestion job (PPTX/Visio/PDF → draft elements) | EA-AI | 3 | P3.S5 |
| P2-11 | Nigerian banking tech catalogue (200 entries) | EA-APM | 1.5 | P3.S1 |
| P2-12 | Pact contract tests with all dependent modules | Cross-cut | 1.5 | P3.S6 |

**P2 total effort: ≈ 27 engineering-weeks.**

### 8.4 P3 — 2027 differentiators (not in current plan)

- 3D visualisation (ABACUS-style) — opportunistic.
- Cloud-account auto-discovery — high value, deferred.
- Full open metamodel customisation — only if customer demand emerges.
- BPMN process simulation — only if customer demand emerges.

---

## 9. Effort, Team and Dependency Implications

### 9.1 Critical path

The single critical path through the gap is:

**P0-1 (write surface) → P0-2 (RelationshipValidator) → P0-3 (Audit) → P0-7 (BulkImporter) → P0-8 (ArchiMate Open Exchange) → P1-1 (Diagram authoring) → P1-3 (ARB workflow) → P1-11 (Evidence pack PDF).**

That path is 8 items totalling ≈ 29 engineering-weeks. With the team sized in the parent plan (8 FTE on EA), and assuming 70% throughput, it lands in ≈ 5 sprints = 10 weeks. This fits inside the P1.S5 → P2.S2 window if started now.

### 9.2 External dependencies

- **Asset Management** must expose `GET /api/v1/assets/applications` + write-back of extension attributes — already in the parent plan's P1.S1 dependency list, **needs confirmation by Asset Management team**.
- **Identity & Access** must expose `GET /api/v1/iam/org-units` and `GET /api/v1/iam/users?role=<role>` — same.
- **Policy Management** read-through for principles domain — stubbable.
- **Workflow Studio** is required for ARB and lifecycle workflows — confirm contract.
- **Notifications** is required for exception expiry, DPO alerts — confirm contract.
- **Reports** is required for evidence pack assembly — confirm contract.

### 9.3 Skills gap

The current team (per `ATH-PIP-EA-001 §3`) is sized for the parent plan but **does not include a dedicated diagramming-canvas specialist**. P1-1 (Diagram authoring) will be slower with a generalist Frontend engineer. **Recommendation:** bring one React-Flow / graph-rendering specialist in as a contractor for sprints P2.S1–S3 (≈ 6 weeks of specialist time).

The team also does not include an **NLP/LLM engineer**. P2-9 and P2-10 (NL search and document ingestion) will be blocked unless the platform Copilot team owns these features. Make Copilot team the formal dependency.

### 9.4 Infrastructure additions

- Redis (already platform-wide; confirm tenancy)
- Meilisearch (new; ≈ 0.5 vCPU per tenant; can be platform-shared)
- Horizon for queues (already platform-wide; confirm)
- pdftk / wkhtmltopdf or laravel-snappy for PDF generation (new; container image change)

---

## 10. Risks, Assumptions and Open Decisions

### 10.1 Risks

| ID | Risk | Likelihood | Impact | Mitigation |
| :---- | :---- | :---- | :---- | :---- |
| R-1 | Asset Management does not deliver the extension-attribute write-back on time | Medium | Blocks P0-5 | Build with a stub client and feature-flag the read-through |
| R-2 | Diagram authoring takes longer than 8 weeks because React-Flow custom-node ergonomics are harder than expected | Medium | Slips P1-1 into Phase 3 | Hire a specialist contractor (cf. §9.3); fall back to read-only viewpoints if needed |
| R-3 | ArchiMate Open Exchange XSD compliance reveals schema gaps that force migration changes | Low | Up to 1 sprint slip | Run the round-trip validation in P1.S6 as the phase-exit gate |
| R-4 | CBN-EA framework changes before evidence pack ships | Low | Reseeding maturity tables; ~3 days | Keep the framework as data, not code |
| R-5 | The Nigerian banking tech catalogue (P2-11) raises licensing questions with NIBSS / CBN | Low | Catalogue ships smaller | Curate only public-domain entries |
| R-6 | LLM gateway not yet stable in the platform when EA-AI items start | Medium | Pushes P2-9, P2-10 to 2027 | Build anomaly rules first (P1-13); they need no LLM |

### 10.2 Assumptions

1. The parent implementation plan (`ATH-PIP-EA-001`) remains valid; this gap analysis adjusts its priority order rather than replacing it.
2. The pilot bank is Zenith Digital Bank (placeholder) but data in seeders is for First Bank — confirm pilot identity in P1.S5 kick-off.
3. The CBN-EA maturity framework as seeded in `EaPhase1Seeder` reflects the latest CBN circular; verify against the CBN Risk-Based Cybersecurity Framework v2.0 (May 2026).
4. AEGIS design tokens cover the diagramming UI as well — needs design partnership in P2.S1.

### 10.3 Open decisions for product to make

The following decisions should be taken **before** P1.S5 kick-off so engineering can plan accordingly:

1. **Diagram authoring vs. data-driven viewpoints only.** Ardoq says "diagrams should be derived from data, not drawn." The leaders all let you both *derive* and *draw*. Recommendation: ship derived viewpoints first (P1.S5–S6), authoring in Phase 2. Confirm.
2. **Customisable metamodel — yes/no.** Ardoq's headline. Five engineer-years to build properly. Recommendation: **no**, ship a fixed ArchiMate 3.2 metamodel + a tenant-extension table for custom properties. Confirm.
3. **AI copilot positioning.** Build NexusRisk-wide LLM gateway into EA, or build dedicated EA copilot? Recommendation: use platform gateway; do not build EA-specific NLP. Confirm.
4. **Evidence pack signing ceremony.** Native digital signature (PHP `crypt(…)` over a SHA-256 hash) or external (DocuSign / Adobe Sign)? Recommendation: native for v1.0, external in Phase 3.
5. **Multi-framework maturity.** Ship CBN-EA only for v1.0, or expand to COBIT/ISO 38500 immediately? Recommendation: CBN-EA only; defer.
6. **Process inventory / BPMN.** Phase 2 or Phase 3? Recommendation: Phase 3.

---

## Appendix A — Capability Heatmap Summary

Mean score per domain across all benchmark capabilities, on the 0–4 scale defined in §2.2. "Target" is the fit-for-purpose threshold from §2.3.

```
Domain                          Today   Target   Gap     Severity
-----------------------------   -----   ------   ----    --------
EA-REP — Repository             1.0     3.0      −2.0    Critical
EA-BA — Business Architecture   1.5     2.5      −1.0    High
EA-APM — Application Portfolio  2.0     3.0      −1.0    Medium
EA-TA — Technology              1.5     3.0      −1.5    High
EA-DA — Data Architecture       2.0     3.0      −1.0    High
EA-IA — Integration             1.5     3.0      −1.5    High
EA-SEC — Security Architecture  2.0     3.0      −1.0    High
EA-VIZ — Visualisation          1.0     3.0      −2.0    Critical
EA-MAT — Maturity & Evidence    2.0     3.5      −1.5    Medium / Diff
EA-GOV — Governance             1.0     3.0      −2.0    High
EA-ROADMAP — Roadmap & ADM      2.0     3.0      −1.0    High
EA-AI — AI / Automation         0.0     2.0      −2.0    Critical*
-----------------------------   -----   ------   ----    --------
Composite                       1.46    2.92     −1.46
```

\* Critical for *parity narrative* in 2026; Medium for fit-for-purpose at CBN-EA Level 3.

---

## Appendix B — ArchiMate 3.2 Conformance Check

The Open Group ArchiMate 3.2 specification defines 56 element types across seven layers/aspects and 11 relationship types. Conformance has three levels: viewpoint mechanism support, full notation, and permitted-relationship enforcement.

### B.1 Element coverage in the current schema

| ArchiMate Layer | Elements expected | Elements present (table or column) | Coverage |
| :---- | :---- | :---- | :---- |
| Strategy | Resource, Capability, Course of Action, Value Stream | Capability ✅, Value Stream ✅; Resource, Course of Action ✗ | 50% |
| Business | Business Actor, Role, Process, Function, Service, Interaction, Event, Object, Product, Contract, Representation, Location | Process ✅; Service, Function, Actor, Role, Event, Object ✗ | 8% |
| Application | Application Component, Collaboration, Interface, Function, Process, Service, Interaction, Event, Data Object | Component (via `ea_applications_ext`) ✅, Interface ✅, API (≈ Service) ✅; Function, Process, Event, Data Object ✗ | 33% |
| Technology | Node, Device, System Software, Collaboration, Interface, Path, Communication Network, Function, Process, Service, Interaction, Event, Artifact, Equipment, Facility | Tech Component (≈ Node) ✅; the rest ✗ | 7% |
| Physical | Equipment, Facility, Distribution Network, Material | ✗ all | 0% |
| Motivation | Stakeholder, Driver, Assessment, Goal, Outcome, Principle, Requirement, Constraint, Meaning, Value | Principle ✅; the rest ✗ | 10% |
| Implementation & Migration | Work Package, Deliverable, Implementation Event, Plateau, Gap | Initiative (≈ Work Package) ✅, Plateau ✅, Solution (≈ Deliverable) ✅; Implementation Event, Gap ✗ | 60% |

**Overall element coverage: ≈ 24%.** This is acceptable for v1.0 of a tool focused on the *banking IT landscape* (Strategy + Application + Technology + Implementation are the layers customers actually use). Aim for 50% by end of Phase 2.

### B.2 Relationship coverage

ArchiMate defines 11 relationship types: composition, aggregation, assignment, realisation, serving, used-by, access, influence, triggering, flow, specialisation. The `ea_relationships.relation_type` column is a free `string(64)` — there is **no enforcement** that values are drawn from this 11-element set. Build the `RelationshipValidator` (P0-2) to enforce.

### B.3 Viewpoint coverage

Twenty standard viewpoints are defined in the specification. The current module renders **zero** of them as proper ArchiMate viewpoints; it renders three idiomatic equivalents (capability indented tree, application BFTF grid, technology radar). Aim for eight viewpoints by end of Phase 2 (P1-1).

### B.4 Open Exchange File Format

`ArchiMate3.0_Exchange_v3.2.xsd` is the round-trip target. Today the module produces no XML at all. P0-8 closes this gap.

---

## Appendix C — KRI Catalogue (Initial Seed)

The `ea_kri_definitions` table is present but unseeded. The recommended initial KRI set follows. Categories follow the existing schema (`portfolio | security | compliance | delivery | tech-debt`).

| Code | Name | Category | Direction | Green | Amber | Red |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| EA-KRI-01 | Applications without owner | portfolio | higher_worse | 0 | 5 | 20 |
| EA-KRI-02 | Critical apps with no DR plan in last 12m | compliance | higher_worse | 0 | 1 | 5 |
| EA-KRI-03 | Tech components within 12-month EOL with no replacement | tech-debt | higher_worse | 0 | 3 | 10 |
| EA-KRI-04 | Open ARB exceptions past expiry | compliance | higher_worse | 0 | 1 | 5 |
| EA-KRI-05 | Cross-border data flows without DPIA | security | higher_worse | 0 | 1 | 3 |
| EA-KRI-06 | Capabilities at maturity Level 1 (initial) | portfolio | higher_worse | 5 | 15 | 30 |
| EA-KRI-07 | Standards exceptions issued in last quarter | compliance | higher_worse | 5 | 15 | 30 |
| EA-KRI-08 | Vendor concentration — apps from single vendor | security | higher_worse | 5 | 15 | 30 |
| EA-KRI-09 | Initiatives delivered on-time (12m rolling) | delivery | higher_better | 80% | 60% | 40% |
| EA-KRI-10 | Plateau drift — applications outside target plateau | portfolio | higher_worse | 5% | 15% | 30% |

All ten should be computed by the scheduled job `App\Jobs\Ea\RecomputeKris` on a nightly cadence and shown on the Command Centre. They are the auditable signals that a CBN examiner will look at first.

---

## Appendix D — Evidence Pack Contents (Target Specification)

When `App\Services\Ea\EvidencePackGenerator->generate($assessmentId)` runs, it should emit:

1. **Cover page** — institution name, period, generation date, generated-by user, overall maturity score.
2. **Executive summary** — one page; 80% chart, 20% narrative; overall score, score by domain, three lowest-scoring questions, three highest-scoring questions.
3. **Maturity domain detail** — one page per domain (CBN-EA has 10 domains): all questions, selected level (1–5), comment, list of evidence refs.
4. **Linked evidence index** — a table of every `evidence_refs` entry: filename, hash, attached-by, attached-at, link to the artefact in the ZIP.
5. **Architecture posture summary** — auto-generated from the EA repo: capability count, application count, tech-component count, critical-application count, applications without owner, tech components past EOL, vendor concentration top-5.
6. **Attestation page** — head-of-architecture name + signature, maturity-owner name + signature, generation hash (SHA-256 of the PDF contents excluding the signature page).
7. **Methodology appendix** — how the score is computed, the framework version, the question library version.

The ZIP bundles the PDF plus every `evidence_refs` artefact, named by hash to prevent collision. The ZIP and PDF are sealed by a SHA-256 hash recorded in `ea_evidence_packs.zip_hash` and `pdf_hash` columns (to be added as part of P1-11).

This is the artefact NexusRisk would hand to a CBN examiner. **Building this well is the single highest-leverage feature in the entire module** because it is what closes the regulator-facing sale. Today it does not exist.

---

## Appendix E — Reference Sources

The benchmark in §4 and the leader claims in §1 and §2.1 are taken from publicly-published vendor and analyst sources current to May 2026:

- SAP LeanIX — *SAP LeanIX 2026: building on our EA momentum* and *Enterprise Architecture Features* product page; *2025 Gartner Magic Quadrant for Enterprise Architecture Tools* leader citation; *MCP server for SAP LeanIX solutions* blog (March 2026).
- Ardoq — *Enterprise Architecture Software*, *Platform Overview*, *Enterprise Architecture Metamodels Explained*; *Ardoq Named a Leader in 2025 Gartner Magic Quadrant for EA Tools*.
- Orbus Software — *OrbusInfinity / iServer365* product pages; *Orbus Software Strengthens Alignment to Microsoft 365 Suite*; *Gartner Magic Quadrant Leader* announcement.
- BiZZdesign — *Horizzon* product page; *ArchiMate modeling* help articles; *Business Architecture with ArchiMate* blog.
- MEGA — *HOPEX Platform* feature page; *MEGA HOPEX: Pros and Cons 2026* (PeerSpot); *HOPEX GRC* feature page.
- Avolution — *ABACUS* product page; *Leading Enterprise Architecture Tool Abacus Adds Data Integrations, 3D Visualizations and UI Updates*; *2025 Gartner Magic Quadrant for Enterprise Architecture Tools* citation.
- Sparx Systems — *Unified Edition of Enterprise Architect* product page; *Enterprise Architect Guide* (NILUS Consulting); *Sparx Pro Cloud Server* feature description.
- Archi — *Open Source ArchiMate Modelling Tool* (archimatetool.com); *ArchiMate Modelling - Archi - INCOSE* article.
- ServiceNow — *Application Portfolio Management (APM)* product page; *Enterprise Architecture (formerly APM) – Start your EA Journey* community article; *Australia release* notes (March 2026).
- The Open Group — *ArchiMate 3.2 Specification* and *TOGAF ADM Phases* reference.

All source references are non-confidential public-domain material and are listed in the Sources section of the conversation that produced this document.

---

*End of document. Version 1.0 — 15 May 2026. Owner: Product & Engineering, Atheris Limited.*
