# ATH-FIN-PLAN-p3: IT Financial Management, Phase P3 (Expenses, procurement, invoices, actuals)

**Spec:** ATH-FIN-001 §3.3 FIN-03.01–.08, .14, .15; §4.3 (expense requests, POs + lines, GRNs + lines, invoices + lines, actuals, recurring, accruals, import batches); §5.2, §5.3 (ActualsService / ReconciliationService only; cloud is P6); §6 `finance.expense_approval`, `finance.invoice_approval`; §7 `UnbudgetedSpendNotification`, `InvoiceDueNotification`, `ImportCompletedNotification`, `fin:recurring-generate`, `fin:invoices-sweep`; §10 Expenses / PurchaseOrders / Invoices / Actuals / Recurring / Accruals (+ Imports) pages; §12 FIN-03 and the FIN-07 items P3 touches.
**Carry-overs folded in:** P2 domain L4 (IFRS 16 lease indicator), P2 C52 (renewal → expense request), P2 FIN-06.10 expenses half of vendor spend and `spend.actual_to_date`, P1 C26 (budget mode `from_actuals` and `py_actual`), P0 D12 (match-tolerance semantics), P0 D14 (IFRS 16 GL accounts). Tax regime D6/C15 stays deferred (C81).
**Branch:** `feature/it-finance-p3` (from P2.1 e1c6ca8).

**Order:** WP-A backend first (schema, services, routes, the props contract in §7). Then WP-B workflow ∥ WP-C frontend. Then WP-D QA. File ownership (§8) has no overlap between B and C.

