# ATH-VAL-013 — Continuous Monitoring: End-to-End Validation Report

**Module:** Continuous Monitoring (sidebar group 13 of the platform validation programme). It covers:
- Dashboard (`monitoring.dashboard`)
- CCM Console (`ccm.*`)
- Controls Monitoring (`monitoring.controls`)
- Drift Detection (`monitoring.drift`, `drift.*`)
- Access Reviews (`monitoring.access-reviews`, `access-reviews.*`)

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- The pages were walked in the in-app browser as the Kano CISO and the First Bank CISO, and the controllers, models and seeders were traced.
- Locked in by `tests/Feature/Monitoring/ContinuousMonitoringModuleTest.php` (6 tests, 137 assertions).

**What the module did before this pass:**
- **Random results:**
  - "Run now" on a CCM test picked pass, warn or fail at random.
  - The seeder gave every test a random status.
  - Nothing was measured.
- **Synthetic pages:**
  - The Drift page generated 30 random events on every request.
  - Access Reviews was a hard-coded list of ten campaigns with a random reviewer per page load.
  - Neither could be acted on.
- **Cross-bank leaks:**
  - `CcmTenantTest` and `CcmTestRun` had no tenant scope.
  - Runs carried no bank at all.
  - The CCM console and monitoring dashboard counted every bank's tests and runs.
- **No links:**
  - CCM tests were not linked to controls.
  - Failures raised nothing.
  - The Controls page only listed effectiveness ratings.
- **Gates and stubs:**
  - The CCM console sat in the platform group under `view platform`.
  - Its controller was a `PlatformController` stub.
- **Coverage:** First Bank had no CCM tests.

**Now:**
- **Services:**
  - `CcmChecks` and `CcmRunner` measure and run the tests.
  - `DriftService` detects, reconciles and works drift.
  - `AccessReviewService` runs recertification campaigns.
  - `MonitoringMetrics` feeds every page and the executive dashboard.
- **Controllers:** `Monitoring\{CcmController, DriftController, AccessReviewController}` and a rewritten `MonitoringController`.
- **Migration `2027_01_22_000001`:**
  - CCM tests gain a control, an owner, the issue they raised and their last value;
  - runs gain their bank, mode, measured value, threshold, findings and who triggered them;
  - one copy of each test per bank;
  - new tables `config_drift_events`, `access_review_campaigns` and `access_review_items`;
  - monitoring permissions for the Compliance Officer (all) and the Risk Manager (view).

## 1. CCM

* **Measured checks:** each of the 40 starter-pack tests has a metric, a unit, a direction and pass/warn limits (`CcmChecks::CHECKS`).
* **Platform checks (15 of 40):** measured from the bank's own records. A run therefore reflects the platform's real state:
  - policies within review date, attestation coverage;
  - DR exercises last quarter, NIP failover within RTO, BIAs approved in 12 months;
  - critical vulnerabilities past due, mean time to resolve incidents;
  - change approval within 5 days, open unauthorised drift;
  - tier-1 vendor assessments, vendor ratings below C;
  - circulars actioned on time, returns past due;
  - data-centre access review age, unconfirmed privileged revocations.
* **Sandbox checks (25 of 40):** these need a tool no bank has connected (AD, AWS, Defender, Tenable, SIEM, DLP…). They run against a deterministic per-bank catalogue, are recorded with `mode = sandbox`, and are labelled as such in the console and on the test page.
* **A run:**
  * records the value, threshold, findings (e.g. which policies are past review) and who triggered it (or the scheduler);
  * schedules the next run from the test's cron;
  * moves the linked control's last-tested date.
* **Scheduling:** `ccm:run-due` runs due tests every 15 minutes.
* **Failures:**
  * A failure raises one issue per test (source `ccm_test`), owned by the test owner or else the control owner.
  * A later pass notes on the issue that the test passes again. It does not close it: remediation sign-off and verification stay with module 6's workflow.
* **Settings:** each bank links a test to one of its controls (tenant-checked), sets an owner, pauses or disables it, and can override the pass/warn limits (the warn limit must sit on the right side of pass).

## 2. Controls Monitoring

* **Side by side:** each control's assessed effectiveness is shown beside the CCM signal (the worst latest result of its linked tests).
* **Disagreements:**
  * Controls rated *effective* whose test fails, or rated *ineffective* while every test passes, are flagged.
  * They can be filtered.
  * They are counted on the monitoring and executive dashboards.

## 3. Drift detection

