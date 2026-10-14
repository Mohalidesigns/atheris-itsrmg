# Atheris ITSRM&G — Module Validation Programme: Handoff

**Repo:** `https://github.com/Mohalidesigns/atheris-itsrmg` (private), branch `main`
**Last commit:** see `git log` — module 7 "Asset Management: end-to-end validation fixes"

## The brief (from the user)

Validate each sidebar module end to end, in order. For every module:
- check every feature, form submission, table, button and modal, and every relationship between records
- check how the module feeds the dashboards
- write up detailed findings and implement the fixes, so the module matches the solution's intended capabilities
- test in the **in-app browser** so the user can watch the process

## Workflow per module (what worked)

1. Map the routes, controllers, models, pages and seeders, then inspect the demo data in MySQL.
2. Walk every page in the in-app browser as the Kano CISO (`admin@kanoheritage.ng` / `password`). Cross-check isolation as First Bank (`admin@firstbanknigeria.ng` / `password`).
3. Fix findings across security/tenancy, lifecycle, forms, tables, demo-data consistency and dashboard feeds.
4. Add `tests/Feature/<Module>/<Module>ModuleTest.php`.
5. Write `docs/ATH-VAL-00N_<Module>_Validation_Report.md`.
6. The Definition of Done is in CLAUDE.md. Run:
   - Pint on the touched files only
   - the full PHP suite: `php vendor/bin/phpunit` (about 26 minutes)
   - `migrate:fresh --seed` against a **scratch SQLite DB**, never the user's MySQL
   - the `RouteSmokeTest` with `SMOKE=1`
   - `npm run build`
7. Commit and push **only after the full suite passes**, then **pause and wait for the user's go-ahead** before starting the next module.

## Done so far

| # | Module | Commit | Report | Tests |
|---|---|---|---|---|
| 1 | IT Risk Management | `4db2b92` | `docs/ATH-VAL-001_IT_Risk_Management_Validation_Report.md` | `tests/Feature/Risk/RiskModuleTest.php` |
| 2 | CBN Cyber Assessment (CSAT) | `af5afd8` | `docs/ATH-VAL-002_CBN_Cyber_Assessment_Validation_Report.md` | `tests/Feature/Csat/CsatModuleTest.php` (9) |
| 3 | Compliance | `493f44b` | `docs/ATH-VAL-003_Compliance_Validation_Report.md` | `tests/Feature/Compliance/ComplianceModuleTest.php` (12) |
| 4 | Regulatory Intelligence | `4591bce` | `docs/ATH-VAL-004_Regulatory_Intelligence_Validation_Report.md` | `tests/Feature/RegulatoryIntelligence/RegulatoryIntelligenceModuleTest.php` (14) |
| 5 | Security Operations | `86041ee` | `docs/ATH-VAL-005_Security_Operations_Validation_Report.md` | `tests/Feature/SecurityOperations/SecurityOperationsModuleTest.php` (12) |
| 6 | Issues & Remediation | `44f0102` | `docs/ATH-VAL-006_Issues_Remediation_Validation_Report.md` | `tests/Feature/Issues/IssuesModuleTest.php` (12) |
| 7 | Asset Management | `f337190` | `docs/ATH-VAL-007_Asset_Management_Validation_Report.md` | `tests/Feature/Assets/AssetManagementModuleTest.php` (12) |
| 8 | Vendor Management | `f57ddea` | `docs/ATH-VAL-008_Vendor_Management_Validation_Report.md` | `tests/Feature/Vendors/VendorManagementModuleTest.php` (10) |
| 9 | Policy Management | `a81f88a` | `docs/ATH-VAL-009_Policy_Management_Validation_Report.md` | `tests/Feature/Policies/PolicyManagementModuleTest.php` (8) |
| 10 | ISMS | `e4ed5da` | `docs/ATH-VAL-010_ISMS_Validation_Report.md` | `tests/Feature/Isms/IsmsModuleTest.php` (6) |
| 11 | PCI Management | `b09d047` | `docs/ATH-VAL-011_PCI_Management_Validation_Report.md` | `tests/Feature/Pci/PciModuleTest.php` (6) |
| 12 | Business Continuity | `25a5ec5` | `docs/ATH-VAL-012_Business_Continuity_Validation_Report.md` | `tests/Feature/Continuity/BusinessContinuityModuleTest.php` (6) |
| 13 | Continuous Monitoring | `0d238b2` | `docs/ATH-VAL-013_Continuous_Monitoring_Validation_Report.md` | `tests/Feature/Monitoring/ContinuousMonitoringModuleTest.php` (6) |
| 14 | KRIs & Dashboards | `989abf4` | `docs/ATH-VAL-014_KRIs_Dashboards_Validation_Report.md` | `tests/Feature/Kri/KriDashboardsModuleTest.php` (6) |
| 15 | Enterprise Architecture | see `git log` | `docs/ATH-VAL-015_Enterprise_Architecture_Validation_Report.md` | `tests/Feature/Ea/EaMaturityModuleTest.php` (6) |
| — | Change Management (new module) | `f41a267` | `docs/ATH-CHG-001_…Specification_v1.0.md` (contract), `docs/ATH-CHG-002_Change_Management_Build_Update.md` | `tests/Feature/Changes/ChangeManagementModuleTest.php` (18), `ChangeTenancyAndRoutesTest.php` (3) |

