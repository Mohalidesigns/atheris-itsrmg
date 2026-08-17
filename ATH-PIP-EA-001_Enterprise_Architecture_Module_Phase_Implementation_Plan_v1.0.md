# Enterprise Architecture Module — Phase Implementation Plan

**Document ID:** ATH-PIP-EA-001 **Version:** 1.0 **Date:** 24 April 2026 **Parent TRD:** ATH-TRD-EA-001 v1.0 **Product:** NexusRisk IRM **Owner:** Product & Engineering, Atheris Limited **Classification:** Atheris Confidential

---

## 1\. Purpose and Scope

This document is the operational implementation plan for delivering the Enterprise Architecture (EA-Studio) module described in `ATH-TRD-EA-001`. It translates the three-phase roadmap in §16 of the TRD into sprint-level work packages, named deliverables, dependencies, gate criteria, and team assignments over a nine-month build-and-release cycle.

The plan is written to be executable by Atheris engineering, Atheris product, and the pilot bank's IT and architecture teams (Zenith Digital Bank, with generalisation to any DMB, PSP or NMFB). It is deliberately granular in Phase 1 because foundational choices lock in the rest of the programme.

## 2\. Delivery Approach

### 2.1 Cadence

- **Sprint length:** 2 weeks  
- **Total sprints:** 18 (6 sprints × 3 phases)  
- **Release cadence:** one minor release per sprint to `dev` and `qa`; one major release per phase to `uat`; production release at phase-end gate clearance  
- **Ceremony rhythm:** sprint planning Mon W1, mid-sprint demo Fri W1, sprint review \+ retrospective Fri W2, architecture sync every Wednesday

### 2.2 Definition of Done (applies to every work package)

A work package is Done when all of the following are true:

- Code merged to `main` behind a feature flag  
- Unit tests passing; coverage targets in NFR-M met (80% service/repo, 60% controller, 70% React)  
- Integration / contract tests passing against all dependent modules  
- API changes reflected in the OpenAPI 3.1 spec and the generated docs  
- Database migrations include a tested down-migration  
- Accessibility check run on any new UI (axe-core clean)  
- Feature walkthrough recorded and attached to the ticket  
- Product has signed off in the sprint review

### 2.3 Feature Flags

Every new capability ships behind a flag in `ea.features.<name>`. Flags default off in production until the phase gate clears. This allows the pilot bank to turn features on progressively without a code deployment.

### 2.4 Branching and Release

- `main` — always releasable  
- `release/phaseN` — stabilisation branch cut two sprints before UAT  
- `feature/*` — short-lived feature branches; PR merge-squash required  
- Tags follow `ea-v<phase>.<sprint>.<patch>` (e.g. `ea-v1.3.0`)

## 3\. Team and Roles

| Role | Headcount | Primary Responsibility |
| :---- | :---- | :---- |
| Module Tech Lead | 1 | Overall technical ownership, ARB representation, code review gatekeeper |
| Backend Engineer (Laravel / PHP) | 3 | Services, repositories, APIs, migrations |
| Frontend Engineer (React / TS) | 2 | Screens, diagramming canvas, Ant Design theming |
| Data / Graph Engineer | 1 | Metamodel, edges cache, traversal queries, OpenSearch indexing |
| QA Engineer | 1 | Test automation (PHPUnit, Vitest, Playwright, k6), test data |
| UX / Product Designer | 1 | Wireframes, AEGIS tokens, usability testing |
| Product Manager | 1 | Backlog, sprint goals, stakeholder comms |
| DevOps Engineer (shared) | 0.5 | CI/CD pipeline, environments, observability |
| Security Engineer (shared) | 0.25 | Threat modelling, pen-test coordination |
| Technical Writer (shared) | 0.25 | Customer-facing docs, admin guide |

Client-side (pilot bank):

| Role | Purpose |
| :---- | :---- |
| Head of Architecture | Product owner proxy on the client side |
| Business Architect (1–2) | Capability map and value stream population |
| Application Architect (1) | Application portfolio scoring |
| Data Architect (1) | Information domain and classification work |
| CISO delegate | Zone model and control mapping |
| Compliance officer | CBN-CSAT evidence link-up |
| Internal Audit observer | Audit trail review, evidence pack check |

## 4\. Cross-Cutting Tracks

Four tracks run in parallel with the feature-build stream across all three phases:

1. **DevOps & Platform Track** — pipeline, environments, observability, performance harnesses  
2. **UX Research Track** — wireframes, usability testing, AEGIS token refinement  
3. **Integration Contracts Track** — Pact contracts with every dependent module; renegotiated each phase  
4. **Docs & Enablement Track** — admin guide, architect handbook, onboarding videos, ARB playbook

Each track has its own backlog and a single owner; tracks do not absorb capacity from the feature stream's Definition of Done.

---

# Phase 1 — Foundation (Months 1–3)

## P1.1 Objective

Deliver a live, metamodel-driven repository holding the bank's capability map, application portfolio, technology stack, and information domains, with a working command-centre dashboard and a baseline CBN EA maturity self-assessment. At phase exit, the pilot bank must be able to show a CBN examiner a single-screen current-state view at Level 3 maturity based on data that was previously scattered across spreadsheets.

## P1.2 In Scope

