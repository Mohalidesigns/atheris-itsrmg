# Atheris ITSRM&G — Module Validation Programme: Handoff

**Repo:** `https://github.com/Mohalidesigns/atheris-itsrmg` (private), branch `main`
**Last commit:** see `git log` — module 6 "Issues & Remediation: end-to-end validation fixes"

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
| 6 | Issues & Remediation | see `git log` | `docs/ATH-VAL-006_Issues_Remediation_Validation_Report.md` | `tests/Feature/Issues/IssuesModuleTest.php` (12) |

Last full suite: **231 tests (11 Oct 2026)**: 229 passed, 1 skipped (the route smoke test, which runs separately and passed), and 1 failure from a stale assertion in the new Issues test, since corrected and passing in the 13 Oct full run.

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

## Next phase: module 7, Asset Management

### Start a new session here

Paste this as the first message of the new session:

> Read `docs/VALIDATION_HANDOFF.md` and `CLAUDE.md`, then start module 7, Asset Management, following the per-module workflow. Test in the in-app browser so I can watch. Commit and push only after the full suite passes, then pause for my go-ahead.

Before you start: XAMPP MySQL must be running (the user starts it). Start the dev server with the Browser pane's `preview_start` using the `atheris` entry (port 8010), and check `ls -lO` for iCloud `dataless` files if reads hang (see Environment gotchas).

### Scope

Sidebar group `Asset Management` (`resources/js/Config/navigation.js`):

| Page | Route | Controller | Page component | Gate |
|---|---|---|---|---|
| IT Assets | `assets.*` (full resource) | `AssetController` | `Assets/*` | `view assets` |
| Asset Discovery | `asset-discovery.index`, `asset-discovery.sync` | `PlatformController` stub | `Assets/Discovery.jsx` | `view platform` |
| Business Services | `business-services.index`, `business-services.graph` | `PlatformController` stub | `BusinessServices/*` | `view platform` |
| Business Assets | `business-assets.*` (full resource) | `BusinessAssetController` | `Assets/BusinessAssets/*` | `view assets` |

### Recon findings (11 Oct 2026, dev MySQL)

- **Tenancy:** `App\Models\Asset` has `BelongsToOrganization`. `App\Models\BusinessService`, `ServiceDependency`, `BusinessCapability` and `AssetSyncJob` have **no tenant-scope trait**, although all their tables have `organization_id`. Business Services, the graph, Asset Discovery and Business Assets (which uses `BusinessService`) are therefore likely cross-tenant, as Issues was. Verify in the browser as First Bank.
- **Stub writes:** `PlatformController::assetDiscoverySync` writes `organization_id => 1` and invents `records_imported` / `records_updated` with `rand()`.
- **Data:**
  - assets: Kano 51, First Bank 70.
  - `business_services`: 5, all Kano. First Bank has none.
  - `business_capabilities` and `service_dependencies`: 0 rows each, so the service graph has nothing to draw.
  - `asset_sync_jobs`: 5, all Kano.
- **Overlap to resolve:** EA has its own capabilities (`ea_capabilities`) and business services (`App\Models\Ea\BusinessService`), and `ea.sync.assets` syncs assets into EA. Decide whether the GRC Business Services page should read the EA model or stay separate; record the decision in the report, as module 6 did for vulnerability tickets.
- **Dashboards to check:** asset criticality feeds the vulnerability prioritiser (module 5) and the risk page's Assets tab. Make sure changes keep those consistent.
- `DashboardController::boardPackExport` still hard-codes org 1 (KRIs & Dashboards pass, not this one).

### Deliverables

- `tests/Feature/Assets/AssetManagementModuleTest.php`
- `docs/ATH-VAL-007_Asset_Management_Validation_Report.md`
- A deterministic per-bank demo seeder if data needs rebuilding (pattern: `IssuesDemoSeeder`, `SecurityOperationsDemoSeeder`), wired into `DatabaseSeeder` at the right point.
- Update this handoff: done table, suite count, highlights, next module (Vendor Management).

**Remaining sidebar order after that:** Vendor Management → Policy Management → ISMS → PCI Management → Business Continuity → Continuous Monitoring → KRIs & Dashboards → the rest of the sidebar.

### Patterns from modules 5-6 to reuse

- **Stub pages:** move `PlatformController` stubs into a module controller, with services under `app/Services/<Module>/`, and a `<Module>Metrics` service shared by the module page and the executive dashboard (`DashboardController` + `Pages/Dashboard.jsx` panel).
- **Tenancy:** strict `BelongsToOrganization` on the model, so route binding 404s across banks; pickers validated with tenant-scoped `Rule::exists` or the model's own scope.
- **Codes:** `GeneratesSequentialCode` for per-bank codes.
- **Workflow:** lifecycle transitions in a service with a `TRANSITIONS` map, with segregation of duties (the doer cannot approve or verify).
- **Webhooks:** signed with `sha256=HMAC(secret, "{ts}.{body}")`, outside auth and CSRF (`routes/web.php` top plus `bootstrap/app.php` exceptions); secrets use the `encrypted` cast and are shown once.
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
- **Issues & Remediation:** demo ITSM connectors are sandbox (live mode tested only with faked HTTP); no pre-breach reminders; verifiers cannot attach vault evidence; no bulk actions or CSV export; CCM failures, gaps and incident lessons do not auto-raise issues yet.
- **Compliance:** no notifications for gap due dates or evidence expiry. The ISMS Statement of Applicability is not yet linked to assessment results, which belongs in the ISMS module pass.

## Environment gotchas (important)

- **The project folder was restored from git (10 Oct 2026):** `vendor/`, `node_modules/`, `.env` and `.claude/launch.json` were missing. They were rebuilt with `composer install`, `npm install --legacy-peer-deps`, and a new `.env` (MySQL `it-security-risk`, `APP_URL=http://127.0.0.1:8010`). A scratch SQLite DB for `migrate:fresh --seed` lives at `database/database.sqlite` (gitignored); run it with `DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/database.sqlite`.
- **XAMPP MySQL** may not be running after a restart; the user starts it (it needs their permission).

- **iCloud eviction:** the project lives in iCloud-synced Documents, and iCloud keeps evicting files to "dataless". Reads and `git` then hang, and `phpunit.xml` and `.git/objects` have both been affected.
  - Detect with `ls -lO <file>` (look for the `dataless` flag).
  - Fix by copying from a fresh `git clone` of the remote in the scratchpad, or `git clone --bare` and copy its pack into `.git/objects/pack`, then `rm` the dataless loose objects.
  - Never `mv` dataless files to another volume; that forces a download and hangs.
  - **Recommended:** move the project out of iCloud Documents.
- **MySQL** is XAMPP: `/Applications/XAMPP/xamppfiles/bin/mysql -uroot it-security-risk`. The dev DB has been migrated and seeded in place, and includes the browser-test data (e.g. KHB-GAP-046, completed CBN assessment #10).
- **Dev servers** come from `.claude/launch.json` (uncommitted): `atheris` on port 8010. The Vite dev server's port 5180 clashes with another project, so use `npm run build` (delete `public/hot`) and serve built assets.
- **Fresh checkout / restore:** `storage/framework/{cache/data,views,sessions,testing}` and `bootstrap/cache` must exist or a dozen feature tests fail with "Please provide a valid cache path". `mkdir -p` them before running the suite.
- **Tests:** ParaTest isn't installed, so run `php vendor/bin/phpunit`. Never name a test helper `result()`, because it clashes with a final method on PHPUnit's TestCase.