Last full suite: **318 tests, 317 passed, 1 skipped, 3,454 assertions (14 Oct 2026, local, PHP 8.4)**. The skip is the route smoke test, which runs separately and passed over 205 GET routes.

### Regulatory Intelligence highlights (module 4)

- **Security:** Document Intelligence jobs and Returns Centre runs are tenant-scoped (they were visible to every bank), and their writes no longer go to org 1. Compiling an architecture return refuses another bank's legal entity. Write routes are gated by `create`/`edit`/`approve platform`.
- **Access:** the Compliance Officer (Head of Compliance) role now has view, create and edit platform; it could not open the module before (migration `2027_01_12_000002`).
- **Circular Feed:** per-bank triage (`circular_assessments`) with impacts linked to the bank's obligations, controls, policies and risks. A Content-Ops editorial gate hides unpublished platform circulars. The crawler is scheduled.
- **Document Intelligence:** real upload → `DocumentExtractionService` (PDF/DOCX/text layer, no OCR) → segregated review. An accepted circular becomes the bank's private circular; an accepted DDQ updates its vendor.
- **Returns Centre:** `ReturnRunPopulator` fills runs from the bank's register. Overrides need a reason. Draft → In review → Approved → Submitted; the preparer cannot approve. CSV export.
- **Architecture Returns:** the calendar is filing-aware, `CsatMapper` has an RBCF-2.5 section from the CSAT cycle, and nested answers render as tables.
- **Demo data:** `RegulatoryIntelligenceDemoSeeder`, which runs after EA stewardship.
- **Dashboard:** a Regulatory Intelligence panel.

### Security Operations highlights (module 5)

- **Security:** SIEM connectors and signals, and regulatory notifications, were visible to every bank. They are now tenant-scoped (`incident_notifications.organization_id` backfilled). Every picker (users, assets, findings, incidents) uses a tenant-scoped `exists`. Index sorts are whitelisted. SecOps writes are gated on the module's own permissions (`create/edit/approve incidents`, `create security-alerts`), because Security Analysts hold only `view platform`.
- **Prioritiser:** random EPSS replaced by CVSS × EPSS × CISA KEV × asset criticality from `epss_cache` / `kev_cache`, with SLAs from `vulnerability_sla_policies` (KEV → critical SLA). "Am I affected?" matches advisory CVEs against the bank's findings and assets.
- **SIEM:** an HMAC-signed webhook `POST /siem/ingest/{integration}` (outside auth/CSRF, with replay window, de-duplication and PII masking). High and critical signals auto-raise alerts. Alerts can link to open incidents.
- **Notifications:** drafted from the incident record; per-regulator clocks from detection (`config/secops.php`); Draft → In review → Approved → Submitted, where the preparer cannot approve. An NDPC submission marks the breach notified.
- **Incidents:** the status buttons never worked (wrong endpoint, non-existent statuses) and are fixed. Types unified. Forward-only lifecycle with reopen. Root cause required to close. Response procedures linked.
- **Breaches:** the index crashed (wrong route names) and is fixed. The NDPA clock runs from `discovered_at`, and the Returns Centre counts breaches by discovery.
- **Code series:** `GeneratesSequentialCode` continues each bank's own codes (`FBN-ALR-051`).
- **Demo data:** `SecurityOperationsDemoSeeder` (after DemoCrossLink, before Regulatory Intelligence). Bank seeders have real CVEs and a medium/low mix.
- **Dashboard:** the SecOps dashboard is rebuilt; the executive dashboard has a Security Operations panel. Both read `SecurityOpsMetrics`.

