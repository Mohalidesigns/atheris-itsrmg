# ATH-VAL-015 — Enterprise Architecture: End-to-End Validation Report

**Module:** Enterprise Architecture (sidebar group 15 of the platform validation programme, brought forward at the user's request). The pass is organised around the CBN IT blueprint's five EA domains:
- Business Architecture
- Application Architecture
- Data Architecture
- Technology Architecture
- Security Architecture & Assurance

It adds a CMMI capability maturity model, so a bank sees its maturity level per domain.

**Tenants:** First Bank (org 3, holds the EA repository), cross-checked against Kano Heritage Bank (org 2).

**Method:**
- The pages were walked in the in-app browser as the First Bank CISO, and the controllers, models, services and seeders were traced.
- Locked in by `tests/Feature/Ea/EaMaturityModuleTest.php` (6 tests, 129 assertions), alongside the existing 139 EA tests.

**What the module did before this pass:**
- **Maturity was a demo shell:**
  - "CBN EA Maturity" listed 10 domains with 40 placeholder questions ("How mature is EA Governance — Q1?").
  - The seeder answered them with `rand(2, 4)`.
  - There was no way to answer, submit or approve an assessment, no CMMI level definitions, and no evidence behind any rating.
  - The evidence-pack generator invented an assessment with an overall score of 3.2 when none existed.
  - The seeded evidence packs pointed at PDF files that were never written.
- **Two inconsistent maturity scales:**
  - The ITSB return scored only the four TOGAF ADM domains, with no security, from documentation completeness.
  - The Score Card used different bands, so the same repository could be "level 3" on one page and "level 4" on the other.
- **Cross-bank control coverage:** 80 of First Bank's 100 EA control mappings pointed at Kano's controls. The Phase 2 seeder drew controls from every bank, so First Bank's security-architecture coverage and the "citable coverage" figures used Kano's effectiveness ratings.
- **Bank-specific records shown as shared:**
  - First Bank's goals ("Become Nigeria's leading digital-first bank"), drivers, outcomes, stakeholders, consent purposes, threat models, DPIAs and EA KRI definitions were seeded with no bank, so every bank saw them.
  - Anomaly findings were created with no bank.
  - Fourteen EA models had no tenant scope at all.
- **The domains in the navigation:**
  - There was no Technology Architecture workspace: the technology radar and sites sat under "Portfolio", and standards under Governance.
  - Security had no assurance view.
  - EA sat near the bottom of the sidebar.

**Now:**
- **Navigation:**
  - EA sits directly under KRIs & Dashboards, arranged by the five CBN domains (Business, Application with Integration, Data, Technology, Security Architecture & Assurance), then Transformation and Governance.
  - "EA Maturity (CMMI)" sits next to the Command Centre.
  - A new Technology Architecture workspace holds the radar, standards, and sites & resilience.
  - Security gains an Assurance tab.
  - Breadcrumbs follow the domain names.
- **Services** (`app/Services/Ea/Maturity/`):
  - `Cmmi`: levels, the ITSB name for each, and the indicative bands.
  - `DomainEvidence`: what the bank's own repository shows for each practice area.
  - `MaturityAssessmentService`: answering, the evidence rules, the workflow and results.
- **Controllers:** `Ea\MaturityController`, `Ea\SecurityAssuranceController`.
- **Migration `2027_01_24_000001`:**
  - the five-domain CMMI framework, with the placeholder framework and its random responses removed;
  - assessment workflow columns;
  - the cross-bank control mappings repaired (re-pointed at a control in the mapping's own bank, same domain where possible);
  - threat models and anomaly findings given the bank of the record they describe.

## 1. The maturity model

* **Framework:** 25 practice areas, five per domain. For example, BA-3 asks whether business services are catalogued with owners and approved RTO/RPO; SA-4 asks whether control effectiveness is assured by testing, continuous monitoring and independent review.
* **Scale:** each practice is rated on the CMMI scale:
  1. Initial;
  2. Managed;
  3. Defined;
  4. Quantitatively Managed;
  5. Optimising.

  The ITSB name for each level (Repeatable, Managed and measurable, Optimised…) is shown alongside.
* **Domain level:** the CMMI staged rating, which is the domain's lowest practice level, since a domain is only as mature as its weakest practice. The average is shown beside it.
* **Repository evidence:** each practice shows measured indicators from the bank's own repository (e.g. "Applications with an owner: 50 of 53", "Critical applications threat-modelled: 3 of 33"). Each domain gets a documentation score and the level that documentation supports on its own, capped at 4, since documentation cannot show "optimising".
* **Evidence rules:**
  * Level 3 and above needs an evidence note.
  * Level 4 and above must also cite at least one measured indicator, which is snapshotted with the response.
  * A domain rated two or more levels above what the documentation supports is flagged as overstated.
* **Workflow:**
  * draft → submitted (all 25 practices rated) → approved, or returned to the assessor with a note;
  * approval is by someone other than the submitter (`approve ea`);
  * a flagged domain needs an approval note;
  * approval snapshots the results.
* **Targets:** each domain's target defaults to the bank's ITSB category: level 3 for Category One institutions, level 2 for Category Two, from its licensed entities. Targets can be adjusted while the assessment is a draft.
* **Maturity page:**
  * rated level against documented level and target per domain (five-cell bars, ▲ for documented, gold outline for target), and the gap;
  * the trend between approved assessments;
  * the assessment in progress;
  * the CMMI level legend.
* **Command Centre:** shows the approved per-domain levels against target.

## 2. Regulatory outputs

* **ITSB maturity return:**
  * It covers all five blueprint domains, adding Security Architecture & Assurance.
  * Each domain reports the approved assessment's level where one exists ("approved assessment"), otherwise the documentation level ("documentation"), with both readings shown.
  * Level 5 is reachable only through an approved, evidenced assessment, never from documentation.
  * The questionnaire section reads the approved assessment.
* **Score Card:** uses the same bands, so it and the return agree.
* **Evidence pack:**
  * It attests the approved assessment and refuses to generate without one.
  * It reads the CMMI results and the assessment's own bank.
  * First Bank's three packs that pointed at missing PDFs were replaced by one generated (and hashed) from its approved assessment.

## 3. Security Architecture & Assurance

The new Assurance tab shows each trust zone and its applications with:

* the bank's controls mapped to them (directly or through the zone);
* how many are rated effective;
* what continuous monitoring says (module 13);
* threat-model coverage;
* open configuration drift on the underlying asset (module 13).

Each application is then rated:
* **assured:** every mapped control effective and monitored;
* **partly assured;**
* **weak:** failing CCM tests, open drift, or fewer than half the controls effective;
* **no controls.**

Applications outside any zone are listed separately. A mapping to another bank's control does not resolve.

## 4. Bank isolation

* **Scoping:** fourteen EA models now carry the tenant scope:
  * goals, drivers, outcomes, stakeholders, courses of action;
  * glossary, DPIAs, consent purposes;
  * technology vulnerabilities, threat models;
  * ADM deliverables, anomaly findings, the EA audit log;
  * application–capability links.

  NULL-organisation rows remain visible to every bank as platform-shared reference data (the EA glossary, Nigerian reference sites).
* **Seeders:** the Phase 4 seeder assigns First Bank's motivation layer, KRI definitions, consent purposes, threat models and DPIAs to First Bank. The Phase 2 seeder maps only the bank's own controls.
* **Control coverage:** `ControlInheritanceService` resolves a mapping only to a control in the mapping's own bank.
* **Anomaly findings:** the anomaly engine records the bank of each finding.

## 5. Demo data

`EaMaturityDemoSeeder` runs after `KriDashboardsDemoSeeder`, through the assessment service:

* **First Bank:**
  * 2024 approved (overall 1.6) and 2025 approved (2.6), so the trend shows improvement;
  * 2026 in progress, with Business, Application and Data rated;
  * ratings follow the repository evidence;
  * a real evidence pack is generated from the 2025 assessment.
* **Kano:** 2026 approved at 1.4, rated at what its thin repository supports. Security is rated two levels above the documentation, with the approver's note explaining why.

## 6. Verification

* **Browser, as the First Bank CISO:**
  * **Sidebar:** reads Continuous Monitoring → KRIs & Dashboards → Enterprise Architecture → Workflow Studio.
  * **Maturity page:** shows 2025 at 2.6 with per-domain rated, documented and target levels (Technology 4/3 met; Business, Data and Security 2/3), and the 2026 assessment at 15/25.
  * **Rating TA-1 at level 4:** refused until an indicator was cited, then saved with the note and both cited indicators snapshotted.
  * **Security Assurance:** shows 53 applications in 7 zones, 1 assured, 22 weak and 30 critical or high without a threat model.
  * **Command Centre:** shows the approved levels.
  * No console errors.
* **Automated:**
  * `EaMaturityModuleTest` (6 tests, 129 assertions)
  * the existing EA tests
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test
  * `npm run build`
* **Dev PostgreSQL:**
  * the migration was applied: First Bank's 100 mappings now all resolve to its own controls, and 44 anomaly findings carry their bank;
  * the ownership correction was applied to the existing rows;
  * `EaMaturityDemoSeeder` was run.

## 7. Not in this pass

* **EA roles:** no bank user holds the Enterprise Architect or Solution Architect role in the demo, so the CISO and sysadmin logins carry out assessments. Assigning architects is a bank set-up step.
* **Capability maturity:** the business-capability maturity attribute on capabilities (1–5, seeded at random in Phase 1) is separate from this practice maturity model and was not re-seeded.
* **ITSB capability areas:** the eight ITSB capability areas in the ATH-EAR-002 plan (§7) are not modelled separately. The five blueprint domains cover the architecture assessment the user asked for.
