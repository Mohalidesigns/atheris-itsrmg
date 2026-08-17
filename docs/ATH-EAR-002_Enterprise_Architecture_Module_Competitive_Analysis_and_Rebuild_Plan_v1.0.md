# Enterprise Architecture Module — Competitive Teardown, Root-Cause Diagnosis and Rebuild Plan

**Document ID:** ATH-EAR-002
**Version:** 1.0
**Date:** 16 August 2026
**Product:** Atheris ITSRMG — Enterprise Architecture module (`EA-Studio`)
**Owner:** Product & Engineering
**Classification:** Atheris Confidential

**Supersedes / extends:**
- `ATH-GAP-EA-001` v1.0 (15 May 2026) — the original gap analysis. Its P0 finding ("no write path exists") has been *half* remediated: the backend write path was built, the frontend was not. §2 of this document restates the current position with fresh evidence.
- `ATH-PLB-EA-001` v1.0 (10 July 2026) — the Commercial Bank Playbook. §2.11 of that document asserts "CRUD is complete for the seven core catalogues." **That statement is incorrect at the user-visible layer** and §2.1 below shows why.
- `ATH-RPT-001` v1.0 (10 July 2026) — the platform audit. Its §2.1 asserts "write routes carry create/edit/delete/approve permissions." **This is not true of the EA route group** and §2.2 below shows why.

---

## 0. Reading Guide

This document answers four questions in order:

1. **Why is the EA module idle?** (§2) — a code-level root-cause diagnosis, not an opinion. Every claim is reproducible from the repository with the commands in §13.
2. **What do the global competitors actually ship, and where are they weak?** (§3–§4) — a teardown of Ardoq, SAP LeanIX, OrbusInfinity, the consolidated Bizzdesign group (Horizzon + HOPEX + Alfabet), Avolution ABACUS and Essential, benchmarked against the 2025 Gartner Magic Quadrant and the Forrester EAMS Wave.
3. **What do we remove, what do we keep, and what do we build instead?** (§5) — a keep/kill/merge/build decision on every existing surface, and the resulting module structure.
4. **What is the massive Nigerian/African feature, and how does the rest of the suite plug into it?** (§6–§9) — the wedge, the cross-module integration contracts, the data model changes and a phased plan.

Three framing statements before the detail.

**First: the module is not under-built, it is un-wired.** The metamodel is genuinely strong — 48 tables, 46 domain models, 20 services, a generic relationship graph with a validator. The backend exposes 44 write endpoints. The problem is that **30 of those 44 endpoints have no caller anywhere in the frontend**, the RBAC policy checks permission names that were never created, and the navigation presents 40 sibling links with no hierarchy. A user opens the module, sees tables of seeded data, and finds nothing to click. That is precisely the reported symptom.

**Second: parity with LeanIX and Ardoq is the wrong target.** Those products win on two mechanics — automated inbound discovery and crowdsourced data freshness — that took them a decade and a partner ecosystem to build. Attacking them frontally with a Laravel monolith is a losing race. The 2025 Gartner MQ has six Leaders; the market is commoditising on core EA and, in Forrester's phrasing, *"transformation-enabling use cases have become the differentiator as traditional EA practices become commoditized."* The defensible position is a different one, set out in §6.

**Third: Nigeria's regulator has already specified the artefact.** The CBN IT Standards Blueprint **names TOGAF 9.2**, sets **target architecture maturity levels by institution category** (Level 3 for Category One FSIs, Level 2 for Category Two), and provides for **formal assessment by the IT Standards Governance Council**. No other market in the world hands a vendor that mandate. Combined with the **1 January 2027 payment-data localisation deadline** and the **NDPC Compliance Audit Return**, there are three regulator-imposed forcing functions that an EA repository — and only an EA repository — can answer mechanically. That is the product.

---

## 1. Executive Summary

### 1.1 The one-line verdict

**The EA module is a complete backend with an amputated frontend, a broken authorisation layer, and no user who is allowed to be an architect.** It is roughly **70% built and 15% usable.** Fixing usability is a 3–4 sprint job, not a rebuild; the strategic work — the thing that makes it beat the competitors — is a separate and larger effort described in §6.

### 1.2 The three root causes, quantified

| # | Root cause | Evidence | Severity |
|---|---|---|---|
| **RC-1** | **Orphaned write surface.** The controller and routes expose full CRUD; the React pages never call it. | **44** write endpoints in the `ea.` route group; **30 have zero references anywhere in `resources/js`** (68%). **27 of 46 EA pages contain no `<input>`, `<button>`, `onClick`, `useForm` or `router.post` at all.** Mean page size 66 LOC. | **Critical** |
| **RC-2** | **Dead-on-arrival RBAC.** The EA policy checks permissions that do not exist, falls back to roles that do not exist, then default-allows everyone — and is never registered or invoked anyway. Meanwhile every write route is gated only by `permission:view ea`. | `EaPolicy` checks `ea.view` / `ea.write` / `ea.approve`; the seeder creates `view ea` / `create ea` / `edit ea`. Fallback checks `hasRole('admin')` and `hasRole('architect')` — neither role exists. Final line: `return $user->id > 0`. **`EaPolicy` is never registered** — no `AuthServiceProvider` mapping, no `Gate::policy()` call, no `#[UsePolicy]` attribute anywhere in `app/`, `bootstrap/` or `config/`. `EaController` contains **0** calls to `authorize()`, `Gate::` or `->can(`. **0 of 44** EA write routes carry their own permission middleware. | **Critical** (both a usability *and* a security defect) |
| **RC-3** | **No architect persona and no navigable IA.** There is no "Enterprise Architect" role, and the module presents **40 flat sidebar links** under one heading. | `RolesAndPermissionsSeeder` defines 7 roles; only Super Admin and Organization Admin receive `create ea`. Risk Manager gets `view ea` only. Compliance Officer and Security Analyst get **nothing**. `navigation.js` lines 277–316: 40 sibling entries, no grouping. | **High** |

Two secondary causes compound these:

| # | Cause | Evidence |
|---|---|---|
| **RC-4** | **No way to get data in.** One CSV bulk-import page; three "sync" buttons that are unreachable from the UI (`ea.sync.assets`, `ea.sync.eol`, `ea.sync.cve` are all orphaned) and which fall back to bundled fixtures (~17 products, 7 CVEs) when offline. No surveys, no discovery, no CMDB pull, no email-based data collection. | `BulkImport.jsx` is the only ingestion page; `EolFeedClient` / `CveFeedClient` fixture fallbacks. |
| **RC-5** | **Tenancy and model duplication.** EA tables use `tenant_id`; every core GRC table uses `organization_id`. Both resolve to `users.organization_id` at runtime, but joins and future extraction are hazardous. Separately the platform ships **two business-architecture models**: `Ea\Capability` / `Ea\Process` / `Ea\ValueStream` **and** core `BusinessCapability` / `BusinessProcess` / `BusinessService`. | 48 EA tables, 14 `tenant_id` declarations in phase-1 migration, 0 `organization_id`. |

### 1.3 What the competitors actually do that we do not

Stripped of marketing, a modern EA tool wins on **five mechanics**. We have zero of the first two, partial credit on the third and fourth, and a real position on the fifth:

| Mechanic | Reference implementation | Atheris today |
|---|---|---|
| **1. Automated inbound discovery** | LeanIX: six SaaS/shadow-IT discovery families (Defender for Cloud Apps, Entra ID, Netskope, Okta, WalkMe, Zscaler), GitHub/CI-CD microservice discovery, SBOM API, a **62k-product Reference Catalog**. Ardoq: AWS, Azure Resources, bidirectional ServiceNow, Entra ID, IT-Pedia. | **None.** Three unreachable sync buttons with fixture fallbacks. |
| **2. Crowdsourced freshness** | Ardoq **Broadcasts**: audiences computed by Gremlin graph query, recurring schedules, staleness triggers (`not updated in 6 months`), auto-reminders, delivered to unlimited users. LeanIX **Quality Seal**: a per-fact-sheet approval state machine with configurable 30/90-day auto-expiry, plus Subscriptions (Responsible/Accountable/Observer). | **None.** No survey object, no ownership subscription, no staleness state, no notification loop. Data goes stale the day after the seeder runs. |
| **3. Authorable visual models** | Orbus: live-sync Visio add-in. LeanIX Diagrams with a **timeline scrubber** that morphs current→target state. Ardoq: 16 view types + Perspectives. | `DiagramEditor.jsx` exists — but adds nodes via `window.prompt()` and creates edges by asking the user to type element IDs. It is a proof of concept, not an editor. |
| **4. Scenario / future-state modelling** | Ardoq **Scenarios**: Git-style branch, diff vs mainline, merge-back, drift tracking. Avolution ABACUS: multi-state scenarios with algorithmic cost/TCO/risk/ESG scoring. | `ScenarioComparer` + `Scenarios.jsx` (93 LOC, read-only). Plateaux exist as records but cannot be diffed. |
| **5. Regulator-native artefacts** | **Nobody.** Ardoq ships EU AI Act and DORA/GLBA/APRA-CPS230 patterns; LeanIX ships nothing regional. No vendor ships a CBN, NDPA or African artefact. | `CbnMaturity.jsx`, `EvidencePackGenerator` (hash-sealed), CSAT module adjacency. **This is the only axis where we lead, and it is the whole strategy.** |

### 1.4 The strategic reframe

Do not sell an EA tool. **Sell the regulatory architecture return, and ship an EA repository as the machine that produces it.**

The reasoning is in the market data. Nigerian EA practice is framework-and-consultant-led, not tool-led — the flagship regional architecture role (KPMG Enterprise/Solution Architecture Lead, Africa, Lagos, April 2026) treats TOGAF as *"preferred"* and names **zero EA tools**. There are ~49 enterprise architect roles open nationally. A blank-canvas ArchiMate modeller will not be adopted because there is nobody to adopt it. But every one of the 33 recapitalised banks has a CISO with a **28 February** CSAT deadline, a DPO with a **31 March** NDPC return, and a CIO with a **1 January 2027** data-localisation deadline — and none of them can currently answer *"where does each system physically run, whose data does it hold, who operates it, and what breaks if it stops."*

That question is an EA query. Make the returns the product; the repository is the by-product. This also solves EA's oldest problem — repositories go stale — because a filing deadline maintains data in a way no governance policy ever has.

### 1.5 Recommended shape of the work

| Phase | Duration | Outcome |
|---|---|---|
| **Phase 0 — Make it usable** | 2 sprints | Wire the 30 orphaned endpoints, fix RBAC, add the Architect role, restructure 40 nav links into 7 workspaces. The module becomes *demonstrable*. |
| **Phase 1 — Make it stay true** | 3 sprints | Ownership/subscription model, survey + campaign engine, quality-seal freshness state machine, scheduled feeds with provenance labelling. The repository becomes *maintainable*. |
| **Phase 2 — Fuse it to the suite** | 3 sprints | 14 cross-module integration contracts (EOL→Risk, Control FKs, BIA↔Process, DPIA↔Breach, EA-KRI→board packs, ARB→Issues/Workflow). The graph becomes *the platform's* graph. |
| **Phase 3 — The African wedge** | 4 sprints | Regulatory Architecture Return Engine, Data Residency Control Tower, FX Exposure lens, Vendor Concentration, Power/Site Resilience, Multi-entity multi-regulator, Nigerian Banking Reference Architecture pack. The module becomes *unmatchable*. |
| **Phase 4 — Depth and scale** | 4 sprints | Real diagram editor, n-hop impact, scenario diff, ArchiMate round-trip gate, performance at 5,000 nodes, test coverage. The module becomes *enterprise-grade*. |

§9 breaks each into workstreams with exit criteria.

---

## 2. Root-Cause Diagnosis — Why the Module Is Idle

Everything in this section is drawn from the repository at the state committed on this machine. §13 gives the commands to reproduce each figure.

### 2.1 RC-1 — The orphaned write surface

The backend is not the problem. `app/Http/Controllers/EaController.php` is **1,014 lines** with **96 public methods**, including `capabilityStore`, `capabilityUpdate`, `capabilityDestroy`, `applicationStore/Update/Destroy`, `techStore/Update/Destroy`, `interfaceStore/Update/Destroy`, `principleStore/Update/Destroy`, `standardStore/Update/Destroy`, `arbStore`, `arbDecide`, `exceptionStore`, `exceptionRenew`, `diagramStore/Update/Destroy`, `dpiaStore`, `glossaryStore`, `motivationStore`, `threatStore`, `bulkImport` and more. Ten FormRequest classes exist under `app/Http/Requests/Ea/`. A repository, a policy and an audit logger exist.

The frontend never calls most of it.

**The count.** The `ea.` route group declares **51 GET routes and 44 write routes** (POST/PUT/DELETE). Searching the entire `resources/js` tree for each write route name:

| | Count | Share |
|---|---|---|
| Write endpoints **called** from the frontend | 14 | 32% |
| Write endpoints **with no caller anywhere** | **30** | **68%** |

The orphan register in full:

```
ea.applications.store            ea.applications.update           ea.applications.destroy
ea.capabilities.store            ea.capabilities.update           ea.capabilities.destroy
ea.tech.store                    ea.tech.update                   ea.tech.destroy
ea.interfaces.store              ea.interfaces.update             ea.interfaces.destroy
ea.principles.store              ea.principles.update             ea.principles.destroy
ea.standards.store               ea.standards.update              ea.standards.destroy
ea.exceptions.store              ea.exceptions.renew              ea.diagrams.destroy
ea.initiatives.dependencies.store  ea.initiatives.deliverables.store  ea.deliverables.update
ea.kri.recompute                 ea.threats.techniques.store      ea.mcp.rpc
ea.sync.assets                   ea.sync.eol                      ea.sync.cve
```

This is the single most important table in the document. **Application Portfolio Management is the flagship EA use case in every competitor product, and a user of Atheris EA cannot add, edit or delete an application.** Same for capabilities, technology components, interfaces, principles, standards and exceptions — the seven catalogues the playbook described as "CRUD complete."

**The page anatomy.** All 46 pages under `resources/js/Pages/Ea/` total **3,070 lines** — a mean of **66 lines per page**. For comparison, `Pages/Risks/Show.jsx` alone is 25,712 bytes and `Pages/SecurityOps/Incidents/Show.jsx` is 24,181 bytes. EA pages are one-third the size of a working module's *single* detail page.

**27 of the 46 pages contain no interactive element of any kind** — no `<input>`, `<select>`, `<textarea>`, `TextInput`, `<button>`, `onClick`, `useForm`, or `router.post/put/delete`:

```
AdmTracker  Apis  Arb  CapabilityMap  CapabilityShow  CbnMaturity  CommandCentre
ControlMappings  DataFlows  Exceptions  InfoDomains  Initiatives  Interfaces  Kri
LogicalEntities  Mcp  Patterns  Plateaux  Principles  Processes  SecurityZones
Solutions  Standards  TechnologyRadar  ValueStreams  VendorConcentration  Viewpoint
```

`CapabilityMap.jsx` is the clearest illustration. It renders a recursive capability tree, links each node to a detail page, shows four KPI cards — and has no "Add capability" button, no inline edit, no delete, no drag-to-reparent, despite `ea.capabilities.store`, `.update` and `.destroy` all existing and being validated by `CapabilityRequest`. `Processes.jsx` renders an RTO/RPO table with no way to enter an RTO. `Standards.jsx` and `Principles.jsx` are catalogues of governance objects that cannot be governed.

**Where interaction does exist, it is thin.** `DiagramEditor.jsx` is the module's most-cited differentiator. Its "add element" handler is:

```js
const label = prompt(`Name for new ${type}`) || type;
```

and its "connect" handler asks the user to type a source element ID, a target element ID, and a relationship name into three sequential `window.prompt()` dialogs. It is a demo, and it will be seen as one.

**Verdict on RC-1.** The `ATH-GAP-EA-001` P0 item *"build a generic write surface"* was implemented server-side and never surfaced client-side. The fix is not architectural — it is roughly 30 forms, 7 index-page action bars and 6 detail pages. It is the highest-return work in this document.

### 2.2 RC-2 — Dead-on-arrival RBAC

Three independent defects compound.

**Defect A — the policy checks permissions that do not exist.**

`app/Policies/Ea/EaPolicy.php` checks:

```php
public function create(?User $user = null): bool { return $this->grant($user, 'ea.write'); }
```

`RolesAndPermissionsSeeder` creates permission names of the form `"{$action} {$module}"` — i.e. `view ea`, `create ea`, `edit ea`, `delete ea`, `approve ea`, `export ea`. **The strings `ea.view`, `ea.write`, `ea.approve` and `ea.admin` are never created.** `$user->can('ea.write')` is therefore always false.

