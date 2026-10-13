# ATH-VAL-008 — Vendor Management: End-to-End Validation Report

**Module:** Vendor Management (sidebar group 8 of the platform validation programme). It covers:
- Vendor Register (`vendors.*`)
- Vendor Assessments (`vendor-assessments.*`)
- Security Ratings (`security-ratings.*`)
- Shared Vendor Directory (`shared-vendors.*`)

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- Every page, form and action was exercised in the in-app browser:
  - as the Kano CISO (Organization Admin);
  - as Kano's Vendor Risk Manager (`tprm@kanoheritage.ng`, Risk Manager role);
  - as the First Bank admin, for isolation.
- Controllers, models, seeders and the consumers of vendor data were traced: the executive dashboard, the Returns Centre vendor figures, the Document Intelligence DDQ intake, and EA vendor concentration. Findings were fixed and re-tested.
- Locked in by `tests/Feature/Vendors/VendorManagementModuleTest.php` (10 tests, 160 assertions).

**Intended capability** (implementation plan):
- **D8 tiering (Phase 2):** a vendor's tier and its review cadence.
- **D8.3 continuous monitoring (Phase 4):** SecurityScorecard/Bitsight ratings refreshed on a schedule, and a breach-news watch.
- **Shared Nigerian Vendor Directory:** platform-maintained, with a baseline DDQ.
- **TPRM Vendor Card:** rating over time, breach feed, baseline DDQ from the directory, tenant tier and review cadence.

**What the module did before this pass:**
- **Register and assessments:** basic CRUD.
- **Security Ratings:** a `PlatformController` stub. All 5 ratings and 6 breach headlines pointed at vendor ids that no longer existed, so every vendor of both banks showed "—". The same headlines appeared for every bank, the Category column read fields that do not exist, and dates were raw ISO strings.
- **Shared Directory:** a read-only card list.
- **Access:** the role each bank's Vendor Risk Manager holds (Risk Manager) had no vendor permission, so the person who runs TPRM could not open the module.

The two stubs now live in `app/Http/Controllers/Vendors/`: `SecurityRatingController` and `SharedDirectoryController`. They are backed by three services in `app/Services/Vendors/`:
- `VendorTiering`: tier, review cadence, assessment completion
- `SecurityRatingService`: sandbox provider
- `VendorMetrics`: shared by the register, Security Ratings and the executive dashboard

Thresholds and cadences are in `config/tprm.php`.

| Area | Before → after |
|---|---|
| Vendor register | **Before:**<ul><li>Any `?sort=` went into `ORDER BY`.</li><li>New codes were `VND-0001`, outside the bank's `KHB-VEN-…` series.</li><li>No tier, no owner, no regulatory facts.</li><li>US-format dates.</li></ul>**After:**<ul><li>Whitelisted, clickable sort; per-bank codes (`KHB-VEN-036`; `REF-…` pack codes are skipped).</li><li>Tier, rating and owner columns.</li><li>Hygiene tiles that filter: tiers, reviews overdue, contracts ending ≤ 90 days, processors without a DPA, material outsourcing not notified to the CBN, no owner.</li><li>Terminated relationships hidden unless asked for.</li></ul> |
| Vendor card | **Before:** details and an assessments tab.<br>**After:**<ul><li>Tier with "why this tier" points.</li><li>Review cadence and next review, with an overdue flag.</li><li>Security grade with a trend sparkline.</li><li>Regulatory facts: NDPA processor and DPA date, CBN material outsourcing and notification date, Shared Directory entry with baseline DDQ.</li><li>Applications supplied, from the EA repository.</li><li>Assessments with residual rating, breach watch, and a "needs attention" summary.</li></ul> |
| Vendor form | **Before:** the edit form showed contract dates blank (ISO strings in date inputs); the create form had no status field.<br>**After:** one shared form with relationship owner, directory link, tiering inputs (data access, rating domain, processor and DPA, material outsourcing and CBN notification), contract and SLA |
| Assessments | **Before:**<ul><li>`vendor_id` was `exists:vendors,id`, so an assessment could be filed against another bank's vendor.</li><li>Completing an assessment changed nothing else.</li></ul>**After:**<ul><li>Tenant-checked vendor.</li><li>"Completed" needs a score and findings.</li><li>A "Complete assessment" action records `completed_at` and the residual rating the score implies, and resets the vendor's review clock: last assessed, plus next review one tier cadence later.</li></ul> |
| Security Ratings | **Before:** cross-tenant and orphaned.<br>**After:**<ul><li>Per-bank ratings with grade, change and 6-month trend.</li><li>"Refresh ratings" runs the sandbox provider.</li><li>KPIs: rated vendors, D/F grades, drops ≥ 10 points in 30 days, events to triage, open assessments.</li><li>A breach watch to triage: Reassess (opens an incident-driven assessment), Acknowledge, or Dismiss with a reason.</li></ul> |
| Shared Directory | **Before:** static cards.<br>**After:** each entry shows which of the bank's own vendors are linked to it. "Add to my register" pre-fills a new vendor from the entry; "Link one of my vendors" ties an existing record. Bank data is never written to the directory |

