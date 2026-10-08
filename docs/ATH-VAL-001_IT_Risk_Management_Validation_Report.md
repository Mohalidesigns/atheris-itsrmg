# ATH-VAL-001 — IT Risk Management: End-to-End Validation Report

**Module:** IT Risk Management (sidebar group 1 of the platform validation programme)
**Tenant used:** Kano Heritage Bank Plc (org 2, `admin@kanoheritage.ng`)
**Method:** every screen, filter, form, button, modal and relationship exercised in the in-app browser, and the code traced underneath. Each finding was fixed and re-tested in the browser. A regression test (`tests/Feature/Risk/RiskModuleTest.php`, 14 tests) locks the fixes in.

| Sub-module | Route | Verdict before → after |
|---|---|---|
| Risk Dashboard | `risks.dashboard` | Numbers didn't reconcile; Residual toggle and donut didn't work → fixed |
| Risk Register | `risks.index` | Status filter mismatched the data; heat map wrong → fixed |
| Risk detail | `risks.show` | 6 of 10 tabs showed hard-coded sample data → real data |
| Create / Edit risk | `risks.create/edit` | **Saving any seeded risk failed silently** → fixed |
| Risk Graph | `risks.graph` | Unrelated nodes around a fake centre, cross-tenant query → real graph |
| Risk Assessments | `risk-assessments.*` | Edit/delete did not re-score the risk; tenant gap → fixed |
| Risk Treatments | `risk-treatments.*` | Every status shown as "Identified"; completion didn't flow → fixed |
| Threat Register | `threats.*` | **No seeded threat could be edited or filtered** → fixed |
| FAIR Quantification | `fair.*` | "Monte Carlo" was `rand()`; not tenant-scoped → real simulation |
| Question Library | `question-libraries.*` | Static empty page → full CRUD with seeded questions |
| Executive Dashboard (feeds) | `dashboard` | Plotted closed risks; orientation and colours differed → aligned |

---

## 1. Cross-cutting findings

| # | Finding | Impact | Fix |
|---|---|---|---|
| X1 | Three different 5×5 rating scales: model and badges used 20/15/8/4, seeders and the Executive Dashboard used 20/12/6. | A score of 12 was "high" in the database, "medium" on its badge and orange on one heat map but yellow on another. | One scale in `Risk::RATINGS` (critical ≥20, high ≥12, medium ≥6, low ≥3, very low <3), mirrored in `resources/js/Utils/risk.js`. A migration recomputed stored ratings. |
| X2 | Risk statuses: the model allowed `treating`/`archived`, but the data held `in_progress`, `mitigated` and `under_review`. | Status filter options matched no records; the edit form couldn't represent the real statuses. | Canonical lifecycle: identified → assessed → treating → mitigated → accepted → under_review → closed → archived. Migration maps `in_progress` → `treating`. Seeders and factory fixed. |
| X3 | Risk appetite: the form offered averse…hungry, the data held within/above/below. | **Saving any seeded risk failed** with an error on a field the form didn't show, so nothing visibly happened. | Appetite is the risk's position against the board appetite (`within`/`above`/`below`). It is shown everywhere and is now an "above appetite" KPI. Seeders derive it from residual exposure. |
| X4 | Risk sources: the form offered `assessment`/`self_identified`, the data used `self-assessment`/`regulator`/`ea.obsolescence`. | Same silent-failure class. | Canonical `Risk::SOURCES`; migration maps legacy values. |
| X5 | Foreign keys validated with unscoped `exists:` rules. | A crafted request could attach another bank's category, owner, asset or risk. Assessment and treatment `store` wrote the record before the tenant check, leaving orphans. | All rules scoped to the user's organisation. The risk is resolved first, and writes happen in a transaction. |
| X6 | `sort` and `direction` passed straight to `orderBy`. | Error 500 on any unknown column. | Whitelisted on the register and threat lists. |
| X7 | Raw ISO timestamps in tables (`2026-07-17T00:00:00.000000Z`), raw enum labels (`In_progress`). | Unprofessional and confusing. | Shared `formatDate` and `humanize` helpers. |

## 2. Dashboards (how the module feeds them)

