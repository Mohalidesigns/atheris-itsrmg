# ATH-RPT-001 — Atheris ITSRMG: Module Audit, Fix Report & Market Benchmark

**Version:** 1.0 · **Date:** 10 July 2026 · **Scope:** Full application audit, remediation, RBAC build-out, cross-module integration, and competitive benchmark for the Nigerian market.

Companion document: `docs/EA-Module-Commercial-Bank-Playbook.md` (ATH-PLB-EA-001) — the Enterprise Architecture deep-dive.

---

## 1. Executive Summary

The platform was audited end-to-end: 46 controllers, 137 models, 204 Inertia pages, 285+ web routes, 138 menu links, all seeders, and both test suites. The audit found a strong core (Risks, Controls, Assets, Policies, CSAT, EA) surrounded by three systemic problems, all of which have now been remediated in this engagement:

1. **RBAC existed on paper but was never enforced.** 168 permissions and 7 roles were seeded, yet not a single route, controller, or menu item checked them — every authenticated user had full access — and there was no UI to manage users, roles, or permissions. Additionally, 46 of 49 seeded users had no role at all, which is why the roles area appeared blank. **Fixed:** full admin UI, route-level enforcement, gated navigation, role auto-assignment.

2. **A dozen modules were façades.** Threats, Security Alerts, Vulnerability Tickets, Response Procedures, Vendor Assessments, Policy Attestations, and Business Assets rendered static empty-state pages while their models and seeded data sat unused. Several core registers were missing CRUD operations, including a registered delete route with no implementation that guaranteed a 500 error. **Fixed:** all seven wired to real data with full CRUD; all missing operations implemented.

3. **Modules didn't talk to each other.** Assets were an island with no relationships to risks, vulnerabilities, or incidents; the Risk↔Control link only existed in one direction; the EA module had no foreign keys into the core GRC domain. **Fixed at the core layer:** asset↔risk and asset↔vulnerability pivots, bidirectional risk↔control, alert↔incident and ticket↔vulnerability surfaced in the UI, plus a cross-link demo seeder. EA-to-core integration is specified as the P1 roadmap in the EA playbook.

The application now boots from an empty database to a fully seeded, role-enforced, interconnected demo (`migrate:fresh --seed` passes cleanly — a seeder crash that previously broke fresh installs was also fixed), all 25 PHPUnit tests pass, the Vite build succeeds, and a new smoke test verifies 167 GET routes return no server errors.

---

## 2. What Was Found and Fixed

### 2.1 Roles, Permissions & Users

| Finding | Severity | Resolution |
|---|---|---|
| Zero enforcement: no `permission:`/`role:` middleware, no `Gate` or `authorize()` calls anywhere | Critical | Spatie middleware aliases registered; every module route group now guarded (`permission:view <module>`); write routes carry create/edit/delete/approve permissions; Super Admin bypass via `Gate::before` |
| No management UI for users/roles/permissions (Settings→Users was read-only) | Critical | New `/admin` area: full user CRUD (search, role filter, role sync, self-delete/self-deactivate guards) and full role CRUD with a 32-module × 6-action permission matrix (select-all by row/column/group). Guarded by `view/create/edit/delete users/roles` |
| 46 of 49 seeded users had no role ("blank" roles symptom) | Critical | Kano Heritage & First Bank seeders now auto-assign roles by job title (CISO→Org Admin, audit/board→Auditor, risk→Risk Manager, compliance/DPCO/policy/ISMS/PCI→Compliance Officer, SOC/incident→Security Analyst, others→Viewer). Verified: 0 users without roles |
| EA/CSAT/platform routes had no permission vocabulary | High | Seeder extended with `ea`, `csat`, `platform`, `roles` modules → 192 permissions; sensible grants per role |
| Bare 403s on denial | Medium | Friendly Inertia `Error` page rendered for 403s |
| Registration assigned a role that may not exist (broke tests/fresh installs) | Medium | `Role::findOrCreate` on register; stale Breeze test updated |

### 2.2 CRUD Completeness

| Module | Was | Now |
|---|---|---|
| Vulnerabilities | `destroy` route registered with **no method** (guaranteed 500) | `destroy()` implemented; invalid status options on Edit fixed |
| Incidents | No edit/update/destroy (Show page linked a non-existent edit route → crash) | Full resource; status changes log an `IncidentEvent` |
| Data Breaches | No edit/update/destroy; store redirected to a non-existent route; broken route-model binding silently injected an empty model | Full resource; both pre-existing bugs fixed |
| Risk Assessments | Could not even open a record (no show) | show/edit/update/destroy + pages |
| Risk Treatments | index/create/store only | show/edit/update/destroy + pages |
| Vendors | No destroy | destroy with guard (blocked while assessments exist) |

### 2.3 Stub Modules Brought to Life

All seven now query real seeded data with search/filter/pagination and full CRUD, guarded by matching permissions, with pages following existing app conventions:

Threats (register of 130 threats, THR-#### codes, assess-against-risk workflow) · Security Alerts (80 alerts, severity/status filters, **promote-to-incident** action) · Vulnerability Tickets (enforced status transitions, SLA/overdue highlighting) · Response Procedures (steps + escalation contacts) · Vendor Assessments (65 records, vendor picker) · Policy Attestations (read-only register of 1,280 records + KPI cards; writes stay on the existing attest flow to avoid duplication) · Business Assets (16 business services, RTO/RPO, criticality, delete guards).

Still intentionally stubs (no models exist; candidates for a future phase): Question Library, Control Standards, Change Requests, Policy Exceptions.

### 2.4 Cross-Module Integration

- `Risk::controls()` inverse relation added; Risk Show tabs now render real linked controls and assets instead of demo fallbacks.
- New `asset_risk` and `asset_vulnerability` pivot tables; "Linked Assets" multiselects on Risk and Vulnerability forms; linked risks/vulnerabilities panels on Asset Show.
- Incident↔Alert and Vulnerability↔Ticket links surfaced on Show pages (alerts were loaded but never rendered before).
- New `DemoCrossLinkSeeder`: 48 asset↔risk, 44 asset↔vuln, 48 risk↔control links, plus first-ever rows for tickets, procedures, assessments, and treatments.
- EA remains architecturally separate by design of the original build; the integration map and P1 build order to break that isolation (EOL→Risk bridge, real Control FKs, EA App↔Asset hardening, BIA linkage) are specified in the EA playbook §4 and §8.

### 2.5 Orphan / Dead Code

- Deleted superseded seeders: `RiskCategorySeeder`, `NigerianBankDemoSeeder`.
- Wired previously-orphaned seeders into `DatabaseSeeder`: `RegulatoryFrameworkSeeder` (NDPA/CBN/ISO frameworks + 180 requirements that had never seeded), `CsatMaNarrativesTemplateSeeder`.
- Fixed fresh-install crash: `AtherisPlatformSeeder::tprm()` called `->random()` on an empty vendor collection; now self-seeds 5 Nigerian ecosystem vendors (Interswitch, NIBSS, MainOne, Temenos partner, Galaxy Backbone) when run before the demo seeders.
- Menu audit: all 138 menu links resolve to real routes; no orphan pages (every page is rendered by a controller); no unrouted controllers.

### 2.6 Verification

- `php artisan migrate:fresh --seed --force` — passes end-to-end (previously crashed).
- PHPUnit: **25/25 passing**.
- New `RouteSmokeTest` (run with `SMOKE=1` against a seeded DB): **167 authenticated GET routes, zero 5xx**.
- `npx vite build` — succeeds; all ~35 new pages in the manifest.
- Permission checks verified at HTTP level: Viewer blocked from `/risks/create` and `/admin/*` (403 with friendly page), Org Admin allowed, Risk Manager sees EA but not PCI/admin.

**Local steps to pick everything up:** `php artisan migrate:fresh --seed` · `npm install --legacy-peer-deps && npm run build` (the `--legacy-peer-deps` flag is needed due to a pre-existing vite/plugin-react peer conflict). Note: demo data lives under the Kano Heritage and First Bank organizations — log in as e.g. `admin@kanoheritage.ng` / `password` to see populated registers; `admin@acme.ng` belongs to an intentionally empty org.

---

## 3. Competitive Benchmark (July 2026)

### 3.1 The global field

Gartner published its first **Magic Quadrant for GRC Tools, Assurance Leaders** (Oct 2025); Forrester followed with **The Forrester Wave: GRC Platforms, Q2 2026**. The market direction both describe: continuous, AI-driven GRC replacing periodic assessments. Reference points per capability:

| Capability | Best-in-class | What they do that defines the bar |
|---|---|---|
| ERM / risk register | Archer, IBM OpenPages | Bank-grade taxonomies; Archer claims 38 of the top 50 global banks; OpenPages ships agentic AI for compliance applicability |
| Risk quantification | Archer Insight (RiskLens/FAIR), LogicGate Quantify, AuditBoard/Optro | Monte Carlo, loss-exceedance curves, scenario planning (Optro scored highest in the Wave) |
| Compliance automation / CCM | Vanta, Drata, ServiceNow+TrustCloud | ~Hourly automated control tests, 270–400+ integrations, evidence auto-collection up to 90% |
| TPRM | ProcessUnity (led Forrester TPRM Wave Q1 2026), Drata VRM agent | Dynamic questionnaire scoping; AI agents that chase vendor evidence autonomously |
| Policy | Vanta AI policy agent, MetricStream | AI-drafted policies/questionnaire answers with ~95% acceptance |
| Incident + asset-linked risk | ServiceNow IRM | Now Assist agents continuously assess controls and propose remediation; CMDB linkage |
| Board reporting | Diligent One | AI-generated board-material summaries; acquired 3rdRisk (AI TPRM) Jan 2026 |
| Privacy | OneTrust | Forrester privacy Leader; consent/data-mapping depth |
| Workflow configurability | LogicGate Risk Cloud | No-code graph workflow builder — "farthest right" in the 2025 MQ |

**EA tools** (relevant to the EA module): SAP LeanIX (obsolescence risk management, Technology Risk & Compliance), Ardoq (graph EA with an explicit GRC pattern and DORA guidance), Bizzdesign+MEGA (EA-driven operational resilience). Critically, **no global GRC leader ships native EA, and no EA leader ships full GRC** — Atheris having both in one product is a genuine structural differentiator.

### 3.2 Where Atheris already matches the leaders

Module-for-module breadth is real: risk register + assessments + treatments + KRIs + **FAIR quantification** (rare below enterprise price points); frameworks/evidence/gap analysis/ISMS/PCI; TPRM; incidents/breaches; policies/attestations; BCP/DR/BIA; audit trail; workflows; **board packs** (Diligent's headline use case); **native EA**; and **CBN CSAT + regulatory returns — which no global vendor has at all.**

### 3.3 Where the leaders are ahead (build next, in order)

1. **Real integrations for CCM/evidence** — connectors (Entra/AD, M365, AWS/Azure, EDR, core banking middleware: Finacle, Flexcube, T24) with deterministic control tests. Today's CCM runs, SIEM signals, drift and access-review data are seeded/synthetic (`rand()`), and several platform writes hardcode `tenant_id = 1` — the single biggest gap between demo and production.
2. **Agentic AI** — the copilot currently returns canned strings. The 2026 bar is agents that do work: map controls across frameworks, pre-fill CSAT answers from evidence, draft risk treatments, summarize incidents into board packs.
3. **Quantification depth** — extend FAIR with Monte Carlo and loss-exceedance curves.
4. **AI governance module** — a new scoring criterion in both analyst evaluations; increasingly relevant as Nigerian banks deploy AI.

### 3.4 The Nigerian wedge (what makes Atheris the best choice locally)

Regulatory context: CBN Risk-Based Cybersecurity Framework (annual CISO self-assessment due 31 March); the **March 2026 CSAT circular** mandating portal submissions for all regulated FIs (DMBs had 3 weeks); **NDPA/GAID 2025** — banks are "Ultra High Level" data controllers with CAR returns due 31 March and 72-hour breach notification (penalty up to 2% of gross revenue); the **Jan 2026 sub-30-minute fraud-response directive** with vendor-concentration and operational-resilience scrutiny; recapitalization-driven governance tightening. Nigerian Tier-1 banks currently run MetricStream/SAP GRC (expensive, heavily customized); USD per-seat pricing from Vanta/Drata-class tools is described locally as "brutal math."

The winning plays, none of which global vendors will build:

1. **CBN returns automation as the killer feature** — evidence-linked CSAT auto-population, one-click annual self-assessment in CBN format, CISO/executive sign-off chain, validation packs for CBN off-site reviews. (The CSAT module is already the most complete secondary module in the app — this is an extension, not a rebuild.)
2. **Maintained Nigerian regulatory content library** — CBN framework (DMB/PSB + OFI), NDPA/GAID with CAR generation and 72-hour breach timers, cybersecurity-levy logic, AML automated-detection framework (18-month clock), mapped to ISO 27001/PCI/NIST crosswalks; regulatory-intel ingestion of circulars auto-mapped to controls.
3. **NGN flat-tier pricing + Nigerian data residency** (MDXi/Rack Centre/Galaxy Backbone or on-prem for boards that demand it).
4. **EA-GRC convergence tuned to CBN supervision** — the dependency chain (capability→service→process→application→technology→vendor) answering exactly what the operational-resilience scrutiny asks; feeds CSAT third-party/resilience sections automatically. See the EA playbook.
5. **Fraud/incident SLA workflows** — timer-driven flows with CBN/NDPC/ngCERT notification templates for the 30-minute directive; trivial to build, high perceived value.

**Positioning:** "MetricStream breadth + Vanta automation + LeanIX EA, purpose-built for CBN supervision" — with connectors and agentic AI as the two global-parity investments.

---

## 4. Remaining Roadmap to Market-Ready

**P1 (production blockers):** replace synthetic data paths (Monitoring drift/access-reviews, PCI maturity/SAQ, CCM/FAIR/board-pack writes) with real logic; eliminate hardcoded `tenant_id = 1` across `PlatformController`; first real connectors (Entra ID, M365, one EDR, one core-banking file/API feed); EA↔core bridges per EA playbook P1; feature tests for the new admin/CRUD surfaces (only auth/profile + smoke are covered today).

**P2 (competitive parity):** real LLM copilot with tool access (the schema — conversations/messages/modes — is already right); CSAT auto-population from evidence vault; NDPA CAR return generation; Monte Carlo on FAIR; models + CRUD for the four remaining stub modules.

**P3 (differentiation):** marketplace with local audit-firm/DPCO content; NIBSS/payments connectors; local threat-intel feeds; AI governance module; multi-bank benchmarking analytics (anonymized cross-tenant risk posture percentiles — data residency permitting).

---

*Prepared as part of the July 2026 audit-and-remediation engagement. All code changes referenced are in this repository; verification steps are reproducible via the commands in §2.6.*
