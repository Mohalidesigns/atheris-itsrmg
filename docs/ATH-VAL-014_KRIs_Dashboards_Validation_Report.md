# ATH-VAL-014 — KRIs & Dashboards: End-to-End Validation Report

**Module:** KRIs & Dashboards (sidebar group 14 of the platform validation programme). It covers:
- KRI Dashboard (`kri.*`)
- Board Packs (`board-packs.*`, plus the dashboard's "Generate Board Pack")
- Executive Dashboard (`reports.executive`)

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- The pages were walked in the in-app browser as the First Bank CISO, and the controllers, models, seeders and the EA publisher were traced.
- Locked in by `tests/Feature/Kri/KriDashboardsModuleTest.php` (6 tests, 104 assertions). The EA integration contract tests (I-8, EA KRI → platform) still pass.

**What the module did before this pass:**
- **Cross-bank leaks:**
  - `Kri`, `KriReading`, `KriBreach` and `BoardPackRun` had no tenant scope.
  - The KRI page listed every bank's KRIs, readings and breaches.
  - The EA publisher matched KRIs on code alone, so two banks' EA KRIs would overwrite each other, and its readings carried no bank.
- **Random KRI data:**
  - Readings were generated with `rand()`, mostly red.
  - Breaches were random rows.
  - Nobody could record a reading, set an owner or link a risk.
  - The card legend read "Red ≤ x" whatever the KRI's direction.
- **Fake board packs:**
  - "Generate" wrote a row for organisation 1 with PPTX/PDF paths that were never written.
  - The dashboard's "Export Board Pack (PPTX + PDF)" did the same.
  - There was no content and no approval.
- **Executive report:**
  - It counted false-positive vulnerabilities and closed risks as open, and its incident filter used a status that does not exist.
  - The inherent/residual heat-map toggle showed inherent risks under both labels.
- **Gates and coverage:**
  - KRIs and board packs sat in the platform group under `view platform`.
  - First Bank had no KRIs.

**Now:**
- **Services:**
  - `KriService` (readings, breaches, measurement);
  - `KriMeasures` (12 platform-measured KRIs);
  - `KriMetrics` (KRI dashboard, board packs, executive report);
  - `BoardPackBuilder` (snapshots and approval).
- **Controllers:** `Kri\KriController` and `Reports\BoardPackController`. The `PlatformController` stubs were removed.
- **Migration `2027_01_23_000001`:**
  - KRIs gain an owner, risk, frequency and source, with one code per bank;
  - readings and breaches carry their bank;
  - readings record who entered them and a commentary;
  - breaches record an action plan and the issue raised;
  - board-pack runs hold a snapshot, preparer and approver;
  - the fake runs are removed;
  - the Risk Manager gains `create reports`.
- **Gates:** KRIs sit under the `risks` permissions (view, edit to record, create to define); board packs under `reports` (view, create, approve).

## 1. KRIs

* **Status:** computed from the thresholds in the KRI's direction, never taken from the person entering the reading.
  * Where higher is worse: green up to the green limit, amber up to the amber limit, red beyond it.
  * Where lower is worse: the reverse.
  * The severe threshold is shown for reference.
  * Thresholds must be ordered for the direction.
* **Periods:** one reading per period (ISO week, month or quarter). Re-entering a period replaces its reading.
* **Commentary:** an amber or red reading needs commentary (cause and response).
* **Platform KRIs (12 of the 30-indicator pack):** measured from the bank's records by "Measure now" or the weekly `kri:measure` schedule:
  * overdue and open critical vulnerabilities, end-of-life assets in service;
  * attestation coverage, policies past review;
  * open critical incidents, incident MTTR;
  * vendors rated below C, vendor breach events;
  * CBN circulars not actioned;
  * DR runbooks not exercised;
  * failed changes.

  The others (channel availability, fraud, SIEM, DLP, phishing…) come from systems Atheris does not hold and are recorded by the owner. EA-published KRIs stay read-only and are edited in EA.
* **Breaches:**
  * An amber or red reading opens one breach, or escalates the open breach to red.
  * A green reading resolves it.
  * Red raises one issue (source `kri`) for the KRI owner, naming the risk the KRI indicates. Back to green adds a note on the issue but leaves it open for remediation sign-off.
  * The owner records an action plan against each open breach.
* **Register:**
  * the latest value and trend;
  * breach and action-plan state;
  * overdue readings (none within the KRI's frequency);
  * KRIs without an owner;
  * filters by status, breach, overdue and category.

## 2. Board packs

* **Generation:** a pack takes a snapshot of every section in its template from the bank's own figures:
  * executive summary, risk overview, heat map, top 10 risks;
  * KRI panel, risk appetite by KRI category;
  * control effectiveness against CCM, CCM results;
  * CBN return readiness;
  * incidents, vulnerabilities, third-party risk;
  * FAIR quantification, threat advisories.

  The pack the board read can therefore be reproduced after the records move on.
* **Output:** the pack renders as a print-ready Navy/Gold page, one section per page, and "Print / save as PDF" produces the file. No PPTX/PDF library is installed, so the old fake file paths are gone rather than imitated.
* **Workflow:** a pack is prepared as a draft (`create reports`) and approved for the board by someone else (`approve reports`). The approval and its note show on the cover.
* **Dashboard:** the executive dashboard's button now generates this month's BAC pack and opens it. It shows only to people who can create packs.

## 3. Executive report and dashboard

* **Executive report:**
  * open vulnerabilities are those open or in progress;
  * active incidents are those not resolved;
  * risk counts cover active risks only;
  * the residual heat map shows residual positions;
  * a KRI row shows green, amber and red, open breaches, breaches without an action plan, and overdue readings.
* **Executive dashboard:** the KRI panel shows the bank's KRIs worst-first instead of the first twelve rows.

## 4. Demo data

`KriDashboardsDemoSeeder` runs after `ContinuousMonitoringDemoSeeder`. Per bank:

* **KRI pack:** the 30-indicator pack (First Bank gains it), each KRI with an owner by category, the risk it indicates, and a weekly or monthly frequency. "EOL AIX host count" becomes "End-of-life assets in service", which is what the platform measures.
* **Readings:** they replace the random ones, recorded through `KriService`:
  * manual KRIs have a deterministic history (most within appetite, some amber, a few red);
  * platform KRIs are measured from the bank's records;
  * statuses and breaches follow;
  * red breaches raise issues;
  * most open breaches have the owner's action plan, a few do not;
  * two monthly KRIs have no recent reading.
* **Board packs:** last month's BAC pack and this month's CRO pack are approved; the CISO weekly pack is a draft.

## 5. Verification

* **Browser, as the First Bank CISO:**
  * The KRI register shows First Bank's 30 KRIs, with platform KRIs at its real figures (e.g. 41 critical vulnerabilities past due, attestation coverage 66.4%).
  * **KRI-IT-STALE:** the breach notes show amber in June, escalated to red in August, with FBN-ISS-0133 raised. Recording 0 resolved the breach, and the issue stayed open.
  * **Board packs:**
    * The draft CISO weekly pack shows First Bank's incidents, vulnerabilities, failing CCM tests and threat advisories; approving it put the approver on the cover.
    * "Generate Board Pack" on the dashboard opened a new BAC draft. Its preparer sees no approve action.
  * The executive report shows the KRI row, with no console errors.
* **Fixed during the walk:** units printed raw ("33 count", "NGN_M"). Values now read 33, 99.5%, 72h, ₦6m, on the pages and in breach notes and issues.
* **Automated:**
  * `KriDashboardsModuleTest` (6 tests, 104 assertions)
  * `EaIntegrationContractsTest`
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test
  * `npm run build`
* **Dev PostgreSQL:** the migration was applied and `KriDashboardsDemoSeeder` run.

## 6. Not in this pass

* **File formats:** board packs are not PPTX. A PPTX/PDF export would need a library (e.g. PhpPresentation, dompdf), which is a dependency decision for the bank.
* **Risk appetite:** there is no appetite statement model. Appetite is expressed through KRI thresholds, and the board pack's "risk appetite" section summarises KRIs outside appetite by category.
* **Threshold changes:** these are not approval-gated, and earlier thresholds are not versioned.
* **Other reports:** risk and compliance reports and scheduled reports (`reports.*` outside the executive dashboard) belong to the Reports sidebar group.
