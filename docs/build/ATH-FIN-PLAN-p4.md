# ATH-FIN-PLAN-p4: IT Financial Management, Phase P4 (Budget monitoring, variance analysis and reporting)

**Spec:** ATH-FIN-001 §3.4 FIN-04.01–.08, .10, .11 (.09 trend is S, partly delivered by the 12-month chart); §3.7 FIN-07.07 reports (the 8 listed below; portfolio, cloud and chargeback stay with P5/P6); §4.6; §5.6; §5.7; §6 `finance.period_reopen`; §7 `BudgetVarianceNotification`, `PeriodCloseReminderNotification`, `fin:variances-sweep`, `fin:period-reminders`; §9 Monitoring and Reports routes; §10 `Dashboard.jsx`, `Monitor/{BvA,Variances,Forecast,PeriodClose}.jsx`, `Reports/Index.jsx`; §12 FIN-04, plus the FIN-01 and FIN-07 items P4 touches.
**Carry-overs folded in:**
- P3 domain **M9** (period close ignores pending accruals and prepayment releases).
- P3 domain **M8** (the accrual cut-off counts submitted invoices).
- P3 domain **M7** (accruals exclude VAT). It is in the same `propose()` code and changes the actuals that BvA reports.
- P0 **D10**, first half (a guard against closing a future-dated period).
- P0 **C7** (reopen and the close checklist).

The other P3.1 items stay deferred (§11).
**Branch:** `feature/it-finance-p4`, from P3 3add6af.

**Order:**
1. **WP-A backend** comes first.
2. **WP-B frontend** may start **in parallel with WP-A**. It needs only the props contract in §7 and `npm run build`; Ziggy names resolve at runtime.
3. **WP-C workflow** starts after A.
4. **WP-E QA** starts after A, B and C.

The file sets in §8 do not overlap.

**DB rule (overrides every command):** never touch `it_security_risk`.
- **WP-A, then WP-C:** these run one after the other on `it_security_risk_fin_test`: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_fin_test php vendor/bin/phpunit --filter '<Classes>'`.
- **WP-E:** `it_security_risk_fin_qa`.
- **Reviewers:** a throwaway `it_security_risk_<agent>_test`, created and dropped with `/opt/homebrew/opt/postgresql@17/bin/createdb` and `dropdb`. Disk is about 94 % full.
- **Orchestrator only:** the suite on `it_security_risk_test`; seed and SMOKE on `it_security_risk_scratch`.
- **WP-B:** `npm run build` only. Run Pint only on the PHP files you touched.

**Route collision check (done, read-only):** `route:list --except-vendor --json` returns 873 routes, 186 of them `finance.*`.
- No `finance.*` name or URI contains dashboard, monitor, variance, forecast, bva, report or period-close.
- The only existing period routes are `finance.periods.close` and `finance.periods.lock`. P4 rewires them; the names and URIs are unchanged.
- The platform names `reports.*`, `board-packs.*`, `monitoring.*`, `kri.*` and `dashboard` are not prefixed `finance.`, so there is no clash.
- `GET /it-finance` (`finance.dashboard`) is free.
- **No collisions.**

**State found in code (verified):**
- **Settings:** `fin_settings` already has `variance_amber_pct` (default 5), `variance_red_pct` (10), `variance_abs_floor` (seeded at 1,000,000 for KHB and 5,000,000 for FBN) and `eac_method` (`Setting::EAC_METHODS = remaining_budget, run_rate`). Settings › General and Settings › Variance already edit them.
- **Line overrides:** budget lines carry `variance_amber_pct` and `variance_red_pct` overrides. Lines have no owner of their own; the budget's `owner_id` is the line owner.
- **Current budget:** comes from `BudgetLedger::currentByPeriod($line)`. `BudgetLine::current_base_amount` gives the annual figure.
- **Current baseline:** the current baseline of a fiscal year is `fin_budgets.status = 'baseline'`. A re-baseline re-creates the lines through `source_line_id`; `BudgetLedger::lineageIds($line, true)` walks back within the same fiscal year.
- **Actuals and commitments:** counted actuals are `fin_actuals` with `match_status ≠ duplicate`. Unmatched and unbudgeted actuals have no `budget_line_id`. `BudgetAvailability::forLines()` gives annual committed, actual and available in bulk.
- **Seeded periods:** in FY2026, Jan–Jun are locked, Jul–Sep closed and Oct–Dec open. The seeder set them directly (`FinanceFoundationSeeder::fiscalYears`), so there are no close records.
- **Seeded actuals:** the Jan–Sep actuals are about annual/12 × a factor per line: 1.15, 1.07, 0.85 or 0.92–1.00. Period-level variances mostly sit below the absolute floors. YTD variances at Sep do not (C103).
- **Period close:** `FiscalCalendarService::closePeriod` and `lockPeriod` are the primitives (ordering, row locks, prior fiscal year). `FinanceConfigurationTest` calls them directly. `FinancePermissionsTest::test_period_close_and_import_need_their_own_permissions` posts `finance.periods.close` on an empty FY2027 Jan and expects no errors, so P4 must adjust that test (WP-A).
- **Accruals:** `AccrualService::propose()` counts invoices in `submitted, approved, scheduled, paid` with no period cut-off (M8), and accrues at the net unit cost (M7). Nothing checks proposed accruals or releases at close (M9).
- **Idempotency:** `ContractSweeper::claim(Model, key, offset, users)` is public. Its existence check ignores the recipient, so one log row per (subject, key, offset) blocks any resend. `notification_key` is limited to 64 characters.
- **Approvals:** `ApprovalEngine::assertEligible` does not check the process permission. The inbox and the notifier do. `recordUrl()` passes `subject_id` as the only route parameter.
- **Scheduled reports:**
  - `ScheduledReport::TYPES` has 3 types, and `report_type` is varchar(32).
  - `reports:run-due` signs in the bank's first user and calls `ReportFigures::summary($type, $orgId)`.
  - The seeder that creates the scheduled reports, `ReportsIntegrationsSettingsDemoSeeder`, **skips a bank that already has any schedule** (R5).
- **KRIs:** `KriMeasures::MEASURES` maps code to a private method. `KriDashboardsDemoSeeder` copies **every Kano `KRI-%` row** to both banks and records their readings, measuring platform-source ones through `KriService`. It runs after the finance seeders (DatabaseSeeder line 175).
- **Board packs:** `BoardPackBuilder::SECTIONS` maps a section key to a `section{Key}()` method. Templates are global (no `organization_id`). `BoardPacks/Show.jsx` renders each section by key.
- **No XLSX library** is installed. `ext-zip` and `xmlwriter` are present, and `ZipArchive` is already used (`EvidencePackGenerator`). PDF exports use `App\Services\Ea\PdfWriter`, as in `FinanceCalendarService::pdf`. The CSV formula guard is the `$cell` closure in `FinanceCalendarService::csv`.
- **Process counts:** Workflow Studio has 18 processes, 7 of them finance:
  - `WorkflowStudioModuleTest` lines 93 and 195 assert 18.
  - `FinanceWorkflowStudioTest` lines 67, 88, 195, 203 and 207 assert 7.
  - `RouteSmokeTest` line 251 asserts 7.

  P4 makes it **19 / 8**.

---

## 0. Decisions (P4-1 … P4-14). The orchestrator records these in ATH-FIN-003. The C-items with the reasons are in §10.

| # | decision | sign-off? |
|---|---|---|
| P4-1 | **Variance basis (C103).** One note per (line, period). It measures **YTD through the end of that period**: `variance = actual_ytd − budget_ytd` (base currency; positive means overspend). The period's own variance is stored for information. Both directions are flagged (`over` / `under`). Severity is **red** when `|pct| ≥ red_pct` **and** `|variance| ≥ abs_floor`, and **amber** at amber_pct with the same floor. The line overrides beat the settings. When `budget_ytd = 0` and `actual_ytd ≠ 0`, pct is null and severity is red once the amount reaches the floor. | **yes** |
| P4-2 | **Note identity (C118).** `line_key` is the lineage root (the oldest same-FY line id). The unique key is (org, line_key, period_id). On re-evaluation `budget_line_id` moves to the current baseline line, so a re-baseline never duplicates a note or a notification. | |
| P4-3 | **Note states.** `open → explained → acknowledged`. A breach that has gone away moves `open → cleared`; a renewed breach moves `cleared → open`. An explained or acknowledged note keeps its status and gets `is_breaching=false`. Explaining again resets the acknowledgement. The acknowledger must not be the author (SoD) and needs `close it-finance-periods`. | |
| P4-4 | **Notifications.** `BudgetVarianceNotification` is sent once per (note, severity): key `variance:{severity}`, offset 0. An amber note that escalates to red sends red once. A red note that drops back to amber sends nothing. Recipients are the budget owner, the cost-centre owner and the finance users (`ContractService::financeUsers`). **No amounts or percentages** in any notification (C110). | |
| P4-5 | **Close checklist (M9 and FIN-04.05).** Items: `period_ended` (D10), `earlier_periods_closed`, `actuals_posted`, `accruals_done`, `invoices_approved`, `variances_explained`, `cloud_imported`. Each item is `pass`, `fail`, `warn` or `n_a`; any `fail` blocks the close. Definitions are in §2.4. The close re-evaluates the period's variances first. | |
| P4-6 | **Reopen (C101, C102, C116).** The approval subject is the **`PeriodClose` record** (one row per close; the reopen fields live on it). The process permission is **`approve it-finance`**, because the CFO stage would otherwise have no deciders: the CFO holds Finance Approver, not `close it-finance-periods`. Requesting a reopen needs `close it-finance-periods`. Only the bank's **latest non-open period** can be reopened: no later period closed or locked, including P1 of the next FY. Approval puts the period back to `open` and clears `closed_*` and `locked_*`; the history stays in `fin_period_closes`. | **yes** |
| P4-7 | **EAC (C113).** The as-of period is the last regular period with `ends_on < today`. `actual_ytd` covers the periods up to and including the as-of period.<br>**remaining budget:** `actual_ytd + Σ current budget of later periods`.<br>**run-rate:** `actual_ytd / elapsed × periods`.<br>**When nothing has elapsed:** EAC = the current budget.<br>**Manual override:** an override with a reason (at least 10 characters) replaces the computed EAC until it is cleared.<br>**Forecast variance:** `EAC − current FY budget`, with the original baseline also shown.<br>**Stored rows:** `fin_forecasts` holds only manual overrides and the snapshots taken at close (C117). | |
| P4-8 | **Burn (FIN-04.04).**<br>**Monthly burn:** `actual_ytd / elapsed`.<br>**Months remaining:** `available / burn`.<br>**Exhaustion date:** the as-of end plus the months remaining. It is null when the burn is zero or less. | |
| P4-9 | **Committed** stays annual and as of today (P3-1), and is not phased by period (C112). In BvA it shows as "open commitments". | |
| P4-10 | **Reports export XLSX, CSV and PDF.** XLSX comes from a small writer with no dependencies, built on ZipArchive (C106). This applies to report exports only; the workbook and imports stay CSV (P1-2). | **yes** |
| P4-11 | **Masking (C111).** Without `view it-finance-amounts`, every amount, variance % and EAC figure in the monitoring props, report files and the board-pack finance section is null or blank. Severity, status, counts and dates stay visible. | |
| P4-12 | **Corrective action as an Issue (C105).** Issue source `finance_variance`, with `model => null` so it appears in no source picker. The issue is raised by the system, like KRI issues: it needs `edit it-finance` plus line visibility, not `create issues`. The owner is the cost-centre owner. Severity is `high` for red and `moderate` for amber. | **yes** |
| P4-13 | **Dashboards (C114).** One `Finance/Dashboard` page. Cost-centre owners see it scoped to their cost centres (`FinancePolicy::visibleCostCentreIds`). Finance and the CIO see everything. The "CIO / owner / Finance dashboard" choice is a filter, not three pages. | |
| P4-14 | **Accrual fixes (M7, M8).** Received-not-invoiced is set against invoices that are **approved, scheduled or paid** in a period starting on or before the accrual period's end; submitted invoices no longer count. The accrual amount includes VAT at the PO's tax type, so it is on the same cost basis as P3-3. | |

---

## 1. Conventions to copy (same as P1–P3)

- **Models:** `use FinanceRecord;` (plus `SoftDeletes` where noted), explicit `$table`, `$guarded = []`, enums as `const`s, casts `decimal:2` and `date`.
- **Policy:** FinancePolicy is auto-mapped; no `GeneratesSequentialCode` this phase (no spec codes).
- **Services:** `public const TRANSITIONS = ['action' => ['from' => [...], 'to' => '...']]`. Refusals use `LogsFinanceEvents::fail($msg, $key)`, with key `workflow`, `approval` or `checklist`. Named audit events use `audit()`.
- **Approvals:** only through `FinanceApprovals`, copying the `TerminationService::approve/reject/withdraw` skeleton exactly: `assertZeroStepSoD`, then the unconfigured `assertDefaultApprover`, else `approve()` returning the stage message.
- **Money:** bcmath strings at scale 2, all in base currency. Today is `FinanceClock::today()`.
- **Unauthenticated work:** sweeps and commands use `withoutGlobalScopes()->where('organization_id', …)`, with a per-tenant try/catch and `report($e)`.
- **Masking:** a new self-contained trait, `Concerns/PresentsMonitoring::renderMonitoring()`. It must not be combined with the other `Presents*` traits, whose method names collide.
- **Events and listeners:** events live in `app/Events/Finance` (WP-A dispatches). Listeners are auto-discovered in `app/Listeners/Finance` (WP-C) and never registered in a provider.
- **UI:**
  - MD3-in-Tailwind, Material Symbols through `Components/Material/{Icon,Chip}`, `PageHeader`, navy `#0A1F44` and gold `#C9A86A`.
  - recharts for charts (as `Components/Finance/Budgets/Charts.jsx` does), and `Components/Finance/Budgets/Money.jsx` for amounts.
  - No MUI, no `rounded-full` pills, no heroicons in new code. Pages must be usable at 375 px.
  - **Never chain `.post()`/`.put()` onto `form.transform()`.**