* **Scans:** each source (AWS Config, Defender for Cloud, Wazuh FIM, Tenable SC) scans the bank's own registered assets of the types it covers. It runs in sandbox mode with a deterministic catalogue of baseline deviations.
* **Recording drift:** drift can also be recorded by hand (an audit or engineer's finding). An open event for the same asset and deviation is not duplicated.
* **Reconciliation:** a deviation inside the window of an approved change that lists the asset is **authorised** and linked to that change; everything else is unauthorised.
* **Issues:** unauthorised critical drift raises an issue (source `config_drift`), and any open drift can raise one by hand.
* **Workflow:**
  * investigate, remediate (what was restored, required) or link to an approved change;
  * accept the risk (`approve monitoring`, not by the drift's owner, with a justification and an expiry of at most 12 months);
  * reopen.
* **CCM link:** open unauthorised high/critical drift feeds CCM-OPS-CFG-026.

## 4. Access reviews

* **Launch:** a campaign is launched over a system with a reviewer (tenant-checked) and a due date. Entitlements come from:
  * Atheris' own users and roles (a real extract; Super Admin and Organization Admin are privileged);
  * an AD extract (sandbox: the bank's users mapped to AD groups, plus the service and leaver accounts an AD review typically surfaces);
  * entries added by hand.
* **Decisions:** keep, revoke or modify, with a reason required for removals.
* **Segregation of duties:**
  * Nobody decides on their own access.
  * A removal is confirmed as done by someone other than the person who decided it.
  * A campaign completes (`approve monitoring`) only when every entitlement has a decision.
* **Tracking:**
  * Overdue campaigns are flagged.
  * Unconfirmed privileged removals older than 7 days feed CCM-IAM-PRIV-003.
  * The last completed physical-access review feeds CCM-PHY-ACC-033.

## 5. Dashboards

* **Monitoring dashboard:**
  * the bank's CCM results and the 14-day run trend;
  * latest runs (with mode);
  * unresolved drift by severity;
  * campaigns in progress;
  * control ratings that disagree with CCM.
* **Executive dashboard:**
  * a Continuous Monitoring panel (tests passing, failing, rating disagreements, open high/critical drift, overdue reviews, removals to confirm);
  * the existing CCM mini-chart is now scoped to the bank's enabled tests and labelled "latest results".

## 6. Demo data

`ContinuousMonitoringDemoSeeder` runs after `BusinessContinuityDemoSeeder`. It goes through the real services, per bank:

* **Access reviews:** four campaigns:
  * last quarter's privileged-access review of Atheris (completed, one removal confirmed);
  * a data-centre badge review completed 70 days ago;
  * an AD-groups review in progress, with a privileged service account revoked 10 days ago and not yet removed;
  * a vendor-access review past its due date.
* **Drift:**
  * a month of detections reconciled against the bank's approved changes, so change-window deviations are authorised;
  * older drift remediated or accepted (by a non-owner, with expiry);
  * recent drift open or under investigation.
* **CCM:**
  * every starter-pack test enabled, most linked to a control in the matching domain, a few deliberately unlinked;
  * 14 days of daily runs;
  * today's run raising issues for failures.
  * Kano's random-status CCM rows are replaced, and First Bank gains its tests.
* **Failures are real findings:** the platform-measured failures come from the demo records themselves:
  * attestation coverage, vendor assessments and overdue critical vulnerabilities;
  * circulars actioned late, and First Bank's unsigned returns and stale DR exercises.

## 7. Verification

* **Browser, as the Kano CISO:**
  * All seven monitoring pages render, with no console errors.
  * **CCM:** "Run now" on CCM-GOV-POL-021 recorded 86.7% (fail) and named the two policies past their review date.
  * **Drift:**
    * A Defender scan found KHB-DRF-047 (encryption at rest disabled, critical) and raised KHB-ISS-0045.
    * Accepting its risk as the CISO (not its owner) recorded the expiry.
  * **Access reviews:**
    * In KHB-UAR-003, the CISO's own entitlement offers no decision.
    * A revoke needs a reason, and the revocation then shows "awaiting IT".
    * Confirming the svc_backup removal (decided by the reviewer) worked.
* **Browser, as the First Bank CISO:** the monitoring dashboard shows First Bank's own figures.
* **Fixed during the walk:**
  * the executive panel label claimed only "effective but failing" when it counts both disagreement directions;
  * the drift page offered "Link to an approved change" when no change lists the asset;
  * the seeder's investigation step stopped at the first record (`each()` returning false).
* **Automated:**
  * `ContinuousMonitoringModuleTest` (6 tests, 137 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test
  * `npm run build`
* **Dev PostgreSQL:** the migration was applied and `ContinuousMonitoringDemoSeeder` run.

## 8. Not in this pass

* **Live connectors:** none. Sandbox checks and drift scans run the real code path against a catalogue, as module 7's asset discovery does. Connecting a tool is a configuration job per bank.
* **Evidence:** CCM runs do not yet write evidence-vault items (`evidence_ids` stays empty). The evidence vault is a separate sidebar item.
* **Access reviews:** revocations are confirmed by hand. Atheris does not remove the role or AD group itself. Campaigns are launched by hand; cadence does not schedule the next one.
* **KRIs:** the old dashboard's "KRIs in red" tile was dropped from this page. KRIs are module 14.