| # | Finding | Fix |
|---|---|---|
| D1 | Severity tiles counted all 40 risks, including closed ones, while the heat map and donut counted 36 open risks. | Tiles count the active register (shown as "Open, inherent"). The total tile says how many are closed or archived. Tiles reconcile exactly with the heat map. |
| D2 | The Inherent/Residual toggle only changed the heading; the data was always inherent. | Both maps are served and the toggle switches between them. |
| D3 | Heat-map cells weren't clickable. | Cells drill into the register (`?likelihood=&impact=&basis=`), with a removable filter chip. This works from both dashboards. |
| D4 | The donut didn't render, and the status breakdown was computed but never shown. | Donut renders and is clickable. A status breakdown with links was added. |
| D5 | The Executive Dashboard plotted closed risks, used the reverse orientation (impact on rows) and placed unscored risks at 3×3. Top 10 included closed risks. | Open risks only, same orientation and colours as the risk dashboard, no fabricated placement. Top 10 shows open risks. The KPI is now "Open Risks · N above appetite". |

## 3. Risk Register, detail, create and edit

| # | Finding | Fix |
|---|---|---|
| R1 | The register heat map plotted only the 15 rows on the current page, defaulted unscored risks to 3×3 and swapped axes versus the dashboard. | Plots the whole filtered register, uses the same orientation, keeps the 3×3/4×4/5×5 option, and adds an inherent/residual toggle. |
| R2 | No owner or appetite filter, no "active only", no sorting, no clear-filters option. | All added. Column headers sort. |
| R3 | Detail tabs Issues, Evidence, History, Audit Trail, Threats/Vulnerabilities and FAIR showed **hard-coded samples**, identical on every risk. Linked Controls fell back to samples when empty. | Each tab is driven by real data: issues raised against the risk, vulnerabilities reached through linked assets, threats via threat assessments, score history, the spatie activity log (old → new values) and the linked FAIR scenario's latest run. Empty states are honest. |
| R4 | No way to link a threat to a risk from the risk itself. | An inline "Link threat" form (likelihood × impact plus analysis) posts to the existing threat-assessment endpoint. |
| R5 | There was no Delete/Archive button, and none of these existed on the detail page: New Assessment, a permission check before showing actions. | Archive (with a confirmation modal), New Assessment and Add Treatment, each shown only with the right permission. |
| R6 | Score changes from create/edit were never written to score history. Clearing likelihood or impact left a stale score. | History is recorded with an optional "reason for change". Clearing either value clears the score. |
| R7 | Generated codes were `RSK-0041`, while the tenant uses `KHB-RSK-040`. | The tenant prefix and padding are kept, and soft-deleted codes are never reused. |
| R8 | The Edit form had no fields for source, appetite, treatment strategy, treatment due date or review date. Create had no visible errors on its selects. | All fields added, with per-field errors and an error summary banner. |

## 4. Risk Assessments

| # | Finding | Fix |
|---|---|---|
| A1 | Editing or deleting an assessment never changed the risk's score. | Revising the latest assessment re-scores the risk. Deleting it reverts the risk to the previous assessment, and both are logged in history. |
| A2 | The Edit form dropped the five impact dimensions; Create had no next-review-date field. | Both added. |
| A3 | `fair_tef` had no upper bound but the column is `decimal(8,4)`. | Capped at 9999. |
| A4 | Seeded "residual" assessments carried inherent scores (e.g. residual 25 on a risk whose residual is 12). | The seeder uses the right basis. The 5 affected dev rows were corrected. |
| A5 | No filters; raw dates. | Method, type and search filters added; dates formatted. |

## 5. Risk Treatments

| # | Finding | Fix |
|---|---|---|
| T1 | Treatment statuses were rendered with the *risk* status badge, so every plan showed "Identified". | A dedicated treatment status badge. |
| T2 | Approving through Edit didn't record the approver. Re-completing reset `completed_at`, and re-opening didn't clear it. | One `transition()` handles side effects for Edit and the quick status control alike. |
| T3 | Completing a plan had no effect on the risk. | Completion sets the risk's residual to the plan's target score (logged in history). When no open plans remain, the risk becomes **Mitigated**. |
| T4 | "Overdue" was only ever a manual status. | Derived `is_overdue` (past due and still open), an overdue filter and KPI tiles. |
| T5 | New plans didn't default to the risk's owner and strategy. | Prefilled from the risk. |

## 6. Threat Register

