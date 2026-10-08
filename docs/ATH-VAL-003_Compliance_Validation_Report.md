# ATH-VAL-003 — Compliance: End-to-End Validation Report

**Module:** Compliance (sidebar group 3 of the platform validation programme). It covers:
- Dashboard, Control Library, AUCS Browser and Regulatory Frameworks
- Compliance Assessments, Evidence Repository and Evidence Vault (WORM)
- Gap Analysis and the Obligations Register

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- Every page, form, table and action was exercised in the in-app browser as the Kano CISO.
- Isolation was then checked as the First Bank admin.
- Controllers, models and seeders were traced, and findings were fixed and re-tested.
- Locked in by `tests/Feature/Compliance/ComplianceModuleTest.php` (12 tests, 101 assertions).

| Area | Before → after |
|---|---|
| Demo data | All 45 seeded assessments had **zero requirement results**, behind hard-coded scores → real results, recomputed scores |
| Assessment lifecycle | No way to complete, cancel, reopen, edit or delete. Results editable forever → full lifecycle with locking |
| Gaps | Read-only list. Two vocabularies across modules. Dashboard count capped at 10 → managed register, one scale, live counts |
| Evidence | No upload UI. Any class name accepted as attachment target. Files on the public disk. No review → secure upload, review, download |
| Control ↔ framework | 0 mappings and no way to create one → mapping UI, 667 seeded mappings, coverage % |
| Security | Results editable cross-tenant. Obligations and vault leaked across banks → isolated |
| Dashboard feeds | Effectiveness donut mis-coloured and counted retired controls → ordered, operational-only, plus compliance score KPI |

---

## 1. Security and tenancy

