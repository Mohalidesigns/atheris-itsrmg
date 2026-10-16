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
