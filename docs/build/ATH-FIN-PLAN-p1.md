# ATH-FIN-PLAN-p1: IT Financial Management, Phase P1 (Budgeting)

**Spec:** ATH-FIN-001 §3.2 (FIN-02), §4.2, §5.1, §6 (`finance.budget_approval`, `finance.budget_transfer`, `finance.supplementary_budget`), §7 (approval notifications), §10 (Budgets pages). **Plus the P1 deferrals from ATH-FIN-003 §P0:** versioned DoA bands + maker-checker + audit event + band snapshot (C8/S7/D5), TBM pool × tower on lines (D3), FX `rate_type`/`rate_date` (D8), base-currency guard (S8/C13), per-bank DoA bands (D16). **Branch:** `feature/it-finance-p1`.

**Order:** BACKEND (§2) first; it fixes the props contract (§6). Then FRONTEND (§3) ∥ WORKFLOW (§4). Then QA (§5). File sets do not overlap (§7 ownership table). Shared files are each owned by exactly one slice.

**DB rule (all agents):** never touch `it_security_risk`. Builders: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_fin_test php vendor/bin/phpunit --filter <Class>`. Seed: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch php artisan migrate:fresh --seed`. Smoke: `SMOKE=1 DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch php vendor/bin/phpunit --filter RouteSmokeTest`. Frontend: `npm run build`. Pint only on touched PHP files.

**Route collision check (done):** `php artisan route:list --except-vendor` = 719 lines; finance names present are only P0's (`finance.settings*`, `finance.cost-centres.*`, `finance.categories.*`, `finance.gl-accounts.*`, `finance.currencies.*`, `finance.fx-rates.*`, `finance.tax-types.*`, `finance.drivers.*`, `finance.fiscal-years.*`, `finance.periods.close|lock`). Zero matches for `finance.budgets|cycles|targets|transfers|supplementary|doa-bands`; the only "target(s)" routes are `csat.ma.target*` and `ea.maturity.targets` (different prefixes). No collisions.

---

## 1. Conventions to copy (verified in code)

| concern | source | rule for P1 |
|---|---|---|
| Tenancy | `app/Models/Finance/Concerns/FinanceRecord.php` | Every new model `use FinanceRecord` (strict `BelongsToOrganization` + `LogsActivity` log `it-finance` + `created_by_id`). Never `BelongsToTenant`. |
| Codes | `app/Models/Concerns/GeneratesSequentialCode.php`, `ChangeRequest::generateNextCode` | `static::nextSequentialCode($orgId, '<col>', '<DEFAULT>-')`. Defaults `BC-`, `BUD-`, `VIR-`, `SUP-`, `ALC-`. Demo seeder writes the first code with the bank prefix (`KHB-BUD-0001`) so the series continues per bank (C44). |
| Transitions | `ChangeWorkflow::TRANSITIONS`, `PolicyWorkflow::TRANSITIONS` + `apply()` | `const TRANSITIONS = ['action' => ['from' => [...], 'to' => '...']]`; refusals `ValidationException::withMessages(['workflow' => …])` (rendered by `AuthenticatedLayout`). |
| Engine use | `PolicyWorkflow::apply()` (`assertCanInitiate` → `start` on submit; `gate()` on approve → stop if message; `reject() || cancel()` on return) | Same sequence, wrapped by `FinanceApprovals` (§2.5). |
| Engine conditions | `ApprovalEngine::start()` filters stages by `in_array((string) $subject->{condition_field}, condition_values, true)` | Condition attributes must be **string-valued accessors** (C30). |
| Lazy defaults | `WorkflowStudioController::changeProps()` calls `ChangePolicySeeder::ensureDefaults()` | `FinancePolicySeeder::ensureDefaults()` is called from Workflow Studio `show` for `finance.*` and from the demo seeder (C34). |
| Notifications | `app/Notifications/BaseNotification.php`, `Notifications/Change/*` | `database`+`mail`, `$type`, `$module`, `$actionUrl`, `getSubject/getMessage`. |
| Sweeps | `routes/console.php` `changes:sweep` (closure command + `Schedule::command(...)->hourly()->name()->withoutOverlapping()`) | `fin:approvals-sweep`, `fin:doa-activate` the same way. |
| Events | `php artisan event:list`: listener **auto-discovery is ON** (EA listeners are listed twice because they are also `Event::listen`ed, C35) | Finance listeners live in `app/Listeners/Finance/` with a typed `handle()`; **do not** register them in `AppServiceProvider`. |
| Policy mapping | `AppServiceProvider::registerFinancePolicies()` globs `app/Models/Finance/*.php` | New models are auto-mapped to `FinancePolicy`; no provider edit needed. |
| Approval trail UI | `resources/js/Components/Approvals/ApprovalTrail.jsx` (`process`, `id`) via `workflows.trail` | Reuse on Budget Show and in transfer/supplementary drawers. `workflows.trail` maps `approve it-finance` → `view it-finance`, fine. |
| Index pages | `ChangeRequestController@index` returns plain arrays (no paginator) | Budget/transfer volumes are small; plain arrays, server filters via query string. |
| UI | `Pages/Changes/*`, `Pages/Finance/Settings.jsx`, `Utils/finance.js` (`FIELD`, `LABEL`, `BTN_*`, `formatMoney`) | Material Symbols only, navy/gold, no `rounded-full` pills, 375 px usable. |

Demo users verified in scratch DB: Kano/First Bank each have `itops@` (Head of IT Operations), `cfo@` (Chief Financial Officer), `cio@` (Chief Information Officer), `finance@` (IT Finance Controller), `procurement@`; **no MD/CEO user** (C32).

---

## 2. BACKEND (fin-backend-engineer)

Build in this order; run your own `--filter` classes after each step.

### 2.1 `config/finance.php` (edit; add keys, keep P0 keys)

```php
'fx' => [
    'rate_types' => ['spot', 'average', 'closing', 'budget'],
    'fallback'   => ['budget' => ['spot'], 'average' => ['spot'], 'closing' => ['spot'], 'spot' => []],
    'planning_rate_type' => 'budget',          // budget lines convert at this type on the FY start date
],
'phasing' => ['seasonal' => [6, 7, 8, 8, 8, 8, 8, 8, 9, 9, 10, 11]],   // weights by period sequence 1..12
'transfers' => ['allow_capex_opex' => false],
'money_tables' => ['fin_budget_lines', 'fin_budget_transfers', 'fin_supplementary_requests'], // P3+ append
'workbook' => ['max_rows' => 2000, 'max_kb' => 4096],
'approval_defaults' => [            // base-currency thresholds; FinancePolicySeeder maps them onto each bank's bands
    'budget_md_ceo_from' => 250000000,
    'supplementary_cio_from' => 50000000,
],
```

### 2.2 Migrations (driver-agnostic: no raw SQL, no `ALTER`; Postgres + SQLite + MySQL)

Every new table: `id()`, `unsignedBigInteger('organization_id')->index()`, `foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete()`, `timestamps()`. Money = `decimal(18,2)`, `fx_rate decimal(18,8)`, currency `char(3)`.

**`database/migrations/2027_02_01_000001_create_it_finance_budgeting_tables.php`** (create in this order; `down()` reverse):

| table | columns | indexes / FKs |
|---|---|---|
| `fin_budget_cycles` | `cycle_code` str32 · `fiscal_year_id` FK fin_fiscal_years (restrict) · `name` str160 · `type` str24 · `opens_on` date · `closes_on` date · `instructions` text null · `coordinator_id` FK users null nullOnDelete · `status` str16 `planned` · `softDeletes()` | unique(org, cycle_code) · index(org, fiscal_year_id, status) |
| `fin_budget_targets` | `cycle_id` FK fin_budget_cycles cascade · `cost_centre_id` FK fin_cost_centres null nullOnDelete · `category_id` FK fin_categories null nullOnDelete (pool or tower) · `legal_entity_id` FK legal_entities null nullOnDelete · `capex_ceiling` dec null · `opex_ceiling` dec null · `notes` str500 null · `set_by_id` FK users null nullOnDelete | index(org, cycle_id). Scope uniqueness (cycle, cc, cat, le) enforced in service (NULLs). |
| `fin_budgets` | `budget_code` str32 · `cycle_id` FK fin_budget_cycles (restrict) · `fiscal_year_id` FK (restrict) · `cost_centre_id` FK fin_cost_centres (restrict) · `legal_entity_id` FK null nullOnDelete · `version_no` smallInt 1 · `previous_version_id` FK fin_budgets null nullOnDelete · `superseded_by_id` FK fin_budgets null nullOnDelete · `source_budget_id` FK fin_budgets null nullOnDelete (prior-year baseline for incremental) · `increment_pct` dec(7,2) null · `title` str200 · `mode` str16 `zero_based` · `status` str16 `draft` · `baseline_slot` str64 null · `owner_id` FK users null · `submitted_by_id` FK users null · `submitted_at` ts null · `approved_by_id` FK users null · `approved_at` ts null · `baselined_by_id` FK users null · `baselined_at` ts null · `executive_summary` text null · `assumptions` text null · `risks_summary` text null · `total_capex` dec 0 · `total_opex` dec 0 · `total_base_amount` dec 0 · `rejection_reason` text null · `softDeletes()` (all user FKs nullOnDelete) | unique(org, budget_code, version_no) · **unique(org, baseline_slot)** (C37) · index(org, fiscal_year_id, cost_centre_id, status) · index(cycle_id) |
| `fin_budget_lines` | `budget_id` FK fin_budgets cascade · `line_no` int · `source_line_id` ubi null index (lineage, no FK) · `origin` str16 `planned` (`planned`·`supplementary`·`transfer`) · `description` str255 · `cost_pool_id` FK fin_categories (restrict) · `tower_id` FK fin_categories (restrict) · `category_id` FK fin_categories null nullOnDelete · `gl_account_id` FK fin_gl_accounts null nullOnDelete · `cost_centre_id` FK fin_cost_centres (restrict) · `legal_entity_id` FK null nullOnDelete · `is_capex` bool false · `is_recurring` bool false · `priority` str16 `discretionary` · `rgt` str16 `run` · `vendor_id` FK vendors null nullOnDelete · `contract_id` ubi null index · `asset_id` FK assets null nullOnDelete · `ea_application_id` ubi null · `business_service_id` ubi null · `investment_id` ubi null index · `risk_id` FK risks null nullOnDelete · `driver_id` FK fin_drivers null nullOnDelete · `driver_rate` dec(18,4) null · `quantity` dec(18,4) 1 · `unit_cost` dec null · `currency` char3 · `fx_rate` dec(18,8) · `fx_rate_type` str16 · `fx_rate_date` date null · `annual_amount` dec · `base_annual_amount` dec · `phasing_method` str16 `equal` · `justification` text null · `variance_amber_pct` dec(6,2) null · `variance_red_pct` dec(6,2) null · `py_budget` dec null · `py_actual` dec null · `sort_order` int 0 | unique(budget_id, line_no) · index(org, cost_centre_id) · index(org, tower_id) · index(org, cost_pool_id) |
| `fin_budget_line_periods` | `budget_line_id` FK cascade · `period_id` FK fin_periods (restrict) · `amount` dec · `base_amount` dec | unique(budget_line_id, period_id) · index(org, period_id) |
| `fin_budget_scenarios` | `budget_id` FK cascade · `name` str120 · `adjustment_pct` dec(7,2) 0 · `line_adjustments` json null (`{line_id: pct}`) · `is_promoted` bool false · `promoted_at` ts null · `promoted_by_id` FK users null | index(budget_id) |
| `fin_budget_comments` (C38) | `budget_id` FK cascade · `budget_line_id` FK fin_budget_lines null cascadeOnDelete · `user_id` FK users null nullOnDelete · `approval_request_id` ubi null · `kind` str16 (`comment`·`return`·`reject`·`approve`) · `body` text | index(budget_id) |
| `fin_budget_adjustments` (C22) | `budget_line_id` FK fin_budget_lines (restrict) · `period_id` FK fin_periods (restrict) · `source_type` str24 (`transfer`·`supplementary`) · `source_id` ubi · `amount` dec (line currency, signed) · `base_amount` dec (signed) · `applied_by_id` FK users null · `applied_at` ts | index(org, budget_line_id) · index(source_type, source_id) |
| `fin_budget_transfers` | `transfer_code` str32 · `fiscal_year_id` FK · `from_line_id` FK fin_budget_lines (restrict) · `to_line_id` FK null (restrict) · `to_cost_centre_id` FK null · `to_cost_pool_id` FK fin_categories null · `to_tower_id` FK fin_categories null · `to_category_id` FK null · `to_description` str255 null · `from_period_id` FK fin_periods null · `to_period_id` FK fin_periods null · `amount` dec · `currency` char3 (= base) · `base_amount` dec · `reason` text · `requested_by_id` FK users null · `status` str16 `draft` · `submitted_at` ts null · `approved_by_id` · `approved_at` · `rejection_reason` text null · `applied_by_id` · `applied_at` · `softDeletes()` | unique(org, transfer_code) · index(org, status) · index(fiscal_year_id) |
| `fin_supplementary_requests` | `request_code` str32 · `fiscal_year_id` FK · `cost_centre_id` FK · `cost_pool_id` FK fin_categories null · `tower_id` FK fin_categories null · `category_id` FK null · `target_line_id` FK fin_budget_lines null · `title` str200 · `is_capex` bool · `amount` dec · `currency` char3 · `fx_rate` dec(18,8) · `fx_rate_type` str16 · `fx_rate_date` date null · `base_amount` dec · `justification` text · `requested_by_id` · `status` str16 `draft` · `submitted_at` · `approved_by_id` · `approved_at` · `rejection_reason` · `applied_by_id` · `applied_at` · `resulting_line_id` FK fin_budget_lines null · `softDeletes()` | unique(org, request_code) · index(org, status) |

