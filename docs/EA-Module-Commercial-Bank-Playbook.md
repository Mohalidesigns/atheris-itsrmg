# Atheris ITSRMG — Enterprise Architecture Module
## Commercial Bank Playbook

**Document ID:** ATH-PLB-EA-001
**Version:** 1.0
**Date:** 10 July 2026
**Product:** Atheris ITSRMG — Enterprise Architecture module (`app/Models/Ea`, `app/Services/Ea`, `resources/js/Pages/Ea`)
**Related documents:** ATH-GAP-EA-001 v1.0 (Gap Analysis), ATH-PIP-EA-001 v1.0 (Phase Implementation Plan)
**Audience:** Product, Sales Engineering, Delivery, and bank-side CIO/CISO stakeholders
**Classification:** Atheris Confidential

---

## 1. Executive Summary

The Enterprise Architecture (EA) module inside Atheris ITSRMG is a purpose-built EA repository for Nigerian financial institutions, delivered **inside the same product as the bank's risk register, control library, vendor management, BCP/BIA, incident management, and CBN CSAT modules**. It is substantial: 46 Eloquent models under `app/Models/Ea/`, 19 domain services under `app/Services/Ea/`, roughly 90 routes served by a single 1,014-line `EaController`, some 45 React pages under `resources/js/Pages/Ea/`, and four phase seeders that install a realistic Nigerian-bank reference dataset (BIAN-style capabilities, core banking applications, technology radar entries with EOL dates, NDPA-classified data entities).

Since the May 2026 gap analysis (ATH-GAP-EA-001) — which found a "read-only demo skin" with zero write paths — the module has moved materially. Full CRUD now exists for capabilities, applications, technology components, interfaces, principles, standards, and diagrams. Create-plus-workflow paths exist for ARB submissions (with decision flow), exceptions (with renewal), DPIAs, glossary terms, threat models, and initiatives. The stub evidence-pack and exchange generators called out in the gap analysis have been replaced by real services (`EvidencePackGenerator`, `ArchiMateExchange`), and the gap analysis's recommended services — `RelationshipValidator`, `AuditLogger`, `ControlInheritanceService`, `BlastRadiusService`, `AnomalyEngine`, `KriCalculator`, `McpServer` — all now exist in `app/Services/Ea/`.

Why it matters for a Nigerian commercial bank, in one paragraph: CBN supervision has shifted from periodic examination to continuous, portal-based, evidence-backed self-reporting — the Risk-Based Cybersecurity Framework's annual CISO self-assessment (due 31 March), the March 2026 CSAT circular mandating structured submissions on governance, risk, third-party dependency and resilience, the January 2026 directive on sub-30-minute fraud response and vendor concentration, and NDPA/GAID 2025 obligations including DPIAs and CAR returns. Every one of those asks a question that only an architecture inventory can answer: *which applications support which critical functions, on what technology, through which vendors, carrying whose personal data, protected by which controls?* Global banks buy LeanIX or Ardoq to answer that question and then wire it to a separate GRC platform. Atheris ships both halves in one product — no global GRC leader (ServiceNow aside, whose EA is a separate SKU) ships a native EA repository, and no EA leader ships a native risk register. That structural position is genuine; the remaining work, detailed in §8, is making the two halves actually talk to each other, because today the EA module is architecturally isolated from the core GRC models.

---

## 2. What Ships Today — Capability Inventory

Every claim below is grounded in a route name (from the `ea.` route group in `routes/web.php`), a page (`resources/js/Pages/Ea/`), a model (`app/Models/Ea/`) or a service (`app/Services/Ea/`).

### 2.1 Strategy & motivation
ArchiMate motivation-layer elements — `Goal`, `Driver`, `Stakeholder`, `Outcome`, `CourseOfAction` models (added in the phase-4 migration `2026_11_01_000004_create_ea_phase4_tables.php`) — surfaced on `Motivation.jsx` via `ea.motivation` (GET + POST store). This closes gap item P1-9 from ATH-GAP-EA-001 §5.2 at the create level; editing and goal-to-initiative propagation are not yet built.