- EA-REP (repository, metamodel, ArchiMate 3.2 compliance, CRUD, audit log)  
- EA-BA (capability map, value streams, strategy linkage)  
- EA-APM (application portfolio read-through from Asset, TIME scoring, fit scoring)  
- EA-TA (technology radar, EOL/EOS tracking)  
- EA-DA partial (information domains, logical entities, classification — *no lineage yet*)  
- EA-VIZ (command-centre dashboard only)  
- EA-MAT (baseline CBN EA self-assessment, no evidence pack yet)  
- Identity integration, tenant scoping, RBAC  
- CI/CD pipeline, dev/qa/uat environments

## P1.3 Out of Scope for Phase 1

- Interfaces, APIs, blast radius (deferred to P2)  
- Security zones, control-to-component (deferred to P2)  
- Process inventory (deferred to P2)  
- ARB, principles, standards, exceptions (deferred to P2)  
- Roadmap, plateaux, initiatives (deferred to P3)  
- Reference patterns, Marketplace (deferred to P3)  
- Full analytics library, KRI publication (deferred to P3)  
- CBN evidence pack generator (deferred to P3)

## P1.4 Dependencies and Assumptions

- Asset Management exposes `GET /api/v1/assets/applications` and `GET /api/v1/assets/tech-components` with extension-attribute write-back within P1.S1  
- Identity & Access supports `GET /api/v1/iam/org-units` and `GET /api/v1/iam/users?role=<role>` from day one  
- Policy Management `GET /api/v1/policies?domain=<domain>` exists for read-through (stub acceptable until P2.S1)  
- Workflow Studio and Reports are not consumed in P1

## P1.5 Sprint Breakdown

### Sprint P1.S1 — Scaffolding and Metamodel (Weeks 1–2)

**Goal:** Module skeleton exists in the platform monorepo, the metamodel is persisted, and the audit log works end-to-end.

Work packages:

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P1.S1.WP-001 | Laravel module scaffold under `Modules/Ea` | Backend | Module registered, PSR-12 clean, CI green |
| P1.S1.WP-002 | React package scaffold `@atheris/ea-studio` under shell | Frontend | Sidebar entry visible behind flag `ea.enabled` |
| P1.S1.WP-003 | Database migrations for metamodel core tables | Data / Graph | `ea_capabilities`, `ea_info_domains`, `ea_logical_entities`, `ea_applications_ext`, `ea_tech_components_ext`, `ea_relationships`, `ea_audit_log` live in dev \+ qa |
| P1.S1.WP-004 | ArchiMate 3.2 permitted-relationship matrix seeded | Data / Graph | Seeder \+ enforcement at repository layer (`RelationshipValidator`) |
| P1.S1.WP-005 | Audit log append-only implementation | Backend | Every write passes through `AuditLogger`; integration test verifies actor/before/after capture |
| P1.S1.WP-006 | Tenant-scoping `TenantScope` global scope \+ tests | Backend | Cross-tenant read attempt returns 404; automated contract test |
| P1.S1.WP-007 | Identity integration (org units, users, roles) | Backend | `IamClient` with circuit breaker; cached 60s |
| P1.S1.WP-008 | AEGIS token file for EA (`tokens.ea.json`) | UX | Tokens imported into Ant Design ConfigProvider |

Exit criteria:

- All migrations apply cleanly with reversible down-migrations  
- A synthetic `Capability` can be created via repository and appears in audit log  
- PR to platform shell lands with EA sidebar entry behind feature flag

### Sprint P1.S2 — Capability Map Backend \+ Skeleton UI (Weeks 3–4)

**Goal:** Capabilities can be created, listed and linked to organisation units through both API and UI.

Work packages:

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P1.S2.WP-001 | `CapabilityService` \+ `CapabilityRepository` | Backend | Full CRUD with parent/level enforcement (max 5 levels) |
| P1.S2.WP-002 | REST endpoints `/api/v1/ea/capabilities` | Backend | OpenAPI spec complete; controller tests |
| P1.S2.WP-003 | BIAN Service Landscape v12 importer | Backend | Seeder installs BIAN as optional reference model per tenant |
| P1.S2.WP-004 | Capability list screen (table view) | Frontend | Paged list; filter by level, parent, owner |
| P1.S2.WP-005 | Capability detail screen (overview tab) | Frontend | Name, description, owner (live from IAM), criticality, last-verified |
| P1.S2.WP-006 | Capability import via CSV | Backend | Excel template; dry-run mode; validation report |
| P1.S2.WP-007 | Pact contract test with Asset Management application endpoint | QA | Contract published to Pact broker |

Exit criteria:

- Create, list, edit, delete a capability end-to-end via UI  
- BIAN v12 importable into a fresh tenant in under 30 seconds  
- Bulk CSV import of 1,000 capabilities completes in under 2 minutes

### Sprint P1.S3 — Capability Map Visualisation \+ Value Streams (Weeks 5–6)

**Goal:** The capability map renders as an interactive heatmap; value streams are modellable; both are linked.

Work packages:

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P1.S3.WP-001 | Tree-map / indented list toggle for capability map | Frontend | D3 tree-map with zoom; 2,000-node render ≤ 2.5s (NFR-P-001) |
| P1.S3.WP-002 | Heatmap overlay (static metrics in P1: criticality \+ app count) | Frontend | Colour scale, legend, filter rail |
| P1.S3.WP-003 | Capability side panel (applications, risks — as stubs in P1) | Frontend | Applications tab live; risks tab shows coming-in-P2 placeholder |
| P1.S3.WP-004 | `ValueStreamService` \+ REST endpoints | Backend | CRUD; stages as JSON; linked capabilities |
| P1.S3.WP-005 | Value stream editor (stages \+ participants) | Frontend | Drag-and-drop stage reorder |
| P1.S3.WP-006 | Strategy linkage: goals, drivers, principles (data model only, full UI in P2) | Data / Graph | Tables seeded; API stubs |

