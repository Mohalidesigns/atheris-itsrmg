# ATH-FIN-003 — IT Financial Management: Build Update

Builds [ATH-FIN-001](ATH-FIN-001_IT_Expense_and_Budget_Management_Module_Specification_v1.0.md) per [ATH-FIN-002](ATH-FIN-002_IT_Finance_Build_Orchestration_Plan.md). One section per phase.

---

## Phase P0 — Foundation (2026-10-15, branch `feature/it-finance-p0`)

Plan: [docs/build/ATH-FIN-PLAN-p0.md](build/ATH-FIN-PLAN-p0.md). Spec §3.1, §4.1, §8, §9 (settings routes), §10 (navigation group, Settings page).

### Shipped
- **Schema:** one migration with 11 `fin_*` configuration tables:
  - settings, fiscal years and periods
  - cost centres, categories (TBM), GL accounts
  - currencies, FX rates, tax types
  - drivers and driver values
- **Permissions migration:** grants the module's permissions on existing databases. Every table has `organization_id`, and every model uses the strict `BelongsToOrganization` scope and logs activity under `it-finance`.
- **Models:** 12 in `app/Models/Finance`. `FinancePolicy` is mapped to every finance model.
- **Services** (`app/Services/Finance`):
  - `FinanceSettings`: lazy per-bank defaults and DoA band validation.
  - `TbmTaxonomy`: 49 system rows per bank.
  - `FiscalCalendarService`: 12-month, 12 + adjustment and 4-4-5 schemes; close and lock in sequence.
  - `CurrencyService`: bcmath conversion with inverse and base-currency triangulation.
  - `FinanceCsvImporter`: cost centres and FX rates.
- **Config:** `config/finance.php` holds every regulatory or default value: Nigeria tax defaults, DoA bands, tolerances.
- **Routes:** 28 `finance.*` routes under `/it-finance`; one page (`finance.settings`), the rest are writes. Each is gated with `permission:` middleware and `Gate::authorize`.
- **Roles:** `it-finance` permissions plus 7 extras. Six new roles: IT Budget Owner, IT Finance Controller, Procurement Officer, Investment Sponsor, IT Steering Committee, Finance Approver.
- **UI:**
  - A sidebar group "IT Financial Management" (Settings only; later phases add their items).
  - `Finance/Settings.jsx` with 11 tabs: General, Fiscal calendar, Cost centres, Categories, GL accounts, Currencies, Taxes, Drivers, DoA bands, Variance, Renewal policy.
- **Demo data** (`FinanceFoundationSeeder`, after `ChangeManagementDemoSeeder`, guarded per bank). Both banks:
  - FY2026 (Jan–Sep closed, H1 locked) and FY2027 as the planning year.
  - Kano 10 and First Bank 12 cost centres. First Bank's sit across its legal entities, and one per bank has no owner, on purpose.
  - FX rates, drivers, custom categories.
  - finance@, procurement@, cfo@ and cio@ users.

### Tests
- New classes: `FinanceTenancyAndRoutesTest` (6), `FinanceConfigurationTest` (21, FIN-01.01–.12 plus review regressions), `FinancePermissionsTest` (7), and a SMOKE-gated `RouteSmokeTest::test_finance_pages_render_for_every_demo_tenant`.
- Final full suite on PostgreSQL after the review fixes: 394 tests, 4,636 assertions, OK. 2 skipped: the opt-in smoke tests, run separately. An earlier run hit a temporary disk-full on the host (17 false EA errors), and all 52 affected EA tests passed on re-run.
- `migrate:fresh --seed` on scratch: clean.
- `npm run build`: passes.
- SMOKE: 205 GET routes plus all finance tabs for 3 tenants, plus the seeded-estate SoD check, 451 assertions, pass.

### Platform fix shipped with P0
`RolesAndPermissionsSeeder` made about 230 separate `givePermissionTo()` calls. Each one flushes Spatie's permission cache, and the next call rebuilds it, so the seeder took 15.4 s in every test that seeds roles. Grants are now batched per role: 1.6 s, the same grants. The full PHP suite dropped from over an hour to 10 min 42 s.

### Review fixes applied
Security review: no Critical findings. Domain review: conditional go. Fixed in P0:
- Driver values and FX rates refused inside non-open or locked periods (security H1).
- Plain-decimal validation before bcmath, so "1e5" no longer gives a 500 (S2).
- CSV import is all-or-nothing, the row cap is checked before apply, reported errors are capped and lookups are cached (S3).
- The cost-centre cycle check casts `parent_id` to int (S4).
- The adjustment period is flagged `is_adjustment` and excluded from date lookups, which are now deterministic (S5 / D2).
- Period close and lock run in a transaction with a row lock, lock in sequence, and P1 cannot close while the prior year has open periods (S6).
- TBM towers no longer default to CapEx or GL 1810; only cost pools carry those defaults (D4).
- FX inverse and cross rates are computed at scale 18 and rounded once; amounts round to the currency's decimals (D7 / D9).
- No demo user holds both IT Budget Owner and Finance Approver (D13). The orchestrator's seeded-DB check caught a remaining conflict, the Kano admin, which is now fixed. `RouteSmokeTest` asserts this against the seeded estate.
- 4-4-5 year end was one day too long (D1, found by QA).

### Deferred, with the phase that owns each
- **P1:**
  - DoA bands get versions with `effective_from`, a band-key snapshot on each approval request, maker-checker on band edits, and a dedicated audit event (S7 / D5 / C8).
  - TBM cost pool × tower on every budget line (D3).
  - FX `rate_type` (spot/average/closing/budget) and `rate_date` returned from conversion (D8).
  - A base-currency change guard once money rows exist (S8 / C13).
  - Per-bank DoA bands for demo realism (D16).
- **P3:**
  - The Nigeria tax regime (D6, C15): Withholding Tax Regulations 2024 / Nigeria Tax Act 2025. Tax types get `effective_from/to` and `recoverable_pct`, plus non-resident WHT and VAT reverse charge. Unverified; needs confirmation by tax counsel.
  - Match tolerance semantics and cap (D12).
  - A fuller chart of accounts for IAS 16/38 and IFRS 16 (D14).
  - CSV formula-injection neutralisation on export (S11).
- **P4:** a guard against closing future-dated periods and fiscal-year contiguity (D10); period reopen and the close checklist (C7).
- **Logged (Medium/Low, not scheduled):**
  - Settings props shown without `view it-finance-amounts`, and no cost-centre filter on Settings (S9).
  - The `import it-finance` permission does nothing; imports require manage-settings (S10).
  - Mass deletes not in the audit log (S13).
  - `$guarded = []` on models (S15).
  - Unbounded FX load on Settings (S16).
  - Cost-centre delegates (D15).
  - TBM v5 labels (D17).
  - The `ensureDefaults` race (D20).
  - DoA label wording, "up to" versus exclusive max (D11).

### Decisions
| # | decision | reason |
|---|---|---|
| P0-1 | Accept architect conflicts C1–C21 as recommended in the P0 plan | Spec/codebase gaps (global roles, missing settings columns, Settings-only nav, per-bank TBM copies, etc.) |
| P0-2 | Gate on PostgreSQL only; QA and fixers on a dedicated `it_security_risk_fin_test` | SQLite hides bugs; concurrent suites on one DB wipe each other |
| P0-3 | P0 reviewers on Sonnet ("be exhaustive"); Opus reserved for P1/P2/P3/P7 | Token economy (ATH-FIN-HANDOVER) |

### Review verdicts
- Security (fin-security-reviewer): GO after H1, S2 and S3. All three are fixed.
- Domain (fin-domain-reviewer): CONDITIONAL GO. D1, D2 and D4 are fixed. D3 and D5 are P1 design items.

---

## Phase P1 — Budget planning & allocation (2026-10-15, branch `feature/it-finance-p1`)

Plan: [docs/build/ATH-FIN-PLAN-p1.md](build/ATH-FIN-PLAN-p1.md). Spec §3.2, §4.2, §5.1, §6 (three budget processes), §7 (approval notifications), §10 (Budgets pages). It also covers the five items P0 deferred to P1.

### Shipped
- **Schema:** 4 migrations, all safe on both Postgres and SQLite.
  - Budgeting: cycles, targets, budgets/versions, lines, line periods, scenarios, comments, the adjustments ledger, transfers, supplementary requests.
  - Allocation tables. Execution is P6.
  - DoA band versions, `fin_notification_logs` and `approval_requests.context`.
  - `rate_type` on `fin_fx_rates`.
- **Models:** 14 new in `app/Models/Finance`.
- **Services:** BudgetService (lifecycle, versions, diff, incremental), BudgetPhasing, BudgetLedger, BudgetWorkbook (CSV), BudgetCycleService, BudgetTransferService, SupplementaryBudgetService, DoaBandService, FinanceApprovals (a wrapper around `ApprovalEngine` that stores a band snapshot and enforces SoD), FinancePolicySeeder, FinanceApprovalNotifier and FinanceApprovalSweeper.
- **P0-deferred items, all delivered:**
  - Versioned DoA bands with `effective_from`, maker-checker, a dedicated audit event and a band snapshot on every approval request. Stage conditions are re-mapped when a version activates.
  - TBM `cost_pool_id` and `tower_id` required on every budget line.
  - FX `rate_type` (spot/average/closing/budget) and `rate_date` returned from conversion.
  - A base-currency change guard once money rows exist.
  - Per-bank DoA bands.
- **Workflow Studio:** 3 processes: `finance.budget_approval`, `finance.budget_transfer`, `finance.supplementary_budget`. `ApprovalProcesses::conditions($process, $orgId)` gives each bank its own `amount_band` values. Default stages are seeded on first open (`ensureDefaults`). The transfer default escalates to the CIO from the high-value band.
- **Notifications:** 3 FinanceApproval* notifications, delivered through an auto-discovered listener.
- **Scheduled commands:** `fin:approvals-sweep` (hourly, deduplicated via `fin_notification_logs`) and `fin:doa-activate` (daily at 00:05).
- **Routes:** 72 new `finance.*` routes (83 with P0). Each has `view it-finance` plus the right write permission.
- **UI:**
  - 8 pages under `Finance/Budgets`: Index, Create, Show, Workbook, Cycles, Targets, Transfers, Supplementary.
  - About 20 components: the workbook grid (keyboard navigation, TSV paste), the version diff, the ceiling bar and the band snapshot.
  - A "Budgets" nav entry with a tab bar.
  - Settings: DoA versions editor and FX rate types.
- **Amount masking:** server-side, through `renderMasked`. Users without `view it-finance-amounts` get null amounts, bands and deltas in props, in the diff JSON and in the CSV export, and CSV import is refused to them.
- **Demo data (`FinanceDemoSeeder`), both banks:**
  - FY baselines: Kano 8 budgets with 59 lines; First Bank 10 with 74 lines across its legal entities, in NGN, GBP and GHS. Plus a mid-year v2 revision.
  - FY+1 drafts in every state, including a returned draft with line comments and an unowned draft.
  - Transfers in every state. Applied ones net to zero, and one crosses cost centres.
  - Supplementary requests, scenarios and a breached ceiling.
  - Per-bank DoA bands: v1 superseded, v2 active, and a pending v3 at Kano.
  - New users md@ and headofit@.

### Tests
- New:
  - `BudgetLifecycleTest`, `BudgetWorkbookTest`, `BudgetTransferTest`, `FinanceDoaFxAndCurrencyGuardTest`
  - `BudgetBackendSmokeTest`, plus `FinanceWorkflowStudioTest` (extended to 15 tests)
  - a seeded-estate check in `RouteSmokeTest`
  - `--filter Finance`: 96 tests, all pass.
- Every FIN-02 M item maps to a test; the table is in plan §8 and the QA report.
- `RouteSmokeTest` (SMOKE=1, scratch): 3 tests, 1,087 assertions, all pass.
- `migrate:fresh --seed`: clean.
- `npm run build`: passes.
- Pint: clean.
- Full suite on PostgreSQL after the review fixes: 452 tests, 5,790 assertions, OK. 3 skipped are opt-in tests: the smoke tests, which run separately. The first gate run collided with a reviewer running tests on the same DB and gave 42 false errors (missing tables, deadlocks).

### Review fixes applied
Both reviews were run on Opus. The first verdict from each was no-go.
- **SoD bypass on zero-step requests (security H1, domain H2).** A configured workflow with no applicable stage was auto-approved, so the owner could approve their own budget, transfer or supplementary request. `FinanceApprovals::assertZeroStepSoD` now applies the default-approver rule and also excludes `created_by_id`. The shared `ApprovalEngine` is untouched.
- **DoA re-map (domain H1).** A band version that adds bands now extends "from X upward" stages to the new top bands.
- **Adjustments across re-baselines (domain H3, security M4).** Transfer and supplementary `apply` now re-resolve their lines to the current baseline through lineage, under a lock. Transfers also re-check that their periods are still open. `baseline()` carries forward adjustments posted during a revision.
- **Incremental mode (domain H4).** The increment now scales the current amount (original + adjustments), not the original.
- **Virement DoA escalation (domain H5).** A default transfer stage goes to the CIO from `transfer_cio_from` (₦50m), conditioned on `amount_band`.
- **Masking leaks (security M1, M2).** Diff deltas, amount band and label, band min/max and `driver_rate` are now masked.
- **Orchestrator fixes:**
  - IT Budget Owner granted `view it-finance-amounts`; owners can't plan blind.
  - The seeder's transfer approvals now include the CIO.

### Deferred / logged (Medium/Low, not fixed)
- **Before P3** (P3 builds on the current-budget figure):
  - Supplementary FX re-quote and the ledger rate for line top-ups (domain M1).
  - The diff only follows lineage one version back; the diff, Index KPI and by-period totals use original rather than current amounts (domain M3).
  - `Budget::LOCKED_WRITABLE` allows any status change on a locked baseline (domain M4).
- **Fold into P7 hardening:**
  - Bank policy in app config rather than per-tenant settings: thresholds, `allow_capex_opex`, seasonal weights (domain M5).
  - Postgres-only `ilike` in `FinanceDemoSeeder` (domain M5).
  - Cross-legal-entity virements not refused or escalated (domain M6).
  - No FX staleness limit; mixed planning rates within one version (domain M7).
  - `FinanceNotificationsTest` missing (domain M8).
  - The targets list and transfer/supplementary summaries are not filtered by cost-centre visibility (security M3).
  - SoD keyed only on submitter and owner: a preparer can reassign the owner (security M5).
- **Low (domain L1–L8, security L1–L6):**
  - The base-currency guard ignores targets and DoA bands.
  - Remainder-to-last-period phasing can go negative.
  - The CSV formula guard misses tab/CR and code columns.
  - Notifications mail amounts to users without amount rights.
  - The DoA activation and sweep loops have no per-tenant try/catch.
  - A return is stored as an engine rejection.
  - No SoD on baseline or apply.
  - Some N+1 queries on the index pages.
  - Banks already seeded keep the two-stage transfer workflow, because `ensureDefaults` skips existing workflows.