### 2.2 Capability map & business architecture
`Capability` (5-level hierarchy, BIAN-tagged, criticality) with full CRUD (`ea.capabilities`, `.store`, `.update`, `.destroy`) on `CapabilityMap.jsx` and a detail view `CapabilityShow.jsx` showing linked applications. `ValueStreams.jsx` (`ea.value-streams`) and `Processes.jsx` (`ea.processes`, L1/L2/L3 with RTO/RPO fields) remain read-only lists.

### 2.3 Application portfolio (APM)
`EaApplication` with full CRUD (`ea.applications.*`). `ApplicationPortfolio.jsx` renders TIME scoring and the business-fit × technical-fit heat grid; `ApplicationShow.jsx` links capabilities, tech components, interfaces and security zone — still the strongest screens in the module, as the gap analysis noted. `BulkImport.jsx` (`ea.bulk-import`, with per-type CSV templates via `ea.bulk-import.template`) and `AssetApplicationClient::syncIntoEa()` (`ea.sync.assets`) provide two ingestion paths.

### 2.4 Technology & obsolescence
`TechComponent` CRUD (`ea.tech.*`) on `TechnologyRadar.jsx` (adopt/trial/assess/hold quadrants, EOL badges). `TechObsolescenceService` recomputes obsolescence flags; `EolFeedClient` targets endoflife.date and `CveFeedClient` targets the NVD 2.0 API, persisting `TechVulnerability` rows (`ea.sync.eol`, `ea.sync.cve`). **Caveat (see §5):** both feed clients fall back to small hard-coded fixtures (~17 products / 7 CVEs) when offline, and neither is scheduled — sync is a manual button press.

### 2.5 Data architecture & privacy
`InfoDomains.jsx`, `LogicalEntities.jsx` (classification + PII flags), `DataFlows.jsx` (cross-border flag, protocol, classification) — read-only. `Dpia.jsx` (`ea.dpia` + POST store) creates DPIA assessments against the `DpiaAssessment` model; `ConsentPurpose` ties flows to declared processing purposes. `Glossary.jsx` (create) gives non-technical stakeholders a business vocabulary. This is the NDPA-facing surface: the inventory and the DPIA record exist; the DPO-notification workflow and NDPC-format outputs do not.

### 2.6 Security architecture
`SecurityZones.jsx` (`ea.security-zones`) renders `Zone` (trust levels 1–5) and `ZoneAssignment`. `Threats.jsx` supports creating `ThreatModel` records and attaching `ThreatTechnique` rows (MITRE-style) via `ea.threats.store` / `ea.threats.techniques.store`. `ControlMappings.jsx` (`ea.control-mappings`) shows per-framework coverage (ISO/PCI/CBN/NDPA) and a portfolio coverage report computed by `ControlInheritanceService`, which resolves effective control posture per application from direct mappings, zone inheritance, and tech-component inheritance (vendor SOC 2 inheritance is an acknowledged stub in the class docblock). **Caveat:** `ControlMapping.control_id` is an unconstrained integer — no database FK to the core `controls` table, and `EaPhase2Seeder` fills it with `rand(1, 80)` (§4).

### 2.7 Governance
`Principle` and `Standard` with full CRUD (`ea.principles.*`, `ea.standards.*`). The ARB stack is the most complete workflow in the module: `ArbWizard.jsx` (`ea.arb.wizard`) → `arbStore` → kanban queue (`Arb.jsx`) → `ArbShow.jsx` → `arbDecide` (`ea.arb.decide`), supported by `PrincipleImpactEngine` and `BlastRadiusService`. Exceptions have create and renewal (`ea.exceptions.store`, `.renew`) on `Exceptions.jsx`. `Patterns.jsx` and `Solutions.jsx` are read-only libraries. `AuditTrail.jsx` (`ea.audit-trail`) surfaces the `AuditLog`, now actually written to via the `WritesAuditLog` trait on every EA model.

