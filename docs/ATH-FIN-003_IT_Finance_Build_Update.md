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