## 1. Security and tenancy

* **Ratings and breach events.** `tprm_security_ratings` and `tprm_breach_events` had no `organization_id`.
  * Both now carry one, backfilled from the vendor; rows whose vendor no longer exists are removed.
  * Both models are strict-scoped (`BelongsToOrganization`).
  * The platform seeder no longer writes them. It used to create them without an organisation, against placeholder vendors that the bank seeders later replace.
* **EA edges.** `EaWedgeDemoSeeder` wrote First Bank's 33 integrator edges (CWG Plc → applications) without an organisation.
  * Under the EA tenant scope, NULL means platform-shared, so these edges sat in Kano's graph, impact analysis and ArchiMate export.
  * They now take their vendor's organisation (migration `repairVendorEdges()`), and the seeder writes it.
  * EA Vendor Concentration for First Bank is unchanged.
* **Pickers.**
  * The assessment's vendor, the vendor's relationship owner, and the directory-link vendor are tenant-checked.
  * The rating domain is validated as a bare domain.
* **Gates:**
  * Security Ratings and the Shared Directory move from `view platform` to `view vendors`.
  * Refresh, triage and directory linking need `edit vendors`.
  * Completing an assessment needs `edit vendor-assessments`.
* **Access** (migration `2027_01_17_000001` and `RolesAndPermissionsSeeder`):
  * **Risk Manager** (each bank's Vendor Risk Manager) gains view, create and edit on vendors and on assessments.
  * **Compliance Officer** (the DPCO, who needs the NDPA processor register) gains view on both.
* **Isolation check:** as the First Bank admin, Kano's vendor is a 404. Security Ratings shows First Bank's 50 rated vendors and its own breach events only.

## 2. Tiering and review cadence

* **Tier is computed, not typed** (`VendorTiering`). Points:

  | Fact | Points |
  |---|---|
  | Material outsourcing (CBN outsourcing guidelines) | 3 |
  | Full access to bank data | 2 |
  | Limited access to bank data | 1 |
  | Processes personal data (NDPA 2023) | 2 |
  | Supplies a critical application in the EA repository | 2 |
  | Supplies a high-criticality application | 1 |
  | Rated critical by the bank | 2 |
  | Rated high by the bank | 1 |
  | Few in-market alternatives (`substitutability_score ≤ 2`) | 1 |

  * 6 points or more is Tier 1; 3 or more is Tier 2; the rest are Tier 3 (configurable).
  * The vendor card lists the reasons.
  * The tier is recomputed on every save.
* **Cadence:** Tier 1 every 12 months, Tier 2 every 24, Tier 3 every 36 (`tprm.review_months`).
  * A vendor never assessed is due its onboarding assessment within 30 days, not a full cycle out. During the walk, a new Tier 1 vendor was first given a review date a year away; that is fixed.
  * Completing an assessment moves the clock forward only, so a late-closed older assessment does not rewind it. The assessment's own next-review date matches the vendor's; during the walk they disagreed, and that is fixed.
* **Residual rating** from the score: 80+ low, 65+ medium, 50+ high, below 50 critical.
* **Regulatory gaps surfaced:**
  * processors without a signed DPA (NDPA 2023 s.29);
  * material outsourcing not notified to the CBN;
  * contracts ended while the vendor is still active;
  * vendors with no relationship owner.

## 3. Continuous monitoring

* **Sandbox ratings.** No bank has supplied SecurityScorecard or Bitsight credentials, so `SecurityRatingService` derives each vendor's score deterministically from its domain (a 70–96 base with ±6 monthly drift), and the page says so.
  * Every capture stores grade and delta, once per vendor per day, so trends, grade changes and drop alerts behave as they would on live data.
  * Vendors without a domain are skipped and counted.
* **Breach watch.** Events carry severity, source, a triage state (new → acknowledged / dismissed / reassessment) and the reviewer.
  * "Reassess vendor" opens an incident-driven assessment linked to the event, and the assessment page shows the event that triggered it.
  * Dismissing needs a reason. A triaged event cannot be triaged again.
* **Demo feed:** seeded events are labelled "Simulated alert" with source "demo feed", so no real company is described as breached.

## 4. Dashboards and cross-module feeds

* **Executive dashboard.** "Top 10 Vendor Ratings" read ratings with no tenant filter and showed "—" for every vendor. It is now a **Third-Party Risk** card from `VendorMetrics`:
  * Tier 1 count, reviews overdue, missing DPAs, contracts ending ≤ 90 days, D/F ratings, and breach alerts, each linking to the filtered list;
  * the lowest-rated Tier 1–2 vendors, linking to their cards.

  The "Vendors" headline count excludes terminated relationships.
* **Returns Centre.** `vendors_assessed_12m` reads `last_assessed`, which is now maintained by assessment completion, as is the vendor's next review.
* **Document Intelligence DDQ intake** (module 4) still sets `last_assessed` on acceptance; it is unchanged.
* **EA** reads `vendor_id` on applications for tiering ("supplies a critical application"). The vendor card lists those applications.

## 5. Demo data

`VendorManagementDemoSeeder` runs after `AssetManagementDemoSeeder` and before Regulatory Intelligence, so the vendor return figures are final. It is deterministic per bank:
- **Facts by category:** NDPA processor and CBN material-outsourcing facts from category and data access, with DPA and CBN-notification dates.
- **Owners:** the Vendor Risk Manager for the TPRM estate, IT operations for infrastructure vendors.
- **Rating domains** from websites (First Bank's vendors had none, so the bank seeders' slug pattern is used).
- **Directory links** by name: 8 for Kano, 10 for First Bank.
- **Deliberate gaps:**
  - two processors without a DPA;
  - one material arrangement not notified;
  - two unowned vendors;
  - Tier 1 vendors past their review date.
- **Assessments and ratings:**
  - Completed assessments gain completion dates and residual ratings, and the review clock runs from them.
  - Six months of sandbox ratings (Kano 210 captures, First Bank 300), with one sharp drop per bank.
  - Five simulated breach events per bank, one in each triage state, one of which opened an incident-driven reassessment.

## 6. Verification

* **Browser (dev PostgreSQL):**
  * **As the Kano CISO:**
    * The register shows 35 relationships: Tier 1/2/3 = 10/16/9, 2 processors without a DPA, 1 material arrangement not notified, 2 unowned.
    * Infosys Finacle's card: 8 points → Tier 1 (material outsourcing, limited data access, processor, rated critical), reassessment overdue.
    * Completing its in-progress DDQ (score 84) recorded "residual risk low" and moved its next review to 13 Sep 2027.
    * Security Ratings: "Reassess vendor" on the NIBSS CVE alert opened an incident-driven reassessment. "Refresh ratings" captured 35 and skipped none.
    * The Shared Directory shows all 8 entries linked to Kano's register.
  * **As Kano's Vendor Risk Manager:** opened the module (previously no access) and registered "Paystack Payments Ltd" (`KHB-VEN-036`).
    * A URL as the rating domain was refused with guidance.
    * With full data access, processor and material outsourcing ticked, it was created as Tier 1, with three "needs attention" items: no DPA, CBN not notified, no owner.
    * The test vendor was then deleted.
  * **As the First Bank admin:** isolation as in §1; EA Vendor Concentration still renders.
* **Fixed during the walk:**
  * a review due today showed as overdue (date compared with the current time);
  * new vendors were given a 12-month first review;
  * assessment and vendor next-review dates disagreed after completion;
  * the sandbox score range produced too many F grades to be realistic;
  * a re-run of the seeder duplicated the incident reassessment;
  * on a fresh seed, the platform seeder's NULL-organisation ratings and events survived.
* **Automated:**
  * `VendorManagementModuleTest` (10 tests, 160 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test
  * `npm run build`
  * the Playwright TPRM spec, updated to the new heading
* **Dev PostgreSQL:** the migration was applied (it backfilled and pruned the monitoring tables, re-scoped the 33 EA edges and granted the roles) and `VendorManagementDemoSeeder` run in place.

## 7. Not in this pass

* **Live rating providers:** SecurityScorecard and Bitsight clients, credential settings, a scheduled refresh, and a real breach-news feed (HaveIBeenPwned, RSS). The service, capture model and triage flow are what they would plug into.
* **DDQ content:** assessments record a score and findings, not a questionnaire. The Shared Directory's "baseline DDQ" is a version label; there is no question set behind it yet. The Workflow marketplace has a "Vendor DDQ" template that could supply one.
* **Directory administration:** entries are seeded; there is no Super Admin editor.
* **Fourth parties and exit plans:** sub-processors, concentration of fourth parties, and exit and termination plans for material arrangements (CBN guidelines) are not modelled.
* **Notifications:** no reminders before a review or contract end date.
* **KRIs:** `KRI-TPR-BREACH` and CCM test `CCM-TPR-RATE-018` exist in the platform seeder but do not read these tables yet. That belongs to the KRIs & Dashboards pass.