### 2.8 Roadmap, initiatives & plateaux
`Initiatives.jsx` with dependency creation (`ea.initiatives.dependencies.store`, `InitiativeDependency`) and TOGAF ADM deliverables (`ea.initiatives.deliverables.store`, `AdmDeliverable`, tracked on `AdmTracker.jsx`). `Roadmap.jsx` (Gantt), `Plateaux.jsx`, and `Scenarios.jsx` (`ea.scenarios`, backed by `ScenarioComparer` for plateau diffing) cover the transformation view. Plateaux themselves are read-only.

### 2.9 KRI & anomaly surveillance
`Kri.jsx` (`ea.kri`) with on-demand recomputation (`ea.kri.recompute`) via `KriCalculator`, which computes off `Capability`, `EaApplication`, `TechComponent`, `DataFlow`, `DpiaAssessment`, `Exception`, `Initiative`, `Plateau` — the KRI catalogue from ATH-GAP-EA-001 Appendix C. `Anomalies.jsx` runs `AnomalyEngine` rules (`ea.anomalies.run`) and supports acknowledge/resolve workflow (`ea.anomalies.ack`, `.resolve`) on `AnomalyFinding`.

### 2.10 Evidence packs, exchange, diagrams & AI surface
- `EvidencePacks.jsx` (`ea.evidence-packs.generate`, `.download`): `EvidencePackGenerator` now produces the real CBN evidence pack per ATH-GAP-EA-001 Appendix D — cover page, executive summary, per-domain maturity detail, architecture posture summary, attestation page — with SHA-256 hashes written to `ea_evidence_packs.pdf_hash/zip_hash` for tamper evidence. `CbnMaturity.jsx` (`ea.cbn-maturity`) carries the underlying CBN-EA self-assessment.
- `Exchange.jsx` (`ea.exchange.queue`, `.download`): `ArchiMateExchange` serialises to the ArchiMate Open Exchange format for interop with Archi/Sparx/Bizzdesign.
- `Diagrams.jsx` / `DiagramEditor.jsx` with full CRUD (`ea.diagrams.*`) plus `Viewpoint.jsx` (`ea.viewpoint` + JSON endpoint) driven by `ViewpointGenerator` — the data-driven viewpoint approach the gap analysis recommended.
- `NlSearch.jsx` (`ea.search`, `.search.json`, `NlSearchService`) and `Mcp.jsx` (`ea.mcp`, `ea.mcp.rpc`, `McpServer`) — an MCP endpoint so external LLM agents can query the EA repository, matching the position LeanIX took in early 2026.
- `BlastRadius.jsx` (`ea.blast-radius`), `Apis.jsx`, `Interfaces.jsx` (CRUD) cover integration architecture.
- `CommandCentre.jsx` (`ea.command-centre`) is the executive landing page.

### 2.11 Honest state summary
Of ~45 pages, roughly 24 remain read-only (value streams, info domains, logical entities, data flows, APIs, security zones, control mappings, processes, plateaux, patterns, solutions, vendor concentration, and the analytic/report views). CRUD is complete for the seven core catalogues; workflow exists for the six governance/privacy objects. The three sync actions exist but are manual, fixture-backed, and unscheduled. And the module is **architecturally isolated**: no EA table carries an enforced foreign key to the core GRC models (`Risk`, `Control`, `Asset`, `Vendor`), the only functioning cross-module read is `VendorConcentrationService`, and `AssetApplicationClient` reads the `assets` table defensively behind a `Schema::hasTable` check rather than a module contract. §4 treats this in full.

---

## 3. Day-in-the-Life Use Cases for a Commercial Bank

These are the demo narratives and pilot workflows the module supports **today**, with the honest boundary of each.

**3.1 Core banking migration (Finacle → T24) governed through the ARB.**
The transformation office raises the migration as an ARB submission via `ArbWizard.jsx`: subject, summary, impacted applications. `BlastRadiusService` computes the affected interface set (which channels, payment rails and NIBSS integrations touch the core), `PrincipleImpactEngine` flags which architecture principles the design touches, and the submission moves through the kanban to a recorded decision via `ea.arb.decide`, with every step written to the EA audit log. The migration itself is then tracked as an `Initiative` with ADM-phase deliverables on `AdmTracker.jsx` and dependencies on the Gantt. *Boundary:* the ARB decision does not yet open items in the core Issues module, and blast radius is interface-graph-based, not n-hop through the full relationship graph.