- **Spec scope moved:**
  - Allocation execution (FIN-02.12) goes to P6 (C27).
  - From-actuals mode and the prior-year actual column go to P3 (C26).
  - XLSX is not supported (C25); the workbook is CSV only.
- **Platform defect, logged:** EA/Core listeners are registered twice, once by auto-discovery and once in `AppServiceProvider` (C35).

### Decisions
| # | decision | reason |
|---|---|---|
| P1-1 | Accept architect conflicts C22–C48 as recommended in the P1 plan. Key ones: baseline + signed adjustments ledger (C22), per-bank condition values via `ApprovalProcesses::conditions` (C28), band snapshot in `approval_requests.context` (C31) | Keep baselines immutable while allowing virements; per-bank DoA without engine changes |
| P1-2 | Workbook is CSV only; XLSX returns 422 (no `openspout` dependency) | No new composer dependency without the owner's sign-off; revisit at P7 |
| P1-3 | Accept the C33 demo workaround: a dedicated headofit@ approver with no cost centres. Delegate approvers are still open | The engine has no delegate/skip rule yet |
| P1-4 | CapEx↔OpEx virements are refused by default | Accounting-treatment control; needs an explicit setting to allow |
| P1-5 | IT Budget Owner gets `view it-finance-amounts` | Owners prepare the amounts; masking is for read-only viewers |
| P1-6 | The SoD fix lives in the finance wrapper, not in `ApprovalEngine` | Avoids changing behaviour in the other modules on the shared engine. The engine-level zero-step auto-approve is logged for a platform fix |

### Review verdicts
- Security (Opus): NO-GO on H1. H1 is fixed, plus M1, M2 and M4, so the branch is GO.
- Domain (Opus): NO-GO on H1–H5. All five are fixed with regression tests, which clears the no-go. M1–M4 must land before P3.

## Phase P2 — Contracts, subscriptions, renewals & calendar (2026-10-16, branch `feature/it-finance-p2`)

Plan: [docs/build/ATH-FIN-PLAN-p2.md](build/ATH-FIN-PLAN-p2.md), work packages WP-A to WP-E. Spec FIN-06 (contracts, renewals, T-minus notifications) and FIN-07 (unified finance calendar). The phase also lands the P1 domain-review items M1, M3 and M4, which had to land before P3, plus D5.

### Shipped
- **Schema:** 3 migrations, `2027_02_10_000001` to `000003`.
  - Contracts, team, links, documents, events, renewals and calendar feeds.
  - An idempotency key on `fin_notification_logs`, with a unique index.
  - The `approve it-finance-contracts` grant on existing databases.
- **Models:** `Contract`, `ContractTeamMember`, `ContractLink`, `ContractDocument`, `ContractEvent` (immutable), `Renewal`, `CalendarFeed`.
- **Services:**
  - ContractService, RenewalService (open → recommend → submit → approve/reject → execute), ContractStatusDeriver (pure).
  - `FinanceClock`, a testable "today".
  - ContractSweeper (roll, derive, T-minus, escalation, passed) and FinanceCalendarService (events, timeline, passed, ICS, CSV and PDF).
  - FinanceDigest.
- **Workflow Studio:** a fourth finance process, `finance.contract_renewal`: Head of IT, then CFO when `requires_cfo` (C49/C50). There are now 15 processes in total.
- **Notifications:** 5 new ones (renewal due, escalation, deadline passed, renewed, daily digest). They carry no amounts.
- **Scheduled commands:**
  - `fin:contracts-sweep`, daily at 06:30 Lagos time.
  - `fin:digest`, hourly, sent at each bank's `digest_hour`.
- **Routes:** 27 `finance.contracts.*`, `finance.renewals.*` and `finance.calendar.*` routes inside auth. Outside auth there is the signed `finance.calendar.ics` feed (C55), versioned per user and rotatable.
- **UI:**
  - Pages: `Finance/Contracts/{Index,Create,Show}`, `Finance/Renewals/{Index,Show}` and `Finance/Calendar` (month, quarter, year, timeline and passed views).
  - About 20 components.
  - Nav entries "Contracts & Renewals" and "Calendar".
  - A digest toggle in Settings › Notifications.
  - Contract picker on budget lines (D5).
  - VersionsDiff shows original, adjustments and current amounts (M3).
- **Demo data, `FinanceContractDemoSeeder`, both banks:**
  - Kano has 31 contracts and First Bank 37, covering all eight statuses.
  - 9 renewals per bank cover submitted, pending CFO, approved, executed and rejected.
  - Seeded past T-minus logs (Q1).
  - Gaps are kept deliberately: an unowned contract, over-licensing, a pending DPA and drafts.

### Tests
- New:
  - `ContractBackendSmokeTest`, `ContractRenewalLifecycleTest` (16), `ContractNotificationSweepTest` (13), `CalendarTest` (12), `ContractWorkflowIntegrationTest` (6), `ContractReviewRegressionTest` (11), `BudgetDomainReviewRegressionTest`.
  - The contract-estate check in `RouteSmokeTest`.
  - The Playwright specs `finance-contract-renewal.spec.js` and `finance-calendar.spec.js`.
- **Gate on PostgreSQL, after the review fixes:**
  - Full suite: 535 tests, 8,044 assertions, OK. The 4 skipped are the opt-in smoke tests. It ran in 40 minutes.
  - `migrate:fresh --seed` on scratch: clean.
  - `SMOKE=1 RouteSmokeTest`: 4 tests, 2,999 assertions, OK.
  - `npm run build`: OK.
  - Pint: clean on the files touched.
  - Playwright `finance-*.spec.js` on chromium-desktop: 4 of 4 pass.
- **The first gate run** gave 46 errors. Most were contract classes, run while the review fixer was editing the same services. One was a real regression: `ReportsIntegrationsSettingsModuleTest` did not send the new `finance_daily_digest` preference.
- **Changed after the full run** (re-run with `ContractReviewRegressionTest|WorkflowStudioModuleTest|FinanceWorkflowStudioTest`, 33 tests OK):
  - The renewal process now declares `view_permission`.
  - The Playwright renewal step follows the row's link by its href.

### Fixes during the build
- **Six forms never submitted.** In Inertia 2.3 `form.transform()` returns `undefined`, so chaining `.put()` or `.post()` onto it threw and no request was sent. The six were five new contract forms and the P0 `RenewalPolicyTab`. The calls are now split into two statements. `Pages/Ea/Surveys.jsx` has the same bug but is outside this module, so it was logged and not fixed then. It is fixed in P2.1.
- **Missing props:** `currencies` added to `Contracts/Show` and `can.edit` to `Renewals/Index`.
- **SupplementaryBudgetService** now calls `FinanceClock::today()` directly.
- **The renewal approval trail returned 403 to everyone but Super Admin.** `WorkflowStudioController::trail` built the view permission from the approve permission, which gave `view it-finance-contracts`, and that permission doesn't exist. Process definitions can now set `view_permission`, and `finance.contract_renewal` uses `view it-finance`. A regression test covers it.

### Review fixes applied
Both reviews were run on Opus. The first verdict from each was no-go.
- **Contract documents leaked through the evidence vault (security H1).** EvidenceController now hides Contract-subject evidence from the vault list, download, review and delete.
- **Renewal window (domain H1).** The window opened on the notice-deadline day with the 90/90 defaults. It now opens at the notice deadline minus the lead days.
- **Recommender could approve (domain H3, security M2).** `recommended_by_id` is now recorded. `sod_excluded_ids` (requester, recommender, opener and owner) is snapshotted into the approval context, and both ApprovalEngine and the default approver path enforce it.
- **Owner swapped mid-approval (security M1, domain M2).** Material contract fields are frozen while a renewal is submitted or approved. Users who can't see amounts can no longer blank money fields through the masked form.
- **Unilateral termination (domain H2, security M3), partial fix.** Termination now refuses the owner, the creator, and the requester or recommender of an open renewal. The date must fall between `commenced_on` (and today minus `terminate_backdate_days`, 30) and `ends_on`, and a reason is required. Routing termination through the full approval workflow is deferred.
- **Reminders went silent after a date edit (domain H4).** The idempotency keys now include the target date. A date change resets `escalated_at` and `last_notified_offset` and records a `dates_changed` event.
- **Execution could re-date the term (security M4, domain M3).** `commenced_on` must equal the approved start, and the proposed start can't overlap the old term.
- **Cost-centre visibility (security M5).** `cost_centre_id` and `budget_line_id` are validated against the user's visible cost centres.
- **Masking (security M6, domain L2).** `value_band`, `uplift_pct`, `uplift_band`, `requires_cfo` and `price_escalation_pct` are masked. The band filter and options are hidden from users who can't see amounts.
- **CSV formula injection (security M7).** The calendar CSV now prefixes `'` on cells starting with `= + - @ \t \r`.
- **Finance missing from T-minus recipients (domain M7).** Added, per FIN-06.04.

### Deferred / logged (not fixed)
- **P2.1, before UAT:** all five items are resolved. See §P2.1 below.
  - ~~Early termination through a full approval workflow (domain H2 remainder).~~ Resolved.
  - ~~An approved but never-executed renewal goes silent (domain M1).~~ Resolved.
  - ~~Terminating an auto-renewing contract after its notice deadline is accepted as effective (domain M4).~~ Resolved.
  - ~~The CFO threshold is a hard-coded NGN config value; it should be per tenant (domain M5).~~ Resolved.
  - ~~Uplift mixes in FX movement (domain M6).~~ Resolved.
- **Low:**
  - Domain L1: `fixed_term` and `evergreen` have no distinct semantics.
  - Domain L3: digest gaps. The passed count ignores lapsed contracts, and the digest tells users about approvals they cannot decide.
  - Domain L4: no IFRS 16 lease indicator. Feed it to P3.
  - Domain L5: documents are not encrypted at rest.
  - Domain L6: the budget-line contract picker ignores visibility and terminal status.
  - Domain L7: single-approver stages can deadlock with no fallback.
  - Security L1: N+1 queries in the sweep and digest.
  - Security L2: uploads keep the client's extension and MIME type.
  - Security L3: ICS polling writes an activity row on every poll.
  - Security L4: an `owner` stage type would let the owner approve.
  - Security L5: contract amounts appear unmasked in the audit trail.
  - `ComplianceController` evidence counts still include contract documents.
- **Environment:**
  - `php artisan serve` drops `DB_*` environment overrides. Serve Playwright runs against a non-default DB with `DB_CONNECTION=… DB_DATABASE=… SESSION_DRIVER=file CACHE_STORE=file php -S 127.0.0.1:8000 -t public`.
  - One QA run hit the dev DB this way. It made logins only, which may have left session and cache rows.

### Decisions
| # | decision | reason |
|---|---|---|
| P2-1 | Accept C49: renewal stages are Head of IT, then CFO, with no owner stage | The owner is the maker; SoD |
| P2-2 | Accept C50: `requires_cfo` is derived from value and uplift | Keeps the DoA in data, not in UI choices |
| P2-3 | Accept C51: Finance Approver gets `approve it-finance-contracts` | The renewal decision sits with Finance |
| P2-4 | Accept C55: signed `.ics` URLs with a per-user version and rotation | Calendar clients cannot send session auth |
| P2-5 | Accept C52–C73 as plan §10 recommends | Architect's recommendations reviewed and accepted |
| P2-6 | Q1 yes: seed past T-minus notification logs | The demo shows the reminder history |
| P2-7 | Keep `approve it-finance-contracts` on IT Finance Controller and enforce exclusions instead (recommender, requester, opener, owner, creator for terminate) | Keeps the role useful at banks without configured stages, while closing the maker/checker gap |
| P2-8 | Contract documents are reachable only through the finance route, never through the evidence vault | Signed contracts carry commercial terms; `view evidence` is too broad |

### Review verdicts
- Security (Opus): NO-GO on H1 and M1–M3. H1, M1, M2 and M4–M7 are fixed, and M3 is partly fixed: termination now has SoD, with the workflow deferred. The branch is GO.
- Domain (Opus): NO-GO on H1–H4. H1, H3 and H4 are fixed with regression tests. H2 is fixed as far as SoD and date bounds go; the full approval workflow is deferred to P2.1. M2, M3 and M7 are fixed too.

## Phase P2.1 — P2 deferrals before UAT (2026-10-16, branch `feature/it-finance-p2`)

This phase resolves the five items that P2 deferred to "P2.1, before UAT". It also fixes `Pages/Ea/Surveys.jsx`. Each item has a regression test in `tests/Feature/Finance/ContractP21RegressionTest.php`, which has 9 tests.

### Resolved
| item | finding | resolution |
|---|---|---|
| 1 | Domain H2 remainder: early termination took effect at once | **Approval process:** `finance.contract_termination` is the 16th process, and the 5th finance one. Stage 1 is Head of IT. Stage 2 is the CFO when `requires_cfo`, that is, when the base value is at or above the bank's threshold or the request is a post-deadline override. It declares `view_permission` `view it-finance`.<br>**Request record:** a `Termination` subject (`fin_contract_terminations`, `TRM-`).<br>**Request route:** `POST finance.contracts.terminate` now creates a request, and the contract is terminated only on final approval.<br>**Decision routes:** `finance.terminations.{approve,reject,withdraw,show}`.<br>**SoD:** the request-time SoD and date bounds stay in place. `sod_excluded_ids` (requester, request creator, contract owner and contract creator) is snapshotted into the approval context.<br>**Withdrawal:** a renewal that is executed or auto-rolled withdraws the pending termination.<br>**Design choice:** this is a separate process rather than a renewal with recommendation=terminate. A renewal has no place for the termination date or the override fields, and it carries a separate execute step. |
| 2 | Domain M1: an approved renewal that was never executed went silent | **Sweep alert:** `ContractApprovedNotExecutedNotification`, which carries no amounts, is sent at the end-date T-minus offsets and then daily after `ends_on` until the renewal is executed or withdrawn.<br>**Repeat sends:** they are made idempotent by `fin_notification_logs`, with the target date in the key.<br>**Calendar:** a red `contract_end_unexecuted` event and timeline status `approved_unexecuted`, shown until the renewal is executed. |
| 3 | Domain M4: a post-deadline termination of an auto-renewing contract was accepted as effective | **Rule:** the request needs `override_reason` and a `document_id` pointing at one of the contract's own documents.<br>**Recording:** the override is recorded on the request and terminated events, and it forces the CFO stage.<br>**UI:** the UI asks for both fields when `terminationNeedsOverride` is true. |
| 4 | Domain M5: the CFO threshold was hard-coded in config | **Storage:** `renewal_cfo_from` and `renewal_cfo_uplift_pct` are now nullable per-tenant columns on `fin_settings`. A null falls back to `config('finance…renewal_cfo_*')`.<br>**Editing:** both are editable in Settings › Renewal policy.<br>**Demo values:** KHB uses 5,000,000 and 8 %. FBN uses 50,000,000 and 10 %. |
| 5 | Domain M6: uplift mixed in FX movement | **`uplift_pct`:** now like-for-like. A same-currency renewal is struck in the contract currency. A foreign-currency quote is re-valued at the contract's own rate date.<br>**New fields:** `uplift_base_pct` (total change in base) and `fx_effect_pct` are stored and shown separately. Both are on the amount mask list.<br>**Thresholds:** `uplift_band` and `requires_cfo` use the like-for-like figure. |
| — | `Pages/Ea/Surveys.jsx` chained `.post()` onto `form.transform()`, so the form never submitted | **Fix:** the call is split into two statements.<br>**Regression guard:** `tests/Feature/InertiaTransformChainTest.php` scans `resources/js` for any call chained onto `.transform(…)`. It fails on the old file. |