Exit criteria:

- The pilot bank can visually navigate 1,500 BIAN \+ client-specific capabilities from a single screen  
- Heatmap re-colours on-the-fly when the metric toggle changes

### Sprint P1.S4 — Application Portfolio (APM) (Weeks 7–8)

**Goal:** Application portfolio is fully live — portfolio table, detail view, TIME/fit scoring, capability linkage, rationalisation heatmap.

Work packages:

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P1.S4.WP-001 | `AssetApplicationClient` with circuit breaker | Backend | Read-through from Asset Management; Redis cache with 5-minute TTL; event invalidation subscriber |
| P1.S4.WP-002 | `ApplicationExtService` for EA-specific attrs | Backend | Write-through: updates `ea_applications_ext` and writes back extension attrs to Asset |
| P1.S4.WP-003 | Application portfolio table | Frontend | Server-side paged; 5,000 rows; NFR-P-002 met |
| P1.S4.WP-004 | Application detail screen (overview, capabilities, technology tabs) | Frontend | Tabs live; interfaces/data/risks tabs stubbed |
| P1.S4.WP-005 | TIME score \+ business fit \+ technical fit editors | Frontend | Dropdowns; change requires reason code logged |
| P1.S4.WP-006 | App-to-capability link editor | Frontend | Many-to-many; supports "primary" flag |
| P1.S4.WP-007 | Application rationalisation heatmap (4-quadrant) | Frontend | Business fit × technical fit with cost/risk overlays |
| P1.S4.WP-008 | Contract test with Asset Management | QA | Breaking change detection; CI blocker |

Exit criteria:

- 400 applications loaded from Asset Management via live API (synthetic test tenant)  
- TIME scoring round-trips: change applied in UI, persisted to Asset extension attrs, verified via Asset UI  
- Rationalisation heatmap renders in under 1.5 seconds

### Sprint P1.S5 — Technology Radar \+ Information Domains (Weeks 9–10)

**Goal:** Technology radar is live with EOL/EOS tracking; information domain catalogue and logical entity model are modelable.

Work packages:

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P1.S5.WP-001 | `AssetTechComponentClient` with same read-through pattern | Backend | Hydrates tech components from Asset |
| P1.S5.WP-002 | Technology radar view (Adopt/Trial/Assess/Hold) | Frontend | D3 radar chart; drag-and-drop quadrant change (with audit) |
| P1.S5.WP-003 | Technology Standards catalogue | Backend | CRUD \+ radar status mapping |
| P1.S5.WP-004 | EOL/EOS date fields \+ obsolescence flag | Backend | Within-12-months components raise `TechnologyComponentEOLApproaching` event |
| P1.S5.WP-005 | Information domain catalogue | Backend \+ Frontend | CRUD; domain owner from IAM |
| P1.S5.WP-006 | Logical entity catalogue | Backend \+ Frontend | CRUD; attributes; classification; PII flag |
| P1.S5.WP-007 | NDPA 2023 classification picker (Public / Internal / Confidential / Personal / Sensitive) | UX | Reusable component |
| P1.S5.WP-008 | Data flow catalogue (entity-to-entity only in P1, app-to-app deferred to P2) | Backend \+ Frontend | CRUD; cross-border flag |

Exit criteria:

- Technology radar displays 1,200 components from synthetic dataset  
- 12 information domains \+ 80 logical entities can be loaded and edited  
- Obsolescence event fires for a synthetic component with EOL in 11 months

### Sprint P1.S6 — Command Centre, CBN Baseline, Stabilisation (Weeks 11–12)

**Goal:** Phase 1 is production-ready — command centre dashboard, baseline CBN maturity self-assessment, performance hardening, UAT pass.

Work packages:

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P1.S6.WP-001 | Command Centre dashboard | Frontend | KRI strip (static in P1), obsolescence count, application count, capability count, last-refresh timestamp |
| P1.S6.WP-002 | Custom dashboard widget catalogue (foundation only) | Frontend | Widget registry; three widgets available |
| P1.S6.WP-003 | CBN EA self-assessment form | Backend \+ Frontend | Full question bank; answer persistence; evidence attachment fields (upload only, deep-link in P3) |
| P1.S6.WP-004 | Maturity score calculator | Backend | Score per domain \+ overall |
| P1.S6.WP-005 | Performance hardening | Data / Graph | Edge cache materialised; NFR-P-001 through NFR-P-004 verified on synthetic dataset |
| P1.S6.WP-006 | Security review (internal) | Security Engineer | No High/Critical findings |
| P1.S6.WP-007 | Accessibility audit | QA \+ UX | No Level A findings; ≤ 3 Level AA findings |
| P1.S6.WP-008 | Admin guide v1 (setup, RBAC, tenant provisioning) | Tech Writer | Published to docs.atheris.ng |
| P1.S6.WP-009 | UAT at pilot bank | PM \+ Client | Sign-off on all P1 user stories |

## P1.6 Phase 1 Exit Criteria (Gate G1)

A phase-end gate review with the Module Tech Lead, Product, Client Chief Architect, CISO delegate, and Internal Audit observer. Pass requires ALL of:

- [ ] 400 applications loaded and scored (TIME \+ fit) for at least 80% of entries  
- [ ] 1,200 technology components loaded with radar status and EOL dates populated  
- [ ] Capability map with at least 500 capabilities active; BIAN optionally adopted  
- [ ] 10 information domains \+ 50 logical entities classified per NDPA  
- [ ] Command centre dashboard available to CIO and Head of Architecture roles  
- [ ] Baseline CBN EA self-assessment completed by pilot bank with score ≥ 3.0 overall  
- [ ] All NFR targets (Sec. 9.1) met on performance harness  
- [ ] Zero High/Critical security findings open  
- [ ] No Level A accessibility findings open  
- [ ] Internal Audit has confirmed audit log completeness

## P1.7 Phase 1 Risks and Mitigations

| Risk | Likelihood | Mitigation |
| :---- | :---- | :---- |
| Asset Management API not ready in time | Medium | Early integration contract workshop in week 1; mock-server fallback |
| BIAN v12 licensing dispute | Low | Ship as optional; do not redistribute until licensing confirmed by legal |
| Pilot bank does not provide Chief Architect | High | Escalation path to CIO office; fallback to consultant Chief Architect |
| Metamodel drift as features are added | Medium | Weekly metamodel review with Tech Lead; extensions are migration-backed only |
| Performance regressions as entity counts grow | Medium | Nightly performance harness run on QA; regression blocks release |

---

# Phase 2 — Integration and Governance (Months 4–6)

## P2.1 Objective

Close the integration loop: every module in the NexusRisk IRM sidebar now reads from or writes to EA-Studio through the matrix in TRD §8.2. Deliver the ARB governance stack on Workflow Studio, and bring security architecture, process architecture, and integration architecture to full depth.

## P2.2 In Scope

- EA-IA (interface catalogue, API register, blast-radius analyser)  
- EA-SEC (security zones, control-to-component mapping, ISMS/PCI/Compliance integration)  
- EA-PROC (process inventory, BCM linkage)  
- EA-GOV (principles, standards, ARB workflow on Workflow Studio, exceptions/waivers)  
- EA-DA depth (cross-border flows with DPO alert, CRUD matrix, lightweight lineage)  
- Strategy linkage depth (goals, drivers, principles fully wired)  
- CBN-CSAT answer-to-component attribution  
- Copilot integration (Atheris Copilot read access to EA metadata)  
- Vendor concentration views

## P2.3 Dependencies

- Workflow Studio must expose `POST /api/v1/workflow/instances` with digital-signature support  
- ISMS `GET /api/v1/isms/controls?framework=iso27001-a` ready by P2.S1  
- PCI Management `GET /api/v1/pci/controls` ready by P2.S1  
- Compliance `GET /api/v1/compliance/frameworks/{framework}` ready by P2.S2  
- Business Continuity `GET /api/v1/bcm/bias` ready by P2.S3  
- CBN Cyber Assessment `GET /api/v1/cbn-csat/answers` ready by P2.S4  
- Issues & Remediation `POST /api/v1/issues` ready by P2.S5

## P2.4 Sprint Breakdown

### Sprint P2.S1 — Interface Catalogue and API Register (Weeks 13–14)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P2.S1.WP-001 | `InterfaceService` \+ `ea_interfaces` table | Backend | CRUD; source/target app refs |
| P2.S1.WP-002 | Interface catalogue UI (table \+ detail) | Frontend | Filter by protocol, pattern, classification |
| P2.S1.WP-003 | `ApiRegisterService` \+ `ea_apis` table | Backend | CRUD; provider/consumer links |
| P2.S1.WP-004 | L0 system context diagram auto-generator | Frontend \+ Data / Graph | Generate on demand for any application using interfaces as source |
| P2.S1.WP-005 | CSV import for interfaces | Backend | Excel template; validation |

### Sprint P2.S2 — Blast Radius and L1 Data Flows (Weeks 15–16)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P2.S2.WP-001 | `BlastRadiusService` with graph traversal | Data / Graph | 500-node graph in under 3s (NFR-P-004) |
| P2.S2.WP-002 | Blast radius UI (graph view \+ affected-item list) | Frontend | Exportable as SVG and PDF |
| P2.S2.WP-003 | L1 data flow diagram with classification highlighting | Frontend | PII-carrying flows shown in signal red |
| P2.S2.WP-004 | Single-point-of-failure detection | Data / Graph | Nightly job flags SPOFs; surfaced in Command Centre |
| P2.S2.WP-005 | Cycle dependency detection | Data / Graph | Tarjan's SCC on interface graph |

### Sprint P2.S3 — Security Architecture: Zones and Control Mapping (Weeks 17–18)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P2.S3.WP-001 | `ZoneService` \+ `ea_zones` \+ `ea_zone_assignments` tables | Backend | CRUD; assignment workflow |
| P2.S3.WP-002 | Default zone set seeder (Internet, DMZ, App, Data, Core Banking, Payment, Management) | Backend | Configurable per tenant |
| P2.S3.WP-003 | Security zone visualiser | Frontend | Zone boxes with draggable application placement |
| P2.S3.WP-004 | Zone transition requires approval workflow | Backend | Hooks into Workflow Studio |
| P2.S3.WP-005 | `ControlCatalogueClient` (ISMS, PCI, Compliance) | Backend | Read-through with cache |
| P2.S3.WP-006 | Control-to-component mapping UI | Frontend | Per-component control coverage view |
| P2.S3.WP-007 | Control auto-suggestion based on zone \+ classification | Backend | Rules engine, overrideable |