**Defect B — the fallback roles do not exist, and the final fallback grants everyone.**

```php
if ($user->hasRole('admin') || $user->hasRole('architect')) return true;
...
// Default-allow authenticated users while the Spatie roles are being seeded.
return $user->id > 0;
```

The seeded roles are `Super Admin`, `Organization Admin`, `Risk Manager`, `Compliance Officer`, `Security Analyst`, `Auditor`, `Viewer`. Neither `admin` nor `architect` exists. Execution therefore always reaches the default-allow line. The policy grants every authenticated user every EA permission.

**Defect C — the policy is never registered, never called, and the routes are gated on read permission only.**

`EaPolicy` is **not registered anywhere**. Grepping `EaPolicy` across `app/`, `bootstrap/` and `config/` returns hits only inside the policy file itself — there is no `AuthServiceProvider` mapping, no `Gate::policy()` call and no `#[UsePolicy]` attribute. Separately, `EaController` contains **zero** calls to `authorize()`, `Gate::` or `->can(`. The policy is doubly dead: even its default-allow branch never executes. The only enforcement is the route-group middleware:

```php
Route::prefix('ea')->name('ea.')->middleware('permission:view ea')->group(function () {
```

**Zero of the 44 write routes carry their own middleware.** Compare the risks module, which does it correctly:

```php
->middlewareFor('destroy', 'permission:delete risks');
```

**Consequence.** `view ea` is granted to Viewer and Auditor. A user with the **Viewer** role can `POST /ea/applications`, `PUT /ea/capabilities/{id}` and `DELETE /ea/tech-components/{id}`. The audit report `ATH-RPT-001` §2.1 states *"write routes carry create/edit/delete/approve permissions"* — this is true of the modules it remediated and **not true of the EA group**, which appears to have been added to the permission vocabulary without being gated.

This is a release blocker independent of the usability work. It should be fixed in Phase 0.

### 2.3 RC-3 — No architect persona, no navigable information architecture

**Nobody in the system is an architect.** The role matrix as seeded:

| Role | EA permissions granted |
|---|---|
| Super Admin | all |
| Organization Admin | all |
| Risk Manager | `view ea` |
| Compliance Officer | *none* |
| Security Analyst | *none* |
| Auditor | `view ea`, `export ea` |
| Viewer | `view ea` |

There is no Enterprise Architect, Solution Architect, Domain Architect, Application Owner or Data Steward. The people who would populate and maintain an architecture repository have no seat. Meanwhile the people who *do* have write access — Super Admin and Org Admin — are precisely the people who should not be doing data entry.

This matters more here than in other modules because EA is a **crowdsourced** discipline. LeanIX's entire freshness model rests on Subscriptions with `Responsible / Accountable / Observer` roles per fact sheet; Ardoq's rests on `Owns` and `Is Expert In` references between Person and component. Atheris has **no Person↔object ownership concept at all** in the EA schema. Ownership is a free-text field, not a relationship.

**The navigation is unusable.** `resources/js/Config/navigation.js` lines 277–316 declare **40 sibling links** under a single "Enterprise Architecture" heading: Command Centre, Capability Map, Value Streams, Application Portfolio, Technology Radar, Information Domains, Logical Entities, Data Flows, CBN EA Maturity, Interfaces, API Register, Blast Radius, Security Zones, Control Mappings, Process Inventory, Principles, Standards, ARB, Exceptions, Vendor Concentration, Plateaux, Initiatives, Roadmap, ADM Phase Tracker, Reference Patterns, Solutions, EA KRIs, ArchiMate Exchange, CBN Evidence Packs, Diagrams & Viewpoints, Motivation Layer, DPIA & Privacy, Business Glossary, Threat Models, Anomaly Inbox, Scenario Compare, NL Search, MCP Server, Bulk Import, Audit Trail.

Every competitor organises around **use cases**, not around metamodel entities. Ardoq ships three outcome suites (Visibility / Transformation / Oversight) and ~20 packaged "Best Practice Modules." LeanIX ships three (APM / Technology Risk & Compliance / Architecture & Road Map Planning). Orbus ships four commercial modules. **Nobody exposes a menu item called "Logical Entities."** §5.5 restructures this into 7 workspaces.

### 2.4 RC-4 — There is no way to get data in

An EA repository's hardest problem is population and freshness. The current inbound paths are:

| Path | State |
|---|---|
| `BulkImport.jsx` → `ea.bulk-import.run` | Works. Single CSV upload per entity type. The only functioning ingestion route. |
| `ea.sync.assets` → `AssetApplicationClient::syncIntoEa()` | **Orphaned** — no UI caller. Reads the local `assets` table behind a `Schema::hasTable` guard. One-way, manual, unscheduled. |
| `ea.sync.eol` → `EolFeedClient` | **Orphaned.** Falls back to a bundled fixture of ~17 products when offline, silently. |
| `ea.sync.cve` → `CveFeedClient` | **Orphaned.** Falls back to a 7-CVE fixture, silently. |
| Surveys / campaigns | **Do not exist.** |
| Discovery (IdP, CASB, cloud, CMDB, CI/CD) | **Do not exist.** |
| ArchiMate Open Exchange import | Exists (`ArchiMateExchange`), round-trip untested at scale. |

Three consequences. First, in a pilot the repository will be populated once by consultants and will be stale within a quarter — the exact failure mode that has killed EA programmes for twenty years. Second, the three sync buttons are unreachable, so even the manual path is closed. Third, the **silent** fixture fallback is a demo integrity risk: an evaluator pressing "Sync CVE" on an air-gapped UAT box receives seven fixture CVEs presented as live data. Feed provenance must be surfaced in the UI (`live feed` vs `bundled fixture`, with last-successful-fetch timestamp) before any bank sees it.

### 2.5 RC-5 — Metamodel and tenancy inconsistencies

**Tenancy naming split.** EA's 48 tables use `tenant_id` (47 occurrences) and **never** `organization_id`. `BelongsToTenant` resolves `tenant_id` from `Auth::user()->organization_id`, and `OrganizationScope` filters on `organization_id` — so the two are functionally the same value under different names.

The split is **not** strictly EA-versus-core: roughly eleven other newer-module migrations (issues, business services, CCM/KRI, SIEM/FAIR/vuln, marketplace/theming) also use `tenant_id`, while 33 older core migrations use `organization_id`. So this is an **old-core versus newer-modules** divergence that EA merely inherited — which makes it a platform-level cleanup, not an EA one, and slightly larger than it first appears. It works today. It will break the first time someone writes a cross-module join or extracts a module into a service. Standardise on `organization_id` with a migration and a compatibility accessor, platform-wide.

**Duplicate business-architecture model.** The platform ships two families:

| Concept | EA model | Core model |
|---|---|---|
| Capability | `App\Models\Ea\Capability` (`ea_capabilities`) | `App\Models\BusinessCapability` |
| Process | `App\Models\Ea\Process` (`ea_processes`) | `App\Models\BusinessProcess` |
| Service | `App\Models\Ea\ValueStream` / `Solution` | `App\Models\BusinessService` (+ `ServiceDependency`) |

Two capability trees in one product, shown to a bank, is the kind of finding a Big-4 assessor writes up. §7.2 resolves ownership: **EA owns the canonical business-architecture objects; the core `BusinessService`/`BusinessCapability` family becomes a read-through projection or is retired.** This is a product decision that must be taken before Phase 2, not during it.

**Unconstrained cross-module keys.** `ea_control_mappings.control_id` is an unconstrained bigint that `EaPhase2Seeder` populates with `rand(1,80)`. `ControlInheritanceService` then computes control posture over IDs that may not resolve. `ea_applications_ext.asset_id` and `.vendor_id` are similarly unconstrained. Any coverage figure the module currently produces is arithmetic over noise, and must not be shown to a regulator.

### 2.6 What is genuinely good and must be preserved

It would be wrong to read the above as "start again." The following are real assets:

- **The metamodel.** 48 tables spanning ArchiMate strategy, business, application, technology, motivation and implementation layers, plus a generic `ea_relationships` graph table with a `RelationshipValidator` (9,435 bytes) enforcing ArchiMate 3.2 relationship legality. Structurally this is the same shape as Ardoq's graph repository and it is better than LeanIX's fact-sheet model for our purposes.
- **The service layer.** 20 services including `BlastRadiusService`, `AnomalyEngine`, `KriCalculator`, `ControlInheritanceService`, `PrincipleImpactEngine`, `TechObsolescenceService`, `VendorConcentrationService`, `EvidencePackGenerator`, `ViewpointGenerator`, `ArchiMateExchange`, `McpServer`, `NlSearchService`. These are the hard parts and they exist.
- **The queued jobs.** Eight jobs under `app/Jobs/Ea/` — `RecomputeKris`, `RecomputeTechObsolescence`, `RecomputeVendorConcentration`, `RunAnomalyRules`, `SyncAssetCatalogue`, `SyncCveFeed`, `SyncEolFeed`, `ExpireExceptions`. The async spine is in place; it just is not scheduled.
- **The regulator-native features.** `CbnMaturity.jsx` self-assessment, hash-sealed `EvidencePackGenerator`, and adjacency to a full CBN CSAT module. **No global vendor has any equivalent.**
- **The MCP endpoint.** `McpServer` (7,767 bytes) — matching LeanIX's and Ardoq's early-2026 MCP moves at the API level, which for a product at this maturity is a striking parity point.

The rebuild plan preserves all of this. What it removes is the read-only page sprawl, not the engine underneath it.
---

## 3. The Competitive Landscape, August 2026

### 3.1 Three corrections to the brief

Before the teardown, three factual corrections that change the competitive picture:

1. **`enterprise-architecture.org` is not a body of knowledge — it is a competing product.** The domain belongs to **The Essential Project** (Enterprise Architecture Solutions Ltd), an EA platform available in open-source and commercial editions. It is a *price-floor* competitor, not a methodology source. The body of knowledge that brief probably intended is **IFEAD** at `enterprise-architecture.info` — Jaap Schekkerman's Institute For Enterprise Architecture Developments, publisher of E2AF, E2AMM and the EA Score Card. That content is largely dormant (early-2000s vintage) but §3.6 shows it contains a directly implementable asset we should steal.

2. **Bizzdesign, MEGA and Alfabet are now one company.** Main Capital-backed Bizzdesign acquired MEGA International and then Alfabet (from Software AG) across 2024–25. Three of the four Forrester Wave Q4 2024 Leaders are now the same vendor. Gartner notes the acquisitions grew Bizzdesign's customer base **sevenfold** — and cautions that *"managing multiple EA tools creates administrative complexity."* Products are being kept separate with individual roadmaps for 5–7 years, unified by a new AI layer called **Bizzdesign Unify** with MCP connectivity.

3. **iServer365 is legacy.** Orbus's current product is **OrbusInfinity**. iServer/iServer365 is the older SharePoint-era lineage still referenced in reviews. Orbus also acquired **Capsifi** to build out business architecture.

### 3.2 Analyst position — the scoring dimensions to build against

**Gartner Magic Quadrant for Enterprise Architecture Tools, 6 October 2025** — 15 vendors:

| Quadrant | Vendors |
|---|---|
| **Leaders (6)** | Ardoq · Avolution · Bizzdesign · BOC Group · Orbus Software · SAP LeanIX |
| **Challengers (2)** | GBTEC · ServiceNow |
| **Visionaries (4)** | ins-pi · QualiWare · ValueBlue · Bee360 |
| **Niche (3)** | Sparx Systems · UNICOM |

Leader strengths and cautions, verbatim in substance:

| Vendor | Strength | Caution |
|---|---|---|
| Ardoq | ~50% of revenue into product development | New **per-application pricing** may limit scope for large portfolios |
| Avolution | 100+ out-of-the-box frameworks | Higher-than-average **customer churn** |
| Bizzdesign | Acquisitions grew customer base 7× | **Managing multiple EA tools creates administrative complexity** |
| BOC Group | Straightforward, clear pricing | Substantial on-prem base may slow cloud innovation |
| Orbus | Comprehensive business architecture post-Capsifi | **~Half of customers require paid professional services** |
| SAP LeanIX | Exemplary support; **lowest paid-services usage** | **30% of roadmap dedicated to SAP-specific features** |

Notable challenger caution: **ServiceNow's base package lacks foundational EA features like modelling and roadmapping.** The platform-consolidation threat is real but currently shallow on core EA — which matters for us, because ServiceNow is the vendor most likely to be pitched to a Nigerian tier-1 bank as "you already own it."

**Gartner Critical Capabilities — the five canonical use cases.** These are the right skeleton for any parity assessment:

1. Enterprise Transformation & Portfolio Management
2. Advanced Roadmapping
3. Solution Architecture Design & Delivery
4. **Enterprise Architecture Governance**
5. Innovation & Sustainability

Avolution scored highest on **EA Governance** in 2025; Ardoq ranked #1 on Enterprise Transformation & Portfolio Management.

**Forrester Wave: Enterprise Architecture Management Suites, Q4 2024** — 12 vendors, 28 criteria. Leaders: **Orbus, MEGA, Bizzdesign, Software AG** (three now one company). Orbus scored highest Current Offering (3.92) and tied-highest Strategy (3.80), with **5/5 on Vision, Community, AI roadmap, low-code/no-code and integration ecosystem.** A successor **EAMS Landscape, Q4 2025** has since been published.

**Forrester's four defining capabilities of a modern EA suite** — this is the most useful single list in the analyst material:

1. **AI integration** — text recommendation engines, chatbots, smart agents, AI-assisted roadmapping
2. **Sustainability** — materiality assessment through carbon-footprint calculation of the IT estate
3. **Advanced modelling** — process modelling, **process mining**, business capability mapping, assessments
4. **Architect empowerment** — digital twins, democratisation, low-code/no-code, and **architecture decision records (ADRs)**

And the strategic quote to internalise: *"transformation-enabling use cases have become the differentiator as traditional EA practices become commoditized."*

### 3.3 Ardoq — the graph-native, AI-first challenger

**Packaging.** Three outcome suites, all in the base plan: **Business & IT Optimization** ("Visibility"), **Strategic Planning** ("Transformation"), **Governance, Risk & Compliance** ("Oversight"). Paid add-ons: Discover Sandbox, AI Process Modeling, Priority Support, extra production instances.

**Named modules.** Ardoq **Discover** (self-service portal for non-technical stakeholders, with a **My Tasks** queue) · **Surveys** · **Broadcasts** · **Scenarios** · **Presentations** (20+ templates, embeddable in Confluence/SharePoint) · **Perspectives** · **Viewpoint Builder** · **Graph Filters / Gremlin Graph Search** · **Calculated Fields** · Dashboards & Reports · **ShiftX / AI Process Modeling** · **AI Lens** · **AI Gateway / MCP Server** (with MCP SSO shipped 2026) · REST API + Webhooks.

**~20 packaged "Best Practice Modules,"** each with its own metamodel fragment, surveys, dashboards and viewpoints: Application Hosting · Application Integration Management · Application Rationalization · **Architecture Records** · Business Process Management · Business Value Stream · Capability Based Planning · Cloud Migration · Compliance Assurance · Data Lineage · Enterprise AI Governance (EU AI Act) · Enterprise AI Management · **GRC (DORA, GLBA, APRA CPS230)** · IT Lifecycle Management · IT Cost Management · Solution Health Check · Strategy to Execution · Technical Debt Management · Governance Best Practice.

**Metamodel.** Genuinely **graph-native** — relationships are first-class traversable objects, which is what makes calculated fields, broadcast audience resolution and blast-radius analysis expressible in one query language (Gremlin). Foundation metamodel ships Organization, Organizational Unit, **Person**, Application, Business Capability, and seven reference types (`Belongs To`, `Consumes`, `Reports To`, `Owns`, `Is Expert In`, `Is Realized By`, `Has Successor`). **TOGAF 9.2 Architecture Content Metamodel** and the **BIZBOK / Business Architecture Guild** metamodel ship as importable bundles (licence-gated), the latter with industry reference models including **Financial Services**.

**The crown jewel — Broadcasts.** Study this mechanic closely; it is the single most valuable thing to copy:

- **Audience resolution, four ways:** (1) *component-based* filters; (2) *person-based* — **derived from graph references**, so you add someone to an audience simply by drawing an `Owns` relationship between a Person and a component in the UI; (3) arbitrary **Gremlin queries**; (4) predefined role segmentation.
- **Trigger conditions** on staleness — named filters include `Survey is incomplete` and `not updated in 6 months`.
- **Scheduling** — one-time or recurring weekly/monthly/quarterly/annually.
- **Automated reminders** to non-respondents at 7–14 day intervals.
- **Delivery** to email plus the Discover **My Tasks** queue.
- **Tracking** — surveys sent, recipients, submission rate.
- **12+ documented patterns**: keep application technical fit current · maintain application risk assessments · identify unknown application owners · capture new interfaces and ownership · enrich cloud infrastructure details · fill technical capability gaps · track data usage across applications · quarterly objective collection.

**AI (Spring 2026 "agentic workforce").** Omnipresent AI Assistant with decision trace · **Data Ingestion Agent** (extracts vendor names, lifecycle stages, renewal dates, SLA terms from contracts/PDFs/spreadsheets/email) · **Foundation Insights Agent** (continuous scan for missing owners, unmapped capabilities, buried risk signals) · **Disaster Recovery Blast Radius Agent** · App Rationalization Agent · Stakeholder Mapping Agent · App-to-Capability Mapping Agent · Value Stream Mapping Agent · **Custom Agents** (no-code agent builder scoped to your metamodel). Plus AI-generated capability maps and value streams, an **AI Visual Importer** (diagram image → structured data), an **AI Import Builder** (agents read third-party API docs and configure the connection), and **AI Reference Creation with RAG**.

**The AI governance design is worth copying:** all AI output lands in a **Scenario branch requiring human merge**. That is an actual control, not a disclaimer.

**Pricing.** **Per application, unlimited users**, declining unit price as app count rises. AI included with no usage credits. No published price points. Ardoq's own published market benchmark: EA pilot $5–15k/yr; 200–500 apps $50–200k/yr; 1,000+ apps $250–750k/yr.

**Weaknesses to exploit.** Aggregate rating **3.9/5 (284 reviews)** vs LeanIX 4.5/5 (684). Steep learning curve requiring formal EA training. Setup "tedious." **Governance fragility from metamodel flexibility** — "complexity can lead to inconsistent models across teams." No quality-seal/approval-state primitive. **No SaaS or CASB shadow-IT discovery.** **Surveys are English-only.** **SaaS-only, no on-premises** — a hard disqualifier for regulated buyers. Reporting "requires workarounds for advanced outputs."

### 3.4 SAP LeanIX — the prescriptive, discovery-heavy incumbent

**Packaging.** Three modules under SAP's Business Transformation Management portfolio: **Application Portfolio Management (APM)** — the flagship; **Technology Risk and Compliance (TRC)** — obsolescence, EOL/EOS, tech debt, standards governance; **Architecture and Road Map Planning (ARP)** — target architecture, transformations, milestones, architecture decisions.

**Metamodel.** Explicitly the philosophical inverse of Ardoq: a **prescriptive best-practice meta model** (currently v4), configurable but not free-form. Fact Sheet types: Business Capability, Business Context (Process is a subtype), Organization, Business Unit, User Group, **Application**, **Interface**, **Data Object**, **IT Component**, Tech Category, **Provider**, Platform, Project/Initiative, Objective. Three relationship classes — hierarchical, generic (Requires/Required by), and explicit typed relations. **Not graph-native**; GraphQL gives graph-ish access but traversal analytics are weaker than Ardoq's.

**Governance primitives baked into the model** — this is what we lack most:

- **Subscriptions** with roles **Responsible / Accountable / Observer**, plus named business roles (Application Owner, Solution Architect). This is the identity spine for surveys, seals and notifications.
- **Quality Seal** state machine per fact sheet: `Approved` · `Check Needed` · `Draft` · `Rejected`. Only Responsible or Accountable subscribers may approve. Edits by anyone else to base fields, relations, external IDs or mandatory/optional fields **break the seal**. Subscriptions, comments, metrics and survey operations explicitly do **not**. Admins configure **automatic renewal intervals (30 or 90 days)** that break the seal on schedule *regardless of whether anything changed* — forcing periodic re-validation of stale data.
- **Mandatory attributes** per fact sheet type; **Fact Sheet Completion Score** (weighted completeness); **Lifecycle** fields (plan / phase-in / active / phase-out / end-of-life) driving roadmap and obsolescence reporting; **Tags** for schema-free extension.

**Discovery — six families.** This is LeanIX's real moat:

| Family | Sources |
|---|---|
| SaaS / shadow-IT | **Microsoft Defender for Cloud Apps**, **Entra ID** (SaaS discovery from IdP sign-in data), **Netskope**, **Zscaler**, **Okta**, **WalkMe** (discovery *and usage analytics*) |
| Self-built software | GitHub Enterprise (GitOps discovery), **Microservice Discovery via CI/CD manifests**, SAP BTP Cloud Foundry / Kyma, SAP Build, **SBOM API** |
| SAP | SAP Cloud ALM, SAP AI Core |
| AI agents | Amazon Bedrock, Google Agent Engine, Azure API Center (MCP servers), Entra ID, ServiceNow, SAP AI Agent, API Upload (A2A) |
| Vendor lifecycle | **Reference Catalog** — 62k+ IT products, 13k+ applications, auto-populating support types, active support, support policies and links onto IT Component fact sheets |
| Bulk / manual | Excel, API, **Low-Code Integration API with LDIF** (a JSON canonical format with an IDE for transformation logic without building a connector) |

**Reports.** 18 named types: Inventory · Fact Sheet Map · Business Capability Map · **Landscape Report** · **Matrix Report** · **Roadmap Report** · **Interface Circle Map** · **Data Flow Diagram** · **World Map** · **Cost Report** · **Portfolio Report** (the TIME quadrant) · Lifecycle and Age · **Tech Radar** · ESG Capability Map · Architecture Executive Dashboard · Customizable Dashboards · Collections · **Portals** (scoped web portals for business audiences).

**Diagrams.** Free Draw, Data Flow, and Lucidchart. Live-bound to the inventory. Standout feature: a **timeline scrubber** — drag a timeline and watch the architecture morph from current state to target per the transformation plan.

**Pricing.** Per-app tier **plus Full/Viewer named users**. List bands €60k–€600k+; 30–50% typical discount. A PeerSpot data point: **$91k/yr for 300 apps.**

**Weaknesses to exploit.** The recurring modelling complaint is **confusion between Applications and IT Components**. Limited customisation of reports and dashboards. **Some integrations are one-way, not bidirectional.** Performance — "slow to load, refresh rates." Cost, "particularly for mid-market." Weak diagramming relative to specialists (versioning still roadmap). **Surveys can be sent only to active licensed users, not to contacts** — a structural crowdsourcing ceiling made worse by Viewer licences costing money. Post-SAP roadmap uncertainty, with 30% of the roadmap SAP-specific. **No general IaaS resource discovery** (agents and SAP BTP only). **No branch/merge scenario semantics.**

### 3.5 OrbusInfinity — the Microsoft-native governance play

**Four commercial modules** (the licensing unit): **Enterprise Architecture** (base — application, data, solution, technology and security architecture) · **Business Architecture** (ex-Capsifi: capability modelling, value streams, customer journey maps, service blueprints, persona profiles, strategy & roadmaps, maturity/performance/risk assessments, innovation management) · **Business Process Management** · **IT Portfolio Management** (application rationalisation, lifecycle management, technology standards, technology risk, cloud migration planning, technical debt).

**11 platform capabilities:** Central Repository · Document Library · Standards & Frameworks · Flexible Metamodel · Diagramming · Collaboration · Integrations · Workflow Automation · Dashboards & Reporting · Views & Visualizations · User Permissions.

**The Microsoft wedge — Orbus's genuine moat and the thing we cannot copy.** A dedicated **Visio add-in with live repository sync** (drag repository objects into diagrams, push updates back, import existing Visio with automatic object extraction) · SharePoint Online document management with **Power BI dashboards embedded in SharePoint** · Teams publishing and lifecycle-review routing · **Power Automate** no-code approvals · Excel/Word/PowerPoint bulk import-export and **Live Documents** (auto-generated Word documentation from the repository) · **Microsoft Lists** intake forms · **Microsoft Planner** auto-created review tasks · Azure resource import **including cost data from Azure APIs** · Azure DevOps · Azure OpenAI private-instance AI. Orbus holds **Microsoft Preferred Solution** designation. Marketing claim: *"industry's deepest Microsoft integration."*

**AI — "22 AI-driven capabilities."** O.ai natural-language assistant; **O.ai Agent Network** (autonomous agents for task generation and data validation); AI Dashboards/Charts (NL → chart); AI Integrations; AI Governance (compliance monitoring, validation against org standards); AI-enabled workflow/approval generation; relationship inference; duplicate and object-similarity detection; repository optimisation recommendations; **multilingual artefact translation with terminology retention**.

**OrbusInfinity Flow** — native iPaaS with **150+ pre-built application components**.

**Editions:** standard, **OrbusInfinity Government (FedRAMP Moderate ATO)**, IRAP-certified (Australia). SOC 2 Type II.

**Frameworks out of the box** (per the UK G-Cloud service definition): TOGAF, ArchiMate 3.2, Azure, AWS, BPMN, UML, **COBIT 5**, ITIL. **No DMN, C4, BIZBOK, SABSA or NIST packs** — a real gap versus Avolution and Bizzdesign.

**Pricing — the most useful public artefact in the market.** From UK G-Cloud 14:

| Package | Authors | Contributors | Viewers | Solutions | Annual |
|---|---|---|---|---|---|
| Entry | 5 | 25 | 500 | 1 | **£72,500** |
| Advanced | 25 | 50 | 1,000 | 2 | **£150,000** |
| Enterprise | 100 | 1,000 | 5,000 | All | **£500,000** |

Three-year commitment required. Orbus FLOW £12,500 per instance. Premium support +10%. **Mandatory onboarding £6,495.** Positioning line: *"Priced around your practice, not your portfolio."*

**Weaknesses to exploit.** **No auto-discovery — manual data entry for applications and technologies** (the biggest functional gap versus LeanIX/Ardoq). Visio integration described as "clunky" for complex relationships; native draw.io capabilities lag Visio. **Lowest-rated feature: Impact and Dependency Analysis (77/100).** Lowest vendor capability: **Usability and Intuitiveness (77/100).** Steep learning curve; needs a dedicated resource. **Dashboard refresh is once a day, not real-time**; BI templates cannot be modified without vendor support. Gartner caution: **~half of customers require paid professional services.** And no packaged Architecture Review Board construct, no formal change-request/RFC object, **no architecture decision records** — despite Forrester naming ADRs a defining 2024+ capability.

### 3.6 The rest of the field, in brief

**Bizzdesign Horizzon** — *"Architect Your Change With Clarity."* Four solution modules: EA Management, Business Architecture Management, **Solution Architecture Management**, BPM. **The broadest notation support in the market:** ArchiMate 3.2, TOGAF ADM, UML, **C4**, BPMN, **DMN**, ERD, plus Business Model Canvas, PESTEL, SWOT and Porter's Five Forces. Bizzdesign **co-developed ArchiMate**. Forrester Q4 2024: tied second-highest on both Current Offering and Strategy; praised for a *"best-in-class natural language analyzer and artifact describer."* G-Cloud pricing **£5–£3,640 per licence per year**. Weaknesses: *"the interface can feel overly technical, making navigation tough for casual business stakeholders"*; serious, repeated **performance complaints** ("very bad experiences with performance over the years"; cloud version freezes); **weak automatic data collection**; support costs extra.

**Bizzdesign Hopex (ex-MEGA)** — *"Govern With Confidence."* The **regulated-industry / GRC** product and therefore our closest philosophical competitor. Modules: EA · BPM · **Business Continuity Management** · **GRC** · **Data Governance** · **Control Mapping** · **Audit Support** · IT Portfolio Management. Differentiator: *"single source of truth for EA artifacts, processes, risks, controls and data assets"* with traceability from **risk → control → architecture**. Ships ArchiMate 3.1, **APQC Process Classification Framework**, ISO 9001 framework modules, a ChatGPT BPMN importer, data discovery, and GraphQL tooling. Weaknesses: **weak data layer — lacks data cleansing and validation**; **operational risk module does not align with Basel standards**; *"needs better integration with ITSM or CMDB"*; complex setup; high cost; dated UI; **no built-in viewer dashboard portal**.

**Bizzdesign Alfabet (ex-Software AG)** — the portfolio-management, not modelling, product. *"ERP approach to IT — edit architecture elements as data using forms."* Five solutions: Strategic / Application / Technology Portfolio Management, EA Management, Business Architecture Management. Named capabilities include **Lean Portfolio Management**, a **Value Accelerators Library** of pre-configured use cases, **hundreds of predefined reports**, and **Flexera Technopedia** standards-catalogue integration. G-Cloud pricing **£91–£168 per user** — by far the cheapest of the set, with a 30-day full-function trial. Weaknesses: limited predefined integrations; governance capabilities need enhancement; **lacks comprehensive automation**.

**Avolution ABACUS** — the **framework breadth and analytics** leader. **~40 named frameworks** including TOGAF (5 versions), ArchiMate, BPMN, UML, SysML, **C4**, Zachman, **BIAN**, **NIST CSF 2.0**, **NIST AI RMF**, **SABSA**, **COBIT 2019**, **BIZBOK**, APQC PCF, IT4IT, ITIL, DoDAF/MoDAF/NAF/FEAF, eTOM. The genuine differentiator is **Algorithms & Automations** — computing cost, TCO, ROI, risk, security scores, complexity, cloud readiness and ESG metrics over the model, with **audit trails and rollback**. Multiple simultaneous scenario states. Deployable **on-prem, cloud or hybrid**. Pricing **£265–£1,320 per user per year** with a minimum 30-day free trial. Highest Gartner score for **EA Governance** in 2025. Weaknesses: UI/UX complexity; **no built-in dashboards** (customisation requires vendor support); resource-intensive, slow with large datasets; **difficult Microsoft Office integration**; **higher-than-average churn**.

**Essential Project** (`enterprise-architecture.org`) — open-source and commercial editions; Essential Launchpad, Business Lenses, EAValuator; HERM higher-education reference models. Cited by reviewers as the cheap alternative when Avolution is called expensive. **Relevant to us as a price floor and as evidence that an open-source EA repository is not itself a moat.**

**IFEAD** (`enterprise-architecture.info`) — dormant but contains one asset worth taking. The **Enterprise Architecture Score Card** is a traffic-light instrument evaluated per cell of a 4×6 grid — four aspect areas (Business/Organization · Information · Information Systems · Technology Infrastructure) × six abstraction levels (Contextual "Why" · **Environmental "With Who"** · Conceptual "What" · Logical "How" · Physical "With What" · Transformational "When") — scoring each cell 🔴 unknown / 🟡 partially documented / 🟢 fully documented. It measures **knowledge completeness and cross-domain alignment simultaneously**. E2AMM adds a **five-level, eight-KPA maturity model** on CMMI's continuous representation, extending to **partner-network maturity**. IFEAD also defines object lifecycle states — *under discussion → valid → in operation → discarded*.

**Why this matters: none of the six products ships an assessment-and-completeness layer.** Avolution ships 40 frameworks but no instrument that tells you how complete your architecture *documentation* is. That is an open white space, and it maps almost perfectly onto CBN's maturity-level requirement (§6.1). §5.4 puts it on the build list.

### 3.7 Pricing landscape — where we should sit

| Vendor | Model | Public price points |
|---|---|---|
| **Orbus** | Package (Authors/Contributors/Viewers) + module extensions, 3-yr commit | £72.5k / £150k / £500k per year |
| **SAP LeanIX** | Per-app tier + Full/Viewer named users | €60k–€600k+ list; ~$91k/yr for 300 apps observed |
| **Ardoq** | **Per application, unlimited users** | Not published; own benchmark $50–200k for 200–500 apps |
| **Avolution** | Per user by role | **£265–£1,320 per user per year** |
| **Bizzdesign Horizzon** | Per licence by role | **£5–£3,640 per licence per year** |
| **Bizzdesign Alfabet** | Per user | **£91–£168 per user** |
| **Essential** | Open source + commercial | Price floor |

**Read for Atheris.** Per-seat EA pricing is dated — Ardoq's unlimited-users precedent has moved the market, and per-seat directly penalises the crowdsourcing we need for freshness. Price by **institution class** (DMB international > DMB national > merchant/non-interest > PSP/MFB) in **naira**, annually invoiced, with **unlimited users**. §11 develops this.

---

## 4. Feature Parity Matrix

Scoring: **0** absent · **1** stub/read-only · **2** functional but thin · **3** competitive · **4** differentiated.

