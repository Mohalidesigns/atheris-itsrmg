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