### Sprint P2.S4 — Process Architecture and BCM Linkage (Weeks 19–20)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P2.S4.WP-001 | `ProcessService` \+ `ea_processes` table with L1/L2/L3 hierarchy | Backend | CRUD; capability link; RTO/RPO fields |
| P2.S4.WP-002 | Process inventory UI | Frontend | Hierarchy tree with filter |
| P2.S4.WP-003 | Process-to-application matrix | Frontend | Dense matrix with dependency count |
| P2.S4.WP-004 | `BcmClient` for BIA read | Backend | Criticality flows into process records |
| P2.S4.WP-005 | BPMN 2.0 XML import (descriptive only) | Backend | Stores model; no execution |
| P2.S4.WP-006 | CBN-CSAT answer-to-component attribution | Backend \+ Frontend | Answer references one or more architecture entities |

### Sprint P2.S5 — ARB, Principles and Standards (Weeks 21–22)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P2.S5.WP-001 | `PrincipleService` \+ `ea_principles` table | Backend | CRUD |
| P2.S5.WP-002 | Principles catalogue UI \+ 15 starter principles seeded | Frontend | TOGAF-21 subset curated for Nigerian banking |
| P2.S5.WP-003 | `StandardsService` \+ `ea_standards` table | Backend | CRUD; radar status mapping |
| P2.S5.WP-004 | Standards catalogue UI \+ 40 starter standards seeded | Frontend | Grouped by category |
| P2.S5.WP-005 | ARB submission entity \+ Workflow Studio template | Backend | Submission includes auto-computed blast radius and control impact |
| P2.S5.WP-006 | ARB queue UI (kanban) | Frontend | Drag-and-drop state changes |
| P2.S5.WP-007 | Digital signature on ARB decisions | Security \+ Backend | Per-user certificate; verifiable on export |
| P2.S5.WP-008 | Issues & Remediation bi-directional hook | Backend | ARB decision creates tracked issues; issue closure can propose architecture change |

### Sprint P2.S6 — Exceptions, Copilot Integration, Stabilisation (Weeks 23–24)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P2.S6.WP-001 | `ExceptionService` \+ `ea_exceptions` table | Backend | CRUD; expiry enforcement |
| P2.S6.WP-002 | Exception renewal workflow on Workflow Studio | Backend | 30/14/7-day reminders |
| P2.S6.WP-003 | Exception dashboard | Frontend | By age, subject, compensating controls |
| P2.S6.WP-004 | Copilot read access to EA metadata | Backend \+ Copilot team | Prompt context injector; test queries from TRD §8.2 |
| P2.S6.WP-005 | Vendor concentration view | Frontend | Read-through from Vendor Management; heatmap |
| P2.S6.WP-006 | Cross-border data flow DPO alert | Backend | Event on create; email to DPO role |
| P2.S6.WP-007 | Phase 2 performance hardening | Data / Graph | All NFR-P targets re-verified |
| P2.S6.WP-008 | Phase 2 security review | Security Engineer | Pen-test pass |
| P2.S6.WP-009 | UAT at pilot bank | PM \+ Client | Sign-off on all P2 user stories |

## P2.5 Phase 2 Exit Criteria (Gate G2)

- [ ] All 23 integrations in TRD §8.2 demonstrated live in UAT  
- [ ] Full interface catalogue live (minimum 80 interfaces in pilot)  
- [ ] Blast-radius report generatable for any proposed change  
- [ ] Security zones assigned to all production applications (minimum 100% of critical applications)  
- [ ] Control coverage visible for every component tagged as processing NDPA Sensitive Personal Data or PCI PAN  
- [ ] Process inventory live (minimum 150 L3 processes)  
- [ ] ARB has held at least three meetings on the platform with digitally signed decisions  
- [ ] At least 10 principles and 25 standards published and enforced  
- [ ] At least 5 exceptions active with renewal workflows firing  
- [ ] Copilot returns correct answer on 8 of 10 test queries from TRD §8.2

## P2.6 Phase 2 Risks and Mitigations

| Risk | Likelihood | Mitigation |
| :---- | :---- | :---- |
| Workflow Studio digital signature not ready | Medium | Early escalation to Workflow team; fallback to authenticated audit-log signing |
| ISMS/PCI control catalogue fragmentation | High | Single integration workshop in P2.S1 to agree read model |
| Client ARB not yet constituted | Medium | ARB charter template provided; workshops scheduled during Phase 1 UAT |
| Zone model disputes between CISO and architects | Medium | Default zone set is configurable; not prescriptive |
| Copilot context window overflow on full EA metadata | Low | Scoped retrieval; indexed snippets, not full graph dumps |

---

# Phase 3 — Analytics, Roadmap and Marketplace (Months 7–9)

## P3.1 Objective

Deliver the full analytics and KRI publication layer, the roadmap and transformation suite, the reference pattern library with Marketplace publishing, the ArchiMate Open Exchange round-trip, and the CBN EA Evidence Pack generator. At phase exit, the pilot bank goes live in production.

## P3.2 In Scope

- EA-ROAD (plateaux, transition architectures, initiative portfolio)  
- EA-SRA (reference pattern library, solution architectures)  
- EA-VIZ full (all 12 OOTB dashboards, custom dashboards)  
- EA KRI publication to KRIs & Dashboards module (8 KRIs)  
- Marketplace integration (pattern publishing, connector install)  
- ArchiMate 3.2 Open Exchange Format import and export (full round-trip)  
- CSV/XLSX/PPTX exports via Reports engine  
- CBN EA Evidence Pack generator  
- External CMDB connectors via Integrations Hub (ServiceNow CMDB, Device42)  
- Regulatory Intelligence integration (impact assessments)  
- Production hardening, final UAT, go-live