**`database/migrations/2027_02_01_000002_create_it_finance_allocation_tables.php`** (tables only; execution is P6, C27):
- `fin_allocations`: `allocation_code` str32 · `source_type` str24 · `source_id` ubi · `target_type` str24 · `target_id` ubi · `method` str16 · `percentage` dec(7,4) null · `driver_id` FK fin_drivers null · `effective_from` date · `effective_to` date null · `is_active` bool true · `softDeletes()` | unique(org, allocation_code) · index(source_type, source_id) · index(target_type, target_id).
- `fin_allocation_results`: `allocation_id` FK cascade · `period_id` FK fin_periods · `amount_type` str16 · `base_amount` dec · `computed_at` ts | unique(allocation_id, period_id, amount_type).

**`database/migrations/2027_02_01_000003_create_it_finance_doa_versions_and_notification_logs.php`**
- `fin_doa_band_versions`: `version_no` smallInt · `bands` json · `effective_from` date · `status` str16 (`pending`·`scheduled`·`active`·`superseded`·`rejected`·`withdrawn`) · `reason` text null · `proposed_by_id` FK users null · `proposed_at` ts · `decided_by_id` FK users null · `decided_at` ts null · `decision_note` text null · `activated_at` ts null · `superseded_at` ts null | unique(org, version_no) · index(org, status, effective_from).
- Backfill in `up()` via `DB::table`: for every `fin_settings` row insert version 1 `active` with its `doa_bands` (or `config('finance.settings_defaults.doa_bands')` when null), `effective_from` = `created_at` date, `proposed_at`/`activated_at` = now.
- `fin_notification_logs` (§4.7): `notifiable_type` str · `notifiable_id` ubi · `notification_key` str64 · `offset_days` smallInt null · `sent_to_user_id` FK users null nullOnDelete · `sent_at` ts · `channel` str16 `database` | index(notifiable_type, notifiable_id, notification_key) · index(org, sent_at). No unique (P2 adds its own idempotency rule).
- `approval_requests`: `Schema::table` add `json('context')->nullable()` (C31). `down()` drops the column.

**`database/migrations/2027_02_01_000004_add_rate_type_to_fin_fx_rates.php`** (D8)
- `Schema::table('fin_fx_rates')`: `string('rate_type', 16)->default('spot')`; `dropUnique('fin_fx_rates_pair_date_unique')`; `unique(['organization_id','from_currency','to_currency','rate_date','rate_type'], 'fin_fx_rates_pair_date_type_unique')`. Two separate `Schema::table` calls (add column, then index swap) so SQLite's table rebuild sees the column. `down()` reverses.

### 2.3 Models `app/Models/Finance/` (all `use FinanceRecord; protected $guarded = [];` explicit `$table`)

| model (table) | extra traits | constants | casts | relations / scopes / accessors |
|---|---|---|---|---|
| `BudgetCycle` (`fin_budget_cycles`) | SoftDeletes, GeneratesSequentialCode | `TYPES=['annual','mid_year_reforecast','rolling_forecast','supplementary']`, `STATUSES=['planned','open','in_review','closed']` | dates | `fiscalYear()`, `coordinator()`, `budgets()`, `targets()`; `scopeOpen` (status open); `scopeForFiscalYear($id)`; `generateNextCode($org)` `BC-` |
| `BudgetTarget` (`fin_budget_targets`) | — | — | ceilings `decimal:2` | `cycle()`, `costCentre()`, `category()`, `legalEntity()`, `setBy()`; `scopeLabel` accessor `scope_label` ("KHB-IT-DC · Opex/Capex", "Tower TW-SEC") |
| `Budget` (`fin_budgets`) | SoftDeletes, GeneratesSequentialCode | `STATUSES=['draft','submitted','under_review','approved','baseline','revised','rejected','withdrawn']`, `MODES=['zero_based','incremental','from_actuals']`, `EDITABLE=['draft','rejected']`, `LOCKED=['approved','baseline','revised']`, `IN_APPROVAL=['submitted','under_review']`, `LOCKED_WRITABLE=['status','superseded_by_id','baseline_slot','baselined_at','baselined_by_id','updated_at']` | dates/timestamps, totals `decimal:2`, `increment_pct` `decimal:2` | `cycle()`, `fiscalYear()`, `costCentre()`, `legalEntity()`, `owner()`, `submittedBy()`, `approvedBy()`, `baselinedBy()`, `previousVersion()`, `supersededBy()`, `sourceBudget()`, `lines()` (ordered sort_order, line_no), `scenarios()`, `comments()`; `versions()` = same org + `budget_code` ordered `version_no`; scopes `scopeForFiscalYear`, `scopeBaseline`, `scopeLatestVersions` (max version per budget_code), `scopeVisibleTo(User)` (cost-centre restriction via `FinancePolicy::visibleCostCentreIds`); accessors **`amount_band`** (`DoaBandService::bandFor(org, total_base_amount)['key']`), `amount_band_label`, **`cycle_type`** (cycle->type), `version_label` ("v2"), `is_locked` (status ∈ LOCKED), `is_editable`; `booted()`: `updating` throws `ValidationException(['workflow'=>'Version N is <status> and immutable; create a revision.'])` when original status ∈ LOCKED and any dirty key ∉ LOCKED_WRITABLE; `deleting` refused unless status draft; `generateNextCode` `BUD-` |
| `BudgetLine` (`fin_budget_lines`) | — | `PRIORITIES=['mandatory','regulatory','committed','discretionary']`, `RGT=['run','grow','transform']`, `PHASING=['equal','custom','front','back','seasonal']`, `ORIGINS=['planned','supplementary','transfer']`; `public static bool $allowLockedAppend = false` | bools, decimals (`decimal:2`, rates `decimal:8`, qty/driver `decimal:4`), `fx_rate_date` date | `budget()`, `periods()` (BudgetLinePeriod), `adjustments()`, `costPool()`, `tower()`, `category()`, `glAccount()`, `costCentre()`, `legalEntity()`, `vendor()` (`App\Models\Vendor`), `asset()` (`App\Models\Asset`), `risk()` (`App\Models\Risk`), `eaApplication()` (`App\Models\Ea\EaApplication`), `businessService()` (`App\Models\Ea\BusinessService`), `driver()`, `sourceLine()`; accessors `adjustments_base` (sum), `current_base_amount` (= base_annual_amount + adjustments), `yoy_pct` ((base_annual − py_budget)/py_budget ×100, null when py null/0); `booted()`: `saving`/`deleting` throw `workflow` when parent budget `is_locked`, except a **create** while `self::$allowLockedAppend` (supplementary append, C22) |
| `BudgetLinePeriod` (`fin_budget_line_periods`) | — | — | decimals | `line()`, `period()`; `saving`/`deleting` guard as BudgetLine |
| `BudgetScenario` (`fin_budget_scenarios`) | — | — | `line_adjustments` array, `is_promoted` bool, `adjustment_pct` `decimal:2` | `budget()`, `promotedBy()` |
| `BudgetComment` (`fin_budget_comments`) | — | `KINDS` | — | `budget()`, `line()`, `user()` |
| `BudgetAdjustment` (`fin_budget_adjustments`) | — | `SOURCES=['transfer','supplementary']` | decimals, `applied_at` | `line()`, `period()`, `appliedBy()`; immutable: `updating`/`deleting` throw |
| `BudgetTransfer` (`fin_budget_transfers`) | SoftDeletes, GeneratesSequentialCode | `STATUSES=['draft','submitted','approved','rejected','applied','withdrawn']` | decimals, timestamps | `fiscalYear()`, `fromLine()`, `toLine()`, `toCostCentre()`, `toCostPool()`, `toTower()`, `toCategory()`, `fromPeriod()`, `toPeriod()`, `requestedBy()`, `approvedBy()`, `appliedBy()`; accessors **`amount_band`** (base_amount), **`crosses_cost_centre`** (`'yes'`/`'no'`), **`capex_opex`** (`'capex'`/`'opex'`/`'mixed'`) (C30); `generateNextCode` `VIR-` |
| `SupplementaryRequest` (`fin_supplementary_requests`) | SoftDeletes, GeneratesSequentialCode | `STATUSES` as transfers | decimals, `is_capex` bool, dates | `fiscalYear()`, `costCentre()`, `costPool()`, `tower()`, `category()`, `targetLine()`, `resultingLine()`, `requestedBy()`, `approvedBy()`; accessors **`amount_band`**, `capex_opex`; `generateNextCode` `SUP-` |
| `Allocation` (`fin_allocations`), `AllocationResult` (`fin_allocation_results`) | Allocation: SoftDeletes, GeneratesSequentialCode (`ALC-`) | `SOURCE_TYPES`, `TARGET_TYPES`, `METHODS`, `AMOUNT_TYPES` per §4.2 | — | relations only (no service in P1) |
| `DoaBandVersion` (`fin_doa_band_versions`) | — | `STATUSES` | `bands` array, `effective_from` date, timestamps | `proposedBy()`, `decidedBy()`; `scopeActive`; accessor `keys` (array of band keys) |
| `NotificationLog` (`fin_notification_logs`) | — | — | `sent_at` datetime | `notifiable()` morphTo, `user()` |

Edits: `FxRate` add `RATE_TYPES` (from config) + no cast change; `FiscalYear` add `budgets()`, `cycles()`; `App\Models\Approvals\ApprovalRequest` add `'context' => 'array'` to `$casts` (backend owns this one-line edit).

### 2.4 Policy `app/Policies/Finance/FinancePolicy.php` (edit)

Keep P0 abilities for config models. Add (planning models = `Budget`, `BudgetLine`, `BudgetScenario`, `BudgetComment`, `BudgetCycle`, `BudgetTarget`, `BudgetTransfer`, `SupplementaryRequest`):
- `view(User, Model)`: existing check **plus** for planning models `costCentreVisible($u, $m)` where the cost centre is: Budget/BudgetLine → `cost_centre_id`; Transfer → from line's cc or `to_cost_centre_id` (or `requested_by_id === $u->id`); Supplementary → `cost_centre_id` (or requester). `visibleCostCentreIds` null ⇒ visible.
- `createPlan(User)` ⇒ `can('create it-finance')`.
- `updatePlan(User, Model)` ⇒ `can('edit it-finance')` ∧ same org ∧ visible.
- `decidePlan(User, Model)` ⇒ `can('approve it-finance')` ∧ same org (SoD checked in services).
- `baselinePlan(User, Budget)` ⇒ `can('approve it-finance')` ∧ same org.
- `managePlanning(User)` ⇒ `can('manage it-finance-settings')` (cycles, targets).
- `proposeBands(User)` ⇒ `can('manage it-finance-settings')`; `checkBands(User, DoaBandVersion)` ⇒ (`approve it-finance` ∨ `manage it-finance-settings`) ∧ same org ∧ `proposed_by_id !== $u->id`.

### 2.5 Services `app/Services/Finance/`

**`DoaBandService`** (container singleton not required) — S7/D5/C8/C29/D16
- `active(int $orgId, ?CarbonInterface $on = null): DoaBandVersion` — latest `active` with `effective_from ≤ on` (default today); creates v1 from settings on the fly if none (fresh orgs after migration).
- `bandFor(int $orgId, string|float $baseAmount, ?CarbonInterface $on = null): array{key,label,min,max,version_id,version_no}` (min ≤ amount < max; last open-ended).
- `conditionValues(int $orgId): array<string,string>` key ⇒ "10m-50m (₦10,000,000 to ₦50,000,000)" for Workflow Studio.
- `propose(int $orgId, array $bands, string $effectiveFrom, ?string $reason, User $maker): DoaBandVersion` — `FinanceSettings::validateBands` + key generation; `effective_from ≥ today`; refuses when another `pending`/`scheduled` version exists; status `pending`; activity event `doa_bands.proposed`.
- `approve(DoaBandVersion $v, User $checker, ?string $note): DoaBandVersion` — refuses maker = checker (`workflow`: "Maker-checker: the person who proposed the bands cannot approve them."); refuses when an active `finance.*` stage references `amount_band` ordinal ≥ new band count (message names the stage); `decided_*`; if `effective_from ≤ today` ⇒ `activate()`, else status `scheduled`; activity `doa_bands.approved`.
- `reject(DoaBandVersion, User, string $note)`, `withdraw(DoaBandVersion, User $maker)`; activity `doa_bands.rejected` / `.withdrawn`.
- `activate(DoaBandVersion $v): void` — transaction: previous active ⇒ `superseded` (`superseded_at`); `$v` ⇒ `active` (`activated_at`); write `fin_settings.doa_bands = $v->bands` (cache read by P0 code) + `FinanceSettings::flush()`; **remap** every `ApprovalWorkflowStage` of the org whose workflow `process` starts `finance.` and `condition_field = 'amount_band'`: old key ⇒ new key by ordinal (out of range ⇒ last band, recorded); activity `doa_bands.activated` with properties `{version_no, old_bands, new_bands, effective_from, maker_id, checker_id, remapped_stages: [{stage_id, from, to}]}`. This is the **dedicated audit event** (log `it-finance`, `event` column set).
- `activateDue(): int` — all orgs, `scheduled` with `effective_from ≤ today` ⇒ `activate`. Used by `fin:doa-activate`.