### Issues & Remediation highlights (module 6)

- **Security:** `Issue` had no tenant scope, so both banks saw all 115 issues. It is now strict-scoped; SLA policies are read and written per bank; ITSM connectors are a per-bank table with encrypted token and webhook secret. Pickers (owner, source record) are tenant-checked. Work needs `edit issues` or being the owner; verify, SLA policy and connectors need `approve issues`.
- **Lifecycle:** open → in_progress ⇄ blocked → remediated → verified → closed (`IssueWorkflow`). Remediating needs root cause and plan; the remediator cannot verify; reject and reopen need a reason. Escalation is a flag, not a status. Per-bank codes (`KHB-ISS-0026`). Full timeline in `issue_events`.
- **SLA:** due time from the bank's severity policy (`moderate` → `medium`); blocked time pauses the clock; severity change re-bases it. Hourly `issues:escalate`: level 1 at breach, level 2 at 150%, notifying the chain by job title.
- **ITSM:** Jira / ServiceNow / Freshservice connectors (live or sandbox), connection test, push from the issue, auto-push by severity, HMAC-signed `POST /itsm/webhook/{connection}` (outside auth/CSRF). A resolved ticket marks the issue remediated only if root cause and plan exist; verification stays human.
- **Decision:** vulnerability tickets stay SecOps work orders; Issues link to the vulnerability and own ITSM sync (link, not absorb).
- **Demo data:** `IssuesDemoSeeder` (after SecurityOperationsDemoSeeder) fixes First Bank issues that pointed at Kano's risks, and rebuilds dates, SLAs, escalations, timelines and tickets per bank.
- **Dashboard:** console KPIs and an executive-dashboard Issues panel, both from `IssueMetrics`.

### Asset Management highlights (module 7)

- **Security:**
  - `AssetSyncJob` is now strict-scoped. Discovery history was visible to every bank, and the stub wrote jobs to org 1.
  - Index sort is whitelisted.
  - Every picker is tenant-checked: owner, custodian, services, review owner, CSV owner e-mails, supporting assets, dependency providers.
  - Business Assets writes require the bank's own row, because the EA tenant scope also admits NULL-org rows.
  - `AssetApplicationClient`'s catalogue cache is now keyed per bank. It used to serve one bank's assets to all.
- **Decision:** business architecture stays canonical in EA. Service → service dependencies are `serving` edges (provider → consumer) in `ea_relationships`; `RelationshipValidator` now allows business-service serving business-service. Asset → service links are a GRC pivot, `asset_business_service`, because assets are not ArchiMate elements and `ea_relationships` is exported to Archi.
- **Discovery** (`AssetDiscoveryService`): five sandbox connectors (AD, Entra, AWS, Tenable, Qualys) and a real CSV import with a template. One reconciler: match on (source, external_id), then de-duplicate on hostname/IP, then create. True counts and row errors. Connector finds go to a review queue: accept needs an owner, reject needs a reason and retires the asset.
- **Criticality:** an asset inherits the criticality of the most critical service it supports (`criticality_inherited`). `effective_criticality` is the higher of that and the owner's rating, and the vulnerability prioritiser now uses it.
- **Business services:** validated against the bank's `ea_capabilities` (validation used to hit the retired table, so no capability could be saved). Supporting assets, upstream and downstream dependencies, cycle refusal, RTO-conflict warning, per-bank `BS-` codes. A layered capability → service → asset graph replaces the hard-coded "Kano Heritage Core" hub.
- **Register:**
  - hygiene tiles and filters: effective criticality, unowned, past end of life, end of life ≤ 90 days, unmapped, stale;
  - source filter and CSV export;
  - per-bank codes (`KHB-AST-0001`);
  - custodian, provenance, change requests, audit trail and archive on the asset page;
  - Edit dates fixed.