## P3.3 Dependencies

- KRIs & Dashboards `POST /api/v1/kri/definitions` and `POST /api/v1/kri/values` ready by P3.S1  
- Reports `POST /api/v1/reports/render` with PDF/PPTX templates ready by P3.S2  
- Marketplace `POST /api/v1/marketplace/listings` ready by P3.S4  
- Regulatory Intelligence `GET /api/v1/regint/changes` ready by P3.S5

## P3.4 Sprint Breakdown

### Sprint P3.S1 — Plateaux and Initiatives (Weeks 25–26)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P3.S1.WP-001 | `PlateauService` \+ `ea_plateaux` table | Backend | Current, target, transition types |
| P3.S1.WP-002 | `InitiativeService` \+ `ea_initiatives` table | Backend | Links to capabilities, applications, risks, obligations |
| P3.S1.WP-003 | Plateau comparison diff | Backend | Add/remove/change lists |
| P3.S1.WP-004 | Initiative list and detail UI | Frontend | Issues & Remediation bi-directional link |
| P3.S1.WP-005 | Plan-vs-actual drift calculator | Data / Graph | KRI raised when drift \> configurable threshold |

### Sprint P3.S2 — Roadmap Visualisation and ADM Tracker (Weeks 27–28)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P3.S2.WP-001 | Roadmap gantt / swim-lane view | Frontend | Initiatives across time, grouped by plateau |
| P3.S2.WP-002 | TOGAF 10 ADM phase tracker per initiative | Backend \+ Frontend | Phase state machine; artefact checklist per phase |
| P3.S2.WP-003 | ADM-phase-to-Workflow Studio binding | Backend | Phase gate \= Workflow Studio approval step |
| P3.S2.WP-004 | Roadmap export to PPTX via Reports | Backend | Template with Atheris \+ client branding |

### Sprint P3.S3 — Reference Patterns and Solution Architectures (Weeks 29–30)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P3.S3.WP-001 | `PatternService` \+ `ea_patterns` table | Backend | Versioned; intent/context/participants/forces/consequences |
| P3.S3.WP-002 | Pattern library UI | Frontend | Browse, search, version history |
| P3.S3.WP-003 | Five starter patterns seeded | UX \+ Backend | API-led integration, event-driven core, zero-trust access, customer onboarding, regulatory reporting |
| P3.S3.WP-004 | `SolutionService` \+ `ea_solutions` table | Backend | Instantiate from pattern; retains link |
| P3.S3.WP-005 | Solution architecture editor | Frontend | Canvas view inheriting from pattern |
| P3.S3.WP-006 | Pattern-supersedes-pattern propagation | Backend | Alert on dependent solutions |

### Sprint P3.S4 — Full Analytics Library and KRI Publication (Weeks 31–32)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P3.S4.WP-001 | Build out remaining 11 dashboards (Command Centre already done in P1) | Frontend | Per TRD Appendix D |
| P3.S4.WP-002 | Custom dashboard builder (drag widgets) | Frontend | Per-user \+ per-role dashboards |
| P3.S4.WP-003 | KRI publication to KRIs & Dashboards | Backend | 8 KRIs from TRD FR-VIZ-004 |
| P3.S4.WP-004 | Drill-down from heatmap cell to entity list | Frontend | ≤ 2 clicks per TRD FR-VIZ-005 |
| P3.S4.WP-005 | Scheduled dashboard distribution via Reports | Backend | Weekly/monthly PDF to distribution lists |
| P3.S4.WP-006 | Marketplace listing for EA patterns | Backend \+ Marketplace team | Publishable patterns with attribution |

### Sprint P3.S5 — ArchiMate Open Exchange and Regulatory Intelligence (Weeks 33–34)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P3.S5.WP-001 | ArchiMate 3.2 Open Exchange export | Backend | Async job; writes to object storage |
| P3.S5.WP-002 | ArchiMate 3.2 Open Exchange import | Backend | Merge vs replace modes; dry-run |
| P3.S5.WP-003 | Round-trip verification test | QA | Export → import into fresh tenant → diff \= empty |
| P3.S5.WP-004 | Regulatory Intelligence integration | Backend | RI change event triggers auto-impact assessment |
| P3.S5.WP-005 | External CMDB connector (ServiceNow CMDB) via Integrations Hub | Backend | Optional enablement |
| P3.S5.WP-006 | External CMDB connector (Device42) via Integrations Hub | Backend | Optional enablement |

### Sprint P3.S6 — CBN Evidence Pack, Production Hardening, Go-Live (Weeks 35–36)

| ID | Work Package | Owner | Deliverable |
| :---- | :---- | :---- | :---- |
| P3.S6.WP-001 | CBN EA Evidence Pack generator | Backend | PDF \+ ZIP bundle per TRD FR-MAT-004 |
| P3.S6.WP-002 | Evidence auto-proposal engine | Backend | Auto-answers from repository state per FR-MAT-003 |
| P3.S6.WP-003 | Maturity score history \+ trend chart | Backend \+ Frontend | Quarter-on-quarter |
| P3.S6.WP-004 | Full disaster recovery test | DevOps | Failover \+ failback in under 4 hours |
| P3.S6.WP-005 | End-to-end performance test at production scale | QA | 200,000 entities, 1,000,000 relationships per NFR-SC-001 |
| P3.S6.WP-006 | External security pen-test | Security \+ external vendor | No High/Critical findings |
| P3.S6.WP-007 | Final UAT at pilot bank | PM \+ Client | Sign-off on all P3 user stories |
| P3.S6.WP-008 | Production go-live | All | Flags flipped on production for pilot bank |
| P3.S6.WP-009 | 48-hour hypercare | Tech Lead \+ DevOps | On-call rotation for 48 hours post-go-live |