**`FinanceSettings`** (edit): `bandFor()` delegates to `DoaBandService::bandFor(...)['key']`; `update(..., 'doa', ...)` now throws `workflow` "DoA bands change through a proposal and a second approver (maker-checker)." (remove `doa` from `SECTION_KEYS`, keep `validateBands`/key generation public as `bandsWithKeys(array): array`); `short()` adds billions: `1000000000 ⇒ "1bn"`, `2500000000 ⇒ "2.5bn"` only when exact to 0.1bn else falls through to `m`.

**`CurrencyService`** (edit) — D8, S8/C13
- `quote(int $orgId, string $from, string $to, CarbonInterface $on, string $rateType = 'spot'): ?array{rate:string, rate_date:string, rate_type:string}` — tries `$rateType`, then `config('finance.fx.fallback')[$rateType]` in order; direct → inverse → triangulated (triangulated `rate_date` = older leg date). Same currency ⇒ `['1.00000000', $on, $rateType]`.
- `rate()` unchanged signature (+ optional `$rateType='spot'`), returns `quote()['rate']`.
- `convert(int $orgId, string $amount, string $currency, CarbonInterface $on, string $rateType = 'spot'): array{amount, currency, fx_rate, base_amount, rate_type, rate_date}`.
- `setBase(Currency $c)`: before switching, `hasMoneyRows($orgId)` over `config('finance.money_tables')` (`DB::table($t)->where('organization_id',$orgId)->exists()`, soft-deleted rows count) ⇒ `workflow` "The base currency cannot change once budgets, transfers or supplementary requests exist."; public `baseLockedReason(int $orgId): ?string`.
- `FinanceCurrencyController@fxRateStore` (edit): `rate_type` `in:` config types, default `spot`; upsert key includes `rate_type`. `currencyUpdate` with `is_base` relies on the guard.
- `FinanceCsvImporter::fxRates` (edit): optional header `rate_type` (default `spot`), validated; upsert key includes it.

**`FiscalCalendarService::deleteYear`** (edit): also refuse when the FY has cycles or budgets.

**`FinanceApprovals`** — the single seam between finance records and `ApprovalEngine` (C31)
- `start(string $process, Model $subject, User $actor): ?ApprovalRequest` — `engine->assertCanInitiate`; `engine->start`; when a request exists write `context` = `snapshot($process, $subject)`; dispatch `FinanceApprovalRequested($process, $subject, $request, $actor)`.
- `snapshot(string $process, Model $subject): array` — `['captured_at', 'base_amount', 'amount_band' => key, 'band' => {label,min,max}, 'doa_band_version_id', 'doa_band_version_no', 'conditions' => [field => (string) $subject->{field} for every condition of the process]]`.
- `approve(string $process, Model $subject, User $actor, ?string $note): ?string` — `engine->gate`; when a message is returned (more stages) dispatch `FinanceApprovalRequested` for the next step; returns the message or null (final).
- `decline(string $process, Model $subject, User $actor, string $note, string $outcome /* returned|rejected */): void` — `engine->reject() || engine->cancel()`; dispatch `FinanceApprovalDecided`.
- `finalised(string $process, Model $subject, User $actor, ?string $note)`: dispatch `FinanceApprovalDecided(..., 'approved', ...)`.
- `withdraw(string $process, Model $subject, User $actor)`: `engine->cancel($process, $subject, 'Withdrawn by '.$actor->name)`; dispatch `FinanceApprovalDecided(..., 'withdrawn', ...)`.
- `trail(string $process, Model $subject, User $viewer): array` = `engine->trail()` + `'context' => request->context`.
- `assertDefaultApprover(Model $subject, User $actor, string $initiatorAttr, ?string $ownerAttr)`: used only when `engine->workflow(org, process)` is null (unconfigured default): actor ≠ initiator, actor ≠ owner ("budget owner cannot be sole approver"), `can('approve it-finance')`.

**Events** `app/Events/Finance/FinanceApprovalRequested.php` (`public string $process, public Model $subject, public ?ApprovalRequest $request, public ?User $actor`) and `FinanceApprovalDecided.php` (`public string $process, public Model $subject, public string $outcome, public User $actor, public ?string $note`). `Dispatchable`, no `SerializesModels` needed (dispatched sync; listeners queue the notifications).

**`BudgetPhasing`** (pure, bcmath)
- `periodsFor(FiscalYear $fy): Collection` — non-adjustment periods ordered by sequence.
- `weights(string $method, int $n, ?array $custom = null): array` — equal 1…; front n…1; back 1…n; seasonal `config('finance.phasing.seasonal')` by sequence (missing ⇒ 1); custom = given.
- `spread(string $total, array $weights, int $dp): array` — round each half-up, **last period takes the remainder** so Σ = total exactly.
- `assertSums(string $annual, array $amounts, int $dp): void` — `ValidationException(['amounts' => 'Phasing totals X but the annual amount is Y.'])`.

**`BudgetLedger`**
- `currentByPeriod(BudgetLine $line): array<period_id,string>` (base) = line periods + adjustments.
- `available(BudgetLine $line, ?Period $p = null): string` — current base (period or full-year). P3 subtracts committed/actual here.
- `post(BudgetLine $line, Period $p, string $baseAmount, string $sourceType, int $sourceId, User $by): BudgetAdjustment` (line-currency `amount` = base / line fx_rate, rounded).