| # | Capability | Atheris today | Atheris target | Ardoq | LeanIX | Orbus | Bizzdesign grp | Avolution |
|---|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| **Repository & metamodel** |
| 1 | Graph repository with typed relationships | 3 | 4 | 4 | 2 | 3 | 3 | 3 |
| 2 | ArchiMate 3.2 conformance + validator | 3 | 4 | 2 | 1 | 3 | 4 | 3 |
| 3 | TOGAF ADM support | 2 | 4 | 3 | 1 | 3 | 3 | 4 |
| 4 | BIZBOK / BIAN reference models | 1 | 4 | 3 | 2 | 0 | 2 | 4 |
| 5 | User-extensible metamodel (no code) | 0 | 2 | 4 | 2 | 3 | 3 | 4 |
| 6 | Object lifecycle state machine | 1 | 4 | 2 | 4 | 2 | 3 | 3 |
| **Data in** |
| 7 | Bulk CSV/Excel import | 2 | 3 | 3 | 3 | 3 | 3 | 3 |
| 8 | CMDB / asset read-through | 1 | 4 | 4 | 3 | 2 | 2 | 3 |
| 9 | Cloud resource discovery | 0 | 2 | 3 | 1 | 3 | 1 | 2 |
| 10 | SaaS / shadow-IT discovery (IdP, CASB) | 0 | 1 | 1 | **4** | 0 | 1 | 1 |
| 11 | Vendor lifecycle/EOL reference catalogue | 1 | 4 | 3 | **4** | 2 | 2 | 3 |
| 12 | CVE / vulnerability feed | 1 | 4 | 2 | 3 | 1 | 2 | 2 |
| 13 | **Surveys / campaigns to non-users** | **0** | **4** | **4** | 2 | 2 | 2 | 2 |
| 14 | **Automated freshness enforcement** | **0** | **4** | 3 | **4** | 2 | 1 | 2 |
| 15 | ArchiMate Open Exchange round-trip | 2 | 4 | 2 | 1 | 3 | 4 | 4 |
| **Analysis** |
| 16 | Application portfolio TIME / quadrant | 2 | 4 | 4 | 4 | 3 | 3 | 4 |
| 17 | Technology radar & obsolescence | 2 | 4 | 3 | **4** | 3 | 3 | 3 |
| 18 | n-hop impact / blast radius | 1 | 4 | **4** | 2 | 1 | 3 | 3 |
| 19 | Scenario branch / diff / merge | 1 | 3 | **4** | 2 | 2 | 3 | 3 |
| 20 | Cost / TCO / ROI algorithms | 0 | 4 | 3 | 3 | 3 | 3 | **4** |
| 21 | Anomaly / data-quality detection | 3 | 4 | 3 | 3 | 3 | 2 | 2 |
| 22 | Process mining | 0 | 0 | 2 | 2 | 0 | 2 | 1 |
| **Visualisation** |
| 23 | Authorable diagram editor | 1 | 3 | 3 | 3 | **4** | 4 | 3 |
| 24 | ArchiMate viewpoint generation | 2 | 4 | 3 | 2 | 3 | 4 | 3 |
| 25 | Capability heat maps | 2 | 4 | 4 | 4 | 4 | 4 | 4 |
| 26 | Roadmap / timeline views | 2 | 4 | 4 | **4** | 3 | 3 | 3 |
| 27 | Executive dashboards / board packs | 2 | 4 | 3 | 3 | 3 | 3 | 2 |
| 28 | Self-service portal for business users | 0 | 3 | **4** | 3 | 3 | 2 | 2 |
| **Governance** |
| 29 | Architecture Review Board workflow | 3 | 4 | 2 | 2 | 2 | 3 | 3 |
| 30 | Principles / standards catalogue + impact | 2 | 4 | 2 | 2 | 3 | 3 | 3 |
| 31 | Exception / waiver lifecycle | 2 | 4 | 1 | 1 | 2 | 3 | 3 |
| 32 | **Architecture Decision Records** | **0** | **4** | 3 | 2 | **0** | 2 | 2 |
| 33 | Ownership / accountability model | 0 | 4 | **4** | **4** | 3 | 3 | 3 |
| 34 | Audit trail with rollback | 3 | 4 | 3 | 3 | 3 | 3 | **4** |
| **AI** |
| 35 | NL search over the repository | 2 | 4 | 4 | 3 | 4 | 3 | 3 |
| 36 | MCP server | 3 | 4 | 4 | 3 | 2 | 3 | 1 |
| 37 | Agentic data enrichment | 0 | 3 | **4** | 3 | 3 | 2 | 2 |
| 38 | AI governance controls (human-in-loop) | 0 | 4 | **4** | 2 | 3 | 2 | 2 |
| **Regulatory / regional** |
| 39 | **CBN EA maturity self-assessment** | 3 | **4** | 0 | 0 | 0 | 0 | 0 |
| 40 | **Regulatory return generation** | 2 | **4** | 0 | 0 | 0 | 0 | 0 |
| 41 | **Data residency / localisation modelling** | 0 | **4** | 0 | 0 | 0 | 0 | 0 |
| 42 | **FX exposure on the app portfolio** | 0 | **4** | 0 | 0 | 0 | 0 | 0 |
| 43 | **Vendor / integrator concentration** | 2 | **4** | 1 | 1 | 1 | 2 | 1 |
| 44 | **Power / site resilience modelling** | 0 | **4** | 0 | 0 | 0 | 0 | 0 |
| 45 | **Multi-entity, multi-regulator views** | 0 | **4** | 1 | 1 | 1 | 2 | 1 |
| 46 | **Nigerian banking reference architecture** | 1 | **4** | 0 | 0 | 0 | 0 | 0 |
| **Platform** |
| 47 | Native GRC fusion (risk/control/incident) | 1 | **4** | 2 | 2 | 1 | 3 | 1 |
| 48 | On-premises deployment | **4** | **4** | **0** | 0 | 0 | 2 | 3 |
| 49 | Unlimited-user pricing | 3 | 4 | **4** | 1 | 1 | 1 | 1 |
| 50 | Test coverage & production hardening | 1 | 3 | 4 | 4 | 4 | 3 | 3 |

**Reading the matrix.**

- **Rows 39–48 are the strategy.** We are at 4 or can reach 4 on eleven capabilities where every competitor is at 0 or 1. That is not a gap to close — it is a category to own.
- **Rows 13, 14, 33 are the emergency.** Surveys, freshness enforcement and ownership are the three mechanics that keep an EA repository alive. We score 0 on all three. Without them the module dies in every pilot regardless of what else we build.
- **Rows 10 and 22 are anti-goals.** SaaS/CASB shadow-IT discovery requires an integration ecosystem we cannot fund; process mining is a separate product category. Do not chase either. §5.1 formalises this.
- **Row 48 is an under-used weapon.** Ardoq is SaaS-only. LeanIX is SAP-hosted. In a market where the regulator has mandated data localisation by 1 January 2027, our Laravel + MySQL + Redis + React stack deploying inside a bank's own data centre — or into MDXi, Rack Centre or Galaxy Backbone — is a structural advantage we currently do not sell.
---

## 5. Keep / Kill / Merge / Build

The brief asked to "remove what's not needed." This section does that explicitly. The governing principle: **every surface must either capture data, enforce a decision, or produce an artefact a regulator or executive asked for.** A page that renders a table of seeded records and does nothing else fails all three tests and is removed or absorbed.

### 5.1 KILL — remove entirely

| Surface | Why it goes | Disposition |
|---|---|---|
| **`Mcp.jsx`** (MCP Server page) | An end-user page that documents a machine protocol. Nobody in a bank navigates to "MCP Server." The endpoint is valuable; the menu item is noise. | Delete page. Move MCP config to Settings → Integrations. Keep `McpServer` service and `ea.mcp.rpc` endpoint. |
| **`AdmTracker.jsx`** as a standalone page | A TOGAF ADM phase tracker divorced from the initiative it tracks. 62 LOC, zero interaction. | Delete. Fold ADM phase + deliverables into the **Initiative detail page** as a tab. |
| **`Solutions.jsx`** | 42 LOC, read-only, semantically overlapping "Application" and "Pattern." Users cannot tell what a "Solution" is versus a "Reference Pattern." | Delete. Merge records into **Reference Patterns** with a `kind` discriminator. |
| **`InfoDomains.jsx`** as a standalone page | 33 LOC. An information domain is a grouping of logical entities, not a destination. | Delete. Becomes a grouping/filter dimension on the Data Architecture workspace. |
| **`Viewpoint.jsx`** as a standalone page | 53 LOC generic viewpoint renderer reached by URL only. | Delete as a menu item. Viewpoints become a **render mode** inside Diagrams. |
| **`Scenarios.jsx`** in current form | 93 LOC read-only comparer with no authoring. Shipping a "Scenario Compare" that cannot create a scenario invites the comparison to Ardoq we lose. | Delete the page; keep `ScenarioComparer`. Rebuild in Phase 4 as **Plateau diff** inside the Roadmap workspace. |
| **`NlSearch.jsx`** as a standalone page | A search box should be in the header, not a menu entry. | Delete page. Promote to a **global command palette** (⌘K) across the whole suite. |
| **Process mining** | Never built; explicitly do not build. Separate product category (Celonis, Signavio). Forrester names it a modern-suite capability; we accept the gap. | **Anti-goal.** |
| **SaaS / CASB shadow-IT discovery** | Requires Netskope/Zscaler/Defender/WalkMe partnerships. Not fundable, and low relevance where the estate is on-prem core banking. | **Anti-goal.** Revisit only if a design partner asks. |
| **Free-form metamodel editing** | Ardoq's biggest adoption risk ("complexity leads to inconsistent models across teams"). Given Nigerian architect scarcity, a user-definable metamodel is actively harmful. | **Anti-goal.** Ship an opinionated CBN-shaped metamodel with custom *fields*, not custom *types*. |
| **ESG / carbon footprint** | Forrester names sustainability a defining capability. It is not a Nigerian banking buying criterion in this cycle. | **Defer to 2027.** Leave the attribute hooks; build no reporting. |

Net effect: **8 pages deleted, 3 anti-goals formalised.** The nav drops from 40 items before restructuring.

### 5.2 MERGE — collapse into a parent surface

| Current pages | Merge into | Rationale |
|---|---|---|
| Logical Entities · Data Flows · Information Domains · Business Glossary · DPIA & Privacy | **Data & Privacy Architecture** workspace with 5 tabs | These are one job — "know your data" — split across five menu items. The NDPC CAR and the DPIA are produced from the same dataset. |
| Interfaces · API Register · Blast Radius | **Integration Architecture** workspace | Blast radius is an *analysis of* interfaces, not a peer of them. Also: this is the CBN "catalogue of all network connections… with the objective of each connection documented" clause, which should be one screen. |
| Security Zones · Control Mappings · Threat Models | **Security Architecture** workspace | Zones, controls and threats are the CISO's three views of the same graph. |
| Plateaux · Initiatives · Roadmap · ADM Tracker | **Transformation** workspace | One planning surface: baseline → target plateaux, initiatives that move between them, ADM phases on the initiative, Gantt as a view. |
| Principles · Standards · ARB · Exceptions · **(new) Decision Records** | **Architecture Governance** workspace | The governance lifecycle is one loop: principle → standard → submission → decision → exception. Splitting it hides the loop. |
| Capability Map · Value Streams · Process Inventory | **Business Architecture** workspace | The BIZBOK triad. Also the surface that must reconcile with the core `BusinessCapability` family (§7.2). |
| Application Portfolio · Technology Radar · Vendor Concentration | **Portfolio** workspace | The APM flagship plus its two lenses. |
| EA KRIs · Anomaly Inbox · Audit Trail | **Assurance** tab set inside Command Centre | Operational monitoring belongs next to the executive view, not as three peers of "Capability Map." |
| ArchiMate Exchange · Bulk Import · sync actions | **Data Sources** admin screen | One place to get data in and see feed provenance. |

### 5.3 KEEP AND FINISH — the Phase 0 wiring list

These surfaces are correct in concept and must be given their write path. Each row is a concrete work item.

| Surface | Missing | Endpoints to wire |
|---|---|---|
| Application Portfolio | Create / edit / delete / bulk-edit; filters; saved views | `ea.applications.store/update/destroy` |
| Capability Map | Add child · rename · delete · drag-to-reparent · inline criticality | `ea.capabilities.store/update/destroy` |
| Technology Radar | Add / edit / delete component; set ring; set EOL/EOS | `ea.tech.store/update/destroy` |
| Interfaces | Create / edit / delete; source-target pickers; **connection objective field** (CBN clause) | `ea.interfaces.store/update/destroy` |
| Principles | Create / edit / delete; version; publish | `ea.principles.store/update/destroy` |
| Standards | Create / edit / delete; link to principle; lifecycle | `ea.standards.store/update/destroy` |
| Exceptions | Raise · renew · expire · approve | `ea.exceptions.store`, `ea.exceptions.renew` |
| Initiatives | Add dependency; add deliverable; update deliverable | `ea.initiatives.dependencies.store`, `.deliverables.store`, `ea.deliverables.update` |
| EA KRIs | Recompute button | `ea.kri.recompute` |
| Threat Models | Add technique to model | `ea.threats.techniques.store` |
| Diagrams | Delete | `ea.diagrams.destroy` |
| Data Sources (new) | Trigger and schedule all three syncs; show provenance | `ea.sync.assets`, `.eol`, `.cve` |

That is the whole of RC-1: **30 endpoints, ~12 surfaces.**

### 5.4 BUILD — new capability, in priority order

| # | Capability | Why | Phase |
|---|---|---|---|
| **B1** | **Ownership & Subscription model** — `Person` as a first-class EA object; `Responsible / Accountable / Consulted / Observer` subscriptions on every entity; ownership derived from relationships, not free text | Prerequisite for surveys, seals, notifications and any accountability claim. LeanIX and Ardoq both build everything on this. | 1 |
| **B2** | **Survey & Campaign engine** — form builder writing directly to entity fields; audience resolved from subscriptions *or* a saved graph query; one-time and recurring schedules; staleness triggers; auto-reminders; magic-link responses **from non-licensed users**; completion tracking | The freshness mechanic. Beats LeanIX (which cannot survey non-users) and matches Ardoq. | 1 |
| **B3** | **Quality Seal / confidence state machine** — per-entity `Draft / Approved / Check Needed`; only Responsible or Accountable may approve; configurable 30/60/90-day auto-expiry; mandatory-attribute gating; completeness score | Directly copies LeanIX's strongest mechanic. Also becomes the evidence-quality indicator on regulatory returns. | 1 |
| **B4** | **Architecture Decision Records (ADR)** — first-class object linked to ARB submissions, initiatives and affected entities; status lifecycle; searchable | Forrester names ADRs a defining capability; **Orbus has none**, Ardoq has "Architecture Records." Cheap to build, immediately demoable, and it is the artefact CBN's governance section asks for. | 1 |
| **B5** | **Architecture Completeness Score Card** — IFEAD-style 4×6 traffic-light grid over aspect area × abstraction level, computed from repository completeness and seal state | **Nobody ships this.** It maps directly onto CBN's maturity-level requirement and turns "how complete is our architecture" from a consultant's opinion into a computed number. | 2 |
| **B6** | **Regulatory Architecture Return Engine** | §6.3. The product. | 3 |
| **B7** | **Data Residency & Localisation Control Tower** | §6.3. | 3 |
| **B8** | **FX Exposure lens on the application portfolio** | §6.3. | 3 |
| **B9** | **Vendor & Integrator Concentration** (deepened) | §6.3. | 3 |
| **B10** | **Power & Site Resilience modelling** | §6.3. | 3 |
| **B11** | **Multi-entity, multi-jurisdiction views** | §6.3. | 3 |
| **B12** | **Nigerian Banking Reference Architecture content pack** | §6.4. | 3 |
| **B13** | **Real diagram editor** — palette drag-drop onto canvas, click-drag edge creation with ArchiMate relationship validation, auto-layout, versioning, PNG/SVG/PDF export | Replaces `window.prompt()`. Table stakes. | 4 |
| **B14** | **n-hop impact traversal** over `ea_relationships` with depth control and a change-impact tab on every entity | Orbus's *weakest* rated feature (77/100). Attackable. | 4 |
| **B15** | **Plateau diff / scenario comparison** with cost, risk and count deltas | The post-merger rationalisation feature the recapitalisation window demands. | 4 |
| **B16** | **Business-user portal** — read-only scoped views + My Tasks queue for survey respondents | Ardoq Discover equivalent; required for B2 to land. | 4 |
| **B17** | **Cost & currency algorithms** — annual cost, TCO, currency of licence, cost per capability | Feeds B8 and the CFO conversation. Avolution's differentiator. | 3 |