**3.2 EOL Windows Server fleet driving obsolescence risk.**
The infrastructure architect runs `ea.sync.eol`; `EolFeedClient` refreshes EOL/EOS dates (Windows Server is in the built-in fixture with a 2027 EOL) and `TechObsolescenceService` recomputes flags. The Technology Radar and Command Centre now show the within-12-months EOL population, `KriCalculator` moves EA-KRI-03 ("EOL components with no replacement initiative") into amber/red, and `AnomalyEngine` raises findings for EOL components with no linked initiative. *Boundary:* this is the flagship isolation gap — the finding **stops inside the EA module**. No risk register entry is created in the core `risks` table, so the CRO's view and the CISO's CSAT return do not see it unless someone re-keys it. §4 and §8 make closing this loop the P1 build item.

**3.3 Vendor concentration answer for CBN.**
Following the January 2026 directive's scrutiny of vendor concentration, the CIO opens `VendorConcentration.jsx` (`ea.vendor-concentration`). `VendorConcentrationService` joins the core `vendors` table to `ea_applications_ext.vendor_id` and returns apps, critical apps and annual spend per vendor — a real query, not the seeded random numbers the gap analysis condemned (§5.10 item 5, now fixed). This is currently the **only** live EA→GRC cross-link, which makes it both the best demo of the integration story and a reminder of how thin that story still is. *Boundary:* `vendor_id` is populated by seed/manual entry, not by TPRM workflow, and there is no reverse view (Vendor page showing dependent applications).

**3.4 DPIA for a new digital lending product.**
Under NDPA/GAID 2025, a new lending product processing BVN, credit and device data requires a DPIA. The data architect records the product's data entities in `LogicalEntities` (PII-flagged, NDPA-classified), maps flows — including any cross-border flow to a cloud credit-scoring bureau — in `DataFlows`, declares purposes via `ConsentPurpose`, and files the DPIA on `Dpia.jsx`. The cross-border count surfaces on the Command Centre, and EA-KRI-05 ("cross-border data flows without DPIA") tracks the residual. *Boundary:* no DPO alerting on cross-border flow creation, no NDPC-format DPIA export, and no link to the core `DataBreach` module — a breach of that lending platform would not automatically pull up its DPIA and data-flow map.

**3.5 Blast-radius analysis for an interface outage.**
When the NIBSS Instant Payments interface degrades, the duty architect opens `BlastRadius.jsx`, selects the interface's source application, and gets the radial graph of dependent applications and interfaces — the "what else breaks" answer within the CBN 30-minute fraud/incident response window. *Boundary:* one-hop traversal grouped by type; incident responders in the core Incident module cannot see it from their screen.

**3.6 CSAT third-party and resilience sections evidenced from the EA graph.**
Preparing the CSAT portal submission (the platform ships a full CSAT module — `CsatAssessmentController`, `csat_*` tables), the CISO uses EA to substantiate answers: application inventory with criticality, technology EOL posture, security zone assignments, control coverage by framework from `ControlMappings.jsx`, vendor concentration, and the CBN-EA maturity evidence pack with hash-sealed attestation from `EvidencePacks.jsx`. *Boundary:* "auto-evidenced" is aspirational — CSAT answer-to-EA-component attribution (planned in ATH-PIP-EA-001 §P2.S4.WP-006) is not built, so evidence is exported from EA and attached to CSAT manually.

---

## 4. Interlock with the Rest of Atheris — The Integration Map

The platform's core GRC models are real and mature: `Risk`, `Control` (with `ControlFramework`/`FrameworkRequirement`), `Asset`, `Vendor`/`VendorAssessment`/`TprmSecurityRating`, `BcpPlan`/`BiaRecord`, `Incident`/`DataBreach`, `Kri`/`KriReading`, plus the CSAT suite. The EA module sits beside them **without enforced foreign keys to any of them**. The table below is the integration contract the product needs, with the honest current state of each.

