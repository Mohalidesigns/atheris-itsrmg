# ATH-VAL-011 — PCI Management: End-to-End Validation Report

**Module:** PCI Management (sidebar group 11 of the platform validation programme), PCI DSS v4.0.1. It covers:
- Dashboard (`pci.dashboard`)
- Cardholder Data Environment (`pci.cde`, `pci.cde.scope`)
- PCI Controls (`pci.controls`, `pci.controls.map` / `unmap`)
- Assessment, ROC / SAQ (`pci.saq`)
- Control Matrix (`pci.matrix`)
- Evidence Repository (`pci.evidence`)

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- Every page was walked in the in-app browser as the Kano CISO.
- The controller and data were traced.
- Locked in by `tests/Feature/Pci/PciModuleTest.php` (6 tests, 114 assertions).

**What the module did before this pass.** The module had no data model behind it, and almost everything on its pages was invented:
- **Dashboard:** "maturity" percentages per requirement were a hard-coded array (85, 78, 92…). "Last assessed" was always 15 days ago, and the next assessment always 180 days away. The evidence count included every bank's vault.
- **PCI Controls:** each requirement showed four controls picked with `random()`, a different set on every page load.
- **Self-Assessment (SAQ):** six questions per requirement were generated from templates, with answers computed from a formula. Nothing could be answered.
- **Control Matrix:** levels were the hard-coded maturity plus `rand(-15, 10)`.
- **Evidence:** it read organisation 1's evidence vault for every bank, mapped at random to requirements.
- **CDE:** the cardholder data environment was guessed from asset categories, with no scoping decision anywhere.
- **No framework:** PCI DSS was not among the platform's frameworks, so there was nothing to assess or map against.

**Now** (migration `2027_01_20_000001`):
- **Framework:** PCI DSS v4.0.1 is a platform framework, with its 12 principal requirements and their 63 defined sub-requirements.
- **Assessments** (ROC or SAQ) run on the compliance-assessment engine, like every other framework.
- **Controls** map through `control_framework_mappings`.
- **Assets** carry the bank's CDE scope decision and its rationale.
- **`PciPosture`** reads the bank's own assessment, mappings, evidence and scoping, and every page is built from it.

## 1. Pages

* **Dashboard:**
  * sub-requirements in place (of those tested) overall and per requirement, from the latest completed PCI assessment;
  * next validation due (last completed plus 12 months, `config/pci.php`), with the validation type (Level 1 ROC by default);
  * CDE and connected-to asset counts, and assets not yet scoped;
  * controls mapped, how many are ineffective, and sub-requirements with no control;
  * PCI policies in force.
* **CDE:** every asset with its scope: in the CDE, connected-to / security-impacting, or out of scope.
  * Scoping needs a rationale (PCI DSS 12.5.2) and `edit pci`.
  * The tiles filter by scope, including "not yet scoped".
* **PCI Controls:** per sub-requirement, the bank's mapped controls with tested effectiveness.
  * Controls can be mapped and unmapped (`edit pci`), checked against the bank's own controls and PCI sub-requirements.
  * Mapping twice does not duplicate.
* **Assessment (ROC / SAQ):**
  * Results from the latest assessment, in PCI reporting terms: in place, partially in place, not in place, not applicable, not tested.
  * Findings and an evidence count are shown per sub-requirement.
  * "Start a PCI assessment" opens the compliance engine with PCI DSS preselected, and the page lists every PCI assessment.
  * The sidebar label changed from "Self-Assessment (SAQ)" to "Assessment (ROC / SAQ)". A deposit-money bank that issues and acquires is validated by a ROC.
* **Control Matrix:** every sub-requirement coloured by its real result, with its mapped-control count. It is stable between loads (this is tested).
* **Evidence:** the bank's evidence attached to PCI assessment results or to controls mapped to PCI requirements.
  * Each item shows the sub-requirements it supports, with expiry flagged.
  * Coverage is shown per requirement.

## 2. Security and tenancy

* Evidence, assessments, mappings and scoping are all read for the signed-in bank. The organisation-1 evidence and every-bank vault count are gone.
* Scoping another bank's asset is a 404. Mapping another bank's control, or a non-PCI requirement, is refused. Unmapping another bank's mapping is a 404.
* **Gates:** `view pci` to read; `edit pci` to scope and map. Starting an assessment uses the compliance permissions.
* **Fixed during the walk:** `assets.pci_scope` was not in `Asset::$fillable`, so scoping silently saved nothing. The test caught it.

## 3. Demo data

`PciDemoSeeder` runs after `IsmsDemoSeeder`. Per bank it:
- **Scopes every asset from what it is**, with the reason:
  - in the CDE: ATMs, PoS, HSMs, card gateways, payment hubs;
  - connected-to: directory, firewalls, switching, SIEM, EDR, scanners, PAM, data centres, the core banking host;
  - the rest out of scope;
  - cloud accounts and assets still under discovery review left unscoped (deliberate gap).
- **Maps controls to sub-requirements by control domain**, leaving wireless (2.3, 11.2) and payment-page monitoring (11.6) unmapped (deliberate gap).
- **Records last year's ROC as a completed assessment:**
  - mostly in place;
  - partially in place: 3.4, 6.4, 8.4 and 10.4, each with a finding;
  - not in place: 11.3 (missed quarterly internal scan) and 12.5 (scope not revalidated);
  - not applicable: 2.3;
  - eleven pieces of evidence, the penetration-test report now expired.
- **Records this year's ROC in progress**, with requirements 1 to 4 tested.
- **Replaces the PCI assessments** that `ComplianceDemoSeeder` (module 3) generates on a fresh install. It creates assessments for every active framework, and PCI DSS is now one.

## 4. Verification

* **Browser (Kano CISO):**
  * The dashboard shows 90% of tested sub-requirements in place, next validation due 14 Jun 2027, 5 CDE and 17 connected-to assets, 8 unscoped, 48 mapped controls (7 ineffective) and 3 sub-requirements with no control.
  * The assessment page shows 55 in place, 4 partially, 2 not in place, 1 N/A and 1 not tested.
  * Evidence shows 31 items, 2 expired, covering 44 of 63 sub-requirements.
  * The CDE, Controls and Matrix pages render with no console errors.
* **Fixed during the walk:**
  * the scope field was not fillable;
  * the evidence label said "link" for attestations with no file;
  * on a fresh install, the module 3 seeder's generated PCI assessments blocked this seeder.
* **Automated:**
  * `PciModuleTest` (6 tests, 114 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test (205 routes)
  * `npm run build`
* **Dev PostgreSQL:** the migration was applied and `PciDemoSeeder` run.

## 5. Not in this pass

* **Requirement depth:** the framework stops at the defined sub-requirements (x.y). The individual testing procedures (x.y.z) are not loaded.
* **Reporting:** no ROC or AOC document generation, and no compensating-control (CCW) worksheet.
* **Scans:** ASV scan results are not ingested. 11.3 is tracked as an assessment result and through the vulnerability module.
* **Validation type:** it is a configuration value. Per-bank merchant or service-provider levels are not modelled.