### 5.5 The resulting module structure

From **40 flat links** to **8 workspaces**, organised by job-to-be-done rather than by metamodel entity — the pattern every Leader uses.

```
Enterprise Architecture
│
├── 1. Command Centre                    ← executive landing; KPIs, Score Card, assurance tabs
│      ├─ Overview
│      ├─ Architecture Score Card         (B5)
│      ├─ EA KRIs
│      └─ Anomaly Inbox
│
├── 2. Business Architecture              ← Capabilities · Value Streams · Processes
│      └─ heat-map overlays: criticality, BIA RTO/RPO, application coverage, spend
│
├── 3. Portfolio                          ← Applications · Technology · Vendors
│      ├─ Application Portfolio           (TIME grid, lifecycle, cost, FX, residency)
│      ├─ Technology Radar                (rings, EOL/EOS, CVE)
│      └─ Concentration                   (vendor + integrator + site)
│
├── 4. Integration Architecture           ← Interfaces · APIs · Impact
│      └─ CBN connection catalogue view (objective, third party, review date)
│
├── 5. Data & Privacy Architecture        ← Entities · Flows · Domains · Glossary · DPIA
│      └─ residency, cross-border, NDPA classification, CAR extract
│
├── 6. Security Architecture              ← Zones · Control Mappings · Threat Models
│
├── 7. Transformation                     ← Plateaux · Initiatives · Roadmap · ADM
│
└── 8. Architecture Governance            ← Principles · Standards · ARB · Decisions · Exceptions
                                             (B4 ADRs live here)

Regulatory Returns  ← promoted OUT of EA to a suite-level module (§6.3, §7)
Settings → EA       ← Data Sources · Metamodel fields · Surveys · Seal policy · Exchange
```

Two structural notes.

**First, Regulatory Returns is promoted out of EA.** The CSAT return, NDPC CAR, localisation gap report and quarterly board pack draw on EA, CSAT, Risk, Control, Vendor, Incident and BCP data. Burying them inside EA hides the product's best feature from the CISO who buys it. It becomes a suite-level module that *consumes* the EA graph. §7.3 defines the contract.

**Second, every workspace gets the same four affordances** — a filter/search bar with saved views, a create action, bulk edit, and an export. Consistency is what makes 8 workspaces learnable where 40 links were not.

---

## 6. The African Wedge — The Massive Feature

This is the answer to "build a massive feature for the Nigerian and African market."

### 6.1 The regulatory reality — why this market is unlike any other

Three findings, each verified, that together define the opportunity.

**Finding 1 — CBN has named the framework and set the maturity bar.**

The **Nigeria Financial Services Industry IT Standards Blueprint (ITSB) v2.1, July 2019** adopts **TOGAF version 9.2** as the mandated enterprise architecture standard, applying to all Financial Service Industries **and their external managed service providers**. It requires all four TOGAF ADM domains — Business, Application, Information and Technology Architecture. It sets **target maturity by institution category**:

| Category | Institutions | Target |
|---|---|---|
| **Category One** | International Commercial Banks; established Commercial and Merchant Banks | **Level 3 — Defined** (documented, formally trained, integrated via policy) |
| **Category Two** | Banks operating ≤18 months; Payment System Providers | **Level 2 — Repeatable** |

Maturity is scored on a COBIT-style 0–5 scale, and institutions must *"submit to a formal assessment by the IT Standards Council."* The blueprint's other named standards — COBIT, ISO 38500, ITIL/ISO 20000, ISO 27001/27002, PCI DSS, **ISO 22301**, **TIA-942** — map directly onto modules Atheris already ships.

> ⚠️ **Verify before it goes in collateral.** The v2.1 implementation roadmap runs through 2022. The current enforcement posture of the IT Standards Governance Council could not be established from public sources. **Ask a design-partner bank when they were last assessed.** The answer determines whether this is a live compliance driver or a strong-but-dormant narrative. Also note CBN names **TOGAF 9.2**, not TOGAF 10 — build to what the regulator names.

**Finding 2 — the Risk-Based Cybersecurity Framework is an EA specification in disguise.**

The 2024 framework (published May 2024, effective 1 July 2024, circular BSD/DIR/PUB/LAB/017/008) contains clauses that describe an architecture repository almost field by field:

| Clause | Requirement | EA object it implies |
|---|---|---|
| §3.1 "Know Your Environment" | Up-to-date inventory of authorised software, hardware and **internal and external network connections** | Application + technology catalogue |
| App. II §1.1(a) | Inventory of all authorised IT assets **on-premises and in third-party cloud infrastructure** | Hosting-location attribute |
| App. II §1.1 | Establish **asset ownership** and assign responsibility | **Ownership relationship (B1)** |
| App. II §1.1 | Categorise devices by **criticality and sensitivity of data** | Criticality tiering |
| App. II §1.1(g)–(h) | Data classification by sensitivity + documented handling and disposal | Data architecture layer |
| **App. II §1.1(i)–(k)** | **Approved, up-to-date network topology diagram** (wired and wireless); **catalogue of all network connections to regulatory authorities, switches and third parties, with the objective of each connection documented and regularly reviewed** | **This is the single most EA-tool-shaped clause in Nigerian regulation.** Interface register with an `objective` field and a review cadence. |
| App. II §1.2 | Vulnerability assessment of all IT assets, presented **quarterly** to governance bodies | Requires the inventory to be current, not a one-off |
| App. II §1.4 | Record of all third-party providers; **document all connections to third parties**; evaluate CSP controls **before adoption** | Vendor↔application↔interface graph |
| §2.3 | Third-party risk framework: selection, due diligence, contracts, monitoring | Concentration analysis |

Reporting obligations that become product-native deliverables: **CSAT annual self-assessment by 28 February**, CISO-signed ⚠️ *(the exposure draft said 31 March; confirm against the signed circular)*; **cyber incident notification within 24 hours**; **threat-intelligence return by the 5th of each month**; **quarterly** board reporting.

And §2.4 of the framework requires the CSAT to establish a **current profile → target profile → roadmap**. That is literally the TOGAF ADM gap-analysis pattern. The regulator has specified the artefact shape.

> The **April 2026 CSAT circular** made this live: DMBs given **3 weeks**, all other FIs **5 weeks**, data as at 31 December 2025, assessed across **governance, risk management, technology controls, third-party risks, incident response and operational resilience** — with false or misleading data a regulatory breach under BOFIA 2020.
>
> ⚠️ The CSAT's internal maturity definitions, question count and whether it demands a machine-readable inventory upload **could not be verified** — the tool is distributed to CISOs, not published. **This is the highest-value single artefact to obtain from a design partner.** If CSAT has an inventory or architecture section, mapping our export directly to its submission format is the product's killer feature.

**Finding 3 — two 2026 shocks put architecture questions on a hard deadline.**

- **Data localisation.** The circular of **15 June 2026** ("Introduction of Market Structure Requirements, Data Localisation, Ultimate Beneficial Ownership Disclosure, and Systemic Oversight Measures in the Nigeria Payments System") requires all institutions facilitating Nigerian payments to store and manage domestically generated transaction data **within Nigeria**, with compliance by **1 January 2027**. It affects **primary and disaster recovery infrastructure**, forces revision of outsourcing and cloud contracts, and prohibits cross-border transfer of Nigerian payment transaction data. A companion market-structure rule bites **31 December 2026**.
  > ⚠️ **The highest-value open question in this document:** whether AWS, Azure or GCP operate in-country Nigerian regions. The public data-centre inventory lists colocation and telco operators only. **If no hyperscaler has a Nigerian region, the directive forces a sector-wide repatriation programme before January 2027.** Verify before building go-to-market on it.
- **Recapitalisation.** Concluded **31 March 2026**, raising **₦4.65trn**; **33 banks** met the thresholds (₦500bn international / ₦200bn regional / ₦50bn national), with merger discussions ongoing. Banks that just raised that capital have money, new international-licence obligations, and — where mergers complete — **duplicate application landscapes requiring rationalisation.** Post-merger APM is the most reliable EA use case in existence and Nigeria is about to have several instances of it.

**Plus NDPA/GAID.** Every Nigerian commercial bank is an **Ultra-High Level** Data Controller of Major Importance; microfinance and mortgage banks are Extra-High Level. Compliance Audit Returns are due **31 March** annually, filed **through a licensed DPCO**, with fees ₦100k–₦1m and non-filing penalties of **up to 2% of annual gross revenue or ₦10m, whichever is higher** ⚠️ *(the 2025 cycle was extended to 30 May 2026 — extensions appear to be a pattern)*. Two of the five CAR audit domains — **cross-border transfer activities** and **third-party data processor arrangements** — are architecture questions dressed as privacy questions. A repository holding *system → data category → hosting location → processor → transfer basis* answers both mechanically.

### 6.2 The compliance calendar as the product's clock

| When | Obligation | Instrument |
|---|---|---|
| **28 February** (annual) ⚠️ | CSAT self-assessment, CISO-signed, to Director Banking Supervision | Cybersecurity Framework §2.5 |
| **5th monthly** | Cyber-threat intelligence return | App. II §1.3 |
| **Within 24 hours** | Cyber incident notification | §5.0 / App. VII |
| **Quarterly** | Board report; vulnerability assessment of all IT assets | §1.1(viii), §1.2(iii), App. II §1.2 |
| **31 March** (annual) ⚠️ | NDPC Compliance Audit Return via DPCO | NDPA / GAID 2025 |
| **Monthly** | Market-share returns on CBN template | Market structure directive |
| **31 December 2026** | Market structure compliance | Market structure directive |
| **1 January 2027** | **Payment data localisation** | Data localisation directive |
| **Q2 2027** | NITDA SQA framework full effect ⚠️ *(bank applicability ambiguous)* | National SQA Framework |
| Periodic ⚠️ | Architecture maturity assessment by IT Standards Governance Council | ITSB v2.1 |

**Product implication.** Build this calendar into the platform as a first-class object. Every return has an owner, a due date, a data dependency graph, a preparation workflow, a sign-off chain and an archived submission. The EA repository is what the returns read from — which is what keeps the repository current.

### 6.3 The seven Africa-first features

These are the "massive feature." Each is evidenced by a specific market condition, each is buildable on the existing metamodel, and **none of them will be built by a global vendor** because the addressable market outside Africa does not justify it.

---

#### **A1 — Regulatory Architecture Return Engine** *(the flagship)*

**What it is.** A return-generation engine that treats each regulatory submission as a compiled artefact of the architecture graph plus the GRC data.