| # | Integration | What should happen | Current state (code evidence) | Verdict |
|---|---|---|---|---|
| 1 | EA TechComponent EOL → Risk register | EOL/CVE findings past threshold auto-create/refresh a `Risk` with treatment tracking | `TechObsolescenceService` + `AnomalyFinding` exist; no reference to `App\Models\Risk` anywhere in `app/Services/Ea` | **Must build** (P1) |
| 2 | EA Application ↔ core Asset | Bidirectional link; EA reads CMDB, Asset shows EA scores | Partial: `ea_applications_ext.asset_id` (nullable, unconstrained) populated by `AssetApplicationClient::syncIntoEa()`; read-only, manual trigger, no write-back, no Asset-side view | **Harden** (P1) |
| 3 | EA ControlMapping → real Control FKs | `control_id` constrained to `controls.id`; coverage reads actual control test status | `control_id` is an unconstrained bigint; `EaPhase2Seeder` fills it with `rand(1,80)`; `ControlInheritanceService` computes posture over potentially dangling IDs | **Must build** (P1) |
| 4 | EA vendor links ↔ Vendor/TPRM | Application-vendor links maintained by TPRM; concentration feeds TPRM dashboards; `TprmSecurityRating` visible on `ApplicationShow` | `VendorConcentrationService` joins `vendors` — the one live cross-link; `vendor_id` unconstrained, seed-populated, no TPRM workflow tie | **Partial → deepen** (P1/P2) |
| 5 | EA DPIA ↔ Data Breach / NDPA module | Breach in `DataBreach` auto-links affected `LogicalEntity`/`DataFlow`/`DpiaAssessment` for 72-hour NDPC notification | No link in either direction | **Must build** (P2) |
| 6 | EA Capability/Process ↔ BCP/BIA | `BiaRecord` criticality flows into EA processes; capability map heat-mapped by BIA; blast radius consumes RTO/RPO | `Process` has RTO/RPO columns but they are seeded, not read from `bia_records`; core also has a **parallel** `BusinessCapability`/`BusinessProcess`/`BusinessService` model family EA ignores — a duplicate source of truth | **Must build + reconcile** (P1) |
| 7 | EA KRIs → platform KRIs & dashboards | EA KRI values published to core `Kri`/`KriReading` for board packs | EA has its own `KriDefinition`/`KriValue`; no publication to core `Kri` | **Must build** (P2) |
| 8 | EA evidence ↔ CSAT / Evidence Vault | Evidence packs registered in `EvidenceVaultItem`; CSAT answers cite EA entities | Packs stored on local disk with hashes; no vault registration; CSAT attribution unbuilt (plan §P2.S4.WP-006) | **Must build** (P2) |
| 9 | ARB/Exceptions ↔ Issues & Workflow | ARB decisions and expiring exceptions raise `Issue` records; approvals ride the core `Workflow` engine | ARB decide and exception renew are self-contained EA state changes | **Must build** (P2) |

Two design notes for whoever builds this. First, prefer **soft FKs + event listeners** over hard DB constraints for cross-module links (the platform is modular monolith today but the seams should stay clean); what matters is that the *application layer* enforces referential integrity and that seeders stop writing random IDs. Second, integration #6 requires a decision, not just code: either EA's `Capability`/`Process` becomes the platform's single business-architecture source and `BusinessCapability`/`BusinessService` read from it, or vice versa. Shipping two capability models to the same bank is the kind of thing a Big-4 assessor will write up.

---

## 5. Benchmark: Atheris EA vs LeanIX, Ardoq, Bizzdesign/MEGA

**Where Atheris is credible (July 2026):**