## P3.5 Phase 3 Exit Criteria (Gate G3 / Production Go-Live)

- [ ] Full analytics library (12 dashboards) operational  
- [ ] 8 EA KRIs published to KRIs & Dashboards and visible on platform-level dashboards  
- [ ] At least 3 initiatives active with plateaux and ADM phase tracking  
- [ ] At least 5 reference patterns in library; 2 instantiated into solution architectures  
- [ ] ArchiMate Open Exchange round-trip verified with zero data loss  
- [ ] CBN EA Evidence Pack generated, reviewed by CBN-qualified internal reviewer, and approved  
- [ ] External pen-test passed with no High/Critical findings  
- [ ] DR test passed with RTO ≤ 4 hours  
- [ ] Performance at 200,000 entities verified  
- [ ] Pilot bank achieves self-assessed CBN EA maturity score ≥ 3.0 (Acceptance A14 from TRD)  
- [ ] 48-hour hypercare window closed without P1 incidents  
- [ ] Admin guide, architect handbook, and ARB playbook published

## P3.6 Phase 3 Risks and Mitigations

| Risk | Likelihood | Mitigation |
| :---- | :---- | :---- |
| CBN evidence pack template rejected | Low | Pre-review with CBN examination-qualified reviewer before production |
| Marketplace governance for public patterns | Medium | Start with private-listing-only; open to public in a later release |
| ArchiMate round-trip data loss on exotic constructs | Medium | Round-trip test is a phase exit criterion; test dataset covers all 9 layers |
| Adoption decline after go-live | High | Hypercare window \+ monthly adoption review; KRIs tracked on Command Centre |
| External CMDB connector scope creep | Medium | Two connectors only in P3; additional connectors follow marketplace model |

---

# 5\. Cross-Phase Resource Plan

| Sprint | Backend FTE | Frontend FTE | Data/Graph FTE | QA FTE | UX FTE | PM FTE | DevOps | Sec | Writer |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| P1.S1 | 3 | 1.5 | 1 | 1 | 1 | 1 | 0.5 | 0.25 | 0.1 |
| P1.S2–S4 | 3 | 2 | 1 | 1 | 1 | 1 | 0.3 | 0.1 | 0.1 |
| P1.S5–S6 | 3 | 2 | 1 | 1 | 0.75 | 1 | 0.3 | 0.25 | 0.25 |
| P2.S1–S5 | 3 | 2 | 1 | 1 | 0.75 | 1 | 0.3 | 0.25 | 0.2 |
| P2.S6 | 3 | 2 | 1 | 1.25 | 0.5 | 1 | 0.4 | 0.5 | 0.3 |
| P3.S1–S5 | 3 | 2 | 1 | 1 | 0.75 | 1 | 0.3 | 0.25 | 0.3 |
| P3.S6 | 3 | 2 | 1 | 1.5 | 0.25 | 1 | 0.6 | 0.75 | 0.5 |

Approximate total effort: **backend 54 engineer-weeks, frontend 36, data/graph 18, QA 19, UX 14, PM 18, devops 6, security 4.5, writer 4** — totalling approximately **173.5 engineer-weeks** over nine months.

# 6\. Cross-Phase Dependency Matrix

Tracks dependencies on other NexusRisk IRM modules. Red \= blocker if unavailable; Amber \= degrades feature; Green \= optional.

| Dependency | P1 | P2 | P3 |
| :---- | :---: | :---: | :---: |
| Asset Management — applications read/write-back | 🔴 | 🟢 | 🟢 |
| Asset Management — tech components read/write-back | 🔴 | 🟢 | 🟢 |
| Identity & Access — users, roles, org units | 🔴 | 🟢 | 🟢 |
| Policy Management — read | 🟡 | 🟢 | 🟢 |
| Vendor Management — read | 🟢 | 🔴 | 🟢 |
| ISMS — controls read | 🟢 | 🔴 | 🟢 |
| PCI Management — controls read | 🟢 | 🔴 | 🟢 |
| Compliance — frameworks read | 🟢 | 🔴 | 🟢 |
| Business Continuity — BIA read | 🟢 | 🟡 | 🟢 |
| CBN Cyber Assessment — answers read | 🟢 | 🟡 | 🔴 |
| Issues & Remediation — bi-directional | 🟢 | 🔴 | 🟢 |
| Workflow Studio — workflow engine \+ digital signature | 🟢 | 🔴 | 🟡 |
| KRIs & Dashboards — KRI publication | 🟢 | 🟢 | 🔴 |
| Reports — PDF/PPTX rendering | 🟢 | 🟢 | 🔴 |
| Marketplace — pattern listings | 🟢 | 🟢 | 🟡 |
| Regulatory Intelligence — change events | 🟢 | 🟢 | 🟡 |
| Atheris Copilot — metadata access | 🟢 | 🔴 | 🟢 |
| Integrations Hub — external connectors | 🟢 | 🟢 | 🟡 |
| Continuous Monitoring — health signals | 🟢 | 🟢 | 🟡 |
| Security Operations — incident events | 🟢 | 🟡 | 🟢 |
| Core Banking — specialisation links | 🟢 | 🟡 | 🟡 |