### Schema, seed and UI
- **Migration:** `2027_02_15_000001_p21_terminations_cfo_thresholds_and_fx_uplift`. It is driver-agnostic.
- **Seed:**
  - One pending termination per bank (KHB-TRM-0001, FBN-TRM-0001), both at the CFO stage.
  - Per-bank CFO thresholds.
  - Proofpoint's approved-unexecuted renewal now ends 7 days out.
  - Every seeded pending request still has someone who can decide it.
- **UI:**
  - `TerminateModal` is now "Request termination", with the override fields.
  - New `TerminationPanel` on the renewal tab, with the trail, decisions and history.
  - Timeline icons for the termination events.
  - Calendar chip, gantt and passed-view treatment for "approved renewal not executed".
  - CFO threshold inputs.
  - Renewal facts show "Uplift (like-for-like)", "FX effect" and "Total change in base".

### Gate (PostgreSQL)
- **Full suite** on `it_security_risk_test`: 541 passed, 8,156 assertions. The 4 skipped are the opt-in smoke tests. It ran in 17.5 minutes.
- **Seed:** `migrate:fresh --seed` on `it_security_risk_scratch` is clean.
- **SMOKE:** `SMOKE=1 RouteSmokeTest` gave 4 passed, 2,999 assertions. The finance-workflow count assertion was updated from 4 to 5.
- **Build:** `npm run build` passes.
- **Not run:** Playwright, and no review pass was made on P2.1.

### Still open
- `PresentsContracts::optionsPayload()` has no termination status list.
- The renewals index and show pages don't list terminations. Terminations are shown on the contract's renewal tab only.
- The "Approved, not executed" badge on the contract renewal tab is inferred from `days_to_end <= 0`. The calendar uses the backend flag.

## Phase P3 — Expense management (2026-10-17, branch `feature/it-finance-p3`)

Plan: `docs/build/ATH-FIN-PLAN-p3.md`. Built by fin-architect (opus) → fin-backend-engineer → fin-workflow-integrator ∥ fin-frontend-engineer → fin-qa-engineer (sonnet) → fin-security-reviewer + fin-domain-reviewer (opus) → fixes → gate.

### Shipped
- **Schema:** 3 migrations (`2027_03_01_000001..3`): expense requests, POs + lines, goods receipts + lines, invoices + lines, recurring expenses, accruals; actuals, import batches, import mappings, match rules; contract `lease_assessment` and `fin_settings.invoice_sod_from`. 14 models.
- **Services:** `BudgetAvailability`, `ExpenseService`, `ProcurementService`, `ThreeWayMatcher`, `InvoiceService`, `ActualsService`, `ReconciliationService`, `RecurringExpenseService`, `AccrualService`, `FinanceTax`, `FinanceDocuments`, `FinanceTransactionImporter`.
- **Workflow:** `finance.expense_approval` (owner → Head of IT → CFO → MD/CEO) and `finance.invoice_approval` (owner → Finance Controller → Finance Approver for high value) with default stages; the processes now total 18 (7 for finance). The Workflow Studio inbox honours `owner_stage_without_permission`. Notifications: UnbudgetedSpend, InvoiceDue, ImportCompleted. Commands: `fin:recurring-generate` (00:30) and `fin:invoices-sweep` (07:30). Invoice due dates appear in the finance calendar, `.ics` feed and digest. Renewals create a draft expense request.
- **HTTP/UI:** 70 routes, 8 controllers, 13 pages (Expenses, Purchase Orders, Invoices, Actuals & Reconciliation with GL import/mapping/unmatched queue/rules, Recurring, Accruals, Imports), 24 components, nav entries, the contract lease field, `from_actuals` on Budgets/Create, and the invoice SoD threshold in Settings.
- **Demo data:** `FinanceExpenseDemoSeeder` seeds orgs 2 and 3 with all expense, PO and invoice statuses, an overdue invoice, an unbudgeted emergency expense, variance/override/lease/prepaid invoices, recurring items, accruals, Jan–Aug actuals and a September GL batch with unmatched, duplicate and unbudgeted rows.

### Tests
New: `ExpenseBackendSmokeTest` (7), `ExpenseServicesSmokeTest` (10), `ExpenseWorkflowIntegrationTest` (11), `ExpenseApprovalAndAvailabilityTest` (14), `ProcurementThreeWayMatchTest` (16), `ActualsImportAndReconciliationTest` (13), `ExpenseReviewRegressionTest` (11), and a `RouteSmokeTest` expense-estate test that also checks every pending demo approval step has a seeded user who can decide it. Process counts were updated in `WorkflowStudioModuleTest` and `FinanceWorkflowStudioTest`.

### Decisions
P3-1 … P3-16 as written in the plan §0 (availability formula, over-budget warn/block, unbudgeted one-band escalation, cost-centre owner stage, CFO-only 10m–50m stage, string condition keys, invoice SoD on the final step, match tolerance, one line and one PO per request, GL import, open-period postings, minimal accruals/prepayments, IFRS 16 indicator, renewal → draft expense). Additionally:
- **P3-6a:** IT Budget Owner gets `view workflows`, so owner-stage approvers can open the Studio inbox.
- **P3-17:** system-created renewal drafts skip the unbudgeted-category check; it is enforced at submit.
- **P3-18:** availability lineage stops at the fiscal-year boundary (prior-year copies no longer inherit commitments).

### Fixes during the build
- `fin_actuals.source` widened to 24 characters (`prepayment_release` was rejected by Postgres).
- `ActualsService::unmatch`/`acknowledgeUnbudgeted` now refuse another bank's actual.

### Review fixes applied
- **Security H1:** the variance overrider is excluded from deciding the invoice. **H2:** a workflow switched on after submission now starts with the SoD snapshot, and final approval re-checks the exclusions, so a requester can't approve their own request.
- **Security M1–M5, L3:** the capturer, overrider and step deciders can't pay; notifications drop amounts for users without `view it-finance-amounts`; document upload has a file-type whitelist and needs edit rights on the record; import batch actuals are filtered by visible cost centres; the requester can't receive goods or confirm their own invoice; the import delimiter must be one character.
- **Domain H1–H4, M6:** no cross-year commitment leak; proposed prepayment releases count as committed; posted accruals reduce the PO commitment; actual re-matching respects period locks and only applies to GL/manual rows; the final-approval availability re-check locks the baseline line.

### Deferred / logged (not fixed)
- **Security L1** (line `category_id`/`asset_id` not bank-checked), **L2** (recurring cost-centre access check), **L4** (reject/return can skip SoD when no request is pending).
- **Domain M7** (accruals exclude VAT), **M8** (accrual cut-off counts submitted invoices), **M9** (period close ignores pending accruals/releases), **M10** (prepayment releases cross into a planning FY), **M11** (GL duplicate detection keys on reference only), **M12** (direct invoices have no cap against the approved request), **L13** (emergency spend not escalated a band — per P3-5; needs a decision), **L14** (recurring dates skipped silently), **L15** (in-file GL duplicates).
- Webhook-channel delivery for the new notifications; column-mapping UI for the expense CSV import; tax rates still unverified against the Nigeria Tax Act 2025; seeder dates drift if reseeded after December.

### Review verdicts
Both reviewers returned NO-GO before the fixes (security: H1, H2; domain: H1–H4). All of those are fixed and covered by `ExpenseReviewRegressionTest`.

