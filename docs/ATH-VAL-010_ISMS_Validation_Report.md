# ATH-VAL-010 — ISMS: End-to-End Validation Report

**Module:** ISMS (sidebar group 10 of the platform validation programme), ISO/IEC 27001:2022. It covers:
- Overview (`isms.index`)
- ISMS Risks (`isms.risks`)
- ISMS Controls (`isms.controls`)
- ISMS Audit (`isms.audit`)
- Statement of Applicability (`isms.soa.*`)
- ISO 27001 Gap Analysis (`isms.gap-analysis`)

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- The pages were walked in the in-app browser as the Kano CISO (Organization Admin), and the controller, data and seeders were traced.
- Locked in by `tests/Feature/Isms/IsmsModuleTest.php` (6 tests, 107 assertions).

**What the module did before this pass.** Every page was read-only, and the Statement of Applicability could not be maintained:
- **Nothing to edit:** there was no way to record applicability, a justification or implementation status, and nothing recorded who changed an entry.
- **No sign-off:** the page said the SoA is "signed annually by the CISO", but nothing supported a sign-off.
- **Dead export:** "Export SoA (PDF)" had no handler.
- **Claims never checked:** the SoA's implementation claims were never compared with anything. In the demo data:
  - Kano marked controls "implemented" whose implementing control tested ineffective;
  - Kano excluded A.5.1 *Policies for information security*, A.5.5 *Contact with authorities* and A.8.5 *Secure authentication*, which no bank can exclude;
  - every entry had one of two boilerplate justifications.
- **Hard-coded maturity:** the overview showed fixed clause maturity scores (3.5, 3.8, 3.2…) and a "3.3 / 4.0" card computed from nothing.
- **Hard-coded bank name:** the subtitle named Kano Heritage Bank for every bank.
- **No scope on "ISMS Risks":** the overview counted risks by source (self-assessment or audit), while the risks page listed the top 60 risks of any kind.

## 1. Statement of Applicability

* **Evidence beside every claim** (`StatementOfApplicabilityService`). Each Annex A control shows:
  * the bank's decision;
  * the controls implementing it: the SoA's primary control plus every control mapped through `control_framework_mappings`, each with its tested effectiveness;
  * the result of the latest completed ISO 27001 assessment.
* **Contradiction flags** (shown inline, as a KPI and as a filter):
  * implemented, but a control implementing it tested ineffective;
  * implemented, but assessed non-compliant;
  * applicable, but no control implements it;
  * assessed compliant, but the SoA still says planned or not started;
  * no reason given for including or excluding it;
  * no decision recorded.
* **Editing** (`edit isms`): applicability, a justification (required both ways), implementation status and the primary control (tenant-checked).
  * An excluded control is stored as not applicable; an applicable one cannot be.
  * Each entry records who changed it, and changes are activity-logged.
* **Versions** (ISO/IEC 27001 cl. 6.1.3 d, documented information approved by management):
  * "Submit new version" freezes every entry as a snapshot. It is refused while any control is undecided, or while another version is pending.
  * Someone other than the preparer approves it or returns it with a reason (`approve isms`).
  * The page lists every version and reports which entries changed since the last approved one (applicability, implementation or justification), with a "changed since approval" filter.
* **Export:** "Export SoA (CSV)" (`export isms`) includes the evidence and the flags. Annex A codes now sort naturally, so A.5.2 comes before A.5.10.

## 2. Overview and risks

* **Overview:**
  * The hard-coded maturity panel is replaced, per Annex A theme, by "what the SoA says is implemented" against "what the latest completed assessment found compliant", with the number of flagged entries.
  * KPIs: claim ≠ evidence, ISMS risks above appetite, open and overdue non-conformities, and the approved SoA version and its date.
  * A banner appears when a version awaits approval or entries have changed since approval.
  * The bank name comes from the signed-in organisation.
  * The assessment card shows the completed assessment the evidence is read from.
* **ISMS Risks:**
  * Now the active risks in the ISMS scope: the information-security risk categories in `config/isms.php` (cyber, technology, OT, third-party, data protection, physical, technology obsolescence).
  * The scope is stated on the page, and each risk lists the controls treating it.
  * A "no treating control" count is added.
  * The five-point rating scale counts "very low" under Low, so the bands add up.

## 3. Demo data

`IsmsDemoSeeder` runs after `PolicyManagementDemoSeeder`. Per bank it:
* **re-includes the controls excluded at random**, taking their implementation status from the latest assessment result;
* **keeps only defensible exclusions, with the reason:**
  * Kano: A.8.30 *Outsourced development* (all development is in-house) and A.7.12 *Cabling security* (the colocation provider's, under its ISO 27001 certificate);
  * First Bank: A.7.12;
* **writes a justification for every applicable control** from its theme, keeping any specific one;
* **issues two approved versions** (last year's and this year's review), prepared by the ISMS lead and approved by the CISO;
* **updates three entries afterwards**, so "changed since approval" has content;
* **leaves the claim/evidence contradictions in place**: Kano has 19 and First Bank its own.

## 4. Verification

* **Browser, as the Kano CISO:**
  * The overview shows 93 Annex A controls (91 applicable), 51 implemented, 19 claim ≠ evidence, 26 ISMS risks (8 above appetite), 46 open non-conformities (10 overdue), and SoA v2 approved 14 May 2026, with 3 entries changed since.
  * The SoA shows A.5.1 flagged "Marked implemented, but a control implementing it tested ineffective", and A.5.11 marked "changed: implementation, justification".
  * Submitting version 3 worked, and the CISO, as preparer, was shown "Awaiting another approver" instead of Approve.
  * ISMS Risks lists the in-scope categories and 17 risks with no treating control.
* **Fixed during the walk:**
  * the overview's assessment card showed an in-progress audit while the evidence came from the completed one;
  * the risk bands did not add up to the total ("very low" was dropped).
* **Automated:**
  * `IsmsModuleTest` (6 tests, 107 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test (205 routes)
  * `npm run build`
* **Dev PostgreSQL:** the migration was applied and `IsmsDemoSeeder` run.

## 5. Not in this pass

* **Clauses 4–10:** management-system clause evidence (context, leadership, management review minutes) is not modelled. The overview no longer pretends to score them.
* **Annex A Controls page:** it still lists requirements with SoA status only; the SoA page is the richer view.
* **Control Standards mappings:** policy → control mappings (module 9) are not yet shown against Annex A.
* **Exports:** CSV only, with no signed PDF of an approved version.
* **Audit and gap pages:** ISMS Audit and Gap Analysis read the Compliance module's assessments and gaps unchanged; they were validated in module 3.