- **Demo data:** `AssetManagementDemoSeeder` runs after Change Management. It does the following per bank:
  - coherent owners and departments;
  - runs the real sandbox connectors twice, plus a CSV import with rejected rows;
  - builds a reviewed queue;
  - creates 16 services per bank (First Bank's on BIAN capabilities), with dependency chains and deliberate gaps;
  - links 29 First Bank EA applications to their assets without model events, so seals are kept.
- **Dashboard:** an Asset Management panel and end-of-life watch, from `AssetMetrics`, which also feeds the register and Discovery.

### Change Management highlights (new module, 13 Oct 2026)

- **Why it exists:** a selling feature — ITIL 4 / ISO 20000 / COBIT BAI06 change enablement with risk-based approval routing, CAB and ECAB, freeze windows, conflict detection, retrospective emergency authorisation and mandatory PIR, with segregation of duties enforced in code. Contract: `docs/ATH-CHG-001_…`; build record: `docs/ATH-CHG-002_…`.
- **Shape:** `app/Models/Change/*`, `app/Services/Change/*` (workflow, router, risk scorer, freeze checker, conflict detector, metrics, policy seeder), `app/Http/Controllers/Change/*`, 37 `changes.*` routes, hourly `changes:sweep`, `resources/js/Pages/Changes/*` (10 pages), dashboard "Change pulse" panel, `ChangeManagementDemoSeeder` (28 changes per bank), new `Change Manager` role + `changemanager@atheris.ng`.
- **Old stub:** `Pages/Policies/ChangeRequests/Index.jsx` was deleted; `change-requests.index` now redirects to `changes.index`.
- **Tests:** 21 passed / 816 assertions. Each test takes ~10 s because the full migration set runs on in-memory SQLite — normal.

### Vendor Management highlights (module 8)

- **Security:**
  - `tprm_security_ratings` and `tprm_breach_events` now have `organization_id`, backfilled from the vendor, with orphans pruned. Every bank used to see every rating and headline.
  - 33 First Bank vendor→application EA edges written with NULL org (so platform-shared) take their vendor's org; `EaWedgeDemoSeeder` is fixed.
  - Tenant-checked assessment vendor and owner pickers; whitelisted sort.
- **Access:** Risk Manager (each bank's Vendor Risk Manager) gains vendor and assessment view/create/edit; Compliance Officer (DPCO) gains view. Neither could open the module before.
- **Tiering** (`VendorTiering`): computed from material outsourcing (CBN), data access, NDPA processor, criticality of EA applications supplied, the bank's rating and substitutability. Tier sets the review cadence (12/24/36 months, `config/tprm.php`). A never-assessed vendor is due onboarding within 30 days.
- **Assessments:** completing one needs a score and findings. It records the residual rating and resets the vendor's last-assessed and next-review dates.
- **Monitoring:**
  - The sandbox rating provider (`SecurityRatingService`) captures once per vendor per day, with deltas and grades.
  - Breach watch triage: reassess (opens an incident-driven assessment), acknowledge, or dismiss with a reason.
  - Shared Directory: link a vendor, or add an entry to the register.
- **Dashboard:** the Third-Party Risk card is fed by `VendorMetrics`.
- **Demo data:** `VendorManagementDemoSeeder` runs after Asset Management. The platform seeder no longer writes ratings or breach events.

### Policy Management highlights (module 9)

- **Vocabulary:** one status set, `draft → in_review → approved → active → retired`, mapped from the data's `under_review / active / expired / archived`. Categories mapped. Attestations are `pending / completed / waived`, with overdue derived from the due date.
  - `PolicyManagementDemoSeeder::normalise()` applies the same mapping on fresh installs, because the bank seeders still write the old values.
- **Workflow** (`PolicyWorkflow`):
  - Submit needs the text and an owner; the owner and the submitter cannot approve.
  - Publish sets the dates and, for a mandatory policy, launches an attestation campaign (all active staff, due in 30 days, version-stamped).
  - Retiring waives open requests. A text change to an approved or active policy is a new minor version and re-opens approval.
- **Attestation for all staff:** `policies.attest` and the new **My Attestations** page need only sign-in plus being asked. Before, only 6 roles could attest.
- **Control Standards:** a `control_policy` mapping (edited on the policy form), with coverage and failing controls per policy.
- **Exceptions:** a `policy_exceptions` request/decide/revoke flow.
  - The requester can't decide; the term is capped by risk rating (critical 3 months, high 6, medium and low 12); high and critical need compensating controls.
  - The daily `policies:sweep` expires lapsed exceptions.
- **Feeds:** the dashboard "Policy Compliance" card is fed by `PolicyMetrics`. The Returns Centre's `policies_in_force` and the Reports "published" count now read `active`.

### ISMS highlights (module 10)

- **SoA against evidence** (`StatementOfApplicabilityService`): each Annex A entry shows its mapped controls with tested effectiveness and the latest completed ISO 27001 assessment result. Contradictions are flagged: implemented but a control is ineffective or the control was assessed non-compliant; applicable with no control; assessed compliant but still "planned"; no justification.
- **SoA editing** (`edit isms`): applicability, a justification (required), implementation status and the primary control (tenant-checked). An excluded control is stored as `not_applicable`. Edits are logged.
- **SoA versions** (`soa_versions`, cl. 6.1.3 d): a submitted snapshot that someone other than the preparer approves or returns. "Changed since approval" is shown per entry. CSV export includes the evidence. Annex A codes sort naturally.
- **Overview:** real theme figures (SoA implemented against assessed compliant) replace the hard-coded maturity scores. The bank name comes from the session.
- **ISMS risks:** scoped to the `config/isms.php` categories, with the controls treating each risk.
- **Demo data:** `IsmsDemoSeeder` keeps only defensible exclusions, writes real justifications, issues two approved versions and three changes since.

### PCI Management highlights (module 11)

- **Framework:** PCI DSS v4.0.1 is a platform framework (12 requirements, 63 sub-requirements). Assessments run on the compliance engine; controls map via `control_framework_mappings`.
- **CDE scoping:** `assets.pci_scope` (`cde` / `connected` / `out_of_scope`) with a required rationale (PCI DSS 12.5.2).
- **`PciPosture`:** every page reads the bank's own assessment, mappings, evidence and scoping. Gone: the `rand()` matrix, random control mapping, generated SAQ answers, hard-coded maturity, and organisation 1's evidence shown to every bank.
- **Bug fixed:** `pci_scope` was not fillable, so scoping silently saved nothing.
- **Demo data:** `PciDemoSeeder` replaces the PCI assessments that `ComplianceDemoSeeder` generates on a fresh install with last year's completed ROC plus this year's in progress.

### Business Continuity highlights (module 12)

- **Tenancy:** `DrRunbook` and `DrExercise` are strictly scoped; `dr_exercises.organization_id` was added and backfilled from the runbook. DR pages moved out of `PlatformController` into `DrController`, gated on `view bcp-plans`.
- **Plans:** edit, approve (not by the owner; scope and RTO required) and record tests. A material change to an approved plan sends it back to under review. Plans link to the services they recover (`bcp_plan_service`), with the plan RTO checked against each service's.
- **BIA:** each record names its EA business service. "Approve & apply" (not by the process owner) writes RTO/RPO onto `ea_business_services`, closing module 7's deferred item. Changing objectives clears the approval.
- **DR exercises:** recorded against the runbook's target: pass within it, partial up to 1.5×, fail beyond.
- **`ContinuityMetrics`:** feeds the plan register tiles and the dashboard's Business Continuity panel.
- **Bug fixed:** the new BIA fields were missing from `$fillable` (indentation mismatch on the first edit).
- **Demo data:** `BusinessContinuityDemoSeeder` links BIAs and plans to services, gives First Bank runbooks and test history, and leaves deliberate gaps (two unapplied BIAs, two plans untested for over a year).

### Continuous Monitoring highlights (module 13)

- **CCM measured, not random:** `CcmChecks` defines all 40 starter-pack tests (metric, unit, direction, pass/warn). 15 are measured from the bank's own records (policies, attestations, DR, BIA, vulnerabilities, incidents, changes, vendors, circulars, returns, drift, access reviews); 25 need an unconnected tool and run in labelled sandbox mode. `CcmRunner` records value, threshold and findings, schedules from the cron (`ccm:run-due` every 15 min), moves the control's last-tested date, and raises one issue per failing test (source `ccm_test`).
- **Controls Monitoring:** assessed effectiveness beside the CCM signal; disagreements flagged and counted on both dashboards.
- **Drift register** (`config_drift_events`): sandbox scans of the bank's own assets, reconciled with approved changes that list the asset; investigate, remediate, link a change, accept (not by the owner, with expiry ≤ 12 months), reopen. Unauthorised critical drift raises an issue (source `config_drift`).
- **Access reviews:** campaigns from Atheris users and roles (real), an AD extract (sandbox) or by hand; nobody decides their own access; removals confirmed by someone other than the decider; completion needs every decision.
- **Tenancy and gates:** CCM models strictly scoped and runs carry their bank; CCM moved from the platform group to `view monitoring`; Compliance Officer gains monitoring permissions, Risk Manager view.
- **Demo data:** `ContinuousMonitoringDemoSeeder` drives the real services for both banks; platform-measured failures are real gaps in the demo records.

### KRIs & Dashboards highlights (module 14)

- **KRIs:** strictly scoped (KRIs, readings, breaches); status computed from thresholds in the KRI's direction; one reading per period; commentary required for amber/red. 12 of the 30-indicator pack are measured from platform data (`KriMeasures`, "Measure now", weekly `kri:measure`); the rest are recorded by their owner; EA-published KRIs stay read-only.
- **Breaches:** opened/escalated by amber/red readings, resolved by green; red raises one issue (source `kri`) for the KRI owner; the owner records an action plan.
- **Board packs** (`BoardPackBuilder`): a snapshot of the bank's figures per template section, rendered print-ready (save as PDF); prepared as a draft, approved by someone else. Fake PPTX/PDF runs removed (no PPTX library installed).
- **Executive report:** open vulnerabilities exclude false positives, risk counts active only, residual heat map really residual, KRI row added.
- **EA publisher (contract I-8):** keyed per bank, readings carry their bank.
- **Gates:** KRIs under `risks` permissions, board packs under `reports` (Risk Manager gains `create reports`).
- **Demo data:** `KriDashboardsDemoSeeder` gives both banks the pack with owners, risks and history through `KriService`, and three board packs (two approved, one draft).

### Enterprise Architecture highlights (module 15)

- **Navigation:** EA sits under KRIs & Dashboards, arranged by the CBN IT blueprint's five domains (Business, Application + Integration, Data, Technology, Security Architecture & Assurance); new Technology workspace (radar, standards, sites) and Security "Assurance" tab.
- **CMMI maturity** (`app/Services/Ea/Maturity`): 25 practice areas, five per domain, rated 1-5; domain level = weakest practice; level 3+ needs an evidence note, 4+ cites measured repository indicators (`DomainEvidence`); overstated domains (2+ above documentation) need an approval note; draft → submitted → approved by someone else, or returned. Targets default to the ITSB category (Category One: 3).
- **Regulatory outputs:** ITSB return covers all five domains (security added), reports the approved assessment's level or the documentation level (capped at 4); Score Card uses the same bands; evidence packs need an approved assessment (no more invented 3.2).
- **Bank isolation:** 80 of First Bank's EA control mappings pointed at Kano's controls (repaired in the migration; seeder fixed; `ControlInheritanceService` resolves only in-bank); First Bank's motivation layer, KRI definitions, threat models, DPIAs now owned by First Bank; 14 EA models gained the tenant scope; anomaly findings carry their bank.
- **Write routes and binding:** the authorisation test calls write routes with id 1, so new EA write routes must resolve their record inside the controller (after the permission middleware), not by implicit binding, or a Viewer gets 404 instead of 403.
- **Demo data:** `EaMaturityDemoSeeder`: First Bank 2024 (1.6) and 2025 (2.6) approved, 2026 in progress, a real evidence pack; Kano 2026 approved (1.4) with an overstated Security rating and the approver's note.

## Next phase: module 16, Workflow Studio

Sidebar items: Workflows (`workflows.index`, `workflows.show`), Marketplace (`workflows.marketplace`), Active Instances (`workflows.instances`), all `PlatformController` methods under `view platform`.

Start with recon, as for the earlier modules: tenant scoping on the workflow models, whether instances actually run against platform records, how the marketplace installs templates, and demo data for both banks.

**Remaining order after that:** Core Banking, Reports, then the rest of the sidebar.

### Patterns from modules 5-9 to reuse

- **Stub pages:** move `PlatformController` stubs into a module controller, with services under `app/Services/<Module>/`, and a `<Module>Metrics` service shared by the module page and the executive dashboard (`DashboardController` + `Pages/Dashboard.jsx` panel).
- **Tenancy:** strict `BelongsToOrganization` on the model, so route binding 404s across banks; pickers validated with tenant-scoped `Rule::exists` or the model's own scope.
- **Codes:** `GeneratesSequentialCode` for per-bank codes.
- **Workflow:** lifecycle transitions in a service with a `TRANSITIONS` map, with segregation of duties (the doer cannot approve or verify).
- **Webhooks:** signed with `sha256=HMAC(secret, "{ts}.{body}")`, outside auth and CSRF (`routes/web.php` top plus `bootstrap/app.php` exceptions); secrets use the `encrypted` cast and are shown once.
- **Sandbox integrations:** when no credentials exist, run the real code path against a deterministic catalogue, record `mode = sandbox`, and say so in the UI (module 6 ITSM, module 7 discovery). Demo seeders should drive the real service, as `AssetManagementDemoSeeder` does with the connectors, so demo history matches what the UI produces.
- **EA overlap:** EA is canonical for business architecture. GRC pages read the EA models. Non-ArchiMate links (assets) go in GRC pivots, not `ea_relationships`. Write EA link fields with the query builder in seeders so stewardship seals are not broken.
- **Fresh seeds versus migrations:** bank seeders run after the migrations. A migration that remaps existing data must be mirrored in the module's demo seeder, or a fresh install keeps the old values (module 9 hit this).
- **Timing:** a new module test takes about 9 minutes on its own (migrations dominate). The full suite takes about 30-35 minutes. The smoke test works as `SMOKE=1 DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/database.sqlite php vendor/bin/phpunit --filter RouteSmokeTest` after a fresh seed of that file.

## Open follow-ups (not blocking)

- **ReportController** counts a treatment status that doesn't exist (`not_started`). A chip was raised for this.
- **CSAT spec items not yet built:**
  - BR-AW-05 deadline reminders (60/30/14/7 days)
  - BR-MA-04 comment threads
  - section assignments and the evidence UI
  - CISO email/phone encryption
- **Regulatory Intelligence:** no OCR engine or LLM attached; Returns Centre exports CSV only; `KRI-COMP-CBN` still reads seeded values.
- **Security Operations:** EPSS, KEV and advisories are seeded snapshots with no scheduled feed. Notifications record submission but are not delivered to a portal. No deadline reminders. No SOAR playbooks.
- **Asset Management:**
  - Discovery connectors are sandbox only: no credential settings, no API clients, not scheduled.
  - Stale assets are reported but not auto-raised as issues.
  - The BIA workflow that should own RTO/RPO belongs to Business Continuity.
  - Change risk scoring still reads the owner's criticality, not the effective one.
- **Issues & Remediation:** demo ITSM connectors are sandbox (live mode tested only with faked HTTP); no pre-breach reminders; verifiers cannot attach vault evidence; no bulk actions or CSV export; CCM failures, gaps and incident lessons do not auto-raise issues yet.
- **Compliance:** no notifications for gap due dates or evidence expiry. The ISMS Statement of Applicability is not yet linked to assessment results, which belongs in the ISMS module pass.

## Environment gotchas (important)

- **The project folder was restored from git (10 Oct 2026):** `vendor/`, `node_modules/`, `.env` and `.claude/launch.json` were missing. They were rebuilt with `composer install`, `npm install --legacy-peer-deps`, and a new `.env` (MySQL `it-security-risk`, `APP_URL=http://127.0.0.1:8010`). A scratch SQLite DB for `migrate:fresh --seed` lives at `database/database.sqlite` (gitignored); run it with `DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/database.sqlite`.
- **The dev database moved to PostgreSQL on 13 Oct 2026.** `.env` uses `pgsql`: Homebrew `postgresql@17` on `127.0.0.1:5432`, database `it_security_risk`, user `mac`, no password. It is fully migrated and seeded. MySQL had failed because an earlier migration run died halfway and left tables behind; starting on a clean Postgres database got around that. The old MySQL settings are in `.env.mysql.bak` (gitignored). A separate EDB PostgreSQL 18 (`/Library/PostgreSQL/18`) also runs on this Mac; the app does not use it. `psql` is not on the PATH: use `/opt/homebrew/opt/postgresql@17/bin/psql -d it_security_risk` or `php artisan tinker`.
- **PostgreSQL compatibility (13 Oct 2026):**
  - `LIKE` is case-sensitive on Postgres, so text searches use `whereLike()` / `orWhereLike()`, which are case-insensitive on every driver. Use them for new search code, not `where(..., 'like', ...)`.
  - The module 5 migration drops the leftover enum CHECK constraint on `incident_notifications.delivery_status`, on `pgsql` only.
  - `AtherisPlatformSeeder` resyncs the `tenants` id sequence after inserting explicit ids.
  - PHPUnit still runs on in-memory SQLite, and migrations must stay driver-agnostic (CLAUDE.md).
- **XAMPP MySQL** is no longer needed for the dev app. If you switch back, the user starts it (it needs their permission).

- **iCloud eviction:** the project lives in iCloud-synced Documents, and iCloud keeps evicting files to "dataless". Reads and `git` then hang, and `phpunit.xml` and `.git/objects` have both been affected.
  - Detect with `ls -lO <file>` (look for the `dataless` flag).
  - Fix by copying from a fresh `git clone` of the remote in the scratchpad, or `git clone --bare` and copy its pack into `.git/objects/pack`, then `rm` the dataless loose objects.
  - Never `mv` dataless files to another volume; that forces a download and hangs.
  - **Recommended:** move the project out of iCloud Documents.
- **Old MySQL dev DB** (XAMPP: `/Applications/XAMPP/xamppfiles/bin/mysql -uroot it-security-risk`) still holds the browser-test data from modules 1-6 (e.g. KHB-GAP-046, completed CBN assessment #10, KHB-ISS-0026). The Postgres DB was freshly seeded, so that walk data is not in it.
- **Dev servers** come from `.claude/launch.json` (uncommitted): `atheris` on port 8010. The Vite dev server's port 5180 clashes with another project, so use `npm run build` (delete `public/hot`) and serve built assets.
- **Fresh checkout / restore:** `storage/framework/{cache/data,views,sessions,testing}` and `bootstrap/cache` must exist or a dozen feature tests fail with "Please provide a valid cache path". `mkdir -p` them before running the suite.
- **Tests:** ParaTest isn't installed, so run `php vendor/bin/phpunit`. Never name a test helper `result()`, because it clashes with a final method on PHPUnit's TestCase.