**DB rule (overrides every command):** never touch `it_security_risk`.
- WP-A, WP-B: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_fin_test php vendor/bin/phpunit --filter <Class>` (B starts only after A hands over, so they never share the DB at the same time).
- WP-D: `it_security_risk_fin_qa`. Reviewers: a throwaway `it_security_risk_<agent>_test` (create with `/opt/homebrew/opt/postgresql@17/bin/createdb`, drop when done; disk is 94 % full, 13 GB free).
- Orchestrator only: suite on `it_security_risk_test`; `migrate:fresh --seed` and `SMOKE=1 … RouteSmokeTest` on `it_security_risk_scratch`.
- WP-C needs no DB: `npm run build`. Pint on touched PHP files only.

**Route collision check (done):** `route:list --except-vendor --json` → 803 routes, 116 `finance.*`. No name or URI contains expense, purchase, invoice, actual, recurring, accrual, receipt or `finance.imports`/`finance.documents`. Existing import routes (`finance.cost-centres.import`, `finance.fx-rates.import`, `finance.budgets.workbook.import`, `ea.bulk-import*`, `asset-discovery.import`) do not clash. **No collisions.**

**State found in code (verified):**
- `fin_settings` already has `overbudget_mode` (KHB `warn`, FBN `block` in the seeded estate), `match_tolerance_pct` (KHB 2, FBN 1), `match_tolerance_abs` (50,000); Settings › General already edits them.
- Current budget = `BudgetLine::current_base_amount` (original + adjustments, P1 M3) and `BudgetLedger::currentByPeriod()`. `BudgetLedger::available()` is "current budget" only and is used by transfers.
- Lines are re-created on every re-baseline (`source_line_id` chain); `BudgetService::resolveBaselineLine()` walks forward. P3 needs the backward walk (lineage ids).
- `fin_contract_renewals.expense_request_id` exists (no FK); `ContractRenewed` event is dispatched on execute and auto-roll.
- `ApprovalEngine` stages take one `condition_field`, compare `(string) $subject->{field}`, support `owner` stages (approver_ref = `$subject->{def.owner}`) and enforce `approval_requests.context.sod_excluded_ids` on every stage. Workflow Studio inbox `mine` and `FinanceApprovalNotifier::approversFor` both require the process `permission`.
- Seeded cost-centre owners hold only **IT Budget Owner** (no approve permission). `cfo@`, `cio@`, `md@`, `headofit@` hold Finance Approver; `finance@` is IT Finance Controller; `procurement@` is Procurement Officer.
- Seeded FY2026: Jan–Jun locked, Jul–Sep closed, Oct–Dec open. 59 (KHB) / 74 (FBN) baseline lines, all with `gl_account_id`, none with vendor/contract; 18 / 23 contracts carry a `budget_line_id`.
- `ContractSweeper::claim(Model, key, offset, users)` is public and idempotent (unique index on `fin_notification_logs`).
- Workflow Studio counts: 16 processes, 5 finance (asserted in `WorkflowStudioModuleTest`, `FinanceWorkflowStudioTest`, `RouteSmokeTest`). P3 makes it 18 / 7.

---

## 0. Decisions taken (P3-1 … P3-16) — the orchestrator records these in ATH-FIN-003

| # | decision |
|---|---|
| P3-1 | **Availability is annual and lineage-aware:** `available = current budget of the line for the FY (BudgetLine::current_base_amount) − committed (open) − actual (posted)`, all base currency, summed over every line id in the line's lineage (backward `source_line_id` walk), so commitments made against an earlier baseline still count after a re-baseline. Period figures are shown for information only. |
| P3-2 | **Committed** = Σ approved-but-uncommitted requests (`base_total_amount`, minus approved invoices booked directly against the request) + Σ open POs (`base_total_amount − Σ approved invoice amount × PO fx_rate`, floored at 0) for PO status `open`/`partially_received`/`received`. Closing a PO or a request releases the remainder. **Actual** = Σ `fin_actuals.base_amount` with `match_status ≠ duplicate`. |
| P3-3 | **Budget cost = subtotal + VAT** (input VAT is not recoverable for a VAT-exempt bank); WHT reduces the payable amount, not the cost. `fin_invoices.wht_tax_type_id` is added. Non-resident WHT, reverse-charge VAT and tax effective dates stay deferred (D6/C15, needs tax counsel). |
| P3-4 | **Over-budget:** checked at submit (excluding the request itself) and re-checked at final approval under `lockForUpdate` on the current baseline line. `warn` ⇒ `overbudget_justification` (≥ `finance.expenses.justification_min` chars) required, `over_budget = true`, UnbudgetedSpend notification; `block` ⇒ refused (`errors.amount`). Unbudgeted (no line) is always allowed with mandatory justification, in both modes. |
| P3-5 | **"Routed to a higher DoA band" (FIN-03.08):** `effective_band` = `amount_band` moved up one band when the request is unbudgeted or over budget. Default expense stages are conditioned on `effective_band`; `amount_band` stays the honest band; both are snapshotted. |
| P3-6 | **Cost-centre owner stage:** an `owner`-type stage on `endorser_id` = cost-centre owner, walking up the cost-centre tree past anyone in `sodExcludedIds()` (requester, creator). Condition `has_endorser = yes`; when no endorser exists the stage is skipped and, if nothing else applies, the P1 zero-step SoD default applies. Expense/invoice approve routes are gated by `view it-finance` + `FinancePolicy::decideExpense/decideInvoice` (process permission **or** being the approver of the current owner-type step). The process flag `owner_stage_without_permission => true` lets the inbox and notifier include that owner. **Needs sign-off** (touches the shared Workflow Studio inbox, scoped by the flag). |
| P3-7 | Spec "10m–50m: + CIO/CFO" → **CFO** (job title "Chief Financial Officer"); one job title per stage. MD/CEO from 50m. |
| P3-8 | **Condition keys are string accessors** (engine compares strings; booleans would be `"1"`/`""`): `capex_opex`, `is_unbudgeted`, `emergency`, `overspend`, `has_endorser`, `has_po`, `high_value` (`yes`/`no`). |
| P3-9 | **Invoice SoD above the band:** "final invoice approver ≠ PO approver" is enforced in `InvoiceService::approve` **on the final step only** (context exclusion would block the cost-centre owner at stage 1). PO approvers = every user who decided an approval step of the expense request + `approved_by_id`. Above `invoice_sod_from` an extra "Finance Approver (high value)" stage guarantees a final decider. Also **payer ≠ approver ≠ submitter** on schedule/pay (§1 three-way match standard). |
| P3-10 | **Match tolerance (P0 D12):** within tolerance iff `|Δ| ≤ pct × expected` **and** `|Δ| ≤ abs` (abs in base, Δ converted at invoice rate); quantity invoiced above received-and-accepted, or a currency different from the PO, is always `variance`; symmetric (under-billing beyond tolerance is a variance too). `variance` cannot be submitted until overridden (reason ≥ 10 chars) → `override`. Non-PO invoices stay `unmatched` and route to the Finance Controller stage. |
| P3-11 | **One budget line and one PO per expense request**; PO lines inherit the request's line; PO total may exceed the approved total only within match tolerance, else "re-approve". Invoice posts **one actual** (header line). |
| P3-12 | **GL import:** saved mappings in a new `fin_import_mappings` table (not `IntegrationConnection`), CSV only, preview then apply; apply is all-or-nothing unless `skip_invalid=1`; same file hash refused unless `force=1`; GL rows whose reference equals an invoice code / vendor invoice no / PO code already posted from an invoice are stored `match_status=duplicate` (`duplicate_of_id`) and excluded from every sum. Auto-match: active `fin_match_rules` (priority order) first, then the cascade contract → vendor+GL → GL → category inside the cost centre's current baseline lines; ambiguous ⇒ unmatched with a reason. Imports run synchronously (≤ `import_max_rows`), no queued job. |
| P3-13 | **Periods:** every posting (invoice approval, GL row, accrual post/reverse, prepayment release) needs an **open** period; invoice `period_id` defaults to the period of `invoice_date`, editable in draft to any open period. The seeder writes Jan–Sep history directly. |
| P3-14 | **Accruals/prepayments (S, shipped minimal):** `propose(period)` creates received-not-invoiced accruals per PO (+ recurring `auto_generate=accrual`); `post` books +actual; `reverse` books −actual in `reversal_period_id`. An invoice with `prepayment_months` posts no P&L actual on approval; it creates N `prepayment_release` rows released by period. Approved invoices cannot be cancelled in P3 (credit notes deferred). |
| P3-15 | **IFRS 16 indicator (L4):** `fin_contracts.lease_assessment` (`not_lease` · `ifrs16_lease` · `short_term_exempt` · `low_value_exempt` · `undetermined`, null = not assessed; contracts of type `lease` with null show "needs assessment"). `is_lease` is copied to expense requests, POs, invoices and actuals from an `ifrs16_lease` contract; UI badge + warning on the invoice that the P&L charge is depreciation + interest (booked in the GL). GL accounts 1850 "Right-of-use assets (IFRS 16)" and 2201 "Lease liabilities (IFRS 16)" are added. No ROU/liability schedule (module is not the ledger, §2). |
| P3-16 | **Renewal → expense (C52):** listener on `ContractRenewed` creates a **draft** request (not submitted) for the successor's first 12 months (or the term if shorter) against the contract's budget line resolved to the current baseline of the FY holding the successor's start, else an unbudgeted draft; sets `renewal.expense_request_id`; idempotent. Expense/invoice CSV imports create drafts only. `from_actuals` mode (C26) uses the prior FY's actuals, annualised by run-rate when the prior FY still has open periods. |

---

## 1. Conventions to copy (verified; same as P1/P2)

| concern | rule |
|---|---|
| Model base | `use FinanceRecord;` (+ `GeneratesSequentialCode`, `SoftDeletes` on main records), explicit `$table`, `$guarded = []`, enum `const`s, casts `decimal:2` / `decimal:8` / `date`. Policy auto-mapped by glob. |
| Codes | `static::nextSequentialCode($org, 'col', 'EXP-')`; prefixes `EXP-`, `PO-`, `GRN-`, `INV-`, `REC-`; demo seeder starts `KHB-EXP-0001` / `FBN-INV-0001`. |
| Transitions | `public const TRANSITIONS = ['action' => ['from' => [...], 'to' => '...']]` on the service; refusals via `LogsFinanceEvents::fail()` (key `workflow`, approval refusals key `approval`); named audit via `audit()`. |
| Approvals | only through `FinanceApprovals` (start / approve / finalised / decline / withdraw / trail / assertZeroStepSoD / assertDefaultApprover). Same approve skeleton as `BudgetService::approve` (configured ⇒ zero-step SoD then `approve()`; unconfigured ⇒ `assertDefaultApprover($s, $by, initiator, null, $permission)`). |
| Money | bcmath strings, scale 2 for amounts, 8 for rates; FX via `CurrencyService::quote/convert` (`spot` at the document date); amounts and base amounts always both stored. |
| Today | `FinanceClock::today()` (Africa/Lagos). |
| Masking | new trait `Concerns/PresentsExpenses::renderExpenses()` mirroring `PresentsContracts` (own key lists, §2.6). CSV import refused without `view it-finance-amounts`. Notifications carry **no amounts**. |
| Unauthenticated contexts | sweeps, commands and listeners use `withoutGlobalScopes()->where('organization_id', …)`. |
| Listeners | auto-discovered `app/Listeners/Finance/*` with typed `handle()`; never registered in a provider. |
| UI | MD3-in-Tailwind, Material Symbols, navy/gold, `Components/Material/{Icon,Chip}`, `PageHeader`, `Components/Approvals/ApprovalTrail.jsx`, `Components/Finance/Budgets/{Money,ActivityList,BandSnapshot}.jsx`, `Components/Finance/{CsvImportForm,ImportReport}.jsx`; no MUI, no `rounded-full` pills, no heroicons; usable at 375 px. **Never chain `.post()`/`.put()` onto `form.transform()`** (Inertia 2.3; `InertiaTransformChainTest`). |

---

## 2. WP-A, backend (fin-backend-engineer, DB `it_security_risk_fin_test`)

### 2.1 `config/finance.php` (append)

```php
'money_tables' => [... existing, 'fin_expense_requests', 'fin_purchase_orders', 'fin_invoices', 'fin_actuals', 'fin_recurring_expenses', 'fin_accruals'],
'gl_accounts'  => [... existing, ['1850', 'Right-of-use assets (IFRS 16)', 'balance_sheet'], ['2201', 'Lease liabilities (IFRS 16)', 'balance_sheet']],
'approval_defaults' => [... existing,
    'expense_head_of_it_from' => 1000000, 'expense_cfo_from' => 10000000, 'expense_md_ceo_from' => 50000000,
    'invoice_sod_from' => 10000000],               // fallback when fin_settings.invoice_sod_from is null
'expenses' => ['justification_min' => 20, 'unbudgeted_band_bump' => 1],
'invoices' => ['due_offsets' => [7, 0], 'overdue_offsets' => [1, 7, 14, 30], 'default_payment_days' => 30, 'override_reason_min' => 10],
'actuals'  => ['max_kb' => 8192, 'date_formats' => ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-M-Y', 'd.m.Y']],
'recurring'=> ['catch_up_max' => 12],
```

### 2.2 Migrations (Schema builder only; `$this->base($t)` = id + `organization_id` index + `created_by_id` FK users nullOnDelete, as in P1)

**`database/migrations/2027_03_01_000001_create_it_finance_expense_tables.php`**

| table | columns (all also `timestamps`) | indexes / FKs |
|---|---|---|
| `fin_expense_requests` | base; `request_code` s32; `title` s200; `description` text null; `fiscal_year_id` FK fin_fiscal_years restrict; `period_id` FK fin_periods null nullOnDelete (expected); `budget_line_id` FK fin_budget_lines null **restrict**; `cost_centre_id` FK fin_cost_centres restrict; `category_id`, `gl_account_id` FK null nullOnDelete; `legal_entity_id` FK legal_entities null; `vendor_id` FK vendors null; `contract_id` FK fin_contracts null; `renewal_id` FK fin_contract_renewals null; `investment_id` ubig null idx (P5); `asset_id` FK assets null; `ea_application_id` ubig null; `is_capex`, `is_emergency`, `is_lease`, `over_budget` bool default false; `needed_by` date null; `currency` char3; `fx_rate` dec(18,8); `fx_rate_type` s16 default spot; `fx_rate_date` date null; `amount` dec(18,2) (net); `tax_type_id` FK fin_tax_types null; `tax_amount`, `total_amount`, `base_amount`, `base_total_amount` dec(18,2) default 0; `justification`, `overbudget_justification` text null; `availability_snapshot` json null; `requested_by_id` FK users restrict; `submitted_at`, `approved_at`, `closed_at` ts null; `approved_by_id` FK users null; `status` s24 default draft; `rejection_reason`, `cancelled_reason` text null; softDeletes | unique(org, request_code); idx(org, status); idx(org, fiscal_year_id, cost_centre_id); idx(budget_line_id) |
| `fin_purchase_orders` | base; `po_code` s32; `expense_request_id` FK restrict; `vendor_id` FK vendors null nullOnDelete; `contract_id` FK null; `cost_centre_id` FK restrict; `budget_line_id` FK null restrict; `fiscal_year_id` FK restrict; `po_date` date; `expected_delivery_on` date null; `currency`, `fx_rate`, `fx_rate_type`, `fx_rate_date`; `subtotal`, `tax_amount`, `total_amount`, `base_total_amount` dec(18,2); `tax_type_id` FK null; `is_capex`, `is_lease` bool; `status` s24 default open; `issued_by_id` FK users; `external_reference` s64 null; `closed_at` ts null; `closed_by_id` FK null; `cancelled_reason` text null; softDeletes | unique(org, po_code); **unique(expense_request_id)** (P3-11); idx(org, status) |
| `fin_purchase_order_lines` | base; `po_id` FK cascade; `line_no` uint; `description` s255; `quantity` dec(18,4); `unit_cost`, `amount` dec(18,2); `received_quantity` dec(18,4) default 0 (accepted, cumulative); `budget_line_id`, `category_id`, `asset_id` FK null | unique(po_id, line_no) |
| `fin_goods_receipts` | base; `grn_code` s32; `po_id` FK restrict; `received_on` date; `received_by_id` FK users; `notes` text null (immutable, no soft delete) | unique(org, grn_code); idx(po_id) |
| `fin_goods_receipt_lines` | base; `grn_id` FK cascade; `po_line_id` FK cascade; `quantity` dec(18,4); `accepted` bool default true; `rejection_reason` s255 null | idx(po_line_id) |
| `fin_invoices` | base; `invoice_code` s32; `vendor_invoice_no` s64; `vendor_id` FK vendors restrict; `po_id`, `contract_id`, `expense_request_id`, `recurring_expense_id`(FK added in 000002) null; `recurring_due_on` date null; `budget_line_id` FK null restrict; `cost_centre_id` FK restrict; `category_id`, `gl_account_id`, `legal_entity_id` FK null; `fiscal_year_id`, `period_id` FK restrict; `invoice_date`, `due_on` date; `received_on` date null; `currency`, `fx_rate`, `fx_rate_type`, `fx_rate_date`; `subtotal`, `tax_amount`, `wht_amount`, `total_amount`, `base_total_amount` dec(18,2); `tax_type_id`, `wht_tax_type_id` FK fin_tax_types null; `match_status` s16 default unmatched; `match_variance_amount`, `match_variance_base` dec(18,2) null; `match_detail` json null; `matched_at` ts null; `match_override_reason` text null; `match_override_by_id` FK null; `match_override_at` ts null; `status` s16 default draft; `submitted_by_id` FK null; `submitted_at`, `approved_at` ts null; `approved_by_id` FK null; `rejection_reason` text null; `scheduled_on` date null; `scheduled_by_id` FK null; `paid_on` date null; `paid_by_id` FK null; `payment_reference` s64 null; `dispute_reason` text null; `disputed_at` ts null; `disputed_by_id` FK null; `cancelled_reason` text null; `is_expected`, `is_capex`, `is_lease` bool; `prepayment_months` smallint null; `description` text null; softDeletes | unique(org, invoice_code); **unique(org, vendor_id, vendor_invoice_no)** (duplicate-invoice control); unique(recurring_expense_id, recurring_due_on); idx(org, status); idx(org, due_on); idx(po_id) |
| `fin_invoice_lines` | base; `invoice_id` FK cascade; `line_no`; `po_line_id` FK fin_purchase_order_lines null nullOnDelete; `description`; `quantity` dec(18,4); `unit_cost`, `amount` dec(18,2); `category_id`, `asset_id` FK null | unique(invoice_id, line_no) |
| `fin_documents` (FIN-03.14) | base; `documentable_type` s; `documentable_id` ubig; `evidence_id` FK evidence cascade; `kind` s24; `title` s200; `sha256` char64 null | idx(documentable_type, documentable_id) |

**`database/migrations/2027_03_01_000002_create_it_finance_actuals_tables.php`**

| table | columns | indexes / FKs |
|---|---|---|
| `fin_import_mappings` | base; `name` s120; `type` s16 default actuals; `columns` json (key → CSV header); `delimiter` s4 default `,`; `date_format` s16 default `Y-m-d`; `decimal_comma`, `is_default` bool; `has_header` bool default true; `default_currency` char3 null | unique(org, type, name) |
| `fin_import_batches` | base; `type` s16 (`actuals` · `expenses` · `invoices` · spec list); `file_name` s255; `file_sha256` char64; `mapping_id` FK null; `row_count`, `accepted_count`, `rejected_count`, `duplicate_count`, `matched_count`, `unmatched_count` uint default 0; `report` json null; `imported_by_id` FK users; `status` s24 (`completed` · `completed_with_errors` · `failed`); `completed_at` ts null | idx(org, type, created_at); idx(org, file_sha256) |
| `fin_match_rules` | base; `name` s120; `priority` smallint default 100; `cost_centre_id`, `gl_account_id`, `vendor_id`, `contract_id` FK null; `description_contains` s120 null; `budget_line_id` FK restrict (target, resolved through lineage at use); `is_active` bool default true; `hits` uint default 0; `last_hit_at` ts null | idx(org, is_active, priority) |
| `fin_recurring_expenses` | base; `recurring_code` s32; `title` s200; `contract_id`, `budget_line_id`(restrict), `vendor_id`, `category_id`, `gl_account_id`, `tax_type_id` FK null; `cost_centre_id` FK restrict; `frequency` s16; `amount` dec(18,2); `currency` char3; `starts_on`, `next_due_on` date; `ends_on`, `last_generated_on` date null; `auto_generate` s20 default expected_invoice; `is_active` bool; `notes` text null; softDeletes | unique(org, recurring_code); idx(org, is_active, next_due_on) |
| `fin_actuals` (immutable ledger; no soft delete) | base; `fiscal_year_id`, `period_id` FK restrict; `posted_on` date; `source` s16 (§4.3 list); `source_ref` s120 null; `posting_key` s96 null; `invoice_id` FK null; `accrual_id` ubig null idx; `import_batch_id` FK null; `gl_account_id`, `category_id`, `vendor_id`, `contract_id`, `asset_id` FK null; `cost_centre_id` FK restrict; `budget_line_id` FK null restrict; `investment_id`, `ea_application_id` ubig null; `description` s255; `amount` dec(18,2) (signed); `currency`, `fx_rate`, `fx_rate_type`, `fx_rate_date`; `base_amount` dec(18,2); `is_capex`, `is_lease` bool; `match_status` s16 default unmatched (`matched` · `unmatched` · `unbudgeted` · `duplicate`); `match_reason` s32 null; `match_rule_id` FK null; `duplicate_of_id` ubig null idx; `reverses_actual_id` ubig null; `matched_by_id` FK null; `matched_at` ts null | **unique(org, posting_key)** (NULLs allowed on all drivers); idx(org, fiscal_year_id, period_id); idx(budget_line_id); idx(org, match_status); idx(org, source, source_ref); idx(invoice_id) |
| `fin_accruals` | base; `fiscal_year_id`, `period_id` FK restrict; `type` s24; `source` s16 (`po` · `recurring` · `prepayment` · `manual`); `dedupe_key` s96 null; `po_id`, `invoice_id`, `recurring_expense_id`, `budget_line_id`(restrict), `gl_account_id` FK null; `cost_centre_id` FK restrict; `description` s255; `currency`, `fx_rate`; `amount`, `base_amount` dec(18,2); `reversal_period_id` FK fin_periods null; `status` s16 default proposed (`proposed` · `posted` · `reversed` · `cancelled`); `posted_actual_id`, `reversal_actual_id` ubig null; `posted_by_id`, `reversed_by_id` FK null; `posted_at`, `reversed_at` ts null | unique(org, dedupe_key); idx(org, period_id, status) |
| then `Schema::table('fin_invoices')` | `$t->foreign('recurring_expense_id')->references('id')->on('fin_recurring_expenses')->nullOnDelete()` | |

**`database/migrations/2027_03_01_000003_p3_lease_indicator_and_invoice_sod.php`** — `fin_contracts`: `lease_assessment` s24 null, `lease_assessment_note` text null; `fin_settings`: `invoice_sod_from` dec(18,2) null. `down()` drops them. No raw SQL. (No FK added to `fin_contract_renewals.expense_request_id`: SQLite cannot add constraints to existing tables; the column stays indexed-by-convention.)

### 2.3 Models `app/Models/Finance/` (new, all `use FinanceRecord`)

| model | traits | constants / casts | relations | accessors / methods |
|---|---|---|---|---|
| `ExpenseRequest` | GeneratesSequentialCode, SoftDeletes | `STATUSES = ['draft','submitted','approved','rejected','committed','partially_received','received','invoiced','closed','cancelled','withdrawn']`, `OPEN_COMMITMENT = ['approved','committed','partially_received','received','invoiced']`, `TERMINAL = ['rejected','closed','cancelled','withdrawn']`, `EDITABLE = ['draft']` | fiscalYear, period, budgetLine, costCentre, category, glAccount, legalEntity, vendor, contract, renewal, asset, taxType, requestedBy, approvedBy, purchaseOrder (hasOne), invoices, documents (morphMany FinanceDocument) | `amount_band`, `amount_band_label`, `effective_band` (P3-5), `capex_opex`, `is_unbudgeted`, `emergency`, `overspend`, `has_endorser`, `endorser_id` (P3-6), `label`, `days_to_needed`; `sodExcludedIds(): int[]` (requested_by, created_by); `approvalBaseAmount(): string` (base_total_amount); `scopeVisibleTo(User)`, `scopeForFiscalYear`, `scopeOpen` |
| `PurchaseOrder` | GeneratesSequentialCode, SoftDeletes | `STATUSES = ['open','partially_received','received','closed','cancelled']`, `OPEN = ['open','partially_received','received']` | expenseRequest, vendor, contract, costCentre, budgetLine, taxType, issuedBy, lines, receipts, invoices, documents | `received_pct`, `label` |
| `PurchaseOrderLine` | — | casts | po, budgetLine, category, asset, receiptLines | `remaining_quantity` |
| `GoodsReceipt` | GeneratesSequentialCode | immutable (`updating`/`deleting` throw) | po, receivedBy, lines, documents | — |
| `GoodsReceiptLine` | — | | receipt, poLine | |
| `Invoice` | GeneratesSequentialCode, SoftDeletes | `STATUSES = ['draft','submitted','approved','rejected','scheduled','paid','disputed','cancelled']`, `MATCH_STATUSES = ['unmatched','matched','variance','override']`, `UNPAID = ['submitted','approved','scheduled','disputed']` | vendor, po, contract, expenseRequest, recurringExpense, budgetLine, costCentre, category, glAccount, fiscalYear, period, taxType, whtTaxType, lines, actual (hasOne, source invoice), prepayments (Accrual), documents, submittedBy, approvedBy | `amount_band`, `amount_band_label`, `has_po`, `high_value` (≥ `invoice_sod_from`, mapped to bands like `FinancePolicySeeder::bandsFrom`), `has_endorser`, `endorser_id`, `payable_amount` (total − wht), `days_to_due`, `is_overdue` (UNPAID minus disputed, `due_on < today`), `label`; `sodExcludedIds()` (submitted_by, created_by); `approvalBaseAmount()` |
| `InvoiceLine` | — | | invoice, poLine | |
| `Actual` | — | `SOURCES` (§4.3), `MATCH_STATUSES`, `MATCH_FIELDS = ['budget_line_id','match_status','match_reason','match_rule_id','matched_by_id','matched_at','duplicate_of_id']`; `updating` refuses dirty keys outside MATCH_FIELDS, `deleting` refuses | period, fiscalYear, invoice, importBatch, glAccount, costCentre, category, budgetLine, vendor, contract, matchRule | `scopeCounted` (match_status ≠ duplicate), `scopeForPeriod`, `scopeForFiscalYear` |
| `ImportMapping` | — | `REQUIRED = ['posted_on','gl_code','cost_centre_code','amount']`, `OPTIONAL = ['description','currency','reference','vendor','contract_code']` | | |
| `ImportBatch` | — | `TYPES`, `STATUSES`; casts report array | importedBy, mapping, actuals | |
| `MatchRule` | — | | budgetLine, costCentre, glAccount, vendor, contract | |
| `RecurringExpense` | GeneratesSequentialCode, SoftDeletes | `FREQUENCIES = ['monthly'=>1,'quarterly'=>3,'semi_annual'=>6,'annual'=>12]`, `AUTO_GENERATE = ['expected_invoice','accrual','none']` | contract, budgetLine, vendor, category, costCentre, glAccount, taxType, invoices, accruals | `days_to_due` |
| `Accrual` | — | `TYPES = ['accrual','prepayment_release']`, `SOURCES`, `STATUSES` | period, reversalPeriod, po, invoice, recurringExpense, budgetLine, costCentre, glAccount | |
| `FinanceDocument` | — | `KINDS = ['quote','purchase_order','goods_receipt','invoice','delivery_note','approval','other']`, `SUBJECTS = ['expense'=>ExpenseRequest::class,'purchase_order'=>PurchaseOrder::class,'goods_receipt'=>GoodsReceipt::class,'invoice'=>Invoice::class]` | documentable (morphTo), evidence | |

**Edits:** `Contract.php` — `LEASE_ASSESSMENTS` const, `is_ifrs16_lease` and `needs_lease_assessment` accessors. `Setting.php` — cast `invoice_sod_from` decimal:2.

### 2.4 Services `app/Services/Finance/` (new unless marked)

**`BudgetLedger` (edit)** — add `lineageIds(BudgetLine $line): array` (backward `source_line_id` walk, ≤ 25 hops, includes `$line->id`). `available()` keeps its P1 meaning; fix its docblock.

**`BudgetAvailability`**
- `forLine(BudgetLine $line, ?Period $period = null, ?int $excludeExpenseId = null): array` → `{line_id, budget, committed, actual, available, used_pct, period: {id,name,budget,actual}|null}` (strings, base). `$line` must be (or resolve via `BudgetService::resolveBaselineLine`) a current-baseline line.
- `committed(array $lineIds, ?int $excludeExpenseId = null): string`, `actual(array $lineIds, int $fiscalYearId, ?int $periodId = null): string` (P3-2). Bulk variant `forLines(Collection $lines): array<int, array>` (two grouped queries) for index pages; no per-row N+1.

**`FinanceTax`** — `compute(string $net, string $currency, ?TaxType $vat, ?TaxType $wht): array{subtotal, tax_amount, wht_amount, total_amount, payable_amount}`; inclusive VAT is extracted (`net = gross / (1 + r)`); rounding to the currency decimals; WHT on the net of VAT.

**`ExpenseService`** (uses FinanceApprovals, BudgetAvailability, CurrencyService, FinanceSettings, BudgetService)

```php
public const TRANSITIONS = [
  'submit'   => ['from' => ['draft'], 'to' => 'submitted'],
  'withdraw' => ['from' => ['submitted'], 'to' => 'withdrawn'],
  'approve'  => ['from' => ['submitted'], 'to' => 'approved'],     // final stage only
  'return'   => ['from' => ['submitted'], 'to' => 'draft'],
  'reject'   => ['from' => ['submitted'], 'to' => 'rejected'],
  'commit'   => ['from' => ['approved'], 'to' => 'committed'],      // ProcurementService::commit
  'cancel'   => ['from' => ['draft', 'approved'], 'to' => 'cancelled'],
  'close'    => ['from' => ['committed', 'partially_received', 'received', 'invoiced', 'approved'], 'to' => 'closed'],
];
// derived by refreshStatus (never by a user): committed → partially_received → received → invoiced
create(array $data, User $by): ExpenseRequest
update(ExpenseRequest $e, array $data, User $by): ExpenseRequest           // draft only
delete(ExpenseRequest $e, User $by): void                                    // draft only, soft
submit(ExpenseRequest $e, User $by): ExpenseRequest
withdraw(ExpenseRequest $e, User $by): ExpenseRequest                        // requester or creator
approve(ExpenseRequest $e, User $by, ?string $note = null): string           // message: next stage or final
returnToDraft(ExpenseRequest $e, User $by, string $note): ExpenseRequest
reject(ExpenseRequest $e, User $by, string $note): ExpenseRequest
cancel(ExpenseRequest $e, User $by, string $reason): ExpenseRequest
close(ExpenseRequest $e, User $by, ?string $note = null): ExpenseRequest     // closes the PO too; releases commitment
refreshStatus(ExpenseRequest $e): ExpenseRequest
createFromRenewal(Renewal $r, Contract $successor): ?ExpenseRequest          // P3-16, idempotent on renewal.expense_request_id
approverIds(ExpenseRequest $e): array                                        // P3-9
```
Rules: `budget_line_id` must be a line of a **baseline** budget in `fiscal_year_id`, same org, visible cost centre (else 422 `budget_line_id`); cost centre/category/GL/legal entity default from the line; FY must be `open` (not `planning`/`closed`); FX `spot` at `needed_by` or today; tax via `FinanceTax`; `is_lease` from contract; unbudgeted ⇒ `justification` ≥ min, `cost_centre_id` and `category_id` required; emergency ⇒ justification required. `submit` stores `availability_snapshot`, `over_budget`, starts `finance.expense_approval`, dispatches `ExpenseFlagged($e, reason)` with reason `unbudgeted` / `over_budget` / `emergency` when applicable. `approve` final ⇒ re-check (P3-4) under lock, then `approved_by_id/approved_at`.

**`ProcurementService`**
```php
public const TRANSITIONS = ['receive' => ['from' => ['open','partially_received']], 'close' => ['from' => ['open','partially_received','received']], 'cancel' => ['from' => ['open']]];
commit(ExpenseRequest $e, array $data, User $by): PurchaseOrder   // data: po_date, expected_delivery_on, external_reference, tax_type_id, lines[{description, quantity, unit_cost, category_id?, asset_id?}]
updateHeader(PurchaseOrder $po, array $data, User $by): PurchaseOrder  // expected_delivery_on, external_reference
receive(PurchaseOrder $po, array $data, User $by): GoodsReceipt   // data: received_on, notes, lines[{po_line_id, quantity, accepted, rejection_reason?}]
close(PurchaseOrder $po, User $by, ?string $note = null): PurchaseOrder
cancel(PurchaseOrder $po, User $by, string $reason): PurchaseOrder    // no GRN and no invoice
refresh(PurchaseOrder $po): PurchaseOrder
remainingCommitmentBase(PurchaseOrder $po): string
```
Rules: commit by anyone with `create it-finance` **except the requester** (SoD); vendor required; PO currency = request currency; FX at `po_date`; PO total vs approved total within tolerance (P3-11); request → `committed`. Receive: `received_on` between `po_date` and today; quantity > 0; cumulative accepted ≤ ordered (over-receipt refused); rejected lines need a reason and add nothing to `received_quantity`; status derived (`partially_received` / `received`); request refreshed. Cancel releases the commitment, request → `approved` (can be re-committed or cancelled).

**`ThreeWayMatcher`** (pure; reads PO/GRN/other invoices) — `evaluate(Invoice $i, string $tolPct, string $tolAbsBase): array{status, expected, invoiced, variance, variance_base, limit, lines: [{po_line_id, description, ordered_qty, received_qty, prior_invoiced_qty, invoiced_qty, po_unit_cost, invoice_unit_cost, expected, amount, variance, flags: string[]}]}`. Per line: `available_qty = accepted_received − qty on other non-cancelled/non-rejected invoices`; `expected = min(invoiced_qty, available_qty) × po_unit_cost`; flags `over_quantity`, `price_change`, `currency_mismatch`, `no_po_line`. Status per P3-10.

**`InvoiceService`**
```php
public const TRANSITIONS = [
  'update'   => ['from' => ['draft']],
  'match'    => ['from' => ['draft']],
  'override' => ['from' => ['draft'], 'match' => ['variance']],
  'submit'   => ['from' => ['draft'], 'to' => 'submitted'],          // match_status matched|override|unmatched(no PO only)
  'withdraw' => ['from' => ['submitted'], 'to' => 'draft'],
  'approve'  => ['from' => ['submitted'], 'to' => 'approved'],
  'return'   => ['from' => ['submitted'], 'to' => 'draft'],
  'reject'   => ['from' => ['submitted'], 'to' => 'rejected'],
  'schedule' => ['from' => ['approved'], 'to' => 'scheduled'],
  'pay'      => ['from' => ['approved', 'scheduled'], 'to' => 'paid'],
  'dispute'  => ['from' => ['draft', 'submitted', 'approved', 'scheduled'], 'to' => 'disputed'],
  'resolve'  => ['from' => ['disputed']],                              // → approved if approved_at, else draft
  'cancel'   => ['from' => ['draft', 'disputed'], 'to' => 'cancelled'], // never after approval (P3-14)
];
capture(array $data, User $by): Invoice     // PO-linked: lines prefilled from PO lines (po_line_id), vendor/currency from PO; then match()
update(Invoice $i, array $data, User $by): Invoice     // re-runs match() and clears an override when amounts/lines change
delete(Invoice $i, User $by): void
match(Invoice $i, ?User $by = null): Invoice
override(Invoice $i, string $reason, User $by): Invoice
submit / withdraw / returnToDraft / reject (as ExpenseService)
approve(Invoice $i, User $by, ?string $note = null): string    // final: SoD (P3-9), period open, then ActualsService::postInvoice or AccrualService::schedulePrepayment; ExpenseService::refreshStatus
schedule(Invoice $i, string $on, User $by): Invoice            // payer ≠ approver/submitter
markPaid(Invoice $i, string $paidOn, string $reference, User $by): Invoice
dispute(Invoice $i, string $reason, User $by): Invoice          // from submitted: FinanceApprovals::withdraw
resolveDispute(Invoice $i, User $by, ?string $note = null): Invoice
cancel(Invoice $i, string $reason, User $by): Invoice
poApproverIds(Invoice $i): array
```
Rules: `vendor_invoice_no` unique per vendor (422 `vendor_invoice_no`); `due_on ≥ invoice_date`; period open; PO-linked invoice uses the PO's line/cost centre/currency; contract-linked inherits line/cc/is_lease; `prepayment_months` 2–36 only when not PO-quantity-based; recurring-expected drafts keep `is_expected` until the number is edited.

**`ActualsService`**
```php
post(array $attrs, User $by): Actual                 // single writer: open period, FX spot at posted_on, posting_key idempotent, auto-match when no line
postInvoice(Invoice $i, User $by): Actual            // posting_key "invoice:{id}", base = invoice base_total_amount (P3-3), is_lease/is_capex copied
previewGl(UploadedFile $f, ImportMapping|array $map, int $orgId): array    // report only, no writes
importGl(UploadedFile $f, ImportMapping|array $map, User $by, bool $skipInvalid = false, bool $force = false): ImportBatch   // dispatches ImportBatchCompleted
match(Actual $a, BudgetLine $line, User $by, bool $remember = false): Actual   // line must be current baseline in the actual's FY and cost centre's tree; remember ⇒ MatchRule
unmatch(Actual $a, User $by): Actual                 // gl_import / manual only; invoice postings are fixed
acknowledgeUnbudgeted(Actual $a, User $by, string $note): Actual
priorYearActual(BudgetLine $priorLine): ?string      // C26: lineage sum of the prior FY; annualised when that FY has open periods
```
Report shape (also `flash.import_report`): `{mode:'preview'|'apply', type, file_name, rows, accepted, rejected, duplicates, matched, unmatched, errors:[{row, field, message}] (≤ 200), sample:[first 20 parsed rows], batch_id|null}`. Row validation: date (mapping format), period found and open, cost centre active by code, GL active by code, plain decimal (allow sign, `decimal_comma`), currency active with a rate, reference ≤ 120, row cap, file ≤ `actuals.max_kb`, row-level duplicate (same `source_ref`, GL, cc, date, amount already imported) counted as `duplicates`.

**`ReconciliationService`**
```php
autoMatch(Actual $a): array{line: ?BudgetLine, reason: string, rule_id: ?int}   // reasons: rule, contract, vendor_gl, gl, category, ambiguous, no_line, unknown_cost_centre
runQueue(int $orgId, User $by): int
candidates(Actual $a, int $limit = 10): array        // [{line_id, label, budget_code, line_no, current_base_amount, score, why}]
duplicateOf(int $orgId, ?string $reference): ?Actual // P3-12
saveRule(array $data, User $by): MatchRule; deleteRule(MatchRule $r, User $by): void
queue(int $orgId, array $filters): Builder           // match_status = unmatched
```

**`RecurringExpenseService`**
```php
save(?RecurringExpense $r, array $data, User $by): RecurringExpense
generateDue(?CarbonInterface $today = null, ?int $orgId = null): int    // fin:recurring-generate; loops next_due_on ≤ today, ≤ catch_up_max per item, stops at ends_on
generate(RecurringExpense $r, CarbonInterface $dueOn, ?User $by = null): Invoice|Accrual|null
nextDue(RecurringExpense $r, CarbonInterface $from): CarbonImmutable
```
Expected invoice: draft, `is_expected`, `vendor_invoice_no = "EXPECTED-{recurring_code}-{Ymd}"`, `invoice_date = due`, `due_on = due + contract.payment_terms_days ?? invoices.default_payment_days`, period = period of due or the next open one, `created_by_id` = recurring creator. Idempotent by unique(recurring_expense_id, recurring_due_on) and `dedupe_key "rec:{id}:{Y-m-d}"`.

**`AccrualService`** — `propose(Period $p, User $by): int`, `store(array $data, User $by): Accrual` (manual), `post(Accrual $a, User $by): Accrual`, `reverse(Accrual $a, User $by): Accrual`, `postAll(Period $p, User $by): int`, `releasePrepayments(Period $p, User $by): int`, `schedulePrepayment(Invoice $i, User $by): array`, `cancel(Accrual $a, User $by): Accrual` (proposed only). Received-not-invoiced = accepted qty × PO unit cost − invoiced on submitted/approved invoices, at the PO rate; `dedupe_key "po:{id}:{period}"`; `reversal_period_id` = next regular period. Prepayment rows `dedupe_key "pre:{invoice}:{n}"`, amounts spread with `BudgetPhasing::spread` (remainder to last).

**`FinanceDocuments`** — `attach(Model $subject, array $data, UploadedFile $file, User $by): FinanceDocument` (copy of `ContractService::addDocument`: sha256, `evidence/{org}/finance/`, random name, Evidence row with `evidenceable_type` = subject class).

**`FinanceTransactionImporter`** — `expenses(UploadedFile, User, bool $apply): array`, `invoices(UploadedFile, User, bool $apply): array`; fixed headers (expenses: `title, cost_centre_code, budget_line_ref (BUDGETCODE:line_no), category_code, vendor, amount, currency, tax_code, is_capex, needed_by, justification`; invoices: `vendor_invoice_no, vendor, po_code, contract_code, invoice_date, due_on, currency, subtotal, tax_code, wht_code, cost_centre_code, budget_line_ref, description`); creates drafts via the services; ImportBatch + `ImportBatchCompleted`.

**Edits to P1/P2 services**
- `FinanceApprovals.php`: `isCurrentOwnerApprover(string $process, Model $subject, User $u): bool` (pending request, current step `approver_type='owner'`, `approver_ref == $u->id`). In `snapshot()` only add `contract_owner_id` when the subject has that attribute.
- `BudgetService.php` (C26): `createVersion` mode `from_actuals` clones prior-FY baseline lines with `annual_amount = priorYearActual()` (lines with no actuals dropped), phased with the prior line's weights; `refreshPriorYear` also sets `py_actual`.
- `BudgetTransferService.php`: refuse a transfer above the **source line's availability** (`BudgetAvailability::forLine`) at submit and at apply ("budget already committed or spent").
- `ContractService.php`: `lease_assessment` in the editable fields (validated against `LEASE_ASSESSMENTS`; not a frozen material field).
- `FinanceSettings.php`: `invoice_sod_from` in the `doa` section (nullable, `DOA_AMOUNT` regex); `invoiceSodFrom(int $orgId): string` with config fallback.

### 2.5 Events (new, `app/Events/Finance/`) — WP-B listens
- `ExpenseFlagged(ExpenseRequest $expense, string $reason)` — reason `unbudgeted` · `over_budget` · `emergency`.
- `ImportBatchCompleted(ImportBatch $batch)`.

### 2.6 Controllers, requests, masking `app/Http/Controllers/Finance/`

New: `ExpenseController`, `PurchaseOrderController`, `InvoiceController`, `ActualsController`, `RecurringExpenseController`, `AccrualController`, `FinanceImportController`, `FinanceDocumentController`, `Concerns/PresentsExpenses.php`. Every action: `Gate::authorize(...)`, then the service, then `back()/redirect()->with('success', …)`; refusals surface as `errors.*`. Index queries apply `FinancePolicy::visibleCostCentreIds`.

`PresentsExpenses`: `renderExpenses($component, $props)` sets `can.viewAmounts` and masks without `view it-finance-amounts`. Money keys: `amount, base_amount, tax_amount, total_amount, base_total_amount, subtotal, wht_amount, payable_amount, unit_cost, match_variance_amount, match_variance_base, expected, invoiced, variance, variance_base, limit, budget, committed, actual, available, used_pct, current_base_amount, remaining_commitment_base, invoiced_amount, amount_band, amount_band_label, effective_band, unpaid_base, overdue_base, committed_base, unbudgeted_base, posted_base, unmatched_base, proposed_base, reversed_base, prepaid_balance_base, annual_run_rate_base, base_amount_est, open_commitment_base`; lists: `ageing, by_source, availability_snapshot, sample`; plus `band` as in P1. Row builders: `expenseRow/expenseFull/poRow/poFull/invoiceRow/invoiceFull/actualRow/accrualRow/recurringRow/batchRow/documentRows` (shapes in §7).

Form requests `app/Http/Requests/Finance/`: `ExpenseFormRequest`, `CommitPurchaseOrderRequest`, `ReceiveGoodsRequest`, `InvoiceFormRequest`, `ImportActualsRequest` (file mimes csv,txt; `mapping_id` or inline `columns`; `mode` preview|apply), `RecurringExpenseFormRequest`. Others validate inline. Money fields: P0 plain-decimal regex before bcmath.

`FinancePolicy.php` (edit): abilities `createExpense`, `updateExpense` (edit + visible + not terminal), `decideExpense` (sameOrg ∧ (`approve it-finance` ∨ `FinanceApprovals::isCurrentOwnerApprover`)), `procure` (`create it-finance`), `captureInvoice` (`create it-finance`), `decideInvoice` (sameOrg ∧ (`approve it-finance-invoices` ∨ owner step)), `payInvoice` (`approve it-finance-invoices`), `importActuals` (`import it-finance` ∧ `view it-finance-amounts`), `manageAccruals` (`close it-finance-periods`). `costCentreVisible` extends to the new models: ExpenseRequest/Invoice/Actual/RecurringExpense/Accrual by `cost_centre_id`; PO/GRN via the PO; FinanceDocument via its subject; requester and submitter always see their own; **Procurement Officer sees all procurement records** (as contracts, C70).

`EvidenceController.php` (edit, 2 lines): `hideFinanceDocument` / `notFinanceDocument` hide every `App\Models\Finance\%` evidenceable type (extends P2-8). `ContractController`/`PresentsContracts` (edit): `vendorSpend` adds `expense_actual_base` (Σ counted actuals by vendor, current FY) and Show `spend.actual_to_date` (Σ actuals with `contract_id`); `lease_assessment`, `is_ifrs16_lease`, `needs_lease_assessment` in ContractFull; `leaseAssessments` in `optionsPayload()`. `StoreContractRequest`: `lease_assessment` nullable in list.

### 2.7 Routes `routes/web.php` (inside the existing `finance.` group, after the P2 block; `use` lines added)

All under `permission:view it-finance`. `{x}` → `->whereNumber()`. Bracket = extra middleware.

```
GET    /expenses                         expenses.index
GET    /expenses/availability            expenses.availability          JSON (budget_line_id, amount, currency, exclude)
GET    /expenses/create                  expenses.create                [create it-finance]
POST   /expenses                         expenses.store                 [create it-finance]
POST   /expenses/import                  expenses.import                [import it-finance]   mode=preview|apply
GET    /expenses/{expense}               expenses.show
GET    /expenses/{expense}/edit          expenses.edit                  [edit it-finance]
PUT    /expenses/{expense}               expenses.update                [edit it-finance]
DELETE /expenses/{expense}               expenses.destroy               [edit it-finance]
POST   /expenses/{expense}/{submit|withdraw|cancel|close}   expenses.{action}   [edit it-finance]
POST   /expenses/{expense}/{approve|return|reject}          expenses.{action}   (policy decideExpense)
POST   /expenses/{expense}/commit        expenses.commit                [create it-finance]
GET    /purchase-orders                  purchase-orders.index
GET    /purchase-orders/{purchaseOrder}  purchase-orders.show
PUT    /purchase-orders/{purchaseOrder}  purchase-orders.update         [edit it-finance]
POST   /purchase-orders/{purchaseOrder}/{receive|close|cancel}  purchase-orders.{action}  [edit it-finance]
GET    /invoices                         invoices.index
GET    /invoices/create                  invoices.create                [create it-finance]   ?po_id|expense_id|contract_id
POST   /invoices                         invoices.store                 [create it-finance]
POST   /invoices/import                  invoices.import                [import it-finance]
GET    /invoices/{invoice}               invoices.show
GET    /invoices/{invoice}/edit          invoices.edit                  [edit it-finance]
PUT    /invoices/{invoice}               invoices.update                [edit it-finance]
DELETE /invoices/{invoice}               invoices.destroy               [edit it-finance]
POST   /invoices/{invoice}/{match|override|submit|withdraw|dispute|resolve|cancel}  invoices.{action}  [edit it-finance]
POST   /invoices/{invoice}/{approve|return|reject}   invoices.{action}  (policy decideInvoice)
POST   /invoices/{invoice}/{schedule|pay}            invoices.{action}  [approve it-finance-invoices]
GET    /actuals                          actuals.index                  ?tab=posted|queue|rules|mappings
GET    /actuals/{actual}/candidates      actuals.candidates             JSON
POST   /actuals/import                   actuals.import                 [import it-finance]   mode=preview|apply
POST   /actuals/auto-match               actuals.auto-match             [import it-finance]
POST   /actuals/{actual}/{match|unmatch|unbudgeted}  actuals.{action}   [import it-finance]
POST   /actuals/mappings  · DELETE /actuals/mappings/{mapping}   actuals.mappings.store/destroy  [import it-finance]
POST   /actuals/rules     · DELETE /actuals/rules/{rule}         actuals.rules.store/destroy     [import it-finance]
GET    /recurring                        recurring.index
POST   /recurring                        recurring.store                [create it-finance]
PUT    /recurring/{recurringExpense}     recurring.update               [edit it-finance]
DELETE /recurring/{recurringExpense}     recurring.destroy              [edit it-finance]
POST   /recurring/{recurringExpense}/generate   recurring.generate      [edit it-finance]
GET    /accruals                         accruals.index
POST   /accruals                         accruals.store                 [close it-finance-periods]
POST   /accruals/propose                 accruals.propose               [close it-finance-periods]   period_id
POST   /accruals/release-prepayments     accruals.release-prepayments   [close it-finance-periods]   period_id
POST   /accruals/{accrual}/{post|reverse|cancel}   accruals.{action}    [close it-finance-periods]
GET    /imports                          imports.index
GET    /imports/{importBatch}            imports.show
POST   /documents                        documents.store                [create it-finance|edit it-finance]  multipart subject, subject_id, kind, title, file
GET    /documents/{document}/download    documents.download             (policy view on the subject)
```
About 62 routes. Literal segments (`availability`, `create`, `import`, `auto-match`, `propose`) are declared before `{param}` routes.

### 2.8 Approval processes `app/Services/Approvals/ApprovalProcesses.php` (WP-A adds them because the services cannot start without them; WP-B owns the default stages)

```php
'finance.expense_approval' => [
  'module' => 'IT Financial Management', 'label' => 'Expense request', 'engine' => 'generic', 'owner_cannot_approve' => true,
  'owner_stage_without_permission' => true,
  'description' => 'An expense request is approved against the delegation of authority before it is committed (FIN-03.02).',
  'permission' => 'approve it-finance', 'view_permission' => 'view it-finance', 'model' => ExpenseRequest::class,
  'initiator' => 'requested_by_id', 'owner' => 'endorser_id', 'title' => ['request_code', 'title'],
  'route' => 'finance.expenses.show', 'initiate' => 'Submitting an expense request',
  'conditions' => [
    'amount_band'    => ['label' => 'Amount band (DoA)', 'values' => 'finance.doa_bands'],
    'effective_band' => ['label' => 'DoA band after unbudgeted / over-budget escalation', 'values' => 'finance.doa_bands'],
    'capex_opex'     => ['label' => 'CapEx / OpEx', 'values' => ['capex' => 'CapEx', 'opex' => 'OpEx']],
    'is_unbudgeted'  => ['label' => 'Unbudgeted (no budget line)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    'emergency'      => ['label' => 'Emergency', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    'overspend'      => ['label' => 'Over the available budget', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    'has_endorser'   => ['label' => 'Has a cost-centre endorser', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    'category_id'    => ['label' => 'TBM category', 'values' => 'finance.categories'],
    'cost_centre_id' => ['label' => 'Cost centre', 'values' => 'finance.cost_centres'],
  ],
  'default' => 'One approval by anyone with "approve it-finance" other than the requester and whoever created the request.',
],
'finance.invoice_approval' => [
  ... 'label' => 'Invoice approval', 'owner_cannot_approve' => true, 'owner_stage_without_permission' => true,
  'description' => 'A vendor invoice is approved, after the three-way match, before the actual is posted (FIN-03.04).',
  'permission' => 'approve it-finance-invoices', 'view_permission' => 'view it-finance', 'model' => Invoice::class,
  'initiator' => 'submitted_by_id', 'owner' => 'endorser_id', 'title' => ['invoice_code', 'vendor_invoice_no'],
  'route' => 'finance.invoices.show', 'initiate' => 'Submitting an invoice',
  'conditions' => [
    'amount_band'  => [... 'finance.doa_bands'],
    'match_status' => ['label' => 'Three-way match', 'values' => ['matched' => 'Matched', 'variance' => 'Variance', 'override' => 'Variance overridden', 'unmatched' => 'No PO (not matched)']],
    'has_po'       => ['label' => 'Has a purchase order', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    'high_value'   => ['label' => 'High value (invoice SoD band)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    'has_endorser' => [...], 'cost_centre_id' => [...],
  ],
  'default' => 'One approval by anyone with "approve it-finance-invoices" other than the submitter, and above the SoD band not an approver of the purchase order.',
],
```

### 2.9 Backend tests owned by WP-A
- `tests/Feature/Finance/Concerns/BuildsExpenses.php` (new): `baselineLine($org, $amount)`, `expense(...)`, `approvedExpense(...)`, `po(...)`, `grn(...)`, `invoice(...)`, `glCsv(array $rows)`, users `requester/owner/headOfIt/cfo/md/financeController/procurement/approverFor($role)`. Used by WP-B and WP-D.
- `tests/Feature/Finance/ExpenseBackendSmokeTest.php` (new): every new GET route 200 for a finance user and 403 without `view it-finance`; cross-tenant 404/403 on show and every write; masking (no amount keys populated for a viewer without `view it-finance-amounts`; import refused); attachments store evidence with sha256 and are hidden from the vault (FIN-03.14); vendor spend includes expenses (FIN-06.10); transfer refused above source availability; actual immutability.
- Count edits only: `tests/Feature/Workflows/WorkflowStudioModuleTest.php` (16 → 18) and `tests/Feature/Finance/FinanceWorkflowStudioTest.php` (16 → 18, finance 5 → 7). `tests/Feature/Finance/BudgetLifecycleTest.php`: the `from_actuals` refusal assertion becomes "refused when the prior FY has no actuals".

---

## 3. WP-B, workflow integration (fin-workflow-integrator, DB `it_security_risk_fin_test`, after WP-A)

### 3.1 `app/Services/Finance/FinancePolicySeeder.php` (edit; `bandsFrom` thresholds from config)
```
finance.expense_approval:
 10 Cost-centre owner endorsement   owner      (endorser_id)              48h  has_endorser   = [yes]
 20 Head of IT approval             job_title  Head of IT                 48h  effective_band ∈ bandsFrom(expense_head_of_it_from)
 30 CFO approval                    job_title  Chief Financial Officer    48h  effective_band ∈ bandsFrom(expense_cfo_from)
 40 MD/CEO approval                 job_title  Managing Director          72h  effective_band ∈ bandsFrom(expense_md_ceo_from)
finance.invoice_approval:
 10 Cost-centre owner confirmation  owner      (endorser_id)              48h  has_endorser = [yes]
 20 Finance Controller review       role       IT Finance Controller      48h  match_status ∈ [variance, override, unmatched]
 30 Finance Approver (high value)   role       Finance Approver           48h  high_value  = [yes]
```
Idempotent per process (existing rule). Note: a 0–1m request whose requester owns the cost centre and whose tree has no other owner gets zero steps → P1 zero-step SoD default (`approve it-finance`, not requester/creator).

### 3.2 Shared approval plumbing (edits)
- `app/Services/Finance/FinanceApprovalNotifier.php`: `url()` adds `finance.expense_approval` → `finance.expenses.show`, `finance.invoice_approval` → `finance.invoices.show`; `approversFor()` keeps an eligible user who lacks the process permission when the step is `owner` type and the process sets `owner_stage_without_permission`.
- `app/Http/Controllers/Workflows/WorkflowStudioController.php` (`inbox`, one condition): `mine` also true for an owner-type current step the user can decide when the process sets `owner_stage_without_permission`. Nothing else in the controller changes.

### 3.3 Notifications `app/Notifications/Finance/` (new; extend `BaseNotification`; scalar ctor args; `$module = 'IT Financial Management'`; **no amounts**)
- `UnbudgetedSpendNotification(string $code, string $title, string $reason, string $costCentre, string $requester, string $url)` — type `finance.unbudgeted_spend`.
- `InvoiceDueNotification(string $invoiceCode, string $vendor, string $dueOn, int $daysLeft, string $url)` — negative `daysLeft` = overdue; type `finance.invoice_due` / `finance.invoice_overdue`.
- `ImportCompletedNotification(string $type, string $fileName, int $accepted, int $rejected, int $unmatched, string $url)` — type `finance.import_completed`.

### 3.4 Listeners `app/Listeners/Finance/` (new, auto-discovered)
- `SendUnbudgetedSpendNotification::handle(ExpenseFlagged $e)` → Finance users (`config('finance.contracts.finance_roles')`) + `FinanceApprovalNotifier::escalationUsers` (Head of IT); claim key `expense_flagged:{reason}` offset 0 (via `ContractSweeper::claim`, injected).
- `SendImportCompletedNotification::handle(ImportBatchCompleted $e)` → importer; url `finance.imports.show`.
- `CreateRenewalExpenseRequest::handle(ContractRenewed $e)` → `ExpenseService::createFromRenewal($e->renewal, $e->successor)` when a successor exists; wrapped in try/catch that logs (never breaks renewal execution).

### 3.5 `app/Services/Finance/InvoiceSweeper.php` (new) — `sweep(?CarbonInterface $today = null): int`
For each org (`withoutGlobalScopes`), invoices in `UNPAID` except `disputed`: if `due_on − today ∈ invoices.due_offsets` or `today − due_on ∈ invoices.overdue_offsets` (catch-up tolerance `contracts.catch_up_days`, smallest offset whose window contains today), recipients = Finance users + cost-centre owner (+ submitter for overdue), `claim($invoice, 'invoice_due:'.$due_on, $offset (negative for overdue), $users)`, then send. Per-org try/catch (P1 L-item).

### 3.6 Calendar and digest (edits)
- `FinanceCalendarService.php`: source `invoices` ("Invoice due dates") in `SOURCES`; event type `invoice_due` (`title = "{invoice_code} {vendor} due"`, colour red if overdue, amber ≤ 7 days, blue otherwise, grey when paid/cancelled, `href finance.invoices.show`, `meta{source:'invoices', invoice_code, vendor, cost_centre, days_left}`; no amounts unless `view it-finance-amounts`, then `base_value`). Respects cost-centre visibility. ICS includes it.
- `FinanceDigest.php`: `invoicesSection($user, $orgId)` — Finance users and cost-centre owners: due in 7 days and overdue counts with codes (no amounts).

### 3.7 `routes/console.php` (edit, IT Financial Management block)
```php
Artisan::command('fin:recurring-generate {--date= : Y-m-d, run as if today}', fn (RecurringExpenseService $s) => $this->info($s->generateDue(...).' generated'));
Schedule::command('fin:recurring-generate')->dailyAt('00:30')->timezone(config('finance.timezone'))->name('finance.recurring-generate')->withoutOverlapping();
Artisan::command('fin:invoices-sweep {--date=}', fn (InvoiceSweeper $s) => …);
Schedule::command('fin:invoices-sweep')->dailyAt('07:30')->timezone(config('finance.timezone'))->name('finance.invoices-sweep')->withoutOverlapping();
```
(`--date` sets `FinanceClock` exactly as `fin:contracts-sweep` does.)

### 3.8 Test owned by WP-B: `tests/Feature/Finance/ExpenseWorkflowIntegrationTest.php` (new)
Defaults seeded on open with the stage table above; expense band routing (0–1m owner only; 5m owner + HoIT; unbudgeted 5m escalates to CFO via `effective_band`; 60m adds MD); owner stage skips a requester-owner and walks to the parent owner; owner appears in inbox `mine` and gets the requested notification without `approve it-finance`, while a non-owner Budget Owner cannot decide; invoice routing (`variance`/`override`/no-PO → Finance Controller; high value → Finance Approver); all three notifications render mail + database and carry no amounts (FIN-07.03); unbudgeted submit notifies Finance + Head of IT once; `fin:invoices-sweep` twice ⇒ one send per offset, overdue series, disputed skipped; `fin:recurring-generate --date` creates one expected invoice per due date and is idempotent on re-run; calendar returns `invoice_due` events with the right colours; digest invoice section; renewal execution creates one draft expense request linked both ways (C52) and auto-roll too.

---

## 4. WP-C, frontend (fin-frontend-engineer, `npm run build`, after WP-A)

**Pages** (`resources/js/Pages/Finance/`, all new): `Expenses/Index.jsx`, `Expenses/Create.jsx` (create + edit), `Expenses/Show.jsx` (tabs: Overview · Approval · Procurement chain (PO → GRNs → invoices → actuals) · Documents · Activity), `PurchaseOrders/Index.jsx`, `PurchaseOrders/Show.jsx`, `Invoices/Index.jsx` (ageing strip), `Invoices/Create.jsx` (create + edit; PO line picker), `Invoices/Show.jsx` (match panel, approval, payment, dispute, prepayment schedule, IFRS 16 banner), `Actuals/Index.jsx` (tabs Posted · Unmatched queue · Rules · Mappings; GL import modal with mapping + preview → apply), `Recurring/Index.jsx`, `Accruals/Index.jsx`, `Imports/Index.jsx`, `Imports/Show.jsx`.

**Components** `resources/js/Components/Finance/Expenses/` (new): `ExpensesNav.jsx` (tab bar: Requests · Purchase orders · Invoices · Actuals · Recurring · Accruals · Imports), `StatusChip.jsx` (expense/PO/invoice/match/accrual tones), `AvailabilityWidget.jsx` (budget/committed/actual/available bar; live via `finance.expenses.availability`, debounced; shows warn vs block message from `settings.overbudget_mode`), `ExpenseForm.jsx`, `DecisionBar.jsx` (approve / return / reject with note, uses `approval` trail `can_decide`), `ProcurementChain.jsx`, `CommitPoModal.jsx`, `ReceiveGoodsModal.jsx`, `InvoiceForm.jsx`, `MatchPanel.jsx` (line table with flags, tolerance limit, override reason modal), `PaymentModal.jsx` (schedule / pay), `DisputeModal.jsx`, `DocumentsPanel.jsx` (generic; `finance.documents.*`), `GlImportModal.jsx` (mapping select or inline column map, `ImportReport` preview, apply with skip_invalid/force), `MatchActualModal.jsx` (fetches `finance.actuals.candidates`; "remember as rule"), `RuleFormModal.jsx`, `MappingFormModal.jsx`, `RecurringFormModal.jsx`, `AccrualFormModal.jsx`, `LeaseBadge.jsx`.

**Edits:** `resources/js/Config/navigation.js` (add "Expenses & Invoices" → `finance.expenses.index`, after Budgets); `resources/js/Utils/finance.js` (tones `EXPENSE_TONES`, `PO_TONES`, `INVOICE_TONES`, `MATCH_TONES`, `ACCRUAL_TONES`, `ACTUAL_MATCH_TONES`); `Pages/Finance/Budgets/Create.jsx` (enable `from_actuals`, help text "uses prior-year actuals, annualised if the year is still open"); `Components/Finance/Contracts/ContractForm.jsx` (lease assessment select + note); `Components/Finance/Contracts/SpendPanel.jsx` (`actual_to_date`, link to the contract's invoices); `Pages/Finance/Contracts/Show.jsx` (LeaseBadge); `Components/Finance/Calendar/calendarUtils.js` (`invoice_due` icon/label); `Components/Finance/Settings/DoaBandsTab.jsx` (`invoice_sod_from` input). Masked amounts (`null`) render "—" with a lock tooltip, as in P1/P2.

---

## 5. WP-D, QA (fin-qa-engineer, DB `it_security_risk_fin_qa`, after B and C)

### 5.1 `database/seeders/FinanceExpenseDemoSeeder.php` (new) + `DatabaseSeeder.php` (call right after the **first** `FinanceContractDemoSeeder` call)
Guard per bank: skip when `ExpenseRequest` rows exist for the org. `mt_srand(3000 + $orgId)`. Calls `FinancePolicySeeder::ensureDefaults($orgId)`. Users: `finance@`, `procurement@`, `cfo@`, `cio@`, `md@`, `headofit@`, `itops@` and the cost-centre owners. Writes `organization_id` explicitly.
1. **Config:** GL 1850/2201 if missing; import mappings "Finacle GL extract" (KHB, `d/m/Y`) / "Oracle EBS GL extract" (FBN, `Y-m-d`, `;`); `lease_assessment`: one `ifrs16_lease` (data-centre/UPS or dedicated-link lease), one `short_term_exempt`, one `lease`-type contract left null (shows "needs assessment").
2. **Actuals Jan–Sep FY2026 (direct inserts, P3-13):** one row per baseline line per month, base = phased current budget × factor; factors give ≥ 3 red (+12…+18 % YTD), ≥ 4 amber (+6…+8 %), 2 under-spent (−15 %) lines per bank (P4 relies on these). Jan–Aug as `gl_import` in one batch "GL extract Jan–Aug"; **Sept** as its own `gl_import` batch with 6–8 `unmatched` rows (ambiguous GL, no line, unknown vendor), 2 `duplicate` rows of Sept invoice postings, and 1 `unbudgeted` acknowledged row; Sept paid invoices posted as `invoice` actuals.
3. **Expenses (≈ 16 per bank), every status:** draft ×2, submitted at owner stage, submitted at Head of IT, approved (not committed), committed (PO open) ×2, partially_received, received, invoiced ×2, closed, rejected, cancelled, withdrawn; **one unbudgeted emergency per bank** (KHB "Emergency replacement of failed core switch, Kano HQ" ≈ ₦18.5m, pending at CFO; FBN "Emergency DDoS scrubbing service" in USD, pending at CFO); KHB (warn mode) one `over_budget` request with justification; one draft from an executed renewal (backfilled via `ExpenseService::createFromRenewal`). Pending items driven through the services as the right users so engine requests are real.
4. **POs / GRNs:** POs for committed+ requests in open, partially_received, received, closed, cancelled; one GRN with a rejected line.
5. **Invoices (≈ 14 per bank):** draft, draft with `variance` (not overridden), submitted at owner, submitted with `override` at Finance Controller, approved unpaid (Oct, actual posted), scheduled, paid (Sept), disputed, rejected, cancelled, **overdue** (approved, `due_on = today − 12`), non-PO contract invoice (Microsoft quarterly), high-value invoice pending at Finance Approver with a PO approver who is shown as excluded, IFRS 16 lease invoice, annual prepaid subscription (`prepayment_months = 12`, releases Jan–Sep posted, Oct proposed), one expected invoice from recurring.
6. **Recurring (6 per bank)** linked to contracts (MTN/Airtel links monthly, Microsoft EA quarterly, Finacle AMC annual, AWS monthly `accrual`, one `none`, one inactive); one due today so `fin:recurring-generate` visibly generates.
7. **Accruals:** Sept received-not-invoiced posted and reversed in Oct; Oct proposed; prepayment rows as above.
8. **Import batches:** actuals ×2 (above) + one invoices batch with rejected rows in its report.
9. **Notification history:** `fin_notification_logs` for the overdue invoice (offsets 7, 0, −1, −7) without sending (P2 Q1 precedent).
10. Every seeded pending expense/invoice request has at least one user who can decide its current step (owner-type steps included).

Periods: service-driven postings use the first **open** period of FY2026 (Oct today); never hard-code October.

### 5.2 Tests `tests/Feature/Finance/` (new, all use `BuildsExpenses`)

**`ExpenseApprovalAndAvailabilityTest`**
- `test_availability_is_current_budget_minus_committed_minus_actual` (incl. a transfer adjustment and an invoice actual) — FIN-03.01
- `test_availability_follows_lineage_after_rebaseline` — FIN-03.01 / P3-1
- `test_block_mode_refuses_over_budget_submission` / `test_warn_mode_requires_justification_and_flags_over_budget` — FIN-03.01
- `test_final_approval_rechecks_availability_in_block_mode` — P3-4
- `test_unbudgeted_requires_justification_is_flagged_and_escalates_one_band` — FIN-03.08
- `test_emergency_requires_justification_and_is_flagged` — FIN-03.08
- `test_doa_bands_route_stages_and_snapshot_band` — FIN-03.02, FIN-01.07
- `test_requester_and_creator_cannot_approve_and_nobody_decides_two_stages` — FIN-03.02, FIN-07.02
- `test_unconfigured_default_needs_approve_it_finance` / `test_zero_step_request_applies_default_sod` — FIN-07.01
- `test_return_reject_withdraw_cancel_transitions_and_commitment_release` — FIN-03.03
- `test_lifecycle_is_activity_logged` — FIN-07.06
- `test_bulk_expense_import_preview_then_apply_creates_drafts` — FIN-03.15

**`ProcurementThreeWayMatchTest`**
- `test_commit_creates_po_with_fx_at_po_date_and_refuses_requester` — FIN-03.03
- `test_po_above_approved_total_beyond_tolerance_is_refused` — P3-11
- `test_partial_and_full_receipt_derive_statuses_and_refuse_over_receipt` — FIN-03.03
- `test_within_tolerance_auto_matches` / `test_outside_tolerance_is_variance_and_cannot_submit` — FIN-03.04
- `test_tolerance_uses_stricter_of_pct_and_abs_and_flags_over_quantity` — P3-10 / D12
- `test_override_needs_reason_and_adds_finance_controller_stage` — FIN-03.04
- `test_invoice_approval_posts_actual_in_invoice_period_once` (incl. closed-period refusal and `posting_key` idempotency) — FIN-03.04 / FIN-03.05
- `test_invoice_final_approver_cannot_be_po_approver_above_band` (and can be below it) — FIN-07.02
- `test_payer_cannot_be_approver_or_submitter` / `test_schedule_pay_dispute_resolve` — FIN-03.04
- `test_duplicate_vendor_invoice_number_refused` — control
- `test_vat_and_wht_computation_and_budget_cost` — FIN-01.06 / P3-3
- `test_lease_contract_flows_ifrs16_flag_to_invoice_and_actual` — L4 / P3-15
- `test_prepaid_invoice_schedules_releases_instead_of_actual` — FIN-03.06
- `test_commitment_relieved_by_invoices_and_released_on_close` — P3-2

**`ActualsImportAndReconciliationTest`**
- `test_gl_import_preview_does_not_write_and_apply_creates_batch` — FIN-03.05 / FIN-03.15
- `test_saved_mapping_with_other_delimiter_date_format_and_decimal_comma` — FIN-03.05
- `test_auto_match_by_rules_then_cascade_leaves_ambiguous_in_queue` — FIN-03.05
- `test_manual_match_unmatch_and_remember_as_rule` — FIN-03.05
- `test_invalid_rows_reject_whole_file_unless_skip_invalid`, `test_same_file_refused_unless_forced`, `test_rows_in_closed_period_rejected` — FIN-03.15 / P3-13
- `test_gl_row_duplicating_invoice_actual_is_excluded_from_totals` — P3-12
- `test_import_completed_notification_sent_to_importer` — FIN-07.03
- `test_recurring_expense_generates_expected_invoice_on_due_date` — FIN-03.07
- `test_accrual_propose_post_reverse_and_prepayment_release` — FIN-03.06
- `test_from_actuals_mode_and_py_actual_populate` — FIN-02.05, FIN-02.16 (C26)
- `test_cross_tenant_rows_and_mappings_are_invisible` — tenancy

**`tests/Feature/RouteSmokeTest.php`** (edit, SMOKE-gated): new `test_finance_expense_estate_is_seeded_and_renders_for_every_demo_tenant` — for orgs 2 and 3: every new index page, every show page per status, every tab; expense/invoice in every status; one overdue invoice; one unbudgeted emergency; Sept actuals exist and the unmatched queue is non-empty; each pending expense/invoice request has a decider (owner-type steps checked with `canDecide` without the permission filter); org 1 renders empty pages. Update the finance workflow count 5 → 7.

No Playwright in P3 (handover rule: P2 and P7 only).

---

## 6. Gate (orchestrator)
1. Full suite on `it_security_risk_test` in the background (≈ 20 min). 2. `migrate:fresh --seed` on scratch. 3. `SMOKE=1 … RouteSmokeTest` on scratch. 4. `npm run build`. 5. Pint on touched PHP files. 6. Manually: `fin:recurring-generate` and `fin:invoices-sweep` against scratch (`DB_*` env set) produce output and a second run sends nothing.

---

## 7. Props contract (WP-A ⇄ WP-C). Decimals are strings; dates `Y-m-d`; timestamps ISO-8601; masked amounts are `null`

Shared shapes: `UserMini{id,name}`, `CcMini{id,code,name}`, `VendorMini{id,name}`, `ContractMini{id,contract_code,title}`, `Opt{value,label}`, `PeriodMini{id,name,status,fiscal_year_id}`, `LineMini{id,budget_id,budget_code,line_no,label,cost_centre_id,current_base_amount}`, `Availability{line_id,budget,committed,actual,available,used_pct,period:{id,name,budget,actual}|null}`, `Doc{id,kind,title,sha256,file_name,download_url,uploaded_by:UserMini|null,created_at}`, `Activity{id,event,description,causer:UserMini|null,at}`.

- **ExpenseRow** `{id,request_code,title,status,cost_centre:CcMini,budget_line:LineMini|null,unbudgeted:bool,is_emergency,over_budget,is_capex,is_lease,vendor:VendorMini|null,currency,amount,total_amount,base_total_amount,amount_band,amount_band_label,effective_band,needed_by,requested_by:UserMini,submitted_at,approved_at,current_stage:string|null,po:{id,po_code,status}|null}`
- **ExpenseFull** = row + `{description,category:{id,code,name}|null,gl_account:{id,code,name}|null,legal_entity|null,contract:ContractMini|null,renewal:{id,renewal_code}|null,asset:{id,label}|null,period:PeriodMini|null,fiscal_year:{id,year_label},fx_rate,fx_rate_date,tax_type:{id,code,rate,is_inclusive}|null,tax_amount,base_amount,justification,overbudget_justification,availability_snapshot,rejection_reason,cancelled_reason,approved_by:UserMini|null,closed_at,created_by:UserMini|null,created_at,updated_at}`
- **PoRow** `{id,po_code,status,vendor,expense_request:{id,request_code,title},cost_centre:CcMini,po_date,expected_delivery_on,currency,subtotal,tax_amount,total_amount,base_total_amount,received_pct,invoiced_amount,remaining_commitment_base,issued_by:UserMini,external_reference,is_lease}`
- **PoFull** = row + `{fx_rate,fx_rate_date,budget_line:LineMini|null,lines:[{id,line_no,description,quantity,unit_cost,amount,received_quantity,invoiced_quantity,remaining_quantity,category|null,asset|null}],receipts:[{id,grn_code,received_on,received_by:UserMini,notes,lines:[{po_line_id,line_no,description,quantity,accepted,rejection_reason}]}]}`
- **InvoiceRow** `{id,invoice_code,vendor_invoice_no,vendor,status,match_status,match_variance_amount,po:{id,po_code}|null,contract:ContractMini|null,expense_request:{id,request_code}|null,cost_centre:CcMini,budget_line:LineMini|null,invoice_date,due_on,days_to_due,is_overdue,period:PeriodMini,currency,subtotal,tax_amount,wht_amount,total_amount,payable_amount,base_total_amount,amount_band,amount_band_label,is_expected,is_lease,prepayment_months,current_stage,submitted_by:UserMini|null,approved_by:UserMini|null,paid_on,payment_reference}`
- **InvoiceFull** = row + `{description,category|null,gl_account|null,fx_rate,fx_rate_date,tax_type|null,wht_tax_type|null,match_variance_base,lines:[{id,line_no,po_line_id,description,quantity,unit_cost,amount}],match:{status,expected,invoiced,variance,variance_base,limit,tolerance:{pct,abs},lines:[{po_line_id,description,ordered_qty,received_qty,prior_invoiced_qty,invoiced_qty,po_unit_cost,invoice_unit_cost,expected,amount,variance,flags:string[]}],override:{reason,by:UserMini,at}|null}|null,scheduled_on,dispute_reason,disputed_at,rejection_reason,cancelled_reason,recurring:{id,recurring_code}|null,created_at,updated_at}`
- **ActualRow** `{id,period:PeriodMini,posted_on,source,source_ref,description,cost_centre:CcMini|null,gl_account:{id,code,name}|null,budget_line:LineMini|null,vendor|null,contract:ContractMini|null,invoice:{id,invoice_code}|null,batch_id,currency,amount,base_amount,is_capex,is_lease,match_status,match_reason,matched_by:UserMini|null,matched_at,duplicate_of_id}`
- **AccrualRow** `{id,type,source,status,period:PeriodMini,reversal_period:PeriodMini|null,po:{id,po_code}|null,invoice:{id,invoice_code}|null,recurring:{id,recurring_code}|null,budget_line:LineMini|null,cost_centre:CcMini,description,currency,amount,base_amount,posted_by:UserMini|null,posted_at,reversed_at}`
- **RecurringRow** `{id,recurring_code,title,contract:ContractMini|null,vendor|null,budget_line:LineMini|null,cost_centre:CcMini,category|null,gl_account|null,tax_type|null,frequency,amount,currency,base_amount_est,starts_on,ends_on,next_due_on,days_to_due,last_generated_on,auto_generate,is_active,notes,generated:[{type:'invoice'|'accrual',id,code,due_on,href}]}` (last 3)
- **BatchRow** `{id,type,file_name,status,row_count,accepted_count,rejected_count,duplicate_count,matched_count,unmatched_count,imported_by:UserMini,created_at,completed_at}`

| page · route | props |
|---|---|
| `Finance/Expenses/Index` · `finance.expenses.index` | `filters{q,status,cost_centre_id,vendor_id,fiscal_year_id,unbudgeted,emergency,mine,awaiting_me}`, `expenses: ExpenseRow[]` (≤ 500, `truncated`), `summary{count,draft,submitted,approved,committed,unbudgeted_count,unbudgeted_base,emergency_count,over_budget_count,committed_base,awaiting_me}`, `options{statuses:Opt[]}`, `costCentres:CcMini[]`, `vendors`, `fiscalYears`, `selectedFiscalYear`, `baseCurrency`, `can{create,import,viewAmounts}` |
| `Finance/Expenses/Create` · `.create` / `.edit` | `expense: ExpenseFull\|null`, `budgetLines:[LineMini + {category_id,gl_account_id,vendor_id,contract_id,is_capex,currency,fiscal_year_id}]` (current baselines, open FYs, visible cc), `costCentres`, `categories:[{id,code,name,kind}]`, `glAccounts`, `vendors`, `contracts:[ContractMini+{vendor_id,budget_line_id,is_ifrs16_lease}]`, `taxTypes:[{id,code,name,rate,is_inclusive,kind}]`, `currencies:[{code,decimals,is_base}]`, `legalEntities`, `assets:[{id,label}]` (≤ 500), `fiscalYears` (open), `settings{overbudget_mode,justification_min}`, `defaults{currency,cost_centre_id,fiscal_year_id}`, `baseCurrency`, `can{viewAmounts}`. `GET finance.expenses.availability` → `Availability + {requested_base, would_exceed, mode}`. Submit fields = `ExpenseFormRequest` keys. |
| `Finance/Expenses/Show` · `.show` | `expense: ExpenseFull`, `availability: Availability\|null`, `approval` (FinanceApprovals::trail incl. `context`), `po: PoFull\|null`, `invoices: InvoiceRow[]`, `actuals: ActualRow[]`, `documents: Doc[]`, `activity: Activity[]`, `tab`, `options{documentKinds:Opt[],taxTypes}`, `can{edit,delete,submit,withdraw,approve,return,reject,cancel,close,commit,attach,captureInvoice,viewAmounts}` |
| `Finance/PurchaseOrders/Index` · `finance.purchase-orders.index` | `filters{q,status,vendor_id,cost_centre_id}`, `orders: PoRow[]`, `summary{open,partially_received,received,closed,open_commitment_base}`, `options{statuses}`, `vendors`, `costCentres`, `can{viewAmounts}` |
| `Finance/PurchaseOrders/Show` · `.show` | `po: PoFull`, `expense: ExpenseRow`, `invoices: InvoiceRow[]`, `documents`, `activity`, `can{edit,receive,close,cancel,captureInvoice,attach,viewAmounts}` |
| `Finance/Invoices/Index` · `finance.invoices.index` | `filters{q,status,match_status,vendor_id,cost_centre_id,overdue,due,mine,awaiting_me}`, `invoices: InvoiceRow[]`, `summary{count,unpaid_base,overdue_count,overdue_base,due_7_count,variance_count,disputed_count,awaiting_me}`, `ageing{current,d1_30,d31_60,d61_90,d90_plus}`, `options{statuses,matchStatuses}`, `vendors`, `costCentres`, `can{create,import,viewAmounts}` |
| `Finance/Invoices/Create` · `.create` / `.edit` | `invoice: InvoiceFull\|null`, `prefill: object\|null` (from `po_id`/`expense_id`/`contract_id`), `purchaseOrders:[{id,po_code,vendor_id,currency,cost_centre_id,budget_line_id,lines:[{id,line_no,description,quantity,unit_cost,received_quantity,invoiced_quantity}]}]` (open POs), `expenses:[{id,request_code,title,vendor_id,currency,budget_line_id}]` (approved, no PO), `contracts`, `vendors`, `budgetLines`, `costCentres`, `categories`, `glAccounts`, `taxTypes{vat:[],wht:[]}`, `periods: PeriodMini[]` (open), `currencies`, `defaults{currency,payment_days}`, `baseCurrency`, `can{viewAmounts}` |
| `Finance/Invoices/Show` · `.show` | `invoice: InvoiceFull`, `po: PoRow\|null`, `expense: ExpenseRow\|null`, `contract: ContractMini+{lease_assessment,is_ifrs16_lease}\|null`, `approval`, `actual: ActualRow\|null`, `prepayments: AccrualRow[]`, `documents`, `activity`, `sod{applies:bool, po_approvers:UserMini[]}`, `periods` (open, for draft re-period), `can{edit,delete,match,override,submit,withdraw,approve,return,reject,schedule,pay,dispute,resolve,cancel,attach,viewAmounts}` |
| `Finance/Actuals/Index` · `finance.actuals.index` | `tab`, `filters{fiscal_year_id,period_id,cost_centre_id,source,match_status,q,batch_id}`, `actuals: ActualRow[]` (≤ 500, `truncated`), `summary{posted_base,unmatched_count,unmatched_base,duplicates,unbudgeted_count,by_source:{[source]:base}}`, `queue: (ActualRow + {reason})[]`, `rules:[{id,name,priority,cost_centre,gl_account,vendor,contract,description_contains,budget_line:LineMini,is_active,hits,last_hit_at}]`, `mappings:[{id,name,type,columns,delimiter,date_format,decimal_comma,has_header,default_currency,is_default}]`, `batches: BatchRow[]` (latest 20), `periods`, `fiscalYears`, `costCentres`, `glAccounts`, `vendors`, `contracts`, `budgetLines`, `mappingKeys{required,optional}`, `dateFormats`, `can{import,match,viewAmounts}`. `GET finance.actuals.candidates` → `{candidates:[{line_id,label,budget_code,line_no,current_base_amount,score,why}]}` |
| `Finance/Recurring/Index` · `finance.recurring.index` | `filters{q,active,frequency,due}`, `items: RecurringRow[]`, `summary{active,due_30,annual_run_rate_base}`, `options{frequencies,autoGenerate}`, `contracts`, `vendors`, `budgetLines`, `costCentres`, `categories`, `glAccounts`, `taxTypes`, `currencies`, `can{create,edit,generate,viewAmounts}` |
| `Finance/Accruals/Index` · `finance.accruals.index` | `filters{fiscal_year_id,period_id,type,status}`, `accruals: AccrualRow[]`, `summary{proposed_base,posted_base,reversed_base,prepaid_balance_base}`, `periods: PeriodMini[]` (FY, with status), `budgetLines`, `costCentres`, `glAccounts`, `currencies`, `can{manage,viewAmounts}` |
| `Finance/Imports/Index` · `finance.imports.index` | `filters{type}`, `batches: BatchRow[]`, `options{types}`, `can{import}` |
| `Finance/Imports/Show` · `finance.imports.show` | `batch: BatchRow + {report}`, `actuals: ActualRow[]` (actuals batches), `can{match,viewAmounts}` |

Writes (flash on success, `errors.*` on refusal): `expenses.approve|return|reject {note}`; `expenses.cancel {reason}`; `expenses.commit {po_date, expected_delivery_on?, external_reference?, tax_type_id?, lines[{description,quantity,unit_cost,category_id?,asset_id?}]}`; `purchase-orders.receive {received_on, notes?, lines[{po_line_id,quantity,accepted,rejection_reason?}]}`; `purchase-orders.close {note?}`, `.cancel {reason}`; `invoices.override {reason}`; `invoices.schedule {scheduled_on}`; `invoices.pay {paid_on, payment_reference}`; `invoices.dispute {reason}`; `invoices.cancel {reason}`; `actuals.import` multipart `{file, mapping_id? | columns{}, delimiter?, date_format?, decimal_comma?, has_header?, mode, skip_invalid?, force?, save_mapping_as?}` → `flash.import_report`; `actuals.match {budget_line_id, remember?}`; `actuals.unbudgeted {note}`; `accruals.propose|release-prepayments {period_id}`; `documents.store` multipart `{subject: expense|purchase_order|goods_receipt|invoice, subject_id, kind, title, file}`.

---

## 8. File ownership (no overlaps)

| WP | files |
|---|---|
| **A** (≈ 67 files: 45 new, 22 edits) | `config/finance.php`; migrations `2027_03_01_000001…000003`; models `app/Models/Finance/{ExpenseRequest,PurchaseOrder,PurchaseOrderLine,GoodsReceipt,GoodsReceiptLine,Invoice,InvoiceLine,Actual,ImportMapping,ImportBatch,MatchRule,RecurringExpense,Accrual,FinanceDocument}.php` (new), `Contract.php`, `Setting.php` (edit); services `app/Services/Finance/{BudgetAvailability,FinanceTax,ExpenseService,ProcurementService,ThreeWayMatcher,InvoiceService,ActualsService,ReconciliationService,RecurringExpenseService,AccrualService,FinanceDocuments,FinanceTransactionImporter}.php` (new), `{BudgetLedger,FinanceApprovals,BudgetService,BudgetTransferService,ContractService,FinanceSettings}.php` (edit); `app/Events/Finance/{ExpenseFlagged,ImportBatchCompleted}.php`; `app/Services/Approvals/ApprovalProcesses.php`; `app/Policies/Finance/FinancePolicy.php`; controllers `app/Http/Controllers/Finance/{Expense,PurchaseOrder,Invoice,Actuals,RecurringExpense,Accrual,FinanceImport,FinanceDocument}Controller.php`, `Concerns/PresentsExpenses.php` (new), `ContractController.php`, `Concerns/PresentsContracts.php` (edit); `app/Http/Requests/Finance/{ExpenseFormRequest,CommitPurchaseOrderRequest,ReceiveGoodsRequest,InvoiceFormRequest,ImportActualsRequest,RecurringExpenseFormRequest}.php` (new), `StoreContractRequest.php` (edit); `app/Http/Controllers/EvidenceController.php`; `routes/web.php`; tests `Concerns/BuildsExpenses.php`, `ExpenseBackendSmokeTest.php` (new), `Workflows/WorkflowStudioModuleTest.php`, `Finance/FinanceWorkflowStudioTest.php` (count lines only), `Finance/BudgetLifecycleTest.php` (C26 assertion) |
| **B** (14 files: 8 new, 6 edits) | `app/Services/Finance/FinancePolicySeeder.php`, `FinanceApprovalNotifier.php`, `FinanceCalendarService.php`, `FinanceDigest.php` (edit), `InvoiceSweeper.php` (new); `app/Http/Controllers/Workflows/WorkflowStudioController.php` (inbox line); `app/Notifications/Finance/{UnbudgetedSpend,InvoiceDue,ImportCompleted}Notification.php`; `app/Listeners/Finance/{SendUnbudgetedSpendNotification,SendImportCompletedNotification,CreateRenewalExpenseRequest}.php`; `routes/console.php`; test `ExpenseWorkflowIntegrationTest.php` |
| **C** (≈ 42 files: 33 new, 9 edits) | `resources/js/Pages/Finance/{Expenses/{Index,Create,Show},PurchaseOrders/{Index,Show},Invoices/{Index,Create,Show},Actuals/Index,Recurring/Index,Accruals/Index,Imports/{Index,Show}}.jsx` (new); `resources/js/Components/Finance/Expenses/*` (new, 20); edits `Config/navigation.js`, `Utils/finance.js`, `Pages/Finance/Budgets/Create.jsx`, `Pages/Finance/Contracts/Show.jsx`, `Components/Finance/Contracts/{ContractForm,SpendPanel}.jsx`, `Components/Finance/Calendar/calendarUtils.js`, `Components/Finance/Settings/DoaBandsTab.jsx` |
| **D** (6 files: 4 new, 2 edits) | `database/seeders/FinanceExpenseDemoSeeder.php` (new), `database/seeders/DatabaseSeeder.php`; tests `ExpenseApprovalAndAvailabilityTest`, `ProcurementThreeWayMatchTest`, `ActualsImportAndReconciliationTest` (new), `tests/Feature/RouteSmokeTest.php` |

Touched by nobody: `ApprovalEngine.php`, `AppServiceProvider.php`, `ContractSweeper.php`, `RenewalService.php`, `FinanceDemoSeeder.php`, `FinanceFoundationSeeder.php`, `FinanceContractDemoSeeder.php`, `RolesAndPermissionsSeeder.php` (no new permissions), `Workflows/Process.jsx`. B and C share no file; the only cross-WP dependency is "A before B and C".

---

## 9. §12 acceptance → test

| §12 / feature | test |
|---|---|
| FIN-03.01 availability = budget − committed − actual; block refuses; warn needs justification | `ExpenseApprovalAndAvailabilityTest::test_availability_is…`, `…lineage…`, `…block_mode…`, `…warn_mode…`, `…final_approval_rechecks…` |
| FIN-03.02 DoA approval, SoD | `ExpenseApprovalAndAvailabilityTest::test_doa_bands_route…`, `…requester_and_creator…`; `ExpenseWorkflowIntegrationTest` (stage routing, owner stage) |
| FIN-03.03 PO, FX at PO date, partial/complete GRN | `ProcurementThreeWayMatchTest::test_commit…`, `…po_above…`, `…partial_and_full_receipt…`, `…commitment_relieved…` |
| FIN-03.04 within tolerance → matched; outside → variance + override + stage; payment status | `ProcurementThreeWayMatchTest::test_within_tolerance…`, `…outside_tolerance…`, `…tolerance_uses_stricter…`, `…override…`, `…schedule_pay_dispute…`, `…payer_cannot…` |
| FIN-03.04/.05 invoice approval posts actual in correct period | `ProcurementThreeWayMatchTest::test_invoice_approval_posts_actual_in_invoice_period_once` |
| FIN-03.05 GL import matches by rules, unmatched queue | `ActualsImportAndReconciliationTest::test_gl_import…`, `…auto_match…`, `…manual_match…`, `…duplicating_invoice…` |
| FIN-03.06 accruals & prepayments (S) | `ActualsImportAndReconciliationTest::test_accrual_propose_post_reverse…`, `ProcurementThreeWayMatchTest::test_prepaid_invoice…` |
| FIN-03.07 recurring generates expected invoice on due date | `ActualsImportAndReconciliationTest::test_recurring…`, `ExpenseWorkflowIntegrationTest` (command idempotency) |
| FIN-03.08 unbudgeted/emergency: justification, flag, higher band, notification | `ExpenseApprovalAndAvailabilityTest::test_unbudgeted…`, `…emergency…`; `ExpenseWorkflowIntegrationTest` (UnbudgetedSpend once) |
| FIN-03.14 attachments with hash | `ExpenseBackendSmokeTest` |
| FIN-03.15 bulk import, preview, error report | `ActualsImportAndReconciliationTest::test_gl_import_preview…`, `…invalid_rows…`, `…same_file…`; `ExpenseApprovalAndAvailabilityTest::test_bulk_expense_import…` |
| FIN-01.06 taxes applied on expenses/invoices | `ProcurementThreeWayMatchTest::test_vat_and_wht…` |
| FIN-02.05 / FIN-02.16 from actuals, PY actual (C26) | `ActualsImportAndReconciliationTest::test_from_actuals_mode_and_py_actual_populate` |
| FIN-06.05 renewal creates expense request (C52) | `ExpenseWorkflowIntegrationTest` |
| FIN-06.10 vendor spend incl. expenses | `ExpenseBackendSmokeTest` |
| FIN-07.01 processes in Workflow Studio, defaults, unconfigured default | `ExpenseWorkflowIntegrationTest`, `FinanceWorkflowStudioTest` (count), `ExpenseApprovalAndAvailabilityTest::test_unconfigured_default…`, `…zero_step…` |
| FIN-07.02 invoice approver ≠ PO approver above band; nobody decides two stages | `ProcurementThreeWayMatchTest::test_invoice_final_approver…`, `ExpenseApprovalAndAvailabilityTest::test_requester_and_creator…` |
| FIN-07.03 notifications render mail + database | `ExpenseWorkflowIntegrationTest` |
| FIN-07.05 calendar shows invoice due dates | `ExpenseWorkflowIntegrationTest` |
| FIN-07.06 audit | `ExpenseApprovalAndAvailabilityTest::test_lifecycle_is_activity_logged` |
| FIN-07.10 masking | `ExpenseBackendSmokeTest` |
| L4 IFRS 16 indicator | `ProcurementThreeWayMatchTest::test_lease_contract_flows_ifrs16…` |
| Tenancy | `ExpenseBackendSmokeTest`, `ActualsImportAndReconciliationTest::test_cross_tenant…`, `FinanceTenancyAndRoutesTest`, `RouteSmokeTest` (orgs 2/3, org 1 empty) |

---

## 10. Spec ⇄ codebase conflicts (continues from P2's C73)

| # | conflict | resolution |
|---|---|---|
| C74 | Default stage "cost-centre owner" but owners hold no approve permission; inbox and notifier filter by permission; requester is often the owner (deadlock). | P3-6. **Needs sign-off** (shared inbox edit, flag-scoped). |
| C75 | "10m–50m: + CIO/CFO" — one job title per stage. | P3-7: CFO. |
| C76 | "Routed to higher DoA band" has no mechanism. | P3-5 `effective_band`. |
| C77 | Spec condition names `is_capex`/`is_emergency` are boolean columns; engine compares strings. | P3-8 string accessors; keys renamed. |
| C78 | "Invoice final approver ≠ PO approver" via `sod_excluded_ids` would block every stage. | P3-9 final-step check + high-value stage. |
| C79 | Non-PO invoices have no match. | `unmatched` routes to Finance Controller (P3-10). |
| C80 | Tolerance semantics undefined (P0 D12). | P3-10. |
| C81 | Tax: no recoverability or WHT type on invoices. | P3-3; `wht_tax_type_id`; regime work deferred to tax counsel. |
| C82 | GL import double-counts invoice actuals. | P3-12 duplicate detection. |
| C83 | Saved mappings "per `IntegrationConnection`". | Own `fin_import_mappings` table. |
| C84 | "Auto-match rules" undefined. | P3-12 rules table + cascade. |
| C85 | Request/PO/invoice lines could hit several budget lines. | P3-11: one line per document. |
| C86 | Spec allows many POs per request. | One PO per request (unique index). |
| C87 | Expense statuses have no `under_review`. | Stays `submitted`; `current_stage` shown from the engine. |
| C88 | `availability(line, period?)` granularity. | P3-1 annual; period shown only. |
| C89 | Virements can move money that is already committed or spent. | Source-availability guard on transfers. |
| C90 | Postings into closed periods. | P3-13. |
| C91 | Prepayments have no source. | P3-14 `prepayment_months`. |
| C92 | Approved invoice cancellation / credit notes. | Refused in P3; credit notes deferred (P4/P7). |
| C93 | FIN-03.14 "via Evidence"; vault would expose quotes/invoices. | `fin_documents` + Evidence; vault hides all finance subjects. |
| C94 | XLSX import. | CSV only (P1-2). |
| C95 | `App\Jobs\Finance` namespace for imports. | Synchronous, capped; no job. |
| C96 | IFRS 16 indicator (L4). | P3-15. |
| C97 | Renewal → expense request (C52). | P3-16 draft only. |
| C98 | `from_actuals` / `py_actual` (C26). | P3-16 annualised run-rate. |
| C99 | `FinanceApprovals::snapshot` reads `contract_owner_id` whenever `sodExcludedIds` exists. | Only when the attribute exists. |
| C100 | `payer ≠ approver` (§1) not in §6. | Enforced on schedule/pay. |

---

## 11. Risks, open questions, deferrals

- **Sign-off needed:** C74 / P3-6 (owner stage + shared inbox change) and P3-7 (CFO rather than CIO). Everything else is decided above.
- **R1 Stuck demo approvals** (the P1 lesson): owner-type steps need the owner-aware decider check in `RouteSmokeTest`; the orchestrator must verify seeded pending items in SMOKE, not trust the seeder.
- **R2 Double counting** between invoice actuals and GL import is the domain reviewer's first target; the duplicate rule keys only on references, so a GL extract without references double-counts. Mitigation: the queue shows "possible duplicate" when amount + vendor + period match an invoice actual (shown, not auto-excluded).
- **R3 Lineage cost:** availability walks lines per request; index pages must use `BudgetAvailability::forLines` (bulk) or omit availability. Watch N+1 (P1 security L-item).
- **R4 Period drift:** the seeder uses "first open FY2026 period" for live postings; if the demo is reseeded after Dec 2026, live postings fall into FY2027 and the overdue/aged data shifts. Accepted (same as P2's relative dates).
- **R5 Shared files:** `WorkflowStudioController` (B) and `EvidenceController` (A) are platform files; keep the edits to the lines named here. Run `WorkflowStudioModuleTest` and `EvidenceVault`/Evidence tests after each.
- **R6 Unique `(vendor_id, vendor_invoice_no)`** survives soft deletes; a deleted draft blocks re-entry of the same number. Accepted (control strength); message tells the user to restore it.
- **R7 Suite time** grows by about 50 tests (≈ +4 min); disk at 94 %: drop `it_security_risk_fin_qa` after QA if space is tight.
- **R8 Tax figures** (VAT 7.5 %, WHT 5/10 %) remain unverified against the Nigeria Tax Act 2025 (P0 D6).
- **Deferred:** credit notes and approved-invoice reversal; multi-line split across budget lines; multiple POs per request; non-resident WHT and reverse-charge VAT; XLSX; queued imports; ERP adapters (`ErpAdapter`); cloud import (P6); BvA/variance/KRIs/dashboard figures (P4) — P3 leaves the data in place (Sept actuals with amber/red variances, overdue invoice, unbudgeted counts); Playwright journeys for expenses (P7); webhooks/API (FIN-07.09).
