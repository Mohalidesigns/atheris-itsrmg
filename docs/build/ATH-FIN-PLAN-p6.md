# ATH-FIN-PLAN-p6: IT Financial Management, Phase P6 (Cloud cost / FinOps, allocation and showback, capitalisation, Application TCO)

**Spec:** ATH-FIN-001 §3.3 FIN-03.09, .10, .12, .13 (FIN-03.11 is C-priority, out of scope, C166); §3.2 FIN-02.12 (allocation execution, M; tables from P1 C27); §3.5 FIN-05.10 Application TCO (deferred from P5, C142); §4.2 allocations and results; §4.3 cloud accounts, cost lines, anomalies, capitalisations, amortisation lines; §5.3 `CloudCostService`; §7 `CloudAnomalyNotification`, `fin:cloud-anomalies` (05:45); §5.7 `cloud_mtd`, `cloud_anomalies_open`; FIN-04.07 cloud trend on the dashboard; FIN-04.08 cloud anomaly KRI; FIN-07.07 cloud spend and chargeback statement reports; §9 `finance.cloud.*`, `finance.allocations.*`, `finance.monitor.showback`, `finance.capitalisations.*`; §10 `Cloud/{Accounts,Costs,Anomalies}`, `Monitor/Showback`, `Budgets/Allocations`, `Capitalisations/Index`; §12 FIN-03 cloud items and the FIN-07 items P6 touches.
**Branch:** `feature/it-finance-p6`, from P5 7da2d7c.
**P5 deferrals adopted (§11.1):** the open gap (no path to reassign sponsor, manager, cost centre, rgt or regulatory flag after approval), domain **L2** and **L6**, security **M1**, **M2** and **M5**. Everything else stays deferred.