| # | Finding | Fix |
|---|---|---|
| H1 | Validation allowed 4 generic categories and 3 sources, but all 130 seeded threats use the bank threat-intel taxonomy (fraud, cloud, insider, supply-chain… and ngCERT, NITDA, MITRE ATT&CK, IBM X-Force). **No seeded threat could be saved, and the filters matched nothing.** | The seeded taxonomy is canonical, with proper labels (ngCERT, MITRE ATT&CK…). |
| H2 | The `recommendations` field was validated but missing from the form; "medium" severity rendered grey. | Added; colour fixed. |
| H3 | No seeded threat was linked to any risk, so the Assessments column read 0 everywhere. | `DemoCrossLinkSeeder` links threats to risks by taxonomy. Dev DB: 79 links. |

## 7. FAIR Quantification

| # | Finding | Fix |
|---|---|---|
| F1 | "Run Monte Carlo" stored `rand()` numbers. P95 and P99 were fixed multiples of a random mean. | `FairMonteCarloService`: each simulated year draws a loss-event rate from a triangular distribution, a Poisson number of events and triangular magnitudes net of control effectiveness. Mean, median, P95, P99 and the histogram come from the distribution. 10,000 iterations take about 10 ms, and runs are reproducible with a seed. |
| F2 | `FairScenario` wasn't tenant-scoped, so any bank could see and run another bank's scenarios. `fair.run` (a write) needed only `view platform`. | `BelongsToOrganization`, and `edit risks` is required to run or link. |
| F3 | Cards showed the oldest run. | Latest first, with the change versus the previous run. |
| F4 | Scenarios weren't linked to risks, so the register ALE/SLE were static seed numbers. | Scenarios can be linked to a risk. Each run updates that risk's ALE and SLE, and the FAIR tab shows the real distribution. |
| F5 | The GHS currency symbol was single-quoted (`'GH\u{20B5}'`) and rendered literally. | Fixed. |

## 8. Risk Graph and Question Library

| # | Finding | Fix |
|---|---|---|
| G1 | The graph hung up to 8 unrelated records of each type off a fake "Risk Universe" node. Controls and assets came from `DB::table()`, bypassing tenancy. | Layered graph of the top N open risks built from real links: threat → risk → control/asset ← open vulnerability. It has filters, and nodes click through. All queries are tenant-scoped. |
| Q1 | Question Library was a static empty state; the table existed with no model. | Full CRUD (modal form, multiple-choice options, weight, active/retired), filters and module tiles. 16 seeded questions per bank (risk, CBN/NDPA compliance, vendor due diligence, ISMS). The routes need create, edit or delete `risks` permission. |

---

## 9. Data changes

* **Migration** `2027_01_10_000001_normalise_risk_register_values`. It maps legacy statuses and sources and recomputes every stored rating on the single scale. It is driver-agnostic.
* **Seeders** (fresh installs):
  * ratings come from `Risk::calculateRating`
  * appetite is derived from residual exposure
  * treatments follow the risk's strategy and owner
  * FAIR scenarios have calibrated ranges and real simulations
  * `DemoCrossLinkSeeder` links threats, issues and FAIR scenarios to risks
  * `QuestionLibrarySeeder` is new
* **Dev database (applied in place, no reseed):**
  * the migration
  * the risk-context links
  * the 5 corrected seeded assessments
  * calibrated FAIR ranges
  * the question library seed

  Pre-existing demo risks keep their original random appetite values until the next `migrate:fresh --seed`.

## 10. Verification

* Browser: every flow above exercised on org 2, including:
  * create → edit (score change with reason) → link threat → assess → delete assessment (revert) → treatment create → approve → complete (risk becomes Mitigated at the target) → FAIR run (risk ALE updated) → archive
  * dashboards re-checked after each change
* `tests/Feature/Risk/RiskModuleTest.php`: 14 tests, 104 assertions.
* Full PHP suite, `migrate:fresh --seed` (SQLite) and `npm run build`: see the hand-off notes.

## 11. Open items (outside this module)

* `ReportController` counts the treatment status `not_started`, which doesn't exist.
* The project lives in iCloud-synced *Documents*; macOS had offloaded `vendor/`, `node_modules/` and even `routes/web.php`, which made `artisan` hang. Keep the folder out of iCloud ("Keep Downloaded", or move it out of Documents).