- **Metamodel breadth.** 46 models spanning ArchiMate strategy, business, application, technology, motivation and implementation layers, plus a generic `Relationship` graph table with `RelationshipValidator` — structurally the same shape as Ardoq's graph repository.
- **Obsolescence risk framing.** SAP LeanIX's growth products this cycle are Obsolescence Risk Management and Technology Risk & Compliance — i.e., the EA leader is moving *toward* GRC. Atheris already has the radar, EOL/EOS/CVE data model, obsolescence service, and KRIs; what LeanIX cannot offer is putting the resulting finding into the same product's risk register (once #1 in §4 is built, this is a head-to-head win in any bank evaluation).
- **Regulator-native artefacts.** CBN-EA maturity self-assessment (`CbnMaturity.jsx`) and a hash-sealed CBN evidence pack generator. No global EA tool ships a CBN artefact; this is a level-4 "differentiated" feature under the gap analysis's own rubric.
- **Governance workflow.** ARB wizard → decision, exception lifecycle with renewal, principle-impact analysis. Comparable in shape (not depth) to what Ardoq's "Architecture Governance" pattern and MEGA's ARB support provide.
- **AI surface.** An MCP endpoint (`McpServer`) and NL search — matching LeanIX's early-2026 MCP move at the API level, which for a tool at this maturity is a striking parity point.

**Where Atheris is behind:**

- **Live data ingestion.** LeanIX ships SaaS discovery against a 6,500-entry catalogue plus CMDB/cloud connectors; Ardoq ships surveys and broad integrations. Atheris's three sync actions are manual buttons; the EOL/CVE clients silently fall back to ~17-product/7-CVE fixtures offline, and `AssetApplicationClient` reads a local table, not a discovery source. A bank evaluator who presses "Sync CVE" on an air-gapped UAT box gets fixture data and should be told so.
- **Repository federation & collaboration.** No import from LeanIX/Ardoq/Sparx repositories (export via ArchiMate exchange exists; import path untested at scale), no surveys, no commenting/review, no versioned baselines beyond plateaux.
- **Depth of read-only areas.** ~24 pages are still lists without editors; scenario comparison and viewpoints are generated views rather than authorable models; blast radius is 1-hop where Ardoq does arbitrary graph traversal.
- **Scale & hardening.** The gap analysis's cross-cutting items (test coverage, performance at 2,000+ nodes, feature-flag gating) remain largely open; leaders are production-grade by definition.

**The positioning story.** Against **EA leaders** (LeanIX, Ardoq, Bizzdesign): "You would still need a GRC platform, a CSAT tool, and an NDPA programme — and then an integration project. LeanIX's own roadmap (obsolescence risk, tech compliance) and Ardoq's GRC pattern and DORA guidance prove the market wants EA fused with risk; Bizzdesign and MEGA HOPEX sell EA-driven operational resilience and cyber scenario simulation for the same reason. Atheris starts fused." Against **GRC leaders** (Archer, MetricStream, ServiceNow GRC, LogicGate): "None ships a native EA repository. When CBN asks *which critical business services run on which EOL technology through which concentrated vendors*, a GRC tool without an architecture graph answers with a spreadsheet." Atheris's honest one-liner: *narrower and shallower than any leader in its own discipline, but the only product a Nigerian bank can buy where the architecture graph and the regulatory obligations live in one database* — provided the §4 interlocks get built, because today the two halves share a login and little else.

---

## 6. CBN / Regulatory Value Map

| Regulatory driver (as at July 2026) | Specific expectation | EA module feature that answers it | Status |
|---|---|---|---|
| CBN Risk-Based Cybersecurity Framework — annual CISO self-assessment (due 31 March) | Evidence-backed self-assessment incl. asset/architecture governance | `CbnMaturity.jsx` self-assessment; `EvidencePackGenerator` hash-sealed PDF/ZIP; `AuditTrail.jsx` | **Shipping** |
| CSAT circular (March 2026) — governance section | IT/architecture governance structures, policies, decision records | ARB workflow with recorded decisions; `Principles`/`Standards` catalogues; exceptions register | **Shipping** (CSAT attribution manual) |
| CSAT — risk section | Technology risk identification incl. obsolescence and vulnerabilities | Technology Radar, `TechVulnerability`, EA KRIs, anomaly findings | **Partial** — findings don't reach the core risk register (§4 #1) |
| CSAT — third-party section | Third-party ICT dependency and concentration | `VendorConcentration.jsx` live query; application-vendor links | **Partial** — needs TPRM interlock (§4 #4) |
| CSAT — resilience section | Critical-function → system → dependency mapping | Capability map, `Processes` RTO/RPO, `BlastRadius.jsx`, interfaces | **Partial** — BIA linkage unbuilt (§4 #6); note this mirrors Ardoq's DORA critical-function pattern, a strong narrative for CBN's direction of travel |
| Jan 2026 CBN directive — <30-min fraud response, operational resilience | Rapid impact determination during incidents | Blast radius, application criticality, zone model | **Partial** — not surfaced in Incident module |
| NDPA / GAID 2025 — DPIA duty | DPIA for high-risk processing | `Dpia.jsx` + `DpiaAssessment`, `ConsentPurpose`, PII-classified entities and flows | **Shipping** (create-level; no NDPC-format export/DPO alerts) |
| NDPA — CAR returns, breach notification | Processing inventory; 72-hour breach context | InfoDomains/LogicalEntities/DataFlows inventory; cross-border flags | **Partial** — no DataBreach interlock (§4 #5) |
| Recapitalization-driven governance tightening & M&A | Board-grade IT estate transparency; integration due diligence | Command Centre, portfolio TIME/BFTF, plateaux/scenarios, ArchiMate exchange | **Shipping** for inventory; scenario depth limited |

---

## 7. Commercial Packaging

**The dual-buyer motion.** EA tools are bought by CIO/CTO offices; GRC tools by CISO/CRO offices. Atheris should deliberately sell the EA module as the *bridge SKU*: the CIO gets the portfolio, radar, ARB and roadmap; the CISO gets the CSAT evidence, control coverage, zones and threat models — from the same data, priced once. In Nigerian banks the CISO self-assessment deadline (31 March) and CSAT portal cadence create the compelling event; the CIO's transformation programme funds the licence.

**Three wedge plays:**
1. **Transformation programmes.** Every recapitalizing bank is running core banking, channel or cloud programmes. Lead with the ARB + blast radius + roadmap demo (§3.1): "govern the programme in the same tool the regulator sees." Land EA with the transformation office, expand to CSAT/GRC.
2. **Recapitalization M&A integration mapping.** Mergers require answering "what do we both run, where does it overlap, what do we retire" — exactly the TIME grid, capability overlap and plateau/scenario feature set. Offer a fixed-scope "estate mapping sprint" (4–6 weeks, Atheris consultants + `BulkImport` CSV templates + BIAN reference seed) as paid discovery that seeds the repository and the licence.
3. **CSAT/NDPA compliance uplift.** For banks already on Atheris GRC/CSAT, EA is the upsell that turns portal answers from prose into graph-backed evidence.

**Pricing and deployment notes for the Nigerian market.** Price in Naira with annual invoicing (FX-denominated SaaS pricing is a procurement killer post-recapitalization); tier by institution class (DMB international > DMB national > merchant/non-interest > PSP/MFB) rather than per-user — EA value scales with estate size, not seats, and Ardoq's "unlimited users" precedent makes per-seat EA pricing look dated. Offer on-prem/private-cloud deployment as first-class: the stack (Laravel + MySQL/Redis + React, no exotic infrastructure) deploys inside a bank's own data centre, which matters both to CBN data-localisation instincts and to security teams that will not send their architecture graph to a foreign SaaS — a genuine deployment advantage over LeanIX/Ardoq, who are SaaS-only. The fixture-fallback design of the feed clients is honestly a feature here (air-gapped environments still function) *if disclosed*. Bundle a "regulatory content subscription" (CBN-EA framework updates, CSAT question mapping, Nigerian banking tech catalogue per gap-analysis item P2-11) as recurring revenue on top of the platform licence.

---

## 8. Gap Register & Recommended Build Order

Priorities reflect this playbook's commercial thesis: **the differentiator is EA↔GRC interlock, so isolation-breaking work outranks EA feature depth.** References map to ATH-GAP-EA-001 (P0/P1/P2 items) and ATH-PIP-EA-001 (sprint slots).

### P1 — Sell-blocking (next 1–2 sprints)
| # | Gap | Build | Reference |
|---|---|---|---|
| P1-1 | EOL/CVE findings die inside EA | `Ea\RiskBridgeService`: anomaly/obsolescence findings past threshold upsert core `Risk` rows with back-links; scheduled, not button-driven | New (extends GAP P1-6/P1-7; the "sync" plumbing exists) |
| P1-2 | `ControlMapping.control_id` unconstrained + seeded with `rand(1,80)` | Constrain to `controls.id` at application layer, fix seeders, have `ControlInheritanceService` read real control status; coverage report becomes CSAT-grade | GAP §5.7; plan §P2.S3.WP-005/006 |
| P1-3 | Asset link is one-way, manual, unconstrained | Schedule `syncIntoEa`, enforce `asset_id` integrity, add EA panel on Asset detail (reverse view) | GAP P0-5; plan §P1.S4.WP-001/002 |
| P1-4 | BIA/BCP criticality not consumed; duplicate `BusinessCapability` family | Read `bia_records` into `Process`/`Capability` criticality; product decision on single capability model | Plan §P2.S4.WP-004; new (duplication finding) |
| P1-5 | Sync jobs unscheduled; fixture fallback undisclosed | Laravel scheduler entries; surface feed provenance ("live feed" vs "bundled fixture") in UI | GAP §6.4 |

### P2 — Competitive depth (quarter 2)
| # | Gap | Build | Reference |
|---|---|---|---|
| P2-1 | CSAT answers cite EA entities manually | Answer-to-component attribution + evidence-pack registration in Evidence Vault | Plan §P2.S4.WP-006 |
| P2-2 | DPIA/data-flow ↔ DataBreach unlinked; no DPO alert | Cross-border flow event → DPO notification; breach screen pulls affected entities/DPIA | GAP P1-10; plan §P2.S6.WP-006 |
| P2-3 | ARB/exceptions self-contained | Decisions raise `Issue` records; exception expiry job + 30/60/90 reminders; ride core `Workflow` | GAP P1-3/P1-4; plan §P2.S5.WP-008 |
| P2-4 | EA KRIs not on platform dashboards | Publish `KriValue` to core `Kri`/`KriReading` | Plan §P3.S4.WP-003 |
| P2-5 | ~24 read-only pages | Prioritised write surface: zones/zone assignments, data flows, logical entities, plateaux, value streams (in that order — security and privacy editors first) | GAP P0-1 residue |
| P2-6 | Blast radius 1-hop | Depth-limited n-hop traversal over `ea_relationships` + change-impact tab on `ApplicationShow` | GAP P1-2; plan §P2.S2.WP-001 |
| P2-7 | TPRM interlock thin | `vendor_id` maintained from TPRM workflow; `TprmSecurityRating` on application detail | New |

### P3 — Differentiation & hardening (quarters 3–4)
| # | Gap | Build | Reference |
|---|---|---|---|
| P3-1 | No tests for EA module | Feature tests per endpoint; Playwright happy paths (ARB, DPIA, evidence pack) | GAP P0-9/P0-10, §6.6 |
| P3-2 | Scenario comparison shallow | Plateau diff with cost/risk deltas — the M&A integration-mapping feature (§7 play 2) | GAP P2-1; plan §P3.S1.WP-003 |
| P3-3 | No Nigerian banking tech catalogue | 200-entry curated catalogue (Finacle, Flexcube, T24, NIBSS, eFASS, telco APIs) | GAP P2-11 |
| P3-4 | ArchiMate import untested at scale | Round-trip verification as release gate | GAP P0-8; plan §P3.S5.WP-003 |
| P3-5 | Multi-framework maturity, trend charts | COBIT/ISO 38500 alongside CBN-EA; QoQ trend | GAP P2-4/P2-5 |
| P3-6 | Feature-flag gating, tenancy tests, performance at 2,000+ nodes | Cross-cutting hardening before multi-bank rollout | GAP §6.2/§6.8; plan NFR-P |

The through-line: the gap analysis's P0 ("build the write surface") has largely been delivered since May. The next inflection is not more EA features — it is making the EA graph and the GRC engine one system, because that fused answer is the only pitch in this market that neither LeanIX nor any GRC incumbent can copy quickly.

---

*End of document. ATH-PLB-EA-001 v1.0 — 10 July 2026.*