# 7\. Communications and Governance

## 7.1 Internal (Atheris)

- **Weekly:** engineering standup (Monday), architecture sync (Wednesday), sprint review (Friday W2)  
- **Fortnightly:** Product \+ Engineering joint review (sprint end)  
- **Monthly:** Head of Product \+ Head of Engineering \+ Atheris CEO programme review  
- **Per phase:** phase-end retrospective \+ gate review

## 7.2 External (Pilot Bank)

- **Weekly:** Client Chief Architect \+ Atheris Tech Lead 1:1  
- **Fortnightly:** sprint demo to Client CIO office  
- **Per phase:** gate review with Client CIO, CISO, CRO, Head of Architecture, Internal Audit  
- **Quarterly:** Board IT Committee briefing (CBN maturity score progress)

## 7.3 Escalation Path

1. Module Tech Lead → Atheris Head of Engineering → Atheris CEO  
2. Product Manager → Atheris Head of Product → Atheris CEO  
3. Client Chief Architect → Client CIO → Pilot Bank MD

# 8\. Post-Go-Live Operations (Months 10+)

After the Phase 3 gate, the module enters steady-state operations. A small long-term team stays with the module:

- 1 × Tech Lead (50% allocation)  
- 1 × Backend Engineer (100%)  
- 1 × Frontend Engineer (50%)  
- 1 × QA Engineer (50%)

Continued work beyond M9 (not covered in this plan):

- BPMN 2.0 execution engine (if prioritised)  
- Additional Marketplace connectors (LeanIX, Ardoq, MEGA HOPEX, Bizzdesign Horizzon)  
- TBM-grade IT financial management integration  
- Code-level APM (source scanning, CAST-style assessments)  
- Mobile companion app for ARB approvals

# 9\. Appendices

## Appendix A — Sprint Goal Summary

| Sprint | Weeks | Phase | Headline |
| :---- | :---- | :---- | :---- |
| P1.S1 | 1–2 | Foundation | Scaffolding, metamodel, audit log |
| P1.S2 | 3–4 | Foundation | Capability backend \+ UI skeleton |
| P1.S3 | 5–6 | Foundation | Capability map viz \+ value streams |
| P1.S4 | 7–8 | Foundation | Application portfolio (APM) |
| P1.S5 | 9–10 | Foundation | Technology radar \+ info domains |
| P1.S6 | 11–12 | Foundation | Command centre \+ CBN baseline \+ stabilise |
| P2.S1 | 13–14 | Integration & Governance | Interface catalogue \+ API register |
| P2.S2 | 15–16 | Integration & Governance | Blast radius \+ L1 data flows |
| P2.S3 | 17–18 | Integration & Governance | Security zones \+ control mapping |
| P2.S4 | 19–20 | Integration & Governance | Process inventory \+ BCM linkage |
| P2.S5 | 21–22 | Integration & Governance | ARB \+ principles \+ standards |
| P2.S6 | 23–24 | Integration & Governance | Exceptions \+ Copilot \+ stabilise |
| P3.S1 | 25–26 | Analytics, Roadmap, Marketplace | Plateaux \+ initiatives |
| P3.S2 | 27–28 | Analytics, Roadmap, Marketplace | Roadmap viz \+ ADM tracker |
| P3.S3 | 29–30 | Analytics, Roadmap, Marketplace | Reference patterns \+ solutions |
| P3.S4 | 31–32 | Analytics, Roadmap, Marketplace | Full analytics \+ KRI publication |
| P3.S5 | 33–34 | Analytics, Roadmap, Marketplace | ArchiMate exchange \+ Regulatory Intelligence |
| P3.S6 | 35–36 | Analytics, Roadmap, Marketplace | CBN evidence pack \+ hardening \+ go-live |

## Appendix B — RACI on Major Deliverables

R \= Responsible, A \= Accountable, C \= Consulted, I \= Informed

| Deliverable | Tech Lead | PM | Backend | Frontend | Data/Graph | QA | UX | DevOps | Security | Client Arch | Client CISO |
| :---- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| Metamodel schema | A | I | R | I | R | C | I | I | C | C | I |
| Capability map | A | R | R | R | C | C | C | I | I | C | I |
| Application portfolio | A | R | R | R | C | C | C | I | I | C | I |
| Technology radar | A | R | R | R | C | C | C | I | I | C | I |
| Interface catalogue | A | R | R | R | C | C | C | I | I | C | C |
| Security zones | A | R | R | R | C | C | C | I | R | C | R |
| ARB workflow | A | R | R | R | I | C | C | I | C | R | C |
| CBN evidence pack | A | R | R | R | C | C | I | I | C | R | C |
| Production go-live | R | A | R | R | R | R | I | R | R | C | C |

## Appendix C — Related Atheris Documents

| Document | Relationship |
| :---- | :---- |
| ATH-TRD-EA-001 v1.0 | Parent technical requirements |
| ATH-BRD-NEXUS-001 | Parent platform BRD |
| ATH-TRD-NEXUS-001 v3.0 | Parent platform TRD |
| ATH-DS-AEGIS-001 | Design system tokens and components |
| ATH-TRD-CBNCSAT-001 | Sibling CBN-CSAT module (dependency) |
| ATH-TRD-AUDITPRO-003 | Reference TRD for stack alignment |
| ATH-UX-EA-001 (pending) | Wireframes and visual specs |
| ATH-API-EA-001 (pending) | OpenAPI 3.1 contract |

---

**End of Document**  