**What ships:**
- **CSAT pre-fill pack** — inventory of applications and technology with criticality and ownership; network connection catalogue with documented objectives; third-party provider register with connection mapping; EOL/obsolescence posture; security zone assignments; control coverage by framework; current-profile → target-profile → **roadmap** generated from plateaux and initiatives (matching CSAT §2.4's own structure). Every answer carries **evidence citations** back to the EA entities that produced it, with the quality-seal state shown so the CISO knows what is trustworthy before signing.
- **CBN ITSB architecture maturity assessment** — the existing `CbnMaturity` extended to the ITSB's eight capability areas with **category-aware targets** (Level 3 for Category One, Level 2 for Category Two), scored 0–5, with the gap and the remediation initiatives auto-derived. Output: a Governance Council-ready pack.
- **NDPC Compliance Audit Return extract** — processing inventory from logical entities; cross-border transfer register from data flows with transfer basis; third-party processor arrangements from the vendor↔application graph; DPIA register. Formatted for DPCO filing.
- **Localisation gap report** — see A2.
- **Quarterly board pack** — architecture risk section auto-composed: obsolescence, concentration, residency, exception backlog, KRI trend.
- **Incident 24-hour notification annex** — on incident declaration, the affected application's blast radius, data categories, hosting location and third parties are attached automatically.

**Mechanics.** Each return is a `RegulatoryReturn` record with a template, a due date, an owner, a data-dependency graph, a preparation workflow with sign-off stages, a hash-sealed archive (the existing `EvidencePackGenerator` already does hash sealing), and a diff against the prior period.

**Why nobody else builds it.** Ardoq ships DORA, GLBA and APRA CPS230 patterns because those markets are large. There is no CBN pattern in any product on earth.

---

#### **A2 — Data Residency & Localisation Control Tower**

**Market condition.** 1 January 2027 deadline; NDPA/GAID requiring Commission approval for SCCs/BCRs, with consent-based transfer permissible **only where connected to jural or fiduciary obligations** — materially stricter than GDPR practice. Two independent regimes constraining where data may sit.

**What ships.** New first-class attributes on applications, technology nodes and data stores:

- `hosting_country`, `hosting_site` (linked to a **Site** object), `hosting_model` (on-prem / colocation / private cloud / public cloud / SaaS)
- `dr_country`, `dr_site`, `replication_topology`
- `data_categories[]` with NDPA classification, `contains_nigerian_payment_data` (boolean)
- `processor_third_party` (→ Vendor), `transfer_basis` (adequacy / SCC / BCR / consent / jural obligation), `transfer_approval_reference`

**The reports:**
1. **Localisation gap register** — every system holding Nigerian payment transaction data whose primary *or DR* site is outside Nigeria, with days-to-deadline, remediation owner and linked migration initiative.
2. **Cross-border flow map** — every data flow crossing a national boundary, its basis, and whether the basis is approved.
3. **Sovereign readiness view** — which workloads can move to which in-country site, by capacity and tier.

**Why nobody else builds it.** LeanIX and Ardoq model "region" as a tag. Neither models a *legal* residency obligation with a deadline, a basis and an approval reference.

---

#### **A3 — FX Exposure Lens on the Application Portfolio**

**Market condition.** Tier-1 banks spend **at least $10m annually on core banking licences and support alone**, dollar-priced; naira devaluation nearly doubled these costs and directly drove Sterling Bank's 2024 migration to the indigenous **SeaBaaS** platform. Six major banks spent **₦268.7bn (~$171.5m) on IT in 2024, up 74.5% year on year**.

**What ships.** `annual_cost`, `cost_currency`, `contract_end_date`, `renewal_notice_period`, `licence_model` on every application and technology component. Then:

- **FX exposure dashboard** — total annual application cost split by currency; naira equivalent at current and stressed rates; exposure by business capability; exposure by vendor.
- **Devaluation stress test** — "at ₦X/$ our application cost base is ₦Y" with a slider.
- **Localisation candidates** — dollar-denominated applications ranked by cost, criticality and availability of an indigenous or naira-priced alternative.
- **Renewal calendar** — contracts expiring in the next 18 months, sorted by FX exposure.

**Why this matters commercially.** This is the artefact that sells the module to the **CFO**, not the CISO or the CIO. It is generated entirely from EA data and no global EA tool has a currency dimension at all.

---

#### **A4 — Vendor & Integrator Concentration** *(deepened)*

**Market condition.** **CWG Plc is the sole Nigerian distributor of Infosys Finacle** and serves First Bank, GTBank, UBA, Fidelity, Stanbic IBTC, FCMB and Wema; an investor cited in reporting states CWG is *"used by 60% of Nigerian banks."* Finacle and Flexcube are a near-duopoly at tier 1. The CBN cybersecurity framework §2.3 and App. II §1.4 explicitly require banks to manage third-party concentration — and no bank can currently visualise it.

**What ships.** Extend `VendorConcentrationService` (which already performs a real join) into a full analysis:

- **Concentration heat map** across vendor → application → business capability → value stream, with an HHI-style concentration index per capability.
- **Integrator concentration** as a distinct dimension from *product* vendor — the CWG case is a *services* concentration, which is invisible if you only model the software publisher. New `Vendor.role` dimension: publisher / integrator / hosting / managed service / connectivity.
- **Single-point-of-failure register** — capabilities where one vendor or one integrator supports >X% of critical applications.
- **Substitutability score** per vendor: how many alternatives exist in-market, and what the switching estimate is.
- **Reverse view on the Vendor page** — dependent applications, capabilities and business services (the TPRM officer's screen today shows nothing of this).

---

#### **A5 — Power & Site Resilience Modelling**

**Market condition.** Nigeria has **28 data centres, 21 of them in Lagos** — severe geographic concentration for DR purposes. Power is an under-acknowledged cause of payment failure and was **entirely absent from stakeholder checklists** for achieving PSV 2028 targets. The ITSB adopts **TIA-942** for data centre tiering. A DR architecture that ignores diesel is fiction in this market.

**What ships.** A first-class **Site** object with:
- `city`, `state`, `operator`, `tia942_tier`, `power_source[]`, `generator_autonomy_hours`, `fuel_dependency`, `ups_autonomy_minutes`, `connectivity_providers[]`, `flood_risk`, `grid_reliability_band`
- Named seed data for the real Nigerian estate: Rack Centre, Africa Data Centres LOSI, Equinix/MainOne LG1–LG3, MTN Sifiso Dabengwa, Digital Realty, OADC, NTT, Airtel Nxtra, Tetracore, **Galaxy Backbone (Abuja, Kano Tier IV, Enugu)**

**The reports:**
1. **Geographic concentration alert** — automatically flag when primary and DR sites are both in Lagos, or within the same grid/flood zone.
2. **Resilience posture by business service** — RTO/RPO from BIA versus the actual site tier and generator autonomy supporting it.
3. **Single-site dependency register** for critical business services.

---

#### **A6 — Multi-Entity, Multi-Jurisdiction Architecture**

**Market condition.** Nigerian banking groups are genuinely multi-jurisdictional: **GTCO 11 countries** (incl. UK), **Access Holdings 10** (incl. UK), **UBA ~20 African markets plus UBA UK**, **FBN Holdings 7**, **Zenith 5**. Each subsidiary faces a different regulator — CBN, Bank of Ghana, CBK, SARB, and **PRA/FCA** for UK subsidiaries.

**What ships.**
- **Legal Entity** as a first-class object with `jurisdiction`, `regulator[]`, `licence_class`
- Application *instances* mapped to entities — one logical application, N deployed instances, each with its own hosting, residency, criticality and regulator set
- **Per-jurisdiction compliance view** over a shared catalogue: "show me GTBank Ghana's estate against the BoG directive" from the same data that produces the CBN return
- **Group roll-up** — concentration, obsolescence and residency aggregated across entities, which is what a group CIO and the group board actually need
- Regulator packs pluggable per jurisdiction (Nigeria first; Ghana second — see §6.6)

**Why this is a moat.** A global tool models "regions." It does not model *"this subsidiary's core banking instance must simultaneously satisfy CBN localisation, the Ghanaian directive and UK operational resilience."* That is genuinely hard, it is table stakes for GTCO/Access/UBA, and it is worth building properly.

---

#### **A7 — The African Channel & Rails Model**

**Market condition.** USSD is a first-class retail channel in Nigeria with commercial and regulatory fragility that has no Western analogue: a **four-year debt dispute between banks and telcos**, telcos suspending service, and the FG **deactivating nine banks' USSD codes**, resolved only in early 2026. Agent banking (Moniepoint, OPay, PalmPay) is a major channel. NIBSS NIP is the national rail; 17 licensed switches operate, subject to mandatory 24/7 operation, PCI DSS/EMV compliance and **seven-year transaction logging**.

**What ships.** The channel layer of the metamodel gets first-class types that global tools do not have:
- **Channel**: branch · ATM · POS · **agent banking** · **USSD** · mobile app · internet banking · API/open banking · WhatsApp/chat
- **Rail / shared infrastructure**: NIBSS NIP · NQR · BVN · NCS · card schemes · switches
- **Telco / aggregator** as a modelled third-party dependency on the USSD channel — so "MTN can switch off this channel over a billing dispute" is a visible, assessable dependency
- Switch objects carrying the CBN licence conditions as attributes (24/7, PCI DSS, EMV, 7-year logging) so the constraint set is inspectable

> **Do not model eNaira into the reference architecture.** It was pulled offline in August 2025 (site 404, wallet delisted) and CBN has reportedly formed a stablecoin task force instead. Ship a **virtual-asset / stablecoin capability placeholder**, consistent with the August 2026 sandbox VASP track.

> **Do not import a PSD2 metamodel for open banking.** Nigeria uses a **4-tier risk classification (Tiers 0–3)** by data sensitivity with a central **Open Banking Registry** and **Know Your Partner** checks — not AISP/PISP roles. And do not claim Nigerian open banking is live: the guidelines are final (Feb 2021 framework, March 2023 operational guidelines), the 1 August 2025 go-live was missed, and CBN was still sandboxing permission-based data sharing in August 2026. What is true and sellable: banks carry standing OBR, consent-management and KYP obligations today.

### 6.4 The Nigerian Banking Reference Architecture content pack

A pre-built content library shipped with the product, so a bank starts at 60% populated rather than at an empty canvas. **This is the single strongest answer to Nigerian architect scarcity** — ~49 EA roles nationally, and the flagship regional architecture role names zero EA tools. Users should populate a structure, not design one.

| Pack | Contents |
|---|---|
| **Capability model** | BIAN Service Landscape (already partially seeded) tailored to Nigerian retail/commercial banking, with CBN ITSB's eight capability areas as a cross-cutting overlay |
| **Application catalogue** | ~200 curated entries with vendor, origin country, typical licence currency and lifecycle: **Finacle** (Infosys), **Flexcube** (Oracle FSS), **T24** (Temenos), **BankOne**, **Basis**, **iMAL** (Path Solutions), **SOPRA Amplitude**, **Intellect**, **SeaBaaS** (Peerless — indigenous), **Neptune**; plus channel, card management, treasury, AML, and reporting platforms |
| **Integrator / vendor register** | CWG, Interswitch, eTranzact, Unified Payments, NIBSS, Chams, Computer Warehouse, Signal Alliance, MainOne, Galaxy Backbone — with the **role** dimension from A4 |
| **Rails & switches** | NIBSS NIP, NCS, BVN, NQR; the 17 CBN-licensed switches with their licence conditions |
| **Site catalogue** | The 28 Nigerian data centres with tier, operator, city and power attributes (A5) |
| **Security zones** | A CBN-shaped reference zone model: internet · DMZ · channel · core banking · card/CDE · management · third-party connection zones |
| **Interface archetypes** | Core↔NIBSS, core↔switch, core↔channel, core↔BVN, agent/USSD aggregator, regulator reporting links — each pre-populated with a **connection objective** template satisfying App. II §1.1(i)–(k) |
| **Principles & standards** | A starter set derived from the ITSB and the cybersecurity framework, ready to adopt or amend |
| **Maturity content** | CBN ITSB architecture maturity questionnaire; CSAT domain mapping; the IFEAD-derived Score Card grid (B5) |
| **Regulatory obligations** | The §6.2 calendar as seeded `Obligation` records wired to the returns engine |

Commercially this becomes a **regulatory content subscription** — recurring revenue on top of the platform licence, and a genuine reason to renew.

### 6.5 Why the global vendors will not follow

Not "cannot" — **will not**, which is more durable:

1. **Addressable market.** Nigeria's entire banking sector is ~33 institutions. A €600k-list vendor cannot staff a Lagos presence for that. Orbus's *entry* package is £72,500/year on a three-year commitment; LeanIX is ~$91k for 300 apps. Nigerian buying reality is naira billing via Paystack/Flutterwave, 70–80% PPP discounts for SME SaaS, and — universally reported — *"Nigerian enterprise buyers will not sign a contract without meeting someone locally."*
2. **No footprint.** No evidence could be found of LeanIX, Ardoq or Orbus having a Nigerian partner, reseller, distributor or public reference. LeanIX's channel programme lists no West African partners. *(This is absence of evidence in public sources, not proof of absence — a bank could hold LeanIX via a global SAP relationship. But no vendor has met the burden of proof in-market.)*
3. **Deployment.** Ardoq is SaaS-only. LeanIX is SAP-hosted. With localisation compliance due 1 January 2027 and security teams unwilling to send an architecture graph to foreign SaaS, on-premises is not a nice-to-have — and it is where we already are.
4. **Roadmap direction.** 30% of LeanIX's roadmap is SAP-specific. Ardoq is building EU AI Act tooling. Bizzdesign is spending 5–7 years merging four products. None of them is going to build a CBN return engine.

### 6.6 Expansion sequencing

| Order | Market | Rationale | Rework |
|---|---|---|---|
| 1 | **Nigeria** | The mandate exists and is named. | — |
| 2 | **Ghana** | BoG Cyber & Information Security Directive (2018); **a revised directive is in preparation, expected 2026**, promoting ISO 27001/NIST and expanding FICSOC monitoring. Access, Zenith, UBA, GTCO and FirstBank all have Ghanaian subsidiaries — land-and-expand through A6. | Low ⚠️ *(revised directive's architecture clauses unverifiable until published)* |
| 3 | **Kenya** | CBK Guidance Note on Cybersecurity (2017); CBK's own adoption survey evidences a maturity gap. GTCO and Access present. | Low |
| 4 | **South Africa** | The most prescriptive regime on the continent — **Joint Standard 1 of 2023** (IT Governance and Risk Management, effective 15 Nov 2024) requires documented IT risk frameworks and **identification of critical technology assets**; **Joint Standard 2 of 2024** (Cybersecurity and Cyber Resilience, effective 1 June 2025). But it is the one market where global vendors compete and PPP pricing does not apply. | Medium — sequence **last**, not first |

Also worth watching: **NITDA took over the Nigeria Government Enterprise Architecture (NGEA) on 27 March 2026**, with pilots at NIMC, Customs, Immigration and NITDA, hosted on Galaxy Backbone. ⚠️ Its underlying framework could not be verified — **if it is TOGAF-based, a second public-sector market opens on essentially the same metamodel.**

### 6.7 Channel strategy — a verified, structural finding

The NDPA regime **requires** Ultra-High and Extra-High Level entities to file Compliance Audit Returns **through a licensed Data Protection Compliance Organisation (DPCO)**. Every Nigerian bank is UHL. That means every bank already has a mandated compliance intermediary with an annual recurring engagement and a direct line to the CISO and DPO — and **DPCOs are structurally under-tooled**. They are the highest-signal reseller channel in this market. **CWG Plc** (~60% of Nigerian banks) is the other obvious route, though note the conflict: CWG is itself the concentration risk A4 measures.
---

## 7. Cross-Module Integration Architecture

The brief noted that other modules will also change to fit business needs. This section defines the contracts so that EA work and suite work do not collide.

### 7.1 The single-graph principle

**One rule governs everything below: there is exactly one architecture graph, EA owns it, and every other module reads from it rather than keeping its own copy.**

Today the platform violates this in three places — duplicate business-architecture models (§2.5), unconstrained cross-module IDs seeded with `rand()`, and a `tenant_id` / `organization_id` naming split. Fixing those is a precondition for the returns engine, because a return that cites an entity must be able to resolve it.

**Implementation stance.** Prefer **soft foreign keys plus domain events** over hard database constraints. The platform is a modular monolith and the seams should stay clean, but the *application layer* must enforce referential integrity, and **seeders must stop writing random IDs**. Concretely: a `Ea\GraphResolver` service that resolves and validates cross-module references, plus a small set of Laravel events that other modules subscribe to.

### 7.2 Canonical object ownership

This table must be agreed before Phase 2 starts. It resolves the duplicate-model problem.

| Object | Canonical owner | Other modules | Action |
|---|---|---|---|
| Business Capability | **EA** (`ea_capabilities`) | Risk, BCP, Reports | **Retire `BusinessCapability`**; migrate rows; expose a read-through accessor for compatibility |
| Business Process | **EA** (`ea_processes`) | BCP/BIA, ISMS, PCI | **Retire `BusinessProcess`**; BIA writes RTO/RPO *into* the EA process |
| Business Service | **EA** (new `ea_business_services`, or promote `ValueStream`) | BCP, Monitoring, Issues | Reconcile `BusinessService` + `ServiceDependency` into EA; keep the Monitoring read path |
| Application | **EA** (`ea_applications_ext`) | Assets, Vulnerability, Incident | EA is master for the *logical* application; `Asset` remains master for the *physical* instance; hard-link via `asset_id` |
| Technology Component | **EA** (`ea_tech_components_ext`) | Assets, Vulnerability | Same split |
| Asset (physical/CMDB) | **Assets module** | EA reads | Scheduled sync, both directions |
| Risk | **Risk module** | EA raises | EA never owns a risk; it *creates* one |
| Control | **Compliance module** | EA maps | `ea_control_mappings.control_id` → real FK |
| Vendor | **TPRM module** | EA reads + analyses | `ea_applications_ext.vendor_id` → real FK; TPRM maintains it |
| Incident / Breach | **SecurityOps** | EA supplies impact | EA supplies blast radius and data context |
| BIA record | **BCP module** | EA consumes | BIA criticality/RTO/RPO flows into EA process and capability |
| KRI | **Platform KRI module** | EA publishes | EA KRIs publish into core `Kri`/`KriReading` |
| Obligation | **Regulatory Intel** | EA + returns consume | The §6.2 calendar lives here |
| Evidence | **Evidence Vault** | EA registers | EA evidence packs register as vault items |
| Legal Entity / Jurisdiction | **New — Platform** | All modules | New shared object (A6); every module becomes entity-aware |
| Site / Data Centre | **New — EA** | Assets, BCP | New shared object (A5) |
| Person / Ownership | **Users + new EA subscription** | All | B1 |

### 7.3 The integration contracts

Fourteen contracts, each with a direction, a trigger and a payload. Status reflects the code as it stands today.

| # | Contract | Direction | Trigger | Payload / behaviour | Status | Phase |
|---|---|---|---|---|---|---|
| **I-1** | **Obsolescence → Risk** | EA → Risk | `RecomputeTechObsolescence` or `RunAnomalyRules` finds a component past EOL threshold with no replacement initiative | Upsert a `Risk` with category "Technology Obsolescence", inherent score from criticality × exposure, back-link to the `TechComponent`, owner from the EA ownership model. Re-runs update rather than duplicate; auto-close when remediated. | **Absent** — no reference to `App\Models\Risk` anywhere in `app/Services/Ea` | 2 |
| **I-2** | **CVE → Vulnerability** | EA → SecurityOps | `SyncCveFeed` matches a CVE to a deployed tech component | Create/update a `Vulnerability` linked to the affected applications and assets | Absent | 2 |
| **I-3** | **EA Application ↔ Asset** | Bidirectional | Scheduled (daily) | `AssetApplicationClient::syncIntoEa()` scheduled and hardened; `asset_id` integrity enforced; **new EA panel on the Asset detail page** showing capability, criticality, TIME score, residency | **One-way, manual, unconstrained, and the trigger route is orphaned** | 0/2 |
| **I-4** | **Control mapping → real Controls** | EA → Compliance | On save | `control_id` validated against `controls.id`; `ControlInheritanceService` reads actual control test status; coverage becomes citable in a return | **`control_id` is an unconstrained bigint seeded with `rand(1,80)`** | 2 |
| **I-5** | **Vendor ↔ TPRM** | Bidirectional | On vendor or application change | `vendor_id` maintained by TPRM workflow; `TprmSecurityRating` shown on Application detail; **reverse view on Vendor page**; concentration feeds TPRM dashboards | Partial — `VendorConcentrationService` is the only live cross-link | 2 |
| **I-6** | **BIA → EA Process / Capability** | BCP → EA | On BIA record save | RTO/RPO and criticality write into `ea_processes`; capability map heat-maps by BIA criticality; blast radius consumes real RTO | **Absent — EA process RTO/RPO columns are seeded, not read from `bia_records`** | 2 |
| **I-7** | **DPIA / Data flow ↔ Data Breach** | Bidirectional | On breach declaration | Breach screen pulls affected logical entities, data flows, DPIA and hosting/processor context for the **72-hour NDPC notification**; cross-border flow creation alerts the DPO | Absent | 2 |
| **I-8** | **EA KRI → Platform KRI** | EA → Platform | On `RecomputeKris` | `KriValue` publishes to core `Kri`/`KriReading` so EA metrics appear on board packs and executive dashboards | Absent — EA maintains a parallel KRI model | 2 |
| **I-9** | **ARB decision → Issue / Workflow** | EA → Issues, Workflow | On `arb.decide`, on exception approach-expiry | ARB conditions raise `Issue` records with owners and due dates; approvals ride the core `Workflow` engine rather than a bespoke state field; exception expiry fires 90/60/30-day reminders | Absent — both are self-contained EA state changes | 2 |
| **I-10** | **EA evidence → Evidence Vault** | EA → Vault | On evidence pack generation | Register as `EvidenceVaultItem` with hash, period and scope so auditors find it where they look | Absent — packs sit on local disk | 2 |
| **I-11** | **EA → CSAT answer attribution** | EA → CSAT | On CSAT preparation | CSAT answers cite EA entities; answer confidence reflects the entity's **quality-seal** state | Absent | 3 |
| **I-12** | **EA + all → Regulatory Returns** | Many → Returns | On return preparation | The returns module reads EA (inventory, connections, residency, concentration), CSAT, Risk, Control, Vendor, Incident and BCP; produces the §6.2 artefacts | **New module** | 3 |
| **I-13** | **Incident → EA impact** | SecurityOps → EA | On incident declaration | Incident detail shows blast radius, dependent business services, RTO exposure and third parties — inside the **CBN 30-minute response window** | Absent — blast radius is only reachable from EA | 2 |
| **I-14** | **Legal Entity → everything** | Platform → all | Always | New shared `LegalEntity` object; every EA object, risk, control, incident and return is entity-scoped; group roll-up views | **New** | 3 |

### 7.4 Changes required in other modules

Work that lands outside `app/*/Ea`, needed to make the above real.

| Module | Change | Driver |
|---|---|---|
| **Assets** | EA panel on Asset detail (capability, criticality, TIME, residency); accept scheduled EA sync; reconcile logical-vs-physical semantics | I-3 |
| **Risk** | Accept machine-created risks with a `source` discriminator and back-link; suppress duplicate creation; render the EA back-link on Risk detail | I-1 |
| **Compliance / Controls** | Expose a control-resolution API for EA mapping; surface "architecture components covered by this control" on Control detail | I-4 |
| **TPRM / Vendors** | Add `Vendor.role` (publisher / integrator / hosting / managed service / connectivity); maintain `application ↔ vendor` links as a workflow step; reverse dependency view | I-5, A4 |
| **BCP / BIA** | Write BIA criticality and RTO/RPO into EA processes rather than a private column; consume EA business services as the BIA scope list | I-6 |
| **SecurityOps** | Blast-radius panel on Incident detail; breach screen pulls data context; ticket creation from CVE-matched components | I-2, I-7, I-13 |
| **CSAT** | Answer-to-entity attribution; pre-fill from the returns engine; show evidence confidence from seal state | I-11 |
| **KRI / Board Packs** | Accept EA-sourced KRI readings; architecture section in the board pack template | I-8 |
| **Issues / Workflow** | Accept EA-raised issues; expose the workflow engine to ARB and exception approvals | I-9 |
| **Evidence Vault** | Accept EA-generated packs with hash and period metadata | I-10 |
| **Regulatory Intel** | Host the §6.2 obligation calendar; map circulars to affected EA object types | I-12 |
| **Platform / Admin** | New `LegalEntity` object and entity-scoping middleware; new **Enterprise Architect / Solution Architect / Application Owner / Data Steward** roles; fix the EA permission gating | I-14, RC-2, RC-3 |
| **Navigation** | Restructure EA into 8 workspaces; promote Regulatory Returns to top level; global ⌘K search | RC-3, §5.5 |

### 7.5 Event design

Introduce a small set of domain events rather than direct service calls between modules. This keeps the seams clean and makes each contract testable in isolation.

```
Ea\Events\
  TechComponentBecameObsolete      → RiskModule\Listeners\OpenObsolescenceRisk
  CveMatchedToComponent            → SecOps\Listeners\OpenVulnerability
  ArbDecisionRecorded              → Issues\Listeners\OpenConditionIssues
  ExceptionApproachingExpiry       → Notifications, Issues
  CrossBorderDataFlowCreated       → Privacy\Listeners\NotifyDpo
  ArchitectureEntityChanged        → Ea\Listeners\BreakQualitySeal          (B3)
  EvidencePackGenerated            → EvidenceVault\Listeners\Register

Core\Events\
  BiaRecordSaved                   → Ea\Listeners\UpdateProcessCriticality
  IncidentDeclared                 → Ea\Listeners\AttachBlastRadius
  AssetSynchronised                → Ea\Listeners\ReconcileApplication
  VendorAssessmentCompleted        → Ea\Listeners\RefreshConcentration
  ReturnPeriodOpened               → Ea\Listeners\SnapshotArchitecture
```

Every listener must be idempotent — I-1 in particular will fire repeatedly against the same component and must update rather than accumulate.

---

## 8. Target Data Model and Service Changes

### 8.1 New tables

| Table | Purpose | Feature |
|---|---|---|
| `ea_subscriptions` | `(entity_type, entity_id, user_id, role)` where role ∈ responsible / accountable / consulted / observer | B1 |
| `ea_quality_seals` | `(entity_type, entity_id, state, approved_by, approved_at, expires_at, break_reason)` | B3 |
| `ea_surveys` | Definition: name, target entity type, scope query, fields, schedule | B2 |
| `ea_survey_campaigns` | Instance of a survey: window, audience snapshot, reminder policy | B2 |
| `ea_survey_responses` | Per-recipient, per-entity response with a magic-link token for non-licensed users | B2 |
| `ea_decision_records` | ADR: title, context, decision, consequences, status, supersedes, linked entities | B4 |
| `ea_sites` | Data centre / site with tier, operator, power, connectivity, geography | A5 |
| `legal_entities` | **Platform-level.** Jurisdiction, regulator(s), licence class, parent | A6 |
| `ea_application_instances` | Deployment of an application at a legal entity + site, with its own residency and criticality | A6 |
| `ea_channels` | Branch / ATM / POS / agent / USSD / mobile / internet / API, with third-party dependency | A7 |
| `ea_rails` | NIBSS NIP, NQR, BVN, NCS, switches, card schemes | A7 |
| `ea_score_cards` | Computed 4×6 completeness grid per period | B5 |
| `regulatory_returns` | Return instance: template, period, owner, due date, state, sign-off chain, archive hash | A1 |
| `regulatory_return_citations` | `(return_id, question_ref, entity_type, entity_id, seal_state_at_capture)` | A1 |

### 8.2 Altered tables

| Table | Columns to add |
|---|---|
| `ea_applications_ext` | `hosting_country`, `hosting_site_id`, `hosting_model`, `dr_country`, `dr_site_id`, `contains_nigerian_payment_data`, `annual_cost`, `cost_currency`, `contract_end_date`, `renewal_notice_days`, `licence_model`, `legal_entity_id` |
| `ea_tech_components_ext` | `hosting_site_id`, `annual_cost`, `cost_currency`, `origin_country` |
| `ea_interfaces` | **`objective`** (the CBN App. II §1.1(j) requirement), `review_cadence`, `last_reviewed_at`, `crosses_border`, `counterparty_vendor_id`, `counterparty_type` (regulator / switch / third party) |
| `ea_data_flows` | `transfer_basis`, `transfer_approval_reference`, `source_country`, `destination_country` |
| `ea_logical_entities` | `ndpa_classification`, `retention_period`, `processor_vendor_id` |
| `ea_control_mappings` | `control_id` → **constrained FK**; `coverage_basis`; `last_verified_at` |
| `vendors` (core) | `role` (publisher / integrator / hosting / managed service / connectivity), `substitutability_score`, `origin_country` |
| All EA tables | `tenant_id` → **`organization_id`** with a compatibility accessor during migration |

### 8.3 New services

| Service | Responsibility |
|---|---|
| `Ea\OwnershipService` | Resolve responsible/accountable parties for any entity; power notification routing |
| `Ea\SurveyEngine` | Build campaigns, resolve audiences (subscription-based or saved-query-based), issue magic links, ingest responses, track completion, fire reminders |
| `Ea\QualitySealService` | State machine; break-on-edit rules; scheduled auto-expiry; completeness score |
| `Ea\ScoreCardService` | Compute the 4×6 grid from repository completeness and seal state (B5) |
| `Ea\ResidencyService` | Localisation gap computation; cross-border register; sovereign readiness (A2) |
| `Ea\FxExposureService` | Currency aggregation, stress test, renewal calendar, localisation candidates (A3) |
| `Ea\ConcentrationService` | Extend `VendorConcentrationService`: HHI index, integrator dimension, SPOF register, substitutability (A4) |
| `Ea\SiteResilienceService` | Geographic concentration detection; resilience posture vs BIA (A5) |
| `Ea\GraphResolver` | Validate and resolve every cross-module reference; the single place soft FKs are enforced |
| `Returns\ReturnCompiler` | Compile a return from its data-dependency graph; emit citations with seal state (A1) |
| `Returns\CsatMapper`, `Returns\NdpcCarMapper`, `Returns\ItsbMaturityMapper` | Per-return mapping logic |

### 8.4 API surface

- **REST** — already present via `routes/api.php` and `EaApiController`. Extend to full CRUD parity with the web routes.
- **GraphQL** — add for the architecture graph. LeanIX ships it; Ardoq does not. Cheap differentiation and it is what an integrator will ask for.
- **MCP** — `McpServer` exists. Extend from read-only to **permission-aware scoped writes**, mirroring Ardoq's MCP + Data Ingestion Agent pattern, with all writes landing in a draft state requiring human approval (the Ardoq Scenario-merge pattern).
- **ArchiMate Open Exchange** — round-trip verification against Archi and Sparx EA as a **release gate**, not a feature.

---

## 9. Phased Implementation Plan

Sprints assume a two-week cadence and a team of 3 engineers (2 full-stack, 1 frontend) plus a product owner with banking domain access. Effort is indicative.

### Phase 0 — Make it usable *(2 sprints)*

**Goal: a user with the right role can create, edit and delete every EA object, and can find it.**

| WS | Work | Effort |
|---|---|---|
| 0.1 | **Wire the 30 orphaned endpoints.** Build the create/edit forms and index action bars for Applications, Capabilities, Technology, Interfaces, Principles, Standards, Exceptions, Initiative dependencies and deliverables, KRI recompute, threat techniques, diagram delete. Extract a shared `EaEntityForm` + `EaIndexToolbar` so this is 12 compositions, not 30 bespoke pages. | 6d |
| 0.2 | **Fix RBAC.** Correct `EaPolicy` permission strings; **register the policy** (it currently has no `AuthServiceProvider` mapping at all); remove the default-allow fallback; call `authorize()` in every controller action; add per-route middleware to all 44 write routes; add **Enterprise Architect**, **Solution Architect**, **Application Owner** and **Data Steward** roles with correct grants. | 3d |
| 0.3 | **Restructure navigation** into the 8 workspaces of §5.5; delete the 8 killed pages; merge the surfaces per §5.2; add breadcrumbs and a workspace-level tab pattern. | 4d |
| 0.4 | **Data Sources screen.** Expose the three sync actions; schedule them; **label feed provenance** (`live feed` / `bundled fixture`) with last-successful-fetch timestamps. | 2d |
| 0.5 | **Search, filter, pagination and export** on every index — matching the pattern the Risks and Incidents modules already use. | 4d |
| 0.6 | **Empty states with a first action** on every workspace. A blank EA repository must tell the user what to do, not show an empty table. | 2d |

**Exit criteria.** Zero orphaned write endpoints. A Viewer cannot write. An Enterprise Architect can perform every catalogue operation. Sidebar has 8 EA entries. `RouteSmokeTest` extended to cover EA POST/PUT/DELETE with correct 403/200 assertions.

### Phase 1 — Make it stay true *(3 sprints)*

**Goal: the repository maintains itself.**

| WS | Work | Effort |
|---|---|---|
| 1.1 | **Ownership & subscriptions (B1).** `ea_subscriptions`; owner pickers on every entity; "My architecture" view; ownership completeness metric. | 5d |
| 1.2 | **Survey & campaign engine (B2).** Form builder; audience resolution from subscriptions and from saved queries; recurring schedules; staleness triggers; **magic-link responses from non-licensed users** (beats LeanIX); reminders; completion dashboard. | 12d |
| 1.3 | **Quality seal (B3).** State machine; break-on-edit rules; configurable 30/60/90-day auto-expiry job; mandatory-attribute gating; completeness score; seal indicator on every entity. | 7d |
| 1.4 | **Architecture Decision Records (B4).** New object; ARB integration; supersession chain; search. | 4d |
| 1.5 | **Notifications.** Email + in-app for survey invitations, reminders, seal expiry, exception expiry, anomaly assignment. Reuse `BaseNotification`. | 3d |
| 1.6 | **Business-user portal (light).** Read-only scoped views + a **My Tasks** queue, so survey recipients have somewhere to land. | 5d |

**Exit criteria.** A campaign can be launched against 200 applications, delivered to non-licensed owners by magic link, tracked to completion, and the responses visibly change seal state and completeness score.

### Phase 2 — Fuse it to the suite *(3 sprints)*

**Goal: the EA graph is the platform's graph.**

| WS | Work | Effort |
|---|---|---|
| 2.1 | **Canonical model decision and migration** (§7.2). Retire duplicate `BusinessCapability` / `BusinessProcess` / `BusinessService`; migrate data; add compatibility accessors. **Product decision required before code.** | 6d |
| 2.2 | **Tenancy standardisation.** `tenant_id` → `organization_id` across 48 tables with a compatibility layer. | 3d |
| 2.3 | **Contracts I-1 through I-10** — the event bus, listeners, back-links and reverse views described in §7.3/§7.5. | 14d |
| 2.4 | **Fix the seeders.** No `rand()` into foreign keys anywhere. `DemoCrossLinkSeeder` extended to EA. | 3d |
| 2.5 | **Architecture Score Card (B5).** Compute and render the 4×6 grid; trend by period. | 5d |
| 2.6 | **Incident and Asset panels** — blast radius on Incident detail; EA panel on Asset detail. | 4d |

**Exit criteria.** An EOL finding creates a risk in the core register with a working back-link; a BIA change updates EA process criticality; an ARB decision opens issues; control coverage is computed over real control IDs; EA KRIs appear on the board pack.

### Phase 3 — The African wedge *(4 sprints)*

**Goal: the features no competitor will build.**

| WS | Work | Effort |
|---|---|---|
| 3.1 | **Legal Entity + application instances (A6).** Platform object, entity-scoping, per-jurisdiction views, group roll-up. | 8d |
| 3.2 | **Site object + power/resilience (A5).** Object, Nigerian seed catalogue, concentration alerts, resilience posture vs BIA. | 6d |
| 3.3 | **Residency & localisation control tower (A2).** Attributes, gap register with countdown, cross-border map, sovereign readiness. | 8d |
| 3.4 | **FX exposure lens (A3).** Cost/currency model, dashboard, stress test, renewal calendar, localisation candidates. | 6d |
| 3.5 | **Concentration deepening (A4).** HHI, integrator dimension, SPOF register, substitutability, reverse Vendor view. | 5d |
| 3.6 | **Channel & rails model (A7).** New types, telco/aggregator dependency, switch licence-condition attributes. | 5d |
| 3.7 | **Regulatory Returns module (A1, I-11, I-12).** Return object, compiler, citation model with seal state, sign-off chain, hash-sealed archive, period diff. CSAT pre-fill, ITSB maturity, NDPC CAR extract, localisation gap report, quarterly board pack, incident 24-hour annex. | 18d |
| 3.8 | **Nigerian Banking Reference Architecture pack (§6.4).** Content authoring + installer + versioning as a subscribable content package. | 10d |

**Exit criteria.** A design-partner bank can produce a CSAT pre-fill pack, an ITSB maturity assessment against its category target, an NDPC CAR extract and a localisation gap report — each with entity-level citations and evidence-quality indicators — from its own data.

### Phase 4 — Depth and scale *(4 sprints)*

| WS | Work | Effort |
|---|---|---|
| 4.1 | **Real diagram editor (B13).** Drag-drop palette, click-drag edges with ArchiMate validation, auto-layout, versioning, export. | 12d |
| 4.2 | **n-hop impact (B14)** with depth control and a change-impact tab on every entity. | 6d |
| 4.3 | **Plateau diff / scenario comparison (B15)** with cost, risk and count deltas — the post-merger rationalisation feature. | 8d |
| 4.4 | **ArchiMate round-trip release gate** against Archi and Sparx EA. | 4d |
| 4.5 | **GraphQL API + MCP scoped writes** with draft-and-approve. | 6d |
| 4.6 | **Test coverage.** Feature tests per endpoint; Playwright happy paths for ARB, survey campaign, DPIA and return generation; performance benchmark at 5,000 nodes / 20,000 relationships. | 10d |
| 4.7 | **Cost & TCO algorithms (B17)** beyond FX — cost per capability, TCO, technical debt scoring. | 6d |

---

## 10. Non-Functional Requirements and Release Gates

| Area | Requirement |
|---|---|
| **Performance** | Capability tree, portfolio grid and blast radius must render within 2s at 5,000 entities / 20,000 relationships. The current `byParent` recursive render and JSON-column filtering will not hold; add materialised closure tables for hierarchies and indexed columns for anything filtered. |
| **Multi-tenancy** | Automated test proving no cross-organisation leakage on every EA endpoint. `BelongsToTenant`'s `orWhereNull` shared-reference-data semantics must be explicitly tested — it is a plausible leak path. |
| **Authorisation** | Every write route gated; every controller action authorised; a test asserting Viewer receives 403 on all 44 write routes. |
| **Data provenance** | Every feed-sourced field displays source and fetch timestamp. Fixture fallbacks must be visibly labelled. **Release gate.** |
| **Evidence integrity** | Every generated return and evidence pack hash-sealed, immutable once signed, with the signer and timestamp recorded. |
| **Auditability** | `ea_audit_log` covers every write. Rollback for accidental bulk changes (Avolution ships this; it is a governance selling point). |
| **Deployment** | Must install and run air-gapped inside a Nigerian data centre with no outbound internet. Feeds degrade gracefully **and say so**. |
| **Accessibility & bandwidth** | Usable on 3G. Nigerian branch and regional-office connectivity is a real constraint; heavy graph views need progressive loading. |
| **Localisation readiness** | UI string extraction from the start. Ardoq's English-only surveys are a documented weakness; do not repeat it in a francophone West African expansion. |

---

## 11. Commercial Packaging

**The dual-buyer motion.** EA is bought by the CIO/CTO; GRC by the CISO/CRO. Sell EA as the **bridge SKU**: the CIO gets portfolio, radar, ARB and roadmap; the CISO gets CSAT evidence, control coverage, zones and threat models; **the CFO gets the FX exposure lens** — all from one dataset, priced once. In Nigerian banks the CSAT deadline creates the compelling event and the CIO's transformation programme funds the licence.

**Four wedge plays:**

1. **The return, not the tool.** Lead with "produce your CSAT architecture evidence and your ITSB maturity assessment from live data." The buyer is not shopping for an EA tool — they have a deadline.
2. **Localisation compliance.** Between now and 1 January 2027, "which systems hold Nigerian payment data and where do they and their DR sites physically run" is a board-level question with a legal deadline. It is an EA query and nothing else in the market answers it.
3. **Post-merger rationalisation.** 33 recapitalised banks, mergers in flight. Offer a fixed-scope **estate mapping sprint** (4–6 weeks: consultants + bulk-import templates + the reference architecture pack) as paid discovery that seeds the repository and lands the licence.
4. **Upsell to the existing GRC base.** For banks already on Atheris, EA turns CSAT portal answers from prose into graph-backed evidence.

**Pricing.** Naira-denominated, annually invoiced, **unlimited users**, tiered by institution class:

| Tier | Institutions |
|---|---|
| Tier A | International commercial banks (₦500bn licence) |
| Tier B | National commercial / merchant / non-interest banks |
| Tier C | Regional banks, PSBs |
| Tier D | PSPs, microfinance banks |

Per-seat pricing is dated (Ardoq's unlimited-users precedent) and directly penalises the crowdsourcing the survey engine depends on. Add a **regulatory content subscription** (reference architecture updates, CBN/NDPC content, CSAT mapping) as recurring revenue. Offer **on-premises and Nigerian-hosted deployment as first-class** — it is a structural advantage over SaaS-only Ardoq and SAP-hosted LeanIX, and it is exactly what the localisation directive rewards.

**Channel.** DPCOs (every UHL entity must file through one — a mandated, recurring, under-tooled intermediary with a direct line to the CISO and DPO) and local integrators. Note the conflict on the integrator route: CWG is itself the concentration risk that feature A4 measures.

**Positioning line.** *"The only architecture repository that files your CBN return."*

---

## 12. Risks, Assumptions and Open Decisions

### 12.1 Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R1 | **ITSB enforcement is dormant.** If the IT Standards Governance Council is not actively assessing architecture maturity, the strongest narrative weakens. | High | Verify with a design partner **before** Phase 3 commits. The RBCF clause map and the localisation deadline stand independently. |
| R2 | **CSAT internal structure differs from our assumption**, making pre-fill mapping wrong. | High | Obtain the actual CSAT instrument from a design-partner CISO. Highest-value single artefact in this document. |
| R3 | **Hyperscalers already have Nigerian regions**, making localisation a contractual exercise rather than an architecture event. | Medium | Verify before go-to-market. A2 retains value either way — the *reporting* obligation persists. |
| R4 | **The canonical-model migration breaks existing modules.** | High | Phase 2.1 behind a feature flag with compatibility accessors and a reversible migration. |
| R5 | **Survey engine adoption fails** — recipients ignore magic links. | High | Ship completion analytics from day one; escalation to line manager via the ownership graph; make the CISO's return dependency visible ("your return is 62% evidenced"). |
| R6 | **Scope inflation.** Phases 3 and 4 are each larger than Phases 0–2 combined. | Medium | Phases 0–2 are independently shippable and already produce a saleable module. Do not start Phase 3 until Phase 1 exit criteria are met. |
| R7 | **Air-gapped feeds present fixtures as live data** in an evaluation. | High — credibility | Provenance labelling is a Phase 0 release gate, not a nice-to-have. |
| R8 | **ServiceNow bundling.** A tier-1 bank is told "you already own architecture in ServiceNow." | Medium | Gartner's own caution is the counter: ServiceNow's base package **lacks modelling and roadmapping**. And it has no CBN return. |

### 12.2 Assumptions

- A design-partner bank can be secured for Phase 3 requirements validation.
- The team can access at least one real CSAT instrument and one real ITSB assessment outcome.
- On-premises deployment inside a Nigerian bank data centre is achievable with the current Laravel/MySQL/Redis stack.
- Naira-denominated, unlimited-user pricing is commercially acceptable to the business.

### 12.3 Open decisions for product

| # | Decision | Owner | Needed by |
|---|---|---|---|
| D1 | **Which capability/process/service model is canonical** — EA or core? (§7.2) | Product | Before Phase 2 |
| D2 | Does **Regulatory Returns** become a separate SKU or is it bundled with EA? | Commercial | Before Phase 3 |
| D3 | Do we build to **TOGAF 9.2** (what CBN names) or TOGAF 10? *Recommendation: 9.2 primary, 10 as an alternate framework pack.* | Product | Phase 3 |
| D4 | Is the **Nigerian Reference Architecture pack** included or a paid content subscription? | Commercial | Phase 3 |
| D5 | Do we pursue **DPCO channel partnerships** now or after the first two direct wins? | Commercial | Q4 2026 |
| D6 | Ghana second or Kenya second? *Recommendation: Ghana — Nigerian banks are already there and A6 makes it near-free.* | Commercial | 2027 |
| D7 | Do we retire `Solutions` and `InfoDomains` data, or migrate it? *Recommendation: migrate under a discriminator; no data loss.* | Engineering | Phase 0 |

---

## 13. Verification Log

Every quantitative claim in §2 is reproducible. Run from the repository root.

```bash
# EA route counts (51 GET, 44 write)
awk '/Route::prefix\(.ea.\)/,/^    \}\);/' routes/web.php | grep -cE "Route::get"
awk '/Route::prefix\(.ea.\)/,/^    \}\);/' routes/web.php | grep -cE "Route::(post|put|delete|patch)"

# Orphaned write endpoints (expect 30 of 44)
awk '/Route::prefix\(.ea.\)/,/^    \}\);/' routes/web.php \
  | grep -E "Route::(post|put|delete|patch)" \
  | grep -oE "name\('[^']+'\)" | sed "s/name('//;s/')//" | sed 's/^/ea./' | sort -u \
  | while read r; do grep -rq "$r" resources/js || echo "ORPHAN $r"; done

# Write routes carrying their own permission middleware (expect 0)
awk '/Route::prefix\(.ea.\)/,/^    \}\);/' routes/web.php \
  | grep -E "Route::(post|put|delete|patch)" | grep -c "middleware"

# Controller never authorises (expect 0)
grep -c "authorize\|Gate::\|->can(" app/Http/Controllers/EaController.php

# EaPolicy is never registered (expect hits only inside the policy file itself)
grep -rn "EaPolicy" app/ bootstrap/ config/

# Pages with zero interactive elements (expect 27 of 46)
cd resources/js/Pages/Ea && for f in *.jsx; do
  grep -qE "<input|<select|<textarea|TextInput|<button|onClick|useForm|router\.(post|put|delete)" "$f" || echo "$f"
done | wc -l

# Total EA page LOC (expect ~3,070 across 46 files)
cat resources/js/Pages/Ea/*.jsx | wc -l

# EA nav item count (expect 40)
sed -n '277,316p' resources/js/Config/navigation.js | grep -c "href:"

# Permission-name mismatch
grep -oE "'ea\.[a-z]+'" app/Policies/Ea/EaPolicy.php | sort -u   # ea.view, ea.write, ea.approve, ea.admin
grep -n "'ea'" database/seeders/RolesAndPermissionsSeeder.php     # creates "view ea", "create ea", ...

# Tenancy split
grep -c "tenant_id" database/migrations/2026_11_01_000001_create_ea_phase1_tables.php   # 14
grep -c "organization_id" database/migrations/2026_11_01_000001_create_ea_phase1_tables.php  # 0

# Seeded random foreign key
grep -n "rand(1,80)" database/seeders/EaPhase2Seeder.php
```

**Research provenance.** Competitor findings are drawn from vendor documentation, help centres, UK G-Cloud 14 service definitions and pricing documents (the only reliable public price source in this category), the 2025 Gartner Magic Quadrant for Enterprise Architecture Tools, the Forrester Wave for EA Management Suites Q4 2024, and user reviews on G2, PeerSpot, SoftwareReviews and Gartner Peer Insights. Regulatory findings are drawn from CBN, NDPC and NITDA primary documents where available and from Nigerian legal-practice commentary where not. **Items marked ⚠️ could not be verified from public sources and must be confirmed with a design partner before appearing in customer-facing collateral.**

---

## Appendix A — Orphaned Write Endpoint Register

Full list, for use as the Phase 0.1 backlog. Each row is one form or action to build.

| # | Endpoint | Target surface | Form/action needed |
|---|---|---|---|
| 1 | `ea.applications.store` | Portfolio → Applications | Create form (25+ fields incl. new residency/cost) |
| 2 | `ea.applications.update` | Portfolio → Application detail | Edit form + inline field edit |
| 3 | `ea.applications.destroy` | Portfolio → Applications | Delete with dependency guard |
| 4 | `ea.capabilities.store` | Business Architecture | Add-child action on tree node |
| 5 | `ea.capabilities.update` | Business Architecture | Inline rename + criticality; drag-to-reparent |
| 6 | `ea.capabilities.destroy` | Business Architecture | Delete with descendant guard |
| 7 | `ea.tech.store` | Portfolio → Technology | Create form incl. EOL/EOS, site, cost |
| 8 | `ea.tech.update` | Portfolio → Technology | Edit + ring assignment |
| 9 | `ea.tech.destroy` | Portfolio → Technology | Delete with usage guard |
| 10 | `ea.interfaces.store` | Integration Architecture | Create with source/target pickers + **objective** field |
| 11 | `ea.interfaces.update` | Integration Architecture | Edit + review-date stamp |
| 12 | `ea.interfaces.destroy` | Integration Architecture | Delete |
| 13 | `ea.principles.store` | Governance → Principles | Create + publish |
| 14 | `ea.principles.update` | Governance → Principles | Edit + version |
| 15 | `ea.principles.destroy` | Governance → Principles | Retire (soft) |
| 16 | `ea.standards.store` | Governance → Standards | Create + link to principle |
| 17 | `ea.standards.update` | Governance → Standards | Edit + lifecycle transition |
| 18 | `ea.standards.destroy` | Governance → Standards | Retire (soft) |
| 19 | `ea.exceptions.store` | Governance → Exceptions | Raise waiver with expiry and compensating control |
| 20 | `ea.exceptions.renew` | Governance → Exceptions | Renew action + approval |
| 21 | `ea.initiatives.dependencies.store` | Transformation → Initiative | Add dependency picker |
| 22 | `ea.initiatives.deliverables.store` | Transformation → Initiative | Add ADM deliverable |
| 23 | `ea.deliverables.update` | Transformation → Initiative | Update deliverable status |
| 24 | `ea.kri.recompute` | Command Centre → KRIs | Recompute button + last-run stamp |
| 25 | `ea.threats.techniques.store` | Security Architecture | Add technique to threat model |
| 26 | `ea.diagrams.destroy` | Diagrams | Delete |
| 27 | `ea.sync.assets` | Settings → Data Sources | Run + schedule + provenance |
| 28 | `ea.sync.eol` | Settings → Data Sources | Run + schedule + provenance |
| 29 | `ea.sync.cve` | Settings → Data Sources | Run + schedule + provenance |
| 30 | `ea.mcp.rpc` | *(no UI needed)* | Move config to Settings → Integrations |

## Appendix B — CBN Clause → Feature Map

| Instrument | Clause | Feature |
|---|---|---|
| ITSB v2.1 | TOGAF 9.2 adoption; Level 3 / Level 2 targets by category; Governance Council assessment | A1 ITSB maturity assessment; B5 Score Card |
| ITSB v2.1 | All four ADM domains required | Business / Application / Data / Technology workspaces |
| ITSB v2.1 | ISO 22301, TIA-942 | A5 Site object with TIA-942 tier; BCP interlock I-6 |
| RBCF 2024 §3.1 | Inventory of software, hardware, internal and external network connections | Portfolio + Integration workspaces |
| RBCF App. II §1.1(a) | Assets on-prem **and in third-party cloud** | A2 hosting model / country |
| RBCF App. II §1.1 | Asset ownership assigned | B1 ownership model |
| RBCF App. II §1.1 | Criticality and data-sensitivity categorisation | Criticality tiering + NDPA classification |
| RBCF App. II §1.1(g)–(h) | Data classification, handling, disposal | Data & Privacy workspace |
| **RBCF App. II §1.1(i)–(k)** | **Approved network topology diagram; catalogue of all connections to regulators, switches and third parties with documented objective, regularly reviewed** | **`ea_interfaces.objective` + review cadence + Diagrams (B13)** |
| RBCF App. II §1.2 | Quarterly vulnerability assessment of all IT assets | I-2 CVE → Vulnerability; quarterly board pack |
| RBCF App. II §1.4 | Third-party register; all third-party connections documented; CSP assessed pre-adoption | A4 + I-5 |
| RBCF §2.3 | Third-party risk framework | A4 concentration |
| RBCF §2.4 | Current profile → target profile → **roadmap** | Transformation workspace + A1 CSAT pre-fill |
| RBCF §2.5 | Annual CSAT, CISO-signed, 28 Feb ⚠️ | A1 with sign-off chain |
| RBCF §5.0 / App. VII | 24-hour incident notification | I-13 + A1 incident annex |
| Localisation directive (15 Jun 2026) | Nigerian payment data stored and managed in Nigeria by **1 Jan 2027**; affects primary **and DR** | **A2** |
| Market structure directive | Monthly returns; 31 Dec 2026 compliance | §6.2 calendar |
| NDPA / GAID 2025 | CAR by 31 Mar via DPCO; cross-border transfers; third-party processors; DPIA | A1 CAR extract; Data & Privacy workspace; I-7 |
| Open Banking Operational Guidelines 2023 | Tier 0–3 data classification; OBR; consent management; KYP | A7 + Data & Privacy |
| PSV 2028 | Interoperability, security, inclusion, innovation, trust, collaboration ⚠️ *(technical mandates unverified)* | A7 rails model |

## Appendix C — Navigation, Before and After

| Before (40 flat links) | After |
|---|---|
| Command Centre, CBN EA Maturity, EA KRIs, Anomaly Inbox, Audit Trail | **1. Command Centre** (+ Score Card) |
| Capability Map, Value Streams, Process Inventory | **2. Business Architecture** |
| Application Portfolio, Technology Radar, Vendor Concentration | **3. Portfolio** |
| Interfaces, API Register, Blast Radius | **4. Integration Architecture** |
| Information Domains, Logical Entities, Data Flows, Business Glossary, DPIA & Privacy | **5. Data & Privacy Architecture** |
| Security Zones, Control Mappings, Threat Models | **6. Security Architecture** |
| Plateaux, Initiatives, Roadmap, ADM Phase Tracker, Scenario Compare | **7. Transformation** |
| Principles, Standards, ARB, Exceptions | **8. Architecture Governance** (+ Decision Records) |
| CBN Evidence Packs | → **Regulatory Returns** (suite level) |
| Diagrams & Viewpoints, Motivation Layer | → Diagrams (viewpoints as a render mode); Motivation folded into Governance |
| ArchiMate Exchange, Bulk Import | → Settings → Data Sources |
| NL Search | → global ⌘K palette |
| MCP Server | → Settings → Integrations |
| Reference Patterns, Solutions | → merged into Reference Patterns |

---

*End of document. ATH-EAR-002 v1.0 — 16 August 2026.*
