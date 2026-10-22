# ATH-FIN-PLAN-p5: IT Financial Management, Phase P5 (IT investment and value realisation)

**Spec:** ATH-FIN-001 §3.5 FIN-05.01–.09 and .11 (.10 Application TCO deferred to P6, C142); §4.5; §5.5; §6 `finance.business_case_approval`; §7 `ValueReviewDueNotification`, `fin:value-reviews-sweep`; §3.7 FIN-07.05 (investment events in the calendar) and FIN-07.07 (investment portfolio and benefits reports); §5.7 `investments_by_stage`, `benefits_realised_pct`; §9 Investments routes; §10 `Investments/{Index,Create,Show,Portfolio}.jsx`; §12 FIN-05 plus the FIN-07 items P5 touches.
**Branch:** `feature/it-finance-p5`, from P4 5ad7b14.
**P4 carry-overs folded in:** security **M2** (finance KRI values and stored board-pack content unmasked) and domain **M5** (forecast snapshots ignore overrides and are replaced on re-close), both in WP-B. Security **L8** (recipients not permission-checked) is applied to the new P5 notification only. The rest stay deferred (§11).

**Order and parallelism:**
1. **WP-A backend core** and **WP-C frontend** start together. WP-C builds against §7 (props contract) and §2.7 (route names) only; Ziggy names resolve at runtime, and `npm run build` needs no backend.
2. **WP-B workflow and integrations** starts when WP-A reports green (it needs A's models, `StageGateService`, `ValueReviewService`, `InvestmentFinancials`, `PortfolioService` and the `ApprovalProcesses` entry).
3. **WP-E QA** starts after A, B and C.
File sets in §8 do not overlap.

**DB rule (overrides every command):** never touch `it_security_risk`.
- WP-A, then WP-B (sequential): `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_fin_test php vendor/bin/phpunit --filter '<Classes>'`.
- WP-E: `it_security_risk_fin_qa`. Reviewers: throwaway `it_security_risk_<agent>_test` (createdb/dropdb at `/opt/homebrew/opt/postgresql@17/bin`).
- Orchestrator only: suite on `it_security_risk_test`; seed and SMOKE on `it_security_risk_scratch`.
- WP-C: `npm run build` only. Pint only on PHP files you touched.

**Route collision check (done, read-only):** `route:list --except-vendor --json` = 895 routes, 208 `finance.*`. No name or URI contains invest, business-case, benefit, value-review, portfolio, gate or investment-change. **No collisions.**

**State found in code (verified):**
- `fin_budget_lines.investment_id`, `fin_expense_requests.investment_id`, `fin_actuals.investment_id` exist as plain nullable bigints (no FK). `BudgetService` line ~388 refuses any `investment_id` ("Investments can be linked from a later phase"). `PresentsBudgets::options` returns `'investments' => []`. `LineFormModal.jsx` has no investment picker.
- `fin_settings.investment_score_weights` (json, nullable) exists; `config('finance.settings_defaults.investment_score_weights')` = 25/25/20/20/10. Settings props pass it; **no tab edits it** and `Setting::SECTIONS = ['general','doa','variance','renewal']`.
- `ContractLink::TYPES` = asset, ea_application, business_service, risk ("investments join in P5, plan C53"). Contract Show `linkOptions` and `PresentsContracts::optionsPayload()['linkTypes']` drive `LinksPanel.jsx` generically.
- `FinanceDocument::subjects()` = expense, purchase_order, goods_receipt, invoice. `EvidenceController::financeSubjectTypes()` hides every class in it from the vault, so adding subjects hides them automatically. `FinanceDocumentController::store` maps subject → Gate ability by a ternary (must be extended).
- Permission `approve it-finance-investments` is held by IT Finance Controller, Investment Sponsor and IT Steering Committee only. **Finance Approver does not hold it**, so `headofit@` (Finance Approver only) could not decide a Head of IT stage nor see it in the inbox (C131).
- Demo users per bank: finance@ (IT Finance Controller), procurement@, cfo@ (Finance Approver, Investment Sponsor, IT Steering Committee; title "Chief Financial Officer"), cio@ (Finance Approver, IT Steering Committee), md@ ("Managing Director/CEO"; Finance Approver, IT Steering Committee), headofit@ ("Head of IT Governance"; Finance Approver). Cost-centre owners hold IT Budget Owner. There is **no Board role** (C130).
- `ApprovalEngine`: `job_title` matches by substring; "nobody decides two stages" and `context.sod_excluded_ids` are enforced in `assertEligible`; the engine does not check the process permission (the inbox, notifier and finance services do). `start()` cancels any pending request on the same subject ("Restarted").
- Code prefixes in use: SUP, BC, PO, TRM, EXP, ALC, **INV (invoices)**, VIR, REN, BUD, REC, GRN, CON. Investments therefore use **`IVT-`**; benefits `BEN-`; investment changes `ICR-` (Change Management owns `CHG`). Seeders write the first code as `{KHB|FBN}-IVT-0001`, and `GeneratesSequentialCode` continues the bank's prefix.
- `FinanceMetrics::summary` returns `investments_by_stage => []`, `benefits_realised_pct => null`. `BoardPackController::maskFinance` masks only `it_financial_position`, only on `show`. `kri_panel` board section, `KriController::index/show` and `DashboardController` (KRI widget) show `KRI-FIN-*` values to anyone.
- `ForecastService::snapshot()` deletes the period's earlier snapshots and stores the computed EAC of the settings method, ignoring an active manual override (P4 domain M5). `MonitorController` (~line 132) reads snapshots for the EAC history.
- Calendar: `FinanceCalendarService::SOURCES` = contracts, budget, periods, invoices; `passed()` returns contract-shaped rows only.
- Process counts: 19 total / 8 finance. Asserted in `WorkflowStudioModuleTest:93`, `FinanceWorkflowStudioTest:67,89,196,204,208`, `RouteSmokeTest:263`. Reports: 8, asserted in `RouteSmokeTest:589`. P5 makes it **20 / 9** processes and **10** reports.
- EA `Capability`, `EaApplication` use `BelongsToTenant` (org or NULL). Change Management's record is `App\Models\Change\ChangeRequest`.

---

## 0. Decisions (P5-1 … P5-14). Orchestrator records them in ATH-FIN-003. C-items with reasons in §10.

| # | decision | sign-off? |
|---|---|---|
| P5-1 | **BAC (C126).** `fin_investments.bac` = the **approved** budget at completion: the approved business case's one-off cost (Σ capex + opex over the horizon, excluding run cost), set when the `approved` gate passes, changed only by an applied investment change. Budget lines tagged with the investment are shown as **allocated** (Σ current baseline amount, all FYs) with `funding_gap = bac − allocated`. The spec's "BAC from allocated lines" becomes "allocated vs BAC". | **yes** |
| P5-2 | **Earned value (C127).** The spec gives no EV source. EV = BAC × `percent_complete` (manager-reported, 0–100, with an as-of date). CV = EV − AC; CPI = EV / AC (null when AC = 0); EAC = BAC / CPI when CPI > 0, else BAC; a manual EAC override (with a reason ≥ 20 chars) replaces it; VAC = BAC − EAC. | **yes** |
| P5-3 | **AC and committed.** AC = life-to-date counted actuals (`match_status ≠ duplicate`, base) where `investment_id = X` **or** `budget_line_id` is in the lineage of a line tagged X; each actual counted once. Committed = `BudgetAvailability::committed(lineIds)` (the availability definition; do not use the P4 commitments-report definition, see deferred M3). | |
| P5-4 | **Approval subject = `StageGate` (C128).** One `fin_stage_gates` row per gate request (as P4 used `PeriodClose`). Approval gates (config `approval_gates`): `approved` (business case) and `delivered` (delivery acceptance). Over-tolerance investment changes ride the same process as gate `change`. Other transitions write a `passed` gate row with the checklist and no approval. | **yes** |
| P5-5 | **Default stages.** 10 Head of IT (job title, all gates) → 20 CFO (`requires_cfo` = gate in approved/change) → 30 IT Steering Committee (role, all gates) → 40 Board, decided by job title "Managing Director" (`requires_board` = gate in approved/change and amount ≥ `business_case_board_from` 250m). | **yes** (C130) |
| P5-6 | **Grant `approve it-finance-investments` to Finance Approver** (migration + `RolesAndPermissionsSeeder`), so the Head of IT and CFO stages have deciders in the inbox and service. | **yes** (C131) |
| P5-7 | **SoD.** Excluded from deciding a gate: requester, gate creator, investment manager, investment sponsor, and the business case's preparer (`created_by_id`). Snapshotted as `sod_excluded_ids`. `owner_cannot_approve` with owner = sponsor. | |
| P5-8 | **Business case lifecycle.** Versions; only one draft or submitted version at a time; only in stage `idea`/`business_case`. Submitting = requesting the `approved` gate. Approved is immutable; a later approval supersedes the earlier. After `approved`, cost and schedule change only through investment changes. Business cases are decided on the gate routes (no `business-cases.approve/reject`, C129). | |
| P5-9 | **Calculator conventions (C132).** All business-case money is **base currency**. Rate is a percentage. Year 0 = today (undiscounted). NPV = Σ (benefit_t − cost_t)/(1+r)^t, t = 0..H, bcmath scale 12. IRR by Newton–Raphson on floats (guess 0.10, ≤ 100 iterations, tol 1e-10), bisection fallback on [−0.99, 10]; **null when the cash flows have no sign change**; `irr_multiple = true` when they change sign more than once. Payback = undiscounted cumulative, linear within the year, **ceil** to whole months, null if not within horizon; also discounted payback. ROI = (Σ benefit − Σ cost)/Σ cost × 100 (null if cost 0). TCO = Σ capex + opex + run, undiscounted. Reference: cost 1,000,000 at year 0, 300,000/yr years 1–5, 10 % → NPV 137,236.03; IRR 15.24 %; payback 40 months; ROI 50 %. | |
| P5-10 | **Scoring.** Five criteria scored 1–5 where 5 is most favourable; `delivery_risk` is entered as risk (5 = high risk) and **inverted** (6 − score). `priority_score` = Σ (effective/5 × weight), 0–100, 2 dp. Weights snapshotted per score row; a settings weight change rescores open investments. `rank(orgId, fyId)` orders open investments (not closed/cancelled) of the funding FY by score desc, then regulatory first, planned cost asc, id. | |
| P5-11 | **Fund line.** Envelope default = Σ current baseline amount of the FY's lines with `rgt ∈ {grow, transform}` (change-the-bank budget); `?envelope=` overrides for what-if (not stored). Ranked investments are "above the line" while cumulative planned cost ≤ envelope. | |
| P5-12 | **Benefits.** `aggregation` = `sum` (currency/hours/count: realised = Σ readings, pct = realised/target) or `latest` (pct/score: pct = (latest − baseline)/(target − baseline)). One reading per (benefit, period), re-recording replaces; refused in **locked** periods (readings are measurements, closed periods allowed). Status: `planned` → `tracking` on first reading; on/after `due_on`: ≥ 100 % `realised`, > 0 `partially_realised`, else `not_realised`. Investment `benefits_realised_pct` = mean of non-cancelled benefits' pct, each capped at 100. | |
| P5-13 | **Value reviews.** On final `delivered` approval: `pir_3m/6m/12m` at actual_ends_on + 3/6/12 months, reviewer = manager (editable), status `scheduled`. Sweep: `due` from due_on − lead (14 days), `overdue` after due_on. Notification once on `due` (key `value_review_due:{due_on}`) and at overdue offsets [1, 7, 14, 30] (key `value_review_overdue:{due_on}`); a reschedule re-arms. Complete stores `benefits_realised_pct` (computed) and `cost_outcome` (EAC vs BAC ±5 %). | |
| P5-14 | **Masking.** Without `view it-finance-amounts`: every money value, NPV, IRR, ROI, payback, CPI, CV, EAC, VAC, band label, currency-benefit values and the business-case cost/benefit arrays are null. Stage, status, health, priority score, rank, counts and non-currency benefit figures stay visible. `finance.business-cases.evaluate` returns 403 to such users. | |

---

## 1. Conventions to copy (same as P1–P4)

- **Models:** `use FinanceRecord;` (+ `SoftDeletes`, `GeneratesSequentialCode` where noted), explicit `$table`, `$guarded = []`, enum `const`s, casts `decimal:2`/`date`/`array`/`boolean`.
- **Services:** `public const TRANSITIONS = ['action' => ['from' => [...], 'to' => '...']]`; refusals via `LogsFinanceEvents::fail($msg, $key)`; named audit via `audit()`. Money bcmath strings scale 2. Today = `FinanceClock::today()`.
- **Approvals:** only through `FinanceApprovals`; copy the `TerminationService::approve/reject/withdraw` skeleton exactly (`assertSubmitted` → `assertApprover` → in a transaction `lockForUpdate` → `assertZeroStepSoD` → unconfigured `assertDefaultApprover` else `approve()` returning the stage message → final effects → `finalised()`).
- **Unauthenticated work:** `withoutGlobalScopes()->where('organization_id', …)`, per-tenant try/catch + `report($e)`.
- **Idempotent sends:** `ContractSweeper::claim($subject, $key, $offset, $users)`; key ≤ 64 chars; include the target date in the key.
- **Masking:** new self-contained trait `Concerns/PresentsInvestments` (do not combine with other `Presents*` traits; their method names collide).
- **Seeders:** `attempt()` must **log** swallowed exceptions (`$this->command?->warn`), copy `FinanceMonitoringDemoSeeder::attempt/whilePeriodsOpen`. No Postgres-only SQL (`ilike`): use `whereRaw('LOWER(col) LIKE ?')`.
- **UI:** MD3-in-Tailwind, `Components/Material/{Icon,Chip}`, `PageHeader`, navy `#0A1F44` / gold `#C9A86A`, recharts, `Components/Finance/Budgets/Money.jsx`, `Components/Approvals/ApprovalTrail.jsx`. No MUI, no `rounded-full` pills, no heroicons; usable at 375 px. **Never chain `.post()`/`.put()` onto `form.transform()`.**

---

## 2. WP-A: backend core (fin-backend-engineer, DB `it_security_risk_fin_test`)

### 2.1 `config/finance.php` (append)
```php
'investments' => [
    'approval_gates' => ['approved', 'delivered'],          // gates routed through finance.business_case_approval ('change' always, when over tolerance)
    'default_discount_rate_pct' => 15,                      // demo hurdle rate, unverified; fin_settings overrides
    'default_horizon_years' => 5, 'max_horizon_years' => 15,
    'default_cost_tolerance_pct' => 10, 'schedule_tolerance_days' => 30,
    'value_review_offsets_months' => ['pir_3m' => 3, 'pir_6m' => 6, 'pir_12m' => 12],
    'value_review_lead_days' => 14, 'value_review_overdue_offsets' => [1, 7, 14, 30],
    'cost_outcome_band_pct' => 5,
    'health' => ['cpi_at_risk' => '0.95', 'cpi_off_track' => '0.85'],
    'reason_min' => 20,
    'irr' => ['guess' => 0.10, 'max_iterations' => 100, 'tolerance' => 1e-10, 'low' => -0.99, 'high' => 10.0],
    'gate_criteria' => [ // [key, label, auto]; auto keys are evaluated by StageGateService::criteria(); manual keys must be ticked
        'business_case' => [['sponsor_named', 'Sponsor named', true], ['manager_named', 'Manager named', true], ['cost_centre_set', 'Cost centre set', true], ['problem_agreed', 'Problem statement and options agreed with the sponsor', false]],
        'approved' => [['business_case_complete', 'Business case complete (costs, horizon, rate)', true], ['scored', 'All five criteria scored', true], ['risks_recorded', 'Risks recorded (summary or linked risk)', true], ['regulatory_reference', 'Regulatory reference (regulatory investments)', true], ['options_considered', 'Options considered and recommendation stated', false]],
        'in_delivery' => [['funded', 'Funded: at least one baseline budget line tagged', true], ['start_date', 'Start date set', true], ['delivery_plan', 'Delivery plan and governance agreed', false]],
        'delivered' => [['actual_end', 'Actual end date given', true], ['acceptance', 'User acceptance signed off', false], ['handover', 'Handed over to operations', false]],
        'benefits_tracking' => [['benefits_registered', 'At least one benefit with owner, target and due date', true], ['reviews_scheduled', 'Value reviews scheduled', true]],
        'closed' => [['reviews_done', 'No value review scheduled, due or overdue', true], ['benefits_final', 'Every benefit has a final status', true], ['lessons', 'Lessons learned recorded', false]],
    ],
],
```
Add to `approval_defaults`: `'business_case_board_from' => 250000000`. Add `'masked_kri_codes' => ['KRI-FIN-VAR', 'KRI-FIN-UNB']` at top level (WP-B uses it).

### 2.2 Migrations
Schema builder only, `$this->base($t)` as P4 (`id`, indexed `organization_id`, `created_by_id` FK users nullOnDelete), timestamps on all. `down()` drops in reverse.

**`database/migrations/2027_03_20_000001_create_it_finance_investment_tables.php`** — create in this order:

| table | columns | indexes / FKs |
|---|---|---|
| `fin_investments` | base; `investment_code` s32; `title` s200; `type` s16; `rgt` s12; `driver` s24; `sponsor_id`, `manager_id` FK users null nullOnDelete; `cost_centre_id` FK fin_cost_centres null nullOnDelete; `legal_entity_id` FK legal_entities null nullOnDelete; `fiscal_year_id` FK fin_fiscal_years null nullOnDelete (funding FY); `stage` s20 default `idea`; `status` s12 default `on_track`; `status_before_hold` s12 null; `starts_on`, `planned_ends_on`, `actual_ends_on` date null; `description`, `strategic_objective` text null; `planned_cost` dec(18,2) null; `bac` dec(18,2) null; `bac_baselined_at` ts null; `eac` dec(18,2) null (manual override); `eac_reason` text null; `percent_complete` dec(5,2) default 0; `percent_complete_as_of` date null; `cost_tolerance_pct` dec(5,2) default 10; `priority_score` dec(6,2) null; `priority_rank` uint null; `is_regulatory` bool default false; `regulatory_reference` s200 null; `value_review_dates` json null; `external_reference` s100 null; `cancelled_at` ts null; `cancelled_by_id` FK users null; `cancel_reason` text null; softDeletes | unique(organization_id, investment_code); idx(org, stage); idx(org, fiscal_year_id, priority_rank); idx(org, cost_centre_id) |
| `fin_business_cases` | base; `investment_id` FK cascade; `version_no` usmallint; `status` s12 default `draft`; `horizon_years` usmallint; `discount_rate_pct` dec(6,3); `option_summary` text null; `recommended_option` s200 null; `costs` json; `benefits` json; `non_financial_benefits` json null; `npv` dec(18,2) null; `irr_pct` dec(9,4) null; `irr_multiple` bool default false; `payback_months`, `discounted_payback_months` int null; `roi_pct` dec(10,2) null; `tco`, `one_off_cost`, `total_benefit` dec(18,2) default 0; `assumptions`, `risks_summary` text null; `submitted_by_id` FK users null; `submitted_at` ts null; `approved_by_id` FK users null; `approved_at` ts null; `rejection_reason` text null; softDeletes | unique(investment_id, version_no); idx(org, status) |
| `fin_investment_scores` | base; `investment_id` FK cascade; `criterion` s24; `score` utinyint; `weight_pct` dec(5,2); `weighted` dec(6,2); `note` s500 null; `scored_by_id` FK users null | unique(investment_id, criterion) |
| `fin_investment_changes` | base; `investment_id` FK cascade; `change_code` s32; `description` text; `reason` text null; `cost_delta` dec(18,2) default 0; `schedule_delta_days` int default 0; `status` s12 (`submitted`,`applied`,`rejected`,`withdrawn`); `requires_approval` bool; `tolerance_snapshot` json null; `requested_by_id` FK users null; `requested_at` ts; `decided_by_id` FK users null; `decided_at` ts null; `rejection_reason` text null; `applied_at` ts null; `approval_request_id` FK approval_requests null nullOnDelete; softDeletes | unique(org, change_code); idx(investment_id, status) |
| `fin_stage_gates` | base; `investment_id` FK cascade; `gate` s20 (target stage or `change`); `from_stage` s20; `status` s12 (`submitted`,`approved`,`rejected`,`withdrawn`,`passed`); `criteria` json; `payload` json null (e.g. `actual_ends_on`); `business_case_id` FK fin_business_cases null nullOnDelete; `investment_change_id` FK fin_investment_changes null nullOnDelete; `approval_base_amount` dec(18,2) null; `requested_by_id` FK users null; `requested_at` ts; `request_note` text null; `passed_at` ts null; `passed_by_id` FK users null; `decided_by_id` FK users null; `decided_at` ts null; `rejection_reason` text null; `approval_request_id` FK approval_requests null nullOnDelete | idx(org, investment_id, id); idx(org, status) |
| `fin_benefits` | base; `benefit_code` s32; `investment_id` FK cascade; `title` s200; `description` text null; `type` s20; `owner_id` FK users null nullOnDelete; `measurement_method` text null; `baseline_value` dec(18,2) default 0; `target_value` dec(18,2); `unit` s12; `aggregation` s8; `currency` char(3) null; `due_on` date null; `status` s20 default `planned`; `realised_value` dec(18,2) null; `realised_pct` dec(7,2) null; `last_reading_period_id` FK fin_periods null nullOnDelete; `cancel_reason` text null; softDeletes | unique(org, benefit_code); idx(investment_id); idx(org, owner_id) |
| `fin_benefit_readings` | base; `benefit_id` FK cascade; `period_id` FK fin_periods restrict; `value` dec(18,2); `document_id` FK fin_documents null nullOnDelete (spec `evidence_id`, C133); `note` s500 null; `recorded_by_id` FK users null | unique(benefit_id, period_id) |
| `fin_value_reviews` | base; `investment_id` FK cascade; `review_type` s12; `due_on` date; `completed_on` date null; `reviewer_id` FK users null nullOnDelete; `benefits_realised_pct` dec(7,2) null; `cost_outcome` s8 null; `summary`, `lessons` text null; `status` s12 default `scheduled`; `completed_by_id` FK users null; `cancel_reason` text null | idx(org, status, due_on); idx(investment_id) |
| `fin_investment_links` | base; `investment_id` FK cascade; `linkable_type` s100; `linkable_id` ubig; `note` s300 null | unique(investment_id, linkable_type, linkable_id); idx(linkable_type, linkable_id) |

`fin_settings` (guarded with `Schema::hasColumn` as in the P2.1 migration): `investment_discount_rate_pct` dec(6,3) null; `investment_cost_tolerance_pct` dec(5,2) null; `value_review_lead_days` usmallint null. Null falls back to config.

No FK is added to the existing `investment_id` columns (SQLite cannot add FKs in place); the services validate the org.

**`database/migrations/2027_03_20_000002_grant_finance_approver_investment_approval.php`** — copy `2027_02_10_000003_grant_it_finance_contract_approval.php` with `approve it-finance-investments` (P5-6).

### 2.3 Models `app/Models/Finance/` (new)

| model | traits / constants | relations | accessors / methods |
|---|---|---|---|
| `Investment` | FinanceRecord, SoftDeletes, GeneratesSequentialCode; `TYPES = ['programme','project','initiative']`, `RGT = ['run','grow','transform']`, `DRIVERS = ['regulatory','strategic','risk_reduction','efficiency','growth','obsolescence']`, `STAGES = ['idea','business_case','approved','in_delivery','delivered','benefits_tracking','closed','cancelled']`, `OPEN_STAGES` (all but closed, cancelled), `STATUSES = ['on_track','at_risk','off_track','on_hold']`, `CRITERIA = ['strategic_alignment','regulatory','risk_reduction','financial_return','delivery_risk']` | sponsor, manager, costCentre, legalEntity, fiscalYear, businessCases (version desc), currentBusinessCase (latest non-superseded), approvedBusinessCase, scores, gates (id desc), pendingGate (hasOne latestOfMany, status submitted), benefits, valueReviews, changes, links, budgetLines (hasMany BudgetLine `investment_id`), documents (morphMany FinanceDocument) | `label` ("KHB-IVT-0004 Mobile app re-platform"), `is_open`, `days_to_planned_end`, `is_late` (planned_ends_on < today and stage in approved/in_delivery), `involves(User)` (manager, sponsor, creator, benefit owner, reviewer), `scopeOpen`, `scopeForFiscalYear`, `generateNextCode($orgId)` → `nextSequentialCode($orgId,'investment_code','IVT-')` |
| `BusinessCase` | FinanceRecord, SoftDeletes; `STATUSES = ['draft','submitted','approved','rejected','superseded']` | investment, approvedBy, submittedBy | `is_editable` (draft) |
| `InvestmentScore` | FinanceRecord | investment, scoredBy | — |
| `StageGate` | FinanceRecord; `GATES = ['business_case','approved','in_delivery','delivered','benefits_tracking','closed','change']`, `STATUSES = ['submitted','approved','rejected','withdrawn','passed']`; casts criteria/payload array | investment, businessCase, investmentChange, requestedBy, passedBy, decidedBy, approvalRequest | `approvalBaseAmount()` (stored `approval_base_amount`), `getAmountBandAttribute`/`getAmountBandLabelAttribute` (as `Termination`), `getRgtAttribute`, `getIsRegulatoryAttribute` → `'yes'|'no'`, `getRequiresCfoAttribute` (`gate ∈ {approved, change}` → yes), `getRequiresBoardAttribute` (gate ∈ {approved, change} and amount ≥ `FinanceSettings`/config `business_case_board_from` → yes), `getSponsorIdAttribute`, `getGateTitleAttribute` ("KHB-IVT-0004 Mobile app re-platform · Business case approval"), `sodExcludedIds()` (requested_by, created_by, investment manager, sponsor, business case created_by; ints, unique) |
| `Benefit` | FinanceRecord, SoftDeletes, GeneratesSequentialCode (`BEN-`); `TYPES = ['cost_saving','cost_avoidance','revenue','risk_reduction','compliance','productivity','customer']`, `UNITS = ['currency','pct','hours','count','score']`, `AGGREGATIONS = ['sum','latest']`, `STATUSES = ['planned','tracking','realised','partially_realised','not_realised','cancelled']`, `FINAL = ['realised','partially_realised','not_realised','cancelled']` | investment, owner, readings (period sequence asc), lastReadingPeriod | `is_money` (unit currency) |
| `BenefitReading` | FinanceRecord | benefit, period, document (FinanceDocument), recordedBy | — |
| `ValueReview` | FinanceRecord; `TYPES = ['pir_3m','pir_6m','pir_12m','ad_hoc']`, `STATUSES = ['scheduled','due','overdue','completed','cancelled']`, `OPEN = ['scheduled','due','overdue']`, `COST_OUTCOMES = ['under','on','over']` | investment, reviewer, completedBy | `label` ("KHB-IVT-0002 · 3-month review"), `days_to_due` |
| `InvestmentChange` | FinanceRecord, SoftDeletes, GeneratesSequentialCode (`ICR-`); `STATUSES = ['submitted','applied','rejected','withdrawn']` | investment, requestedBy, decidedBy, gate (hasOne StageGate) | — |
| `InvestmentLink` | FinanceRecord; `TYPES = ['ea_application' => EaApplication::class, 'capability' => \App\Models\Ea\Capability::class, 'business_service' => BusinessService::class, 'risk' => Risk::class, 'change' => \App\Models\Change\ChangeRequest::class]` | investment, linkable (morphTo) | `type_key` |

**Edits:** `BudgetLine.php` + `investment()` belongsTo; `Actual.php` + `investment()`; `ContractLink.php` TYPES + `'investment' => Investment::class`; `FinanceDocument.php` `subjects()` + `'investment' => Investment::class, 'business_case' => BusinessCase::class, 'benefit' => Benefit::class, 'value_review' => ValueReview::class`, KINDS + `'business_case','benefit_evidence','pir_report'`; `Setting.php` SECTIONS + `'investments'`, casts for the 3 new columns.

### 2.4 Services `app/Services/Finance/` (new unless marked)

**`BusinessCaseCalculator`** (pure, no DB, no container; P5-9)
```php
/** @param list<array{year:int,capex?:string,opex?:string,run?:string}> $costs @param list<array{year:int,amount:string}> $benefits */
public static function evaluate(array $costs, array $benefits, string|int|float $ratePct, int $horizon): array;
// {cash_flows:[{year,cost,benefit,net,discounted,cumulative,discounted_cumulative}], npv, irr_pct:?string, irr_multiple:bool,
//  payback_months:?int, discounted_payback_months:?int, roi_pct:?string, tco, one_off_cost, total_cost, total_benefit}
public static function npv(array $netFlows, string $ratePct): string;       // bcmath, scale 12, rounded 2
public static function irr(array $netFlows): ?float;                        // NR + bisection; null without a sign change
public static function paybackMonths(array $netFlows): ?int;                // ceil months, null if never
```
Validation (throws `InvalidArgumentException`; the service converts to a 422): years 0..horizon, horizon 1..max, amounts plain decimal ≥ 0, rate 0..100.

**`BusinessCaseService`**
```php
public const TRANSITIONS = ['submit' => ['from' => ['draft'], 'to' => 'submitted'], 'approve' => ['from' => ['submitted'], 'to' => 'approved'],
  'reject' => ['from' => ['submitted'], 'to' => 'rejected'], 'withdraw' => ['from' => ['submitted'], 'to' => 'draft'], 'supersede' => ['from' => ['approved'], 'to' => 'superseded']];
public function createVersion(Investment $i, User $by, ?BusinessCase $copyFrom = null): BusinessCase; // stage idea|business_case; no other draft/submitted; copies the latest
public function update(BusinessCase $bc, array $data, User $by): BusinessCase;   // draft only; evaluate() and store results; investment.planned_cost = one_off_cost
public function evaluate(array $data, int $orgId): array;                        // defaults rate/horizon from settings; wraps the calculator
public function markSubmitted(BusinessCase $bc, User $by): void;                 // called by StageGateService
public function markApproved(BusinessCase $bc, User $by): void;                  // supersedes earlier approved versions
public function markRejected(BusinessCase $bc, string $reason): void;
public function markWithdrawn(BusinessCase $bc): void;
```

**`InvestmentService`**
```php
public const TRANSITIONS = [
  'to_business_case' => ['from' => ['idea'], 'to' => 'business_case'],
  'to_approved' => ['from' => ['business_case'], 'to' => 'approved'],             // approval gate
  'to_in_delivery' => ['from' => ['approved'], 'to' => 'in_delivery'],
  'to_delivered' => ['from' => ['in_delivery'], 'to' => 'delivered'],             // approval gate
  'to_benefits_tracking' => ['from' => ['delivered'], 'to' => 'benefits_tracking'],
  'to_closed' => ['from' => ['benefits_tracking'], 'to' => 'closed'],
  'cancel' => ['from' => ['idea','business_case','approved','in_delivery'], 'to' => 'cancelled'],
];
public function store(array $data, User $by): Investment;        // code, fiscal year in org, users in org, cost_tolerance default; stage idea
public function update(Investment $i, array $data, User $by): Investment;  // not closed/cancelled; after 'approved' the dates/bac are frozen (changes only)
public function destroy(Investment $i, User $by): void;          // stage idea, no gates, no tagged lines
public function hold(Investment $i, string $reason, User $by): Investment;   // status → on_hold, keeps status_before_hold; refuses gates/changes while held
public function resume(Investment $i, User $by): Investment;
public function cancel(Investment $i, string $reason, User $by): Investment; // withdraws a pending gate (FinanceApprovals::withdraw), cancels open reviews and benefits
public function progress(Investment $i, array $data, User $by): Investment;  // percent_complete 0..100 + as_of; optional eac override + reason (P5-2); clear override
public function linkLines(Investment $i, array $lineIds, User $by): array;   // tags investment_id on visible lines of baseline/draft budgets in the org (C134); untags removed ones; audited
public function addLink(Investment $i, string $type, int $id, ?string $note, User $by): InvestmentLink; // linkable in org or org NULL (EA)
public function removeLink(InvestmentLink $l, User $by): void;
public function applyStage(Investment $i, string $to, User $by): void;       // internal: used by StageGateService
```

**`StageGateService`**
```php
public const PROCESS = 'finance.business_case_approval';
public const PERMISSION = 'approve it-finance-investments';
public function nextGate(Investment $i): ?array;                                  // {to_stage, requires_approval, criteria:[{key,label,auto,met,detail}]}
public function criteria(Investment $i, string $to, array $ticked = [], array $payload = []): array;
public function advance(Investment $i, string $to, array $ticked, array $payload, ?string $note, User $by): StageGate;
   // to must be the next stage; not on hold; every criterion met; approval gate → status submitted, approval_base_amount snapshot,
   // FinanceApprovals::start(PROCESS, $gate, $by); 'approved' gate → BusinessCaseService::markSubmitted; else status passed + applyStage
public function requestChange(InvestmentChange $c, User $by): StageGate;          // gate 'change', amount = bac + cost_delta (≥ bac)
public function approve(StageGate $g, User $by, ?string $note = null): string;    // Termination skeleton; final effects below
public function reject(StageGate $g, User $by, string $note): StageGate;
public function withdraw(StageGate $g, User $by): StageGate;                      // requester or manager
```
Final effects of `approve`: `approved` → BC approved, `bac` = BC one_off_cost, `bac_baselined_at` = now, stage approved; `delivered` → `actual_ends_on` = payload date (≤ today, ≥ starts_on), stage delivered, `ValueReviewService::schedule()`; `change` → `InvestmentChangeService::apply()`. Every final sets `passed_at/passed_by_id/decided_*`, status approved. Reject: `approved` gate → BC rejected; `change` → change rejected. Withdraw: BC back to draft; change withdrawn.

**`InvestmentScoring`**
```php
public function weights(int $orgId): array;                                       // settings or config; ints summing to 100
public function score(Investment $i, array $scores, User $by): Investment;        // all 5 criteria, 1..5; upsert rows; priority_score; rank()
public static function priorityScore(array $scores, array $weights): string;      // pure, P5-10
public function rank(int $orgId, ?int $fiscalYearId): int;                        // writes priority_rank; null rank for unscored
public function rescore(int $orgId): int;                                         // after a weights change
```

**`InvestmentFinancials`** (EVM, P5-1..3; bulk, no N+1)
```php
public function lineIds(Investment $i): array;                         // tagged lines + lineage ids (BudgetLedger::lineageIds)
public function evm(Investment $i): array;  // {bac, planned_cost, allocated, funding_gap, ac, committed, ev, percent_complete, cv, cpi, eac, eac_method, vac, health, spend_by_stage[], spend_by_year[]}
public function forInvestments(Collection $investments): array;       // id => {bac, ac, eac, cpi, health} in ≤ 4 grouped queries
public static function health(?string $cpi, bool $late): ?string;     // on_track|at_risk|off_track per config
```
`spend_by_stage`: actuals bucketed by `posted_on` against the passed/approved gates' `passed_at`. `spend_by_year`: business-case cost by year vs actuals by FY.

**`BenefitsService`**
```php
public const TRANSITIONS = ['record' => ['from' => ['planned','tracking','partially_realised','not_realised','realised'], 'to' => 'tracking'], 'cancel' => ['from' => ['planned','tracking'], 'to' => 'cancelled']];
public function store(Investment $i, array $data, User $by): Benefit;       // owner in org; aggregation default by unit; currency = base when unit currency
public function update(Benefit $b, array $data, User $by): Benefit;          // not final
public function record(Benefit $b, Period $p, string $value, ?FinanceDocument $doc, ?string $note, User $by): BenefitReading; // P5-12; refuses locked period / cancelled benefit / cancelled investment
public function cancel(Benefit $b, string $reason, User $by): Benefit;
public static function realised(string $aggregation, string $baseline, string $target, array $values): array; // pure: {value, pct}
public function investmentRealisedPct(Investment $i): ?string;
public function deriveStatus(Benefit $b, ?CarbonInterface $today = null): string;
```

**`ValueReviewService`**
```php
public function schedule(Investment $i, User $by): int;                   // idempotent per type; writes investment.value_review_dates
public function adHoc(Investment $i, string $dueOn, ?int $reviewerId, User $by): ValueReview;
public function update(ValueReview $r, array $data, User $by): ValueReview; // due_on/reviewer; open only; reason when moving due_on
public function complete(ValueReview $r, array $data, User $by): ValueReview; // summary ≥ 20, lessons; computes benefits_realised_pct + cost_outcome (P5-13)
public function derive(int $orgId, CarbonInterface $today): Collection;   // status scheduled→due→overdue; returns changed reviews (WP-B notifies)
```

**`InvestmentChangeService`**
```php
public function request(Investment $i, array $data, User $by): InvestmentChange; // stage approved|in_delivery, not on hold, one submitted at a time;
   // cumulative |cost| applied without approval since bac_baselined_at + |delta| > tolerance% × bac, or cumulative |days| > schedule_tolerance_days
   // → status submitted + StageGateService::requestChange; else apply() immediately (status applied)
public function apply(InvestmentChange $c, User $by): void;   // bac += delta (never < 0), planned_ends_on += days, applied_at; on approval also bac_baselined_at = now
public function withdraw(InvestmentChange $c, User $by): void; // withdraws its gate
```

**`PortfolioService`**
```php
public function portfolio(int $orgId, ?int $fiscalYearId, ?string $envelope, User $viewer): array; // §7.4 shape; visibility applied
public function defaultEnvelope(int $orgId, int $fiscalYearId): string;                             // P5-11
public function summary(int $orgId, ?array $costCentreIds = null): array;  // {by_stage:[{stage,count,bac}], benefits_realised_pct, at_risk, reviews_due, reviews_overdue, bac_total, ac_total, eac_total, rgt_split, regulatory_share_pct}
```

**Edits:**
- `BudgetService.php`: replace the refusal with a check that `investment_id` exists in the org and is not closed/cancelled.
- `FinanceSettings.php`: `update()` handles section `investments` (weights: the 5 keys, ints 0..100, **sum = 100**, else fail `investment_score_weights`; on change call `InvestmentScoring::rescore`); getters `discountRatePct($orgId)`, `costTolerancePct($orgId)`, `valueReviewLeadDays($orgId)`.
- `app/Services/Approvals/ApprovalProcesses.php` (WP-A adds the entry because the service cannot start without it; WP-B owns stages):
```php
'finance.business_case_approval' => [
  'module' => 'IT Financial Management', 'label' => 'Business case / stage gate', 'engine' => 'generic', 'owner_cannot_approve' => true,
  'description' => 'An investment passes its business-case and delivery gates, and changes beyond tolerance, only with approval (FIN-05.04, FIN-05.11; COBIT APO05, BAI01).',
  'permission' => 'approve it-finance-investments', 'view_permission' => 'view it-finance', 'model' => StageGate::class,
  'initiator' => 'requested_by_id', 'owner' => 'sponsor_id', 'title' => ['gate_title'], 'route' => 'finance.stage-gates.show',
  'initiate' => 'Requesting an investment gate',
  'conditions' => [
    'amount_band' => ['label' => 'Amount band (DoA, BAC)', 'values' => 'finance.doa_bands'],
    'rgt' => ['label' => 'Run / grow / transform', 'values' => ['run' => 'Run', 'grow' => 'Grow', 'transform' => 'Transform']],
    'is_regulatory' => ['label' => 'Regulatory mandate', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    'gate' => ['label' => 'Gate', 'values' => ['approved' => 'Business case approval', 'delivered' => 'Delivery acceptance', 'change' => 'Change beyond tolerance']],
    'requires_cfo' => ['label' => 'Needs CFO (business case or change)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    'requires_board' => ['label' => 'Needs the board (high value)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
  ],
  'default' => 'One approval by anyone with "approve it-finance-investments" other than the requester, the manager, the sponsor and the business case preparer.',
],
```

### 2.5 Controllers, requests, policy, masking
- **`app/Http/Controllers/Finance/InvestmentController.php`:** `index`, `create`, `store`, `show` (`?tab=`), `update`, `destroy`, `score`, `advanceGate`, `hold`, `resume`, `cancel`, `progress`, `syncLines`, `storeLink`, `destroyLink`.
- **`BusinessCaseController.php`:** `store(Investment)`, `update(BusinessCase)`, `submit(BusinessCase)` (→ `StageGateService::advance($i,'approved',…)`), `evaluate` (JSON, needs amounts), `show` (redirect to `finance.investments.show` `tab=business-case&version=`).
- **`StageGateController.php`:** `show` (redirect, `tab=gates`), `approve`, `reject`, `withdraw`.
- **`BenefitController.php`:** `store(Investment)`, `update`, `cancel`, `storeReading`.
- **`ValueReviewController.php`:** `store(Investment)`, `update`, `complete`, `show` (redirect, `tab=reviews`).
- **`InvestmentChangeController.php`:** `store(Investment)`, `withdraw`.
- **`PortfolioController.php`:** `index`.
- **`Concerns/PresentsInvestments.php`:** `INV_MONEY_KEYS = bac, planned_cost, allocated, funding_gap, ac, committed, ev, cv, cpi, eac, vac, eac_override, npv, irr_pct, roi_pct, payback_months, discounted_payback_months, tco, one_off_cost, total_benefit, total_cost, cost_delta, current_base_amount, actual, amount, value, planned, realised, cumulative_cost, envelope, approval_base_amount, amount_band_label, bac_total, ac_total, eac_total, cpi_portfolio, benefits_planned, benefits_realised`; `INV_MONEY_LISTS = costs, benefits, cash_flows, spend_by_stage, spend_by_year`; benefit `baseline_value/target_value/realised_value/realised_pct` and reading `value` nulled when `unit === 'currency'`. Helpers `investmentRow()`, `userMini()`, `optionsPayload()` (labels for every enum), `visibleInvestmentsQuery(User)`.
- **Requests** `app/Http/Requests/Finance/`: `InvestmentFormRequest`, `BusinessCaseFormRequest` (also used by evaluate), `AdvanceGateRequest`, `BenefitFormRequest`, `BenefitReadingRequest`, `ValueReviewRequest`, `InvestmentChangeRequest`, `InvestmentProgressRequest`. Ids existence-checked against the user's org; money via `FinanceSettings::DOA_AMOUNT` regex; enums via `Rule::in`.
- **Edits:** `FinanceSettingsController.php` (`investments` rules + props `investmentPolicy{weights, discount_rate_pct, cost_tolerance_pct, value_review_lead_days, defaults}`); `FinanceDocumentController.php` (abilities: investment family → `updateInvestment`); `Concerns/PresentsBudgets.php` (`investments` options: open investments in org `[{id, investment_code, title}]`); `ContractController.php` (`linkOptions.investment`) and `Concerns/PresentsContracts.php` (`linkTypes` label "Investment").
- **`app/Policies/Finance/FinancePolicy.php` (edit):**
```php
createInvestment(User)                      // create it-finance
updateInvestment(User, Model)               // (edit it-finance || manager of it) && sameOrg && investmentVisible
scoreInvestment(User, Investment)           // (edit it-finance || approve it-finance-investments) && visible
decideGate(User, StageGate)                 // approve it-finance-investments && sameOrg (the engine narrows the stage)
recordBenefit(User, Benefit)                // updateInvestment || benefit owner
completeReview(User, ValueReview)           // updateInvestment || reviewer
```
`costCentreVisible()` gets an investment branch: Investment, BusinessCase, InvestmentScore, StageGate, Benefit, BenefitReading, ValueReview, InvestmentChange, InvestmentLink resolve their investment → visible when `visibleCostCentreIds` is null, the user holds `approve it-finance-investments`, the cost centre is in the tree, or `involves($user)`. FinanceDocument already delegates to its subject.

### 2.6 Routes `routes/web.php`
Inside the existing `finance.` group, after the P4 block, with `use` lines. Every `{param}` `whereNumber`; implicit binding gives 404 across tenants; every action also `Gate::authorize`.
```
GET    /investments                                 finance.investments.index
GET    /investments/create                          finance.investments.create          + permission:create it-finance
GET    /investments/{investment}                    finance.investments.show
GET    /portfolio                                   finance.portfolio
GET    /stage-gates/{stageGate}                     finance.stage-gates.show            (redirect)
GET    /business-cases/{businessCase}               finance.business-cases.show         (redirect)
GET    /value-reviews/{valueReview}                 finance.value-reviews.show          (redirect)
POST   /business-cases/evaluate                     finance.business-cases.evaluate     (JSON; register before {businessCase})
permission:create it-finance:
POST   /investments                                 finance.investments.store
permission:edit it-finance:
PUT    /investments/{investment}                    finance.investments.update
DELETE /investments/{investment}                    finance.investments.destroy
POST   /investments/{investment}/advance-gate       finance.investments.advance-gate
POST   /investments/{investment}/hold               finance.investments.hold
POST   /investments/{investment}/resume             finance.investments.resume
POST   /investments/{investment}/cancel             finance.investments.cancel
POST   /investments/{investment}/progress           finance.investments.progress
POST   /investments/{investment}/budget-lines       finance.investments.lines.sync
POST   /investments/{investment}/links              finance.investments.links.store
DELETE /investments/{investment}/links/{link}       finance.investments.links.destroy
POST   /investments/{investment}/business-cases     finance.business-cases.store
PUT    /business-cases/{businessCase}               finance.business-cases.update
POST   /business-cases/{businessCase}/submit        finance.business-cases.submit
POST   /investments/{investment}/benefits           finance.benefits.store
PUT    /benefits/{benefit}                          finance.benefits.update
POST   /benefits/{benefit}/cancel                   finance.benefits.cancel
POST   /investments/{investment}/value-reviews      finance.value-reviews.store
PUT    /value-reviews/{valueReview}                 finance.value-reviews.update
POST   /investments/{investment}/changes            finance.investment-changes.store
POST   /investment-changes/{investmentChange}/withdraw finance.investment-changes.withdraw
POST   /stage-gates/{stageGate}/withdraw            finance.stage-gates.withdraw
view only + policy (owner/reviewer may lack edit; also scorers from the Steering Committee):
POST   /investments/{investment}/score              finance.investments.score           (scoreInvestment)
POST   /benefits/{benefit}/readings                 finance.benefits.readings.store     (recordBenefit)
POST   /value-reviews/{valueReview}/complete        finance.value-reviews.complete      (completeReview)
permission:approve it-finance-investments:
POST   /stage-gates/{stageGate}/approve             finance.stage-gates.approve
POST   /stage-gates/{stageGate}/reject              finance.stage-gates.reject
```
P5 adds 36 routes: 7 GET, 29 write.

### 2.7 Backend tests owned by WP-A
- **`tests/Feature/Finance/Concerns/BuildsInvestments.php`** (new; WP-B/E use, do not edit): builds on `BuildsMonitoring`. `bootInvestments(string $now = '2027-04-05 09:00:00')` (users: manager, sponsor, headofit, cfo, cio, md with the right roles/titles; FY with baseline lines), `makeInvestment(array $over = [])`, `makeBusinessCase(Investment, costs, benefits, rate, horizon)`, `scoreAll(Investment, int $s)`, `tagLines(Investment, BudgetLine ...)`, `approveGate(StageGate, User ...$deciders)`.
- **`tests/Unit/Finance/BusinessCaseCalculatorTest.php`** (new, pure `PHPUnit\Framework\TestCase`, ~10 tests): the reference case (NPV 137,236.03 ±0.01; IRR 15.24 ±0.01; payback 40; ROI 50.00; TCO 1,000,000.00); no sign change → irr null; all-negative; multiple sign changes → `irr_multiple`; payback beyond horizon → null; mid-year interpolation; discounted payback; zero cost → ROI null; rate 0 → NPV = Σ net; validation (year > horizon, negative amount, "1e5").
- **`InvestmentBackendSmokeTest`** (new, ~10): every new GET renders with the §7 keys; redirects land on the right tab; masking nulls `INV_MONEY_KEYS` and evaluate is 403; another bank's investment/gate/benefit/review gives 404; `finance.business-cases.evaluate` returns the reference figures; budget line accepts `investment_id` of the org and refuses another bank's.
- **`FinanceTenancyAndRoutesTest.php`** (edit): add the 7 P5 GET routes to its route list.

Run: `--filter 'BusinessCaseCalculatorTest|InvestmentBackendSmokeTest|FinanceTenancyAndRoutesTest|FinancePermissionsTest|BudgetLifecycleTest|FinanceConfigurationTest|ContractRenewalLifecycleTest'`.

---

## 3. WP-B: workflow and integrations (fin-workflow-integrator, DB `it_security_risk_fin_test`, after WP-A)

1. **`app/Services/Finance/FinancePolicySeeder.php` (edit):** `'finance.business_case_approval' => [stage(10,'Head of IT review','job_title','Head of IT',72), stage(20,'CFO approval','job_title','Chief Financial Officer',72,'requires_cfo',['yes']), stage(30,'IT Steering Committee','role','IT Steering Committee',120), stage(40,'Board approval (MD/CEO)','job_title','Managing Director',168,'requires_board',['yes'])]`. Board threshold lives on the `StageGate` accessor, not in `bandsFrom` (so a DoA re-map never drops it).
2. **`FinanceApprovalNotifier.php` (edit):** `'finance.business_case_approval' => route('finance.stage-gates.show', $subject)`.
3. **`app/Notifications/Finance/ValueReviewDueNotification.php`** (new, `BaseNotification`, `$module = 'IT Financial Management'`, **no amounts or percentages**): constructor `(string $investment, string $reviewType, string $dueOn, string $kind /* due|overdue */, int $daysOverdue, string $url)`; type `finance.value_review_due` / `finance.value_review_overdue`.
4. **`app/Services/Finance/ValueReviewSweeper.php`** (new): `sweep(?CarbonInterface $today = null): int` → per org (try/catch), `ValueReviewService::derive()`, then sends: `due` once (`value_review_due:{due_on}`, offset = lead days); `overdue` at each reached offset in `value_review_overdue_offsets` not yet claimed (key `value_review_overdue:{due_on}`, offset n). Recipients: reviewer + sponsor, active, holding `view it-finance` (security L8 for new code), unique.
5. **`routes/console.php` (edit):** `Artisan::command('fin:value-reviews-sweep {--date=}', …)`; `Schedule::command('fin:value-reviews-sweep')->dailyAt('07:45')->timezone(config('finance.timezone'))->name('finance.value-reviews-sweep')->withoutOverlapping();` (`--date` parsed as in P4).
6. **Calendar (`FinanceCalendarService.php` edit):** `SOURCES['investments'] = 'Investments and value reviews'`; `EVENT_TYPES` + `investment_start` (blue), `investment_planned_end` (amber; red when late), `investment_delivered` (blue), `investment_gate_passed` (blue), `investment_gate_pending` (amber), `value_review_due` (amber; red overdue; grey completed/cancelled); `sourceInvestments()` honours visibility and the "mine" filter (manager, sponsor, reviewer); events flow into `.ics` and CSV/PDF automatically. New `passedInvestments(int $orgId, User $viewer, ?CarbonInterface $today)` → rows `{id, investment_code, title, stage, manager, which: 'planned_end'|'value_review', date, days_overdue, href}` (late investments and overdue reviews). **`FinanceCalendarController.php` (edit):** add prop `passedInvestments`. Contract `passed[]` is unchanged.
7. **`FinanceMetrics.php` (edit):** `summary()` fills `investments_by_stage` (`[{stage,count,bac}]`) and `benefits_realised_pct` from `PortfolioService::summary($orgId, $costCentreIds)`; new `boardInvestmentFigures(int $orgId): ?array` (null when the bank has no investments) = `{as_of, count_open, by_stage[], rgt_split{run,grow,transform}, regulatory_share_pct, bac_total, ac_total, eac_total, cpi_portfolio, benefits_planned, benefits_realised, benefits_realised_pct, at_risk:[{code,title,status,health}], reviews_due, reviews_overdue}`; public const `BOARD_INVESTMENT_MONEY_KEYS = ['rgt_split','regulatory_share_pct','bac_total','ac_total','eac_total','cpi_portfolio','benefits_planned','benefits_realised']` plus `by_stage.*.bac`.
8. **Board pack:** `BoardPackBuilder.php` `SECTIONS['it_investment_portfolio'] = 'IT investment portfolio and value'` + `sectionItInvestmentPortfolio()`.
9. **Security M2 (adopted):** new **`app/Services/Finance/FinanceMasking.php`**: `boardContent(array $content, User $u): array` (masks `it_financial_position` per `BOARD_MONEY_KEYS` + top-variance pct, `it_investment_portfolio` per `BOARD_INVESTMENT_MONEY_KEYS`, and `kri_panel` rows whose code is in `config('finance.masked_kri_codes')`: value and trend values null); `kriValueMasked(string $code, User $u): bool`; `maskKriPayload(array $kri, User $u): array`. Wire it in: `BoardPackController::show` (replace `maskFinance`), `Kri/KriController::index` + `show` (value, trend, readings values), `DashboardController` (KRI widget). Thresholds and status stay visible.
10. **Domain M5 (adopted):** migration **`2027_03_20_000003_add_snapshot_history_to_fin_forecasts.php`** (`period_close_id` FK fin_period_closes null nullOnDelete; `superseded_at` ts null; idx(org, period_id, is_snapshot, superseded_at)). `ForecastService::snapshot(Period $p, User $by, ?PeriodClose $close = null)`: uses `eac[...]['eac']` and `method` (so an active manual override is snapshotted as `manual`), marks earlier snapshots of the period `superseded_at = now` instead of deleting. `PeriodCloseService::close` passes the new close. `MonitorController` EAC history reads `superseded_at IS NULL`.
11. **Reports:** `app/Services/Finance/Reports/Definitions/InvestmentPortfolioReport.php` (code, title, rgt, driver, stage, status, regulatory, rank, score, NPV, IRR, BAC, AC, EAC, CPI, benefits realised %; filter `fiscal_year_id`; group `Investments`) and `BenefitsRegisterReport.php` (benefit code, investment, type, owner, unit, baseline, target, realised, realised %, due, status; money columns typed `money` only for currency units). `FinanceReports::DEFINITIONS` (edit) + both keys. `ScheduledReport::TYPES` (edit) + `finance.investment_portfolio`, `finance.benefits_register`.
12. **Count updates (WP-B owns):** `tests/Feature/Workflows/WorkflowStudioModuleTest.php:93` 19 → 20; `tests/Feature/Finance/FinanceWorkflowStudioTest.php` 8 → 9 at lines 67, 89, 196 (and 19 → 20), 204, 208, plus `assertCount(4, $wfs['finance.business_case_approval']->stages)`. `RouteSmokeTest` counts are WP-E's.
13. **Tests:** `tests/Feature/Finance/InvestmentWorkflowIntegrationTest.php` (new, ~12): the business case flows through the Studio inbox (headofit → cfo → cio → md for ≥ 250m; `mine` for each); `requires_cfo`/`requires_board` route stages; a `delivered` gate skips CFO and Board; SoD (sponsor, manager, requester, BC preparer refused; nobody decides two stages); unconfigured default approver; the value-review sweep sends once on due and once per overdue offset, re-arms after reschedule, skips users without `view it-finance`; `ValueReviewDueNotification` renders mail + database; calendar returns investment events and `passedInvestments`; `.ics` contains a value review; dashboard metrics and the board section are filled; both reports export CSV/XLSX/PDF. **`tests/Feature/Finance/FinanceP4CarryOverTest.php`** (new, ~5): M5 snapshot keeps the manual override and the history across a reopen/re-close; M2 board pack (both sections, kri_panel), KRI index/show and dashboard mask `KRI-FIN-VAR/UNB` for a viewer without amounts and not for one with.

Run: `--filter 'InvestmentWorkflowIntegrationTest|FinanceP4CarryOverTest|FinanceWorkflowStudioTest|WorkflowStudioModuleTest|CalendarTest|MonitoringAndVarianceTest|PeriodCloseTest|FinanceReportsTest|KriDashboardsModuleTest|MonitoringWorkflowIntegrationTest'`.

---

## 4. WP-C: frontend (fin-frontend-engineer, `npm run build`, parallel with WP-A)

**Pages** (new, `resources/js/Pages/Finance/Investments/`):
- **`Index.jsx`:** `InvestmentsNav` (Investments · Portfolio), KPI strip from `summary`, filters (search, stage, rgt, driver, status, FY, cost centre, regulatory), table (code, title, stage chip, status/health chip, RGT, sponsor, manager, rank, score, BAC, AC, CPI, benefits %, pending-gate chip), pagination, "New investment" when `can.create`.
- **`Create.jsx`:** form (title, type, rgt, driver, sponsor, manager, cost centre, legal entity, funding FY, dates, regulatory + reference, objective, description, cost tolerance) → `finance.investments.store`.
- **`Show.jsx`:** header (code, title, `StageStepper`, status chip, health chip, actions Hold/Resume/Cancel per `can`), tabs `overview · business-case · scoring · gates · budget · benefits · reviews · changes · activity`:
  - overview: facts, links panel (EA application, capability, business service, risk, change) with add/remove, documents (`finance.documents.store` with `subject: 'investment'`).
  - business-case: version picker; `BusinessCaseEditor` (year grid 0..horizon: capex/opex/run per year, benefits per year, non-financial benefits list with 1–5 score, rate, horizon, options, recommendation, assumptions, risks) with **live results** — debounce 400 ms, `axios.post(route('finance.business-cases.evaluate'), payload)`; `CalcResults` (NPV, IRR, payback, discounted payback, ROI, TCO, cash-flow table + cumulative chart); Save (`PUT finance.business-cases.update`), Submit (`finance.business-cases.submit`, opens the gate checklist), New version.
  - scoring: 5 sliders/selects 1–5 with weights and weighted contribution; "delivery risk: 5 = high risk" hint; Save → `finance.investments.score`; shows score and rank.
  - gates: `GatesPanel` timeline of `gates[]` with criteria snapshots and `ApprovalTrail`; `AdvanceGateModal` for `nextGate` (auto criteria read-only ticks, manual checkboxes, note, `actual_ends_on` for delivered); Approve/Reject/Withdraw per `gate.can`.
  - budget: `EvmPanel` (BAC, allocated, funding gap, AC, committed, EV, CV, CPI, EAC (manual badge), VAC, health); spend by stage and planned-vs-actual by year charts; `ProgressModal` (% complete, as-of, EAC override + reason); `LinkedLinesPanel` (tagged lines + picker from `lineOptions` → `finance.investments.lines.sync`).
  - benefits: `BenefitsPanel` (cards with progress bar realised vs target, status chip), `BenefitFormModal`, `ReadingModal` (period, value, optional document upload first via `finance.documents.store` subject `benefit`), realised-vs-planned chart.
  - reviews: `ValueReviewsPanel` (type, due, status chip, reviewer, outcome), `CompleteReviewModal` (summary, lessons), add ad-hoc, reschedule.
  - changes: `ChangesPanel` + `ChangeRequestModal` (description, cost delta, schedule delta days, reason); shows "within tolerance: applied" vs "awaiting approval" and links the gate.
  - activity: list of `activity[]`.
- **`Portfolio.jsx`:** FY picker + envelope input (what-if, query param); `PortfolioRanking` table with cumulative cost and a gold **fund line** row; `PortfolioSplits` (RGT, driver, regulatory share); pipeline by stage bar; planned vs actual (BAC/AC/EAC); benefits realisation; at-risk list; `PortfolioBubble` (recharts `ScatterChart`: x = priority score, y = NPV, z = BAC, colour by RGT); `CapabilitySpend` table (hidden when empty).

**Components** (new, `resources/js/Components/Finance/Investments/`): `InvestmentsNav`, `StageStepper`, `StageChip`, `HealthChip`, `RgtChip`, `BusinessCaseEditor`, `CalcResults`, `ScoringPanel`, `GatesPanel`, `AdvanceGateModal`, `EvmPanel`, `ProgressModal`, `LinkedLinesPanel`, `InvestmentLinksPanel`, `BenefitsPanel`, `BenefitFormModal`, `ReadingModal`, `ValueReviewsPanel`, `CompleteReviewModal`, `ChangesPanel`, `ChangeRequestModal`, `PortfolioRanking`, `PortfolioSplits`, `PortfolioBubble`, `CapabilitySpend`. Masked (null) money renders "Hidden"; charts hidden with a note.

**Edits:**
- `resources/js/Config/navigation.js`: "Investments & Value" (`finance.investments.index`, `view it-finance`) after Calendar, before Reports.
- `resources/js/Utils/finance.js`: `stageLabel`, `stageTone`, `healthTone`, `fmtMonths`.
- `resources/js/Pages/Finance/Dashboard.jsx`: "Investments" card from `metrics.investments_by_stage` and `metrics.benefits_realised_pct` (link to portfolio); hide when empty.
- `resources/js/Pages/BoardPacks/Show.jsx`: `case 'it_investment_portfolio'` (stats, by-stage table, at-risk list; null → "Hidden").
- `resources/js/Pages/Finance/Calendar.jsx` (+ `Components/Finance/Calendar/*` as needed): colours/labels for the 6 new event types, `investments` source toggle, a second "Investments past their date" list from `passedInvestments`.
- `resources/js/Components/Finance/Budgets/LineFormModal.jsx`: investment select from `options.investments` → `investment_id`.
- `resources/js/Pages/Finance/Settings.jsx` + new `resources/js/Components/Finance/Settings/InvestmentPolicyTab.jsx`: weights (5 ints, live sum must be 100), discount rate, cost tolerance, value-review lead days → `PUT finance.settings.update` `section: 'investments'`.
- `resources/js/Pages/Finance/Reports/Index.jsx`: only if report groups are hard-coded, add `Investments`.

---

## 5. WP-E: QA (fin-qa-engineer, DB `it_security_risk_fin_qa`, after A, B, C)

### 5.1 `database/seeders/FinanceInvestmentDemoSeeder.php` (new) + `DatabaseSeeder.php` (call right after the **first** `FinanceMonitoringDemoSeeder` call, line ~142, so `KriDashboardsDemoSeeder` and board packs see it)
Per bank (KHB org 2, FBN org 3): guard = skip when `Investment` exists for the org; `mt_srand(5000 + orgId)`; `Auth::setUser(finance@)`; `attempt()` copied from `FinanceMonitoringDemoSeeder` (logs warnings; quiets `FinanceApprovalRequested/Decided`); wrap benefit readings in Jan–Jun (locked) periods in `whilePeriodsOpen()`; dates relative to `FinanceClock::today()`; first codes `{KHB|FBN}-IVT-0001`, `-BEN-0001`, `-ICR-0001`.
1. **People:** managers = cost-centre owners (IT Budget Owner) and the CISO; sponsors = business heads (Head of Digital Channels, Treasury IT, CISO) given the `Investment Sponsor` role. **Never** headofit@, cfo@, cio@ or md@ as manager or sponsor (they are the stage deciders; SoD would leave a stage with nobody).
2. **Investments** (KHB 10, FBN 12), every stage incl. cancelled, ≥ 1 regulatory, ≥ 1 transform. KHB examples: Finacle 11 core banking upgrade (transform, in_delivery); CBN Risk-Based Cybersecurity Framework programme: SIEM/SOAR + PAM (regulatory, delivered ~3 months ago → **pir_3m due**); PCI DSS v4.0 remediation (regulatory, benefits_tracking, pir_3m completed); mobile app re-platform (grow, business_case, **gate pending at CFO**); Tier III DR site (approved); agency banking platform (idea); RPA in operations (business_case, draft BC); NDPA privacy tooling (in_delivery, **change over tolerance pending at Head of IT**); ATM switch replacement (closed); contact-centre AI (cancelled). FBN adds ISO 20022 / NIBSS NIP migration (regulatory), open banking API platform (CBN framework, regulatory), cloud landing zone, zero-trust network, Temenos payments hub.
3. **Business cases** for every investment past `idea`, through the services (calculator figures real); approved ones via `StageGateService` with headofit@ → cfo@ → cio@ (→ md@ when ≥ 250m) so the Studio history is real. ≥ 1 rejected v1 + approved v2.
4. **Scores** for all but one idea; `rank()` per FY (FY2026 delivery, FY2027 pipeline).
5. **Budget lines:** tag 1–3 FY2026 baseline lines per delivering investment by `LOWER(description) LIKE` keywords (Finacle, SIEM, PCI, DR, privacy…) via `InvestmentService::linkLines`, so AC comes from the existing P3 actuals. `progress()` with plausible % complete; one CPI < 0.85 (off track).
6. **Benefits** (2–4 per delivered+ investment, mixed units) with monthly readings; **value reviews** via the gate approval; one completed with summary and lessons; one due; one overdue (FBN).
7. **Pending approvals:** per bank one `approved` gate at the CFO stage (cfo@ can decide) and one `change` gate at Head of IT (headofit@ can decide). Seed `fin_notification_logs` for past value-review sends so the first sweep doesn't resend history (as P2 Q1).
8. **Board pack:** append `it_investment_portfolio` to the global BAC template's `sections` if missing.
9. **Self-check:** after each bank, warn (not fail) if any target above is missing.

### 5.2 Tests (new unless marked, `tests/Feature/Finance/`, using `BuildsInvestments`)
- **`InvestmentAndBusinessCaseTest`** (~16): lifecycle idea → closed with criteria enforcement (missing auto criterion / unticked manual refused); gate advance requires approval (stage unchanged until final); reject keeps stage and rejects BC; withdraw returns BC to draft; one draft/submitted BC at a time; approved BC immutable; BAC set on approval = one-off cost; rank recomputed on score change (two investments swap rank); delivery-risk inversion; weights must sum 100 and rescore; EVM from actuals (AC via tagged line lineage + direct `investment_id`, no double count, duplicates excluded; EV, CV, CPI, EAC both computed and manual; CPI null when AC 0); change within tolerance applied at once, cumulative over tolerance needs approval and applies on approval; hold blocks gates/changes; cancel withdraws the pending gate and cancels reviews/benefits; visibility (budget owner sees own cost centre, sponsor/manager see theirs, others 404/absent); masking.
- **`BenefitsAndValueReviewTest`** (~12): realised % updates on readings (sum and latest, decreasing target); re-recording a period replaces; locked period refused, closed allowed; status derivation at due date; investment `benefits_realised_pct`; value reviews scheduled on delivered approval at +3/+6/+12 months; sweep `fin:value-reviews-sweep` twice with `Notification::fake` → one due send, overdue offsets once each; reschedule re-arms; complete computes pct and cost outcome; reviewer may complete without `edit it-finance`, others refused.
- **`tests/Feature/RouteSmokeTest.php` (edit):** line 263 8 → 9; line 589 reports 8 → 10; add route-param resolvers for `{investment}`, `{stageGate}`, `{businessCase}`, `{valueReview}` if the GET loop needs them; new `test_finance_investment_estate_is_seeded_and_renders_for_every_demo_tenant` (per bank: ≥ 8 investments across all stages, a due review, the pending CFO and Head-of-IT gates have an eligible decider, Show renders every tab for one investment, Portfolio renders with a fund line, Calendar has investment events, BAC template has the section, both new reports download in 3 formats; org 1 renders Index and Portfolio empty).

Run: `--filter 'InvestmentAndBusinessCaseTest|BenefitsAndValueReviewTest|InvestmentBackendSmokeTest|InvestmentWorkflowIntegrationTest|BusinessCaseCalculatorTest'`, then seed on `it_security_risk_fin_qa` and confirm **no seeder warnings**. No Playwright this phase.

---

## 6. Gate (orchestrator)
1. Full suite on `it_security_risk_test` in the background (baseline 692; expect ~760).
2. `migrate:fresh --seed` on scratch; **no seeder warnings**.
3. `SMOKE=1 … RouteSmokeTest`.
4. `npm run build`; Pint on touched files.
5. Tinker on scratch: each bank's pending `approved` gate decidable by cfo@ and the pending `change` gate by headofit@; `php artisan fin:value-reviews-sweep` sends to the due review's reviewer + sponsor once.

---

## 7. Props contract (WP-A ⇄ WP-C)
Decimals are strings, dates `Y-m-d`, timestamps ISO-8601, masked values `null`. Every page gets `baseCurrency` and `can.viewAmounts`. `options` = `{types, rgt, drivers, stages, statuses, criteria, benefitTypes, units, reviewTypes, linkTypes}` each `[{value,label}]`. `UserMini = {id,name,job_title}`.

**7.1 `Finance/Investments/Index`:** `filters{search,stage,rgt,driver,status,fiscal_year_id,cost_centre_id,regulatory}`, `investments` (paginator 25 `{data,links,meta}` of `Row`), `summary{count,by_stage:{[stage]:int},bac_total,ac_total,at_risk}`, `fiscalYears[{id,year_label}]`, `costCentres[{id,code,name}]`, `options`, `can{create,viewAmounts}`.
`Row = {id,investment_code,title,type,rgt,driver,stage,status,health,is_regulatory,sponsor:UserMini|null,manager:UserMini|null,cost_centre:{id,code,name}|null,fiscal_year:{id,year_label}|null,starts_on,planned_ends_on,actual_ends_on,priority_score,priority_rank,planned_cost,bac,ac,eac,cpi,npv,benefits_realised_pct,pending_gate:{id,gate}|null,href}`

**7.2 `Finance/Investments/Create`:** `options`, `users[UserMini]`, `costCentres`, `legalEntities[{id,code,name}]`, `fiscalYears`, `defaults{discount_rate_pct,horizon_years,cost_tolerance_pct}`, `can`.

**7.3 `Finance/Investments/Show`:**
```
tab, investment: Row + {description,strategic_objective,regulatory_reference,legal_entity:{id,code,name}|null,cost_tolerance_pct,percent_complete,percent_complete_as_of,
  eac_override,eac_reason,external_reference,status_before_hold,cancel_reason,value_review_dates:[string]},
businessCases:[BC], businessCase: BC|null,   // current: latest draft/submitted, else approved
BC = {id,version_no,status,horizon_years,discount_rate_pct,option_summary,recommended_option,costs:[{year,capex,opex,run}],benefits:[{year,amount}],
      non_financial_benefits:[{label,score,note}],npv,irr_pct,irr_multiple,payback_months,discounted_payback_months,roi_pct,tco,one_off_cost,total_benefit,
      assumptions,risks_summary,submitted_at,approved_by,approved_at,rejection_reason,can:{edit,submit}}
scores:[{criterion,label,score,weight_pct,weighted,note,scored_by,scored_at}], weights:{[criterion]:int},
gates:[{id,gate,from_stage,status,criteria:[{key,label,auto,met,detail}],payload,requested_by,requested_at,request_note,decided_by,decided_at,
        rejection_reason,passed_at,passed_by,approval_base_amount,amount_band_label,trail|null,can:{approve,reject,withdraw}}],
nextGate:{to_stage,requires_approval,criteria:[{key,label,auto,met,detail}]}|null,
evm:{bac,planned_cost,allocated,funding_gap,ac,committed,ev,percent_complete,cv,cpi,eac,eac_method:'computed'|'manual',vac,health,
     spend_by_stage:[{stage,amount}],spend_by_year:[{year,planned,actual}]},
lines:[{id,budget_id,budget_code,line_no,description,fiscal_year,cost_centre,current_base_amount,actual,href}],
lineOptions:[{id,label,fiscal_year,cost_centre}],
benefits:[{id,benefit_code,title,type,owner:UserMini|null,measurement_method,baseline_value,target_value,unit,aggregation,currency,due_on,status,
           realised_value,realised_pct,readings:[{id,period:{id,name},value,document:{id,title,url}|null,note,recorded_by,recorded_at}],can:{edit,record,cancel}}],
reviews:[{id,review_type,due_on,completed_on,status,reviewer:UserMini|null,benefits_realised_pct,cost_outcome,summary,lessons,can:{complete,edit}}],
changes:[{id,change_code,description,reason,cost_delta,schedule_delta_days,status,requires_approval,requested_by,requested_at,gate_id,applied_at,can:{withdraw}}],
links:[{id,type,label,href|null}], linkOptions:{ea_application,capability,business_service,risk,change}, // each [{id,label}]
documents:[{id,kind,title,url,uploaded_by,uploaded_at}], activity:[{id,description,causer,at,changes}],
users:[UserMini], periods:[{id,name,fiscal_year,status}], options,
can:{edit,advance,score,hold,resume,cancel,progress,addBenefit,addReview,requestChange,viewAmounts}
```
`finance.business-cases.evaluate` request `{costs,benefits,discount_rate_pct,horizon_years}` → `{npv,irr_pct,irr_multiple,payback_months,discounted_payback_months,roi_pct,tco,one_off_cost,total_cost,total_benefit,cash_flows:[{year,cost,benefit,net,discounted,cumulative,discounted_cumulative}]}`; 422 `{errors}` on bad input; 403 without amounts.

**7.4 `Finance/Investments/Portfolio`:**
```
filters:{fiscal_year_id,envelope}, fiscalYears, envelope:{default,used,source:'grow_transform_baseline'|'manual'},
ranking:[{id,investment_code,title,rgt,driver,stage,is_regulatory,priority_score,priority_rank,planned_cost,cumulative_cost,above_line,npv,roi_pct,href}],
splits:{rgt:{run:{count,bac},grow:{count,bac},transform:{count,bac}}, driver:{[driver]:{count,bac}}, regulatory:{count,bac,share_pct}},
pipeline:[{stage,count,value}], costs:{bac,ac,eac,committed},
benefits:{planned,realised,pct,by_investment:[{id,title,planned,realised,pct}]},
atRisk:[{id,investment_code,title,status,health,cpi,days_late,href}],
bubble:[{id,label,x,y,z,rgt}], capabilities:[{id,name,investments,bac}], can:{viewAmounts}
```

**7.5 Other props:** `Finance/Dashboard` `metrics.investments_by_stage = [{stage,count,bac}]`, `metrics.benefits_realised_pct`. `Finance/Calendar` new prop `passedInvestments` (§3.6) and event types (§3.6). `Finance/Settings` new prop `investmentPolicy{weights:{[criterion]:int},discount_rate_pct,cost_tolerance_pct,value_review_lead_days,defaults:{…}}`. Budgets line options `investments:[{id,investment_code,title}]`. Board section `it_investment_portfolio` data = `boardInvestmentFigures` (§3.7).

---

## 8. File ownership (no overlaps)

| WP | files |
|---|---|
| **A** | `config/finance.php`; migrations `2027_03_20_000001_create_it_finance_investment_tables.php`, `2027_03_20_000002_grant_finance_approver_investment_approval.php`; `database/seeders/RolesAndPermissionsSeeder.php` (Finance Approver grant); models `app/Models/Finance/{Investment,BusinessCase,InvestmentScore,StageGate,Benefit,BenefitReading,ValueReview,InvestmentChange,InvestmentLink}.php` (new), `BudgetLine.php`, `Actual.php`, `ContractLink.php`, `FinanceDocument.php`, `Setting.php` (edit); services `app/Services/Finance/{BusinessCaseCalculator,BusinessCaseService,InvestmentService,StageGateService,InvestmentScoring,InvestmentFinancials,BenefitsService,ValueReviewService,InvestmentChangeService,PortfolioService}.php` (new), `BudgetService.php`, `FinanceSettings.php` (edit); `app/Services/Approvals/ApprovalProcesses.php`; `app/Policies/Finance/FinancePolicy.php`; controllers `app/Http/Controllers/Finance/{Investment,BusinessCase,StageGate,Benefit,ValueReview,InvestmentChange,Portfolio}Controller.php`, `Concerns/PresentsInvestments.php` (new), `FinanceSettingsController.php`, `FinanceDocumentController.php`, `ContractController.php`, `Concerns/PresentsBudgets.php`, `Concerns/PresentsContracts.php` (edit); requests (8, §2.5); `routes/web.php`; tests `tests/Feature/Finance/Concerns/BuildsInvestments.php`, `tests/Unit/Finance/BusinessCaseCalculatorTest.php`, `tests/Feature/Finance/InvestmentBackendSmokeTest.php` (new), `tests/Feature/Finance/FinanceTenancyAndRoutesTest.php` (edit) |
| **B** | `app/Services/Finance/{FinancePolicySeeder,FinanceApprovalNotifier,FinanceCalendarService,FinanceMetrics,ForecastService,PeriodCloseService}.php` (edit), `ValueReviewSweeper.php`, `FinanceMasking.php` (new); `app/Notifications/Finance/ValueReviewDueNotification.php`; `routes/console.php`; `app/Http/Controllers/Finance/{FinanceCalendarController,MonitorController}.php`; `app/Http/Controllers/Reports/BoardPackController.php`; `app/Http/Controllers/Kri/KriController.php`; `app/Http/Controllers/DashboardController.php`; `app/Services/Reports/BoardPackBuilder.php`; `app/Services/Finance/Reports/FinanceReports.php` (edit), `Reports/Definitions/{InvestmentPortfolio,BenefitsRegister}Report.php` (new); `app/Models/ScheduledReport.php`; migration `2027_03_20_000003_add_snapshot_history_to_fin_forecasts.php`; tests `tests/Feature/Finance/{InvestmentWorkflowIntegrationTest,FinanceP4CarryOverTest}.php` (new), `tests/Feature/Workflows/WorkflowStudioModuleTest.php`, `tests/Feature/Finance/FinanceWorkflowStudioTest.php` (counts) |
| **C** | `resources/js/Pages/Finance/Investments/{Index,Create,Show,Portfolio}.jsx`; `resources/js/Components/Finance/Investments/*` (25, §4); `resources/js/Components/Finance/Settings/InvestmentPolicyTab.jsx`; edits `resources/js/Config/navigation.js`, `resources/js/Utils/finance.js`, `resources/js/Pages/Finance/{Dashboard,Calendar,Settings}.jsx`, `resources/js/Pages/Finance/Reports/Index.jsx` (if needed), `resources/js/Components/Finance/Calendar/*` (if needed), `resources/js/Components/Finance/Budgets/LineFormModal.jsx`, `resources/js/Pages/BoardPacks/Show.jsx` |
| **E** | `database/seeders/FinanceInvestmentDemoSeeder.php` (new), `database/seeders/DatabaseSeeder.php`; tests `tests/Feature/Finance/{InvestmentAndBusinessCaseTest,BenefitsAndValueReviewTest}.php` (new), `tests/Feature/RouteSmokeTest.php` |

**Touched by nobody:** `ApprovalEngine.php`, `WorkflowStudioController.php`, `FinanceApprovals.php`, `BudgetAvailability.php`, `BudgetLedger.php`, `ContractSweeper.php`, `ContractService.php`, `EvidenceController.php`, the P0–P4 finance seeders, `AppServiceProvider.php`.
**Dependencies:** B needs A (models, `StageGateService`, `ValueReviewService::derive`, `PortfolioService::summary`, `InvestmentFinancials`, `BuildsInvestments`, `ApprovalProcesses` entry, `config('finance.masked_kri_codes')`). C needs only §2.6 + §7. E needs A, B and C.

---

## 9. §12 acceptance → test

| ID | criterion | test |
|---|---|---|
| FIN-05.02 | NPV/IRR/payback match reference values | `BusinessCaseCalculatorTest::test_reference_case`, `::test_irr_null_without_sign_change`; `InvestmentBackendSmokeTest::test_evaluate_endpoint` |
| FIN-05.03 | priority rank recomputed on score change | `InvestmentAndBusinessCaseTest::test_rank_recomputed_on_score_change`, `::test_weights_sum_and_rescore` |
| FIN-05.04 | gate advance requires approval | `InvestmentAndBusinessCaseTest::test_gate_advance_requires_approval`, `::test_gate_criteria_enforced`; `InvestmentWorkflowIntegrationTest::test_business_case_through_studio_inbox`, `::test_sod_on_gates` |
| FIN-05.05 | CPI/EAC computed from actuals | `InvestmentAndBusinessCaseTest::test_evm_from_actuals` |
| FIN-05.06 | benefits realised % updates on readings | `BenefitsAndValueReviewTest::test_realised_pct_updates_on_readings` |
| FIN-05.07 | value reviews scheduled on delivery and notified when due | `BenefitsAndValueReviewTest::test_reviews_scheduled_on_delivery`, `::test_sweep_notifies_once`; `InvestmentWorkflowIntegrationTest::test_value_review_notification_renders` |
| FIN-05.01 / .09 | portfolio register and dashboard | `InvestmentBackendSmokeTest::test_pages_render`; RouteSmokeTest estate test |
| FIN-05.08 (S) | links to EA capabilities/applications | `InvestmentBackendSmokeTest::test_links_in_org_only` |
| FIN-05.11 (S) | change control over tolerance | `InvestmentAndBusinessCaseTest::test_change_within_and_over_tolerance` |
| FIN-07.01 | 9 finance processes, defaults, SoD | `FinanceWorkflowStudioTest` (9), `InvestmentWorkflowIntegrationTest::test_default_approver_when_unconfigured` |
| FIN-07.03 | notification renders mail + database | `InvestmentWorkflowIntegrationTest::test_value_review_notification_renders` |
| FIN-07.05 | calendar shows investment dates and value reviews | `InvestmentWorkflowIntegrationTest::test_calendar_investment_events_and_passed` |
| FIN-07.07 | investment portfolio and benefits reports, 3 formats | `InvestmentWorkflowIntegrationTest::test_investment_reports_export` |
| FIN-07.10 | amounts masked | `InvestmentBackendSmokeTest::test_masking`, `FinanceP4CarryOverTest` (board, KRI) |
| FIN-04.11 / §5.7 | dashboard + board figures | `InvestmentWorkflowIntegrationTest::test_metrics_and_board_section` |
| Tenancy | orgs 2/3 render, org 1 empty, cross-bank 404 | `RouteSmokeTest`, `InvestmentBackendSmokeTest::test_cross_bank_404`, `FinanceTenancyAndRoutesTest` |
| P4 M5 / security M2 | snapshot history; KRI/board masking | `FinanceP4CarryOverTest` |

---

## 10. Spec ⇄ codebase conflicts (continuing from P4's C125)

| # | conflict | resolution |
|---|---|---|
| C126 | §3.5 "BAC from allocated lines"; PMBOK BAC is the approved baseline, and lines are per FY and often absent at approval. | P5-1. **Sign-off.** |
| C127 | EVM asks for CPI/CV but no EV/% complete is specified. | P5-2. **Sign-off.** |
| C128 | §6 gives model `Investment` "per gate"; one subject with several requests breaks `engine->start()` (cancels pending) and has no per-gate history. | Subject `StageGate` (P5-4). **Sign-off.** |
| C129 | §9 lists `finance.business-cases.approve/reject`. | Decided on `finance.stage-gates.approve/reject`; the business case follows its gate (P5-8). |
| C130 | §6 Board stage "(role)"; no Board role exists. | Job title "Managing Director" (md@). Alternative: a new "Board IT Committee" role + users. **Sign-off.** |
| C131 | Head of IT/CFO stages have no holders of `approve it-finance-investments`. | Grant it to Finance Approver (P5-6). **Sign-off.** |
| C132 | Calculator conventions (currency, timing, rounding) unspecified. | P5-9; base currency only. |
| C133 | Readings `evidence_id` (vault); P2-8/P3 serve finance documents only through finance routes. | `document_id` → `fin_documents` (which wraps the vault row). |
| C134 | Baseline lines are immutable; tagging an investment is a classification. | `linkLines` may tag/untag `investment_id` on baseline lines without a revision, audited (money untouched). **Sign-off.** |
| C135 | Spec has no `fiscal_year_id` on investments, but `rank(fiscalYear)` needs one. | `fiscal_year_id` = funding FY. |
| C136 | "delivery_risk" scored 1–5 is ambiguous in direction. | Inverted (P5-10). |
| C137 | "Fund line against envelope": no envelope defined. | P5-11. |
| C138 | `value_reviews.status` lacks `cancelled`; benefits of a cancelled investment. | Add `cancelled`; cancel cascades. |
| C139 | Resume after hold has no route in §9. | `finance.investments.resume`. |
| C140 | ValueReviewDue recipients may lack module access. | Filter by `view it-finance` (security L8 for new code). |
| C141 | Investment code `INV-` would collide with invoices. | `IVT-`; changes `ICR-`. |
| C142 | FIN-05.10 Application TCO (S) needs cloud cost. | Deferred to P6. |
| C143 | Calendar "Passed" panel is contract-shaped. | Separate `passedInvestments` prop; contract `passed[]` unchanged. |
| C144 | Investment money in non-base currency. | Base currency only in P5; logged. |

---

## 11. Risks, open questions, deferrals

**Sign-off needed:** C126/P5-1, C127/P5-2, C128/P5-4, C130/P5-5, C131/P5-6, C134.

**P4 deferrals:**
- **Adopted:** security **M2** (WP-B item 9; P5 adds a second finance board section, so the masking has to be generalised now), domain **M5** (WP-B item 10; isolated, small), security **L8** for the new notification only.
- **Declined (P7 hardening unless noted):** domain M1 (BvA severity on unelapsed periods; monitoring display), M2 (close acknowledgement / self-explain SoD), M3 (commitments definition; P5 uses the availability definition so the gap does not widen), M6 (accrual FX at period end; accounting change), M7 (demo polish), M8 (scoped viewers' unallocated spend), M9 (close "approvals complete" checks invoices only; investment gates are not period-bound), L1–L14; security L1, L2, L3 (`$guarded = []`: kept for consistency, fix module-wide), L4–L7, L9. P3.1 items (M10–M12, L13 decision, L14, L15) are unrelated to P5.

**Risks:**
- **R1 stuck demo approvals:** the Board stage needs md@; Steering then must be cio@ (cfo@ decided the CFO stage). If the seeder ever makes cio@/md@/cfo@/headofit@ a manager or sponsor, a stage has no decider. SMOKE's pending-step check plus the gate tinker check catch it.
- **R2 swallowed seeder errors:** P3 `attempt()` swallowed exceptions; use the P4 logging version and fail the gate on any warning.
- **R3 locked periods:** benefit readings before July hit locked periods; the seeder must use `whilePeriodsOpen()` there.
- **R4 `RouteSmokeTest` GET loop:** new route params need seeded ids; a missing resolver gives false 404s.
- **R5 IRR floats:** keep floats confined to `irr()`; everything stored or compared is bcmath strings.
- **R6 EVM performance:** Index and Portfolio must use `InvestmentFinancials::forInvestments` (grouped queries), not per-row `evm()`.
- **R7 KRI masking reach:** `KriController` and `DashboardController` are platform files; mask only `KRI-FIN-VAR/UNB` values and leave every other KRI untouched (`KriDashboardsModuleTest` must stay green).
- **R8 suite time:** ~70 more tests, about +4 min.

**Deferred from P5 scope:** FIN-05.10 Application TCO (P6, C142); investment API endpoints (FIN-07.09 S, P7); value reviews in the daily digest; webhook channel; multi-currency business cases (C144); Change Management auto-link from a change to an investment (link type exists, no automation).