**Order and parallelism:**
1. **Task A (backend core)** and **Task C (frontend)** start together. Task C builds against §7 (props) and §2.6 (route names) only.
2. **Task B (workflow, integration and P5 carry-overs)** starts at the same time on **slice B1** (P5 carry-overs: investment files that Task A never touches). It starts **slice B2** (integrations on top of A's services) only when Task A reports green.
3. **Task E (QA)** starts after A, B and C.
The file sets in §8 do not overlap.

**DB rule (overrides every command; never `it_security_risk`):**
- Task A: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_fin_test php vendor/bin/phpunit --filter '<Classes>'`.
- Task B: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_fin_wf_test php vendor/bin/phpunit --filter '<Classes>'`. Create it once with `/opt/homebrew/opt/postgresql@17/bin/createdb it_security_risk_fin_wf_test`. Slice B1 runs while A is still writing migrations. If a `RefreshDatabase` run fails **inside an A migration**, wait and re-run. Never edit an A file.
- Task E: `it_security_risk_fin_qa`. Reviewers: throwaway `it_security_risk_<agent>_test`.
- Orchestrator only: suite on `it_security_risk_test`; seed and SMOKE on `it_security_risk_scratch`.
- Task C: `npm run build` only. Pint only on PHP files you touched.

**Route collision check (done, read-only, on scratch):** `route:list --except-vendor --json` returns 930 routes, 243 of them `finance.*`. No name or URI contains cloud, showback, chargeback, capitalis, amortis, allocation or tco. The only `anomal` routes are `ea.anomalies*` and `api.ea.anomalies.run`; the new `finance.cloud.anomalies.*` names and the `/it-finance/cloud/anomalies` URI do not collide with them. **No collisions.**

**State found in code and on the seeded scratch DB (verified):**
- `fin_allocations` and `fin_allocation_results` exist (P1 migration `2027_02_01_000002`), but **no code reads or writes them**. Only the two models reference them. Their constants disagree with the spec: sources are `cost_centre, category, budget_line, asset` (spec: `budget_line, cost_centre, category, contract, cloud_account`), targets are `cost_centre, business_service, legal_entity, application`, and methods are `fixed_pct, driver, equal` (spec: `direct, percentage, driver`). The tables are rebuilt (C149).
- `Actual::SOURCES` already contains `cloud_import`, and `ImportBatch::TYPES` contains `cloud`. `PeriodCloseService::checklist()` line 110 has a stub item `cloud_imported` set to `n_a` ("arrives with a later phase"); `FinanceMonitoringDemoSeeder` line 453 mirrors it. `FinanceMetrics::summary()` returns `cloud_mtd => null, cloud_anomalies_open => null`.
- `fin_settings.showback_enabled` exists (section `general`). It has no anomaly threshold columns, no business-unit list and no chargeback GL codes.
- Budget lines carry `cost_pool_id`, `tower_id` (both required), `ea_application_id`, `business_service_id`, `asset_id`, `investment_id`, `legal_entity_id`. Actuals carry `ea_application_id` and `investment_id`, but **an actual is immutable except for `Actual::MATCH_FIELDS`**, so actual tags cannot be added afterwards. Attribution must come through the matched budget line.
- Scratch DB:
  - **0** budget lines carry `ea_application_id` or `business_service_id`. **0** actuals carry `ea_application_id`. 43 `fin_contract_links` point at EA applications (org 3 only).
  - **EA applications exist only in org 3 (53). Org 2 has none.** Business services: 16 per bank. Legal entities: org 3 has 6, org 2 has 0.
  - Cost centres: KHB 10 and FBN 12, all with `business_unit` ∈ {Technology, Information Security}, which are IT units, not consumers. Drivers per bank: `BRANCHES, HEADCOUNT, M365_SEATS, STORAGE_TB, TXN_VOL`.
- Scratch DB: capex actuals attributed to investments exist only for **KHB-IVT-0001 (in_delivery), KHB-IVT-0005 (approved), FBN-IVT-0001 (in_delivery), FBN-IVT-0009 (closed)**. The delivered and benefits_tracking investments have **0** capex actuals (R3).
- `Asset` (Asset Management) has no cost, useful-life or depreciation columns. Codes come from `Asset::generateNextCode($orgId)` (`{BANK}-AST-NNNN`). `AssetManagementDemoSeeder` runs **after** `FinanceInvestmentDemoSeeder` and before the second `FinanceContractDemoSeeder`.
- EA: `App\Services\Ea\CostModelService` already models TCO per application (`tco()`, `rationalisationCandidates()`, `tcoForApplications()`) from `annual_cost`/`tco_annual_ngn`, with configurable uplift factors and a coverage figure. It is used by `Ea\DepthController`, GraphQL, MCP and `PlateauDiffService`. It is tested in `tests/Feature/Ea/EaDepthAndScaleTest.php` and `EaPerformanceBenchmarkTest.php`. `EaApplication` (`ea_applications_ext`: `code`, `name`, `criticality`, `lifecycle`, `asset_id`) and `BusinessService` (`ea_business_services`: `code`, `name`) use `BelongsToTenant` (org or NULL).
- Processes: 20 total, 9 finance (`WorkflowStudioModuleTest:93`, `FinanceWorkflowStudioTest:67,197`, `RouteSmokeTest:271`). Reports: 10 (`FinanceReports::DEFINITIONS`; `RouteSmokeTest` reports count). KRIs: `KriMeasures::MEASURES` holds `KRI-FIN-VAR/UNB/INV/NOT`, and the definitions are seeded in `FinanceMonitoringDemoSeeder::krisAndBoardPack()`.
- `FinancePolicy::importActuals` = `import it-finance` + `view it-finance-amounts`; `managePlanning` = `manage it-finance-settings`. Procurement Officer and IT Finance Controller hold `import it-finance`.
- Code prefixes in use: SUP, BC, PO, TRM, EXP, ALC, INV, VIR, REN, BUD, REC, GRN, CON, IVT, BEN, ICR. **P6 adds `CAP-` (capitalisations) and `CBK-` (chargeback runs)** (C171). Allocations keep `ALC-`.

---

## 0. Decisions (P6-1 … P6-22). Orchestrator records them in ATH-FIN-003; C-items with reasons in §10.

| # | decision | sign-off? |
|---|---|---|
| P6-1 | **Cloud cost is a FinOps sub-ledger, never posted to `fin_actuals` (C145).** The book of record stays invoices and GL. AWS/Azure invoices already post actuals (P3 recurring accrual + invoices), so posting cloud lines too would double count. `Actual` source `cloud_import` stays reserved and unused. The Costs page shows a **ledger reconciliation**: FinOps total vs ledger cloud actuals (cost pool `CP-CLD`, GL `6302`, or contract type `cloud_commitment`) for the period, with the gap. | **yes** |
| P6-2 | **Import grain and restatement (C146).** Provider rows are aggregated to **daily** lines keyed `(usage_date, service, resource_id, region, normalised tags)`; `line_hash` = sha1 of that key. A file with an already-imported sha256 for the account is refused. Restatement: for each usage date in the file, existing lines of the account on that date are **replaced** when the date's period is `open`. When the period is `closed`/`locked`, rows are **appended only if the account has no line on that date**; otherwise they are rejected with "period closed, already imported". Rows whose provider account id ≠ `account_identifier` are rejected. | |
| P6-3 | **Tag attribution (C147).** `fin_cloud_accounts.tag_mapping` = `{tag_key: dimension}`, where dimension ∈ `cost_centre`, `ea_application`, `business_service`. A value resolves by: (1) an account-specific tag rule; (2) an org-wide tag rule (`cloud_account_id` null); (3) built-in matching on case-insensitive equality with a cost-centre `code`, an EA application `code`/`name`, or a business-service `code`/`name` (org or NULL rows for EA). `cost_centre_id` = resolved value, else the account's `default_cost_centre_id`. **`is_untagged` = no mapped dimension resolved.** Re-attribution (`reallocate`, untagged assign) only touches lines in **open** periods. | |
| P6-4 | **Anomaly detection (C148, C172).** Scopes: `account` (account total), `service` (per account) and `application` (per account, `ea_application_id`). For each day *d* in `[asOf − lookback_days, asOf − 1]` (lookback 3), *d* is anomalous when the observed cost exceeds the threshold on either test, and the absolute floor is also met:<br>• **Rolling:** against the mean of *d*−7…*d*−1 (needs ≥ 5 days with data).<br>• **Week-over-week:** against the same weekday a week earlier.<br>Thresholds: `fin_settings.cloud_anomaly_pct` (default 30) **and** observed − baseline ≥ `cloud_anomaly_abs_floor` (base, default 250,000). Detection runs on **billing-currency `cost`**, so an FX move is not an anomaly; baseline and observed are stored in both currencies. Unique `(organization_id, cloud_account_id, detected_on, scope, scope_ref)`; a re-run updates figures on an `open` row and never re-opens a closed one. `deviation_pct` and `method` (`rolling_7d`/`week_over_week`, whichever is larger) are stored. | |
| P6-5 | **Allocation model rebuilt (C149).** The P1 tables are empty and unused; a guarded migration drops and recreates them in the spec's shape. Values:<br>• Sources: `cost_centre` (includes descendants), `category` (matches line `cost_pool_id`/`tower_id`/`category_id` incl. descendants), `budget_line` (lineage), `contract`.<br>• Targets: `business_unit` (a code from the bank's showback units, P6-9), `cost_centre`, `business_service`, `ea_application`, `investment`, `legal_entity`.<br>• Methods: `direct` (100 %, a single rule), `percentage`, `driver` (share = rule `driver_quantity` / Σ quantities of the group's rules).<br>One method per group `(source_type, source_id, target_type)`; percentages of a group sum to ≤ 100 across overlapping effective windows (validated on store/update). | **yes** |
| P6-6 | **Allocation pools (C150).** Per `(target_type, period, amount_type)`:<br>• Each ledger item (budget-line period amount, or counted actual) belongs to **at most one source**, the most specific matching rule source. Precedence: `budget_line` > `contract` > `category` > `cost_centre`.<br>• **Direct attribution wins:** items already carrying the target dimension are excluded from that lens's pools (`ea_application_id` for `ea_application`, `business_service_id` for `business_service`, `investment_id` (line or actual) for `investment`, `legal_entity_id` for `legal_entity`).<br>• Results are split by **cost pool** (`cost_pool_id` of the line; for unmatched actuals the root cost pool of `category_id`, else null "unclassified").<br>• Rounding is largest-remainder at 2 dp, so Σ results of a group = round(pool × Σ share) ≤ pool, exactly. | |
| P6-7 | **Amount types `budget` and `actual` only (C151).** `forecast` is deferred: P4 forecasts are line/FY EACs, not period amounts. Budget = current baseline period amounts (`MonitoringService::baselineLines` + `lineFigures`, the BvA definition). Actual = counted actuals (`match_status ≠ duplicate`), including unmatched/unbudgeted rows matched to sources by their own `cost_centre_id`/`category_id`/`contract_id`, so the IT total ties to the BvA ledger total incl. `actual_unallocated`. | |
| P6-8 | **Results freeze with the period (C152).**<br>• `AllocationService::run()` recomputes `open` periods (delete + insert, one `fin_allocation_runs` row per period × amount_type).<br>• For `closed`/`locked` periods it **only computes when no results exist yet**; otherwise it skips them as frozen.<br>• `PeriodCloseService::close()` calls `AllocationService::freeze($period)` just before closing (Task B), so the closed statement is the one that was computed.<br>• Rules are effective-dated. A create/update/end may not set `effective_from`/`effective_to` earlier than the first day of the earliest **open** period of the bank. | |
| P6-9 | **Showback lens and reconciliation (C153).** A statement is per `target_type` over a period range and basis (budget/actual):<br>• Per target: `direct` (tag-type targets and `legal_entity` only), `allocated` (Σ results) and `total`, by cost pool.<br>• `retained` = IT total − Σ direct − Σ allocated (unallocated IT cost, never negative).<br>• The statement **reconciles to the cent**: Σ total + retained = IT total.<br>• Business units are a per-bank list `fin_settings.showback_units` = `[{code, name, erp_cost_centre_code}]` (Settings tab "FinOps & showback"); IT cost centres' `business_unit` values are IT units, not consumers.<br>• `showback_enabled = false` shows a banner and refuses chargeback export; rules and statements still work. | |
| P6-10 | **Chargeback journal (C154).**<br>• Only for a **closed or locked** period, one run per `(period, target_type)` at a time. A new export is refused while an `exported` run exists, until it is **voided** with a reason (≥ 10 chars, `close it-finance-periods`).<br>• Lines: one debit per (target, cost pool), with GL `chargeback_debit_gl` and the receiving ERP cost centre (`showback_units.erp_cost_centre_code`, or the target cost centre's `code`, or the rule's `journal_cost_centre_code`); one credit per (source cost centre, cost pool), with GL `chargeback_credit_gl` and the IT cost-centre code. The journal is balanced (Σ Dr = Σ Cr) and in base currency.<br>• Code `CBK-`. The CSV is stored (sha256) on the run and re-downloadable. | |
| P6-11 | **Capitalisation approval through Workflow Studio: a 10th finance process `finance.capitalisation_approval` (C155).** The spec §6 lists none, but decision #2 (Workflow Studio only) rules out a module-private maker-checker.<br>• Stages: 10 IT Finance Controller (role), then 20 CFO (job title) when `requires_cfo` (base amount ≥ `approval_defaults.capitalisation_cfo_from`, 50m).<br>• SoD snapshot `sod_excluded_ids` = requester, creator, the investment manager (investment source) or the expense requester (expense source).<br>• Processes become **21 / 10**. | **yes** |
| P6-12 | **Capitalisation basis (C156).**<br>• Source = an investment (`is_capitalisable`, stage `in_delivery`/`delivered`/`benefits_tracking`/`closed`) or a capex expense request (`is_capex`, status `received`/`invoiced`/`closed`).<br>• **Eligible pool** = Σ counted **capex** actuals (base) attributed to the source with `posted_on ≤ available_for_use_on` (IAS 38.30: later costs are not capitalised). Attribution follows `InvestmentFinancials::lineIds` + direct `investment_id`; for an expense, actuals of its invoices. The pool is reduced by `submitted` + `capitalised` capitalisations of the same source.<br>• `amount` ≤ remaining pool; `excluded_amount` + reason records research/training costs left out (IAS 38.54/.67).<br>• Checklist by `standard`:<br>&nbsp;&nbsp;– `ias_38`: the six IAS 38.57 criteria + "research-phase and training costs excluded";<br>&nbsp;&nbsp;– `ias_16`: probable future benefits, cost reliably measurable, expected use > 1 year.<br>&nbsp;&nbsp;Every item is ticked with a note of ≥ 10 chars.<br>• Booking date `capitalised_on` = the final approval date and **must fall in an open period** (else the approval is refused). Amortisation starts in the month of `available_for_use_on` (≤ today). Months before the booking month are **catch-up** lines, booked in the booking period. | **yes** |
| P6-13 | **Amortisation is a memo schedule (C157).** It is not posted to `fin_actuals`: depreciation is not IT cash spend, and the capex is already in actuals. Straight-line, or declining balance at `declining_rate_pct` (default 2 / life years × 100) switching to straight-line when that is higher; residual value optional (default 0); full-month convention; the last line absorbs rounding so Σ = amount − residual exactly. Lines store `month` and a nullable `period_id` (resolved where the period exists; future-FY lines resolve at read by date). Exported through the `capitalisation_register` report (schedule by FY). | |
| P6-14 | **Asset link (C158).** On final approval: if `asset_id` is set, link that asset (same org). Else, when `create_asset`, create an `Asset` with `asset_id_code` `Asset::generateNextCode`, `name`, `asset_type` (`software` for `ias_38`, `hardware` for `ias_16`, editable), `category = 'Capitalised IT asset'`, `owner_id` (investment manager / expense requester), `purchase_date = available_for_use_on`, `end_of_life = available_for_use_on + useful life`, `source = 'manual'`, tags `['capitalised', CAP code]`. The `assets` table is not extended. Asset Show is unchanged (deferred). | |
| P6-15 | **Application TCO (C159, C160).** Subjects are `ea_application` and `business_service`, so Kano, which has no EA applications, shows business-service TCO. Per subject and FY, base currency:<br>• `direct` = baseline lines and counted actuals tagged to the subject (line tag; actual tag or its matched line's tag);<br>• `allocated` = allocation results of that lens;<br>• **actual basis:** the ledger cloud pool `CP-CLD` is **excluded** from direct/allocated and replaced by **FinOps cloud** = Σ cloud lines tagged to the subject in the FY (no double count);<br>• **budget basis:** ledger cloud is kept (no FinOps budget);<br>• `labour` (`CP-LAB` tree) is a breakdown, not an addend;<br>• `contracts_memo` = annualised `base_contract_value` of active/in-window contracts linked to the subject (memo only);<br>• `ea_modelled` = `CostModelService::tco($app)['total_ngn']` when the base currency is NGN, else null;<br>• `coverage_pct` = attributed / IT total. | **yes** |
| P6-16 | **EA feed is additive (C161).** `CostModelService::tco()` gains two keys, `measured_ngn` (finance actual TCO, current FY YTD annualised = YTD / elapsed periods × 12) and `measured_basis` (`'it_finance_ytd_annualised'` or null), when `config('ea.cost.finance_tco', true)` and the bank has a current FY. `total_ngn`, `rationalisationCandidates()` and `portfolio()` are **unchanged**. | |
| P6-17 | **Line classification (C162).** `BudgetLineClassifier::classify` may set or clear `ea_application_id`/`business_service_id` on lines of **any** budget status, baseline included, without a revision: it is classification only, no money changes (as C134). Conditions: the editor must see the line's cost centre (`FinancePolicy::updatePlan` visibility), the fiscal year must not be `closed`, and the subject must be in the org (or org NULL for EA). One activity row per line with old/new. | **yes** |
| P6-18 | **Masking (C163).** Without `view it-finance-amounts`, every cost, base cost, baseline/observed, pool, result, statement figure, TCO component, capitalisation amount, NBV and schedule amount is null. Visible: `deviation_pct`, `untagged_pct`, `coverage_pct`, `share_pct`, counts, statuses, dates and the anomaly `method`. The TCO detail endpoint and chargeback export/download return 403. | |
| P6-19 | **Permissions (C164).**<br>• Cloud accounts, tag rules, import, reallocate, untagged assign: `permission:import it-finance` + policy `manageCloud` (import + amounts). This gives the P0 S10 "unused" permission its meaning.<br>• Anomaly acknowledge/resolve/dismiss: `edit it-finance` + `handleAnomaly`.<br>• Allocations: `manage it-finance-settings` (`managePlanning`).<br>• Chargeback export: `export it-finance` + amounts. Void: `close it-finance-periods`.<br>• Capitalisation prepare: `edit it-finance` + amounts. Decide: `approve it-finance` (process permission). | |
| P6-20 | **Scoped viewers (C165).** Users with a restricted cost-centre set (`visibleCostCentreIds` non-null) see:<br>• cloud lines, groups and anomalies whose `cost_centre_id` is visible, plus accounts they own;<br>• showback and TCO computed over items in visible cost centres only (services take `?array $costCentreIds`, like `FinanceMetrics`);<br>• capitalisations whose source is visible. | |
| P6-21 | **Period close cloud item (C167).** `cloud_imported`:<br>• `pass` when every active cloud account has a line dated on or after the period end, or no active account exists (then `n_a`);<br>• otherwise **`warn`**, never `fail` (FinOps is a memo; it must not block the books).<br>`config('finance.cloud.close_requires_import')` (default false) turns warn into fail. | |
| P6-22 | **P5 carry-overs (§11.1, C168):** investment reassignment as an investment change of `kind = reassign`, always approved through the `change` gate; L2 tolerance against the baselined BAC and refusal of a negative BAC; L6 `is_capitalisable` flag; M1 approval-trail band masking; M2 scoped `linkLines`; M5 row lock for one pending request per investment. | **yes** (C168) |

---

## 1. Conventions to copy (same as P1–P5)

- **Models:** `use FinanceRecord;` (+ `SoftDeletes`, `GeneratesSequentialCode` where noted), explicit `$table`, `$guarded = []` (module-wide fix is P7), enum `const`s, casts `decimal:2`/`date`/`array`/`boolean`.
- **Services:** `public const TRANSITIONS = ['action' => ['from' => [...], 'to' => '...']]`. Refusals via `LogsFinanceEvents::fail($msg, $key)`; named audit via `audit()`. Money: bcmath strings, scale 2 (scale 6 for shares). Today = `FinanceClock::today()`. Input via `ValidatesFinanceInput::money()`.
- **Approvals:** only through `FinanceApprovals`; copy `TerminationService::approve/reject/withdraw` exactly, then add `assertNoStageStranded` before `approve()` as `StageGateService::approve` does.
- **Bulk writes** (cloud lines, allocation results): `DB::table()->insert()` in chunks of 500, inside a transaction, with one audited parent row (`ImportBatch`, `AllocationRun`, `ChargebackRun`) and an activity entry on it. This is the rule for new code under P5 security M7.
- **Unauthenticated work** (sweeps): `withoutGlobalScopes()->where('organization_id', …)`, per-tenant try/catch + `report($e)`.
- **Idempotent sends:** `ContractSweeper::claim($subject, $key, $offset, $users)`; key ≤ 64 chars.
- **Masking:** new self-contained trait `Concerns/PresentsFinOps` (cloud, allocations, showback, TCO) and `Concerns/PresentsCapitalisations`. Do not combine with other `Presents*` traits; their method names collide.
- **Seeders:** `attempt()` **must log** (`$this->command?->warn`), copied from `FinanceMonitoringDemoSeeder::attempt/whilePeriodsOpen`. Finish each bank with a self-check that **warns** for each missing target. No Postgres-only SQL: use `whereRaw('LOWER(col) LIKE ?')`.
- **UI:** MD3-in-Tailwind, `Components/Material/{Icon,Chip}`, `PageHeader`, navy `#0A1F44` / gold `#C9A86A`, recharts, `Components/Finance/Budgets/Money.jsx`, `Components/Approvals/ApprovalTrail.jsx`, `Components/Finance/CsvImportForm.jsx`, `ImportReport.jsx`. No MUI, no `rounded-full` pills, no heroicons; usable at 375 px. **Never chain `.post()`/`.put()` onto `form.transform()`.**

---

## 2. Task A: backend core (fin-backend-engineer, DB `it_security_risk_fin_test`)

### 2.1 `config/finance.php` (append; Task A owns the file, including the keys Task B reads)
```php
'cloud' => [
    'providers' => ['aws' => 'Amazon Web Services', 'azure' => 'Microsoft Azure', 'gcp' => 'Google Cloud', 'oci' => 'Oracle Cloud', 'other' => 'Other'],
    'dimensions' => ['cost_centre' => 'Cost centre', 'ea_application' => 'EA application', 'business_service' => 'Business service'],
    'max_rows' => 200000, 'max_kb' => 51200, 'chunk' => 500,
    'anomaly' => ['default_pct' => 30, 'default_abs_floor' => 250000, 'baseline_days' => 7, 'min_baseline_days' => 5, 'lookback_days' => 3, 'kri_window_days' => 30],
    'close_requires_import' => false,
    'formats' => [ // canonical => candidate header names (first present wins); tag_prefix/tags_json/tags_kv describe tags; account => id column checked against account_identifier
        'aws_cur' => ['date' => ['lineItem/UsageStartDate'], 'service' => ['product/ProductName', 'lineItem/ProductCode'], 'resource_id' => ['lineItem/ResourceId'],
            'region' => ['product/region', 'product/regionCode'], 'cost' => ['lineItem/UnblendedCost'], 'currency' => ['lineItem/CurrencyCode'], 'account' => ['lineItem/UsageAccountId'], 'tag_prefix' => 'resourceTags/user:'],
        'azure' => ['date' => ['Date', 'UsageDate'], 'service' => ['ServiceName', 'MeterCategory'], 'resource_id' => ['ResourceId', 'InstanceId'], 'region' => ['ResourceLocation'],
            'cost' => ['CostInBillingCurrency', 'Cost', 'PreTaxCost'], 'currency' => ['BillingCurrencyCode', 'BillingCurrency', 'Currency'], 'account' => ['SubscriptionId'], 'tags_json' => ['Tags']],
        'gcp' => ['date' => ['usage_start_time'], 'service' => ['service.description'], 'resource_id' => ['resource.name', 'sku.description'], 'region' => ['location.region'],
            'cost' => ['cost'], 'currency' => ['currency'], 'account' => ['project.id'], 'tags_json' => ['labels'], 'tag_prefix' => 'labels.'],
        'generic' => ['date' => ['date'], 'service' => ['service'], 'resource_id' => ['resource_id'], 'region' => ['region'], 'cost' => ['cost'], 'currency' => ['currency'],
            'account' => ['account_id'], 'tags_kv' => ['tags'], 'tag_prefix' => 'tag:'],
    ],
],
'allocations' => ['source_precedence' => ['budget_line', 'contract', 'category', 'cost_centre']],
'showback' => ['default_units' => [], 'chargeback_debit_gl' => '6950', 'chargeback_credit_gl' => '6951', 'void_reason_min' => 10],
'capitalisation' => [
    'standards' => ['ias_38' => 'IAS 38 intangible (software)', 'ias_16' => 'IAS 16 property, plant and equipment (hardware)'],
    'checklists' => [
        'ias_38' => [['technical_feasibility', 'Technically feasible to complete for use (IAS 38.57a)'], ['intention', 'Intention to complete and use (57b)'], ['ability_to_use', 'Ability to use the asset (57c)'],
            ['future_benefits', 'How it will generate probable future economic benefits (57d)'], ['resources', 'Adequate technical, financial and other resources to complete (57e)'],
            ['reliable_measurement', 'Development expenditure reliably measured (57f)'], ['exclusions', 'Research-phase, training and admin overheads excluded (IAS 38.54, .67)']],
        'ias_16' => [['future_benefits', 'Probable future economic benefits (IAS 16.7a)'], ['reliable_measurement', 'Cost reliably measurable (16.7b)'], ['use_beyond_year', 'Expected to be used for more than one period (16.6)']],
    ],
    'note_min' => 10, 'max_life_months' => 600, 'default_life_months' => ['ias_38' => 60, 'ias_16' => 48],
    'investment_stages' => ['in_delivery', 'delivered', 'benefits_tracking', 'closed'],
    'expense_statuses' => ['received', 'invoiced', 'closed'],
    'asset_category' => 'Capitalised IT asset',
],
```
Add to `approval_defaults`: `'capitalisation_cfo_from' => 50000000`. Add `'fin_cloud_cost_lines', 'fin_capitalisations'` to `money_tables`.

### 2.2 Migrations (schema builder only; `$this->base($t)` = `id`, indexed `organization_id`, `created_by_id` FK users nullOnDelete; timestamps on all; `down()` reverses)

**`database/migrations/2027_04_01_000001_create_it_finance_cloud_tables.php`**

| table | columns | indexes / FKs |
|---|---|---|
| `fin_cloud_accounts` | base; `provider` s8; `account_identifier` s100; `name` s160; `default_format` s12 (`aws_cur`/`azure`/`gcp`/`generic`); `currency` char(3); `default_cost_centre_id` FK fin_cost_centres restrict; `owner_id` FK users null nullOnDelete; `contract_id` FK fin_contracts null nullOnDelete; `tag_mapping` json null; `is_active` bool default true; `last_usage_date` date null; `notes` text null; softDeletes | unique(organization_id, provider, account_identifier); idx(org, is_active) |
| `fin_cloud_tag_rules` | base; `cloud_account_id` FK fin_cloud_accounts null cascade; `tag_key` s128; `tag_value` s255; `dimension` s20; `target_id` ubig; `is_active` bool default true; `hits` uint default 0; `last_hit_at` ts null | unique(organization_id, cloud_account_id, tag_key, tag_value, dimension); idx(org, tag_key) |
| `fin_cloud_cost_lines` | base; `cloud_account_id` FK cascade; `import_batch_id` FK fin_import_batches null nullOnDelete; `usage_date` date; `period_id` FK fin_periods null nullOnDelete; `service` s160; `resource_id` s512 null; `region` s64 null; `tags` json null; `line_hash` char(40); `cost` dec(18,6); `currency` char(3); `fx_rate` dec(18,8) default 1; `base_cost` dec(18,2); `cost_centre_id` FK fin_cost_centres null nullOnDelete; `ea_application_id` ubig null; `business_service_id` ubig null; `is_untagged` bool default false; `attributed_by` s12 null (`rule`/`builtin`/`manual`/`default`) | unique(cloud_account_id, usage_date, line_hash); idx(org, usage_date); idx(org, cloud_account_id, usage_date); idx(org, is_untagged, usage_date); idx(org, ea_application_id, usage_date); idx(org, business_service_id, usage_date); idx(org, cost_centre_id, usage_date) |
| `fin_cloud_anomalies` | base; `cloud_account_id` FK cascade; `detected_on` date (the anomalous usage day); `scope` s12; `scope_ref` s191 (`'*'` for account, service name, or application id); `method` s16; `baseline_cost`, `observed_cost` dec(18,2) (billing currency); `baseline_base_cost`, `observed_base_cost` dec(18,2); `deviation_pct` dec(9,2); `status` s16 default `open`; `owner_id` FK users null nullOnDelete; `note` text null; `acknowledged_by_id`, `resolved_by_id` FK users null; `acknowledged_at`, `resolved_at` ts null | unique(organization_id, cloud_account_id, detected_on, scope, scope_ref); idx(org, status, detected_on) |

`fin_import_batches` (guarded with `Schema::hasColumn`): `cloud_account_id` FK fin_cloud_accounts null nullOnDelete; `format` s12 null; `date_from`, `date_to` date null.

**`database/migrations/2027_04_01_000002_rebuild_it_finance_allocation_tables.php`**: `up()` first throws `RuntimeException('fin_allocations holds data; migrate it by hand')` if `DB::table('fin_allocations')->exists()` or `fin_allocation_results` has rows. It then drops both and creates:

| table | columns | indexes / FKs |
|---|---|---|
| `fin_allocations` | base; `allocation_code` s32; `name` s160; `source_type` s16; `source_id` ubig; `target_type` s20; `target_id` ubig null; `target_ref` s32 null (business-unit code); `method` s12; `percentage` dec(7,4) null; `driver_id` FK fin_drivers null nullOnDelete; `driver_quantity` dec(18,4) null; `effective_from` date; `effective_to` date null; `is_active` bool default true; `journal_cost_centre_code` s32 null; `notes` text null; softDeletes | unique(org, allocation_code); idx(org, source_type, source_id); idx(org, target_type, target_id); idx(org, is_active, effective_from) |
| `fin_allocation_runs` | base; `fiscal_year_id` FK restrict; `period_id` FK fin_periods restrict; `amount_type` s8; `target_type` s20; `pool_total`, `allocated_total`, `direct_total`, `it_total` dec(18,2); `results_count` uint; `computed_at` ts; `computed_by_id` FK users null; `is_frozen` bool default false | unique(org, period_id, amount_type, target_type); idx(org, fiscal_year_id) |
| `fin_allocation_results` | base; `allocation_id` FK cascade; `run_id` FK fin_allocation_runs cascade; `period_id` FK restrict; `amount_type` s8; `cost_pool_id` FK fin_categories null nullOnDelete; `pool_base_amount` dec(18,2); `share_pct` dec(9,6); `base_amount` dec(18,2); `computed_at` ts | unique(allocation_id, period_id, amount_type, cost_pool_id); idx(org, period_id, amount_type) |
| `fin_chargeback_runs` | base; `run_code` s32; `period_id` FK restrict; `target_type` s20; `status` s12 (`exported`/`voided`); `line_count` uint; `total_debit`, `total_credit` dec(18,2); `currency` char(3); `file_sha256` char(64); `content` longText (the CSV); `exported_by_id` FK users; `exported_at` ts; `voided_by_id` FK users null; `voided_at` ts null; `void_reason` text null | unique(org, run_code); idx(org, period_id, target_type, status) |

`down()` drops these four and recreates the exact P1 shape (copy from `2027_02_01_000002`).

**`database/migrations/2027_04_01_000003_create_it_finance_capitalisation_tables.php`**

| table | columns | indexes / FKs |
|---|---|---|
| `fin_capitalisations` | base; `capitalisation_code` s32; `title` s200; `source_type` s12 (`investment`/`expense`); `investment_id` FK fin_investments null restrict; `expense_request_id` FK fin_expense_requests null restrict; `standard` s8; `asset_id` FK assets null nullOnDelete; `create_asset` bool default true; `asset_name` s200 null; `asset_type` s20 null; `asset_owner_id` FK users null nullOnDelete; `cost_centre_id` FK fin_cost_centres null nullOnDelete (from source); `available_for_use_on` date; `capitalised_on` date null; `booking_period_id` FK fin_periods null nullOnDelete; `amount` dec(18,2); `eligible_amount` dec(18,2) (snapshot at submit); `excluded_amount` dec(18,2) default 0; `excluded_reason` text null; `residual_value` dec(18,2) default 0; `useful_life_months` usmallint; `method` s16 (`straight_line`/`declining`); `declining_rate_pct` dec(6,2) null; `criteria_checklist` json; `status` s12 default `draft`; `requested_by_id` FK users null; `requested_at` ts null; `approved_by_id` FK users null; `approved_at` ts null; `rejection_reason` text null; `cancel_reason` text null; `sod_excluded_ids` json null; `approval_request_id` FK approval_requests null nullOnDelete; softDeletes | unique(org, capitalisation_code); idx(org, status); idx(investment_id); idx(expense_request_id) |
| `fin_amortisation_lines` | base; `capitalisation_id` FK cascade; `line_no` usmallint; `month` date (first of month); `period_id` FK fin_periods null nullOnDelete; `opening_nbv`, `amount`, `closing_nbv` dec(18,2); `is_catch_up` bool default false | unique(capitalisation_id, line_no); idx(org, month) |

`fin_investments` (hasColumn guards): `is_capitalisable` bool default false; `capitalisation_standard` s8 null (P5 L6; Task B makes them editable).

**`database/migrations/2027_04_01_000004_add_finops_settings_columns.php`**: `fin_settings` (hasColumn guards): `cloud_anomaly_pct` dec(6,2) null; `cloud_anomaly_abs_floor` dec(18,2) null; `showback_units` json null; `chargeback_debit_gl` s32 null; `chargeback_credit_gl` s32 null. Null means the config default applies.

No FK is added to `ea_application_id`/`business_service_id`/`target_id` (polymorphic and EA-tenant NULL rows); the services validate the org.

### 2.3 Models `app/Models/Finance/` (new unless marked)

| model | traits / constants | relations | accessors / methods |
|---|---|---|---|
| `CloudAccount` | FinanceRecord, SoftDeletes; `PROVIDERS` (keys of config), `FORMATS = ['aws_cur','azure','gcp','generic']`, `DIMENSIONS = ['cost_centre','ea_application','business_service']` | defaultCostCentre, owner, contract, costLines, anomalies, tagRules, batches (ImportBatch `cloud_account_id`) | `label` ("AWS · 4021-…-88 · Digital channels"), `scopeActive` |
| `CloudTagRule` | FinanceRecord | account | `targetLabel()` |
| `CloudCostLine` | FinanceRecord (no SoftDeletes); casts tags array, usage_date date, cost `decimal:6`, base_cost `decimal:2` | account, period, costCentre, importBatch, `eaApplication()` (belongsTo `Ea\EaApplication`, withoutGlobalScope), `businessService()` | `scopeTagged`, `scopeUntagged`, `scopeBetween($from,$to)` |
| `CloudAnomaly` | FinanceRecord; `SCOPES = ['account','service','application']`, `METHODS = ['rolling_7d','week_over_week']`, `STATUSES = ['open','acknowledged','resolved','false_positive']`, `OPEN = ['open','acknowledged']` | account, owner, acknowledgedBy, resolvedBy | `scope_label` (service name / application name / "Account total"), `label` |
| `Allocation` (**rewrite**) | FinanceRecord, SoftDeletes, GeneratesSequentialCode (`ALC-`); `SOURCE_TYPES = ['cost_centre','category','budget_line','contract']`, `TARGET_TYPES = ['business_unit','cost_centre','business_service','ea_application','investment','legal_entity']`, `TAG_TARGETS = ['business_service','ea_application','investment','legal_entity']`, `METHODS = ['direct','percentage','driver']`, `AMOUNT_TYPES = ['budget','actual']` | driver, results, `sourceModel()` / `targetModel()` (resolver by type, no morph map) | `source_label`, `target_label`, `isEffectiveOn(CarbonInterface)`, `scopeEffectiveBetween($from,$to)` |
| `AllocationRun` | FinanceRecord | period, fiscalYear, results, computedBy | — |
| `AllocationResult` (**rewrite**) | FinanceRecord | allocation, run, period, costPool (Category) | — |
| `ChargebackRun` | FinanceRecord, GeneratesSequentialCode (`CBK-`); `STATUSES = ['exported','voided']` | period, exportedBy, voidedBy | `file_name` (`{run_code}-{period}.csv`) |
| `Capitalisation` | FinanceRecord, SoftDeletes, GeneratesSequentialCode (`CAP-`); `SOURCE_TYPES = ['investment','expense']`, `STANDARDS = ['ias_38','ias_16']`, `METHODS = ['straight_line','declining']`, `STATUSES = ['draft','submitted','capitalised','rejected','cancelled']`, `ASSET_TYPES = ['software','hardware','cloud_service','network','facility']` | investment, expenseRequest, asset, assetOwner, costCentre, bookingPeriod, requestedBy, approvedBy, lines (line_no asc), approvalRequest, documents (morphMany FinanceDocument) | `source()` (investment or expense), `source_label`, `getAmountBandAttribute`/`getAmountBandLabelAttribute` (as `Termination`, on `amount`), `getRequiresCfoAttribute` (`amount ≥ approval_defaults.capitalisation_cfo_from` → `'yes'|'no'`), `getStandardAttribute` (passthrough for conditions), `getSourceTypeAttribute`, `getCapitalisationTitleAttribute` ("KHB-CAP-0002 Finacle 11 release 1 (IAS 38)"), `sodExcludedIds()` (snapshot else live: requested_by, created_by, investment manager or expense requested_by; ints, unique), `nbvOn(CarbonInterface)`, `amortisedTo(CarbonInterface)` |
| `AmortisationLine` | FinanceRecord | capitalisation, period | — |

**Edits (Task A):** `ImportBatch.php` (+ `cloudAccount()`, `cloudLines()` hasMany CloudCostLine, casts `date_from/date_to`); `FinanceDocument.php` `subjects()` + `'capitalisation' => Capitalisation::class`, KINDS + `'capitalisation_memo'`; `Setting.php` SECTIONS + `'finops'`, casts `showback_units` array, `cloud_anomaly_pct` `decimal:2`, `cloud_anomaly_abs_floor` `decimal:2`. **Do not edit `Investment.php`** (Task B); read `is_capitalisable` with `where()`.

### 2.4 Services `app/Services/Finance/` (new unless marked)

**`CloudExportParser`** (pure, no DB; unit-tested)
```php
public function parse(string $path, string $format, string $accountIdentifier, array $options = []): array;
// returns ['rows' => list<{usage_date:'Y-m-d', service, resource_id:?string, region:?string, tags:array<string,string>, cost:string(6dp), currency}>,
//          'rejected' => list<{line:int, reason:string}>, 'row_count' => int, 'date_from' => ?string, 'date_to' => ?string]
// Header lookup per config('finance.cloud.formats.{format}') (case-insensitive, BOM stripped); date parsed from ISO-8601/Y-m-d/m/d/Y (Azure);
// tags: tag_prefix columns, tags_json (Azure fragment without braces is wrapped; GCP [{key,value}] list), tags_kv "k=v;k=v"; keys trimmed, values ≤ 255;
// account column present and ≠ identifier → row rejected; cost must match /^-?\d{1,14}(\.\d{1,10})?$/ (credits may be negative); currency /^[A-Z]{3}$/; rows > max_rows → whole file refused.
public static function aggregate(array $rows): array;   // daily key → summed cost (bcadd scale 6); line_hash = sha1(json of key with ksort'ed tags)
```

**`CloudCostService`**
```php
public function storeAccount(array $data, User $by): CloudAccount;       // identifier unique per provider in org; default cost centre in org; owner/contract in org; tag_mapping keys ≤128, dimension in DIMENSIONS
public function updateAccount(CloudAccount $a, array $data, User $by): CloudAccount; // provider/identifier immutable once lines exist
public function destroyAccount(CloudAccount $a, User $by): void;         // refused when lines exist ("deactivate it instead")
public function import(CloudAccount $a, UploadedFile $file, ?string $format, User $by): ImportBatch;
   // sha256 duplicate per account refused; parse → aggregate → per date P6-2 replace/append rules → FX via CurrencyService::convert(orgId, cost, ccy, usage_date,'spot')
   // (memoised per ccy+date; no rate → rows rejected "no USD→NGN rate on 2026-08-03") → attribute() → chunked insert; batch type 'cloud', counts, report json
   // {rejected[], replaced_dates[], appended_dates[], refused_dates[]}; status completed|completed_with_errors|failed; account.last_usage_date; audit on batch.
   // Does NOT notify (Task B wires ImportCompletedNotification in the controller after this returns).
public function ingestRows(CloudAccount $a, array $aggregatedRows, ?ImportBatch $batch, User $by): array; // shared by import() and the seeder; same P6-2 rules
public function attribute(CloudAccount $a, array $tags, ?array $ruleCache = null): array;   // P6-3 → {cost_centre_id, ea_application_id, business_service_id, is_untagged, attributed_by}
public function allocateByTags(CloudAccount $a, ?string $from, ?string $to, User $by): int; // re-attribute lines in OPEN periods; returns changed count; audit
public function assignUntagged(CloudAccount $a, string $resourceId, ?string $service, string $dimension, int $targetId, bool $createRule, ?string $tagKey, User $by): int;
   // open-period lines of that resource (and service) get the dimension; is_untagged recomputed; createRule → CloudTagRule on (tagKey, the resource's tag value) or,
   // when the resource has no tags, no rule (UI says so); attributed_by 'manual'
public function storeRule(array $data, User $by): CloudTagRule;  public function updateRule(CloudTagRule $r, array $data, User $by): CloudTagRule;  public function destroyRule(CloudTagRule $r, User $by): void;
public function summary(int $orgId, array $filters, ?array $ccIds): array;           // §7.2 summary + series + groups (SQL GROUP BY, no per-row PHP)
public function untaggedQueue(int $orgId, array $filters, ?array $ccIds, int $limit = 200): array;
public function ledgerReconciliation(int $orgId, int $periodId, ?array $ccIds): array; // P6-1 {finops_total, cloud_ledger_actual, gap, gap_pct, period}
public function mtd(int $orgId, ?array $ccIds = null, ?CarbonInterface $today = null): array; // {mtd, prior_mtd, trend:[{date,cost}] last 30 days} (Task B uses for metrics/dashboard)
```

**`CloudAnomalyService`**
```php
public const TRANSITIONS = ['acknowledge' => ['from' => ['open'], 'to' => 'acknowledged'], 'resolve' => ['from' => ['open','acknowledged'], 'to' => 'resolved'],
                            'dismiss' => ['from' => ['open','acknowledged'], 'to' => 'false_positive']];
public function detect(CloudAccount $a, ?CarbonInterface $asOf = null): Collection;   // P6-4; returns the NEWLY created anomalies (Task B notifies those)
public function detectForOrg(int $orgId, ?CarbonInterface $asOf = null): Collection;  // active accounts
public static function evaluate(array $dailyCosts, string $day, array $thresholds): ?array; // pure: {method, baseline, observed, deviation_pct} or null — unit-tested
public function acknowledge(CloudAnomaly $x, ?string $note, User $by): CloudAnomaly;
public function resolve(CloudAnomaly $x, string $note, User $by): CloudAnomaly;      // note ≥ 10
public function dismiss(CloudAnomaly $x, string $reason, User $by): CloudAnomaly;    // reason ≥ 10 → false_positive
public function thresholds(int $orgId): array;                                        // settings → config
```

**`AllocationService`**
```php
public function store(array $data, User $by): Allocation;     // P6-5 validation: source/target exist in org (EA: org or NULL; business_unit ref in showback_units);
                                                               // method rules (direct: percentage forced 100, single rule in group; percentage: 0<p≤100, group sum ≤100 over overlapping windows;
                                                               // driver: driver in org + driver_quantity>0); one method per group; effective dates per P6-8
public function update(Allocation $a, array $data, User $by): Allocation;  // source/target/method immutable once results exist → end it and create a new rule
public function end(Allocation $a, string $effectiveTo, User $by): Allocation;
public function destroy(Allocation $a, User $by): void;        // only without results
public function shares(int $orgId, string $targetType, Period $p): array;  // group → [allocation_id => share_pct(scale 6)]
public function run(int $orgId, int $fiscalYearId, ?array $periodIds, array $amountTypes, User $by): array; // P6-6..P6-8; per target_type with active rules; returns [{period_id, amount_type, target_type, status:'computed'|'frozen'|'skipped', results, allocated_total}]
public function freeze(Period $p, User $by): void;             // final run for the period (both amount types, every lens) then is_frozen=true (called by PeriodCloseService, Task B)
public function pools(int $orgId, string $targetType, Period $p, string $amountType, ?array $ccIds = null): array; // {sources:[{source_type,source_id,by_pool:{pool_id:amount}}], direct:[{target_key,by_pool}], it_total, by_pool_total}
```

**`ShowbackService`**
```php
public function statement(int $orgId, string $targetType, int $fiscalYearId, int $fromPeriodId, int $toPeriodId, string $basis, ?array $ccIds, User $viewer): array; // §7.5 shape; reconciles (P6-9)
public function detail(int $orgId, string $targetType, string $targetKey, int $fromPeriodId, int $toPeriodId, string $basis, ?array $ccIds): array; // rule/period/pool rows + direct items (top 200)
public function units(int $orgId): array;   // showback_units with labels
public function cloudMemo(int $orgId, string $targetType, int $fromPeriodId, int $toPeriodId, ?array $ccIds): array; // FinOps cost by cost_centre/ea_application/business_service lens; [] for other lenses
```

**`ChargebackService`**
```php
public const TRANSITIONS = ['void' => ['from' => ['exported'], 'to' => 'voided']];
public function preview(int $orgId, int $periodId, string $targetType): array;  // {lines:[{line_no,gl_code,cost_centre_code,debit,credit,description,target_ref,rule_code}], total_debit, total_credit, blocked_reason:?string}
public function export(int $orgId, int $periodId, string $targetType, User $by): ChargebackRun; // P6-10; showback_enabled; closed/locked period; no live exported run; balanced or fail
public function void(ChargebackRun $r, string $reason, User $by): ChargebackRun;
public static function csv(array $lines, string $journalRef, string $postingDate, string $currency): string; // header: journal_ref,posting_date,line_no,gl_code,cost_centre_code,debit,credit,currency,description,target_type,target_ref,rule_code (formula-injection neutralised: prefix ' on =,+,-,@)
```

**`ApplicationTcoService`**
```php
public const SUBJECTS = ['ea_application', 'business_service'];
public function portfolio(int $orgId, int $fiscalYearId, string $subject, string $basis, ?array $ccIds, User $viewer): array; // §7.6 rows + totals + subjectAvailable; grouped SQL, no N+1
public function detail(int $orgId, int $fiscalYearId, string $subject, int $id, string $basis, ?array $ccIds): array;          // {components:{direct:[...lines], allocated:[...rules], cloud:[...services], contracts:[...]}}
public function measuredFor(int $orgId, int $eaApplicationId): ?string;   // P6-16, current FY YTD annualised actual TCO; null when no FY / no data (Task B's EA hook calls this)
public function rationalisation(int $orgId, int $fiscalYearId, ?array $ccIds): array; // FBN: CostModelService::rationalisationCandidates() groups re-priced with measured TCO
```

**`BudgetLineClassifier`** (P6-17)
```php
public function classify(array $lineIds, array $dims /* ea_application_id?, business_service_id? (null clears) */, User $by): int; // visibility, FY not closed, subject in org/NULL; per-line audit 'classified'
public function unattributed(int $orgId, int $fiscalYearId, string $subject, ?array $ccIds, ?string $search, int $limit = 100): array; // baseline lines without the tag: [{id,label,cost_centre,current_base_amount}]
```

**`AmortisationSchedule`** (pure, unit-tested)
```php
public static function build(string $amount, string $residual, int $months, string $method, ?string $decliningRatePct, string $startMonth /* Y-m-01 */, ?string $bookingMonth = null): array;
// list<{line_no, month, opening_nbv, amount, closing_nbv, is_catch_up}>; Σ amount = amount − residual exactly; declining switches to SL when SL(remaining) ≥ DB amount
```

**`CapitalisationService`**
```php
public const PROCESS = 'finance.capitalisation_approval';
public const PERMISSION = 'approve it-finance';
public const TRANSITIONS = ['submit' => ['from' => ['draft'], 'to' => 'submitted'], 'withdraw' => ['from' => ['submitted'], 'to' => 'draft'],
    'approve' => ['from' => ['submitted'], 'to' => 'capitalised'], 'reject' => ['from' => ['submitted'], 'to' => 'rejected'],
    'reopen' => ['from' => ['rejected'], 'to' => 'draft'], 'cancel' => ['from' => ['draft','rejected'], 'to' => 'cancelled']];
public function candidates(int $orgId, ?array $ccIds, User $viewer): array;  // [{source_type,id,label,eligible_amount,already_capitalised,remaining,delivered_on,standard_hint}] remaining > 0 only
public function eligibility(string $sourceType, int $sourceId, string $availableForUseOn, int $orgId, ?int $excludeCapId = null): array; // {pool, already, remaining, actuals:[top 100]}
public function store(array $data, User $by): Capitalisation;        // source visible + rules of P6-12; checklist keys from config(standard) initialised unticked; cost_centre from source
public function update(Capitalisation $c, array $data, User $by): Capitalisation; // draft only
public function submit(Capitalisation $c, User $by): Capitalisation;  // all items ticked with notes; amount ≤ remaining (lock the source row); life/method/rate valid;
                                                                       // eligible_amount snapshot, sod_excluded_ids snapshot, FinanceApprovals::start(PROCESS, $c, $by)
public function approve(Capitalisation $c, User $by, ?string $note = null): string;  // Termination skeleton + assertNoStageStranded; final: re-check remaining pool,
                                                                                     // booking period = open period containing today (else fail), capitalised_on = today, asset link/create (P6-14),
                                                                                     // AmortisationSchedule::build(...) → fin_amortisation_lines with period_id where a period exists, status capitalised
public function reject(Capitalisation $c, User $by, string $note): Capitalisation;
public function withdraw(Capitalisation $c, User $by): Capitalisation;  // requester or creator
public function reopen(Capitalisation $c, User $by): Capitalisation;
public function cancel(Capitalisation $c, string $reason, User $by): Capitalisation;
public function scheduleFor(Capitalisation $c): array;                   // lines with period resolved by month at read
```

**Edits (Task A):**
- `FinanceSettings.php`:
  - `SECTION_KEYS['finops'] = ['cloud_anomaly_pct', 'cloud_anomaly_abs_floor', 'showback_units', 'chargeback_debit_gl', 'chargeback_credit_gl']`.
  - `update()` validates `showback_units`: ≤ 50; code `/^[A-Z0-9_-]{2,16}$/` unique; name ≤ 120; `erp_cost_centre_code` ≤ 32 nullable. A unit code still used by an active allocation cannot be removed.
  - Getters: `cloudAnomalyThresholds($orgId)`, `showbackUnits($orgId)`, `chargebackGl($orgId)`.
- `app/Services/Approvals/ApprovalProcesses.php` (Task A adds the entry; Task B owns the stages):
```php
'finance.capitalisation_approval' => [
  'module' => 'IT Financial Management', 'label' => 'Capitalisation (IAS 38 / IAS 16)', 'engine' => 'generic', 'owner_cannot_approve' => false,
  'description' => 'Investment or capex spend is capitalised as an asset, and amortisation starts, only with approval (FIN-03.13; IAS 38, IAS 16).',
  'permission' => 'approve it-finance', 'view_permission' => 'view it-finance', 'model' => Capitalisation::class,
  'initiator' => 'requested_by_id', 'title' => ['capitalisation_title'], 'route' => 'finance.capitalisations.show',
  'initiate' => 'Submitting a capitalisation',
  'conditions' => [
    'amount_band' => ['label' => 'Amount band (DoA)', 'values' => 'finance.doa_bands'],
    'standard' => ['label' => 'Standard', 'values' => ['ias_38' => 'IAS 38 intangible', 'ias_16' => 'IAS 16 PP&E']],
    'source_type' => ['label' => 'Source', 'values' => ['investment' => 'Investment', 'expense' => 'Capex expense']],
    'requires_cfo' => ['label' => 'Needs the CFO (high value)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
  ],
  'default' => 'One approval by anyone with "approve it-finance" other than the preparer and the investment manager or expense requester.',
],
```

### 2.5 Controllers, requests, policy, masking

Controllers in `app/Http/Controllers/Finance/` (new):
- **`CloudAccountController`:** `index`, `store`, `update`, `destroy`, `reallocate`.
- **`CloudImportController`:** `store` (calls `CloudCostService::import`; then `FinanceImportNotifier::completed($batch)` **if the class exists** (Task B creates it), then redirect with `flash.import_report`).
- **`CloudTagRuleController`:** `store`, `update`, `destroy`.
- **`CloudCostController`:** `index` (`?tab=costs|untagged`), `assignUntagged`.
- **`CloudAnomalyController`:** `index`, `acknowledge`, `resolve`, `dismiss`.
- **`AllocationController`:** `index`, `store`, `update`, `end`, `destroy`, `run`.
- **`ShowbackController`:** `index`, `export` (`?format=csv|xlsx|pdf`, reuses the P4 report writers).
- **`ChargebackController`:** `store` (export + download response), `download`, `void`.
- **`TcoController`:** `index`, `detail` (JSON), `classify` (POST → `BudgetLineClassifier`).
- **`CapitalisationController`:** `index`, `show`, `store`, `update`, `submit`, `withdraw`, `cancel`, `approve`, `reject`. Rejected → draft (reopen) happens on `update` of a rejected row: the service calls `reopen` first.

Other HTTP files:
- **`Concerns/PresentsFinOps.php`:**
  - `FINOPS_MONEY_KEYS` = cost, base_cost, total, untagged, mtd, prior_mtd, baseline_cost, observed_cost, baseline_base_cost, observed_base_cost, pool_total, allocated_total, direct, allocated, retained, it_total, budget_total, variance, amount, finops_total, cloud_ledger_actual, gap, cloud, labour, contracts_memo, ea_modelled, measured, current_base_amount, total_debit, total_credit, difference, tco_total, unattributed.
  - `FINOPS_MONEY_LISTS` = series, by_pool, lines.
  - Helpers `accountRow()`, `anomalyRow()`, `ruleRow()`, `optionsPayload()`, `visibleCcIds(User)` (wraps the policy's visible set).
- **`Concerns/PresentsCapitalisations.php`:** money keys amount, eligible_amount, excluded_amount, residual_value, nbv_today, amortised_to_date, opening_nbv, closing_nbv, pool, already, remaining, base_amount, capitalised_total, nbv_total, amortisation_this_fy, `amount_band_label`; `capRow()`.
- **Requests `app/Http/Requests/Finance/`:** `CloudAccountRequest`, `CloudImportRequest` (file `mimes:csv,txt` ≤ `cloud.max_kb`, `format` in FORMATS), `CloudTagRuleRequest`, `UntaggedAssignRequest`, `AnomalyActionRequest`, `AllocationRequest`, `AllocationRunRequest`, `ChargebackRequest`, `CapitalisationRequest`, `ClassifyLinesRequest`. Every id is existence-checked against the user's org; money uses the `FinanceSettings::DOA_AMOUNT` regex; enums use `Rule::in`.
- **Edits:**
  - `FinanceSettingsController.php`: `finops` rules + prop `finopsPolicy`.
  - `FinanceDocumentController.php`: `capitalisation` → `updateCapitalisation`.
  - `FinanceImportController.php`: `show` handles `type = cloud` with props `cloudSummary{lines, untagged, dates:{from,to}, by_service:[{service,cost}]}` and no actuals query. **R5:** SMOKE renders it.
- **`app/Policies/Finance/FinancePolicy.php` (edit):**
```php
manageCloud(User)                                   // import it-finance && view it-finance-amounts
handleAnomaly(User, CloudAnomaly)                   // edit it-finance && sameOrg && (owner || manageCloud || close it-finance-periods)
manageAllocations(User)                             // managePlanning
exportChargeback(User)                              // export it-finance && view it-finance-amounts
voidChargeback(User, ChargebackRun)                 // close it-finance-periods && sameOrg
capitalise(User, Model $source)                     // edit it-finance && view it-finance-amounts && sameOrg && source visible (investmentVisible / costCentreVisible)
updateCapitalisation(User, Capitalisation)          // edit it-finance && sameOrg && (requested_by/creator || manage it-finance-settings || close it-finance-periods) && visible
decideCapitalisation(User, Capitalisation)          // approve it-finance && sameOrg (engine narrows the stage)
```
  `costCentreVisible()` gets two new branches: Capitalisation (source cost centre, or `investmentVisible` for investment sources), and CloudAccount/CloudCostLine/CloudAnomaly (default cost centre or line cost centre visible, or owner).

### 2.6 Routes `routes/web.php` (inside the `finance.` group after the P5 block; `use` lines; every `{param}` `whereNumber`; implicit binding gives a cross-tenant 404; every action also calls `Gate::authorize`)
```
GET    /cloud/accounts                                finance.cloud.accounts.index
GET    /cloud/costs                                   finance.cloud.costs
GET    /cloud/anomalies                               finance.cloud.anomalies.index
GET    /allocations                                   finance.allocations.index
GET    /monitor/showback                              finance.monitor.showback
GET    /monitor/tco                                   finance.monitor.tco
GET    /monitor/tco/detail                            finance.monitor.tco.detail           (JSON; ?subject=&id=&fiscal_year_id=&basis=)
GET    /capitalisations                               finance.capitalisations.index
GET    /capitalisations/{capitalisation}              finance.capitalisations.show
permission:export it-finance:
GET    /monitor/showback/export                       finance.monitor.showback.export
POST   /chargeback-runs                               finance.chargeback.store
GET    /chargeback-runs/{chargebackRun}/download      finance.chargeback.download
permission:close it-finance-periods:
POST   /chargeback-runs/{chargebackRun}/void          finance.chargeback.void
permission:import it-finance:
POST   /cloud/accounts                                finance.cloud.accounts.store
PUT    /cloud/accounts/{cloudAccount}                 finance.cloud.accounts.update
DELETE /cloud/accounts/{cloudAccount}                 finance.cloud.accounts.destroy
POST   /cloud/accounts/{cloudAccount}/reallocate      finance.cloud.accounts.reallocate
POST   /cloud/import                                  finance.cloud.import
POST   /cloud/tag-rules                               finance.cloud.tag-rules.store
PUT    /cloud/tag-rules/{cloudTagRule}                finance.cloud.tag-rules.update
DELETE /cloud/tag-rules/{cloudTagRule}                finance.cloud.tag-rules.destroy
POST   /cloud/untagged/assign                         finance.cloud.untagged.assign
permission:edit it-finance:
POST   /cloud/anomalies/{cloudAnomaly}/acknowledge    finance.cloud.anomalies.acknowledge
POST   /cloud/anomalies/{cloudAnomaly}/resolve        finance.cloud.anomalies.resolve
POST   /cloud/anomalies/{cloudAnomaly}/dismiss        finance.cloud.anomalies.dismiss
POST   /budgets/lines/classify                        finance.budgets.lines.classify
POST   /capitalisations                               finance.capitalisations.store
PUT    /capitalisations/{capitalisation}              finance.capitalisations.update
POST   /capitalisations/{capitalisation}/submit       finance.capitalisations.submit
POST   /capitalisations/{capitalisation}/withdraw     finance.capitalisations.withdraw
POST   /capitalisations/{capitalisation}/cancel       finance.capitalisations.cancel
permission:manage it-finance-settings:
POST   /allocations                                   finance.allocations.store
POST   /allocations/run                               finance.allocations.run              (register before {allocation})
PUT    /allocations/{allocation}                      finance.allocations.update
POST   /allocations/{allocation}/end                  finance.allocations.end
DELETE /allocations/{allocation}                      finance.allocations.destroy
permission:approve it-finance:
POST   /capitalisations/{capitalisation}/approve      finance.capitalisations.approve
POST   /capitalisations/{capitalisation}/reject       finance.capitalisations.reject
```
That is 38 routes: 11 GET and 27 writes. Check `finance.budgets.lines.classify` against the existing `finance.budgets.lines.*` names first (`budgets/{budget}/lines/...` takes a `{budget}` param; `/budgets/lines/classify` has none, so register it **before** any `/budgets/{budget}` route, or use `/budget-lines/classify` if `{budget}` is not `whereNumber`).

### 2.7 Backend tests owned by Task A
- **`tests/Feature/Finance/Concerns/BuildsFinOps.php`** (new; Tasks B and E use it, read-only). Builds on `BuildsMonitoring` + `BuildsInvestments`:
  - `bootFinOps(string $now = '2026-10-15 09:00:00')`: FY with open Oct–Dec, baseline lines in `CP-CLD` / `CP-LAB` / `CP-SW` across two cost centres, showback units RET/CIB/TRS.
  - `makeCloudAccount(array $over = [])`
  - `cloudRows(string $from, int $days, array $services, array $tags, float $daily)` (aggregated rows for `ingestRows`)
  - `spike(string $date, string $service, float $factor)`
  - `makeAllocation(array $over)`
  - `capexActual(Model $source, string $amount, string $postedOn)`
  - `makeCapitalisation(Model $source, array $over)`
  - `decide(Model $subject, string $process, User ...$deciders)`
- **`tests/Unit/Finance/CloudExportParserTest.php`** (pure, ~8): one fixture per format under `tests/Fixtures/finance/cloud/{aws_cur,azure,gcp,generic}.csv` (Task A creates them, ≤ 30 rows each). Covers: tags from every encoding; account mismatch rejected; hourly CUR rows aggregate to daily; negative credit accepted; "1e5" rejected; BOM header; max-rows refusal.
- **`tests/Unit/Finance/AmortisationScheduleTest.php`** (pure, ~7):
  - SL 12,000,000.00 / 36 → 35 × 333,333.33 + 333,333.45; Σ exact.
  - SL with residual.
  - Declining 10,000,000 / 60 months at 40 % → first month 333,333.33, switches to SL, ends at the residual exactly.
  - Catch-up flags when the booking month is later.
  - 1-month life; invalid inputs.
- **`tests/Unit/Finance/CloudAnomalyEvaluateTest.php`** (pure, ~5): rolling vs WoW; < 5 baseline days → null; floor not met → null; billing-currency inputs.
- **`tests/Feature/Finance/FinOpsBackendSmokeTest.php`** (new, ~12):
  - every new GET renders with the §7 keys;
  - masking nulls `FINOPS_MONEY_KEYS`; TCO detail / chargeback / showback export give 403 without amounts;
  - another bank's account, anomaly, rule, allocation, run or capitalisation → 404;
  - import of the AWS fixture creates a batch and lines and `imports.show` renders the cloud batch;
  - classify refuses another bank's line and a closed FY;
  - allocation store refuses a group sum > 100 and mixed methods;
  - chargeback refuses an open period.
- **`tests/Feature/Finance/FinanceTenancyAndRoutesTest.php`** (edit): add the 7 P6 page GET routes (cloud accounts/costs/anomalies, allocations, showback, tco, capitalisations index).

Run: `--filter 'CloudExportParserTest|AmortisationScheduleTest|CloudAnomalyEvaluateTest|FinOpsBackendSmokeTest|FinanceTenancyAndRoutesTest|FinancePermissionsTest|FinanceConfigurationTest|MonitoringAndVarianceTest|BudgetLifecycleTest|ActualsImportAndReconciliationTest|InvestmentBackendSmokeTest'`. **Do not run** `WorkflowStudioModuleTest` / `FinanceWorkflowStudioTest`: their process counts are Task B's and stay red until B lands.

---

## 3. Task B: workflow, integration and P5 carry-overs (fin-workflow-integrator, DB `it_security_risk_fin_wf_test`)

### 3.1 Slice B1: P5 carry-overs (start immediately; touches only investment and approval files)
1. **Migration `database/migrations/2027_04_01_000005_investment_reassignment_and_bac_baseline.php`** (Task B owns it; hasColumn guards):
   - `fin_investment_changes`: `kind` s12 default `scope` (`scope`/`reassign`); `field_changes` json null (`{field: {from, to}}`).
   - `fin_investments`: `bac_baseline_amount` dec(18,2) null, backfilled with `DB::table('fin_investments')->whereNotNull('bac_baselined_at')->update(['bac_baseline_amount' => DB::raw('bac')])`.
2. **Reassignment (the P5 open gap, C168).** In `InvestmentChangeService::request()`, `kind = reassign` takes `field_changes` over `sponsor_id`, `manager_id`, `cost_centre_id`, `rgt`, `is_regulatory` (at least one, each different from its current value).
   - Validation: users active, in the org, holding `view it-finance`; cost centre in the org; a **manager ≠ sponsor**; neither may be the requester. A reason of ≥ 20 chars is required. `cost_delta` and `schedule_delta_days` must be 0.
   - It **always** requires approval (`requires_approval = true` → `StageGateService::requestChange`, gate `change`, amount = current BAC).
   - `StageGate::liveSodExcludedIds()` adds the **old and new** sponsor and manager from `field_changes` (edit in `StageGate.php`).
   - `apply()` for `reassign`: `forceFill` of the fields; open value reviews whose `reviewer_id` = the old sponsor move to the new sponsor; audit `reassigned` with from/to.
   - `InvestmentService::CONTROL_FIELDS` freezing stays; reassignment is the only path.
   - Allowed in stages `approved`, `in_delivery`, `delivered`, `benefits_tracking` (scope changes stay `approved`/`in_delivery`).
3. **Domain L2.** On approval re-baseline (`StageGateService` final of `approved` gate and approved `change`), set `bac_baseline_amount = bac`. The tolerance limit = `bac_baseline_amount × tol` (falls back to `bac`). `apply()` **refuses** a delta that would make BAC negative ("The change would take the approved budget below zero") instead of clamping. Validate at request too.
4. **Domain L6.** `Investment.php`: casts `is_capitalisable` boolean, const `CAPITALISATION_STANDARDS = ['ias_38','ias_16']`. `InvestmentService::store/update` accept both fields, editable until stage `closed`/`cancelled` and not frozen (not a control field). `InvestmentFormRequest` adds the rules. `PresentsInvestments::investmentRow()` adds `is_capitalisable`, `capitalisation_standard`.
5. **Security M5.** `StageGateService::advance()` and `InvestmentChangeService::request()` take `Investment::withoutGlobalScopes()->lockForUpdate()->find($id)` inside their transaction **before** the "already awaiting approval" check.
6. **Security M2.** `InvestmentService::linkLines()` tags and untags only lines whose cost centre the editor may see (`Gate::allows('updatePlan', $line->budget)` or the policy's visible set) and whose budget is `draft`/`approved`/`baseline` in a fiscal year that is not `closed`. Others are refused with the line labels, not silently skipped.
7. **Security M1.** In `FinanceApprovals::trail()`, when `! FinanceMasking::canSeeAmounts($viewer)`: null `context.amount_band`, `context.amount_band_label`, `context.approval_base_amount`, `context.base_amount` and the `condition_values` of any stage whose `condition_field === 'amount_band'`. This applies to every finance process.
8. **`PresentsInvestments` / `InvestmentController::show`:** new prop `capitalisations: [{id, capitalisation_code, status, amount, available_for_use_on, href}]`, queried with `Capitalisation::where('investment_id')` and masked. Task A creates the model; **guard with `class_exists`** until A lands. Changes rows gain `kind` and `field_changes` (labels resolved: user names, cost-centre code); `options.reassignFields`.
9. **`InvestmentChangeRequest`** (edit): `kind`, `field_changes.*` rules.
10. **Tests:** `tests/Feature/Finance/FinanceP5CarryOverTest.php` (new, ~10):
    - reassign approved through the change gate, applied on final approval and refused within the request rules;
    - old and new sponsor/manager excluded from deciding;
    - open reviews move to the new sponsor;
    - L2 tolerance against the baselined BAC after within-tolerance drift; negative BAC refused;
    - L6 flag editable after approval;
    - M5 second pending request refused under the lock (sequential assertion);
    - M2 another cost centre's line refused for a budget owner;
    - M1 trail band nulled for a viewer without amounts, kept for one with.

Run B1: `--filter 'FinanceP5CarryOverTest|InvestmentAndBusinessCaseTest|InvestmentWorkflowIntegrationTest|BenefitsAndValueReviewTest|InvestmentBackendSmokeTest'`.

### 3.2 Slice B2: integrations (after Task A reports green)
1. **`FinancePolicySeeder.php` (edit):** `'finance.capitalisation_approval' => [stage(10,'Finance Controller review','role','IT Finance Controller',72), stage(20,'CFO approval','job_title','Chief Financial Officer',72,'requires_cfo',['yes'])]`.
2. **`FinanceApprovalNotifier.php` (edit):** `'finance.capitalisation_approval' => route('finance.capitalisations.show', $subject)`.
3. **`app/Notifications/Finance/CloudAnomalyNotification.php`** (new, `BaseNotification`, `$module = 'IT Financial Management'`, **no amounts**). Constructor `(string $account, string $scopeLabel, string $detectedOn, string $deviationPct, string $method, string $url)`; type `finance.cloud_anomaly`.
4. **`app/Services/Finance/CloudAnomalySweeper.php`** (new): `sweep(?CarbonInterface $today = null): int`. Per org (try/catch): `CloudAnomalyService::detectForOrg($orgId, $today)`; for each new anomaly, `ContractSweeper::claim($anomaly, 'cloud_anomaly', 0, $users)` with users = account owner + active `IT Finance Controller` role holders, each holding `view it-finance`, unique; then notify. Returns the count sent.
5. **`app/Services/Finance/FinanceImportNotifier.php`** (new): `completed(ImportBatch $b): void` sends the existing `ImportCompletedNotification` to the importer (type cloud; no amounts). Task A's controller calls it when the class exists.
6. **`routes/console.php` (edit):** `Artisan::command('fin:cloud-anomalies {--date= : Y-m-d, run as if today}', …)` plus `Schedule::command('fin:cloud-anomalies')->dailyAt('05:45')->timezone(config('finance.timezone'))->name('finance.cloud-anomalies')->withoutOverlapping();`. Parse `--date` as in P4.
7. **KRI:** `app/Services/Kri/KriMeasures.php` `MEASURES['KRI-FIN-CLD'] = 'financeCloudAnomalies'` → `FinanceKriSource::cloudAnomalies($orgId)` (new method) = `[count of open + acknowledged anomalies with detected_on ≥ today − kri_window_days, "N open cloud cost anomalies in the last 30 days (FinOps)."]`. Not masked (C170).
8. **`FinanceMetrics.php` (edit):** `summary()` fills `cloud_mtd` (base, null when the bank has no cloud account) and `cloud_anomalies_open` (count) via `CloudCostService::mtd`; `BOARD_MONEY_KEYS` + `cloud_mtd`. **`FinanceDashboardController.php` (edit):** new prop `cloudTrend: [{date, cost}]` (30 days; `[]` when there is no account; masked → `[]` + `cloudTrendMasked: true`).
9. **`PeriodCloseService.php` (edit):** the real `cloud_imported` item per P6-21 (link `route('finance.cloud.costs', ['to' => period end], false)`); in `close()` call `app(AllocationService::class)->freeze($p, $by)` inside the transaction before status → closed (P6-8). `FinanceMonitoringDemoSeeder` line 453's hard-coded `n_a` item stays as history (do not edit P4 seeders).
10. **EA hook (P6-16):** `app/Services/Ea/CostModelService.php::tco()` adds `measured_ngn`/`measured_basis` to **every** return branch (null when the flag is off, the org has no FY, or the finance class is missing). `config/ea.php` adds `'cost' => [... 'finance_tco' => env('EA_FINANCE_TCO', true)]`, merged with the existing `ea.cost` keys; do not drop `factors`. Run the EA cost tests.
11. **Reports** (`app/Services/Finance/Reports/Definitions/`, new; `FinanceReports::DEFINITIONS` + 4; `ScheduledReport::TYPES` + 4):
    - `CloudSpendReport` (`cloud_spend`): account, provider, service, cost centre, application/service, days, billing cost, base cost, untagged flag; filters account, from/to; group `Cloud & FinOps`.
    - `ShowbackStatementReport` (`showback_statement`): target, direct, allocated, total, by cost pool, retained row; filters target_type, fiscal_year_id, from/to period, basis.
    - `CapitalisationRegisterReport` (`capitalisation_register`): code, title, source, standard, asset, available-for-use, capitalised on, amount, life, method, amortised to date, NBV, amortisation by FY (current and next 4 FYs as columns).
    - `ApplicationTcoReport` (`application_tco`): subject, label, direct, allocated, cloud, total, labour, contracts memo, EA modelled, coverage.
    - Reports total **14**. Money columns are typed `money`, so the existing masking applies.
12. **Count updates (Task B owns):**
    - `tests/Feature/Workflows/WorkflowStudioModuleTest.php:93` 20 → 21.
    - `tests/Feature/Finance/FinanceWorkflowStudioTest.php`: 9 → 10 at :67 and :197, 20 → 21 at :197, plus `assertCount(2, $wfs['finance.capitalisation_approval']->stages)`.
    - `RouteSmokeTest` counts are Task E's.
13. **Tests:** `tests/Feature/Finance/FinOpsWorkflowIntegrationTest.php` (new, ~14):
    - Capitalisation through the Studio inbox: finance@ → cfo@ for ≥ 50m; finance@ only below. `requires_cfo` routes the stages.
    - SoD: preparer, creator, manager and expense requester refused; nobody decides two stages.
    - Unconfigured default approver.
    - `fin:cloud-anomalies --date=` twice → one notification per new anomaly to the owner + Finance Controller; nothing for users without `view it-finance`.
    - `CloudAnomalyNotification` renders mail + database without amounts.
    - KRI-FIN-CLD measures through `kri:measure`.
    - Dashboard `metrics.cloud_mtd`, `cloud_anomalies_open`, `cloudTrend` (masked for no-amounts).
    - Period-close `cloud_imported` pass/warn/n_a and the config fail mode.
    - Close freezes allocation results (a later `run()` skips the period).
    - The 4 reports export CSV/XLSX/PDF.
    - `CostModelService::tco()` carries `measured_ngn` when finance data exists and null otherwise, and `total_ngn` is unchanged.
    - Import completion notification.

Run B2: `--filter 'FinOpsWorkflowIntegrationTest|FinanceP5CarryOverTest|FinanceWorkflowStudioTest|WorkflowStudioModuleTest|PeriodCloseTest|MonitoringWorkflowIntegrationTest|FinanceReportsTest|KriDashboardsModuleTest|EaDepthAndScaleTest|CalendarTest|InvestmentWorkflowIntegrationTest'`.

---

## 4. Task C: frontend (fin-frontend-engineer, `npm run build`, parallel with Task A)

**Pages (new):**
- `resources/js/Pages/Finance/Cloud/Costs.jsx`:
  - `CloudNav` (Costs · Untagged · Anomalies · Accounts).
  - Filters bar; KPI strip (MTD, prior MTD, untagged %, days); daily stacked area chart (tagged vs untagged); group-by table with share bars; ledger reconciliation card.
  - Tab `untagged`: queue table with an "Assign" action → `AssignUntaggedModal` (dimension, target picker, "also create a tag rule" with tag key select) → `finance.cloud.untagged.assign`.
- `Cloud/Accounts.jsx`:
  - Accounts table (provider chip, identifier, owner, default cost centre, MTD, untagged %, open anomalies, last usage date, last batch).
  - `CloudAccountFormModal` (provider, identifier, name, default format, currency, default cost centre, owner, contract, tag mapping editor of key → dimension rows).
  - `CloudImportPanel` (account, format, file) → `finance.cloud.import` (multipart; reuse `ImportReport.jsx` for `flash.import_report`).
  - `TagRulesPanel` (CRUD); "Re-attribute open periods" → `finance.cloud.accounts.reallocate`; recent batches list linking `finance.imports.show`.
- `Cloud/Anomalies.jsx`:
  - Status tabs with counts; table (detected on, account, scope label, baseline vs observed, deviation % chip, method, status chip, owner).
  - Acknowledge / Resolve / Dismiss modals (note/reason ≥ 10) per `can`.
  - Thresholds caption.
- `Budgets/Allocations.jsx`:
  - `BudgetsNav` + "Allocations".
  - Rules table grouped by lens (target type), with a coverage bar per source (allocated % with retained remainder).
  - `AllocationFormModal`: name, source type + source picker, target type + target picker (business units from `targets.business_unit`), method, percentage or driver + quantity, effective from/to, journal cost-centre code.
  - End-rule modal; delete (only `can.delete`).
  - `RunAllocationsModal` (FY, periods multi, amount types) → `finance.allocations.run`.
  - Runs table showing frozen periods.
- `Monitor/Showback.jsx`:
  - `MonitorNav` + Showback.
  - Filters (FY, period range, lens, basis).
  - Banner when `enabled = false`.
  - Statement table: target, direct, allocated, total, budget, variance, per-pool columns collapsible; a retained row; a reconciliation chip (green "Reconciles to IT total" / red with difference).
  - Row click → detail drawer (`detail` via `router.reload({only:['detail'], data:{target}})`).
  - Cloud memo card. Export buttons (csv/xlsx/pdf).
  - Chargeback card: period select (closed periods only), lens, preview count, Export → `finance.chargeback.store` (a full-page form post so the download works); runs list with download/void (`VoidRunModal`).
- `Monitor/Tco.jsx`:
  - `MonitorNav` + "Application TCO".
  - Filters (FY, subject toggle disabled when `subjectAvailable[x]` is false, basis, search).
  - Totals strip (TCO total, IT total, coverage %, unattributed).
  - Table: label, code, criticality/lifecycle chips, direct, allocated, cloud, total, labour, contracts memo, EA modelled, Δ vs model %.
  - Stacked bar by cost pool for the top 15.
  - Row → `TcoDetailDrawer` (axios GET `finance.monitor.tco.detail`).
  - `AttributeSpendModal` (search `unattributed`, multi-select lines, pick subject → `finance.budgets.lines.classify`).
  - Rationalisation panel (FBN; hidden when empty).
- `Capitalisations/Index.jsx`:
  - Summary tiles; filters; table (code, title, source link, standard chip, status chip, amount, life/method, available-for-use, NBV today, asset link).
  - "Candidates" panel listing `candidates` with "Capitalise" → `CapitalisationFormModal` (prefilled source; title, standard, available-for-use date, amount ≤ remaining with a live hint, excluded amount + reason, residual, life months, method + declining rate, create asset / link existing asset picker, asset name/type/owner) → `finance.capitalisations.store` → redirect to Show.
- `Capitalisations/Show.jsx`:
  - Header with status, actions per `can` (Submit, Withdraw, Approve, Reject, Cancel, Edit when draft/rejected).
  - Checklist panel (each item: tick + note + optional document upload via `finance.documents.store` subject `capitalisation`; editable in draft).
  - Eligibility card (pool, already capitalised, remaining, contributing actuals list).
  - Amortisation schedule table (month, period, opening, amount, closing, catch-up badge) + by-year bar chart.
  - Asset card; `ApprovalTrail`; documents; activity.

**Components** (new, `resources/js/Components/Finance/FinOps/`): `CloudNav`, `ProviderChip`, `CloudAccountFormModal`, `TagMappingEditor`, `CloudImportPanel`, `TagRulesPanel`, `AssignUntaggedModal`, `CloudCostChart`, `LedgerReconCard`, `AnomalyActionModal`, `DeviationChip`, `AllocationFormModal`, `CoverageBar`, `RunAllocationsModal`, `ShowbackTable`, `ShowbackDetailDrawer`, `ChargebackCard`, `VoidRunModal`, `TcoTable`, `TcoDetailDrawer`, `AttributeSpendModal`, `PoolStackChart`. In `resources/js/Components/Finance/Capitalisations/`: `CapitalisationFormModal`, `ChecklistPanel`, `EligibilityCard`, `ScheduleTable`, `CapStatusChip`. Masked (null) money renders "Hidden"; charts are hidden with a note.

**Edits:**
- `resources/js/Config/navigation.js`: "Cloud & FinOps" (`finance.cloud.costs`) after "Budget Monitor"; "Capitalisation" (`finance.capitalisations.index`) after "Investments & Value"; both `view it-finance`.
- `resources/js/Components/Finance/Monitor/MonitorNav.jsx`: + Showback (`finance.monitor.showback`), + Application TCO (`finance.monitor.tco`).
- `resources/js/Components/Finance/Budgets/BudgetsNav.jsx`: + Allocations (`finance.allocations.index`).
- `resources/js/Pages/Finance/Settings.jsx` + new `resources/js/Components/Finance/Settings/FinOpsTab.jsx`: anomaly %, abs floor, showback units editor (code, name, ERP cost centre), chargeback debit/credit GL → `PUT finance.settings.update` with `section: 'finops'`.
- `resources/js/Pages/Finance/Dashboard.jsx`: "Cloud" card from `metrics.cloud_mtd`, `metrics.cloud_anomalies_open` and `cloudTrend` sparkline; hidden when `cloud_mtd === null && !cloudTrendMasked`.
- `resources/js/Pages/Finance/Imports/Show.jsx`: render `cloudSummary` when `batch.type === 'cloud'`.
- `resources/js/Pages/Finance/Investments/Create.jsx` + `Show.jsx` overview: `is_capitalisable` toggle and `capitalisation_standard` select; Show budget tab lists `capitalisations[]` with a "Capitalise" link to `finance.capitalisations.index?source=investment&id=`.
- `resources/js/Components/Finance/Investments/ChangeRequestModal.jsx`: a kind toggle (Scope / Reassign). The reassign section has selects for sponsor, manager, cost centre, rgt and regulatory, plus a reason. It posts `kind`, `field_changes`.
- `ChangesPanel.jsx`: show `kind` and the from → to labels.
- `resources/js/Utils/finance.js`: `providerLabel`, `anomalyTone`, `capStatusTone`, `lensLabel`.
- `resources/js/Pages/Finance/Reports/Index.jsx`: only if groups are hard-coded, add "Cloud & FinOps" and "Capitalisation".

---

## 5. Task E: QA (fin-qa-engineer, DB `it_security_risk_fin_qa`, after A, B and C)

### 5.1 `database/seeders/FinanceFinOpsDemoSeeder.php` (new) + `DatabaseSeeder.php`
Call it **right after the second `FinanceContractDemoSeeder` call (line ~156)**, so assets and First Bank's business-service links exist, and before `KriDashboardsDemoSeeder` (line ~185), so KRI-FIN-CLD gets measured.

Per bank (KHB org 2, FBN org 3):
- Guard: skip when a `CloudAccount` exists for the org. `mt_srand(6000 + orgId)`; `Auth::setUser(finance@)`.
- `attempt()` copies `FinanceMonitoringDemoSeeder`: it **logs** warnings and quiets `FinanceApprovalRequested/Decided`.
- Dates are relative to `FinanceClock::today()`. First codes are `{KHB|FBN}-CAP-0001`, `-CBK-0001`, `-ALC-0001`.

1. **Settings:** `showback_units` (both banks): RET Retail Banking, CIB Corporate & Investment Banking, TRS Treasury, OPS Operations, DIG Digital Channels, RSK Risk & Compliance. FBN adds UKS UK Subsidiary and GHS Ghana Subsidiary. Each gets an ERP cost-centre code, e.g. `5100-RET`. `showback_enabled = true`, anomaly 30 % / 250,000, GLs 6950/6951.
2. **Cloud accounts (2 per bank):**
   - KHB: AWS "Digital channels production" (USD, default cost centre `KHB-IT-DIG`, linked to the AWS contract, owner = DIG owner) and Azure "Microsoft 365 & analytics" (USD, `KHB-IT-DC`).
   - FBN: AWS "Digital banking" (`FBN-GT-DIG`) and Azure "Data & analytics platform" (`FBN-GT-DATA`).
   - `tag_mapping` `{CostCentre: cost_centre, Application: ea_application, Service: business_service}`.
   - Tag rules: KHB maps `Service` values onto its business services (e.g. `mobile` → Mobile Banking BS-002, `ussd` → USSD Banking); FBN maps `Application` values onto EA application codes.
3. **Cost lines:** 90 days ending yesterday. Per account 8–10 services and ~25 resources with weekly seasonality and ±8 % noise. About **12 % untagged** spend (resources without tags), with some untagged lines in the open October period so the queue is actionable. Use `CloudCostService::ingestRows()` (appends into closed periods are allowed by P6-2), plus **one real CSV import per bank** of a 3-day restatement from a generated AWS CUR file (via `import()`) with 2 rejected rows (account mismatch, bad cost), so `finance.imports.show` has a cloud batch.
4. **Anomalies:** inject a spike: KHB AWS "Amazon Elastic Compute Cloud" ×3.4 on today−2; FBN Azure "Azure Databricks" ×2.8 on today−3. Then call `CloudAnomalyService::detect()` (lookback covers it). The spike must be **detected, not inserted**. Add one older spike (today−25) that is detected, then `resolve`d (note), and one `dismiss`ed as a false positive. Seed `fin_notification_logs` claims for the older two so the first sweep sends only the new one.
5. **Allocation rules (≥ 8 per bank, all three methods, effective from FY start, inside `whilePeriodsOpen()`):**
   - Business-unit lens:
     - `percentage`: data centre cost centre → RET 35 / CIB 20 / TRS 10 / OPS 20 / DIG 15;
     - `driver`: ITSM / end-user cost centre → units by `HEADCOUNT` quantities;
     - `direct`: the Treasury systems line or cost centre → TRS;
     - `percentage`: network cost centre → units by branches share.
   - Application lens:
     - FBN: core banking cost centre → EA applications Finacle / mobile / internet;
     - KHB: business-service lens instead, core banking cost centre → Finacle Core / Mobile Banking / Internet Banking.
   - `legal_entity` lens: FBN only, UK/Ghana subsidiary cost centres.
   - Run `AllocationService::run()` for FY2026 Jan → current period, `budget` + `actual`.
6. **Line classification:** tag 15–25 FY2026 baseline lines per bank through `BudgetLineClassifier::classify`, matching `LOWER(description) LIKE` keywords. FBN uses `ea_application_id` (Finacle, mobile, internet banking, SWIFT, AML…); KHB uses `business_service_id` (Finacle Core, Mobile Banking, USSD, ATM, Agency Banking…). TCO then has direct figures.
7. **Chargeback:** one exported run (lens business_unit) for the latest **closed** FY2026 period per bank, through `ChargebackService::export` as finance@. FBN also gets one voided run for the period before (voided by finance@, who holds `close it-finance-periods`; cfo@ does not), with a reason, plus a re-export.
8. **Capitalisations (≥ 4 per bank)**, sources chosen from `CapitalisationService::candidates()` where `remaining > 0` (R3):
   - set `is_capitalisable` + standard on the in-delivery and closed investments with capex actuals (KHB-IVT-0001, KHB-IVT-0005, FBN-IVT-0001, FBN-IVT-0009, via `InvestmentService::update`);
   - (a) a closed-investment capitalisation `capitalised` with catch-up lines and a created asset;
   - (b) an in-delivery phased "Release 1" `capitalised` (IAS 38);
   - (c) a capex expense request (hardware, IAS 16) `capitalised`, linked to an **existing** asset;
   - (d) one `submitted`, waiting at the **Finance Controller** stage (prepared by the investment manager, so finance@ can decide; amount ≥ 50m so the CFO stage follows);
   - (e) one `draft`, one `rejected`.
   - Prepare as the manager / expense requester (`Auth::setUser`), decide as finance@ then cfo@. The booking period must be open (the current October period); if it is not, wrap in `whilePeriodsOpen()`.
9. **Self-check:** after each bank, warn (not fail) when any of these is missing: 2 accounts, ≥ 85 days per account, untagged > 0, an open anomaly from the seeded spike, ≥ 8 rules covering all 3 methods, results for every closed period, a statement that reconciles, ≥ 5 TCO subjects > 0, 1 exported chargeback, the 4 capitalisation states, and a pending capitalisation with an eligible decider.

### 5.2 Tests (new unless marked, `tests/Feature/Finance/`, using `BuildsFinOps`)
- **`CloudCostTest`** (~14, FIN-03.09/.10):
  - each format imports;
  - allocation by tag (rule > built-in > default cost centre) and the untagged flag;
  - re-import of the same file refused; restatement replaces open dates and appends/refuses closed dates (P6-2);
  - FX uses the usage-date rate; missing rate rejects the row;
  - untagged assign + rule creation re-attributes open-period lines only; reallocate after a new rule;
  - seeded spike detected (rolling and WoW), floor respected, FX-only move not flagged, idempotent re-detect;
  - lifecycle acknowledge → resolve, dismiss;
  - ledger reconciliation gap;
  - scoped viewer sees only visible cost centres; masking.
- **`ShowbackAndAllocationTest`** (~14, FIN-02.12, FIN-03.12):
  - percentage, driver and direct shares;
  - group sum > 100 refused; mixed methods refused;
  - most-specific source wins (no double allocation);
  - direct attribution excluded from the lens pool;
  - largest-remainder rounding (Σ results = pool × Σ share to the cent);
  - by-cost-pool split;
  - statement **reconciles to the IT total** for budget and actual (and ties to the BvA ledger total incl. unallocated actuals);
  - closed period computed once and frozen; close freezes; effective dates before the first open period refused;
  - chargeback: closed only, balanced, once until voided, void needs `close it-finance-periods`, CSV injection neutralised;
  - `showback_enabled = false` refuses export.
- **`CapitalisationTest`** (~14, FIN-03.13):
  - eligibility = capex actuals ≤ available-for-use, minus already capitalised; amount over remaining refused;
  - checklist complete with notes before submit (IAS 38 7 items, IAS 16 3 items);
  - approval through the process (stage routing by `requires_cfo`); SoD;
  - booking period must be open; catch-up lines;
  - SL and declining schedules persist and sum exactly;
  - asset created with code/owner/EOL, or an existing asset linked (other bank's asset refused);
  - reject → reopen → resubmit; cancel; withdraw;
  - masking; scoped visibility; investment not capitalisable refused.
- **`ApplicationTcoTest`** (~9, FIN-05.10):
  - direct + allocated + FinOps cloud with the ledger `CP-CLD` excluded on the actual basis (no double count); budget basis keeps ledger cloud;
  - labour breakdown; contracts memo not added;
  - business-service subject; `subjectAvailable.ea_application = false` for a bank without apps;
  - EA modelled side-by-side; coverage %;
  - classify (scoping, closed FY refused, audited); masking.
- **`tests/Feature/RouteSmokeTest.php` (edit):**
  - finance process count at :271 9 → 10; reports count 10 → 14;
  - route-param resolvers for `{capitalisation}`, `{chargebackRun}`, `{cloudAccount}`, `{cloudAnomaly}`, `{cloudTagRule}`, `{allocation}` if the GET loop needs them; skip `finance.monitor.tco.detail` (JSON with required query) and `finance.chargeback.download` / `finance.monitor.showback.export` (downloads) in the loop, or resolve them;
  - new `test_finance_finops_estate_is_seeded_and_renders_for_every_demo_tenant`, per bank:
    - 2 accounts with ≥ 85 days each; untagged queue non-empty;
    - ≥ 1 open anomaly whose `detected_on` is the spike day; KRI-FIN-CLD exists with value ≥ 1 after `kri:measure`;
    - ≥ 8 rules using all 3 methods; results for each closed FY2026 period; the showback statement reconciles (`totals.reconciles === true`) for business_unit/actual;
    - 1 exported chargeback run whose CSV is balanced;
    - capitalisations in `capitalised` (≥ 2, each with an `Asset` in the same org and Σ lines = amount − residual), `submitted` (with an eligible decider: finance@ can decide the pending step), `draft`, `rejected`;
    - TCO page: FBN `ea_application` ≥ 5 rows with total > 0; KHB `business_service` ≥ 5 rows;
    - Cloud Costs, Anomalies, Accounts, Allocations, Showback, TCO and Capitalisations Index + one Show render; the cloud `imports.show` renders;
    - org 1 renders every P6 page empty (no errors).

Run: `--filter 'CloudCostTest|ShowbackAndAllocationTest|CapitalisationTest|ApplicationTcoTest|FinOpsBackendSmokeTest|FinOpsWorkflowIntegrationTest'`, then `migrate:fresh --seed` on `it_security_risk_fin_qa` and confirm **no seeder warnings**. No Playwright this phase.

---

## 6. Gate (orchestrator)
1. Full suite on `it_security_risk_test` in the background. Baseline is 763 passed / 7 skipped; expect about 860 (≈ +95 tests, about +4 min).
2. `migrate:fresh --seed` on scratch, with **no seeder warnings**.
3. `SMOKE=1 … RouteSmokeTest`.
4. `npm run build`; Pint on touched files.
5. Tinker on scratch, per bank:
   - the pending capitalisation is decidable by finance@;
   - `php artisan fin:cloud-anomalies` (no `--date`) sends nothing new (history claimed);
   - with `--date` = today+1 after injecting nothing, still nothing;
   - the showback statement reconciles;
   - `CostModelService::tco()` for one FBN app carries `measured_ngn`.

---

## 7. Props contract (Task A ⇄ Task C)
Decimals are strings; dates `Y-m-d`; timestamps ISO-8601; masked values `null`. Every page gets `baseCurrency` and `can.viewAmounts`. `UserMini = {id, name, job_title}`; `Opt = {value, label}`; `Ref = {id, label}`.

**7.1 `Finance/Cloud/Accounts`:**
```
accounts:[{id,provider,account_identifier,name,default_format,currency,owner:UserMini|null,default_cost_centre:{id,code,name},contract:{id,contract_code,title}|null,
           tag_mapping:{[tagKey]:dimension},is_active,last_usage_date,mtd,lines_count,untagged_pct,open_anomalies,
           last_batch:{id,file_name,status,created_at,href}|null,can:{edit,delete,import}}],
tagRules:[{id,cloud_account_id,account_name|null,tag_key,tag_value,dimension,target:Ref|null,hits,last_hit_at,is_active,can:{edit}}],
batches:[{id,file_name,account,format,row_count,accepted_count,rejected_count,status,imported_by,created_at,href}],
options:{providers:[Opt],formats:[Opt+{columns:[string]}],dimensions:[Opt],currencies:[Opt]},
costCentres:[{id,code,name}], applications:[Ref], businessServices:[Ref], contracts:[Ref], users:[UserMini],
can:{create,import,manageRules,viewAmounts}
```
**7.2 `Finance/Cloud/Costs`:**
```
tab:'costs'|'untagged',
filters:{account_id,from,to,group_by:'service'|'account'|'cost_centre'|'ea_application'|'business_service'|'region'|'day',tagged:'all'|'tagged'|'untagged',service,search},
summary:{total,untagged,untagged_pct,days,mtd,prior_mtd},
series:[{date,cost,untagged}],
groups:[{key,label,cost,share_pct,untagged,lines}],
untagged:[{cloud_account_id,account,service,resource_id,region,tags:{[k]:v},cost,lines,first_date,last_date,in_open_period}],
ledger:{period:{id,name,status}|null,finops_total,cloud_ledger_actual,gap,gap_pct},
accounts:[{id,name,provider}], tagKeys:{[accountId]:[string]}, options:{dimensions:[Opt],groupBy:[Opt]},
applications:[Ref], businessServices:[Ref], costCentres:[{id,code,name}],
can:{assign,manageRules,viewAmounts}
```
POST `finance.cloud.untagged.assign` `{cloud_account_id, resource_id, service?, dimension, target_id, create_rule, tag_key?}`.

**7.3 `Finance/Cloud/Anomalies`:**
```
filters:{status,account_id,scope}, counts:{open,acknowledged,resolved,false_positive},
anomalies:{data:[{id,cloud_account:{id,name,provider},detected_on,scope,scope_ref,scope_label,method,baseline_cost,observed_cost,currency,
                  baseline_base_cost,observed_base_cost,deviation_pct,status,owner:UserMini|null,note,acknowledged_by,acknowledged_at,resolved_by,resolved_at,
                  can:{acknowledge,resolve,dismiss}}],links,meta},
thresholds:{pct,abs_floor,baseline_days,lookback_days}, accounts:[{id,name,provider}], options:{statuses:[Opt],scopes:[Opt]}, can:{viewAmounts}
```
**7.4 `Finance/Budgets/Allocations`:**
```
filters:{fiscal_year_id,target_type,source_type,method,active},
rules:[{id,allocation_code,name,source_type,source:Ref,target_type,target:{id|null,ref|null,label},method,percentage,driver:{id,code,name,unit}|null,
        driver_quantity,share_pct,effective_from,effective_to,is_active,journal_cost_centre_code,has_results,can:{edit,end,delete}}],
coverage:[{target_type,source_type,source_id,label,allocated_pct,rules}],
runs:[{id,period:{id,name,status},amount_type,target_type,results_count,pool_total,allocated_total,computed_at,is_frozen}],
sources:{cost_centre:[Ref],category:[Ref],budget_line:[Ref],contract:[Ref]},
targets:{business_unit:[{ref,label}],cost_centre:[Ref],business_service:[Ref],ea_application:[Ref],investment:[Ref],legal_entity:[Ref]},
drivers:[{id,code,name,unit}], fiscalYears:[{id,year_label}], periods:[{id,name,status}], firstOpenDate,
options:{sourceTypes:[Opt],targetTypes:[Opt],methods:[Opt],amountTypes:[Opt]}, can:{manage,run,viewAmounts}
```
**7.5 `Finance/Monitor/Showback`:**
```
filters:{fiscal_year_id,from_period_id,to_period_id,target_type,basis:'actual'|'budget',target}, enabled:bool,
statement:{target_type,pools:[{id|null,code,name}],
           rows:[{target_key,label,direct,allocated,total,budget_total,variance,variance_pct,by_pool:{[poolCode]:amount}}],
           totals:{direct,allocated,total,retained,it_total,reconciles:bool,difference}},
detail:{target_key,label,items:[{kind:'rule'|'direct',source_label,rule_code|null,method|null,period,cost_pool,amount}]}|null,
cloudMemo:[{target_key,label,cost}],
chargeback:{periods:[{id,name,status}],runs:[{id,run_code,period:{id,name},target_type,status,line_count,total_debit,total_credit,exported_by,exported_at,voided_by,voided_at,void_reason,download_url}],
            preview:{period_id,target_type,line_count,total_debit,total_credit,blocked_reason|null}|null},
fiscalYears, periods:[{id,name,status}], options:{targetTypes:[Opt],bases:[Opt]},
can:{export,void,viewAmounts}
```
POST `finance.chargeback.store` `{period_id, target_type}` returns the CSV download. POST `finance.chargeback.void` `{reason}`.

**7.6 `Finance/Monitor/Tco`:**
```
filters:{fiscal_year_id,subject:'ea_application'|'business_service',basis:'actual'|'budget',search},
subjectAvailable:{ea_application:bool,business_service:bool},
rows:[{id,label,code,criticality|null,lifecycle|null,direct,allocated,cloud,total,labour,
       by_pool:{hardware,software,labour,outside_services,facilities,telecom,cloud,other,unclassified},
       contracts_memo,contracts_count,ea_modelled|null,delta_vs_model_pct|null,share_pct,href|null}],
totals:{tco_total,it_total,coverage_pct,unattributed},
rationalisation:[{capability,applications:[{id,label,total}],combined_total}],
fiscalYears, can:{classify,viewAmounts}
```
GET `finance.monitor.tco.detail` → `{subject,id,label,components:{direct:[{line_id,label,cost_centre,amount}],allocated:[{rule_code,source_label,amount}],cloud:[{service,account,cost}],contracts:[{id,contract_code,title,annualised}]}}`; 403 without amounts.
POST `finance.budgets.lines.classify` `{line_ids:[int], ea_application_id?:int|null, business_service_id?:int|null}`. The unattributed lines list comes from `GET finance.monitor.tco` partial reload `only:['unattributed']` with `filters.unattributed=1` → prop `unattributed:[{id,label,cost_centre,current_base_amount}]`.

**7.7 `Finance/Capitalisations/Index`:**
```
filters:{status,source_type,search,source,id},
capitalisations:{data:[CapRow],links,meta},
CapRow={id,capitalisation_code,title,source_type,source:{id,label,href},standard,status,amount,useful_life_months,method,available_for_use_on,capitalised_on,
        nbv_today,amortised_to_date,asset:{id,code,name,href}|null,href},
summary:{by_status:{[status]:int},capitalised_total,nbv_total,amortisation_this_fy},
candidates:[{source_type,id,label,eligible_amount,already_capitalised,remaining,delivered_on,standard_hint}],
assets:[{id,code,name}], users:[UserMini],
options:{standards:[Opt],methods:[Opt],statuses:[Opt],assetTypes:[Opt],checklists:{ias_38:[{key,label}],ias_16:[{key,label}]}},
defaults:{life_months:{ias_38,ias_16}}, can:{create,viewAmounts}
```
**7.8 `Finance/Capitalisations/Show`:**
```
capitalisation: CapRow + {excluded_amount,excluded_reason,residual_value,declining_rate_pct,eligible_amount,booking_period:{id,name}|null,create_asset,asset_name,asset_type,
   asset_owner:UserMini|null,cost_centre:{id,code,name}|null,criteria_checklist:[{key,label,met,note,document_id}],requested_by,requested_at,approved_by,approved_at,
   rejection_reason,cancel_reason,amount_band_label},
schedule:[{line_no,month,period:{id,name,status}|null,opening_nbv,amount,closing_nbv,is_catch_up}],
scheduleByYear:[{year,amount}],
eligibility:{pool,already,remaining,actuals:[{id,posted_on,description,base_amount,href}]},
trail|null, documents:[{id,kind,title,url,uploaded_by,uploaded_at}], activity:[{id,description,causer,at,changes}],
assets:[{id,code,name}], users:[UserMini], options,
can:{edit,submit,withdraw,approve,reject,cancel,viewAmounts}
```
**7.9 Other props:**
- `Finance/Dashboard`: `metrics.cloud_mtd`, `metrics.cloud_anomalies_open`, `cloudTrend:[{date,cost}]`, `cloudTrendMasked:bool`.
- `Finance/Settings`: new prop `finopsPolicy{cloud_anomaly_pct,cloud_anomaly_abs_floor,showback_units:[{code,name,erp_cost_centre_code}],chargeback_debit_gl,chargeback_credit_gl,showback_enabled,defaults:{…}}`.
- `Finance/Imports/Show`: `cloudSummary|null`.
- `Finance/Investments/Show`: `capitalisations[]`; `investment.is_capitalisable`, `investment.capitalisation_standard`; `changes[].kind`, `changes[].field_changes:[{field,label,from,to}]`; `options.reassignFields`, `options.capitalisationStandards`.
- `Finance/Investments/Create`: `options.capitalisationStandards`.

---

## 8. File ownership (no overlaps)

| task | files |
|---|---|
| **A** | `config/finance.php`; migrations `2027_04_01_000001_create_it_finance_cloud_tables.php`, `…000002_rebuild_it_finance_allocation_tables.php`, `…000003_create_it_finance_capitalisation_tables.php`, `…000004_add_finops_settings_columns.php`; models `app/Models/Finance/{CloudAccount,CloudTagRule,CloudCostLine,CloudAnomaly,AllocationRun,ChargebackRun,Capitalisation,AmortisationLine}.php` (new), `Allocation.php`, `AllocationResult.php` (rewrite), `ImportBatch.php`, `FinanceDocument.php`, `Setting.php` (edit); services `app/Services/Finance/{CloudExportParser,CloudCostService,CloudAnomalyService,AllocationService,ShowbackService,ChargebackService,ApplicationTcoService,BudgetLineClassifier,AmortisationSchedule,CapitalisationService}.php` (new), `FinanceSettings.php` (edit); `app/Services/Approvals/ApprovalProcesses.php`; `app/Policies/Finance/FinancePolicy.php`; controllers `app/Http/Controllers/Finance/{CloudAccount,CloudImport,CloudTagRule,CloudCost,CloudAnomaly,Allocation,Showback,Chargeback,Tco,Capitalisation}Controller.php`, `Concerns/{PresentsFinOps,PresentsCapitalisations}.php` (new), `FinanceSettingsController.php`, `FinanceDocumentController.php`, `FinanceImportController.php` (edit); requests (10, §2.5); `routes/web.php`; tests `tests/Feature/Finance/Concerns/BuildsFinOps.php`, `tests/Fixtures/finance/cloud/*.csv`, `tests/Unit/Finance/{CloudExportParserTest,AmortisationScheduleTest,CloudAnomalyEvaluateTest}.php`, `tests/Feature/Finance/FinOpsBackendSmokeTest.php` (new), `tests/Feature/Finance/FinanceTenancyAndRoutesTest.php` (edit) |
| **B** | B1: migration `2027_04_01_000005_investment_reassignment_and_bac_baseline.php`; `app/Models/Finance/{Investment,InvestmentChange,StageGate}.php`; `app/Services/Finance/{InvestmentService,InvestmentChangeService,StageGateService,FinanceApprovals}.php`; `app/Http/Controllers/Finance/InvestmentController.php`, `Concerns/PresentsInvestments.php`; `app/Http/Requests/Finance/{InvestmentFormRequest,InvestmentChangeRequest}.php`; `tests/Feature/Finance/FinanceP5CarryOverTest.php`. B2: `app/Services/Finance/{FinancePolicySeeder,FinanceApprovalNotifier,FinanceMetrics,PeriodCloseService,FinanceKriSource}.php` (edit), `{CloudAnomalySweeper,FinanceImportNotifier}.php` (new); `app/Notifications/Finance/CloudAnomalyNotification.php`; `app/Services/Kri/KriMeasures.php`; `routes/console.php`; `app/Http/Controllers/Finance/FinanceDashboardController.php`; `app/Services/Ea/CostModelService.php`; `config/ea.php`; `app/Services/Finance/Reports/FinanceReports.php` (edit), `Reports/Definitions/{CloudSpend,ShowbackStatement,CapitalisationRegister,ApplicationTco}Report.php` (new); `app/Models/ScheduledReport.php`; tests `tests/Feature/Finance/FinOpsWorkflowIntegrationTest.php` (new), `tests/Feature/Workflows/WorkflowStudioModuleTest.php`, `tests/Feature/Finance/FinanceWorkflowStudioTest.php` (counts) |
| **C** | `resources/js/Pages/Finance/Cloud/{Accounts,Costs,Anomalies}.jsx`, `Budgets/Allocations.jsx`, `Monitor/{Showback,Tco}.jsx`, `Capitalisations/{Index,Show}.jsx` (new); `resources/js/Components/Finance/FinOps/*`, `Components/Finance/Capitalisations/*`, `Components/Finance/Settings/FinOpsTab.jsx` (new); edits `resources/js/Config/navigation.js`, `Components/Finance/Monitor/MonitorNav.jsx`, `Components/Finance/Budgets/BudgetsNav.jsx`, `Pages/Finance/{Settings,Dashboard}.jsx`, `Pages/Finance/Imports/Show.jsx`, `Pages/Finance/Investments/{Create,Show}.jsx`, `Components/Finance/Investments/{ChangeRequestModal,ChangesPanel}.jsx`, `resources/js/Utils/finance.js`, `Pages/Finance/Reports/Index.jsx` (only if needed) |
| **E** | `database/seeders/FinanceFinOpsDemoSeeder.php` (new), `database/seeders/DatabaseSeeder.php`; tests `tests/Feature/Finance/{CloudCostTest,ShowbackAndAllocationTest,CapitalisationTest,ApplicationTcoTest}.php` (new), `tests/Feature/RouteSmokeTest.php` |

**Touched by nobody:** `ApprovalEngine.php`, `WorkflowStudioController.php`, `BudgetAvailability.php`, `BudgetLedger.php`, `MonitoringService.php` (read-only use), `ContractSweeper.php`, `CurrencyService.php`, `Asset.php`/`AssetController.php`, the P0–P5 finance seeders, `AppServiceProvider.php`, `EvidenceController.php` (`financeSubjectTypes()` already hides every `FinanceDocument::subjects()` class).
**Dependencies:**
- Task B1 needs nothing from A except that A's migrations run (`RefreshDatabase`). The `class_exists` guard covers `Capitalisation` in `PresentsInvestments`.
- Task B2 needs A's `CloudAnomalyService::detectForOrg`, `CloudCostService::mtd`, `AllocationService::freeze`, `ApplicationTcoService::measuredFor`, the `ApprovalProcesses` entry, the config keys and `BuildsFinOps`.
- Task C needs only §2.6 and §7.
- Task E needs A, B and C.

---

## 9. §12 acceptance → test

| ID | criterion | test |
|---|---|---|
| FIN-03.09 (S) | cloud import (AWS CUR / Azure / GCP / generic) allocates by tag, flags untagged | `CloudExportParserTest`; `CloudCostTest::test_import_allocates_by_tag_and_flags_untagged`, `::test_each_provider_format_imports`, `::test_restatement_rules` |
| FIN-03.10 (S) | anomaly detected on a seeded spike; alert and KRI reading | `CloudAnomalyEvaluateTest`; `CloudCostTest::test_anomaly_detected_on_seeded_spike`, `::test_fx_move_is_not_an_anomaly`; `FinOpsWorkflowIntegrationTest::test_sweep_notifies_once`, `::test_kri_cloud_anomalies`; RouteSmokeTest estate (seeded spike detected) |
| FIN-02.12 (M) | allocate approved budget to services/BUs/apps by fixed %, driver, direct | `ShowbackAndAllocationTest::test_percentage_driver_direct_shares`, `::test_group_validation`, `::test_most_specific_source_wins` |
| FIN-03.12 (S) | showback statements; chargeback journal | `ShowbackAndAllocationTest::test_statement_reconciles_to_it_total`, `::test_chargeback_closed_period_balanced_once`, `::test_results_frozen_with_period`; `FinOpsWorkflowIntegrationTest::test_close_freezes_allocations` |
| FIN-03.13 (S) | IAS 38 checklist; create/link Asset; amortisation lines | `AmortisationScheduleTest`; `CapitalisationTest::test_eligibility_and_checklist`, `::test_approval_creates_asset_and_schedule`, `::test_catch_up_lines`, `::test_links_existing_asset_in_org_only`; `FinOpsWorkflowIntegrationTest::test_capitalisation_through_studio_inbox` |
| FIN-05.10 (S) | TCO per EA application / service = allocated lines + contracts + cloud + labour | `ApplicationTcoTest::test_components_without_double_count`, `::test_business_service_subject`, `::test_classify_lines`; `FinOpsWorkflowIntegrationTest::test_ea_tco_measured_feed` |
| FIN-04.05 | period close checklist includes cloud import | `FinOpsWorkflowIntegrationTest::test_cloud_close_item` |
| FIN-04.07 / §5.7 | dashboard cloud trend, `cloud_mtd`, `cloud_anomalies_open` | `FinOpsWorkflowIntegrationTest::test_dashboard_cloud_metrics` |
| FIN-04.08 | cloud anomaly count KRI via `kri:measure` | `FinOpsWorkflowIntegrationTest::test_kri_cloud_anomalies` |
| FIN-07.01 | 10 finance processes, defaults, SoD | `FinanceWorkflowStudioTest` (10), `FinOpsWorkflowIntegrationTest::test_default_approver_when_unconfigured`, `::test_capitalisation_sod` |
| FIN-07.03 | `CloudAnomalyNotification` renders mail + database | `FinOpsWorkflowIntegrationTest::test_cloud_anomaly_notification_renders` |
| FIN-07.07 | cloud spend and chargeback statement reports, 3 formats | `FinOpsWorkflowIntegrationTest::test_finops_reports_export` |
| FIN-07.10 | amounts masked | `FinOpsBackendSmokeTest::test_masking`; per-feature masking tests in E |
| Tenancy | orgs 2/3 render, org 1 empty, cross-bank 404 | `RouteSmokeTest` estate test, `FinOpsBackendSmokeTest::test_cross_bank_404`, `FinanceTenancyAndRoutesTest` |
| P5 carry-overs | reassignment, L2, L6, M1, M2, M5 | `FinanceP5CarryOverTest` |

---

## 10. Spec ⇄ codebase conflicts (continuing from P5's C144)

| # | conflict | resolution |
|---|---|---|
| C145 | `Actual.source = cloud_import` implies cloud lines post actuals; that double counts the invoiced cloud spend. | P6-1: FinOps sub-ledger with a ledger reconciliation. **Sign-off.** |
| C146 | Spec is silent on CUR restatement, hourly rows and closed periods. | P6-2. |
| C147 | `tag_mapping` maps tag key → dimension but not values → ids. | Tag rules + built-in code/name match (P6-3). |
| C148 | "Day-over-day / week-over-week" vs §5.3 "rolling 7-day baseline". | Both tests, rolling 7-day mean or WoW, plus an absolute floor (P6-4). |
| C149 | P1 allocation tables and enums differ from §4.2 (no contract/cloud_account source, no business_unit/investment target, methods `fixed_pct/equal`). | Tables empty and unused: guarded drop/recreate in the spec shape (P6-5). **Sign-off.** |
| C150 | Spec has no rule for overlapping sources or directly-tagged spend. | Most-specific source + direct-attribution precedence; results by cost pool (P6-6). |
| C151 | `amount_type = forecast` per period has no source (P4 EAC is per line/FY). | Budget and actual only; forecast deferred (P6-7). |
| C152 | Showback for closed periods vs period immutability. | Compute once, freeze at close (P6-8). |
| C153 | "Business units" have no table; cost-centre `business_unit` values are IT units. | `fin_settings.showback_units` per bank (P6-9). |
| C154 | Chargeback journal format and controls unspecified. | Closed periods, a recorded and balanced run, once until voided (P6-10). |
| C155 | Capitalisation has `approved_by_id` but no §6 process; decision #2 requires Workflow Studio. | New `finance.capitalisation_approval` (21/10 processes) (P6-11). **Sign-off.** |
| C156 | IAS 38 checklist content, cost basis and booking date unspecified. | P6-12. **Sign-off.** |
| C157 | Amortisation lines have `period_id` only; future FYs have no periods; posting would double count capex. | `month` + nullable `period_id`, memo only (P6-13). |
| C158 | `assets` has no cost/life columns; Asset Management is another module. | Finance holds the cost; the asset is created/linked with EOL; Asset Show unchanged (P6-14). |
| C159 | FIN-05.10 "allocated lines + contracts + cloud + labour" double counts (contracts and cloud also appear in actuals). | Direct + allocated + FinOps cloud (ledger cloud excluded on actual basis); labour a breakdown; contracts memo (P6-15). **Sign-off.** |
| C160 | Kano has no EA applications. | TCO subjects include business services. |
| C161 | "Feeds EA rationalisation": EA already has a modelled TCO with its own consumers and tests. | Additive `measured_ngn` only; EA figures untouched (P6-16). |
| C162 | Baseline lines are immutable, but TCO needs application/service tags. | Classification on baseline lines, scoped and audited (P6-17, as C134). **Sign-off.** |
| C163 | Masking of anomaly percentages. | Deviation/untagged/coverage % visible (P6-18). |
| C164 | Spec names no permission for cloud, allocation or capitalisation actions. | P6-19 (cloud uses `import it-finance`, closing P0 S10 for cloud). |
| C165 | Cost-centre-scoped viewers vs org-level FinOps views. | Filtered to visible cost centres (P6-20). |
| C166 | FIN-03.11 (C) commitments/discount utilisation; §4.2 `cloud_account` allocation source. | Out of scope; cloud is attributed by tags directly, so a cloud allocation source is redundant. |
| C167 | Close checklist "cloud imported" could block the books. | Warn, configurable fail (P6-21). |
| C168 | P5 froze control fields with no change path. | Reassignment as an investment change `kind=reassign`, always approved, SoD on old+new (P6-22). **Sign-off.** |
| C169 | `CloudAnomalyNotification` recipients "Finance" undefined; amounts in notifications. | Owner + IT Finance Controller holders with `view it-finance`; no amounts. |
| C170 | KRI definition for cloud anomaly count. | `KRI-FIN-CLD` = open + acknowledged in 30 days; not masked. |
| C171 | Codes for capitalisations and chargeback runs. | `CAP-`, `CBK-`. |
| C172 | Base-currency detection would flag FX moves. | Detect on billing-currency cost (P6-4). |

---

## 11. Risks, open questions, deferrals

### 11.1 P5 deferrals (ATH-FIN-003 §P5): verdicts

| item | verdict | reason |
|---|---|---|
| **Open gap:** no path to reassign sponsor / manager / cost centre / rgt / regulatory flag after approval | **Scope in (B1)** | Showback/allocation by investment and capitalisation SoD depend on live owners and cost centres. It reuses the ICR + `change` gate, so it is cheap. |
| Domain M1 benefit status derived lazily | Defer (P7) | Unrelated to P6 |
| Domain M2 audit text lacks bank prefix on first seeded code | Defer (P7) | Cosmetic |
| Domain M3 DoA/board band on one-off cost, not TCO | Defer (needs a policy decision) | Business-case TCO ≠ application TCO; changing the band basis re-routes live approvals |
| Domain M4 "funded" criterion needs only one tagged line | Defer (P7) | Not touched by P6 |
| Domain M5 closure / benefit-shortfall acceptance unapproved | Defer (P7) | Not touched by P6 |
| Domain M6 gate criteria not re-evaluated at decision | Defer (P7) | Not a P6 dependency; new capitalisation code re-checks the pool at decision |
| Domain M7 readings/financials mutable after closure | Defer (P7) | Capitalisation snapshots its basis |
| Domain M8 EVM edge cases | Defer (P7) | Not touched |
| Domain M9 delivery acceptance self-attested | Defer (P7) | Not touched |
| Domain M10 mixed-unit benefit averaging | Defer (P7) | Not touched |
| Domain M11 BC benefits not linked to the register | Defer (P7) | Not touched |
| Domain M12 job-title substring matching | Defer (P7, platform) | Engine-wide; P6's new CFO stage uses the same title as existing stages, so it adds no new exposure |
| Domain M13 unconfigured workflow single approver | Defer | Spec default behaviour |
| Domain M14 scoring not independent | Defer (P7) | Not touched |
| Domain L1 advance race without row lock | **Covered by security M5 (scope in)** | Same lock |
| Domain L2 negative BAC clamp; tolerance on drifted BAC | **Scope in (B1)** | Same method as the reassignment change; one column |
| Domain L3 fund line keeps delivered, ignores committed | Defer (P7) | Not touched |
| Domain L4 actual tagged to two investments double counted in portfolio AC | Defer (P7) | P6 new code follows "each item counted once" (P6-6); the portfolio fix is separate |
| Domain L5 NPV note / sensitivity | Defer | Feature, not P6 |
| Domain L6 IAS 38 capitalisable flag missing | **Scope in (A column, B behaviour, C UI)** | P6 capitalisation depends on it |
| Domain L7 board threshold config, not per bank | Defer (P7) | Not touched |
| Domain L8 unlimited value-review postponement | Defer (P7) | Not touched |
| Domain L9 delivered end date by position | Defer (P7) | Capitalisation reads `actual_ends_on`, not criteria |
| Security M1 trail leaks amount band | **Scope in (B1)** | P6 adds a new approval trail; one generic fix in `FinanceApprovals::trail` |
| Security M2 untagging lines not scoped | **Scope in (B1)** | P6 adds the same pattern (`BudgetLineClassifier`); fixing both keeps one rule |
| Security M4 benefit targets editable after approval | Defer (P7) | Not touched |
| Security M5 no lock on one pending request per investment | **Scope in (B1)** | Reassignment adds a new request type on the same check |
| Security M6 gate approvers see and score every investment | Defer (P7) | Not touched |
| Security M7 raw/bulk writes outside the audit trail | Defer for P5 code; **rule applied to P6 bulk writes** | Batch-level audit for cost lines/results |
| Security L1–L9, L11 (pickers, link perms, saveLine, orphan files, BC input rules, null causer, self-attested criteria, `$guarded`, sweeper queries) | Defer (P7) | Not P6 dependencies; P6 pickers use visibility-scoped queries |
| Security L10 activity log stores money regardless of amounts | Defer (P7, module-wide) | Capitalisation Show masks the money keys of `activity[].changes` for no-amounts viewers in new code |
| Also deferred from P5 scope: investment API, value reviews in digest, webhook channel, multi-currency BC, Change Management auto-link | Keep deferred | Unchanged |

### 11.2 Sign-off needed
C145/P6-1, C149/P6-5, C155/P6-11, C156/P6-12, C159/P6-15, C162/P6-17, C168/P6-22.

### 11.3 Risks
- **R1 stuck demo approvals.** The pending capitalisation must be prepared by someone other than finance@ (the stage-10 decider) and the cfo@ (stage 20). Prepare it as the investment manager. SMOKE's "pending step has an eligible decider" plus the gate tinker check catch a mistake.
- **R2 swallowed seeder errors.** Use the logging `attempt()`; the gate fails on any warning.
- **R3 thin capex.** On scratch, delivered and benefits-tracking investments have **no capex actuals**. The seeder must take sources from `candidates()` (in-delivery KHB-IVT-0001 / FBN-IVT-0001 phased releases, closed FBN-IVT-0009, approved KHB-IVT-0005 only if in a capitalisable stage, plus capex expense requests). If fewer than 4 candidates have a remaining pool, the self-check warns. QA must then pick capex expense requests rather than post new actuals. **Never post actuals into closed periods to make demo data fit.**
- **R4 period immutability in seeding.** Allocation rules with past `effective_from` and closed-period first runs are created inside `whilePeriodsOpen()`. Cloud lines rely on the P6-2 append rule. Capitalisation booking needs the open October period (KHB's September is re-opened; both banks have Oct–Dec open).
- **R5 SMOKE-only surfaces.** `finance.imports.show` for a cloud batch, and downloads in the GET loop: add resolvers or skips explicitly (P3 lesson).
- **R6 performance.** 90 days × ~25 resources × 2 accounts ≈ 4,500–7,500 lines per bank. Summaries must be SQL `GROUP BY`. TCO and showback must use grouped queries over lines and actuals, not per-line `BudgetLedger` calls; reuse `MonitoringService::lineFigures` once per FY. Allocation `run()` for 9–10 periods × 2 types × ≤ 4 lenses must finish in seconds in the seeder.
- **R7 EA regression.** `CostModelService::tco()` gains keys. Any EA test using `assertSame`/`assertEquals` on the whole array would break, so run `EaDepthAndScaleTest` and `EaPerformanceBenchmarkTest` in B2. If one asserts the exact shape, put the keys under a single nested `measured` key rather than editing EA tests.
- **R8 counts.** Processes 21/10 (B); reports 14 and finance processes 10 in `RouteSmokeTest` (E). Task A must not run the two Workflow Studio tests while B is pending.
- **R9 suite time.** About +95 tests, about +4 min (≈ 30 min total).
- **R10 cross-task file.** `PresentsInvestments` (B) reads `Capitalisation` (A). Guard it with `class_exists` so B1 is green before A lands.

### 11.4 Deferred from P6 scope
- Forecast amount type in allocations (C151).
- FIN-03.11 commitments/discount utilisation (C166).
- Cloud S3/Blob fetch adapters (v1.x per spec §11).
- Capitalisation disposal/impairment and an Asset Show "capitalised" panel (C158).
- Posting amortisation to a GL (export only).
- Cloud items in the daily digest; webhook channel.
- A cloud cost budget (FinOps budgets per account).
- Per-period driver quantities, which are static per rule in P6; change them by ending a rule.