---

## 2. WP-A: backend (fin-backend-engineer, DB `it_security_risk_fin_test`)

### 2.1 `config/finance.php` (append)
```php
'monitoring' => [
    'explain_amber_at_close' => false,     // amber notes only warn at close
    'allow_early_close' => false,          // D10: a period cannot close before its end date
    'override_reason_min' => 10,
    'close_reminder_working_days' => [5],  // WP-C
    'close_overdue_working_days' => [3],   // WP-C: still open 3 working days after the end
    'top_variances' => 10,
],
'reports' => ['max_rows' => 20000, 'pdf_max_rows' => 1500, 'preview_rows' => 50],
```

### 2.2 Migration `database/migrations/2027_03_10_000001_create_it_finance_monitoring_tables.php`
Use the Schema builder only. `$this->base($t)` is the id, an indexed `organization_id` and a `created_by_id` FK to users with nullOnDelete. Every table also has timestamps. The `down()` drops the four tables in reverse order.

| table | columns | indexes / FKs |
|---|---|---|
| `fin_forecasts` | base; `fiscal_year_id` FK restrict; `budget_line_id` FK fin_budget_lines cascade; `line_key` ubig; `period_id` FK fin_periods null nullOnDelete (as-of); `method` s16 (`remaining_budget`, `run_rate` or `manual`); `amount` dec(18,2) (line currency); `base_amount` dec(18,2); `reason` text null; `set_by_id` FK users null; `is_snapshot` bool default false; `cleared_at` ts null; `cleared_by_id` FK users null | idx(org, fiscal_year_id, line_key); idx(budget_line_id); idx(org, period_id, is_snapshot) |
| `fin_variance_notes` | base; `fiscal_year_id`, `period_id` FK restrict; `budget_line_id` FK restrict; `line_key` ubig; `cost_centre_id` FK fin_cost_centres restrict; `basis` s8 default `ytd`; `budget_amount`, `actual_amount`, `variance_amount` dec(18,2); `variance_pct` dec(9,2) null; `period_variance_amount` dec(18,2) null; `direction` s8; `severity` s8; `peak_severity` s8; `is_breaching` bool default true; `status` s16 default `open`; `explanation`, `corrective_action` text null; `issue_id` FK issues null nullOnDelete; `author_id` FK users null; `explained_at` ts null; `acknowledged_by_id` FK users null; `acknowledged_at` ts null; `evaluated_at` ts | **unique(organization_id, line_key, period_id)**; idx(org, fiscal_year_id, period_id, severity); idx(org, status) |
| `fin_variance_comments` (C104) | base; `variance_note_id` FK cascade; `author_id` FK users restrict; `body` text | idx(variance_note_id) |
| `fin_period_closes` | base; `fiscal_year_id`, `period_id` FK restrict; `status` s16 (`closed`, `locked` or `reopened`); `checklist` json; `closed_by_id` FK users null; `closed_at` ts null; `locked_by_id` FK users null; `locked_at` ts null; `reopen_status` s16 null (`submitted`, `approved`, `rejected` or `withdrawn`); `reopen_reason` text null; `reopen_requested_by_id` FK users null; `reopen_requested_at` ts null; `reopen_approval_request_id` FK approval_requests null nullOnDelete; `reopen_decided_by_id` FK users null; `reopen_decided_at` ts null; `reopen_rejection_reason` text null; `reopened_at` ts null | idx(org, period_id, id); idx(org, reopen_status) |

There are no permission changes and no `fin_settings` columns. The thresholds already exist.

### 2.3 Models `app/Models/Finance/` (new, all with `use FinanceRecord`)

