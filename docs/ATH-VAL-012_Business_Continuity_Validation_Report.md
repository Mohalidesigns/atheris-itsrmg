# ATH-VAL-012 — Business Continuity: End-to-End Validation Report

**Module:** Business Continuity (sidebar group 12 of the platform validation programme). It covers:
- BCP Plans (`bcp.plans.*`)
- DR Plans (`bcp.dr-plans`)
- DR Runbooks and DR Exercises (`dr.*`)
- Business Impact Analysis (`bcp.bia.*`)
- Tests & Exercises (`bcp.tests`)

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- The pages were walked in the in-app browser as the Kano CISO, and the controllers, models and seeders were traced.
- Locked in by `tests/Feature/Continuity/BusinessContinuityModuleTest.php` (6 tests, 135 assertions).

**What the module did before this pass:**
- **Cross-bank leaks:**
  - `DrRunbook` and `DrExercise` had no tenant scope, and `dr_exercises` no `organization_id` at all.
  - The DR Runbooks and Exercises stubs, the DR Plans page and the Tests page queried their tables directly, so every bank saw every bank's runbooks, exercises and BCP tests.
- **No plan lifecycle:** plans could be created and viewed, but not edited, approved or tested. Some draft plans carried an approver.
- **BIA disconnected:** the BIA was a list of process names with no link to the business services it should set objectives for. Module 7 left "BIA writes RTO/RPO onto the service" to this module.
- **Pickers:** the plan and BIA owner was `exists:users,id` (any bank).
- **Codes:** new plan codes were `BCP-0001`, outside the bank's `KHB-BCP-…` series.
- **Formatting:** dates showed as raw ISO strings, and BIA financial impact was in US dollars.

**Now:**
- **`ContinuityMetrics`** feeds the plan register, DR pages and the executive dashboard.
- **`BcpController`** gains edit, approve and test recording for plans, and update and apply for BIAs.
- **`DrController`** replaces the PlatformController stubs and adds exercise recording.
- **Migration `2027_01_21_000001`:**
  - DR exercises are tenant-scoped and measured against their RTO;
  - BIAs are linked to services and approval is recorded;
  - plans are linked to the services they recover (`bcp_plan_service`);
  - drafts lose the approver they should never have had.

## 1. Plans and tests

* **Approval:** a draft or under-review plan is approved by someone other than its owner (`approve bcp-plans`). It needs a scope and an RTO; approval puts it in force and sets a review date.
* **Material changes:** an approved plan whose scope, objectives or RTO/RPO change goes back to under review, with the approval cleared.
* **Services:** each plan names the business services it recovers. The plan page shows each service's RTO and flags where the plan's RTO is longer than the service needs.
* **Recording a test** (`edit bcp-plans`):
  * type, date (not in the future), pass, partial or fail, an optional recovery time, results, and findings (required unless it passed);
  * it sets the plan's last-tested and next-test dates.
* **Flags:** plans untested for 12 months are flagged on the plan page, in the register's tiles and filters, and on the dashboard.

## 2. BIA and business services

* **Linking:** each BIA names the business service it assesses (tenant-checked). MTPD cannot be shorter than RTO.
* **Approve & apply:** someone other than the process owner (`approve bcp-plans`) approves the BIA and writes its RTO/RPO onto the service (`ea_business_services`).
  * EA, Asset Management (RTO conflicts, inherited criticality) and the DR plans therefore read one set of objectives.
  * Changing the BIA's objectives clears the approval until it is re-applied.
* **Mismatch flag:** a BIA whose RTO differs from its service's is flagged with the two values.
* **Coverage:** critical and high services with no BIA are listed.

## 3. DR runbooks and exercises

* **Recording an exercise:** start time, achieved recovery time, participants, outcome and lessons.
  * It passes within the runbook's target, is "partial" up to 1.5× the target, and fails beyond that.
  * Runbooks show the last result; the exercise list shows achieved against target.
* **Gates:** DR pages move from `view platform` to `view bcp-plans`, and recording needs `edit bcp-plans`.

## 4. Dashboard

A Business Continuity panel on the executive dashboard shows:
* plans in force;
* plans not tested in 12 months;
* critical services with no BIA, and with no plan;
* BIA RTOs not applied to the service;
* DR exercises within RTO over the last 12 months.

## 5. Demo data

`BusinessContinuityDemoSeeder` runs after `PciDemoSeeder`. Per bank it:
* links BIAs to their services and applies most of them, leaving two with the service out of step;
* links plans to services by what they cover;
* gives First Bank the DR runbooks and BCP test history it lacked;
* measures every exercise against its target, with a few over;
* leaves two plans untested for over a year.

## 6. Verification

* **Browser, as the Kano CISO:**
  * All seven BC pages render, with no console errors.
  * The BIA page lists 7 critical or high services with no BIA, and flags Loan Origination (service 8h ≠ BIA 24h) and Treasury Trading (2h ≠ 4h).
  * "Approve & apply" on Treasury Trading set FX Operations to RTO 4h and RPO 1h.
  * The plan page for KHB-BCP-001 shows its four services, each flagged as "plan RTO longer".
* **Fixed during the walk:**
  * the new BIA fields were missing from `$fillable` (the test caught it), so linking and approval silently saved nothing;
  * raw ISO dates on the plan page;
  * a plan under review said "Approved by" rather than "Last approved by";
  * BIA financial impact was shown in USD instead of NGN.
* **Automated:**
  * `BusinessContinuityModuleTest` (6 tests, 135 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test (205 routes)
  * `npm run build`
* **Dev PostgreSQL:** the migration was applied and `BusinessContinuityDemoSeeder` run.

## 7. Not in this pass

* **Plan content:** no structured plan content (call trees, recovery teams, alternate sites) and no document upload.
* **Issues:** failed tests and exercises do not raise issues automatically.
* **Scheduling:** exercises are recorded after the fact; there is no scheduling or reminders.
* **Crisis communications and regulators:** not modelled. Notification to the CBN of a major disruption sits in Security Operations' regulatory notifications.