**`BudgetService`** — §5.1
```php
public const TRANSITIONS = [
    'submit'   => ['from' => ['draft', 'rejected'],            'to' => 'submitted'],
    'withdraw' => ['from' => ['submitted', 'under_review'],    'to' => 'draft'],      // C23
    'approve'  => ['from' => ['submitted', 'under_review'],    'to' => 'approved'],   // 'under_review' while stages remain
    'return'   => ['from' => ['submitted', 'under_review'],    'to' => 'draft'],
    'reject'   => ['from' => ['submitted', 'under_review'],    'to' => 'rejected'],
    'baseline' => ['from' => ['approved'],                     'to' => 'baseline'],
    'revise'   => ['from' => ['approved', 'baseline'],         'to' => 'draft'],      // new version; old keeps status until the new one is baselined
];
```
- `createVersion(BudgetCycle $cycle, CostCentre $cc, string $mode, User $by, array $attrs = []): Budget` — cycle `open`; refuses a second open version (draft/submitted/under_review/approved) for (cycle, cc); `zero_based` ⇒ empty; `incremental` ⇒ requires `source_budget_id` = prior-FY **baseline** for the same cc (default: found automatically) and `increment_pct` (−100…+100): clones lines (`source_line_id` = prior id, amounts × (1+pct), re-phased into the new FY using the prior line's per-period amounts as custom weights), `py_budget` = prior line current base; `from_actuals` ⇒ `workflow` "Needs posted actuals (available from Phase P3)." (C26). Owner defaults to cc owner. Code via `Budget::generateNextCode`.
- `addLine(Budget $b, array $data, User $by): BudgetLine` / `updateLine(BudgetLine, array, User)` / `deleteLine(BudgetLine)` / `reorder(Budget, array $ids)` — editable statuses only; validates TBM (`cost_pool_id` kind cost_pool, `tower_id` kind tower, optional `category_id` whose root is that pool or tower), dims in org (vendor/asset/risk/driver/gl/legal entity; EA app/business service visible via their own scopes); defaults `gl_account_id` ← category default GL ?? pool default GL, `is_capex` ← pool `default_capex` when not given; `cost_centre_id` = budget's (C42); `line_no` = max+1; annual = qty × unit_cost when `unit_cost` given, driver-based when `driver_id`+`driver_rate` (per-period driver value × rate, phasing forced `custom`; missing period value ⇒ error), else `annual_amount`; FX via `CurrencyService::convert($org, $annual, $currency, $fy->starts_on, config('finance.fx.planning_rate_type'))` storing `fx_rate/fx_rate_type/fx_rate_date/base_annual_amount`; then `phase()`; then `recalculate()`.
- `phase(BudgetLine $line, string $method, ?array $amounts = null /* period_id => amount */): BudgetLine` — custom ⇒ `assertSums`; others ⇒ `spread`; base per period spread from `base_annual_amount` with the same weights (remainder last).
- `bulkSave(Budget $b, array $lines, array $deletedIds, User $by): Budget` — the workbook grid; one transaction; all-or-nothing; errors keyed `lines.{i}.{field}`.
- `recalculate(Budget $b): Budget` — `total_capex`/`total_opex`/`total_base_amount` in base currency (C36).
- `checkCeiling(Budget $b): array{targets: [...], over: bool, over_line_ids: int[]}` — targets of the cycle matching the budget's cc (or ancestor cc), legal entity, or category (line `cost_pool_id`/`tower_id`/`category_id` = target category); requested = Σ over the **latest non-withdrawn, non-rejected version** per `budget_code` in the cycle inside the scope; lines flagged by walking this budget's in-scope lines in priority order (mandatory → regulatory → committed → discretionary, then sort_order) and flagging those whose cumulative crosses the ceiling. Computed, not stored.
- `submit(Budget, User)` — TRANSITIONS; cycle `open`/`in_review`; ≥1 line; every line phased (Σ periods = annual); `recalculate`; `submitted_by_id/at`; `FinanceApprovals::start('finance.budget_approval', …)`; activity event `submitted`.
- `withdraw(Budget, User)` — submitter or owner; `FinanceApprovals::withdraw`.
- `approve(Budget, User, ?string $note): string` — unconfigured ⇒ `assertDefaultApprover($b, $u, 'submitted_by_id', 'owner_id')`; configured ⇒ `FinanceApprovals::approve`; message ⇒ status `under_review`, return message; final ⇒ `approved`, `approved_by/at`, `finalised(...)`; comment kind `approve` when note.
- `return(Budget, User, string $note, array $lineComments = [])` / `reject(Budget, User, string $note)` — note required; comments stored (kind `return`/`reject`, per line); `FinanceApprovals::decline`; reject sets `rejection_reason`.
- `baseline(Budget, User): Budget` — transaction + `lockForUpdate` on the (org, fy, cc) baseline; prior baseline ⇒ `revised`, `superseded_by_id` = this, `baseline_slot` = null; this ⇒ `baseline`, `baseline_slot = "{fy_id}:{cc_id}"`, `baselined_by/at`; previous **approved** versions of the same `budget_code` ⇒ `revised`; refresh `py_budget` on open drafts of FY+1 for the cc (`refreshPriorYear`).
- `revise(Budget, User): Budget` — refuses when a draft/in-approval version of the `budget_code` exists; clones header + lines + periods to `version_no + 1` (`previous_version_id`, lines `source_line_id`), status `draft`, `mode` copied; activity `revised`.
- `editLocked(Budget $b, User $u, callable $edit): Budget` — used by update/line routes when `$b->is_locked`: `revise()` then applies the edit to the matching line (by `source_line_id`) in the new draft; returns the draft (§12 "edit attempt creates v2").
- `diff(Budget $a, Budget $b): array` — same `budget_code` required; match by lineage (`b.source_line_id = a.id`) then `line_no`; returns `{a:{id,version_no,status,total_base_amount}, b:{…}, totals:{capex_delta, opex_delta, base_delta}, lines:[{key, change:'added'|'removed'|'changed'|'unchanged', a:LineMini|null, b:LineMini|null, fields:{field:[old,new]}, base_delta, periods:{period_id: base_delta}}]}`.
- `refreshPriorYear(Budget $draft): void` — `py_budget` = matching prior-FY baseline line current base (lineage, else same `tower_id`+`cost_pool_id`+description); `py_actual` stays null until P3 (C26).
- `scenarioStore/Update/Destroy`, `promoteScenario(BudgetScenario, User)` — draft only; promotion scales lines (`(1+pct/100)`, per-line override), re-phases with existing weights, marks the scenario `is_promoted` (others false).
- `comment(Budget, User, string $body, ?int $lineId)`.

**`BudgetWorkbook`** (C24/C25)
- Columns (header row, exact): `line_no, description, cost_pool, tower, category, gl_account, is_capex, is_recurring, priority, rgt, vendor, currency, quantity, unit_cost, annual_amount, phasing_method, justification, P01 … Pnn` (period names from `BudgetPhasing::periodsFor`; codes for pool/tower/category/gl; vendor by name; booleans `Y/N`).
- `export(Budget $b, string $format /* csv|xlsx */): StreamedResponse` — text cells starting `= + - @` prefixed with `'` (S11 for this export); filename `{budget_code}-v{n}.{ext}`.
- `import(Budget $b, UploadedFile $f, User $by): array` (report `{type:'budget_workbook', file_name, row_count, accepted_count, rejected_count, created, updated, unchanged, errors:[{row,message}]}`) — editable statuses only; cap `config('finance.workbook.max_rows')`; validate every row first (unknown codes, bad numbers, Σ periods ≠ annual when both given, duplicate `line_no`); **all-or-nothing**; matched by `line_no` (update) else create; lines missing from the file are kept (no delete). Export → import of the same file ⇒ `unchanged = row_count`, no writes.
- XLSX through `openspout/openspout` **only if the orchestrator approves the dependency (Q1)**; otherwise `format=xlsx` returns 422 and P1 ships CSV only.

**`BudgetCycleService`**
- `TRANSITIONS = ['open' => ['from'=>['planned'],'to'=>'open'], 'review' => ['from'=>['open'],'to'=>'in_review'], 'close' => ['from'=>['in_review'],'to'=>'closed'], 'reopen' => ['from'=>['in_review'],'to'=>'open']]`.
- `create(int $orgId, array $data, User)`, `update(BudgetCycle, array)` (dates within the FY, `opens_on ≤ closes_on`), `transition(BudgetCycle, string, User)` (`close` refused while any budget of the cycle is in approval).
- `saveTarget(BudgetCycle, array, User, ?BudgetTarget)`, `deleteTarget(BudgetTarget)` — one target per (cycle, cc, category, le) incl. NULLs; at least one ceiling.
- `targetUsage(BudgetCycle): array` — per target `capex_requested`, `opex_requested`, `%`, `over`.

**`BudgetTransferService`** — FIN-02.10
```php
public const TRANSITIONS = [
    'submit' => ['from' => ['draft'], 'to' => 'submitted'],
    'withdraw' => ['from' => ['draft', 'submitted'], 'to' => 'withdrawn'],
    'approve' => ['from' => ['submitted'], 'to' => 'approved'],
    'reject' => ['from' => ['submitted'], 'to' => 'rejected'],
    'apply' => ['from' => ['approved'], 'to' => 'applied'],
];
```
- `create(array $data, User)` / `update(BudgetTransfer, array)` (draft only). Rules: from line in a **baseline** budget of `fiscal_year_id`; target = existing baseline line of the same FY **or** a new line spec (`to_cost_centre_id` + `to_cost_pool_id` + `to_tower_id` + `to_description`); from ≠ to unless both periods given and differ; periods (when given) in the FY, `open`; `amount` > 0 in base currency (C43); amount ≤ `BudgetLedger::available(from, fromPeriod)`; capex↔opex refused unless `config('finance.transfers.allow_capex_opex')`; reason required.
- `submit` (re-checks availability; `FinanceApprovals::start('finance.budget_transfer')`), `approve` (default approver ≠ requester; configured ⇒ engine; final ⇒ `approved`), `reject` (note), `withdraw`, `apply(BudgetTransfer, User)`: re-check availability; creates the new target line when needed (`origin='transfer'`, appended with `$allowLockedAppend`, zero original amount); posts −amount on from and +amount on to (period-specific, or spread over the FY's remaining **open** periods with `BudgetPhasing::spread` on the same weights for both sides); **asserts Σ base of the transfer's adjustments = 0** (net-zero) else rolls back; `applied_*`.

**`SupplementaryBudgetService`** — FIN-02.11; same TRANSITIONS as transfers.
- `create/update`: fy, cc (visible), `cost_pool_id`+`tower_id` (+ optional category) **or** `target_line_id` (baseline line of the FY, same cc); `amount`+`currency` converted at today's `spot` (`CurrencyService::convert`); justification required.
- `submit` ⇒ `finance.supplementary_budget`; `approve`/`reject`/`withdraw` as transfers.
- `apply`: with `target_line_id` ⇒ +adjustments over remaining open periods; else appends a new `origin='supplementary'` line (zero original, `$allowLockedAppend`) to the cc's FY baseline and posts the adjustments; sets `resulting_line_id`. Refused when the cc has no FY baseline.

### 2.6 Controllers `app/Http/Controllers/Finance/`

Every action: `Gate::authorize` per §2.4 on top of route middleware; services throw `workflow` refusals; success ⇒ `back()`/redirect with `flash.success` (or `flash.warning` for "saved as revision v2"); imports add `flash.import_report`.

| controller | methods |
|---|---|
| `BudgetController` | `index`, `create`, `store`, `show`, `edit` (Workbook page), `update` (header/narrative; locked ⇒ `editLocked`), `submit`, `withdraw`, `approve`, `returnToDraft` (route `return`), `reject`, `baseline`, `revise`, `diff` (JSON), `comment` |
| `BudgetLineController` | `store`, `update` (locked ⇒ `editLocked`), `destroy`, `reorder`, `bulk`, `phase` |
| `BudgetScenarioController` | `store`, `update`, `destroy`, `promote` |
| `BudgetWorkbookController` | `export`, `import` |
| `BudgetCycleController` | `index`, `store`, `update`, `transition` |
| `BudgetTargetController` | `index`, `store`, `update`, `destroy` |
| `BudgetTransferController` | `index`, `store`, `update`, `submit`, `withdraw`, `approve`, `reject`, `apply` |
| `SupplementaryRequestController` | `index`, `store`, `update`, `submit`, `withdraw`, `approve`, `reject`, `apply` |
| `DoaBandController` | `store` (propose), `approve`, `reject`, `withdraw` — redirect `finance.settings?tab=doa` |
| `FinanceSettingsController` (edit) | `show` adds `doaVersions`, `can.approveBands`, `can.changeBase`, `baseLockedReason`, `fxRates[].rate_type`, `latestRates[].rate_type` (latest per pair **and** type), `options.fxRateTypes` |
| `FinanceCurrencyController` (edit) | `fxRateStore` `rate_type` |

### 2.7 Routes `routes/web.php` (edit inside the existing `finance.` group after line 723; add `use` lines)

All `{id}` params `->whereNumber()`; `{line}`/`{scenario}` nested under `{budget}` with `->scopeBindings()`.

```
GET    /budgets                                   finance.budgets.index            BudgetController@index
GET    /budgets/{budget}                          finance.budgets.show             @show            (?tab=lines|phasing|scenarios|approvals|versions|narrative|activity)
GET    /budgets/{budget}/edit                     finance.budgets.edit             @edit            (Workbook page; read-only when locked or no edit perm)
GET    /budgets/{budget}/diff                     finance.budgets.diff             @diff            (?against={id}; JSON)
GET    /cycles                                    finance.cycles.index             BudgetCycleController@index
GET    /targets                                   finance.targets.index            BudgetTargetController@index   (?cycle_id=)
GET    /transfers                                 finance.transfers.index          BudgetTransferController@index (?fiscal_year_id=&status=)
GET    /supplementary                             finance.supplementary.index      SupplementaryRequestController@index
permission:export it-finance:
  GET  /budgets/{budget}/workbook                 finance.budgets.workbook         BudgetWorkbookController@export (?format=csv|xlsx)
permission:create it-finance:
  GET  /budgets/create                            finance.budgets.create           BudgetController@create   (declare BEFORE /budgets/{budget})
  POST /budgets                                   finance.budgets.store
  POST /transfers                                 finance.transfers.store
  POST /supplementary                             finance.supplementary.store
permission:edit it-finance:
  PUT    /budgets/{budget}                        finance.budgets.update
  POST   /budgets/{budget}/lines                  finance.budgets.lines.store
  PUT    /budgets/{budget}/lines/bulk             finance.budgets.lines.bulk      (declare BEFORE lines/{line})
  POST   /budgets/{budget}/lines/reorder          finance.budgets.lines.reorder
  PUT    /budgets/{budget}/lines/{line}           finance.budgets.lines.update
  DELETE /budgets/{budget}/lines/{line}           finance.budgets.lines.destroy
  POST   /budgets/{budget}/lines/{line}/phase     finance.budgets.phase
  POST   /budgets/{budget}/workbook               finance.budgets.workbook.import
  POST   /budgets/{budget}/scenarios              finance.budgets.scenarios.store
  PUT    /budgets/{budget}/scenarios/{scenario}   finance.budgets.scenarios.update
  DELETE /budgets/{budget}/scenarios/{scenario}   finance.budgets.scenarios.destroy
  POST   /budgets/{budget}/scenarios/{scenario}/promote finance.budgets.scenarios.promote
  POST   /budgets/{budget}/comments               finance.budgets.comments.store
  POST   /budgets/{budget}/submit                 finance.budgets.submit
  POST   /budgets/{budget}/withdraw               finance.budgets.withdraw
  POST   /budgets/{budget}/revise                 finance.budgets.revise
  PUT    /transfers/{transfer}                    finance.transfers.update
  POST   /transfers/{transfer}/submit             finance.transfers.submit
  POST   /transfers/{transfer}/withdraw           finance.transfers.withdraw
  PUT    /supplementary/{supplementary}           finance.supplementary.update
  POST   /supplementary/{supplementary}/submit    finance.supplementary.submit
  POST   /supplementary/{supplementary}/withdraw  finance.supplementary.withdraw
permission:approve it-finance:
  POST /budgets/{budget}/approve                  finance.budgets.approve
  POST /budgets/{budget}/return                   finance.budgets.return
  POST /budgets/{budget}/reject                   finance.budgets.reject
  POST /budgets/{budget}/baseline                 finance.budgets.baseline
  POST /transfers/{transfer}/approve|reject|apply finance.transfers.approve|reject|apply
  POST /supplementary/{supplementary}/approve|reject|apply finance.supplementary.approve|reject|apply
permission:manage it-finance-settings:
  POST /cycles                                    finance.cycles.store
  PUT  /cycles/{cycle}                            finance.cycles.update
  POST /cycles/{cycle}/transition                 finance.cycles.transition      (body action=open|review|close|reopen)
  POST /targets                                   finance.targets.store
  PUT  /targets/{target}                          finance.targets.update
  DELETE /targets/{target}                        finance.targets.destroy
  POST /doa-bands                                 finance.doa-bands.store        (propose)
  POST /doa-bands/{doaBandVersion}/withdraw       finance.doa-bands.withdraw
permission:approve it-finance|manage it-finance-settings:
  POST /doa-bands/{doaBandVersion}/approve        finance.doa-bands.approve
  POST /doa-bands/{doaBandVersion}/reject         finance.doa-bands.reject
```
No `finance.allocations.*` in P1 (C27).

### 2.8 `app/Services/Approvals/ApprovalProcesses.php` (edit; backend owns this file because Show pages call `trail()` which needs the entries)

Add three `generic` entries (module `IT Financial Management`) and a resolver:
```php
'finance.budget_approval' => [
    'module' => 'IT Financial Management', 'label' => 'Budget version approval', 'engine' => 'generic', 'owner_cannot_approve' => true,
    'description' => 'A budget version is approved before Finance baselines it (COBIT APO06.03).',
    'permission' => 'approve it-finance', 'model' => Budget::class, 'initiator' => 'submitted_by_id', 'owner' => 'owner_id',
    'title' => ['budget_code', 'version_label', 'title'], 'route' => 'finance.budgets.show', 'initiate' => 'Submitting a budget version',
    'conditions' => [
        'amount_band' => ['label' => 'Amount band (DoA)', 'values' => 'finance.doa_bands'],
        'cost_centre_id' => ['label' => 'Cost centre', 'values' => 'finance.cost_centres'],
        'cycle_type' => ['label' => 'Cycle type', 'values' => ['annual' => 'Annual', 'mid_year_reforecast' => 'Mid-year reforecast', 'rolling_forecast' => 'Rolling forecast', 'supplementary' => 'Supplementary']],
    ],
    'default' => 'One approval by anyone with "approve it-finance" other than the submitter and the budget owner.',
],
'finance.budget_transfer' => [ … 'label' => 'Budget transfer (virement)', 'model' => BudgetTransfer::class, 'initiator' => 'requested_by_id',
    'title' => ['transfer_code'], 'route' => 'finance.transfers.index', 'initiate' => 'Submitting a budget transfer',
    'conditions' => ['amount_band' => [… 'finance.doa_bands'], 'crosses_cost_centre' => ['label' => 'Crosses cost centres', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                     'capex_opex' => ['label' => 'CapEx / OpEx', 'values' => ['capex' => 'CapEx', 'opex' => 'OpEx', 'mixed' => 'Mixed']]],
    'default' => 'One approval by anyone with "approve it-finance" other than the requester.' ],
'finance.supplementary_budget' => [ … 'label' => 'Supplementary budget', 'model' => SupplementaryRequest::class, 'initiator' => 'requested_by_id',
    'title' => ['request_code', 'title'], 'route' => 'finance.supplementary.index', 'initiate' => 'Submitting a supplementary request',
    'conditions' => ['amount_band' => [… 'finance.doa_bands'], 'category_id' => ['label' => 'TBM category', 'values' => 'finance.categories'],
                     'capex_opex' => […]],
    'default' => 'One approval by anyone with "approve it-finance" other than the requester.' ],
```
```php
/** Conditions with per-bank value lists resolved (C28). @return array<string, array{label:string, values: array<string,string>}> */
public static function conditions(string $process, ?int $orgId = null): array
// 'finance.doa_bands'   => app(DoaBandService::class)->conditionValues($orgId)
// 'finance.cost_centres'=> CostCentre::withoutGlobalScopes()->where('organization_id',$orgId)->orderBy('code') id => "CODE Name"
// 'finance.categories'  => Category (pools, towers, subs) id => "CODE Name"
// arrays pass through unchanged; null orgId ⇒ [] for markers.
```
Supplementary `category_id` = the most specific TBM category chosen (required on the request). Also update the WorkflowStudioModuleTest assertion `summary.processes` 11 ⇒ **14** (backend owns that one-line edit, §7).

### 2.9 Backend test upkeep (backend owns these edits so its own filters stay green)
- `tests/Feature/Finance/FinanceTenancyAndRoutesTest.php`: exact GET list becomes `finance.settings, finance.budgets.index, finance.budgets.create, finance.budgets.show, finance.budgets.edit, finance.budgets.diff, finance.budgets.workbook, finance.cycles.index, finance.targets.index, finance.transfers.index, finance.supplementary.index`; render-loop over parameterless ones (component names per §6); parameterised ones checked with a created budget; 404 cross-bank for budget/line/transfer/supplementary/cycle/target/doa version.
- `tests/Feature/Finance/FinanceConfigurationTest.php`: DoA test switches to propose → approve (different user) → active keys; `settings.update` `section=doa` now returns `workflow` error; FX test asserts `rate_type`/`rate_date` keys in `convert()`.
- `tests/Feature/Workflows/WorkflowStudioModuleTest.php`: `summary.processes` 14.

---

## 3. FRONTEND (fin-frontend-engineer)

Your files only (§7). Reference: `Pages/Changes/Show.jsx` (tabs + action bar), `Pages/Changes/Index.jsx` (filters, chips), `Pages/Finance/Settings.jsx` + `Components/Finance/Settings/ui.jsx` (tab bar, modal forms), `Components/Approvals/ApprovalTrail.jsx`. `errors.workflow`/`errors.approval` are rendered by the layout; still show `errors.approval` inline near the approve button.

### 3.1 `resources/js/Config/navigation.js`
In "IT Financial Management" children insert above Settings: `{ name: 'Budgets', href: 'finance.budgets.index', permission: 'view it-finance' }`. (Cycles/Targets/Transfers/Supplementary are reached through `BudgetsNav`, C39.)

### 3.2 `resources/js/Utils/finance.js` (edit; append)
`BUDGET_TONES = { draft:'neutral', submitted:'info', under_review:'warning', approved:'success', baseline:'success', revised:'muted', rejected:'error', withdrawn:'muted' }`, `REQUEST_TONES = { draft:'neutral', submitted:'warning', approved:'info', applied:'success', rejected:'error', withdrawn:'muted' }`, `CYCLE_TONES = { planned:'info', open:'success', in_review:'warning', closed:'neutral' }`, `PRIORITY_TONES`, `RGT_LABELS = {run:'Run', grow:'Grow', transform:'Transform'}`, `sumDecimal(values[]) → string` (integer-cents math; no float drift), `pctOf(a,b)`.

### 3.3 Pages `resources/js/Pages/Finance/Budgets/`
| page | contents |
|---|---|
| `Index.jsx` | `BudgetsNav` active `budgets`; KPI strip from `summary`; filters (FY, cycle, cost centre, status, search) via `router.get(route('finance.budgets.index'), filters, { preserveState: true })`; table: code + `version_label`, title, cost centre, cycle, status chip, CapEx, OpEx, total (base), band chip, updated; row → show; "New budget" (`can.create`). Empty state with link to Cycles. |
| `Create.jsx` | Form: cycle (open cycles only), cost centre (visible), title, mode radio (zero-based / incremental / from actuals disabled with hint "available once actuals are posted"), source baseline (filtered by cc, prior FY) + increment % when incremental, owner, executive summary. POST `finance.budgets.store`. |
| `Show.jsx` | Header (code vN, status chip, band chip, cc, cycle, owner, totals vs ceiling mini-bar); action bar from `can` (submit/withdraw/approve/return/reject/baseline/revise/open workbook/export CSV|XLSX); approve/return/reject in a modal with note (return also offers per-line comments: line select + text, multiple); locked banner "Version N is <status> and immutable. Editing creates version N+1." Tabs (`?tab=`, `replaceState` like Settings): **Lines** (`LinesTable` read view + add/edit `LineFormModal` when `can.edit`; columns: #, description, pool/tower, priority, RGT, CapEx/OpEx, currency amount, base, PY budget, YoY %, ceiling flag icon, adjustments + current when baseline), **Phasing** (`PhasingPanel`: per line method select + custom amounts with live Σ check, POST `finance.budgets.phase`), **Scenarios** (`ScenariosPanel`: list, add/edit pct + per-line overrides, totals side by side vs base, promote), **Approvals** (`ApprovalTrail process="finance.budget_approval" id={budget.id}` + `approval.context` band snapshot card + comments thread + add comment), **Versions** (`VersionsDiff`: versions table; pick two ⇒ `axios.get(route('finance.budgets.diff', {budget: a, against: b}))`; render added/removed/changed rows with field deltas and period deltas), **Narrative** (exec summary, assumptions, risks; PUT `finance.budgets.update`), **Activity** (`ActivityList`). |
| `Workbook.jsx` | `WorkbookGrid`: rows = lines, frozen left columns (#, description, pool, tower, priority, CapEx), then one input column per period, then annual, base, PY, YoY. Inline edit; keyboard nav (arrows/Enter/Tab); **paste** handler parses TSV from clipboard into the focused cell's block (adds rows as needed); row add/delete; totals row + column; dirty tracking; Σ periods vs annual per row (red when off, with "phasing custom" auto-set when period cells edited); ceiling bar (`CeilingBar`) from `ceiling`; Save ⇒ `router.put(route('finance.budgets.lines.bulk', budget.id), { lines, deleted_ids })`, map `errors['lines.i.field']` to cells. Import card (`can.import`, reuse `CsvImportForm` with `routeName` + params) + `ImportReport` (`type === 'budget_workbook'`). Export links. Read-only mode when `lockedReason` (with "Create revision" button ⇒ POST `finance.budgets.revise`). |
| `Cycles.jsx` | `BudgetsNav` `cycles`; table: code, name, type, FY, window, coordinator, status chip, counts (budgets/submitted/approved/baseline), requested vs ceiling; create/edit modal (`can.manage`); transition buttons per `CYCLE` status. |
| `Targets.jsx` | `BudgetsNav` `targets`; cycle select; table: scope label, CapEx ceiling/requested/% bar, OpEx ceiling/requested/% bar, over chip; add/edit/delete modal (cc, category pool/tower, legal entity, ceilings, notes). |
| `Transfers.jsx` | `BudgetsNav` `transfers`; filters FY/status; summary tiles; table: code, from (budget vN · line · cc), to, periods, amount (base), band chip, crosses-cc chip, status chip, requester; row expands to reason + `ApprovalTrail process="finance.budget_transfer"`; actions per `can`; create/edit `TransferFormModal` (from line select from `lineOptions` showing available; target: existing line or new line [cc, pool, tower, description]; optional from/to period from open periods; amount with available hint; reason). |
| `Supplementary.jsx` | Same layout; `SupplementaryFormModal` (cc, existing baseline line or pool/tower/category for a new line, title, currency + amount (base preview), CapEx, justification); `ApprovalTrail process="finance.supplementary_budget"`. |

### 3.4 Components `resources/js/Components/Finance/Budgets/`
`BudgetsNav.jsx` (tabs: Budgets · Cycles · Targets · Transfers · Supplementary, Ziggy hrefs), `BudgetHeader.jsx`, `BudgetActions.jsx` (+ decision modal), `LinesTable.jsx`, `LineFormModal.jsx` (all §6 line fields; dimension selects hidden when the option list is empty; driver mode toggle), `PhasingPanel.jsx`, `ScenariosPanel.jsx`, `VersionsDiff.jsx`, `NarrativeForm.jsx`, `CommentsThread.jsx`, `ActivityList.jsx`, `CeilingBar.jsx`, `WorkbookGrid.jsx`, `TransferFormModal.jsx`, `SupplementaryFormModal.jsx`, `CycleFormModal.jsx`, `TargetFormModal.jsx`, `BandSnapshot.jsx`.

### 3.5 Settings edits
- `Components/Finance/Settings/DoaBandsTab.jsx`: show the **active** version (version no, effective from, bands); a versions table (no, status chip, effective from, proposed by/at, decided by/at, note); "Propose new bands" editor (P0 editor reused) + `effective_from` (≥ today) + reason ⇒ POST `finance.doa-bands.store`; on a `pending` version: Approve/Reject (`can.approveBands` and `proposed_by.id !== auth.user.id`; otherwise show "Awaiting a second approver"), Withdraw for the maker. Banner: "Bands are maker-checker controlled. Changing them re-maps amount-band conditions in Workflow Studio when the version takes effect."
- `Components/Finance/Settings/CurrenciesTab.jsx`: rate-type column + select in add-rate modal (`options.fxRateTypes`); "Latest rates" tiles grouped by type; "Make base currency" disabled with `baseLockedReason` tooltip when `!can.changeBase`. CSV hint `from_currency,to_currency,rate,rate_date,rate_type`.
- `Pages/Finance/Settings.jsx`: pass `doaVersions`, `can.approveBands`, `can.changeBase`, `baseLockedReason` to the two tabs.

---

## 4. WORKFLOW (fin-workflow-integrator)

Starts after backend is green (needs §2.5 events, `DoaBandService`, `ApprovalProcesses::conditions`, `NotificationLog`). Your files only (§7).

### 4.1 `app/Services/Finance/FinancePolicySeeder.php` (new)
`public static function ensureDefaults(int $orgId): void` — idempotent **per process**: skip a process when an `ApprovalWorkflow` row exists for (org, process) (a bank that removed its stages stays unconfigured). Creates `ApprovalWorkflow` (`is_active`, `initiator_roles` null, notes "Seeded default (ATH-FIN-001 §6)") + stages (`approver_type`, `approver_ref`, `min_approvals` 1, `sla_hours`, `sequence` 10/20/30/40):

| process | stages |
|---|---|
| `finance.budget_approval` | 10 "Head of IT review" job_title `Head of IT` 72h · 20 "CFO approval" job_title `Chief Financial Officer` 72h · 30 "CIO approval" job_title `Chief Information Officer` 72h · 40 "MD/CEO approval" job_title `Managing Director` 120h, `condition_field=amount_band`, values = `bandsFrom($orgId, config('finance.approval_defaults.budget_md_ceo_from'))` |
| `finance.budget_transfer` | 10 "Finance Controller review" role `IT Finance Controller` 48h · 20 "CFO approval (cross cost centre)" job_title `Chief Financial Officer` 48h, `condition_field=crosses_cost_centre`, values `['yes']` |
| `finance.supplementary_budget` (C32) | 10 "Head of IT review" job_title `Head of IT` 72h · 20 "CFO approval" job_title `Chief Financial Officer` 72h · 30 "CIO approval" job_title `Chief Information Officer` 72h, `amount_band` ∈ `bandsFrom($orgId, config('finance.approval_defaults.supplementary_cio_from'))` |

`bandsFrom(int $orgId, int|string $threshold): array` = keys of the active bands whose `max` is null or `> threshold` (the band containing the threshold and every band above it).

### 4.2 `app/Http/Controllers/Workflows/WorkflowStudioController.php` (edit)
- `show()`: when `str_starts_with($process, 'finance.')` call `FinancePolicySeeder::ensureDefaults($orgId)` before reading; build `process.conditions` from `ApprovalProcesses::conditions($process, $orgId)` (shape unchanged: `[{field,label,values}]`).
- `validatedStage()`: allowed values from `ApprovalProcesses::conditions($def-key, $orgId)` (pass the process key in; `stageUpdate` uses `$stage->workflow->process`).
- No change to `Workflows/Process.jsx` (consumes the same shape).

### 4.3 Notifications `app/Notifications/Finance/` (extend `BaseNotification`; `$module = 'IT Financial Management'`, C48)
| class | `$type` | ctor | subject / message |
|---|---|---|---|
| `FinanceApprovalRequestedNotification` | `finance.approval_requested` | `(string $process, string $subjectLabel, ?string $stepName, string $amountText, string $url)` | "Approval needed: {label}" / "{process label} {label} ({amount}) is awaiting {step or 'your approval'}." |
| `FinanceApprovalDecisionNotification` | `finance.approval_decided` | `(string $process, string $subjectLabel, string $outcome, string $actorName, ?string $note, string $url)` | "{Label} {outcome}" / "… was {approved|returned|rejected|withdrawn} by {actor}{: note}." |
| `FinanceApprovalOverdueNotification` | `finance.approval_overdue` | `(string $process, string $subjectLabel, string $stepName, string $dueAt, string $url)` | "Overdue approval: {label} — {step}" / "… has been waiting on {step} since {due}." |

Pass scalars, not models (queued; keeps payload small and tenant-safe).

### 4.4 `app/Services/Finance/FinanceApprovalNotifier.php` (new)
- `url(string $process, Model $subject): string` — budget ⇒ `finance.budgets.show` `?tab=approvals`; transfer ⇒ `finance.transfers.index?highlight={id}`; supplementary ⇒ `finance.supplementary.index?highlight={id}`.
- `approversFor(string $process, Model $subject, ?ApprovalRequest $r): Collection<User>` — configured: `engine->eligibleUsers(currentStep, $r)` filtered `->can($def['permission'])`; unconfigured: org users with `approve it-finance`, minus initiator and (for budgets) owner.
- `escalationUsers(int $orgId): Collection` — users whose `job_title` contains `fin_settings.escalation_role` (case-insensitive), fallback role `Organization Admin` in the org.
- `requested(FinanceApprovalRequested $e)`, `decided(FinanceApprovalDecided $e)` (recipients: initiator + owner, minus actor; de-duplicated; same-org only, mirroring `ChangeWorkflow::notifyPeople`).

### 4.5 `app/Listeners/Finance/SendFinanceApprovalNotifications.php` (new; auto-discovered — **do not** add to `AppServiceProvider`)
`handleRequested(FinanceApprovalRequested $e)` and `handleDecided(FinanceApprovalDecided $e)` → notifier. (Two typed methods on one class; verify with `php artisan event:list` that each event lists the listener exactly once.)

### 4.6 `app/Services/Finance/FinanceApprovalSweeper.php` (new) + `routes/console.php` (edit)
- `sweep(): int` — `ApprovalStep::withoutGlobalScopes()` `status=pending`, `due_at < now()`, request `status=pending` and `process like 'finance.%'`, step is the request's current step; skip when a `NotificationLog` (`notifiable` = the step, key `approval_overdue`) has `sent_at > now()-24h`; recipients = approvers ∪ escalation users; send `FinanceApprovalOverdueNotification`; one `NotificationLog` row per recipient (`channel='database'`). Returns steps reminded.
- console block after Change Management:
```php
/* =====================  IT Financial Management ===================== */
Artisan::command('fin:approvals-sweep', fn (FinanceApprovalSweeper $s) => $this->info($s->sweep().' overdue finance approval reminder(s) sent.'))
    ->purpose('Remind approvers of overdue IT finance approvals (ATH-FIN-001 §7)');
Schedule::command('fin:approvals-sweep')->hourly()->name('finance.approvals-sweep')->withoutOverlapping();
Artisan::command('fin:doa-activate', fn (DoaBandService $d) => $this->info($d->activateDue().' DoA band version(s) activated.'))
    ->purpose('Activate approved DoA band versions whose effective date has arrived');
Schedule::command('fin:doa-activate')->dailyAt('00:05')->name('finance.doa-activate')->withoutOverlapping();
```
(Use full closures with `$this`, not arrow fns, if `$this` binding fails in arrow functions.)

---

## 5. QA (fin-qa-engineer)

### 5.1 Demo data — `database/seeders/FinanceDemoSeeder.php` (new) + `DatabaseSeeder.php` (wire right after `FinanceFoundationSeeder`)
`BANKS` as in `FinanceFoundationSeeder` (`seed` 8111/8112). Per bank: skip if org/admin missing; **guard** `Budget::withoutGlobalScopes()->where('organization_id', $orgId)->exists()`; `Auth::setUser`, `mt_srand`. `$y = now()->year` (FY{$y} = current, FY{$y+1} = planning; never hardcode 2026). Steps:
1. **Users:** `md@{domain}` "Managing Director/CEO" (Kano "Aminu Lawal", First Bank "Babatunde Okoro"), roles `Finance Approver`, `IT Steering Committee` (never IT Budget Owner; SoD smoke check stays green).
2. **DoA v1 (D16)** via `DoaBandService::propose` (finance@) + `approve` (cfo@), `effective_from` = Jan 1 FY{$y} (forceFill after approval since propose requires ≥ today):
   - Kano: `0–5m`, `5m–25m`, `25m–100m`, `100m–500m`, `500m+`.
   - First Bank: `0–10m`, `10m–100m`, `100m–500m`, `500m–2bn`, `2bn+`.
   - Kano also gets **v2 pending** (finance@, thresholds ×1.5, reason "FY{$y+1} inflation uplift") for the maker-checker demo.
3. **FX budget rates:** `rate_type=budget` USD/GBP/EUR→NGN dated Dec 31 FY{$y−1} (FY{$y} plan rates) and Sep 30 FY{$y} (FY{$y+1} plan rates); First Bank also GHS. Plus `closing` rates at each closed quarter end.
4. **Cycles:** `{P}-BC-0001` "FY{$y} Annual IT Budget" (annual, closed); `{P}-BC-0002` "FY{$y} Mid-year reforecast" (mid_year_reforecast, closed); `{P}-BC-0003` "FY{$y+1} Annual IT Budget" (annual, **open**, opens Sep 1, closes Nov 30, coordinator finance@, instructions text).
5. **FY{$y} baselines (history, before defaults exist):** wrap in `Event::fakeFor(…, [FinanceApprovalRequested::class, FinanceApprovalDecided::class])`. One budget per cost centre with lines (roots may have none): **Kano ≈ 60 lines over 8 budgets, First Bank ≈ 70 over 10**, realistic estate (Finacle AMC, core network refresh, Microsoft EA (USD), Oracle DB support (USD), Cisco SmartNet, Fortinet, CrowdStrike (USD), SIEM/SOC, AWS/Azure (USD), data-centre power/diesel, MTN/Airtel/Globacom links, Interswitch/NIBSS fees, staff training, CBN regtech; First Bank adds Temenos for the UK sub (GBP), Ghana sub (GHS)), all priorities/RGT mixes, CapEx and OpEx, phasing methods varied (equal/seasonal/front/back/custom). Submit by owner (cc owner; budgets of `itops`-owned ccs submitted by `itops`), approved by `cfo@` under the **unconfigured default**, baselined by `finance@`. Amounts set so totals land in several bands.
6. **Mid-year revision:** Kano `KHB-IT-DIG` and First Bank `FBN-GT-DIG`: `revise` → change 3 lines → submit/approve/baseline v2 (v1 becomes `revised`; diff demo).
7. `FinancePolicySeeder::ensureDefaults($orgId)` (stages from now on).
8. **Transfers FY{$y}** (requested by budget owners, never finance@): 2 `applied` (one within a cc between periods, one cross-cc), 1 `submitted` cross-cc (pending at "Finance Controller review" — real events, notifications queued), 1 `approved` not yet applied, 1 `rejected` (note), 1 `draft`.
9. **Supplementary FY{$y}:** 1 `applied` creating a new line ("CBN directive: enhanced fraud monitoring", `origin=supplementary`), 1 `submitted` pending, 1 `draft`.
10. **Targets FY{$y+1}:** per root cc + key children (capex/opex ceilings) + one tower-level target (`TW-SEC`); **one deliberately breached** (Kano `KHB-IT-DC` opex; First Bank `FBN-GT-DC` opex).
11. **FY{$y+1} budgets** (incremental from FY{$y} baselines, +5…+18 %): states per bank — 3 `draft` (one owned by the **unowned** cc `KHB-IT-ITSM`/`FBN-GT-DATA` with owner null, one with a discretionary line missing vendor/justification: deliberate gaps), 1 `submitted` (awaiting Head of IT; choose a cc **not** owned by `itops`, C33), 1 `under_review` (Head of IT approved, awaiting CFO), 1 `approved` (all stages) awaiting baseline, 1 `rejected` (reason), 1 `draft` returned with two per-line comments, 1 with a promoted scenario ("Cut 10 %") and one unpromoted ("Growth +8 %").
12. Finish `mt_srand(); Auth::forgetUser();`.

### 5.2 Tests (new) — `tests/Feature/Finance/`
Each class: `RefreshDatabase`, `withoutVite()`, `RolesAndPermissionsSeeder`, factory orgs, `FinanceSettings::ensureDefaults`, FY via `FiscalCalendarService`, NGN base + USD spot/budget rates, users with job titles (Head of IT…, CFO, CIO, Managing Director) and roles.

**`BudgetLifecycleTest`**
| test | proves |
|---|---|
| `test_cycles_have_types_windows_and_guarded_transitions` | FIN-02.01 |
| `test_draft_submitted_approved_baseline_happy_path_unconfigured` | FIN-02.02, 02.14 (default single approval; submitter and owner refused) |
| `test_baseline_is_immutable_and_an_edit_creates_version_two` | FIN-02.02, FIN-07.06 (direct model update throws; HTTP line update on baseline ⇒ v2 draft with the edit, v1 untouched, flash warning) |
| `test_only_one_baseline_per_fiscal_year_and_cost_centre` | FIN-02.02 (baselining v2 marks v1 `revised` + `superseded_by_id`; `baseline_slot` unique) |
| `test_transitions_refused_from_wrong_status` | §5.1 TRANSITIONS (every action from every illegal status ⇒ `workflow`) |
| `test_withdraw_return_and_reject_paths` | FIN-02.14 (return with per-line comments ⇒ draft; reject ⇒ rejected ⇒ resubmit) |
| `test_incremental_from_prior_baseline_and_prior_year_columns` | FIN-02.05, FIN-02.16 (py_budget, yoy_pct; from_actuals refused, C26) |
| `test_version_diff_reports_added_removed_changed_lines` | FIN-02.02 diff |
| `test_budget_owner_sees_only_their_cost_centres` | §8 row restriction on index/show (403) |
| `test_lifecycle_is_activity_logged` | FIN-07.06 (created/updated/submitted/approved/baselined events, log `it-finance`) |

**`BudgetWorkbookTest`**
| test | proves |
|---|---|
| `test_line_requires_tbm_pool_and_tower_and_defaults_gl_and_capex` | FIN-02.04, D3 |
| `test_every_phasing_method_sums_exactly_to_annual` | FIN-02.04 (equal/front/back/seasonal on awkward totals e.g. 1,000,000.01 over 12 → Σ exact, base too) |
| `test_custom_phasing_must_sum_to_annual` | FIN-02.04 |
| `test_foreign_currency_line_stores_rate_type_and_date` | FIN-01.05, D8 (budget type used; fallback to spot reported) |
| `test_ceiling_breach_flags_scope_and_lines` | FIN-02.03 |
| `test_workbook_csv_round_trip` | FIN-02.13 (export ⇒ import same file ⇒ unchanged = rows, no activity rows; edit 2 amounts + add 1 row ⇒ updated 2, created 1) |
| `test_workbook_import_is_all_or_nothing_with_report` | FIN-02.13 (bad code + bad Σ ⇒ rejected, no writes, row numbers in `import_report`) |
| `test_bulk_grid_save_validates_per_cell` | FIN-02.13 (errors keyed `lines.i.amounts`) |
| `test_scenarios_compare_and_promote` | FIN-02.07 (S) |
| `test_workbook_xlsx_round_trip` | FIN-02.13, only if openspout approved (Q1) else assert 422 |

**`BudgetTransferTest`**
| test | proves |
|---|---|
| `test_transfer_is_net_zero_and_applied_only_after_approval` | FIN-02.10 (apply before approve refused; after apply Σ adjustments base = 0; source current − amount, target + amount; baseline original untouched) |
| `test_transfer_cannot_exceed_availability_or_cross_capex_opex` | FIN-02.10 |
| `test_transfer_to_a_new_line_in_another_cost_centre` | FIN-02.10 (`origin=transfer`, `crosses_cost_centre=yes`) |
| `test_requester_cannot_approve_own_transfer` | FIN-07.02 |
| `test_supplementary_request_lifecycle_creates_resulting_line` | FIN-02.11 |
| `test_supplementary_top_up_of_existing_line` | FIN-02.11 |

**`FinanceWorkflowStudioTest`** (budget part)
| test | proves |
|---|---|
| `test_three_budget_processes_listed_with_defaults_seeded_on_open` | FIN-07.01 (index lists 14 processes; `show` of each finance process seeds §4.1 stages once; second visit no duplicates; a bank that deletes stages is not re-seeded) |
| `test_amount_band_condition_values_come_from_the_banks_doa_bands` | FIN-01.07 (§12 "reflected in Workflow Studio conditions"; another bank's bands differ; stage store rejects a foreign key) |
| `test_budget_runs_through_configured_stages_in_order` | FIN-02.14, FIN-07.01 (Head of IT → CFO → CIO; MD stage only when band matches; `under_review` in between; out-of-order refused) |
| `test_sod_owner_initiator_and_two_stage_rules` | FIN-07.02 |
| `test_transfer_cfo_stage_only_when_crossing_cost_centres` | FIN-07.01 conditions |
| `test_band_snapshot_is_stored_on_the_approval_request` | C8/S7 (context has key + version; new band version later does not change it) |
| `test_default_when_unconfigured` | FIN-07.01 |

**`FinanceDoaFxAndCurrencyGuardTest`**
| test | proves |
|---|---|
| `test_band_change_needs_a_second_person_and_is_audited` | S7/D5 maker-checker; activity event `doa_bands.approved`/`.activated` with old/new |
| `test_future_effective_version_activates_on_its_date_and_remaps_stage_conditions` | effective_from + `fin:doa-activate` (`Carbon::setTestNow`) + ordinal remap |
| `test_approval_refused_when_a_stage_uses_a_removed_band` | C29 |
| `test_convert_returns_rate_type_and_rate_date_with_fallback` | D8 |
| `test_fx_rates_unique_per_type_and_import_accepts_rate_type` | D8 |
| `test_base_currency_cannot_change_once_money_rows_exist` | S8/C13 (allowed before, refused after a budget line exists, incl. soft-deleted) |

**`FinanceNotificationsTest`** (budget part; `Notification::fake()`)
| test | proves |
|---|---|
| `test_stage_open_notifies_eligible_approvers_only` | FIN-07.03 (`FinanceApprovalRequestedNotification` to Head of IT on submit, CFO after stage 1; not the submitter; not another bank) |
| `test_decisions_notify_initiator_and_owner` | FIN-07.03 |
| `test_overdue_sweep_reminds_once_per_day_and_escalates` | FIN-07.03, `fin:approvals-sweep` (run twice ⇒ one send; +25 h ⇒ second; escalation user included) |
| `test_every_finance_approval_notification_renders_mail_and_database` | FIN-07 (mail + `toArray` keys) |
| `test_each_event_has_exactly_one_listener` | C35 guard |

### 5.3 `tests/Feature/RouteSmokeTest.php` (edit `test_finance_pages_render_for_every_demo_tenant`)
- Parameterless finance GETs now include budgets/cycles/targets/transfers/supplementary/create — 200 for Kano, First Bank, Acme (Acme: empty arrays, not errors).
- Per demo bank: every seeded budget `show` (each `?tab=`) and `edit` returns 200; `finance.budgets.workbook?format=csv` 200; `diff` JSON 200 between v1 and v2 of the revised budget.
- Seeded estate asserts: ≥1 `baseline` FY{$y} budget per bank with ≥ 50 lines bank-wide; FY{$y+1} statuses include draft/submitted/under_review/approved/rejected; ≥1 pending `approval_requests` row for each of the 3 finance processes per bank; Kano has a `pending` DoA version; one target breached per bank (`checkCeiling` over); no user holds both `Finance Approver` and `IT Budget Owner`.

### 5.4 Gate
Full suite on `it_security_risk_test` (background), `migrate:fresh --seed` + SMOKE on scratch, `npm run build`, Pint on touched PHP. Confirm `php artisan event:list | grep -A2 FinanceApproval` shows one listener each.

---

## 6. PROPS CONTRACT (backend ⇄ frontend)

Decimals are strings (`decimal:n`); dates `YYYY-MM-DD`; timestamps ISO-8601; ids ints; `baseCurrency` string. Mutations redirect back with `flash.success` (or `flash.warning` for auto-revision) and `errors` per field; business refusals `errors.workflow`; engine refusals `errors.approval`.

**Shared shapes**
```ts
type UserMini = { id: number, name: string } | null
type BudgetSummary = {
  id, budget_code, version_no, version_label, title, status, mode,
  fiscal_year: { id, year_label }, cycle: { id, cycle_code, name, type, status },
  cost_centre: { id, code, name }, legal_entity: { id, code, name } | null, owner: UserMini,
  total_capex: string, total_opex: string, total_base_amount: string,
  amount_band: string, amount_band_label: string,
  submitted_at: string|null, approved_at: string|null, baselined_at: string|null,
  is_locked: boolean, updated_at: string,
}
type Line = {
  id, line_no, source_line_id: number|null, origin: 'planned'|'supplementary'|'transfer', description,
  cost_pool_id, tower_id, category_id: number|null, gl_account_id: number|null, cost_centre_id, legal_entity_id: number|null,
  is_capex: boolean, is_recurring: boolean, priority, rgt,
  vendor_id, contract_id, asset_id, ea_application_id, business_service_id, investment_id, risk_id, driver_id: number|null,
  driver_rate: string|null, quantity: string, unit_cost: string|null,
  currency: string, fx_rate: string, fx_rate_type: string, fx_rate_date: string|null,
  annual_amount: string, base_annual_amount: string, phasing_method,
  amounts: Record<period_id, string>, base_amounts: Record<period_id, string>,
  justification: string|null, variance_amber_pct: string|null, variance_red_pct: string|null,
  py_budget: string|null, py_actual: string|null, yoy_pct: string|null,
  adjustments_base: string, current_base_amount: string, over_ceiling: boolean, sort_order: number,
  labels: { cost_pool: string, tower: string, category: string|null, gl: string|null, vendor: string|null },
}
type PeriodMini = { id, sequence, name, starts_on, ends_on, status }
type Ceiling = { targets: { id, scope_label, cost_centre_id, category_id, legal_entity_id, capex_ceiling: string|null, opex_ceiling: string|null,
                            capex_requested: string, opex_requested: string, capex_over: boolean, opex_over: boolean }[],
                 over: boolean, over_line_ids: number[] }
type Dimensions = {
  costPools: {id,code,name,default_capex,default_gl_account_id}[], towers: {id,code,name}[], subCategories: {id,code,name,parent_id}[],
  glAccounts: {id,code,name,type}[], vendors: {id,name}[], assets: {id,name}[], eaApplications: {id,name}[], businessServices: {id,name}[],
  risks: {id,title}[], drivers: {id,code,name,unit}[], currencies: {code,decimals,is_base}[], legalEntities: {id,code,name}[],
  contracts: [], investments: [],   // empty until P2/P5; hide the selects
  priorities: string[], rgt: string[], phasingMethods: string[], fxRateTypes: string[],
}
```

| page (component) · route | props |
|---|---|
| `Finance/Budgets/Index` · `finance.budgets.index` | `filters {fiscal_year_id,cycle_id,cost_centre_id,status,q,all_versions}`, `budgets: BudgetSummary[]` (latest version per code unless `all_versions`), `summary {count,draft,in_approval,approved,baseline,total_baseline_base,total_in_flight_base}`, `fiscalYears {id,year_label,status,is_current}[]`, `cycles {id,cycle_code,name,type,status,fiscal_year_id}[]`, `costCentres {id,code,name}[]` (visible), `statuses`, `baseCurrency`, `can {create, manageCycles}` |
| `Finance/Budgets/Create` · `finance.budgets.create` | `cycles` (open only), `costCentres {id,code,name,owner_id,legal_entity_id}[]` (visible), `users {id,name,job_title}[]`, `modes`, `priorBaselines {id,budget_code,version_no,title,fiscal_year_label,cost_centre_id,total_base_amount}[]`, `defaults {cycle_id,owner_id}`, `baseCurrency`. **POST** `finance.budgets.store` `{cycle_id, cost_centre_id, legal_entity_id?, title, mode, source_budget_id?, increment_pct?, owner_id?, executive_summary?}` ⇒ redirect `finance.budgets.edit` |
| `Finance/Budgets/Show` · `finance.budgets.show` | `tab`, `budget: BudgetSummary & {executive_summary, assumptions, risks_summary, rejection_reason, increment_pct, previous_version_id, superseded_by {id,budget_code,version_no}|null, source_budget {id,budget_code,version_no}|null, cycle {…, opens_on, closes_on}}`, `lines: Line[]`, `periods: PeriodMini[]`, `totals {capex, opex, base, py_budget, yoy_pct, adjustments_base, current_base, by_period: Record<period_id,string>, by_priority: Record<string,string>, by_rgt: Record<string,string>}`, `ceiling: Ceiling`, `scenarios {id,name,adjustment_pct,line_adjustments,is_promoted,promoted_at,total_base}[]`, `versions {id,version_no,status,total_base_amount,submitted_at,approved_at,baselined_at,is_current}[]`, `approval {process:'finance.budget_approval', configured:boolean, context: object|null}`, `comments {id,budget_line_id,user:UserMini,kind,body,created_at}[]`, `activity {id,event,description,causer:UserMini,changes:object|null,created_at}[]` (latest 50, budget + its lines), `availableDimensions: Dimensions`, `can {edit,submit,withdraw,approve,return,reject,baseline,revise,comment,import,export}`, `baseCurrency` |
| `Finance/Budgets/Workbook` · `finance.budgets.edit` | `budget: BudgetSummary`, `lines: Line[]`, `periods: PeriodMini[]`, `totals` (as Show), `ceiling: Ceiling`, `availableDimensions: Dimensions`, `lockedReason: string|null`, `can {edit, import, export, revise}`, `baseCurrency`. **PUT** `finance.budgets.lines.bulk` `{lines: (Partial<Line> & {id?: number|null})[], deleted_ids: number[]}`; **POST** `finance.budgets.workbook.import` multipart `file` ⇒ `flash.import_report {type:'budget_workbook', file_name,row_count,accepted_count,rejected_count,created,updated,unchanged,errors:{row,message}[]}` |
| JSON · `finance.budgets.diff?against=` | `BudgetService::diff` shape (§2.5) |
| `Finance/Budgets/Cycles` · `finance.cycles.index` | `cycles {id,cycle_code,name,type,status,fiscal_year:{id,year_label},opens_on,closes_on,instructions,coordinator:UserMini,budgets_count,in_approval_count,approved_count,baseline_count,requested_base,ceiling_total}[]`, `fiscalYears`, `users`, `types`, `statuses`, `can {manage}`. **POST/PUT** `{fiscal_year_id,name,type,opens_on,closes_on,instructions?,coordinator_id?}`; **transition** `{action}` |
| `Finance/Budgets/Targets` · `finance.targets.index` | `cycle {id,cycle_code,name}|null`, `cycles`, `targets {id,scope_label,cost_centre:{id,code,name}|null,category:{id,code,name,kind}|null,legal_entity:{id,code,name}|null,capex_ceiling,opex_ceiling,capex_requested,opex_requested,capex_pct,opex_pct,over:boolean,notes,set_by:UserMini,updated_at}[]`, `costCentres`, `categories` (pools + towers), `legalEntities`, `can {manage}`. **POST/PUT** `{cycle_id,cost_centre_id?,category_id?,legal_entity_id?,capex_ceiling?,opex_ceiling?,notes?}` |
| `Finance/Budgets/Transfers` · `finance.transfers.index` | `filters {fiscal_year_id,status,highlight}`, `transfers {id,transfer_code,status,fiscal_year:{id,year_label},from_line:{id,line_no,description,budget:{id,budget_code,version_no},cost_centre:{id,code,name}},to_line:(same)|null,to_cost_centre,to_cost_pool,to_tower,to_description,from_period:{id,name}|null,to_period:{id,name}|null,amount,base_amount,currency,reason,amount_band,crosses_cost_centre:'yes'|'no',capex_opex,requested_by:UserMini,approved_by:UserMini,approved_at,applied_at,rejection_reason,can:{edit,submit,withdraw,approve,reject,apply}}[]`, `summary {draft,pending,approved_unapplied,applied_count,applied_base}`, `lineOptions {id,label,cost_centre_id,is_capex,current_base_amount,available_by_period:Record<period_id,string>}[]` (baseline lines of the selected FY, visible ccs), `periods: PeriodMini[]` (open, selected FY), `costCentres`, `costPools`, `towers`, `fiscalYears`, `baseCurrency`, `can {create}`. **POST/PUT** `{fiscal_year_id, from_line_id, to_line_id?, to_cost_centre_id?, to_cost_pool_id?, to_tower_id?, to_category_id?, to_description?, from_period_id?, to_period_id?, amount, reason}`; decisions `{note?}` (reject note required) |
| `Finance/Budgets/Supplementary` · `finance.supplementary.index` | `filters`, `requests {id,request_code,title,status,fiscal_year,cost_centre,cost_pool,tower,category,target_line:{id,line_no,description}|null,resulting_line:{id,line_no,description,budget_id}|null,is_capex,amount,currency,fx_rate,base_amount,amount_band,justification,requested_by,approved_by,approved_at,applied_at,rejection_reason,can:{…}}[]`, `summary`, `lineOptions` (as transfers), `costCentres`, `costPools`, `towers`, `subCategories`, `currencies`, `fiscalYears`, `baseCurrency`, `can {create}`. **POST/PUT** `{fiscal_year_id, cost_centre_id, target_line_id?, cost_pool_id?, tower_id?, category_id, title, is_capex, currency, amount, justification}` |
| `Finance/Settings` (edit) · `finance.settings` | P0 props + `doaVersions {id,version_no,status,effective_from,bands,reason,proposed_by:UserMini,proposed_at,decided_by:UserMini,decided_at,decision_note,activated_at}[]` (desc), `fxRates[].rate_type`, `latestRates[].rate_type`, `options.fxRateTypes`, `can.approveBands`, `can.changeBase`, `baseLockedReason: string|null`. **POST** `finance.doa-bands.store` `{bands:{label,min,max}[], effective_from, reason?}`; approve/reject `{note?}` (reject note required) |

---

## 7. File ownership (no overlaps)

| slice | files |
|---|---|
| **BACKEND** | `config/finance.php`; migrations `2027_02_01_000001…000004`; models `app/Models/Finance/{BudgetCycle,BudgetTarget,Budget,BudgetLine,BudgetLinePeriod,BudgetScenario,BudgetComment,BudgetAdjustment,BudgetTransfer,SupplementaryRequest,Allocation,AllocationResult,DoaBandVersion,NotificationLog}.php` (new), `FxRate.php`, `FiscalYear.php` (edit); `app/Models/Approvals/ApprovalRequest.php` (cast only); `app/Policies/Finance/FinancePolicy.php`; services `app/Services/Finance/{DoaBandService,FinanceApprovals,BudgetPhasing,BudgetLedger,BudgetService,BudgetWorkbook,BudgetCycleService,BudgetTransferService,SupplementaryBudgetService}.php` (new), `{FinanceSettings,CurrencyService,FinanceCsvImporter,FiscalCalendarService}.php` (edit); `app/Events/Finance/{FinanceApprovalRequested,FinanceApprovalDecided}.php`; controllers `app/Http/Controllers/Finance/{BudgetController,BudgetLineController,BudgetScenarioController,BudgetWorkbookController,BudgetCycleController,BudgetTargetController,BudgetTransferController,SupplementaryRequestController,DoaBandController}.php` (new), `{FinanceSettingsController,FinanceCurrencyController}.php` (edit); `app/Services/Approvals/ApprovalProcesses.php`; `routes/web.php`; tests `tests/Feature/Finance/{FinanceTenancyAndRoutesTest,FinanceConfigurationTest}.php`, `tests/Feature/Workflows/WorkflowStudioModuleTest.php` (edits per §2.9); `composer.json`/`composer.lock` only if Q1 approved |
| **FRONTEND** | `resources/js/Config/navigation.js`; `resources/js/Utils/finance.js`; `resources/js/Pages/Finance/Budgets/{Index,Create,Show,Workbook,Cycles,Targets,Transfers,Supplementary}.jsx`; `resources/js/Components/Finance/Budgets/*.jsx` (§3.4); `resources/js/Components/Finance/Settings/{DoaBandsTab,CurrenciesTab}.jsx`; `resources/js/Pages/Finance/Settings.jsx` |
| **WORKFLOW** | `app/Services/Finance/{FinancePolicySeeder,FinanceApprovalNotifier,FinanceApprovalSweeper}.php`; `app/Notifications/Finance/{FinanceApprovalRequestedNotification,FinanceApprovalDecisionNotification,FinanceApprovalOverdueNotification}.php`; `app/Listeners/Finance/SendFinanceApprovalNotifications.php`; `app/Http/Controllers/Workflows/WorkflowStudioController.php`; `routes/console.php` |
| **QA** | `database/seeders/FinanceDemoSeeder.php` (new), `database/seeders/DatabaseSeeder.php`; `tests/Feature/Finance/{BudgetLifecycleTest,BudgetWorkbookTest,BudgetTransferTest,FinanceWorkflowStudioTest,FinanceDoaFxAndCurrencyGuardTest,FinanceNotificationsTest}.php` (new); `tests/Feature/RouteSmokeTest.php` |

Not touched by anyone: `AppServiceProvider.php` (policies auto-mapped, listeners auto-discovered), `ApprovalEngine.php`, `Workflows/Process.jsx`, `FinanceFoundationSeeder.php`, `RolesAndPermissionsSeeder.php` (no new permissions).

---

## 8. §12 FIN-02 (and P1-relevant M) acceptance → test

| §12 / feature | test |
|---|---|
| FIN-02.01 cycles (M) | `BudgetLifecycleTest::test_cycles_have_types_windows_and_guarded_transitions` |
| FIN-02.02 draft → submitted → approved → baseline | `BudgetLifecycleTest::test_draft_submitted_approved_baseline_happy_path_unconfigured`, `FinanceWorkflowStudioTest::test_budget_runs_through_configured_stages_in_order` |
| FIN-02.02 immutable baseline, edit creates v2 | `BudgetLifecycleTest::test_baseline_is_immutable_and_an_edit_creates_version_two` |
| FIN-02.02 one baseline per FY per cc; diff | `…::test_only_one_baseline_per_fiscal_year_and_cost_centre`, `…::test_version_diff_reports_added_removed_changed_lines` |
| FIN-02.03 ceiling breach flagged | `BudgetWorkbookTest::test_ceiling_breach_flags_scope_and_lines` |
| FIN-02.04 lines; phasing sums to annual | `BudgetWorkbookTest::test_line_requires_tbm_pool_and_tower…`, `…::test_every_phasing_method_sums_exactly_to_annual`, `…::test_custom_phasing_must_sum_to_annual` |
| FIN-02.05 zero-based & incremental | `BudgetLifecycleTest::test_incremental_from_prior_baseline_and_prior_year_columns` (from-actuals ⇒ P3, C26) |
| FIN-02.10 transfer net-zero, applied only after approval | `BudgetTransferTest::test_transfer_is_net_zero_and_applied_only_after_approval` (+ availability, new-line, SoD tests) |
| FIN-02.11 supplementary | `BudgetTransferTest::test_supplementary_request_lifecycle_creates_resulting_line`, `…::test_supplementary_top_up_of_existing_line` |
| FIN-02.12 allocation | **Deferred to P6** (tables only in P1, C27) |
| FIN-02.13 workbook round-trip | `BudgetWorkbookTest::test_workbook_csv_round_trip`, `…::test_workbook_import_is_all_or_nothing_with_report`, `…::test_bulk_grid_save_validates_per_cell` (+ xlsx if Q1) |
| FIN-02.14 submission & approval in Workflow Studio | `FinanceWorkflowStudioTest::*`, `BudgetLifecycleTest::test_withdraw_return_and_reject_paths` |
| FIN-02.16 PY columns from prior baseline | `BudgetLifecycleTest::test_incremental_from_prior_baseline_and_prior_year_columns` (PY actual ⇒ P3) |
| FIN-01.07 DoA reflected in WS conditions | `FinanceWorkflowStudioTest::test_amount_band_condition_values_come_from_the_banks_doa_bands` |
| FIN-07.01 (3 of 8 processes), FIN-07.02 SoD | `FinanceWorkflowStudioTest` |
| FIN-07.03 approval notifications | `FinanceNotificationsTest` |
| FIN-07.06 audit | `BudgetLifecycleTest::test_lifecycle_is_activity_logged`, `FinanceDoaFxAndCurrencyGuardTest::test_band_change_needs_a_second_person_and_is_audited` |
| Deferred P0 items C8/S7/D5, D3, D8, S8/C13, D16 | `FinanceDoaFxAndCurrencyGuardTest::*`, `BudgetWorkbookTest::test_line_requires_tbm_pool_and_tower…`, `…::test_foreign_currency_line_stores_rate_type_and_date`, `RouteSmokeTest` (per-bank bands) |
| Tenancy | `FinanceTenancyAndRoutesTest` (404 cross-bank, exact route list), `RouteSmokeTest` |

---

## 9. Spec/codebase conflicts (numbering continues P0's C1–C21)

| # | conflict | recommendation |
|---|---|---|
| C22 | Baselines are immutable (§4.2, FIN-07.06) but virements and supplementary budgets must change "the budget" during the year; `resulting_line_id` implies adding a line to a locked budget. | Original vs current budget: baseline amounts never change; approved transfers/supplementary post signed rows to a new `fin_budget_adjustments` ledger; current = original + adjustments (P3 availability reads current). The one permitted write to a baseline is appending a zero-original `origin=supplementary|transfer` line through `BudgetLine::$allowLockedAppend`. |
| C23 | §5.1 `withdraw` has no target status; `submit` is only from draft/rejected, so `withdrawn` would be a dead end for budgets. | Budget withdraw ⇒ `draft`. `withdrawn` is used by transfers/supplementary only. |
| C24 | §9 `finance.budgets.workbook` names both GET export and POST import (route names are unique); §9 `budgets.edit` has no page while §10 lists `Workbook.jsx`. | `finance.budgets.edit` renders `Budgets/Workbook`; `finance.budgets.workbook` = GET export; `finance.budgets.workbook.import` = POST. |
| C25 | FIN-02.13 requires XLSX; the repo has no spreadsheet library (no phpoffice/openspout). | **Q1:** add `openspout/openspout:^4` (MIT, needs ext-zip/xmlreader, both present); P4 reports need it too. Without approval P1 ships CSV only and XLSX moves to P4. |
| C26 | FIN-02.05 "from prior-year actuals" and FIN-02.16 "PY actual" need `fin_actuals` (P3). | Mode kept in the enum but refused with a clear message; `py_actual` column ships null; the two assertions move to `ActualsImportAndReconciliationTest` in P3. |
| C27 | FIN-02.12 allocation is M, but the kick-off puts execution in P6. | Tables + models only; no routes, no `Allocations.jsx` in P1. |
| C28 | `ApprovalProcesses::PROCESSES` is a `const`, so condition values cannot be per bank. | String markers (`'finance.doa_bands'`, `'finance.cost_centres'`, `'finance.categories'`) + `ApprovalProcesses::conditions($process, $orgId)`; Workflow Studio `show`/`validatedStage` use it. |
| C29 | Band keys are derived from thresholds, so an edit orphans stage conditions (P0 C8). | Versioned bands; at activation stage `amount_band` values are re-mapped by ordinal and recorded in the activation audit event; approving a version that drops a referenced band is refused. |
| C30 | The engine compares `(string) $subject->{field}`; booleans become `'1'`/`''` and `''` cannot be stored as a condition value. §6 names `is_capex`. | String accessors: `crosses_cost_centre` (`yes`/`no`), `capex_opex` (`capex`/`opex`/`mixed`) instead of `is_capex`. |
| C31 | "Band-key snapshot on each approval request": `approval_requests` has no field for it. | Add nullable `context` json to `approval_requests` (generic, harmless to other modules), written by `FinanceApprovals::start()`. Engine untouched. |
| C32 | §6 default stages need an MD/CEO, and §6 gives no supplementary stages. | Seed `md@` users (job title "Managing Director/CEO"). Supplementary defaults: Head of IT → CFO (+ CIO from 50m). |
| C33 | Budget stage 1 is "Head of IT", but the Head of IT owns several cost centres; `owner_cannot_approve` means nobody can decide that stage for their own budgets. | **Q2.** P1: the demo avoids it (pending budgets use other cost centres). Options for later: a delegate approver per cost centre (D15), or an engine rule "skip a stage whose only eligible person is excluded by SoD, and record why". |
| C34 | §6 says `FinancePolicySeeder::ensureDefaults` but not when. | Same as Change: called from Workflow Studio `show` for `finance.*` and from the demo seeder; per process, only if the bank has no workflow row. Banks that never open Workflow Studio use the documented default. |
| C35 | Listener auto-discovery is on, and `AppServiceProvider::registerEaContracts()` also `Event::listen`s, so every EA/Core contract listener runs twice today (`php artisan event:list`). | Finance relies on discovery only (no provider edit) and tests one listener per event. Log the EA/Core duplicate in ATH-FIN-003 as a pre-existing defect for a separate fix; out of P1 scope. |
| C36 | `total_capex`/`total_opex`/`total_base_amount` currency is unstated. | All three in base currency. |
| C37 | "Only one baseline per FY per cost centre" cannot be a portable partial unique index. | `baseline_slot` nullable column (`"{fy}:{cc}"` only while baseline) + unique(org, baseline_slot); NULLs don't collide on Postgres/SQLite/MySQL. |
| C38 | FIN-02.14 "return with comments per line": §4.2 has no comment table. | New `fin_budget_comments`. |
| C39 | §10 lists one nav item "Budgets" but five Budget pages. | Sidebar "Budgets" only; `BudgetsNav` tabs across the five pages. |
| C40 | Workbook import permission: `import it-finance` is not held by IT Budget Owners, who own their workbooks (S10). | Workbook import needs `edit it-finance` + row visibility; `import it-finance` stays for bulk master data. |
| C41 | `contract_id`, `investment_id`, `ea_application_id`, `business_service_id` point at tables that don't exist yet (P2/P5) or are `BelongsToTenant` EA tables. Adding FKs later means SQLite table rebuilds. | Indexed columns without FKs; validated in the service. FKs only to `vendors`, `assets`, `risks`, `legal_entities`, finance tables. |
| C42 | Line `cost_centre_id` "inherits" (§4.2) but is a free column. | Forced to the budget's cost centre so row restriction and ceilings stay consistent. |
| C43 | Transfer currency unstated; lines may be in USD. | Transfers are in base currency; ledger rows store base and line-currency amounts. |
| C44 | Code prefixes "per bank" (`KHB-BUD-0001`) but `GeneratesSequentialCode` takes a single default. | Default `BUD-`/`BC-`/`VIR-`/`SUP-`; demo seeder starts each bank's series with its prefix and the trait continues it. |
| C45 | §8 has no ability split between config and planning records; P0's `create`/`update` require `manage it-finance-settings`. | Separate policy abilities (`createPlan`, `updatePlan`, `decidePlan`, `baselinePlan`, `managePlanning`, `proposeBands`, `checkBands`). |
| C46 | DoA maker-checker: who is the checker? | Anyone in the bank with `approve it-finance` or `manage it-finance-settings` other than the maker. |
| C47 | `fin_fx_rates` unique key lacks rate type. | Swap to (org, from, to, rate_date, rate_type); `spot` default keeps P0 rows valid. |
| C48 | Notification `module`: spec says "IT Financial Management", Change uses `changes`. | Follow the spec. |

## 10. Risks and open questions

- **Q1** Approve `openspout/openspout` (C25)? Default without approval: CSV only.
- **Q2** Head-of-IT self-ownership deadlock (C33): accept the demo workaround for P1?
- **Q3** CapEx↔OpEx virements: refused by default (`finance.transfers.allow_capex_opex=false`). Confirm with the domain reviewer.
- **R1** Amount-band remap by ordinal assumes banks reorder rather than re-meaning bands; the activation audit event lists every remap for review.
- **R2** The Kano/First Bank FY numbering is `now()->year`-relative (as P0). Seeder date logic must not hardcode 2026/2027.
- **R3** The demo seeder runs the engine under real stages for FY{$y+1}; queued notifications land in `jobs` (QUEUE_CONNECTION=database, MAIL_MAILER=log). Acceptable; history is event-faked.
- **R4** Grid paste/keyboard UX is custom (no grid library); keep it to inputs + TSV paste, no virtualisation (≤ ~150 lines per budget).

**Deferred out of P1:** FIN-02.08 contingency/reserves (S), FIN-02.09 carry-forward (S), FIN-02.15 attachments via Evidence (S; narrative fields ship), FIN-02.12 allocation execution + page (P6), from-actuals mode + PY actual (P3), amount masking (P7), Playwright `finance-budget.spec.js` (P7).