| model | constants / casts | relations | accessors / methods |
|---|---|---|---|
| `Forecast` | `METHODS = ['remaining_budget','run_rate','manual']`; decimals; `is_snapshot` bool | fiscalYear, line (`budget_line_id`), period, setBy | `scopeActiveOverride` (manual, `cleared_at` null, not a snapshot) |
| `VarianceNote` | `SEVERITIES = ['amber','red']`, `DIRECTIONS = ['over','under']`, `STATUSES = ['open','explained','acknowledged','cleared']`, `NEEDS_EXPLANATION = ['open']`; decimals, booleans, datetimes | fiscalYear, period, line, costCentre, issue (`App\Models\Issue`), author, acknowledgedBy, comments (hasMany, oldest first) | `label` ("Sep 2026 · KHB-IT-NET · Cisco SmartNet AMC"), `needs_explanation` (open and red, or amber when `explain_amber_at_close`), `scopeForPeriod`, `scopeForFiscalYear`, `scopeRed`, `scopeVisibleTo(User)` (by cost centre) |
| `VarianceComment` | — | note, author | — |
| `PeriodClose` | `STATUSES = ['closed','locked','reopened']`, `REOPEN_STATUSES = ['submitted','approved','rejected','withdrawn']`; `checklist` array | period, fiscalYear, closedBy, lockedBy, reopenRequestedBy, reopenDecidedBy, approvalRequest | `period_status` (the period's live status: closed or locked), `period_label` ("FY2026 Sep 2026"), `sodExcludedIds()` (`[reopen_requested_by_id, created_by_id]`, filtered), `approvalBaseAmount(): '0'` |

**Edits:**
- `Period.php`: add `closes()` (hasMany PeriodClose, latest first) and `latestClose()` (hasOne latestOfMany).
- `BudgetLine.php`: add `forecasts()` and `varianceNotes()` (hasMany).
- `app/Models/Issue.php`: add to `SOURCES` the entry `'finance_variance' => ['label' => 'Budget variance', 'model' => null]` (C105).

### 2.4 Services `app/Services/Finance/` (new unless marked)

**`MonitoringService`** (uses `BudgetLedger`, `BudgetAvailability`, `ForecastService`, `VarianceService`, `FinanceSettings`). Every query is grouped, with no per-line or per-period N+1.

```php
public const GROUP_BY = ['cost_centre','tower','cost_pool','category','gl_account','legal_entity','vendor','rgt','capex','line'];
public const DRILL = ['cost_centre' => 'tower', 'tower' => 'category', 'category' => 'line', 'cost_pool' => 'line', 'gl_account' => 'line',
                      'legal_entity' => 'cost_centre', 'vendor' => 'line', 'rgt' => 'cost_centre', 'capex' => 'cost_centre', 'line' => null];
public function baselineLines(int $orgId, int $fiscalYearId, ?array $costCentreIds = null, array $filters = []): Collection; // current baseline lines (status baseline), filtered
public function asOfPeriod(int $orgId, int $fiscalYearId, ?CarbonInterface $today = null): ?Period;   // last regular period with ends_on < today
public function bva(int $orgId, array $filters, User $viewer): array;          // the matrix (§7.2); cost-centre visibility applied
public function lineFigures(Collection $lines, int $fiscalYearId, ?int $fromSeq = null, ?int $toSeq = null): array; // line_id => budget/actual by period + YTD + FY (bulk)
public function transactions(BudgetLine $line, ?int $fromPeriodId, ?int $toPeriodId): array;  // §7.2 transactions JSON
public function forecast(BudgetLine $line): array;                              // delegates to ForecastService::eac (spec §5.6 name)
```

- **Budget per period:** the line periods plus the adjustments, grouped by `(budget_line_id, period_id)`.
- **Actual per period:** counted actuals grouped by `(budget_line_id, period_id)` over each line's **lineage ids**. The lineage maps are built once, as `BudgetAvailability::forLines` does.
- **Range:** a range sums the periods from `from_period_id` to `to_period_id` by sequence. The default is P1 through the as-of period, or the whole FY when nothing has elapsed.
- **Unallocated row:** counted FY actuals with `budget_line_id` null (unmatched or unbudgeted), or on lines outside the visible set and filters, form the `unallocated` row. It appears only when no line-specific filter is set.
- **Reconciliation:** `reconciliation.difference = ledger_actual − matrix_actual` must be `'0.00'`. Here `ledger_actual` is the counted FY actuals within the user's visibility.

**`ForecastService`**
```php
public function eac(BudgetLine $line, ?Period $asOf = null): array;   // {method, eac_remaining_budget, eac_run_rate, manual:{amount,reason,set_by,set_at}|null, eac,
                                                                       //  baseline, budget, actual_ytd, variance, variance_pct, periods_elapsed, periods_total, burn_monthly, months_remaining, exhaustion_on}
public function eacForLines(Collection $lines, int $fiscalYearId, ?Period $asOf = null): array; // bulk, line_id => eac()
public function override(BudgetLine $line, string $baseAmount, string $reason, User $by): Forecast;   // baseline line only; reason ≥ min; plain-decimal ≥ 0; clears the previous override
public function clear(BudgetLine $line, User $by, string $reason): void;
public function snapshot(Period $p, User $by): int;                   // one is_snapshot row per baseline line, method = settings eac_method
```

**`VarianceService`**
```php
public const TRANSITIONS = [
  'explain'     => ['from' => ['open','explained','acknowledged','cleared'], 'to' => 'explained'],
  'acknowledge' => ['from' => ['explained'], 'to' => 'acknowledged'],
];
public function thresholds(int $orgId, ?BudgetLine $line = null): array;   // {amber_pct, red_pct, abs_floor}
public static function classify(string $budget, string $actual, string $amberPct, string $redPct, string $floor): array; // pure: {severity:?string, direction, variance, pct:?string}
public function evaluate(Period $p, ?User $by = null): array;             // {created, updated, cleared, escalated}; upsert on (org,line_key,period) under a transaction
public function evaluateDue(?CarbonInterface $today = null): int;          // sweep: every org, regular periods of open FYs with ends_on < today and status != locked
public function explain(VarianceNote $n, string $explanation, ?string $action, User $by): VarianceNote; // ≥ 20 chars; resets acknowledgement
public function acknowledge(VarianceNote $n, User $by): VarianceNote;       // SoD: by ≠ author_id
public function comment(VarianceNote $n, string $body, User $by): VarianceComment;
public function raiseIssue(VarianceNote $n, User $by, ?string $title = null): Issue; // once per note (refuse if issue_id is set and open); IssueWorkflow::open([... 'source_type' => 'finance_variance', 'source_id' => $n->id, 'source_reference' => $n->label, 'owner_id' => cost-centre owner, 'severity' => red ? 'high' : 'moderate'])
public function unexplained(Period $p): Collection;                         // notes still needing an explanation (P4-5)
```
- `evaluate()` dispatches **`App\Events\Finance\VarianceBreached(int $noteId, string $severity)`** for a new breaching note, and when a note escalates to a higher peak severity. WP-C's listener handles the idempotent send.
- `evaluate()` works on any period, including locked ones (the seeder uses this). The sweep skips locked periods.

**`PeriodCloseService`** (uses `FiscalCalendarService`, `VarianceService`, `ForecastService`, `AccrualService`, `FinanceApprovals`)
```php
public const PROCESS = 'finance.period_reopen';
public const PERMISSION = 'approve it-finance';
public const TRANSITIONS = [
  'close'           => ['from' => ['open'], 'to' => 'closed'],
  'lock'            => ['from' => ['closed'], 'to' => 'locked'],
  'request_reopen'  => ['from' => ['closed','locked'], 'to' => 'submitted'],   // period status → PeriodClose.reopen_status
  'approve_reopen'  => ['from' => ['submitted'], 'to' => 'approved'],          // period → open, PeriodClose.status → reopened
  'reject_reopen'   => ['from' => ['submitted'], 'to' => 'rejected'],
  'withdraw_reopen' => ['from' => ['submitted'], 'to' => 'withdrawn'],
];
public function checklist(Period $p): array;   // {period:{id,name,status}, items:[{key,label,status,count,detail,href}], can_close, blockers:string[]}
public function close(Period $p, User $by): PeriodClose;   // evaluate(); checklist(); on fail dispatch PeriodCloseBlocked and fail('checklist', joined blockers); else closePeriod() + snapshot() + PeriodClose(status closed, checklist)
public function lock(Period $p, User $by): PeriodClose;    // lockPeriod(); latest PeriodClose → locked (creates one if a legacy close had none)
public function requestReopen(Period $p, string $reason, User $by): PeriodClose; // reason ≥ 20; latest-non-open rule; no other submitted request; FinanceApprovals::start
public function approveReopen(PeriodClose $c, User $by, ?string $note = null): string; // Termination skeleton; final → reopen applied under lockForUpdate; re-checks the latest-non-open rule
public function rejectReopen(PeriodClose $c, User $by, string $note): PeriodClose;
public function withdrawReopen(PeriodClose $c, User $by): PeriodClose;   // the requester only
```

**Checklist items** (`status`, `count`, `detail`; `href` is a Ziggy URL to fix the item):

| key | pass | fail | n_a / warn |
|---|---|---|---|
| `period_ended` | `ends_on < today` | not ended, unless `allow_early_close` | — |
| `earlier_periods_closed` | no earlier open period (this FY or the prior one) | otherwise | — |
| `actuals_posted` | ≥ 1 counted actual in the period **and** 0 `unmatched` actuals in it | otherwise; the count is the unmatched rows | `n_a` when the FY has no baseline lines |
| `accruals_done` (**M9**) | 0 proposed `accrual` rows, 0 proposed `prepayment_release` rows in the period, **and** 0 new candidates from `AccrualService::candidates($p)` | otherwise; the detail splits the three counts | — |
| `invoices_approved` | 0 invoices with this `period_id` in `draft` or `submitted` | otherwise | `warn` when a disputed invoice is in the period |
| `variances_explained` | `unexplained($p)` is empty | red notes open | `warn` when amber notes are open (fail if `explain_amber_at_close`) |
| `cloud_imported` | — | — | always `n_a` until P6 (C107) |

**`AccrualService` (edit, M7/M8/M9)**
- Extract `public function candidates(Period $p): array` from `propose()`. It returns a list of `{po_id, dedupe_key, amount, base_amount, tax_amount}` and is a dry run that skips existing dedupe keys.
- `propose()` now just persists the candidates.
- **M8:** invoiced = the invoice lines of invoices in `approved, scheduled, paid` whose period's `starts_on ≤ $p->ends_on`.
- **M7:** the amount includes VAT through `FinanceTax::compute(net, currency, PO tax type, null)['total_amount']`. `base_amount` is the total times the PO rate.
- Add `public function pendingFor(Period $p): array{accruals:int, releases:int, candidates:int}`.

**`FinanceMetrics`** (spec §5.7):
```php
public function summary(int $orgId, ?int $fiscalYearId = null, ?array $costCentreIds = null): array; // §7.1 metrics keys; fiscal year defaults to is_current
public function monthly(int $orgId, int $fiscalYearId, ?array $costCentreIds = null): array;         // monthlyBvA
public function spendByTower(int $orgId, int $fiscalYearId, ?array $costCentreIds = null): array;
public function topVariances(int $orgId, int $fiscalYearId, ?array $costCentreIds = null, int $n = 10): array; // the as-of period's notes, by |variance| desc
public function pendingApprovals(User $viewer, int $limit = 10): array;  // pending ApprovalRequest rows with process like 'finance.%'; `mine` as the inbox computes it (C122)
public function boardFigures(int $orgId): array;                          // §7.1 board section shape
```
- Contracts use the existing scopes: `Contract::live()`, `dueWithin(30/60/90)` and `passed(today)`.
- Overdue invoices use `Invoice::UNPAID` minus disputed, with `due_on < today`.
- Unbudgeted spend is expense requests that are unbudgeted or over budget and not terminal, plus counted actuals with `match_status = unbudgeted`.
- The P5 and P6 keys (`investments_by_stage`, `benefits_realised_pct`, `cloud_mtd`, `cloud_anomalies_open`) return `[]` or null.

**`FinanceKriSource`.** Each method returns `[float $value, string $note]`; WP-C registers them.
- `budgetVariancePct(int $orgId)`: the absolute YTD variance % of the current FY.
- `unbudgetedSpendPct(int $orgId)`: unbudgeted actual as a % of actual YTD.
- `overdueInvoices(int $orgId)`.
- `contractsPastNotice(int $orgId)`: live contracts whose notice deadline has passed with no decision, using `passed()`.

**Report framework** (`app/Services/Finance/Reports/`; WP-A owns the framework and the BvA definition):
- **`FinanceReport`** (abstract):
  ```php
  abstract public function key(): string;
  abstract public function title(): string;
  public function description(): string;
  public function filters(): array;   // subset of ['fiscal_year_id','period_range','cost_centre_id','window','as_of']
  abstract public function columns(): array;   // [key => ['label'=>…, 'type'=>'text|money|pct|int|date']]
  abstract public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable;
  public function totals(array $rows): ?array;
  ```
- **`FinanceReports`** (registry):
  ```php
  public const DEFINITIONS = [
    'budget_book' => BudgetBookReport::class, 'bva' => BvaReport::class,
    'variance_commentary' => VarianceCommentaryReport::class, 'commitments' => CommitmentsReport::class,
    'invoice_ageing' => InvoiceAgeingReport::class, 'unbudgeted' => UnbudgetedSpendReport::class,
    'contract_register' => ContractRegisterReport::class, 'vendor_spend' => VendorSpendReport::class,
  ];
  ```
  The classes live in `Reports\Definitions\`. `available()` filters the map with `class_exists`, so the 7 classes WP-C builds appear when they land. Methods:
  ```php
  run(string $key, int $orgId, array $filters, User $viewer): array  // {columns, rows, totals, row_count, truncated}
  figures(string $key, int $orgId): array                            // {report, rows, totals}, for ScheduledReport runs
  ```
  `run()` applies the cost-centre visibility and caps at `max_rows`. Money and pct values are null when the viewer lacks amounts.
- **`ReportExporter`:**
  ```php
  csv(array $result, string $title): string   // BOM; the formula guard copied from the calendar
  xlsx(array $result, string $title): string  // XlsxWriter
  pdf(array $result, string $title): string   // PdfWriter landscape; rows beyond pdf_max_rows give a "truncated: export CSV/XLSX" line
  ```
- **`XlsxWriter`:** `addSheet(string $name, array $header, iterable $rows, array $types): void` and `render(): string`. Through ZipArchive it writes:
  - `[Content_Types].xml`, `_rels/.rels`, `xl/workbook.xml` and `xl/_rels/workbook.xml.rels`;
  - `xl/styles.xml`, with a bold header and number format `#,##0.00`;
  - `xl/worksheets/sheet1.xml`, with inline strings and numeric `<v>` values.

  When `class_exists('ZipArchive')` is false it gives a 422 saying "XLSX is not available on this server".
- **`Definitions/BvaReport.php`** (WP-A): one row per line, with cost centre, tower, line, budget, actual, variance and variance % for the range, plus the FY budget and EAC. It is built from `MonitoringService::lineFigures`. The `finance.monitor.bva.export` route reuses it with the drill filters, so "export at every level" comes from the same filters.

**`app/Services/Reports/ReportFigures.php` (edit).** In `summary()`, add `'finance' => app(FinanceMetrics::class)->boardFigures($orgId)` and `str_starts_with($type, 'finance.') => app(FinanceReports::class)->figures(substr($type, 8), $orgId)` ahead of the default branch.

**`FiscalCalendarController` (edit).** `closePeriod` and `lockPeriod` now call `PeriodCloseService::close` and `lock`. The redirect, flash and route names stay unchanged. The primitives in `FiscalCalendarService` are untouched.

### 2.5 Events (new, `app/Events/Finance/`)
- `VarianceBreached(public int $noteId, public string $severity)`.
- `PeriodCloseBlocked(public int $periodId, public array $blockers, public int $byUserId)`, dispatched before the refusal is thrown and outside the transaction.

### 2.6 Controllers, requests, policy and masking
- **`app/Http/Controllers/Finance/FinanceDashboardController.php`:** `index`.
- **`MonitorController.php`:**
  - `bva`, `bvaExport`, `transactions(BudgetLine $line)`
  - `forecast`, `forecastOverride(BudgetLine $line)`, `forecastClear(BudgetLine $line)`
  - `variances`, `explain(VarianceNote $note)`, `comment`, `acknowledge`, `raiseIssue`
  - `evaluate(Period $period)`
- **`PeriodCloseController.php`:** `index`, `checklist(Period)` (JSON), `close`, `lock`, `requestReopen(Period)`, `approve(PeriodClose)`, `reject`, `withdraw`, and `show(PeriodClose)`, which redirects to `finance.monitor.period-close?fiscal_year_id=&period_id=`.
- **`FinanceReportController.php`:** `index`, which returns the preview through `Inertia::optional`, and `show(string $report)`, which downloads with `?format=xlsx|csv|pdf`, default csv.
- **`Concerns/PresentsMonitoring.php`:**
  - `MON_MONEY_KEYS = budget, committed, actual, available, forecast, eac, eac_remaining_budget, eac_run_rate, baseline, variance, variance_pct, period_variance, budget_ytd, actual_ytd, variance_ytd, budget_total, forecast_fy, forecast_variance_pct, unbudgeted_amount, burn_monthly, monthly, months_remaining, exhaustion_on, amount, base_amount, remaining_base, ledger_actual, matrix_actual, difference, cumulative_budget, cumulative_actual, abs_floor, capex, opex, run, grow, transform`.
  - `MON_MONEY_LISTS = ['by_period']`.
  - It also provides `fiscalYearsMini()`, `periodsMini()`, `visibleCostCentresMini()` and `userMini()`.
- **Requests** in `app/Http/Requests/Finance/`:
  - `MonitorFilterRequest`: every BvA, report and dashboard filter, with ids existence-checked against the user's organization, and `group_by` and `view` restricted with `Rule::in`.
  - `ForecastOverrideRequest`: `amount` matching `FinanceSettings::DOA_AMOUNT`, and `reason`.
  - `VarianceExplainRequest`.
  - `ReopenPeriodRequest`.
- **`app/Policies/Finance/FinancePolicy.php` (edit):**
  ```php
  overrideForecast(User, BudgetLine)    // edit it-finance + view it-finance-amounts + sameOrg + cost centre visible
  explainVariance(User, VarianceNote)   // edit it-finance + sameOrg + cost centre visible
  acknowledgeVariance(User, VarianceNote) // close it-finance-periods + sameOrg
  requestReopen(User, Period)           // = closePeriod
  decideReopen(User, PeriodClose)       // approve it-finance + sameOrg (the engine narrows the stage)
  exportFinance(User)                   // export it-finance
  ```
  `costCentreVisible()` gains a branch for `VarianceNote` (by `cost_centre_id`) and `Forecast` (by its line).

### 2.7 Routes `routes/web.php`
These go inside the existing `finance.` group, after the P3 block, with their `use` lines.
```
GET    /                                   finance.dashboard                        (view it-finance)
GET    /monitor/bva                        finance.monitor.bva
GET    /monitor/bva/export                 finance.monitor.bva.export               + permission:export it-finance (200 empty CSV when no FY)
GET    /monitor/lines/{line}/transactions  finance.monitor.transactions  whereNumber (JSON)
GET    /monitor/forecast                   finance.monitor.forecast
GET    /monitor/variances                  finance.monitor.variances
GET    /monitor/period-close               finance.monitor.period-close
GET    /periods/{period}/close-checklist   finance.periods.close-checklist (JSON)
GET    /period-closes/{periodClose}        finance.period-closes.show (redirect)
GET    /reports                            finance.reports.index
GET    /reports/{report}                   finance.reports.show   whereIn(report, keys) + permission:export it-finance
permission:edit it-finance:
POST   /monitor/forecast/{line}            finance.monitor.forecast.override
DELETE /monitor/forecast/{line}            finance.monitor.forecast.clear
POST   /monitor/variances/{note}/explain   finance.monitor.variances.explain
POST   /monitor/variances/{note}/comment   finance.monitor.variances.comment
POST   /monitor/variances/{note}/raise-issue finance.monitor.variances.raise-issue
permission:close it-finance-periods:
POST   /monitor/variances/{note}/acknowledge finance.monitor.variances.acknowledge
POST   /periods/{period}/evaluate-variances finance.periods.evaluate-variances
POST   /periods/{period}/reopen            finance.periods.reopen
POST   /period-closes/{periodClose}/withdraw finance.period-closes.withdraw
(existing, rewired) POST /periods/{period}/close|lock  finance.periods.close|lock
permission:approve it-finance:
POST   /period-closes/{periodClose}/approve finance.period-closes.approve
POST   /period-closes/{periodClose}/reject  finance.period-closes.reject
```
- The parameters `{line}`, `{note}` and `{periodClose}` use `whereNumber`. Implicit binding gives a 404 across tenants through `OrganizationScope`.
- Every action also calls `Gate::authorize(...)`.
- In total, P4 adds 21 routes: 11 GET and 10 write.

### 2.8 `app/Services/Approvals/ApprovalProcesses.php` (WP-A adds this, because the service can't start without it; WP-C owns the stages)
```php
'finance.period_reopen' => [
  'module' => 'IT Financial Management', 'label' => 'Re-open a closed period', 'engine' => 'generic',
  'description' => 'A closed or locked accounting period is re-opened only with approval (FIN-04.05, ICFR).',
  'permission' => 'approve it-finance', 'view_permission' => 'view it-finance', 'model' => PeriodClose::class,
  'initiator' => 'reopen_requested_by_id', 'title' => ['period_label'], 'route' => 'finance.period-closes.show',
  'initiate' => 'Requesting a period re-open',
  'conditions' => ['period_status' => ['label' => 'Period status', 'values' => ['closed' => 'Closed', 'locked' => 'Locked']]],
  'default' => 'One approval by anyone with "approve it-finance" other than the requester and whoever created the close record.',
],
```

### 2.9 Backend tests owned by WP-A
- **`tests/Feature/Finance/Concerns/BuildsMonitoring.php`** (new; WP-E uses it but does not edit it). It builds on `BuildsExpenses`.
  - `bootMonitoring(string $now = '2027-04-05 09:00:00')`: a baseline with 3 lines phased equally, the FY2027 periods with Jan–Mar ended, and the thresholds amber 5, red 10, floor 100000.
  - `postActual(BudgetLine $l, Period $p, string $base, array $over = [])`: a direct ledger insert.
  - `periodSeq(int $n)`.
  - `explainAll(Period $p)`.
- **`MonitoringBackendSmokeTest`** (new, 8–10 tests): every new GET renders or returns JSON with the §7 prop keys; masking nulls the money keys; another bank's note, line or period gives 404; `bva.export` returns 200 for an organization with no FY.
- **`FinancePermissionsTest`** (edit one test): add `Carbon::setTestNow('2027-02-05')` before the closer posts, so `period_ended` passes. The FY has no baselines, so `actuals_posted` is `n_a`.

Run: `--filter 'MonitoringBackendSmokeTest|FinancePermissionsTest|FinanceConfigurationTest|ActualsImportAndReconciliationTest|ExpenseReviewRegressionTest|ExpenseServicesSmokeTest'`. The last three exercise the accrual paths changed by M7/M8.

---

## 3. WP-B: frontend (fin-frontend-engineer, `npm run build`, may run in parallel with A)

**Pages** (new, `resources/js/Pages/Finance/`):
- **`Dashboard.jsx`:**
  - FY and cost-centre filters, and an audience badge ("Your cost centres" when `audience === 'owner'`).
  - A KPI row: budget, actual YTD, forecast, variance %, available, unbudgeted, overdue invoices, renewals 30/60/90, passed.
  - Charts: monthly BvA (bars for budget and actual, a line for the cumulative) and spend by TBM tower.
  - Splits: CapEx/OpEx and Run/Grow/Transform.
  - Lists: top variances (linking to Variances), renewals due, pending approvals (with a `mine` chip) and a burn card.
  - Masked: show "Hidden" tiles and hide the charts with a note.
- **`Monitor/BvA.jsx`:**
  - Filters: FY, from/to period, view (`period`, `ytd` or `fy`), group-by and the dimension pickers.
  - Drill breadcrumbs built from `breadcrumbs[]`.
  - The pivot is a table with rows from `matrix.rows` and, for the `period` view, column groups per period (budget, actual, variance). Fixed columns: committed, available, forecast, variance and variance %. A severity chip shows on rows. The unallocated row and the totals row are sticky at the foot.
  - Clicking a row with `drill` navigates with `params + {group_by: drill.next_group_by}`. A row with `line_id` opens `TransactionsDrawer`, which fetches `finance.monitor.transactions`.
  - An export menu (CSV, XLSX, PDF) carries the current filters to `finance.monitor.bva.export`.
  - The reconciliation badge turns red if `difference !== '0.00'`.
- **`Monitor/Variances.jsx`:**
  - Filter chips for severity, status and "needs explanation", a period picker and a summary strip.
  - A card or table per note, showing the explanation, the corrective action and a link to the issue.
  - `ExplainModal`, `AcknowledgeButton`, `RaiseIssueModal` and a `CommentThread` that posts to `finance.monitor.variances.comment`.
- **`Monitor/Forecast.jsx`:** a line table with both EAC methods side by side, the active method highlighted and a "manual" badge. `OverrideModal` (amount and reason) and a clear button (`DELETE`). The totals sit in the footer. A small chart shows the EAC history from `history[]`.
- **`Monitor/PeriodClose.jsx`:**
  - A period strip for the FY, with status chips.
  - The selected period shows `ChecklistPanel` (items with status icons, count, detail and a fix link), plus a Close button (disabled with the blockers listed when `!can_close`) and a Lock button.
  - A history table, `ReopenModal` (reason), and `ReopenPanel`, which uses `Components/Approvals/ApprovalTrail.jsx` and Approve/Reject/Withdraw.
  - Errors in `errors.checklist` show as a list.
- **`Reports/Index.jsx`:** report cards grouped Budget, Spend, Contracts and Vendors. Each card has a filter form per its `filters` and a preview through a partial reload (`router.reload({ only: ['preview'], data: {...} })`). The download buttons are plain `<a href={route('finance.reports.show', {report, format, ...filters})}>`. A schedules list links to `reports.scheduled`.

**Components** (new, `resources/js/Components/Finance/Monitor/`):
- `MonitorNav.jsx` (tabs BvA · Variances · Forecast · Period close), `SeverityChip.jsx`, `KpiTile.jsx`, `FiltersBar.jsx`
- `BvaPivot.jsx`, `DrillBreadcrumbs.jsx`, `TransactionsDrawer.jsx`
- `VarianceCard.jsx`, `ExplainModal.jsx`, `RaiseIssueModal.jsx`, `CommentThread.jsx`
- `ForecastTable.jsx`, `OverrideModal.jsx`
- `ChecklistPanel.jsx`, `PeriodStrip.jsx`, `ReopenModal.jsx`, `ReopenPanel.jsx`
- `ReportCard.jsx`, `ReportPreview.jsx`
- `DashboardCharts.jsx` (recharts: MonthlyBvA, SpendByTower, SplitBar)

**Edits:**
- **`resources/js/Config/navigation.js`:** in the finance group, add Dashboard (`finance.dashboard`) first, "Budget Monitor" (`finance.monitor.bva`) after "Actuals & Reconciliation", and Reports (`finance.reports.index`) before Settings. Each needs `view it-finance`.
- **`resources/js/Utils/finance.js`:** add `severityTone`, `fmtPct` and `checklistIcon` helpers.
- **`resources/js/Pages/BoardPacks/Show.jsx`:** add `case 'it_financial_position'`. It shows Stats for budget, actual YTD, variance %, forecast, committed, available, unbudgeted, red/amber, renewals due and contracts passed, plus a table of `top_variances`. Null values show "Hidden".
- **`resources/js/Components/Finance/Settings/FiscalCalendarTab.jsx`:** show `errors.checklist` under the period row and link to `finance.monitor.period-close`.

---

## 4. WP-C: workflow integration (fin-workflow-integrator, DB `it_security_risk_fin_test`, after WP-A)

1. **`app/Services/Finance/FinancePolicySeeder.php` (edit):** add `'finance.period_reopen' => [self::stage(10, 'CFO approval', 'job_title', 'Chief Financial Officer', 48)]`. It is idempotent: banks that are already seeded get the new process because none exists yet.
2. **`app/Services/Finance/FinanceApprovalNotifier.php` (edit):** add `'finance.period_reopen' => route('finance.period-closes.show', $subject)`.
3. **Notifications** (new, extending `BaseNotification`, `$module = 'IT Financial Management'`, **no amounts or percentages**):
   - **`BudgetVarianceNotification`:** constructor `(string $line, string $costCentre, string $period, string $severity, string $direction, string $url)`. The type is `finance.budget_variance_amber` or `_red`. The subject reads "Sep 2026: red overspend on Cisco SmartNet AMC (KHB-IT-NET)", and the message asks for an explanation before close.
   - **`PeriodCloseReminderNotification`:** constructor `(string $period, string $kind, int $workingDays, array $openItems, string $url)`. `$kind` is `due`, `overdue` or `blocked`. `$openItems` holds labels and counts only.
4. **Listeners** (new, auto-discovered):
   - **`SendBudgetVarianceNotification`** handles `VarianceBreached`. It loads the note `withoutGlobalScopes`. Recipients are the budget owner, the cost-centre owner and `financeUsers()`, active and unique. It calls `ContractSweeper::claim($note, "variance:{$severity}", 0, $users)` and sends to the users returned.
   - **`SendPeriodCloseBlockedNotification`** handles `PeriodCloseBlocked`. The key is `close_blocked:{Y-m-d}` and the subject is the Period, so a bank gets at most one per period per day. Recipients are `financeUsers()` plus the owners of cost centres with red notes outstanding.
5. **`app/Services/Finance/PeriodCloseReminderSweeper.php`** (new): `sweep(?CarbonInterface $today = null): int`.
   - Per organization, it looks at the open regular periods of open FYs.
   - Working days are Mon–Fri, through a private `workingDaysBetween()` (C121).
   - **Due reminder:** when the working days to `ends_on` are at most 5 and the period hasn't ended, send key `close_due` with offset 5. The catch-up is implicit, because the key is claimed once.
   - **Overdue reminder:** when the working days after `ends_on` are at least 3 and the period is still open, send `close_overdue` with offset −3.
   - Recipients are the owners of cost centres with baseline lines in the FY, plus `financeUsers()`.
   - `openItems` comes from `PeriodCloseService::checklist()` (labels and counts of the fail items).
6. **`routes/console.php`** (edit, IT Financial Management block):
   ```php
   Artisan::command('fin:variances-sweep {--date=}', fn (VarianceService $v) => $this->info($v->evaluateDue(...).' period(s) evaluated'));
   Schedule::command('fin:variances-sweep')->dailyAt('07:00')->timezone(config('finance.timezone'))->name('finance.variances-sweep')->withoutOverlapping();
   Artisan::command('fin:period-reminders {--date=}', fn (PeriodCloseReminderSweeper $s) => ...);
   Schedule::command('fin:period-reminders')->dailyAt('08:00')->timezone(config('finance.timezone'))->name('finance.period-reminders')->withoutOverlapping();
   ```
   `--date` parses to a CarbonImmutable in the finance timezone, as the existing commands do.
7. **Report definitions** (new, `app/Services/Finance/Reports/Definitions/`): each extends `FinanceReport`, runs grouped queries `withoutGlobalScopes()->where('organization_id', $orgId)` and applies `$costCentreIds`.
   - **`BudgetBookReport`** (baseline lines): cost centre, tower, cost pool, line, CapEx/OpEx, RGT, original, adjustments, current. It has subtotals per cost centre.
   - **`VarianceCommentaryReport`** (FY or period): period, line, cost centre, severity, direction, variance, variance %, status, explanation, corrective action, issue code, author, acknowledged by.
   - **`CommitmentsReport`:** approved requests and open POs with their remaining commitment, through `BudgetAvailability::remainingForPo`. Columns: document, vendor, line, cost centre, date, remaining.
   - **`InvoiceAgeingReport`** (`as_of`): unpaid invoices bucketed current, 1–30, 31–60, 61–90 and 90+, with a total per vendor.
   - **`UnbudgetedSpendReport`:** unbudgeted or over-budget requests plus unbudgeted actuals, with a source column.
   - **`ContractRegisterReport`** (`window` = all, 30, 60 or 90, where the window means the end or notice date falls within N days): code, title, vendor, type, status, commenced, ends, notice deadline, days to notice, auto-renew, owner, base value.
   - **`VendorSpendReport`** (FY): vendor, actual YTD, committed, live contracts, live contract value, share of total.
8. **`app/Models/ScheduledReport.php` (edit):** extend `TYPES` with `'finance' => 'IT financial position'` and one `finance.{key}` entry per definition, labelled with "IT finance: " plus the title.
9. **`app/Http/Controllers/ReportController.php` (edit):** in `validateSchedule()`, a `finance` or `finance.*` type needs `view it-finance` and `export it-finance`. Otherwise it fails `report_type`.
10. **`app/Services/Kri/KriMeasures.php` (edit):**
    - Add `'KRI-FIN-VAR' => 'financeBudgetVariance'`, `'KRI-FIN-UNB' => 'financeUnbudgetedSpend'`, `'KRI-FIN-INV' => 'financeOverdueInvoices'` and `'KRI-FIN-NOT' => 'financeContractsPastNotice'`.
    - Each private method delegates to `app(FinanceKriSource::class)`.
    - The cloud anomaly KRI is deferred to P6 (C109).
11. **`app/Services/Reports/BoardPackBuilder.php` (edit):** add `'it_financial_position' => 'IT financial position'` to `SECTIONS`, and `sectionItFinancialPosition(int $orgId)`, which returns `FinanceMetrics::boardFigures($orgId)`, or null when the bank has no current FY.
12. **`app/Http/Controllers/Reports/BoardPackController.php` (edit):** in `show`, null the money keys of the `it_financial_position` section's data when the viewer lacks `view it-finance-amounts`. The keys are `FinanceMetrics::BOARD_MONEY_KEYS`, a public const added by WP-A.
13. **Count updates (WP-C owns them):**
    - `tests/Feature/Workflows/WorkflowStudioModuleTest.php`: lines 93 and 195 go from 18 to 19.
    - `tests/Feature/Finance/FinanceWorkflowStudioTest.php`: the 7s at lines 67, 88, 195, 203 and 207 become 8, plus `assertCount(1, $wfs['finance.period_reopen']->stages)`.
    - The `RouteSmokeTest` 7 → 8 update is WP-E's.
14. **Test** `tests/Feature/Finance/MonitoringWorkflowIntegrationTest.php` (new, about 10 tests):
    - The reopen flows through the Studio inbox, and the CFO is `mine`.
    - The variance listener sends once, and an escalation sends red once.
    - The due and overdue reminders send once, skip weekends and stay silent after close.
    - A blocked close notifies once per day.
    - Both notifications render to mail and database (`toMail`, `toArray`).
    - `kri:measure` records the 4 finance KRIs.
    - The board pack contains `it_financial_position` and masks it for a viewer without amounts.
    - A `finance.bva` schedule needs `export it-finance`, and `reports:run-due` records a summary for a finance type.

Run: `--filter 'MonitoringWorkflowIntegrationTest|FinanceWorkflowStudioTest|WorkflowStudioModuleTest|KriDashboardsModuleTest|ReportsIntegrationsSettingsModuleTest'`.

---

## 5. WP-E: QA (fin-qa-engineer, DB `it_security_risk_fin_qa`, after A, B and C)

### 5.1 Demo seeding: a **separate `database/seeders/FinanceMonitoringDemoSeeder.php`** is needed
**Why separate:** the existing finance seeders each skip a whole bank once their first entity exists (`Budget`, `Contract`, `ExpenseRequest`), so adding to them would never run on an already-seeded database. The schedules also have to be created **after** `ReportsIntegrationsSettingsDemoSeeder`, whose guard skips any bank that already has a schedule.

**`DatabaseSeeder.php`:** call the seeder right after `FinanceExpenseDemoSeeder`, and **a second time** right after `ReportsIntegrationsSettingsDemoSeeder`. Each step has its own guard, scoped to the tenant.

For each bank (KHB org 2, FBN org 3), using `mt_srand(4000 + orgId)`, `Auth::setUser(finance@)`, `Event::fakeFor` (quieting `VarianceBreached`, `PeriodCloseBlocked`, `FinanceApprovalRequested` and `FinanceApprovalDecided`), and `attempt()` that **logs** swallowed exceptions with `$this->command?->warn(...)`, which fixes the P3 gotcha:

1. **Guard:** skip steps 2–7 when `VarianceNote` already exists for the organization.
2. **Guarantee the demo variances.** After a dry evaluation of Sep, top up named lines with direct historic actuals in Sep: `source=adjustment`, `posting_key = "p4-demo:{line}:{period}"`, matched to the line. The aim is that by YTD Sep each bank has **≥ 3 red overspends, ≥ 1 red underspend and ≥ 3 amber**, all above the bank's floor. Then evaluate Jan–Sep in order.
3. **Seed notification logs.** For every note, insert one `fin_notification_logs` row: `notifiable_type` VarianceNote, key `variance:{peak_severity}`, offset 0, recipient the cost-centre owner, `sent_at` the period end plus 1 day. That way the first real sweep doesn't resend the history (as in P2 Q1).
4. **Explanations:**
   - Every red note in Jan–Aug is explained by the cost-centre owner and acknowledged by finance@.
   - Most amber notes are explained; two are left open.
   - Two notes get comment threads.
   - One red note per bank raises an issue through `VarianceService::raiseIssue`.
5. **Close history:**
   - Insert `fin_period_closes` rows for Jan–Sep from the existing `closed_*` and `locked_*` columns, with a checklist snapshot where every item passes.
   - Run `ForecastService::snapshot` for each.
   - **KHB:** finance@ requests a Sep reopen ("late Interswitch settlement invoice"), and cfo@ approves it, so Sep is **open**. Then add one proposed accrual in Sep with `AccrualService::store`, and keep **one red Sep note unexplained**. The Sep checklist shows `accruals_done` and `variances_explained` as fail; this is the M9 and blocked-close demo.
   - **FBN:** Sep stays closed with a **pending reopen request** from finance@ at the CFO stage. cfo@firstbanknigeria.ng must be able to decide it.
6. **Forecasts:** two manual overrides per bank, with reasons.
7. **KRIs and board pack:**
   - `Kri::updateOrCreate` for `KRI-FIN-VAR`, `-UNB`, `-INV` and `-NOT` for both organizations: category `finance`, source `platform`, frequency monthly, owner cfo@. Thresholds: VAR % 5/10/20, UNB % 2/5/10, INV count 0/3/10, NOT count 0/1/3, all `higher_worse`.
   - Their readings come from `KriDashboardsDemoSeeder`. **Edit its `OWNERS`** to add `'finance' => 'cfo'`.
   - Add `it_financial_position` to the global **BAC** board-pack template's `sections` if it is missing.
8. **Schedules (second pass only):** when the bank already has a non-finance schedule and no `finance%` one, create "Monthly IT BvA" (`finance.bva`, monthly) and "Weekly invoice ageing" (`finance.invoice_ageing`, weekly), each with one recorded run. Use `withoutGlobalScopes()` and write `organization_id` directly.

### 5.2 Tests (new unless marked, `tests/Feature/Finance/`, using `BuildsMonitoring`)
- **`MonitoringAndVarianceTest`** (about 14 tests):
  - BvA reconciles to the cent: across FX lines, adjustments, a duplicate actual excluded, unallocated actuals and every `group_by`; `difference === '0.00'`, and the totals equal Σ lines + unallocated.
  - The drill path goes cost centre → tower → category → line → transactions.
  - Each export level gives a CSV whose total equals the matrix.
  - EAC by remaining budget and by run-rate, against hand-computed values; the `elapsed = 0` case; a manual override with its reason, then cleared; the snapshot taken at close.
  - The burn and exhaustion date.
  - `classify()` boundary table: amber/red at exactly the thresholds, the floor, under-spend, zero budget, line overrides.
  - The sweep is idempotent: running `fin:variances-sweep` twice with `Notification::fake` gives one send per (line, period, severity); amber then red gives one red; red then amber gives nothing; a re-baseline keeps the same note.
  - The recipients are the line owner, the cost-centre owner and Finance.
  - Locked periods are skipped by the sweep.
  - The explain, acknowledge and SoD rules, and the comment thread.
  - `raiseIssue` creates an Issue with source `finance_variance` and refuses a second one.
  - Masking: a user without amounts gets nulls in the BvA, Forecast and Variances props.
  - Cost-centre visibility: an owner sees only their own rows, and the unallocated row is hidden.
  - FIN-04.08: `kri:measure` gives the 4 readings.
- **`PeriodCloseTest`** (about 12 tests):
  - Close is refused while the period has not ended (D10).
  - Close is refused while actuals are unposted (no actual, or an unmatched row); this is FIN-01.
  - Close is refused with an unexplained red variance, and allowed once it is explained; amber only warns.
  - **M9:** close is refused while a proposed accrual, a proposed prepayment release or an unproposed accrual candidate exists.
  - Close is refused while an invoice is draft or submitted in the period.
  - `PeriodCloseBlocked` is dispatched.
  - A successful close writes `PeriodClose` with the checklist.
  - Lock comes only after close.
  - A reopen needs approval: the requester cannot approve; the CFO stage approves; the period returns to open with history kept.
  - The latest-non-open rule; reject and withdraw; the unconfigured default approver.
  - **M8:** a submitted invoice no longer reduces the accrual, and an approved invoice in a later period doesn't either.
  - **M7:** the accrual includes VAT.
  - Another bank's period or close record returns 404.
- **`FinanceReportsTest`** (about 10 tests):
  - Each of the 8 reports returns rows for a seeded mini-estate.
  - `?format=csv|xlsx|pdf` each returns 200 with the right `Content-Type` and `Content-Disposition`.
  - The XLSX is a valid zip (`ZipArchive::open`) whose `sheet1.xml` contains the header and a value.
  - The PDF starts with `%PDF`.
  - The CSV cells are formula-guarded.
  - `export it-finance` is required, giving 403.
  - Money columns are blank without amounts.
  - Cost-centre visibility applies.
  - An unknown report key gives 404.
  - A `ScheduledReport` of a finance type records figures; `ReportFigures::summary('finance', …)` has the board keys (FIN-04.11).
- **`tests/Feature/RouteSmokeTest.php` (edit):**
  - Line 251: 7 → 8.
  - New `test_finance_monitoring_estate_is_seeded_and_renders_for_every_demo_tenant`. Per bank it checks:
    - FY2026 has red and amber notes and the BvA difference is `'0.00'`.
    - KHB Sep is open with the checklist `can_close=false`, and both `accruals_done` and `variances_explained` fail.
    - FBN has a submitted reopen with an eligible decider (the pending-step check that already exists).
    - The 4 finance KRIs have readings.
    - The BAC pack has the finance section.
    - Every report downloads in all three formats.
    - Org 1 (Acme) renders every monitoring page empty.

Run: `--filter 'MonitoringAndVarianceTest|PeriodCloseTest|FinanceReportsTest|MonitoringBackendSmokeTest|MonitoringWorkflowIntegrationTest'`. Then seed on `it_security_risk_fin_qa`. **No Playwright this phase.**

---

## 6. Gate (orchestrator)
1. The full suite on `it_security_risk_test`, in the background. The baseline is 624 tests; expect about 690.
2. `migrate:fresh --seed` on scratch. Check that the seeder printed **no warnings**.
3. `SMOKE=1 … RouteSmokeTest`.
4. `npm run build`.
5. Pint on the touched files.
6. In the seeded estate, verify by hand (tinker on scratch) that FBN's pending reopen can be decided by cfo@ and that KHB's Sep close is blocked by both M9 and the red variance. Don't trust the seeder for this.

---

## 7. Props contract (WP-A ⇄ WP-B)
Decimals are strings, dates `Y-m-d` and timestamps ISO-8601. Masked values are `null`. Every page gets `baseCurrency` and `can.viewAmounts`.

**7.1 `Finance/Dashboard`**:
```
fiscalYears:[{id,year_label,status,is_current}], selectedFiscalYear:{id,year_label,starts_on,ends_on}|null, asOfPeriod:{id,name,sequence}|null,
audience:'finance'|'owner', costCentres:[{id,code,name}], filters:{fiscal_year_id,cost_centre_id},
metrics:{budget_total,budget_ytd,committed,actual_ytd,forecast_fy,variance_ytd,variance_pct,available,unbudgeted_count,unbudgeted_amount,
  capex_opex_split:{capex,opex}, rgt_split:{run,grow,transform}, burn:{monthly,months_remaining,exhaustion_on},
  variances:{red,amber,unexplained_red}, contracts_active, renewals_due_30, renewals_due_60, renewals_due_90, contracts_passed, invoices_overdue,
  investments_by_stage:[], benefits_realised_pct:null, cloud_mtd:null, cloud_anomalies_open:null},
spendByCategory:[{id,code,name,budget,actual}], monthlyBvA:[{period_id,name,budget,actual,forecast,cumulative_budget,cumulative_actual}],
topVariances:[{note_id,line_id,label,cost_centre,severity,direction,variance,variance_pct,explained,href}],
renewalsDue:[{id,contract_code,title,vendor,ends_on,notice_deadline_on,days_to_notice,status,href}],
pendingApprovals:[{id,process,process_label,subject,url,step,due_at,mine}], can:{viewAmounts,export,closePeriods}
```
The board section shape (`boardFigures`) is `{fiscal_year, as_of_period, currency, budget_total, budget_ytd, actual_ytd, variance_pct, forecast_fy, forecast_variance_pct, committed, available, unbudgeted_count, unbudgeted_amount, capex_opex_split, rgt_split, red, amber, unexplained_red, top_variances:[{label,cost_centre,severity,variance_pct,explained}], renewals_due_90, contracts_passed, invoices_overdue}`.

**7.2 `Finance/Monitor/BvA`**:
```
filters:{fiscal_year_id,from_period_id,to_period_id,view,group_by,cost_centre_id,tower_id,cost_pool_id,category_id,gl_account_id,legal_entity_id,vendor_id,rgt,capex},
groupings:[{key,label}], breadcrumbs:[{label,params}], fiscalYears, periods:[{id,name,sequence,status,ends_on}],
dimensions:{costCentres,towers,costPools,categories,glAccounts,legalEntities,vendors} (each [{id,code?,name}]), thresholds:{amber_pct,red_pct,abs_floor},
matrix:{group_by, periods:[{id,name,sequence,status,elapsed}], rows:[Row], unallocated:Row|null, totals:Row,
        reconciliation:{ledger_actual,matrix_actual,difference}}, can:{viewAmounts,export}
Row = {key,label,sublabel,drill:{param,value,next_group_by}|null,line_id|null,budget,committed,actual,available,forecast,variance,variance_pct,
       severity:null|'amber'|'red', by_period:{[period_id]:{budget,actual,variance}}}
transactions JSON = {line:{id,label}, actuals:[{id,posted_on,period,source,source_ref,description,vendor,base_amount,href}],
                     commitments:[{type:'expense'|'po',code,title,remaining_base,href}], totals:{actual,committed}}
```

**7.3 `Finance/Monitor/Forecast`**: `filters{fiscal_year_id,cost_centre_id}`, `fiscalYears`, `costCentres`, `settingsMethod`, `asOfPeriod`, `periodsElapsed`, `periodsTotal`.
```
lines:[{line_id,label,budget_code,cost_centre,baseline,budget,actual_ytd,eac_remaining_budget,eac_run_rate,
        manual:{amount,reason,set_by,set_at}|null, eac, method, variance, variance_pct, severity, burn_monthly, exhaustion_on, can:{override}}]
totals:{baseline,budget,actual_ytd,eac_remaining_budget,eac_run_rate,eac,variance,variance_pct}
history:[{period_id,name,eac_total}]
```

**7.4 `Finance/Monitor/Variances`**: `filters{fiscal_year_id,period_id,severity,status,cost_centre_id,needs_explanation}`, `fiscalYears`, `periods`, `costCentres`, `statuses`, `thresholds`, `summary{red,amber,unexplained_red,explained,acknowledged}`, and `notes` (a paginator of 25: `{data,links,meta}`).
```
Note = {id, period:{id,name,status}, line:{id,label,budget_code}, cost_centre:{id,code,name}, owner:{id,name}|null, severity, peak_severity, direction, status, is_breaching,
        budget, actual, variance, variance_pct, period_variance, explanation, corrective_action, author, explained_at, acknowledged_by, acknowledged_at,
        issue:{id,code,url}|null, comments:[{id,author,body,at}], can:{explain,acknowledge,raiseIssue,comment}}
```

**7.5 `Finance/Monitor/PeriodClose`**: `fiscalYears`, `filters{fiscal_year_id,period_id}`, `can{closePeriods,approve}`.
```
periods:[{id,name,sequence,status,starts_on,ends_on,closed_at,closed_by,locked_at,can:{close,lock,reopen}}]
selected:{period:{id,name,status,ends_on}, checklist:{items:[{key,label,status,count,detail,href}],can_close,blockers}, 
          history:[{id,status,closed_by,closed_at,locked_at,reopen_status,reopen_reason,requested_by,requested_at,decided_by,decided_at,rejection_reason}],
          reopen:{id,status,reason,requested_by,trail,can:{approve,reject,withdraw}}|null}|null
```
The checklist JSON (`finance.periods.close-checklist`) has the same shape as `selected.checklist`.

**7.6 `Finance/Reports/Index`**: `reports:[{key,title,description,group,filters,formats:['xlsx','csv','pdf']}]`, `filters{report,fiscal_year_id,from_period_id,to_period_id,cost_centre_id,window,as_of}`, `fiscalYears`, `periods`, `costCentres`, `schedules:[{id,name,report_type,frequency,next_run_at,active}]`, `can{export,viewAmounts,schedule}`. The optional prop `preview` is `{key,title,columns:[{key,label,type}],rows:[{…}],totals:{…}|null,row_count,truncated}|null`.

---

## 8. File ownership (no overlaps)

| WP | files |
|---|---|
| **A** (about 34: 24 new, 10 edits) | `config/finance.php`; migration `2027_03_10_000001_create_it_finance_monitoring_tables.php`; models `app/Models/Finance/{Forecast,VarianceNote,VarianceComment,PeriodClose}.php` (new) and `Period.php`, `BudgetLine.php`, `app/Models/Issue.php` (edit); services `app/Services/Finance/{MonitoringService,ForecastService,VarianceService,PeriodCloseService,FinanceMetrics,FinanceKriSource}.php` and `Reports/{FinanceReport,FinanceReports,ReportExporter,XlsxWriter}.php`, `Reports/Definitions/BvaReport.php` (new), `AccrualService.php`, `app/Services/Reports/ReportFigures.php` (edit); `app/Events/Finance/{VarianceBreached,PeriodCloseBlocked}.php`; `app/Services/Approvals/ApprovalProcesses.php`; `app/Policies/Finance/FinancePolicy.php`; controllers `app/Http/Controllers/Finance/{FinanceDashboard,Monitor,PeriodClose,FinanceReport}Controller.php` and `Concerns/PresentsMonitoring.php` (new), `FiscalCalendarController.php` (edit); requests `app/Http/Requests/Finance/{MonitorFilterRequest,ForecastOverrideRequest,VarianceExplainRequest,ReopenPeriodRequest}.php`; `routes/web.php`; tests `Finance/Concerns/BuildsMonitoring.php`, `Finance/MonitoringBackendSmokeTest.php` (new), `Finance/FinancePermissionsTest.php` (edit) |
| **B** (about 28: 25 new, 4 edits) | `resources/js/Pages/Finance/{Dashboard,Monitor/BvA,Monitor/Variances,Monitor/Forecast,Monitor/PeriodClose,Reports/Index}.jsx`; `resources/js/Components/Finance/Monitor/*` (19 listed in §3); edits `resources/js/Config/navigation.js`, `resources/js/Utils/finance.js`, `resources/js/Pages/BoardPacks/Show.jsx`, `resources/js/Components/Finance/Settings/FiscalCalendarTab.jsx` |
| **C** (about 21: 13 new, 8 edits) | `app/Services/Finance/{FinancePolicySeeder,FinanceApprovalNotifier}.php` (edit), `PeriodCloseReminderSweeper.php` (new); `app/Notifications/Finance/{BudgetVariance,PeriodCloseReminder}Notification.php`; `app/Listeners/Finance/{SendBudgetVarianceNotification,SendPeriodCloseBlockedNotification}.php`; `app/Services/Finance/Reports/Definitions/{BudgetBook,VarianceCommentary,Commitments,InvoiceAgeing,UnbudgetedSpend,ContractRegister,VendorSpend}Report.php`; `routes/console.php`; `app/Models/ScheduledReport.php`; `app/Http/Controllers/ReportController.php`; `app/Services/Kri/KriMeasures.php`; `app/Services/Reports/BoardPackBuilder.php`; `app/Http/Controllers/Reports/BoardPackController.php`; tests `Finance/MonitoringWorkflowIntegrationTest.php` (new), `Workflows/WorkflowStudioModuleTest.php`, `Finance/FinanceWorkflowStudioTest.php` (counts) |
| **E** (7: 4 new, 3 edits) | `database/seeders/FinanceMonitoringDemoSeeder.php` (new), `database/seeders/DatabaseSeeder.php`, `database/seeders/KriDashboardsDemoSeeder.php` (OWNERS only); tests `Finance/{MonitoringAndVarianceTest,PeriodCloseTest,FinanceReportsTest}.php` (new), `tests/Feature/RouteSmokeTest.php` |

**Touched by nobody:**
- `ApprovalEngine.php`, `WorkflowStudioController.php`, `FiscalCalendarService.php`, `BudgetAvailability.php`, `BudgetLedger.php`, `ContractSweeper.php`
- the finance seeders from P0–P3, `RolesAndPermissionsSeeder.php` (no new permissions), `AppServiceProvider.php`

**Cross-WP dependencies:**
- C needs A's events, `FinanceReport`, `FinanceMetrics::BOARD_MONEY_KEYS`, `FinanceKriSource` and `PeriodCloseService::checklist`.
- B needs only §7.
- E needs all three.

---

## 9. §12 acceptance → test

| ID | criterion | test |
|---|---|---|
| FIN-04.01 / .06 | BvA matrix reconciles to lines and actuals to the cent; drill-down; export at every level | `MonitoringAndVarianceTest::test_bva_reconciles_to_the_cent_for_every_grouping`, `::test_drill_path_and_export_totals_match`; SMOKE difference `'0.00'` |
| FIN-04.02 | EAC by both methods, manual override with reason | `::test_eac_remaining_budget_and_run_rate`, `::test_manual_override_and_clear` |
| FIN-04.03 | amber/red once per (line, period, severity); explanation required at close | `::test_variance_sweep_notifies_once_per_line_period_severity`, `::test_classify_thresholds`; `PeriodCloseTest::test_close_blocked_by_unexplained_red_variance` |
| FIN-04.04 | burn, exhaustion, months remaining | `::test_burn_and_exhaustion` |
| FIN-04.05 | close checklist; lock; reopen with approval; M9 | `PeriodCloseTest` (all), `MonitoringWorkflowIntegrationTest::test_reopen_through_workflow_studio` |
| FIN-04.07 | dashboards (CIO/Finance, cost-centre owner) | `MonitoringBackendSmokeTest::test_dashboard_props`, `MonitoringAndVarianceTest::test_owner_sees_own_cost_centres` |
| FIN-04.08 | KRI readings produced by `kri:measure` | `MonitoringAndVarianceTest::test_kri_measure_produces_finance_readings`, `MonitoringWorkflowIntegrationTest` |
| FIN-04.10 | commentary thread, corrective action as Issue | `::test_explain_acknowledge_comment_and_raise_issue` |
| FIN-04.11 | ReportFigures plus board-pack section | `FinanceReportsTest::test_report_figures_finance`, `MonitoringWorkflowIntegrationTest::test_board_pack_section_and_masking` |
| FIN-01 | period close refused while actuals unposted | `PeriodCloseTest::test_close_refused_while_actuals_unposted` |
| FIN-07.01 | 8 finance processes in Workflow Studio, default when unconfigured | `FinanceWorkflowStudioTest` (8), `PeriodCloseTest::test_unconfigured_default_approver` |
| FIN-07.03 | each notification renders mail plus database | `MonitoringWorkflowIntegrationTest::test_notifications_render` |
| FIN-07.07 | reports export in 3 formats; scheduled | `FinanceReportsTest` |
| FIN-07.10 | amounts masked | `MonitoringAndVarianceTest::test_masking`, `FinanceReportsTest::test_money_blank_without_amounts`, the board-pack masking test |
| Tenancy | every `finance.*` GET 200 for orgs 2 and 3, org 1 empty | `RouteSmokeTest` (existing loop plus the new estate test), 404 tests in `MonitoringBackendSmokeTest` and `PeriodCloseTest` |
| P3 M7/M8/M9 | accrual VAT, cut-off, close vs pending | `PeriodCloseTest::test_accrual_cutoff_and_vat`, `::test_close_blocked_by_pending_accruals_and_releases` |

---

## 10. Spec ⇄ codebase conflicts (continuing from P3's C100)

| # | conflict | resolution |
|---|---|---|
| C101 | §6 gives `finance.period_reopen` the model `Period`, but a period has no initiator column and can be reopened more than once. | The subject is `PeriodClose`, one row per close. The spec's `reopen_reason` and `reopen_approval_request_id` live on it (P4-6). |
| C102 | The spec gives the process permission as `close it-finance-periods`. The CFO (Finance Approver) doesn't hold it, so the inbox and notifier would show the CFO stage to nobody. | Use permission `approve it-finance`; requesting still needs `close`. **Needs sign-off.** The alternative is granting `close it-finance-periods` to Finance Approver, which lets the CFO close as well, a weaker SoD. |
| C103 | The variance basis per line and period is undefined. Period-level amounts sit below the seeded absolute floors. | YTD through the period, both directions, pct **and** floor (P4-1). **Needs sign-off.** |
| C104 | The "commentary thread" has no table. | Add `fin_variance_comments`. |
| C105 | "Corrective actions raised as Issue": finance users lack `create issues`, and `Issue::SOURCES` with a model would list variance notes in the issue source picker. | Source `finance_variance` with model null; the issue is raised by the system (P4-12). **Needs sign-off.** |
| C106 | §3.7 asks for XLSX; P1-2 refused a new dependency. | A ZipArchive writer with no dependency, for exports only (P4-10). **Needs sign-off.** |
| C107 | The checklist's "cloud imported" and "approvals complete". | Cloud is `n_a` until P6. Approvals means invoices in the period; transfers and supplementary requests post to open periods and are already guarded (P3-13). |
| C108 | P0 D10 asked for a future-dated close guard and FY contiguity. | The guard ships (`period_ended`). FY contiguity is deferred to P7, because it would change `createYear()`, which tests use freely. |
| C109 | KRI "cloud anomaly count". | Deferred to P6; 4 finance KRIs ship now. |
| C110 | §7 is silent on the notification content. | No amounts or percentages, as in P2 and P3. |
| C111 | Masking scope for percentages and EAC. | Masked like amounts; severity and counts stay visible (P4-11). |
| C112 | BvA "Committed" for a period range. | Annual and as of today (P3-1), labelled "open commitments". |
| C113 | The EAC terms "elapsed" and "remaining budget". | As-of = the last ended period; actuals of the period in progress are excluded (P4-7). |
| C114 | Three dashboards are named in FIN-04.07. | One page, scoped by visibility (P4-13). |
| C115 | §9 lists `finance.periods.close`, `lock` and `reopen` under Settings and the checklist under Monitoring. | All four go through `PeriodCloseService`; the Settings tab shows the checklist errors. |
| C116 | The reopen ordering is unspecified. | Only the latest non-open period can be reopened, and the rule is re-checked at approval. |
| C117 | `fin_forecasts` "per line and period". | Stores only manual overrides and close-time snapshots; the live EAC is computed. |
| C118 | Re-baselines re-create lines, which would duplicate notes and alerts. | Keyed on `line_key`, the lineage root (P4-2). |
| C119 | Board-pack templates are global. | The seed appends the section to BAC; banks without finance data see "No data". |
| C120 | `ScheduledReport` only records figures; there is no delivery. | Kept. Finance types store the row count and totals, and creating one needs `export it-finance`. |
| C121 | "5 working days" has no holiday calendar. | Mon–Fri; Nigerian public holidays are not modelled (logged). |
| C122 | `pendingApprovals` for processes that are not configured. | Only `ApprovalRequest` rows are listed; records under the unconfigured default have no request row. |
| C123 | The issue severity scale is critical, high, moderate or low. | Red maps to high and amber to moderate. |
| C124 | Which periods the nightly sweep evaluates. | Ended, not locked, in open FYs; locked periods are frozen. |
| C125 | The line owner is a recipient, but lines have no owner. | The budget's `owner_id`. |

---

## 11. Risks, open questions, deferrals

**Sign-off needed:** C102/P4-6, C103/P4-1, C105/P4-12 and C106/P4-10. Everything else is decided above.

**Other P3.1 items:**
- **Folded in:** M7, M8 and M9.
- **Deferred (P3.1 or P7):**
  - M10 (releases into a planning FY). The close checklist only looks at the period being closed, so it never blocks.
  - M11 (GL duplicates keyed only on reference).
  - M12 (no cap on direct invoices).
  - Security L1, L2 and L4.
  - L14 and L15.
- **L13 (escalating emergency spend) still needs the owner's decision.** It is not monitoring.

**Risks:**
- **R1, stuck demo approvals:** FBN's pending reopen needs cfo@ to hold `approve it-finance` and the job title "Chief Financial Officer". Verify this in SMOKE, not from the seeder. The CFO stage also must not be blocked by SoD: the requester is finance@, never cfo@.
- **R2, KHB Sep reopened:** two open periods (Sep, Oct) change the "first open period" defaults used by later code. The P3 seeder has already run. Check that `RouteSmokeTest`'s expense-estate assertions do not assume Sep is closed (none found). Invoice period defaults follow the invoice date.
- **R3, coupling to `KriDashboardsDemoSeeder`:** it copies the Kano `KRI-%` rows and **deletes and re-records the readings**. That is intended here, and the finance KRIs get their history from it. If that seeder's guard ever skips a bank, the finance KRIs have no readings, and the SMOKE estate test catches it.
- **R4, the schedules guard** (`ReportsIntegrationsSettingsDemoSeeder` skips any bank with a schedule). This is handled by the second pass; a schedule created in the first pass would silently remove that module's demo.
- **R5, BvA performance:** at 74 lines × 12 periods × lineage, BvA must stay at about 6 grouped queries. Reviewers should check for N+1 in the variance sweep (P2 security L1 pattern).
- **R6, `FiscalCalendarController` rewiring:** every existing UI close now runs the checklist. On real data, a bank whose GL import is late can't close; that is the point, but tell UAT.
- **R7, the XLSX writer:** it is hand-rolled OOXML. Keep it to one sheet with inline strings and test that it opens with ZipArchive; Excel strictness is the risk. The fallback is CSV.
- **R8, suite time:** about 65 more tests, roughly +4 min. Disk is tight; drop `it_security_risk_fin_qa` after QA if needed.
- **R9, seeded variance tuning:** the FBN floor (5m) needs larger top-ups. The seeder must assert its own targets (counts per severity) and warn rather than fail silently.

**Deferred from P4 scope:**
- FIN-04.09 rolling 24-month trend: only the 12 months of the FY are charted (S).
- Showback page (P6).
- Cloud KRI and cloud figures (P6).
- Investment and benefits figures (P5).
- Copilot finance skills (FIN-07.08, S).
- Report delivery by email.
- Variance counts in the daily digest.
- FY contiguity (P7).
- Credit notes (P7, C92).