| # | Finding | Fix |
|---|---|---|
| S1 | `PATCH /compliance-results/{id}` bound `ComplianceResult` globally (the model has no tenant scope). **Any user with edit rights could rewrite another bank's assessment results by id.** | The parent assessment is resolved through the tenant-scoped model, so another bank's result returns 404. Verified in the browser (First Bank result #1000 returned 404 and was unchanged) and in tests. |
| S2 | `evidence.store` accepted any `evidenceable_type` string and id, so evidence could be attached to any model class or any tenant's record. | Allowlist of attachable types (`control`, `compliance_result`, `gap`). The target must resolve inside the user's organisation. |
| S3 | Evidence files were stored on the **public** disk, so they were downloadable by URL without login. | Stored on the private disk under `evidence/{org}/`. Served only by the authorised `evidence.download` route, which returns 404 for another bank. |
| S4 | The Obligations Register, the Evidence Vault and the main dashboard's obligations calendar had **no tenant scope**: First Bank saw Kano's 56 obligations and 40 vault items. | `Obligation` and `EvidenceVaultItem` use `BelongsToTenant` (own org plus platform-shared NULL rows). |
| S5 | Control `owner_id`/`parent_id` and assessment `lead_assessor_id` were validated with bare `exists`, so users or controls from another bank could be assigned. A control could be made its own ancestor. | Tenant-scoped `exists` rules, plus a cycle check on the parent. |

## 2. Assessments

| # | Finding | Fix |
|---|---|---|
| A1 | **Seeded assessments had no results.** The page showed "92/92" and "50 compliant" over an empty list (and ISO has 97 requirements, not 92). Planned assessments carried scores. | `ComplianceDemoSeeder` creates one result per assessable requirement, consistent with each assessment's status (planned 0%, in progress partial, completed 100%), and **recomputes** every score from those results. |
| A2 | Results were created for domain headings too (e.g. ISO "A.5", NDPA "NDPA-1"), so they were scored as requirements. | Only leaf requirements are assessable, and results are grouped under their domain heading in the UI. |
| A3 | The score counted not-assessed items as failures, so an in-progress score started near 0%. | Score = (compliant + ½ partial) / *assessed* applicable requirements. Progress is shown separately. |
| A4 | There was no lifecycle at all (no route to complete), so `completed` was unreachable and the dashboard posture could never change. | Start, complete, cancel and reopen actions. **Complete is blocked until every requirement is assessed**, then stamps `end_date` and a conclusion. Completed and cancelled assessments are read-only, and a completed one can't be deleted. Details (title, scope, lead, dates, summary) are editable. |
| A5 | The result editor sent only status and findings. Recommendations and tested control were unused, and nothing required a finding. | Status pills, finding (**required** for non- or partially-compliant), recommendation, and tested control (pre-linked from mappings). Inline gap and evidence links, plus evidence upload per result. |
| A6 | Index: raw ISO timestamps, no filters or progress. Create: no start date, no preselect from a framework page. | Status tabs with counts, framework filter, search, progress bars, and overdue due dates. Create takes a start date (a future date makes it Planned) and preselects the framework from its page. |

## 3. Gaps (raised from results)

| # | Finding | Fix |
|---|---|---|
| G1 | The gap register was read-only: no assign, plan, close or log. | Full register: log a manual gap, update (owner, target, severity, priority, requirement, control, plan, notes), delete. KPI tiles act as filters (open, critical, high, overdue, unassigned, resolved). |
| G2 | A result going compliant never closed its gap. A result going from partial to non-compliant never re-graded it. | `ComplianceGapService`: a non- or partially-compliant result opens or **re-grades** the gap (high/medium), and its finding and recommendation become the gap's description and plan. Compliant or N/A **resolves** it as remediated. A risk-accepted gap is never auto-changed. |
| G3 | Two vocabularies were in use. Compliance used critical/high/medium/low and identified…closed, while the ISMS seeders wrote major/minor and open/resolved. Badges went blank, dashboard "open" counts included resolved gaps, and **the ISMS heat grid was always zero** (it looked up `high/medium/low`). | Migration `2027_01_11_000001` normalises existing rows and the seeders write the model's scale. ISMS pages display it with ISO non-conformity labels (Major, Moderate, Minor). ISMS views only show ISO-linked or unlinked gaps. |
| G4 | Moving into remediation needed no plan, and "accepted" needed no rationale. | A remediation plan is required for planned/in-progress, and an acceptance rationale for accepted. `completed_at` is stamped on resolution. |
| G5 | Codes reset to `GAP-0001` style regardless of the bank's `KHB-GAP-015` series. | The org prefix and width are preserved (KHB-GAP-016). |

## 4. Evidence

| # | Finding | Fix |
|---|---|---|
| E1 | There was no upload form anywhere, and the repository had 0 rows. | Upload from the Evidence page (choose a control or open gap), from a control (Evidence tab), or from an assessment result (paperclip). File types: document, screenshot, log, link, attestation. A file is required for file types and a URL for links, with validity dates. |
| E2 | There was no review: everything stayed "pending" forever. | Approve or reject (a reason is required to reject). **The uploader cannot review their own evidence** (segregation of duties). Approved evidence is removable only by an admin. Evidence past `valid_until` shows as Expired. |
| E3 | The list had no context on what the evidence supports, and no filters. | A "Supports" column links to the control, gap or assessment. Status tiles act as filters, plus type, subject and search. |

## 5. Control Library and frameworks

| # | Finding | Fix |
|---|---|---|
| C1 | **No control was mapped to any framework requirement** (`control_framework_mappings` was empty) and there was no UI to map. Every "Mappings" column read 0. | Mappings tab: pick framework, then requirement (leaf only), then coverage (full/partial/planned). Mappings can be updated or removed. The seeder maps controls by domain (about 1 in 12 left unmapped on purpose so the "Unmapped" filter has something to find). |
| C2 | The edit form dropped nature, frequency, parent, implementation notes and review dates. The `under_review` status (used by seeded data) failed validation. | A shared Create/Edit form with all fields. The status list includes Under Review. |
| C3 | Index had no status filter or sort. The effectiveness filter couldn't find "not assessed" (stored as NULL or `not_assessed`). | Status filter, sortable columns, quick filters (key controls, unmapped, review due within 30 days) and risk and evidence counts. |
| C4 | Control detail had read-only tabs with no testing history, and the risks tab query used unqualified columns on a pivot join. | Tabs: details (parent and sub-controls), mappings, **testing history** (every assessment result that tested this control), risks (rating and status) and evidence (upload and review). |
| F1 | Frameworks showed only requirement counts. | Each framework card and page shows **compliance score** (latest completed assessment) and **control coverage %**. The requirement tree shows mapped controls (coloured by effectiveness) and each requirement's latest result. Links start or continue an assessment. |

## 6. Feeds

* **Compliance dashboard:**
  * the overall score averages each framework's *latest completed* assessment
  * framework cards show the result mix and control coverage, and link to the assessment
  * the open-gaps KPI is a true count (it was `openGaps.length`, capped at 10), alongside critical/high, overdue and unassigned
  * the evidence KPI shows approved, pending and expired
  * recent assessments were already queried but never displayed, and are now shown
  * the control-effectiveness donut counts operational controls only and treats NULL as not assessed (it read 0)
* **Executive dashboard:** the control-effectiveness donut is now ordered and colour-consistent. Previously the colours followed query order, so "ineffective" could render green, and it counted deprecated and draft controls. A **Compliance %** KPI with open gaps replaces the bare "assessments" count. The obligations calendar is tenant-scoped and shares `Obligation::nextDue()` with the register.
* **Verified in the browser:**
  * recording a non-compliant CBN-1.1 result raised KHB-GAP-046 (finding as description, recommendation as plan)
  * the gap was assigned, put In Progress and given a target
  * link evidence was attached to it; self-review was refused; another officer's attestation was rejected with a reason
  * the remaining 4 requirements were assessed and the assessment completed at 80%
  * the CBN card on the dashboard updated to "14/20 compliant · Assessed 08 Oct 2026"

## 7. Obligations and Evidence Vault

* **Obligations:** tenant scope (S4), regulator filter, next-due date with days-to-due chip, ordered by due date, and evidence requirement shown. First Bank now correctly sees an empty register, since the platform demo layer is Kano's per `CLAUDE.md`.
* **Evidence Vault:** tenant scope (S4) and readable retention dates.

## 8. Demo data (`ComplianceDemoSeeder`, after both bank seeders)

The seeder is deterministic per tenant and each part is guarded per tenant. It produces:
- **Mappings:** 667 control → requirement mappings.
- **Results:** about 1,800 requirement results, with scores recomputed from them. Each later cycle of a framework scores a little better than the one before.
- **Gaps:** raised from each framework's latest completed assessment, at mixed remediation stages, with some overdue, some unassigned and some risk-accepted with a rationale. The latest in-progress assessment of each framework also raises gaps, all Identified.
- **Evidence:** about 110 items across controls, gaps and results. The mix is approved, pending, rejected (with a reason) and expired. Files are small text files on the private disk; links point to ticket URLs.

## 9. Verification

* **Browser** (Kano CISO, First Bank admin): the flows in §6, plus:
  * a control mapping added to KHB-CTL-001
  * First Bank isolation on obligations, results and evidence download
* **Automated:**
  * `ComplianceModuleTest` (12 tests)
  * the full PHP suite and a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test and `npm run build`

## 10. Not in this pass

* **AUCS Browser:** platform reference data that is read-only by design; it works. Linking a bank control to its AUCS source control is not modelled, so mappings use framework requirements.
* **Frameworks and requirements:** read-only reference data, with no custom-framework authoring.
* **Notifications:** none for gap due dates or evidence expiry (no scheduler, as with CSAT BR-AW-05).
* **Statement of Applicability:** ISMS's SoA is not yet connected to assessment results. That is for the ISMS module pass.