### Gate (PostgreSQL)
- Full suite on `it_security_risk_test`: 624 passed, 5 skipped (8637 assertions, 22 min).
- `migrate:fresh --seed` on `it_security_risk_scratch`: clean, no seeder warnings. `SMOKE=1 RouteSmokeTest`: 5 passed (3444 assertions).
- `npm run build` passes. Playwright not run in P3 (per the token rules: P2 and P7 only).
- After the suite run: added a missing `FinancePolicy` import in `FinanceImportController` (the import page 500'd, caught by SMOKE) and changed the seeder so procurement, not the Finance Controller, overrides the demo variance (H1 had left that review stage with no eligible decider in org 3). `ExpenseReviewRegressionTest` and `ActualsImportAndReconciliationTest` were re-run green after the change.

## Phase P4 — Budget monitoring, variance, period close & reports (2026-10-18, branch `feature/it-finance-p4`)

Plan: `docs/build/ATH-FIN-PLAN-p4.md`. Built by fin-architect (opus) → fin-backend-engineer ∥ fin-frontend-engineer → fin-workflow-integrator → fin-qa-engineer (sonnet) → fin-security-reviewer + fin-domain-reviewer (sonnet, "be exhaustive") → fixes → gate.

### Shipped
- **Schema:** `2027_03_10_000001` (forecasts, variance notes, variance comments, period closes) and `2027_03_10_000002` (accrual `cancel_reason`, `cancelled_at`). 4 models; `Period::closes()`; Issue source `finance_variance`.
- **Services:** `MonitoringService` (BvA matrix over 10 groupings, drill-down, ledger reconciliation incl. the unallocated row), `ForecastService` (EAC by remaining budget and run rate, floored at booked actuals + open commitments; overrides; burn; snapshots at close), `VarianceService` (YTD, both directions, % and absolute floor, per-line overrides; explain, acknowledge with SoD, comments, raise issue), `PeriodCloseService` (8-item checklist, close, lock, reopen through approval), `FinanceMetrics`, `FinanceKriSource`, `PeriodCloseReminderSweeper`, and a report framework with a dependency-free XLSX writer.
- **Workflow:** `finance.period_reopen` (CFO stage; requested with `close it-finance-periods`, decided with `approve it-finance`, subject = the close record). Processes total 19 (8 for finance). Notifications: BudgetVariance (amber/red, once per line/period/severity) and PeriodCloseReminder (due, overdue, blocked). Commands: `fin:variances-sweep` (07:00) and `fin:period-reminders` (08:00), both Africa/Lagos, `withoutOverlapping`.
- **Reports:** 8 keys (`bva`, `budget_book`, `variance_commentary`, `commitments`, `invoice_ageing`, `unbudgeted`, `contract_register`, `vendor_spend`) in CSV, XLSX and PDF, schedulable through `ScheduledReport` (`finance` and `finance.*` types). 4 finance KRIs (`KRI-FIN-VAR`, `-UNB`, `-INV`, `-NOT`), CFO-owned. Board-pack section `it_financial_position`.
- **HTTP/UI:** 22 routes; Dashboard, Budget Monitor (BvA pivot + drill-down + export), Variances, Forecast, Period Close, Reports pages; 20 components; nav entries; board-pack section; checklist errors on the Settings fiscal calendar.
- **P3 carry-overs folded in:** domain M7 (accrual VAT), M8 (accrual cut-off), M9 (close blocked by pending accruals/releases), D10 (no close before period end), C7 (reopen/checklist).
- **Demo data:** `FinanceMonitoringDemoSeeder` (run twice in `DatabaseSeeder`: after the expense seeder, and after `ReportsIntegrationsSettingsDemoSeeder` for finance schedules). Kano 260 variance notes, First Bank 365; Kano September re-opened and blocked (pending accrual + unexplained red); First Bank September reopen pending at the CFO stage, decidable by cfo@firstbanknigeria.ng.

### Tests
New: `MonitoringBackendSmokeTest` (9), `MonitoringWorkflowIntegrationTest` (10), `MonitoringAndVarianceTest` (15), `PeriodCloseTest` (16), `FinanceReportsTest` (9), `MonitoringReviewRegressionTest` (9), and a `RouteSmokeTest` monitoring-estate test. Counts updated in `WorkflowStudioModuleTest` (19), `FinanceWorkflowStudioTest` (8) and `FinanceTenancyAndRoutesTest` (P4 page routes).

### Decisions
P4-1 … P4-14 and C101 … C125 as written in the plan §0/§10, all accepted. Key ones: YTD variance with % AND absolute floor, flagged both ways (P4-1); reopen decided with `approve it-finance` because the CFO doesn't hold `close it-finance-periods` (P4-6, C102; spec §6 should be updated to match — domain L12); corrective actions as system-raised Issues with no linked model (P4-12); XLSX via ZipArchive for report exports only, the workbook and imports stay CSV (P4-10). L13 (emergency spend escalation) stays deferred to P3.1.

### Fixes during the build
- `FinanceConfigurationTest::test_fx_rates_import_and_single_base_currency` was time-of-day flaky (UTC vs Africa/Lagos dates); pinned to midday.
- `ReportFigures` `finance.*` match arm could never match; prefix check moved ahead of the match.
- BvA reconciliation badge hidden for users without amounts (it showed red "Out of balance").
- Accrual Cancel now prompts for the required reason.

### Review fixes applied
- **Security H1 / domain M4:** finance scheduled-run summaries hold no money; `reports:run-due` runs finance schedules as `created_by` and records a failed run if that user lacks access; finance schedules can only be edited, paused or deleted with view + export it-finance (security L4).
- **Security H2 / domain H3:** explain, acknowledge, comment, raise-issue and evaluate are refused on closed or locked periods; the nightly sweep only evaluates open, ended periods.
- **Domain H1:** dashboard, board pack and KRIs include unmatched/unbudgeted actuals (`actual_unallocated`) and tie to the BvA and ledger.
- **Domain H2:** close checks period end before evaluating; evaluation of an unended period needs an explicit force flag.
- **Domain H4:** posted accruals due for reversal block the close; cancelling an accrual needs a reason, shown on the checklist as a warning.
- **Domain H5:** EAC and snapshots are floored at booked actuals + open commitments; overrides below the floor are refused.
- **Security M1 / domain M10, M3, M4:** dashboard pending approvals scoped to what the viewer may see and limited in SQL; one failing schedule no longer stops `reports:run-due`; unchanged nightly evaluations write no activity-log rows.

### Deferred / logged (not fixed)
- **Domain:** M1 (BvA severity over unelapsed periods), M2 (close needs no acknowledgement of explained reds; closer may self-explain), M3 (BvA export, commitments report and drill use a different commitments definition from availability), M5 (snapshots ignore overrides and are replaced on re-close), M6 (accruals at PO-date FX, not period-end), M7 (demo polish: hard-coded seeded checklist, red-heavy floors, burn vs EAC note, synthetic KRI history), M8 (scoped viewers don't see their unallocated spend), M9 ("approvals complete" checks invoices only), L1–L14.
- **Security:** M2 (finance KRIs and stored board-pack content unmasked; masking only on board-pack read), L1 (org-wide checklist counts for restricted users), L2 (report preview needs only view), L3 (`$guarded = []` on new models), L5 (issue title exposes line labels), L6 (reopen/close races), L7 (defence-in-depth org filters on vendor/legal-entity lookups), L8 (notification recipients not permission-checked), L9 (report memory before the row cap).
- XLSX output proven as valid zip/XML only, not opened in Excel. Playwright not run (P2 and P7 only).

### Review verdicts
Both reviewers returned NO-GO before fixes (security: H1, H2; domain: H1–H5). All are fixed and covered by `MonitoringReviewRegressionTest`.

### Gate (PostgreSQL)
- First run, before the review fixes: 682 passed, 6 skipped, 1 failed (the P4 page routes missing from `FinanceTenancyAndRoutesTest`, then fixed).
- Re-run after the review fixes, on `it_security_risk_test`: 692 passed, 6 skipped (9691 assertions, 22 min).
- `migrate:fresh --seed` on `it_security_risk_scratch`: clean (only the deliberate EA metamodel demotions). `SMOKE=1 RouteSmokeTest`: 6 passed (3786 assertions, 233 GET routes).
- `npm run build` passes.

## Phase P5 — Investments & value realisation (2026-10-18, branch `feature/it-finance-p5`)

Plan: `docs/build/ATH-FIN-PLAN-p5.md`. Spec FIN-05.01–.09 and .11 (FIN-05.10 Application TCO deferred to P6, C142), plus the FIN-07 items P5 touches (investment events in the calendar, portfolio and benefits reports). Built by fin-architect (opus) → fin-backend-engineer (WP-A) ∥ fin-frontend-engineer (WP-C) → fin-workflow-integrator (WP-B) → fin-qa-engineer (WP-E, sonnet) → fin-security-reviewer + fin-domain-reviewer (sonnet, "be exhaustive") → fixes → gate.

### Shipped
- **Schema:** investments, business cases (versioned), investment scores, stage gates, investment changes, benefits, benefit readings, value reviews, plus the freeze-control migration `2027_03_25_000001_p5_freeze_control_fields`. 9 new models. Codes: `IVT-` for investments, `ICR-` for changes (C141); the first seeded code per bank is `{KHB|FBN}-IVT-0001`.
- **Services:** `InvestmentService` (lifecycle, `linkLines`), `BusinessCaseService` and the calculator (NPV, IRR, payback, ROI, TCO), `InvestmentScoring` and `PortfolioService` (rank, fund line, summary), `InvestmentFinancials` (BAC, AC, committed, EVM, grouped for lists), `StageGateService`, `InvestmentChangeService`, `BenefitsService`, `ValueReviewService`, `ValueReviewSweeper`.
- **Workflow:** `finance.business_case_approval`, subject `StageGate`. Default stages: Head of IT (job title, all gates) → CFO (approved and change gates) → IT Steering Committee (role, all gates) → Board, decided by job title "Managing Director" (approved and change gates, base amount at or above `business_case_board_from`, 250m). Delivered gates skip CFO and Board. Processes total 20 (9 for finance). `ValueReviewDueNotification` and `fin:value-reviews-sweep` (daily 07:45, `--date=`): due once at the 14-day lead window, overdue at 1/7/14/30 days; recipients are active reviewer and sponsor holding `view it-finance` (C140).
- **Integrations:** investment events in the finance calendar (separate `passedInvestments` prop, C143; "mine" filter applies); 2 reports (10 total, group `investments`); `investments_by_stage` and `benefits_realised_pct` figures; board-pack section `it_investment_portfolio`.
- **P4 carry-overs adopted:** security M2 (`FinanceMasking::boardContent` masks `it_financial_position`, `it_investment_portfolio` and the `KRI-FIN-VAR` / `KRI-FIN-UNB` values and notes; `config('finance.masked_kri_codes')`), domain M5 (re-close sets `superseded_at` on forecast snapshots, readers filter on it), security L8 (new notification only).
- **HTTP/UI:** 35 new routes (930 total, 243 `finance.*`). Pages `Investments/{Index,Create,Show,Portfolio}`; nav entries; the finance dashboard, calendar, budget line form and KRI/board-pack views updated for masking.
- **Demo data:** `FinanceInvestmentDemoSeeder` (called after the first `FinanceMonitoringDemoSeeder`). Kano Heritage 10 investments, First Bank 12; a `steerco@` user per bank. A pending approved gate sits at the CFO stage (KHB-IVT-0004, FBN-IVT-0007) and an over-tolerance change at Head of IT (KHB-IVT-0008, FBN-IVT-0011). Mixed health, rejected, withdrawn and closed paths, and an overdue value review are seeded on purpose.

### Tests
New: `Unit/Finance/BusinessCaseCalculatorTest` (10), `InvestmentBackendSmokeTest` (10), `InvestmentAndBusinessCaseTest` (18), `BenefitsAndValueReviewTest` (11), `InvestmentWorkflowIntegrationTest` (11), `FinanceP4CarryOverTest` (3), all on the `BuildsInvestments` fixture trait, plus 8 review-regression tests for the fixes below and `RouteSmokeTest::test_finance_investment_estate_is_seeded_and_renders_for_every_demo_tenant`. Counts updated in the Workflow Studio, finance Workflow Studio, tenancy/routes and route-smoke tests (20 processes, 9 finance, P5 page routes).

### Decisions
P5-1 … P5-14 and C126 … C144 as written in the plan §0/§10. C126–C134 were put to sign-off and are **accepted with the plan's recommended option**:
- **C126 / P5-1:** BAC is the approved business case's one-off cost (capex + opex, run excluded), set at the `approved` gate and changed only by an applied investment change; tagged budget lines show as allocated, with a funding gap.
- **C127 / P5-2:** EV = BAC × manager-reported % complete; CPI = EV / AC; EAC = BAC / CPI, with a manual override needing a reason of 20 characters or more.
- **C128 / P5-4:** the approval subject is `StageGate`, one row per gate request; approval gates are `approved`, `delivered` and over-tolerance `change`.
- **C129:** business cases are decided on the gate routes (`finance.stage-gates.approve/reject`), not on separate business-case routes.
- **C130 / P5-5:** the Board stage is decided by job title "Managing Director" rather than a new role.
- **C131 / P5-6:** `approve it-finance-investments` is granted to Finance Approver.
- **C132 / P5-9:** calculator conventions (base currency, year-0 undiscounted, IRR null without a sign change, payback linear within the year).
- **C133:** benefit readings link evidence through `document_id` to `fin_documents`.
- **C134:** `linkLines` may tag or untag `investment_id` on baseline lines without a revision, audited.

Other settled decisions: delivery risk is entered as risk and **inverted** in the score (P5-10, C136); the fund-line envelope defaults to the change-the-bank (grow/transform) baseline lines, with `?envelope=` as a what-if (P5-11, C137); Application TCO is deferred to P6 (C142); multi-currency business cases are deferred (C144).

### P4 deferrals
- **Adopted in P5:** security M2, domain M5, security L8 (new code only).
- **Left for P7:** domain M1, M2, M3, M6–M9, L1–L14; security L1–L7, L9.

### Fixes during the build
- Demo `md@` no longer holds the IT Steering Committee role (see domain H1 below).
- Finance KRI masking: the Dashboard showed `0.0` for a masked KRI value; now a dash.

### Review fixes applied
- **Domain H1 (approval deadlock):** `md@` was a legitimate stage-3 decider and then, by the engine's one-stage-per-person rule, could not decide the Board stage. `md@` removed from the IT Steering Committee role in `FinanceDemoSeeder`; `FinanceApprovals::assertNoStageStranded` look-ahead (called from `StageGateService::approve`) refuses a decision that would leave a later stage without an eligible decider.
- **Domain H2 / security H1 + H2 (control parameters editable under review):** `cost_tolerance_pct` is snapshotted at baseline in `bac_baselined_tolerance_pct` and used by `InvestmentChangeService`; sponsor, manager, cost centre, `is_regulatory`, `rgt` and tolerance are frozen while a gate or change is pending and after approval; `StageGate.sod_excluded_ids` is snapshotted at request time. Migration `2027_03_25_000001_p5_freeze_control_fields`.
- **Domain H3 (PIR independence):** the reviewer defaults to the sponsor, then a non-manager benefit owner; the manager and the delivered-gate requester cannot complete reviews.
- **Security M3:** `KRI-FIN-VAR` / `KRI-FIN-UNB` breach notes and the auto-raised Issue text carry no amounts.

### Deferred / logged (not fixed; P5.1 / before UAT)
- **New:** no investment-change path to reassign sponsor, manager, cost centre, regulatory flag or rgt after approval (the fields are frozen).
- **Domain M:** M1 benefit status derived lazily (sweep only runs for orgs with open reviews); M2 audit text lacks the bank prefix on the first seeded code (`recode()`); M3 DoA/board band uses one-off cost only, not TCO; M4 "funded" criterion needs only one tagged line, not allocated >= BAC; M5 closure and benefit-shortfall acceptance have no approval; M6 gate criteria not re-evaluated at decision time; M7 readings and financials mutable after closure; M8 EVM edge cases (delivered not forced to 100 %, CPI = 0); M9 delivery acceptance is self-attested; M10 benefit percentages averaged across mixed units; M11 business-case benefits not linked to the benefits register; M12 job-title approver matching is a substring match on a free-text field; M13 unconfigured workflow collapses to a single approver; M14 scoring is not independent of manager and sponsor.
- **Domain L:** L1 advance check races without a row lock; L2 change apply clamps negative BAC and tests tolerance against the drifted BAC; L3 fund line keeps delivered items and ignores committed spend; L4 actual tagged to two investments counts twice in portfolio AC; L5 NPV convention note and sensitivity table missing; L6 IAS 38 capitalisable flag missing; L7 board threshold is config, not per-bank; L8 value-review postponement unlimited; L9 delivered end date read from `$criteria[0]` by position.
- **Security M:** M1 approval trail leaks the amount band to users without `view it-finance-amounts`; M2 untagging budget lines not scoped to the editor's cost centres or budget status; M4 benefit targets, units and due dates editable after approval, readings self-attested; M5 no lock on one pending request per investment; M6 gate approvers see and may score every investment; M7 raw or bulk writes outside the audit trail.
- **Security L:** L1 investment picker discloses titles beyond cost-centre visibility; L2 risk/change/EA link picker ignores module permissions; L3 `saveLine` accepts any open investment for `investment_id`; L4 uploaded evidence file orphaned when a reading is refused; L5 business-case cost and benefit inputs lack type/size rules; L6 rejection and withdrawal audit rows have a null causer; L7 manual criteria for non-approval gates are self-attested; L8 `$guarded = []` on the nine new models; L9 `ValueReviewSweeper` per-review queries; L10 activity log stores business-case money regardless of `view it-finance-amounts`.
- Also deferred from scope: FIN-05.10 Application TCO (P6), investment API endpoints (P7), value reviews in the daily digest, webhook channel, multi-currency business cases, automatic Change Management link.

### Review verdicts
Domain: conditional go before fixes (0 Critical, 3 High, 14 Medium, 9 Low). Security: 0 Critical, 2 High, 7 Medium, 11 Low. All Highs and security M3 are fixed and covered by regression tests.

### Gate (PostgreSQL)
Gate: 763 tests passed, 7 skipped (10,395 assertions, 26 min) on PostgreSQL `it_security_risk_test`; `migrate:fresh --seed` on `it_security_risk_scratch` ([KHB] 10 / [FBN] 12 investments, no warnings); SMOKE `RouteSmokeTest` 7 passed (3,936 assertions); `npm run build` passes; Pint clean on all touched PHP files.

## Phase P6 — Cloud FinOps, allocation, showback & capitalisation (2026-10-19, branch `feature/it-finance-p6`)

Plan: `docs/build/ATH-FIN-PLAN-p6.md`. Spec FIN-03.09, .10, .12, .13, FIN-02.12 and FIN-05.10 (Application TCO, deferred from P5, C142), plus the P5 carry-overs below. Built by fin-architect (opus) → fin-backend-engineer ∥ fin-frontend-engineer → fin-workflow-integrator → fin-qa-engineer (sonnet) → fin-security-reviewer + fin-domain-reviewer (sonnet, "be exhaustive") → fixes → gate.

### Shipped
- **Schema:** migrations `2027_04_01_000001` (cloud accounts, import lines, tag rules, anomalies), `…000002` (guarded drop and rebuild of the unused P1 allocation tables in the spec shape, plus allocation runs), `…000003` (capitalisations and amortisation lines), `…000004` (FinOps settings columns), `…000005` (investment reassignment and BAC baseline) and `2027_04_02_000001_capitalisation_cost_basis`. New models: `CloudAccount`, `CloudCostLine`, `CloudTagRule`, `CloudAnomaly`, `AllocationRun`, `ChargebackRun`, `Capitalisation`, `AmortisationLine`. Codes: `CAP-` for capitalisations, `CBK-` for chargeback runs (C171).
- **Cloud / FinOps (FIN-03.09, .10):** cloud accounts, import of AWS, Azure and generic CSV exports (`CloudExportParser`, `CloudCostService`), tag rules and tag attribution, untagged assignment and reallocation, and the FinOps sub-ledger, which is never posted to `fin_actuals` (C145). Anomaly detection (`CloudAnomalyService`) with the sweeper `fin:cloud-anomalies` (daily 05:45) and `CloudAnomalyNotification`; `KRI-FIN-CLD` (open + acknowledged in 30 days, not masked, C170). A `cloud_imported` item on the period-close checklist (warn, never fail by default, C167).
- **Allocation, showback, chargeback (FIN-03.12, .13):** `AllocationService` rebuilt on the spec's tables; results frozen with the period, so allocation runs are computed once at close (C152). `ShowbackService` statements by target type and basis, and `ChargebackService` journals for closed or locked periods (one run per period and target type until voided, C154). `CostPools` and `BudgetLineClassifier` (line classification of application and business service, C162).
- **Application TCO (FIN-05.10):** `ApplicationTcoService`, by application and by business service (Kano has no EA applications, C160). EA hook: `CostModelService::tco()` is unchanged; `tcoWithFinance()` adds the measured finance TCO when `ea.cost` is enabled (C161).
- **Capitalisation (FIN-02.12):** IAS 38 / IAS 16 capitalisation from an investment or a capex expense request, with the IAS 38.57 criteria checklist, a memo amortisation schedule (straight-line or declining balance, C157) and asset link or creation on approval (C158). Workflow: a 10th finance process `finance.capitalisation_approval` (21 processes in total, C155).
- **Reports:** 4 new (14 total): `cloud_spend`, `showback_statement`, `application_tco` and `capitalisation_register`.
- **P5 carry-overs adopted:** investment reassignment as an investment change of kind `reassign`, always through the `change` gate (C168); L2 (tolerance tested against the baselined BAC, negative BAC refused); L6 (`is_capitalisable` / IAS 38 flag); security M1 (approval trail amount band masked), M2 (untagging scoped to the editor's cost centres and budget status) and M5 (one pending request per investment).
- **Demo data:** `FinanceFinOpsDemoSeeder` for both banks (cloud accounts, imports, tag rules, anomalies, allocation rules and runs, a chargeback run, capitalisations with schedules). The seeder tags budget lines directly before the allocation run (see domain H1).

### Tests
New: `CloudCostTest`, `ShowbackAndAllocationTest`, `CapitalisationTest`, `ApplicationTcoTest`, `FinOpsBackendSmokeTest`, `FinOpsWorkflowIntegrationTest`, `FinanceP5CarryOverTest`, `P6ReviewHighFixesTest` (regression tests for the review Highs below), `Unit/Finance/AmortisationScheduleTest`, `CloudAnomalyEvaluateTest`, `CloudExportParserTest`, on the `BuildsFinOps` fixture trait and `tests/Fixtures`; plus the FinOps estate test in `RouteSmokeTest`. Counts updated in the Workflow Studio, finance Workflow Studio and tenancy/routes tests (21 processes, 10 finance, P6 page routes).

### Decisions
P6-1 … P6-22 and C145 … C172 as written in the plan §0/§10. Sign-off items (C145, C149, C155, C156, C159, C162, C168) were **accepted with the plan's recommended option**:
- **C145 / P6-1:** cloud cost is a FinOps sub-ledger reconciled to the ledger, never posted to actuals (invoices already post them; posting both would double count).
- **C149 / P6-5:** the P1 allocation tables were empty and unused, so a guarded migration drops and recreates them in the spec shape.
- **C155 / P6-11:** capitalisation approval goes through Workflow Studio as `finance.capitalisation_approval`, not a module-private maker-checker.
- **C156 / P6-12:** capitalisation cost basis and IAS 38 checklist as in the plan (hardened by domain H2 below).
- **C159 / P6-15:** TCO is direct + allocated + FinOps cloud; ledger cloud is excluded on the actual basis, labour is a breakdown and contracts are memo.
- **C162 / P6-17:** line classification may set application and business-service tags on lines of any budget status, without a revision, audited (as C134).
- **C168 / P6-22:** reassignment of frozen investment fields is an investment change, always approved, SoD on old and new holders.

Other settled decisions: tag attribution order account rule → org rule → built-in code/name match (C147); anomalies tested by rolling baseline and week-on-week with an absolute floor (C148, C172); overlapping sources resolved by most-specific source (C150); `forecast` amount type deferred (C151); showback units live in `fin_settings.showback_units` (C153); masking of deviation, untagged and coverage percentages is not applied (C163); cloud uses `import it-finance` (C164); scoped viewers see visible cost centres only (C165); FIN-03.11 commitment and discount utilisation out of scope (C166).

### P5 deferrals
- **Adopted in P6:** investment reassignment (C168), domain L2, domain L6, security M1, M2, M5.
- **Still open from P5:** domain M1–M14, L1, L3–L5, L7–L9; security M4, M6, M7, L1–L10 (see the P5 section).

### Fixes during the build
- QA found two production bugs: `ChargebackService::preview` used `whereKey()` with a subquery (now `whereIn`), and `AllocationService` called an undefined `sameBank()`.

### Review fixes applied
- **Domain H1 (reclassification double counts closed periods):** classification is refused for budget lines that have actuals in closed or locked periods; the seeder tags lines directly before the allocation run.
- **Domain H2 (capitalisation cost basis):** leases are excluded; `excluded_amount` counts as consumed; optional recoverable-VAT setting `fin_settings.capitalisation_vat_recoverable` (default off, no UI yet); `recognition_date`; a `consumed_actual_ids` overlap guard, which also closes domain M2 (sources can overlap).
- **Domain H3 (TCO ledger cloud):** the ledger cloud pool is dropped only where FinOps covers the subject; `unattributed` is `it_total − tco_total`; the detail ties to the row.
- **Domain H4 / security H2 (EA TCO performance):** `ApplicationTcoService` is a scoped singleton and EA `tco()` no longer calls finance.
- **Security H1 (EA disclosure):** EA `tco()` is pure; `tcoWithFinance()` is gated by `view it-finance`, amounts and cost-centre scope; the GraphQL cost output uses a `COST_KEYS` whitelist.
- **Security H3 (chargeback controls):** create, download and void need `close it-finance-periods`; void has SoD (exporter ≠ voider); runs are hidden without `can.run`.
- **Domain M5:** declining balance under 24 months without a rate is a validation error, not a 500.

### Deferred / logged (not fixed; P6.1 / P7)
- **Domain M (integrity and controls):** M1 "reconciles to the cent" is circular and open-period figures mix stored and live data; M3 capitalisation criteria need no evidence document; M4 amortisation and asset lifecycle gaps (prior-year catch-up warning, no cancel, impairment, disposal or life change on a capitalised row); M6 allocation rules starting before the open floor cannot be edited (blocks chargeback); M7 period re-open ignores exported chargeback runs, `is_frozen` and capitalisations; M14 no maker-checker on allocation methodology or the chargeback journal.
- **Domain M (cloud and data):** M8 restatement deletes manual attributions and other batches' lines with no before/after record; M9 one spike creates up to three anomalies (account, service, application) and inflates `KRI-FIN-CLD`; M10 hard-coded local assumptions (GL 6302, `CP-CLD`/`CP-LAB`, annualisation `* 12`, CFO threshold from config only); M11 scope gaps for cost-centre-limited users on tag rules and anomaly handling; M12 ingestion fidelity (Tax, Credit, Refund and fee lines included, no billed vs effective cost, no FOCUS); M13 the linked Asset has no cost, life or depreciation method (spec FIN-03.13, recorded as C158).
- **Domain M (masking):** M15 amounts leak through free text (activity description, error text, `blocked_reason`); M16 approvers can decide a capitalisation without `view it-finance-amounts`.
- **Domain L:** L1 allocation rules apply by period start day only; L2 straight-line schedule with a tiny amount ends negative; L3 tag-rule precedence is per tag, not per scope; L4 rule `hits` inflate on restatement; L5 capitalisation register grouped under Cloud and skips earlier-FY catch-up; L6 migration `…000005` `down()` leaves two columns; L7 no account confirmation for files without an account column; L8 `KRI-FIN-CLD` and the other finance KRIs defined only by the demo seeder; L9 `ea.cost` config block under the wrong comment; L10 chargeback downloads not audited, no tax treatment; L11 no SoD between importer and anomaly dismisser; L12 `is_capitalisable` is a project-manager toggle; L13 FinOps maturity gaps (unit economics, tower dimension, cloud budget, commitments); L14 rejected-row line numbers refer to aggregated rows; L15 dashboard open-anomaly count and the anomalies list scope differently.
- **Domain, new follow-up (Medium):** after H3, `unattributed` can go negative when FinOps cloud exceeds ledger cloud; needs a presentation rule or a reconciling line.
- **Security M:** M1 tag rules, account creation and anomaly handling not cost-centre scoped; M2 closed-period showback and TCO only half frozen (direct pool computed live); M3 re-open then allocation run diverges from an exported chargeback journal; M4 amounts in capitalisation activity descriptions and no amounts check on submit; M5 anomaly sweep not failure-isolated per account and loses notifications; M6 bulk cloud writes lack before/after audit; M7 scoped viewers receive bank-wide dropdown lists and the user directory; M8 cloud CSV parsing memory-unbounded and `base_cost` can overflow; M9 dashboard `investments_by_stage.bac` shown without amounts; M10 capitalisation index loads everything in memory.
- **Security L:** L1 `authorize(): true` on four form requests; L2 `$guarded = []` on the new models; L3 anomaly account subquery without organisation filter; L4 capitalisation SoD snapshot omits the sponsor and survives a reassignment; L5 chargeback void race and `file_name` in Content-Disposition; L6 approval trail still shows `requires_cfo` / `requires_board` values; L7 no throttle on allocation runs, code and unique-key collisions under concurrency; L8 client-supplied cloud import `file_name`; L9 cloud batch detail visible outside the viewer's cost centres.
- Also deferred from scope: FIN-03.11 commitment and discount utilisation (C166), allocation `forecast` amount type (C151), investment API endpoints (P7), spec §6 update for the new finance process.

### Review verdicts
Domain: NO-GO before fixes (0 Critical, 4 High, 16 Medium, 15 Low). Security: 0 Critical, 3 High, 10 Medium, 9 Low. All Highs and domain M2 and M5 are fixed and covered by `P6ReviewHighFixesTest`.

### Gate (PostgreSQL)
Gate: after review fixes, 875 passed / 8 skipped on PostgreSQL (`it_security_risk_test`, 29 min); `migrate:fresh --seed` + SMOKE `RouteSmokeTest` (8 passed, 4,226 assertions) on `it_security_risk_scratch` with no seeder warnings; `npm run build` passes; pint clean on all touched PHP files. Pre-fix gate: 863 passed / 8 skipped.


## Phase P7 — Hardening & hand-off (2026-10-31, branch `feature/it-finance-p7`)

Plan: `docs/build/ATH-FIN-PLAN-p7.md`. Final reviews: `docs/build/ATH-FIN-REV-p7.md`. **P7 adds no features.** It triaged every deferred Medium and Low item from §P0–§P6 (62 must-fix before UAT, 94 backlog, 31 already resolved), fixed the must-fix set in five work packages, added the QA sweeps and the hardening checklist (H1–H10), and then ran two final Opus reviews over the whole module. Built by fin-architect (opus) → five fin-backend-engineer fixers on their own databases (sonnet) → fin-qa-engineer (sonnet) → orchestrator query work and gate → fin-security-reviewer + fin-domain-reviewer (opus, whole module) → fixes F1–F4 → final gate.

### Shipped

**Work packages (plan §3; plan item IDs in brackets)**
- **WP-A, budgets, approvals wrapper, settings masking:**
  - Segment-exact job-title matching for finance stages, enforced in `FinanceApprovals` (`jobTitleMatches`, `assertEligible`; P5-dom-M12, P7-3). Demo `headofit@` users are retitled "Head of IT".
  - The approval trail masks `requires_cfo` / `requires_board` for viewers without amounts (P6-sec-L6).
  - Targets, transfers and supplementary summaries are cost-centre filtered (P1-sec-M3).
  - Cross-legal-entity virements are refused (P1-dom-M6, P7-4).
  - Remainder phasing never goes negative (P1-L2). The workbook CSV guard covers every non-numeric cell and tab/CR (P1-L3).
  - Per-tenant isolation on the approval sweep and DoA activation loops (P1-L5).
  - The base-currency guard now covers targets, investments and business cases (P1-L1).
  - The Postgres-only `ilike` in a seeder is gone (P1-dom-M5b).
  - Risk, EA application and business service pickers are empty without that module's view permission (P5-sec-L2, P7-15). `saveLine` refuses an investment outside the editor's cost centres (P5-sec-L3).
  - Settings shows null DoA and threshold amounts to users without `view it-finance-amounts` (P0-sec-S9).
- **WP-B, spend, ledger, monitoring, close:**
  - PO and invoice line `category_id` / `asset_id` are bank-checked (P3-sec-L1). Recurring expenses refuse a cost centre the user cannot see (P3-sec-L2).
  - Prepayment releases in a later fiscal year post to the successor baseline line, or to the unbudgeted queue (P3-dom-M10, P7-8).
  - GL duplicate key and in-file duplicates (P3-dom-M11 / L15, P7-9). Direct-invoice cap against the approved request (P3-dom-M12, P7-10).
  - A failed recurring generation no longer skips the date; it writes a `generation_failed` audit row and retries (P3-dom-L14).
  - Accruals convert at the period-end closing rate (P4-dom-M6, P7-7).
  - `BudgetAvailability::commitmentItems` is the single definition of commitments for the availability figure, the BvA drill and the commitments report (P4-dom-M3).
  - BvA severity is classified over ended periods only (P4-dom-M1).
  - Close needs red variance notes acknowledged (P4-dom-M2, P7-5).
  - Re-open is refused while a non-voided chargeback exists, clears `is_frozen`, and warns about capitalisations (P6-dom-M7 / sec-M2 / sec-M3, P7-6).
- **WP-C, investments:**
  - `funded` compares tagged baseline lines with the BAC (P5-dom-M4, P7-11). Gate decisions re-check the auto criteria (P5-dom-M6). The delivered payload reads `actual_end` (P5-dom-L9).
  - Benefit control fields frozen after approval, and no readings on closed investments (P5-dom-M7 / sec-M4, P7-14).
  - EVM edge cases (P5-dom-M8, P7-12). An actual tagged to one investment is not counted via another's line (P5-dom-L4).
  - Sponsor and manager cannot score (P5-dom-M14, P7-13). Business-case input rules (P5-sec-L5). Causer on business-case rejection and withdrawal (P5-sec-L6).
- **WP-D, FinOps, allocation, capitalisation, KRIs:**
  - Allocation rules validate only the dates that change (P6-dom-M6).
  - Restatement preserves manual attributions, is scoped to the same export and is audited with before/after totals (P6-dom-M8 / sec-M6).
  - `KRI-FIN-CLD` counts distinct (account, day) events (P6-dom-M9).
  - Tag rules, account creation, anomaly actions and batch detail are cost-centre scoped (P6-dom-M11 / sec-M1 / L3 / L9 / dom-L15). The anomaly sweeper notifies every unclaimed anomaly, isolated per account.
  - Straight-line amortisation capped at the remaining room (P6-dom-L2).
  - No amounts in capitalisation activity descriptions, errors or `blocked_reason`; submit and decide need amounts; the SoD snapshot includes the sponsor (P6-dom-M15 / M16, sec-M4 / L4).
  - TCO `unattributed` ≥ 0 with a reconciling `finops_excess` line (P7-17).
  - `FinanceKriSource::ensureDefinitions` provisions the finance KRIs for every bank (P6-dom-L8, P7-18).
  - Chargeback downloads are audited (P6-dom-L10). The capitalisation index is paginated (P6-sec-M10).
  - The showback reconciliation test now compares against an independent sum (P6-dom-M1). Hard-coded 2026 dates in the FinOps seeder are relative to the seeded FY.
- **WP-E, masking of activity and dashboard:** activity rows on Budget, Expense, Invoice, PO, Investment and Contract pages drop `changes` / `properties` for viewers without amounts (P2-sec-L5, P5-sec-L10, P6-dom-M15, P7-16). `investments_by_stage.bac` and the EVM keys are masked on the dashboard (P6-sec-M9).

**QA (plan §3 QA and §4)**
- `FinanceNotificationsTest` (P1-dom-M8): all 16 notification classes render mail and database payloads without amounts for a no-amounts recipient.
- `FinanceRoutePermissionSweepTest` (H1): every `finance.*` route is gated, with an explicit `GATED_IN_SERVICE` list of the 11 service-gated routes.
- `FinanceAuditTrailTest` (H4): create, update and approve on each process subject write an `it-finance` activity row with a causer.
- `FinanceMaskingSweepTest` (H2): view-only user sees no amounts on any finance page, KRI, board-pack section or export.
- `RouteSmokeTest`: every finance GET route is visited (H5), view-only masking is asserted on the seeded estate, and the 60-query budget per page is enforced (H3).
- `tests/Playwright/specs/finance-budget.spec.js` (H6): create a draft budget, add a line, submit, approve and baseline. The workflow is Head of IT → CFO → CIO (→ MD above the board threshold); the spec was fixed to match.

**H3 query work (orchestrator, post-QA).** New `RequestMemo`: a per-request GET memo, cleared on terminate, whose keys always include the user, organisation and cost-centre ids. Counts are Kano view-only (the worst case, with the full estate), before → after; the budget is 60 per page, enforced in `RouteSmokeTest`.

| page | before | after |
|---|---|---|
| actuals.index | 593 | 35 |
| dashboard | 512 | 71 (FBN 576 → 73; allow-listed at 80) |
| monitor.bva | 371 | 36 |
| transfers | 212 | 27 |
| supplementary | 204 | 25 |
| forecast | 188 | 26 |
| expenses | 152 | 29 |
| recurring | 150 | 38 |
| settings | 112 | 35 |
| purchase-orders | 106 | 12 |
| accruals | 87 | 29 |
| invoices | 78 | 35 |
| contracts | 77 | 16 |
| tco | 64 (FBN 218) | 37 (FBN 45) |
| showback | 83 | 33 |

**H7 scheduler.** 10 `fin:*` schedules plus the shared `reports:run-due` (which also runs the scheduled finance reports). All are `withoutOverlapping`, the Lagos-time ones carry `Africa/Lagos`, and each command ran clean once on the scratch database. Entries and times are under Administrator notes.

**H8 driver-agnostic and seed.** No `ilike`, raw `ALTER` or `SHOW` in any finance migration or seeder (grep), and `migrate:fresh --seed` prints no "finance … seed skipped" warning for either bank.

### Decisions

P7-1 … P7-19 as in the plan §0, plus the orchestrator's entries below.

| # | decision |
|---|---|
| P7-1 | **Emergency spend (P3 L13): no automatic DoA escalation.** Emergency requests keep the mandatory justification, the `emergency` audit flag and the UnbudgetedSpend notification. A bank that wants an extra stage adds one on the existing `emergency = yes` condition. Unbudgeted spend is already escalated by `effective_band`. |
| P7-2 | **Reassigning sponsor, manager, cost centre or rgt after approval is closed as already resolved** (C168, investment change kind `reassign`, always through the change gate). |
| P7-3 | **Job-title approver matching for finance processes is segment-exact.** The title is split on `/ , & ;` and the word "and", each part is trimmed, and a user matches when any part equals the stage's `approver_ref`, case-insensitively. Orchestrator ruling: titles match exactly per segment, so "Head of IT Operations" does **not** qualify for a "Head of IT" stage and "Deputy Managing Director" does not qualify for "Managing Director"; "Managing Director/CEO" does. Demo `headofit@` is retitled "Head of IT". Enforced in `FinanceApprovals`, not in the shared `ApprovalEngine` (see backlog). |
| P7-4 | Cross-legal-entity virements are refused (a transfer whose lines have different non-null `legal_entity_id`). That is an intercompany recharge, not a virement. |
| P7-5 | Period close needs red variance notes **acknowledged**, not only explained. Amber notes still need only an explanation (or the config flag). |
| P7-6 | Re-open is refused while a non-voided chargeback run exists for the period (void it first, which needs `close it-finance-periods` with SoD). On approval, `is_frozen` is cleared on the period's allocation results. Capitalisations that consumed the period's actuals appear as a warning item. |
| P7-7 | Received-not-invoiced accruals convert at the period-end `closing` rate (falling back to `spot`), not the PO rate. Reversals keep the accrual's own rate. |
| P7-8 | Prepayment releases in a later fiscal year post to the successor of the invoice's line (by `line_key`); with no successor they post with `budget_line_id = null` and land in the unbudgeted queue. |
| P7-9 | GL duplicate key = reference + vendor + transaction amount + currency within a date window (see F3 below, superseding the plan's exact `posted_on`). A reference match with a different amount is flagged `possible_duplicate`, never excluded. |
| P7-10 | Direct invoices against an approved expense request are capped at the request total plus the bank's match tolerance. |
| P7-11 | Investment `funded` = Σ current base amount of tagged baseline lines ≥ BAC (or the draft business-case one-off cost when BAC is null). |
| P7-12 | EVM: once delivered, EV = BAC. With EV = 0 and AC > 0: CPI = 0, EAC = AC + (BAC − EV), health `off_track`. |
| P7-13 | The sponsor and the manager cannot score their own investment. Gate approvers keep bank-wide visibility and may score. |
| P7-14 | Benefit `target_value`, `baseline_value`, `unit` and `due_on` are frozen once the investment has passed `approved` (F4 adds `aggregation`, `type`, `measurement_method`). Correct by cancelling and re-registering, which is audited. Readings are refused on `closed` investments. |
| P7-15 | Reference pickers (contract, investment, vendor, cost centre, user directory) stay bank-wide, titles only. Cross-module pickers (risks, EA applications, business services) are empty without that module's view permission. |
| P7-16 | For a viewer without `view it-finance-amounts`, activity `changes` / `properties` are dropped. Activity descriptions never carry amounts; a description that does is a bug fixed at the write site. |
| P7-17 | TCO `unattributed` below zero is shown as 0 plus a reconciling `finops_excess`; identity: `tco_total + unattributed − finops_excess = it_total`. |
| P7-18 | Finance KRI definitions (`KRI-FIN-VAR`, `-UNB`, `-INV`, `-NOT`, `-CLD`) are provisioned by `FinanceKriSource::ensureDefinitions($orgId)` from `FinanceSettings::ensureDefaults`. |
| P7-19 | Kept as is: `$guarded = []` on finance models (no controller mass-assigns; services whitelist with `Arr::only`); CSV-only workbook (P1-2); FY gaps allowed (overlap refused); no credit notes or approved-invoice reversal; investment API endpoints, FIN-03.11 and the `forecast` allocation type are backlog. |
| P7-20 | (orchestrator) A variance note that was acknowledged while amber, or before an escalation, is re-armed when the variance escalates or drifts by more than `finance.monitoring.variance_reopen_delta_pct` (10 % of the budget) after acknowledgement. |
| P7-21 | (orchestrator) Possible GL duplicates are resolved, not ignored: **Confirm duplicate** or **Not a duplicate** with a reason, the confirmer must differ from the importer, both audited; the close checklist gains `duplicates_resolved`. The window is `finance.actuals.duplicate_window_days` (31). |
| P7-22 | (orchestrator) A person's own `job_title` cannot be edited by themselves, and title changes are audited, because job-title stages depend on it. |
| P7-23 | (orchestrator) Platform finance KRIs: the `code` is immutable, and changing their thresholds needs `manage it-finance-settings`. |

### Review fixes applied (final reviews, Opus, whole module)

| fix | maps to | what changed |
|---|---|---|
| **F1** | security C1 = domain H3 | The platform audit trail is scoped to the user's organisation: new `App\Models\Activity` with `organization_id`, migration `2027_04_03_000001_add_organization_id_to_activity_log` with a backfill, and finance `properties` masked without `view it-finance-amounts`. `AuditTrailTenancyTest`. |
| **F2** | security M1, M2, M3, L1–L4 | `workflows.trail` applies `FinancePolicy::view` and the masked `FinanceApprovals::trail`. Finance KRI code immutable, threshold edits need `manage it-finance-settings`, the "measure now" flash is masked. Subject-row locks in four services, a request lock in `ApprovalEngine`, and a unique `approval_decisions (step_id, user_id)` index (migration `2027_04_03_000002`). `RequestMemo` cleared on terminate; `FinanceSettings` bound as `scoped`; no self `job_title` edit and audited title changes; `canDecide` uses the exact segment rule for finance. `P7F2SecurityHardeningTest`. |
| **F3** | domain H1, H2, M3, L9 (P4) | Variance re-arm on escalation or >10 % drift after acknowledgement; GL duplicate key and the Confirm / Not-a-duplicate flow; close checklist item `duplicates_resolved`; re-open vs chargeback race closed both ways (a chargeback cannot be exported while a re-open is pending; the FBN FinOps seeder now avoids the pending-reopen period); dashboard `unexplained_red` counts open + explained. `P7F3VarianceDuplicateAndReopenTest`. |
| **F4** | domain M2, M4, M5; security L5; low 4 | Direct-invoice cap counts only live statuses, excludes soft-deleted invoices and locks the request. Benefit `aggregation`, `type` and `measurement_method` frozen, null → null no longer counts as a change. `finops_excess` and `finops_untagged_cloud` masked. The PO error no longer prints amounts. `P7F4DirectInvoiceCapTest`, `P7F4BenefitFreezeTest`. |

### Review verdicts

- **Security (Opus, whole module):** **NO-GO** at c7d0cc1 on C1 (platform audit trail cross-tenant and unmasked, present since the initial commit), plus 3 Mediums and 5 Lows. All fixed in F1/F2/F4. Clean areas: 192 parameterised routes tried with the other bank's ids (all 403/404), 284 out-of-scope cost-centre requests with no hits, masking on every finance page, all 281 routes gated, CSV guards, signed ICS feed, sweeps isolated per bank.
- **Domain (Opus, whole module):** **CONDITIONAL GO** with H1, H2 and H3 (= security C1). All fixed in F1/F3. Mediums M2–M5 fixed in F3/F4; M1 and M6 are backlog.
- Findings table: see `docs/build/ATH-FIN-REV-p7.md`.

### Gate (PostgreSQL)

- First gate at c7d0cc1: 950 passed / 10 skipped on PostgreSQL (`it_security_risk_test`, 32 min); `migrate:fresh --seed` + SMOKE `RouteSmokeTest` 10/10 on `it_security_risk_scratch`; `npm run build` passes; Playwright 11/11 (3 finance journeys × desktop/tablet/mobile, plus 2 setups).
- Final gate (after review fixes F1–F4): 983 tests on Postgres (973 passed, 10 skipped; 34 min). The one first-run failure was `FinanceReportsTest::report_figures_finance`, whose expectation was updated for L9 (an explained but unacknowledged red now counts). `migrate:fresh --seed` on scratch exited 0 with no finance warnings, and 0 activity rows have no `organization_id`. SMOKE passed 10/10, with no page over its query budget (busiest: 71 Kano / 73 First Bank, the dashboard). `npm run build` passed, Pint passed on touched files, and Playwright passed 11/11 (3 finance journeys × desktop, tablet and mobile).

### H9: requirement coverage (spec §12)

Every §12 FIN-xx "M" clause maps to at least one passing test except the ones in the next table. Tests are Class::method, `test_` prefix left off.


M clauses with no proof, or only partial proof:

| clause | coverage |
|---|---|
| FIN-07.06 audit-trail export per record or period | Not built. The audit trail is scoped (F1); the export is backlog. |
| FIN-03.15 invoice bulk import | Covered only by the seeded-estate assertion in `RouteSmokeTest`; no PHPUnit test (backlog). |
| FIN-02.13 and FIN-06.06 XLSX | Refused by decision (P1-2, P4-10). A recorded spec deviation. |
| FIN-01.10 dimensions | Proven only at the props level. |
| FIN-04 "close blocked by unexplained red" | The escalation path was unproven; F3 adds the regression test. |
| FIN-06.04 and FIN-07.03 webhook channel | Optional in the spec; not built. |

**S items.** Delivered: FIN-02.07, 03.06, 03.09, 03.10, 03.12, 03.13, 05.10, 05.11, 06.07, 06.08, 06.09. Partial: FIN-02.06 (driver × rate not tested), 02.15 (attachments), 05.08 (links only), 07.04 (digest toggle only), 07.10 (masking only). Deferred: FIN-01.11, 02.08, 02.09, 04.09, 07.08, 07.09.

### H9 table: each M clause and the tests that prove it

| M clause | proving tests |
|---|---|
| FIN-01.01 fiscal calendars | FinanceConfigurationTest::monthly_fiscal_year_has_twelve_contiguous_periods, ::fiscal_years_cannot_overlap_and_only_one_is_current, ::periods_close_in_order_and_lock_only_after_close, ::period_lookup_skips_the_adjustment_period_and_close_order_spans_years |
| FIN-01.02 cost centres | FinanceConfigurationTest::cost_centre_hierarchy_and_csv_import, ::cost_centre_parent_id_string_cannot_create_a_cycle |
| FIN-01.03 TBM | FinanceConfigurationTest::tbm_taxonomy_is_seeded_for_every_new_org, ::only_cost_pools_carry_capex_and_gl_defaults |
| FIN-01.04 GL | FinanceConfigurationTest::gl_codes_are_unique_per_org_only; ActualsImportAndReconciliationTest::auto_match_by_rules_then_cascade_leaves_ambiguous_in_queue |
| FIN-01.05 FX | FinanceConfigurationTest::fx_conversion_stores_rate_and_base_amount, ::fx_inverse_cross_rates_and_amount_rounding; FinanceDoaFxAndCurrencyGuardTest::convert_returns_rate_type_and_rate_date_with_fallback, ::base_currency_cannot_change_once_money_rows_exist |
| FIN-01.06 taxes | FinanceConfigurationTest::nigeria_tax_defaults_are_seeded_and_editable; ProcurementThreeWayMatchTest::vat_and_wht_computation_and_budget_cost |
| FIN-01.07 DoA | FinanceConfigurationTest::doa_bands_are_editable_and_banding_is_correct; FinanceWorkflowStudioTest::amount_band_condition_values_come_from_the_banks_doa_bands; FinanceDoaFxAndCurrencyGuardTest::band_change_needs_a_second_person_and_is_audited, ::future_effective_version_activates_on_its_date_and_remaps_stage_conditions |
| FIN-01.08 variance thresholds | FinanceConfigurationTest::variance_thresholds_validate; MonitoringAndVarianceTest::classify_thresholds (the absolute floor is not per line; see P4 L4) |
| FIN-01.09 renewal policy | FinanceConfigurationTest::renewal_policy_offsets_normalised; ContractNotificationSweepTest::per_contract_offset_override |
| FIN-01.10 dimensions | FinanceConfigurationTest::settings_props_expose_every_dimension_source (props only) |
| FIN-01.12 settings | FinanceConfigurationTest::settings_changes_are_audited; FinancePermissionsTest::settings_page_is_read_only_without_manage; P7BudgetAndApprovalHardeningTest::settings_thresholds_are_null_without_amount_permission |
| §12 FIN-01 close refused while actuals are unposted | PeriodCloseTest::close_refused_while_actuals_unposted |
| FIN-02.01 cycles | BudgetLifecycleTest::cycles_have_types_windows_and_guarded_transitions |
| FIN-02.02 versions and immutable baseline | BudgetLifecycleTest::baseline_is_immutable_and_an_edit_creates_version_two, ::only_one_baseline_per_fiscal_year_and_cost_centre, ::version_diff_reports_added_removed_changed_lines; BudgetDomainReviewRegressionTest::locked_versions_refuse_illegal_status_moves |
| FIN-02.03 targets and ceilings | BudgetWorkbookTest::ceiling_breach_flags_scope_and_lines, ::ceiling_on_a_parent_cost_centre_covers_its_children |
| FIN-02.04 lines and phasing | BudgetWorkbookTest::line_requires_tbm_pool_and_tower_and_defaults_gl_and_capex, ::every_phasing_method_sums_exactly_to_annual, ::custom_phasing_must_sum_to_annual; P7BudgetAndApprovalHardeningTest::spread_never_overshoots_or_goes_negative |
| FIN-02.05 budgeting modes | BudgetLifecycleTest::incremental_from_prior_baseline_and_prior_year_columns, ::incremental_uses_the_current_amount_including_adjustments; ActualsImportAndReconciliationTest::from_actuals_mode_and_py_actual_populate |
| FIN-02.10 virements | BudgetTransferTest::transfer_is_net_zero_and_applied_only_after_approval, ::transfer_cannot_exceed_availability_or_cross_capex_opex, ::requester_cannot_approve_own_transfer; P7BudgetAndApprovalHardeningTest::transfer_between_legal_entities_is_refused |
| FIN-02.11 supplementary | BudgetTransferTest::supplementary_request_lifecycle_creates_resulting_line, ::supplementary_top_up_of_existing_line |
| FIN-02.12 allocation | ShowbackAndAllocationTest::percentage_driver_and_direct_shares_and_the_most_specific_source_wins, ::largest_remainder_rounding_sums_to_the_cent |
| FIN-02.13 workbook (CSV) | BudgetWorkbookTest::workbook_csv_round_trip, ::workbook_import_is_all_or_nothing_with_report, ::bulk_grid_save_validates_per_cell, ::workbook_xlsx_is_refused_with_422 |
| FIN-02.14 submission and approval | BudgetLifecycleTest::draft_submitted_approved_baseline_happy_path_unconfigured, ::withdraw_return_and_reject_paths; FinanceWorkflowStudioTest::budget_runs_through_configured_stages_in_order; Playwright finance-budget.spec.js |
| FIN-02.16 prior-year columns | BudgetLifecycleTest::incremental_from_prior_baseline_and_prior_year_columns; ExpenseServicesSmokeTest::budget_from_actuals_uses_the_prior_year_and_py_actual_follows |
| FIN-03.01 availability | ExpenseApprovalAndAvailabilityTest::availability_is_current_budget_minus_committed_minus_actual, ::block_mode_refuses_over_budget_submission, ::warn_mode_requires_justification_and_flags_over_budget, ::final_approval_rechecks_availability_in_block_mode |
| FIN-03.02 DoA routing and SoD | ExpenseApprovalAndAvailabilityTest::doa_bands_route_stages_and_snapshot_band, ::requester_and_creator_cannot_approve_and_nobody_decides_two_stages; P7BudgetAndApprovalHardeningTest::non_matching_title_cannot_decide_a_job_title_stage |
| FIN-03.03 PO and GRN | ProcurementThreeWayMatchTest::commit_creates_po_with_fx_at_po_date_and_refuses_requester, ::partial_and_full_receipt_derive_statuses_and_refuse_over_receipt |
| FIN-03.04 three-way match | ProcurementThreeWayMatchTest::within_tolerance_auto_matches, ::outside_tolerance_is_variance_and_cannot_submit, ::override_needs_reason_and_adds_finance_controller_stage, ::schedule_pay_dispute_resolve, ::invoice_final_approver_cannot_be_po_approver_above_band, ::invoice_approval_posts_actual_in_invoice_period_once |
| FIN-03.05 actuals and unmatched queue | ActualsImportAndReconciliationTest::auto_match_by_rules_then_cascade_leaves_ambiguous_in_queue, ::manual_match_unmatch_and_remember_as_rule, ::gl_row_duplicating_invoice_actual_is_excluded_from_totals; P7SpendLedgerAndCloseHardeningTest (duplicate key, plus the F3 possible-duplicate tests) |
| FIN-03.07 recurring | ActualsImportAndReconciliationTest::recurring_expense_generates_expected_invoice_on_due_date; ExpenseWorkflowIntegrationTest::recurring_generate_is_idempotent |
| FIN-03.08 unbudgeted and emergency | ExpenseApprovalAndAvailabilityTest::unbudgeted_requires_justification_is_flagged_and_escalates_one_band, ::emergency_requires_justification_and_is_flagged; ExpenseWorkflowIntegrationTest::unbudgeted_submit_notifies_finance_and_head_of_it_once |
| FIN-03.14 evidence hash | ExpenseBackendSmokeTest::documents_are_hashed_in_the_vault_and_hidden_from_it; ContractRenewalLifecycleTest::documents_store_evidence_with_hash |
| FIN-03.15 bulk import | ExpenseApprovalAndAvailabilityTest::bulk_expense_import_preview_then_apply_creates_drafts; ActualsImportAndReconciliationTest::gl_import_preview_does_not_write_and_apply_creates_batch (invoices: smoke test only) |
| FIN-04.01 BvA to the cent | MonitoringAndVarianceTest::bva_reconciles_to_the_cent_for_every_grouping; MonitoringReviewRegressionTest::dashboard_kri_and_board_tie_to_bva_and_ledger |
| FIN-04.02 EAC | MonitoringAndVarianceTest::eac_remaining_budget_and_run_rate, ::manual_override_and_clear; MonitoringReviewRegressionTest::eac_is_floored_at_actuals_and_override_below_is_refused |
| FIN-04.03 thresholds, each alert sent once | MonitoringAndVarianceTest::variance_sweep_notifies_once_per_line_period_severity, ::variance_recipients; MonitoringWorkflowIntegrationTest::variance_listener_sends_once_and_escalation_sends_red_once |
| §12 close blocked by an unexplained red variance | PeriodCloseTest::close_blocked_by_unexplained_red_variance; P7SpendLedgerAndCloseHardeningTest::close_needs_red_variances_acknowledged_by_a_second_person; F3 escalation regression |
| FIN-04.04 burn | MonitoringAndVarianceTest::burn_and_exhaustion |
| FIN-04.05 close, lock, re-open | PeriodCloseTest (all); MonitoringWorkflowIntegrationTest::reopen_through_workflow_studio; P7SpendLedgerAndCloseHardeningTest::reopen_is_refused_while_a_chargeback_is_exported_and_clears_frozen_results |
| FIN-04.06 drill-down | MonitoringAndVarianceTest::drill_path_and_export_totals_match; P7SpendLedgerAndCloseHardeningTest::commitment_items_match_the_committed_total_and_posted_accruals_relieve_a_po |
| FIN-04.07 dashboards | MonitoringBackendSmokeTest::dashboard_props; MonitoringAndVarianceTest::owner_sees_own_cost_centres; FinOpsWorkflowIntegrationTest::dashboard_cloud_metrics_and_masking |
| FIN-04.08 KRIs | MonitoringAndVarianceTest::kri_measure_produces_finance_readings; MonitoringWorkflowIntegrationTest::kri_measure_records_the_four_finance_kris; FinOpsWorkflowIntegrationTest::kri_fin_cld_is_measured; P7FinOpsHardeningTest::finance_kri_definitions_are_provisioned_for_every_bank |
| FIN-04.10 commentary and issues | MonitoringAndVarianceTest::explain_acknowledge_comment_and_raise_issue; MonitoringBackendSmokeTest::explain_acknowledge_comment_and_raise_issue_over_http |
| FIN-04.11 board pack | MonitoringWorkflowIntegrationTest::board_pack_section_and_masking; FinanceReportsTest::report_figures_finance |
| FIN-05.01 portfolio | InvestmentAndBusinessCaseTest::lifecycle_idea_to_closed; InvestmentBackendSmokeTest::pages_render |
| FIN-05.02 NPV, IRR and payback | Unit BusinessCaseCalculatorTest::reference_case, ::irr_null_without_sign_change, ::rate_zero_npv_is_the_sum_of_net_flows, ::payback_beyond_horizon_is_null, ::multiple_sign_changes_flagged |
| FIN-05.03 scoring | InvestmentAndBusinessCaseTest::rank_recomputed_on_score_change, ::weights_sum_and_rescore, ::delivery_risk_is_inverted; P7InvestmentHardeningTest::sponsor_and_manager_cannot_score_their_own_investment |
| FIN-05.04 gates | InvestmentAndBusinessCaseTest::gate_advance_requires_approval, ::gate_criteria_enforced; P7InvestmentHardeningTest::funded_needs_tagged_lines_to_cover_bac, ::decision_rechecks_auto_criteria_and_payload_reads_actual_end |
| FIN-05.05 EVM | InvestmentAndBusinessCaseTest::evm_from_actuals, ::bac_set_on_approval_is_the_one_off_cost; P7InvestmentHardeningTest::evm_edge_cases, ::actual_with_an_investment_is_not_counted_via_another_investments_line |
| FIN-05.06 benefits | BenefitsAndValueReviewTest::realised_pct_updates_on_readings, ::status_derivation_at_due_date; P7InvestmentHardeningTest::benefit_control_fields_freeze_after_approval_and_closed_takes_no_readings |
| FIN-05.07 value reviews | BenefitsAndValueReviewTest::reviews_scheduled_on_delivery, ::sweep_notifies_once; InvestmentWorkflowIntegrationTest::value_review_sweep_sends_once_on_due_and_once_per_overdue_offset_and_rearms_on_reschedule |
| FIN-05.09 portfolio dashboard | InvestmentWorkflowIntegrationTest::dashboard_metrics_and_board_section_are_filled_and_masked |
| FIN-06.01 contract register | ContractRenewalLifecycleTest::register_fields_codes_and_fx; ContractBackendSmokeTest::create_computes_code_term_fx_and_dates |
| FIN-06.02 contract lifecycle | ContractRenewalLifecycleTest::status_derivation_every_path, ::manual_no_decision_notice_passed_then_expired, ::auto_renew_rolls_one_new_term |
| FIN-06.03 renewal windows | ContractRenewalLifecycleTest::dates_computed_on_create_and_recomputed_on_edit; ContractReviewRegressionTest::renewal_window_opens_lead_days_before_the_notice_deadline |
| FIN-06.04 T-minus alerts | ContractNotificationSweepTest::end_series_once_per_offset_to_whole_team, ::notice_series_once_per_offset, ::escalation_at_notice_minus_seven_once, ::recipients_are_exact_and_never_cross_banks |
| FIN-06.05 renewal decision | ContractRenewalLifecycleTest::approved_renewal_executes_successor_linked_both_ways, ::renewal_sod_owner_initiator_and_nobody_decides_two_stages; ExpenseWorkflowIntegrationTest::an_auto_rolled_renewal_raises_one_draft_expense_request; Playwright finance-contract-renewal.spec.js |
| FIN-06.06 calendar | CalendarTest::events_for_every_contract_milestone, ::passed_list_sorted_by_days_overdue_and_cleared_by_decision, ::ics_body_is_valid_and_has_no_amounts_or_other_bank_events; Playwright finance-calendar.spec.js |
| FIN-06.10 vendor spend | ContractBackendSmokeTest::index_vendor_spend_and_vendor_summary_sync; ExpenseBackendSmokeTest::vendor_spend_includes_expense_actuals |
| FIN-07.01 approval processes | FinanceWorkflowStudioTest::defaults_seed_per_process_idempotently_with_band_conditions, ::default_when_unconfigured; FinOpsWorkflowIntegrationTest::capitalisation_defaults_seed_two_stages_and_cfo_stage_is_conditional |
| FIN-07.02 SoD | FinanceWorkflowStudioTest::sod_owner_initiator_and_two_stage_rules, ::zero_step_request_still_enforces_segregation_of_duties; InvestmentWorkflowIntegrationTest::segregation_of_duties_and_one_stage_per_person |
| FIN-07.03 notifications | FinanceNotificationsTest::every_notification_renders_mail_and_database_payloads_without_amounts, ::catalogue_lists_every_notification_class |
| FIN-07.05 unified calendar | CalendarTest::cycle_and_period_milestones; ExpenseWorkflowIntegrationTest::calendar_and_digest_carry_invoices; InvestmentWorkflowIntegrationTest::calendar_returns_investment_events_passed_investments_and_ics |
| FIN-07.06 audit trail | FinanceAuditTrailTest (all); BudgetLifecycleTest::lifecycle_is_activity_logged; AuditTrailTenancyTest (F1). The export is not built. |
| FIN-07.07 reports | FinanceReportsTest::every_report_downloads_in_csv_xlsx_and_pdf, ::xlsx_is_a_valid_workbook_and_pdf_starts_with_the_marker |
| §12 FIN-07 masking | FinanceMaskingSweepTest (all); P7MaskingHardeningTest (all); RouteSmokeTest::finance_amounts_masked_and_query_budget_for_view_only_user |
| Tenancy | FinanceTenancyAndRoutesTest::every_finance_page_renders_for_an_organization_admin, ::records_404_for_another_banks_records, ::a_new_bank_with_no_finance_data_renders_empty_not_errors; FinanceRoutePermissionSweepTest::foreign_tenant_records_are_not_found |

### P4 domain Lows L1–L18

Never itemised in §P4 ("L1–L14" was only a count). The domain reviewer re-derived them from the code. All are backlog except L9 (fixed in F3) and the duplicates part of L16.

| id | finding |
|---|---|
| L1 | BvA columns use different bases. Committed, Available and Forecast are for the full year as of today, while Budget, Actual and Variance follow the selected range. |
| L2 | "YTD" means the whole year before the first period has ended. |
| L3 | "As of" is the last ended period, not the last closed one. The sweep evaluates periods that have ended but are still open, with no grace period. |
| L4 | The absolute variance floor is bank-wide only; FIN-01.08 asks for it per line. |
| L5 | A red note can be explained without a corrective action (APO06.05). |
| L6 | The run-rate EAC ignores phasing, so an annual payment made in P1 is multiplied by 12. |
| L7 | The forecast variance is labelled "vs baseline" but is calculated against the current budget. |
| L8 | Burn and exhaustion dates assume 30.4375-day months. |
| L9 | The dashboard's `unexplained_red` counts only `open` notes, so it disagrees with the close checklist (fixed in F3). |
| L10 | The unbudgeted figure double-counts and is not limited to the fiscal year. |
| L11 | Finance KRI definitions: `-UNB` uses inconsistent numerator and denominator, `-VAR` nets overspends against underspends, and `-NOT` also counts contracts past their end date. |
| L12 | Period 1 can't close while the prior year's adjustment period is open. |
| L13 | Lock has no segregation of duties from close and no checklist. |
| L14 | The re-open approver doesn't see the capitalisation warning. |
| L15 | Reports don't tie out:<br>• the BvA report's totals leave out unallocated actuals<br>• the vendor spend report uses its own definition of commitment<br>• invoice ageing uses today's status and the gross amount |
| L16 | The close checklist has no item for missing closing FX rates or unresolved possible duplicates (the duplicates item was added in F3). `actuals_posted` fails a period that genuinely had no IT actuals. |
| L17 | The total EAC can be lower than the YTD actual when there is unbudgeted spend. |
| L18 | Close reminders assume a Monday–Friday week, one timezone and no holidays. |

### Deferred / logged (not fixed; backlog)

Grouped by theme. Anything not listed here and not in the shipped lists above was either resolved earlier or closed by a decision.

- **Domain Mediums and Lows from the final review:**
  - M1: a prepayment release's successor line is resolved when the release is scheduled, not when it posts.
  - M6: accruals do not store the FX rate type and date, and fall back to the PO rate without saying so.
  - Low 1: IAS 21 detail (expense at the GRN-date rate, liability retranslated at closing); the management-reporting simplification needs documenting.
  - Low 2: EVM actual cost keeps growing after `actual_ends_on`.
  - Low 3: benefit readings are overwritten for a closed period (`updateOrCreate`); keep history.
  - Low 5: legitimate identical in-file GL lines are flagged rather than excluded, and need a review.
  - Low 6: global readiness (one timezone, Monday–Friday week with no holiday calendar, EA TCO comparison only in NGN, KRI owner found by English job title).
  - P4 L1–L18 (list above).
- **Audit and coverage:** FIN-07.06 audit-trail export; PHPUnit test for invoice bulk import; the webhook channel; FIN-02.06, 02.15, 05.08, 07.04, 07.10 partials; FIN-01.11, 02.08, 02.09, 04.09, 07.08, 07.09.
- **Scoping and security leftovers:** settings and Targets have no cost-centre filter beyond the P7 fixes (P0-sec-S9b); reject/return with no pending request skips SoD (P3-sec-L4); recipients not always permission-checked (P4-sec-L8); `$guarded = []` (P7-19); ICS poll writes an activity row per poll; contract upload keeps the client extension; `authorize(): true` on four form requests; unthrottled allocation runs; client-supplied `file_name` on cloud imports.
- **Spend and ledger features:** credit notes and approved-invoice reversal; expense CSV column mapping UI; XLSX workbook; chart-of-accounts depth for IAS 16 / 38 / IFRS 16 (P0→P3 D14); Nigeria WHT / VAT rate verification (see Open questions).
- **Contracts:** fixed_term vs evergreen (P2-dom-L1), digest gaps, documents not encrypted at rest, single-approver deadlock (P2-dom-L7), termination statuses in options and on renewals pages.
- **Investments and value:** closure approval, self-attested delivery, mean-of-ratios benefit aggregation, business-case↔register link, an unconfigured workflow collapsing to one approver (P5-dom-M1/M2/M3/M5/M9/M10/M11/M13); fund line keeps delivered items, NPV sensitivity, board threshold in config, unlimited postponement; manual criteria are self-attested; investment API endpoints; multi-currency business cases.
- **FinOps and allocation:** criteria evidence documents, amortisation lifecycle (cancel, impairment, disposal), hard-coded local assumptions (GL 6302, `CP-CLD`, annualisation `* 12`), FOCUS fidelity, asset cost and life (C158), allocation maker-checker, rule precedence per scope, the recoverable-VAT capitalisation setting has no UI, FIN-03.11 commitment and discount utilisation, the `forecast` allocation type, chargeback tax treatment.
- **Settings and policy:** bank policy in app config rather than per tenant (P1-M5a), FX staleness limit, DoA versions per bank, per-bank bands, cost-centre delegates, TBM v5 labels.
- **Platform defects (outside finance):**
  - `ApprovalEngine` still uses substring job-title matching for non-finance processes (`ApprovalEngine.php:212`). Finance is protected by the wrapper; the platform defect belongs to Change Management.
  - `FinanceApprovalNotifier` recipient selection may still use the substring rule. That affects who is notified only; the server refuses the decision.
  - Listeners registered twice (P1-C35): not affecting finance.

### Spec errata (recorded, spec not edited)

- Spec §6 lists 8 finance processes; there are now **10** (`finance.period_reopen` and `finance.capitalisation_approval` were added, 21 processes in total in Workflow Studio).
- `finance.period_reopen` is decided with `approve it-finance` (P4-6 / L12).
- §14 `finance-budget.spec.js` is delivered in P7. The budget workflow is Head of IT → CFO → CIO (→ MD/CEO at or above the board threshold), not the shorter chain the spec journey assumed.
- XLSX workbook import/export is refused (CSV only) by P1-2 / P4-10: a spec deviation for FIN-02.13 and FIN-06.06.

### Open questions for the user

1. **Re-seeding after 31 Dec 2026.** The demo seeders are anchored on `now()`. In January–March the current FY has few or no closed periods, so many demo records (Jan–Aug actuals, the September GL batch, closed-period variances) would be thin or skipped. If UAT will re-seed then, add a `FINANCE_DEMO_TODAY` anchor in a follow-up.
2. **Nigeria tax rates (WHT 2024 / Nigeria Tax Act 2025) are unverified** (P0→P3 D6/C15). UAT needs tax-counsel sign-off or an on-screen disclaimer before the figures are relied on.

### Administrator notes

**Roles and permissions.** Permissions: the standard actions on `it-finance` (view, create, edit, export and so on), plus `approve it-finance-invoices`, `approve it-finance-investments`, `approve it-finance-contracts`, `close it-finance-periods`, `manage it-finance-settings`, `view it-finance-amounts` and `import it-finance`. Every finance route needs `view it-finance` (except the signed calendar ICS feed); every write needs a second permission.

| role | grants |
|---|---|
| IT Finance Controller | every action on `it-finance` plus all the extras (close, settings, amounts, import, approvals). The only holder of `close it-finance-periods`. |
| Finance Approver | view, export, `approve it-finance`, `-invoices`, `-contracts`, `-investments`, amounts |
| IT Budget Owner | view, create, edit, export, amounts, `view workflows` |
| Procurement Officer | view, create, edit, `import it-finance`, amounts, `view vendors` |
| Investment Sponsor | view, `approve it-finance-investments`, amounts |
| IT Steering Committee | view, `approve it-finance-investments`, amounts |
| Auditor | `view it-finance-amounts` (added to the existing role) |

Super Admin bypasses all gates. Cost-centre visibility (`FinancePolicy`) further limits money records for owners and delegates; bank-wide roles see everything in the bank.

**Workflow Studio processes and default stages.** Ten finance processes are in Workflow Studio. Defaults are created once per process by `FinancePolicySeeder`; a bank that already has a workflow row (even with no stages) is left alone. Amount thresholds come from the bank's DoA bands (`config/finance.php` `approval_defaults`, mapped onto each bank's bands) and can be edited in Workflow Studio. Anything not listed below defaults to one approval.

| process | default stages |
|---|---|
| `finance.budget_approval` | Head of IT review → CFO → CIO → MD/CEO (only from `budget_md_ceo_from`, 250 m) |
| `finance.budget_transfer` | IT Finance Controller review → CFO (if it crosses cost centres) → CIO (from 50 m) |
| `finance.supplementary_budget` | Head of IT review → CFO → CIO (from 50 m) |
| `finance.contract_renewal` | Head of IT review → CFO (when `requires_cfo`: renewal over 10 m or uplift over 10 %) |
| `finance.contract_termination` | Head of IT review → CFO (when `requires_cfo`: high value or after the notice deadline) |
| `finance.expense_approval` | Cost-centre owner endorsement (if there is an endorser) → Head of IT (effective band from 1 m) → CFO (from 10 m) → MD/CEO (from 50 m) |
| `finance.invoice_approval` | Cost-centre owner confirmation (if there is an endorser) → IT Finance Controller (match status variance, override or unmatched) → Finance Approver role (high value, from the invoice SoD band) |
| `finance.business_case_approval` | Head of IT → CFO (when `requires_cfo`) → IT Steering Committee (role) → Board / MD/CEO (when `requires_board`, from 250 m) |
| `finance.period_reopen` | CFO approval |
| `finance.capitalisation_approval` | IT Finance Controller review → CFO (when `requires_cfo`, from 50 m) |

**Job-title rule (P7-3).** Stages of type "job title" match on the user's `job_title` field, split on `/ , & ;` and the word "and", and compared exactly (case-insensitive) per part. "Managing Director/CEO" qualifies for "Managing Director" and for "CEO"; "Head of IT Operations" does **not** qualify for "Head of IT"; "Deputy Managing Director" does not qualify for "Managing Director". A bank whose Head of IT has a longer title must change the stage's approver reference in Workflow Studio, or use a role or named-person stage instead. Users cannot edit their own job title, and every title change is audited. Role, named-person, owner and permission stages are unaffected. Segregation of duties applies on top: the requester, the owner and anyone who already decided a stage cannot decide a later one, and the exclusion list is snapshotted at request time.

**Adding an emergency stage (P7-1).** Emergency requests do not escalate by themselves. To add a stage, open Workflow Studio → Expense request, add a stage with the approver you want and set its condition to `Emergency = Yes`. No code is needed. The justification and the `emergency` flag in the audit trail are mandatory regardless.

**Per-tenant thresholds (Finance → Settings; need `manage it-finance-settings`).** Variance amber/red percentages and absolute floor; renewal lead days and notice offsets; escalation role; over-budget mode (warn or block); three-way match tolerance (percent and absolute); EAC method; showback on/off; digest hour; FX provider; DoA bands (versioned, with a second-person activation and a future effective date); investment score weights; CFO thresholds for renewals, invoice SoD, capitalisation and business-case board; cloud anomaly and FinOps thresholds. Users without `view it-finance-amounts` see these values as "—".

**Global keys in `config/finance.php` (not per tenant).** Timezone (`Africa/Lagos`); tax types and the starter chart of accounts; currency catalogue; FX rate types and fallbacks; phasing seasonal weights; `transfers.allow_capex_opex`; `money_tables`; workbook, actuals and report row limits; `actuals.duplicate_window_days` (31); `monitoring.variance_reopen_delta_pct` (10), `monitoring.explain_amber_at_close`, `monitoring.allow_early_close`, `monitoring.close_reminder_working_days`; `masked_kri_codes`; contract catch-up and escalation job titles; investment gate criteria, health thresholds and IRR settings; cloud formats and limits; capitalisation checklists and default lives. Changing them needs a deploy.

**Scheduler (all `withoutOverlapping`).**

| schedule name | command | when |
|---|---|---|
| `finance.doa-activate` | `fin:doa-activate` | daily 00:05 |
| `finance.recurring-generate` | `fin:recurring-generate` | daily 00:30 Lagos |
| `finance.cloud-anomalies` | `fin:cloud-anomalies` | daily 05:45 Lagos |
| `finance.contracts-sweep` | `fin:contracts-sweep` | daily 06:30 Lagos |
| `finance.variances-sweep` | `fin:variances-sweep` | daily 07:00 Lagos |
| `finance.invoices-sweep` | `fin:invoices-sweep` | daily 07:30 Lagos |
| `finance.value-reviews-sweep` | `fin:value-reviews-sweep` | daily 07:45 Lagos |
| `finance.period-reminders` | `fin:period-reminders` | daily 08:00 Lagos |
| `finance.approvals-sweep` | `fin:approvals-sweep` | hourly |
| `finance.digest` | `fin:digest` | hourly (sends at each bank's digest hour) |
| `reports.run-due` (shared) | `reports:run-due` | daily; runs scheduled finance reports as the user who scheduled them, and refuses if that user lost view or export |

Date-taking commands accept `--date=Y-m-d` to run as if on that day, which is how UAT tests the alert series. Each sweep runs per bank inside a try/catch, so one bank's failure does not stop the others. Production needs the Laravel scheduler (`schedule:run` every minute) and a queue worker.

**KRI provisioning (P7-18).** `KRI-FIN-VAR`, `-UNB`, `-INV`, `-NOT` and `-CLD` are created for a bank the first time its finance settings are initialised (`FinanceSettings::ensureDefaults` → `FinanceKriSource::ensureDefinitions`), owned by the CFO when one exists. The code is immutable. Thresholds can be changed only by users with `manage it-finance-settings`. `-VAR` and `-UNB` are masked for viewers without amounts.

**Amount masking.** Amounts are removed on the server, not hidden in the browser, for any user without `view it-finance-amounts`: page props, activity `changes`, the approval trail's `requires_cfo` / `requires_board`, the platform audit trail's finance `properties`, masked KRIs, board-pack sections, notifications and mail, and error messages. Reports, calendar CSV/ICS and chargeback files are refused or amount-free. Grant the permission only to people who may see money; the Auditor role already holds it.

**Period close and re-open.**
- Close is blocked by the checklist: actuals unposted, red variance notes not acknowledged (explained is not enough, P7-5), possible GL duplicates unresolved (F3), plus the existing items. Acknowledging needs a different person from the author of the explanation.
- A variance note that was already acknowledged is re-armed when the variance escalates (amber → red) or moves by more than `monitoring.variance_reopen_delta_pct` of the budget (P7-20), including after a re-open and new postings.
- Possible duplicates: a GL row with the same reference, vendor, transaction amount and currency as an earlier actual within ±31 days is held as `possible_duplicate`. A reviewer who did not import the batch chooses Confirm duplicate (excluded) or Not a duplicate (kept), with a reason; both are audited.
- Re-open is a workflow request. It is refused while a non-voided chargeback exists for the period (void it first; the exporter cannot void), and a chargeback cannot be exported while a re-open is pending. On approval the period's allocation results are un-frozen so the close recomputes. Capitalisations that used the period's actuals show as a warning.
- Variance notes on closed or locked periods are frozen.

**Tax caveat.** The VAT 7.5 % and WHT 5 % / 10 % rates, and the services/supplies split, are unverified against the WHT regulations of 2024 and the Nigeria Tax Act 2025 (P0→P3 D6/C15). They are editable per bank in Finance settings. Obtain tax-counsel sign-off, or show a disclaimer, before UAT figures are relied on.
