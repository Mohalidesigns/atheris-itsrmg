# ATH-FIN-PLAN-p2: IT Financial Management, Phase P2 (Contracts, subscriptions, renewals & calendar)

**Spec:** ATH-FIN-001 §3.6 (FIN-06), §4.4, §4.7, §5.4, §6 (`finance.contract_renewal`), §7 (contract notifications, sweep, digest, Calendar), §10 (Contracts / Renewals / Calendar), §12 FIN-06 + FIN-07 items, §13 contracts demo data. **Plus** the P1 domain-review carry-overs M1, M3 and M4 (ATH-FIN-003 §P1). **Branch:** `feature/it-finance-p2` (from P1 f30deaa).

**Order:** WP-A (backend) first; it fixes schema, services and the props contract (§7). Then WP-B (frontend) ∥ WP-C (workflow) ∥ WP-D (P1 carry-over). WP-D's M1/M3/M4 items touch no WP-A file, so WP-D may start at the same time as WP-A on its own DB; only D5 waits for WP-A's `Contract` model. Then WP-E (QA). The file ownership in §8 has no overlaps.

**DB rule:** never touch `it_security_risk`.
- WP-A and WP-C: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_fin_test php vendor/bin/phpunit --filter <Class>`.
- WP-D: `it_security_risk_fin_d_test`. Create it with `/opt/homebrew/opt/postgresql@17/bin/createdb it_security_risk_fin_d_test` and drop it when done; the disk is about 94 % full.
- WP-E: `it_security_risk_fin_qa`.
- Seed and SMOKE run on `it_security_risk_scratch`, orchestrator only.
- WP-B needs no DB: `npm run build`.
- Pint runs only on touched PHP files.

**State found in code:**
- No §4.4 table exists yet. P1 created only the shared `fin_notification_logs` table (migration `2027_02_01_000003`) and its `NotificationLog` model. That table has no unique key.
- `fin_budget_lines.contract_id` exists as an indexed column without an FK (P1 C41). `BudgetService::saveLine` currently refuses `contract_id` ("linked from a later phase").
- `fin_settings` already has `renewal_lead_days`, `renewal_notice_offsets`, `escalation_role` and `digest_hour`, and the Settings page already has a Renewal policy tab.

**Route collision check (done, read-only):** `route:list --except-vendor --json` returns 770 routes, 83 of them `finance.*`. No route name or URI contains `contract`, `renewal` or `.ics`. The only calendar route is `changes.calendar` (`/changes/calendar`). **No collisions.**

---

## 1. Conventions to copy (verified)

| concern | source | P2 rule |
|---|---|---|
| Model base | `app/Models/Finance/Concerns/FinanceRecord.php` | every new model `use FinanceRecord;` `$guarded = []`, explicit `$table`. Policy auto-mapped by glob (no provider edit). |
| Codes | `GeneratesSequentialCode::nextSequentialCode($org,'col','CON-')` | defaults `CON-`, `REN-`; demo seeder starts `KHB-CON-0001` / `FBN-REN-0001` (P1 C44 `recode`). |
| Transitions / refusals | `BudgetService::TRANSITIONS`, `LogsFinanceEvents::{audit,fail}` | `const TRANSITIONS = ['action'=>['from'=>[…],'to'=>…]]`; refusals `ValidationException(['workflow'=>…])`, approval refusals key `approval`. |
| Approvals | `FinanceApprovals` (start/approve/decline/withdraw/finalised/trail/assertZeroStepSoD/assertDefaultApprover) | renewals go through it, never straight to `ApprovalEngine`. |
| Engine conditions | `ApprovalEngine::start` compares `(string) $subject->{field}` | string-valued accessors only (P1 C30). |
| Notifications | `BaseNotification` + `Notifications/Finance/FinanceApprovalOverdueNotification` | scalar ctor args, `$module='IT Financial Management'`, **no amounts in text** (P1 L-item). |
| Listeners | auto-discovery (P1 C35) | `app/Listeners/Finance/*` with typed `handle()`; never `Event::listen` in a provider. |
| Sweeps | `routes/console.php` closures + `Schedule::command()->name()->withoutOverlapping()`; tenant iteration with `withoutGlobalScopes()` + explicit `organization_id` | same. |
| Masking | `PresentsBudgets::renderMasked` / `maskMoney` | contracts get their own trait (§2.6); do **not** edit `PresentsBudgets` from WP-A (WP-D owns it). |
| Unauthenticated scope | `OrganizationScope` filters only when `auth()->check()` | sweep, ICS feed, listeners: always `withoutGlobalScopes()->where('organization_id', …)`. |
| UI | `Pages/Finance/Budgets/*`, `Components/Finance/Budgets/{Money,ActivityList,BandSnapshot}.jsx`, `Components/Approvals/ApprovalTrail.jsx`, `Components/Material/{Icon,Chip}`, `PageHeader`, `Utils/finance.js` | MD3-in-Tailwind, Material Symbols, navy/gold, **no MUI, no `rounded-full` pills, no heroicons in new code**, usable at 375 px. |

Demo users (scratch DB, both banks): `finance@` (IT Finance Controller), `procurement@` (Procurement Officer), `cfo@`, `cio@`, `md@`, `headofit@` ("Head of IT Governance", Finance Approver), `itops@` ("Head of IT Operations", IT Budget Owner), app owners `finacle@`/`finacle.app@`, `digital@`, `network@`, `treasury.it@`, `soc.analyst@`/`soc@`. The vendors already include Infosys Finacle, Microsoft, Oracle, Cisco, AWS, MTN, Airtel, Globacom, Interswitch, NIBSS, Temenos (FBN). Kano has no CrowdStrike or Fortinet. Kano has 0 EA apps; FBN has 53. Assets: 62 and 81.

---

## 2. WP-A, backend (fin-backend-engineer, DB `it_security_risk_fin_test`)

### 2.1 `config/finance.php` (edit, append; WP-A owns every P2 config key)
```php
'timezone' => 'Africa/Lagos',               // "today" for derivation, sweeps, digest hour, calendar (app tz is UTC)
'contracts' => [
    'default_notice_period_days' => 90,       // FIN-01.09 default; no fin_settings column (C57)
    'catch_up_days' => 3,                     // T-minus tolerance for missed sweeps (C60)
    'escalation_days_before_notice' => 7,
    'escalation_job_titles' => ['Chief Information Officer', 'Chief Financial Officer'],
    'finance_roles' => ['IT Finance Controller'],
    'calendar' => ['ics_past_days' => 90, 'ics_future_days' => 548, 'max_range_days' => 400],
    'document_max_kb' => 20480,
],
// append to 'approval_defaults':
'renewal_cfo_from' => 10000000, 'renewal_cfo_uplift_pct' => 10,
// append to 'money_tables':
'fin_contracts', 'fin_contract_renewals',
```

### 2.2 Migrations (driver-agnostic; `Schema` builder only; Postgres + SQLite + MySQL)

Every table: `id()`, `unsignedBigInteger('organization_id')->index()`, `foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete()`, `timestamps()`. Money is `decimal(18,2)`, rates `decimal(18,8)`, currency `char(3)`. All user FKs are `nullOnDelete`.

**`database/migrations/2027_02_10_000001_create_it_finance_contract_tables.php`** (create in this order; `down()` drops in reverse order)

| table | columns | indexes / FKs |
|---|---|---|
| `fin_contracts` | `contract_code` str32 · `title` str200 · `type` str32 · `vendor_id` FK vendors null · `legal_entity_id` FK legal_entities null · `cost_centre_id` FK fin_cost_centres null · `budget_line_id` FK fin_budget_lines null · `category_id` FK fin_categories null · `owner_id` / `technical_owner_id` / `procurement_contact_id` FK users null · `description` text null · `commenced_on` date · `ends_on` date · `term_months` usmallint · `notice_period_days` usmallint 0 · `renewal_type` str16 `manual` · `auto_renew` bool false · `renewal_lead_days` usmallint null (null ⇒ settings) · `renewal_window_opens_on` date · `notice_deadline_on` date · `notification_offsets` json null · `currency` char3 · `fx_rate` dec(18,8) 1 · `fx_rate_type` str16 `spot` · `fx_rate_date` date null (C58) · `contract_value` dec 0 · `base_contract_value` dec 0 · `billing_frequency` str16 `annual` · `amount_per_billing` dec null · `licence_metric` str16 null · `licence_quantity` dec(18,2) null · `deployed_quantity` dec(18,2) null · `payment_terms_days` usmallint null · `price_escalation_pct` dec(6,2) null · `data_processing_role` str16 `none` · `dpa_status` str16 `not_required` · `sla_summary` text null · `exit_terms` text null · `key_clauses` json null (FIN-06.08: `[{key,label,value}]`) · `external_reference` str100 null · `status` str32 `draft` · `renewed_from_id` / `renewed_to_id` FK fin_contracts null · `decision` str24 null · `decision_at` ts null · `decision_by_id` FK users null · `terminated_on` date null · `termination_reason` text null · `last_notified_offset` smallint null · `escalated_at` ts null · `softDeletes()` | unique(org, contract_code) · index(org,status) · index(org,ends_on) · index(org,notice_deadline_on) · index(org,renewal_window_opens_on) · index(org,vendor_id) · index(org,owner_id) · index(org,cost_centre_id) |
| `fin_contract_team` | `contract_id` FK cascade · `user_id` FK users cascade · `role` str16 `member` | unique(contract_id,user_id) · index(org,user_id) |
| `fin_contract_links` | `contract_id` FK cascade · `linkable_type` str (FQCN) · `linkable_id` ubi · `note` str255 null | unique(contract_id, linkable_type, linkable_id) · index(linkable_type, linkable_id) |
| `fin_contract_renewals` | `renewal_code` str32 · `contract_id` FK fin_contracts (restrict) · `opened_on` date · `recommendation` str24 null · `justification` text null · `benchmark_notes` text null · `proposed_term_months` usmallint null · `proposed_commences_on` date null · `proposed_value` dec null · `proposed_currency` char3 null · `proposed_fx_rate` dec(18,8) null · `proposed_fx_rate_type` str16 null · `proposed_fx_rate_date` date null · `proposed_base_value` dec null · `uplift_pct` dec(7,2) null · `alternatives_considered` text null · `status` str16 `open` · `is_auto` bool false · `requested_by_id` FK users null · `submitted_at` ts null · `approved_by_id` FK users null · `approved_at` ts null · `rejection_reason` text null · `executed_by_id` FK users null · `executed_at` ts null · `new_contract_id` FK fin_contracts null · `expense_request_id` ubi null (P3, no FK, C52) · `softDeletes()` | unique(org, renewal_code) · index(org,status) · index(contract_id,status) |
| `fin_contract_events` | `contract_id` FK cascade · `type` str32 · `payload` json null · `user_id` FK users null · `occurred_at` ts | index(contract_id, occurred_at) · index(org,type) |
| `fin_contract_documents` | `contract_id` FK cascade · `evidence_id` FK evidence null nullOnDelete · `kind` str24 · `title` str200 · `sha256` char64 null · `signed_on` date null · `expires_on` date null | index(contract_id) · index(org,expires_on) |
| `fin_calendar_feeds` | `user_id` FK users cascade · `version` uint 1 · `last_used_at` ts null | unique(user_id) |

**`database/migrations/2027_02_10_000003_grant_it_finance_contract_approval.php`** (C51): this migration grants `approve it-finance-contracts` to the `Finance Approver` role. Follow the pattern of `2027_01_30_000002`: guard on the role and permission existing, then call `forgetCachedPermissions`. `down()` revokes the grant. (Migration `000002` belongs to WP-C.)

### 2.3 Models `app/Models/Finance/` (all `use FinanceRecord`)

| model (table) | extra traits | constants | casts | relations / scopes / accessors |
|---|---|---|---|---|
| `Contract` (`fin_contracts`) | SoftDeletes, GeneratesSequentialCode | `TYPES` (§3.6, 11), `STATUSES=['draft','active','in_renewal_window','notice_deadline_passed','expired','renewed','terminated','replaced']`, `LIVE=['active','in_renewal_window','notice_deadline_passed']`, `TERMINAL=['renewed','terminated','replaced']`, `RENEWABLE=LIVE+['expired']`, `RENEWAL_TYPES=['manual','auto','evergreen','fixed_term']`, `BILLING=['monthly','quarterly','semi_annual','annual','one_off','usage']`, `LICENCE_METRICS` (§4.4, 10), `DP_ROLES=['none','processor','controller','joint']`, `DPA=['not_required','pending','signed']`, `DECISIONS=['renew_as_is','renew_renegotiated','replace','terminate','extend_short_term']` | dates; money `decimal:2`; `fx_rate` `decimal:8`; `notification_offsets`/`key_clauses` array; `auto_renew` bool; `decision_at`/`escalated_at` datetime | `vendor()`, `legalEntity()`, `costCentre()`, `budgetLine()`, `category()`, `owner()`, `technicalOwner()`, `procurementContact()`, `decisionBy()`, `renewedFrom()`, `renewedTo()`, `team()` hasMany, `teamUsers()` belongsToMany(User,'fin_contract_team')->withPivot('role'), `links()`, `documents()`, `renewals()` (latest first), `events()` (occurred_at desc), `openRenewal()` hasOne where status in open/submitted/approved. Scopes: `scopeLive`, `scopeVisibleTo(User)` (§2.5 rule), `scopeDueWithin(int $days)` (ends_on or notice_deadline_on in [today, today+days]), `scopePassed(CarbonInterface $today)` (§2.4). Accessors: **`amount_band`** / `amount_band_label` (DoaBandService on `base_contract_value`), `annualised_base_value` (base ÷ max(term,1) × 12, bcmath), `next_term_forecast` (base × (1+escalation%)), **`days_to_end`**, **`days_to_notice`**, `days_to_window` (signed ints vs `FinanceClock::today()`), `effective_offsets` (own ?? settings, desc unique ints), `effective_lead_days`, `is_auto` (`auto_renew` or type in auto/evergreen), `is_terminal`, `has_decision` (decision ≠ null), `licence_position` (`over` deployed>entitled · `under` deployed<80 % · `ok` · null), `label` ("KHB-CON-0007 Microsoft EA"). `generateNextCode` `CON-`. |
| `ContractTeamMember` (`fin_contract_team`) | — | `ROLES=['project_lead','member','approver','watcher']` | — | `contract()`, `user()` |
| `ContractLink` (`fin_contract_links`) | — | `TYPES=['asset'=>Asset::class,'ea_application'=>EaApplication::class,'business_service'=>BusinessService::class,'risk'=>Risk::class]` (investment added P5, C53) | — | `contract()`, `linkable()` morphTo; accessor `type_key` |
| `ContractDocument` (`fin_contract_documents`) | — | `KINDS` (§4.4, 7) | dates | `contract()`, `evidence()` (App\Models\Evidence) |
| `ContractEvent` (`fin_contract_events`) | — | `TYPES` = §4.4 list + `renewal_opened`, `renewal_submitted`, `renewal_rejected`, `renewal_withdrawn`, `team_changed`, `link_changed` (C67) | `payload` array, `occurred_at` datetime | `contract()`, `user()`; immutable (`updating`/`deleting` throw) |
| `Renewal` (`fin_contract_renewals`) | SoftDeletes, GeneratesSequentialCode | `STATUSES=['open','submitted','approved','rejected','executed','withdrawn']`, `ACTIVE=['open','submitted','approved']`, `DECISIONS=Contract::DECISIONS` | dates/timestamps, money `decimal:2`, `uplift_pct` `decimal:2`, `is_auto` bool | `contract()`, `requestedBy()`, `approvedBy()`, `executedBy()`, `newContract()`. Engine accessors (strings): **`amount_band`** (proposed_base_value ?? contract base), **`decision`** (= recommendation), **`contract_type`**, **`uplift_band`** (`decrease` ≤0 · `0-5` · `5-10` · `10+`), **`requires_cfo`** (`yes` when proposed_base ≥ `renewal_cfo_from` or uplift > `renewal_cfo_uplift_pct`), **`contract_owner_id`**, `contract_label`. Method `approvalBaseAmount(): string`. `generateNextCode` `REN-`. |
| `CalendarFeed` (`fin_calendar_feeds`) | — | — | `last_used_at` datetime | `user()`; static `for(User): self` (firstOrCreate version 1) |

**`app/Services/Finance/FinanceClock.php`** (new): `static today(): CarbonImmutable` = `CarbonImmutable::now(config('finance.timezone'))->startOfDay()` (honours `Carbon::setTestNow`); `static now(): CarbonImmutable`.

### 2.4 Status derivation `app/Services/Finance/ContractStatusDeriver.php` (pure, no notifications)

| first matching rule | status |
|---|---|
| status ∈ draft, renewed, terminated, replaced | unchanged |
| today > `ends_on` (no executed renewal ⇒ not terminal) | `expired` |
| today > `notice_deadline_on` | `notice_deadline_passed` |
| today ≥ `renewal_window_opens_on` | `in_renewal_window` |
| otherwise | `active` (also moves back when dates are edited forward) |

- `derive(Contract $c, CarbonInterface $today): string`
- `apply(Contract $c, CarbonInterface $today, ?User $by = null): ?array{from:string,to:string}` writes the status and logs `status_changed` plus the specific event (`window_opened` / `notice_deadline_passed` / `expired`). Each event is recorded once per transition.
- `needsRoll(Contract $c, CarbonInterface $today): bool` is true when `is_auto`, `today > ends_on`, `decision === null`, `renewed_to_id === null`, and status ∈ LIVE + expired.

**The three predicates stay spec-literal and distinct (C59):**
- Status uses the rule "no *executed* decision".
- The **Passed** list (`scopePassed`) holds non-terminal, non-draft contracts with `decision IS NULL` and (`notice_deadline_on < today` or `ends_on < today`). Auto contracts appear here once their notice deadline passes, flagged "will auto-renew".
- **Escalation** fires when no renewal row has status submitted, approved or executed.

### 2.5 Services `app/Services/Finance/`

**`ContractService`** (`use LogsFinanceEvents`)
- `create(array $data, User $by): Contract`
  1. Checks every FK belongs to the org. EA apps and business services may be shared (NULL org) rows.
  2. Checks `ends_on ≥ commenced_on`. `term_months` defaults to the whole months between the dates (rounded up). The notice period must be at most the term in days.
  3. Converts the value through `CurrencyService::convert($org, $value, $ccy, FinanceClock::today(), 'spot')`.
  4. Sets the code, `computeDates`, and status `draft`. When `$data['activate']` is truthy it calls `activate()`.
  5. Logs a `created` event, writes the audit entry and runs `syncVendorSummary`.
- `update(Contract $c, array $data, User $by): Contract` refuses terminal contracts ("Renewed/terminated contracts are read-only; edit the successor."). It re-converts FX if the value or currency changed. If dates, notice or lead days changed it calls `recompute`. Then it runs `syncVendorSummary`.
- `activate(Contract $c, User $by): Contract`: draft → active, which needs `vendor_id`, `owner_id` and both dates. Then `deriver->apply`.
- `computeDates(Contract $c): void`: `renewal_window_opens_on = ends_on − effective_lead_days`; `notice_deadline_on = ends_on − notice_period_days`.
- `recompute(Contract $c, ?User $by = null): ?array`: `computeDates`, save, then `deriver->apply(today)`.
- `addTeamMember(Contract, int $userId, string $role, User $by): ContractTeamMember` (same-org user, unique) and `removeTeamMember(ContractTeamMember, User $by)`. Both log `team_changed`.
- `addLink(Contract, string $type, int $id, ?string $note, User $by): ContractLink` uses the `ContractLink::TYPES` allowlist and checks the target is in the org (shared EA rows allowed). `removeLink(ContractLink, User)`.
- `addDocument(Contract, array $data, ?UploadedFile $file, User $by): ContractDocument`:
  - Creates an `Evidence` row with `evidenceable` = the contract, `type` document, status `approved`, `uploaded_by`.
  - Stores the file on disk `local` under `evidence/{org}/contracts/` and keeps the `sha256` of its bytes.
  - Logs `document_added`.
- `terminate(Contract, string $reason, ?string $terminatedOn, User $by): Contract` (early termination, C65):
  - Allowed from LIVE or expired.
  - Withdraws any active renewal (`FinanceApprovals::withdraw` when it is submitted).
  - Sets `status=terminated`, `terminated_on` (default today), `termination_reason`, `decision='terminate'` and `decision_at/by`.
  - Logs `terminated` and runs `syncVendorSummary`.
- `event(Contract, string $type, array $payload = [], ?User $user = null, ?CarbonInterface $at = null): ContractEvent` is public; the sweep uses it.
- `recipients(Contract $c): Collection<User>` returns team ∪ owner ∪ technical owner ∪ procurement contact. Users must be in the same org and active, de-duplicated. It is shared by WP-C.
- `financeUsers(int $orgId): Collection<User>` returns users holding a role in `config('finance.contracts.finance_roles')`.
- `billingSchedule(Contract $c, int $n = 12): array<{due_on, amount, base_amount}>` steps from `commenced_on` by `billing_frequency`, from today, up to `ends_on`. `one_off` and `usage` return [].
- `syncVendorSummary(?int $vendorId): void` (C68): the vendor's live contract with the largest `base_contract_value` writes `vendors.contract_start/contract_end/contract_value/contract_currency`. It does nothing when the vendor has no live finance contract.

**`RenewalService`** (`use LogsFinanceEvents`; `PROCESS = 'finance.contract_renewal'`)
```php
public const TRANSITIONS = [
    'recommend' => ['from' => ['open'], 'to' => 'open'],
    'submit'    => ['from' => ['open'], 'to' => 'submitted'],
    'withdraw'  => ['from' => ['open', 'submitted'], 'to' => 'withdrawn'],
    'approve'   => ['from' => ['submitted'], 'to' => 'approved'],      // stays submitted while stages remain
    'reject'    => ['from' => ['submitted'], 'to' => 'rejected'],
    'execute'   => ['from' => ['approved'], 'to' => 'executed'],
];
```
- `open(Contract $c, ?User $by, bool $auto = false): Renewal` needs the contract status in `RENEWABLE` and refuses if a renewal is already active. Sets `opened_on` today and logs `renewal_opened`.
- `recommend(Renewal $r, array $data, User $by): Renewal`:
  - `recommendation` must be in DECISIONS.
  - `proposed_term_months` (1–120) is required unless the recommendation is `terminate`. `extend_short_term` allows at most 12 months.
  - `proposed_value` and `proposed_currency` are required for the renew and extend options and optional for `replace`.
  - Converts the base value at today's spot rate.
  - `uplift_pct` = (proposed annualised base − contract annualised base) ÷ contract annualised base × 100, or null when the contract value is 0.
  - Sets `requested_by_id ??= $by`.
- `submit(Renewal $r, User $by): Renewal` needs a justification and a recommendation. It **re-quotes FX** (the lesson of M1), sets `requested_by_id = $by` and `submitted_at`, calls `FinanceApprovals::start(PROCESS, …)` and logs `renewal_submitted`.
- `approve(Renewal $r, User $by, ?string $note): string`:
  1. `assertZeroStepSoD(PROCESS, $r, $by, 'requested_by_id', 'contract_owner_id')`.
  2. Unconfigured process: `assertDefaultApprover($r, $by, 'requested_by_id', 'contract_owner_id', 'approve it-finance-contracts')`. Configured process: `FinanceApprovals::approve`; a returned message means more stages remain, so it returns that message.
  3. Final approval: sets `approved`, `approved_by/at`, and the contract's `decision`, `decision_at` and `decision_by_id`. Logs `decision_recorded` and calls `finalised()`.
- `reject(Renewal, User, string $note)` needs a note and uses `FinanceApprovals::decline(... 'rejected')`. `withdraw(Renewal, User)` is for the requester or the contract owner.
- `execute(Renewal $r, User $by, array $data = []): ?Contract` runs in a transaction with `lockForUpdate` on the contract:
  - `renew_as_is` / `renew_renegotiated` / `extend_short_term`:
    - The successor is a clone with the same parties, cost centre, budget line, category, billing, licence, DPA, notice, renewal type and offsets.
    - `commenced_on` = `proposed_commences_on` ?? old `ends_on` + 1 day; `term_months` = proposed term; `ends_on` = commenced + term − 1 day.
    - Value, currency and FX come from the renewal. `renewed_from_id` points at the old contract.
    - Team and links are copied. The successor is activated and derived.
    - The old contract becomes `renewed` with `renewed_to_id` set.
  - `replace`: the successor is a **draft** clone titled "Replacement for {code}: {title}", and the old contract becomes `replaced`.
  - `terminate`: no successor. The old contract becomes `terminated` with `terminated_on` = `$data['terminated_on']` ?? `ends_on`.
  - In every case it sets `executed_by/at` and `new_contract_id`, logs `renewed` or `terminated` on both records, runs `syncVendorSummary`, and dispatches `ContractRenewed` (except for terminate).
  - It does not create an `ExpenseRequest` (C52).
- `autoRenew(Contract $c, ?CarbonInterface $today = null): ?Contract`:
  - Idempotent: it locks the contract and returns null unless `deriver->needsRoll`.
  - Withdraws an active renewal (engine cancel).
  - Creates a `Renewal` with `is_auto`, `renew_as_is`, status `executed`, justification "Auto-renewed under the contract's renewal terms", `proposed_value` = value × (1 + escalation %) (C66), and term = `term_months`.
  - The successor starts at `ends_on` + 1 day and the old contract becomes `renewed`. It dispatches `ContractRenewed(auto: true)`.

**`FinanceApprovals`** (edit, C71): `snapshot()` amount = `method_exists($subject,'approvalBaseAmount') ? $subject->approvalBaseAmount() : (base_amount ?? total_base_amount ?? '0')`. `assertDefaultApprover(Model, User, string $initiatorAttr, ?string $ownerAttr = null, string $permission = 'approve it-finance')`; the owner message becomes "You own this record…" (the existing tests match on `approval` key only; check `BudgetLifecycleTest` text asserts before changing wording, keep the budget wording when `$ownerAttr === 'owner_id'`). `assertZeroStepSoD` gains the same `$permission` param passed through.

**Event** `app/Events/Finance/ContractRenewed.php` (`Dispatchable`; `public Renewal $renewal, public Contract $contract, public ?Contract $successor, public bool $auto`).

**`FinancePolicy`** (edit), C70:
- `costCentreVisible()` covers Contract, ContractTeamMember, ContractLink, ContractDocument, ContractEvent and Renewal through `contractVisible`.
- A user may see a contract if any of these holds:
  - `visibleCostCentreIds === null`
  - the user has the role `Procurement Officer`
  - the user is the contract's owner, technical owner, procurement contact or creator
  - the user is a team member
  - the contract's `cost_centre_id` is in the user's visible ids
- New abilities:
  - `createContract(User)` = `create it-finance`.
  - `updateContract(User, Contract)` = `edit it-finance` ∧ same org ∧ visible.
  - `decideRenewal(User, Renewal)` = `approve it-finance-contracts` ∧ same org.
  - `terminateContract(User, Contract)` = `approve it-finance-contracts` ∧ same org ∧ visible.
- `Contract::scopeVisibleTo` applies the same rule in SQL: `whereIn cost_centre_id`, or one of the people columns = the user, or `whereExists` on the team.

**`app/Services/Approvals/ApprovalProcesses.php`** (edit; WP-A owns it, because Show pages call `trail()`):
```php
'finance.contract_renewal' => [
    'module' => 'IT Financial Management', 'label' => 'Contract renewal decision', 'engine' => 'generic', 'owner_cannot_approve' => true,
    'description' => 'A renewal recommendation (renew, renegotiate, replace, terminate, extend) is approved before the next term is created (FIN-06.05).',
    'permission' => 'approve it-finance-contracts', 'model' => Renewal::class, 'initiator' => 'requested_by_id', 'owner' => 'contract_owner_id',
    'title' => ['renewal_code', 'contract_label'], 'route' => 'finance.renewals.show', 'initiate' => 'Submitting a renewal recommendation',
    'conditions' => [
        'amount_band' => ['label' => 'Amount band (DoA, proposed value)', 'values' => 'finance.doa_bands'],
        'decision' => ['label' => 'Recommendation', 'values' => ['renew_as_is' => 'Renew as is', 'renew_renegotiated' => 'Renew (renegotiated)', 'replace' => 'Replace', 'terminate' => 'Terminate', 'extend_short_term' => 'Short-term extension']],
        'contract_type' => ['label' => 'Contract type', 'values' => [/* Contract::TYPES => humanised labels */]],
        'uplift_band' => ['label' => 'Price uplift', 'values' => ['decrease' => 'Decrease or flat', '0-5' => 'Up to 5 %', '5-10' => '5 % to 10 %', '10+' => 'Above 10 %']],
        'requires_cfo' => ['label' => 'Needs CFO (high value or uplift above threshold)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
    ],
    'default' => 'One approval by anyone with "approve it-finance-contracts" other than the requester and the contract owner.',
],
```

### 2.6 Controllers, form requests, masking

**`app/Http/Controllers/Finance/Concerns/PresentsContracts.php`** (new trait):
- `contractRow(Contract)`, `contractFull(Contract)`, `renewalSummary(Renewal)`, `userMini`, `optionsPayload()` (the §7 shapes).
- `renderContracts(string $component, array $props)` masks without `view it-finance-amounts`. It sets these keys to null: `contract_value`, `base_contract_value`, `amount_per_billing`, `annualised_base_value`, `next_term_forecast`, `proposed_value`, `proposed_base_value`, `base_value`, `amount`, `base_amount`, `current_base_amount`, `amount_band`, `amount_band_label`, `value_base_total`, `annualised_base_total`, `fx_rate`, `proposed_fx_rate`. It empties the `billing` and `vendorSpend` arrays. Any `band` array becomes `{label,min,max: null}`.
- It merges `can.viewAmounts`.
- This trait is self-contained. Do **not** `use PresentsBudgets` in the same class, because their method names collide.

Every action calls `Gate::authorize(...)` (§2.5 abilities) on top of the route middleware. Successful writes go `back()`/redirect with `flash.success`. Refusals come from the services.

| controller | methods |
|---|---|
| `ContractController` | `index`, `create`, `store`, `show` (`?tab=overview\|team\|links\|documents\|renewal\|timeline\|spend`), `edit` (renders `Finance/Contracts/Create` with `contract`), `update`, `destroy` (draft only), `activate`, `recompute`, `terminate` |
| `ContractRelationsController` | `teamStore`, `teamDestroy`, `linkStore`, `linkDestroy`, `documentStore`, `documentDownload` (streams the evidence file after `Gate::authorize('view', $contract)`; 404 when the file is missing) |
| `RenewalController` | `index`, `show`, `open` (POST on a contract), `recommend` (PUT), `submit`, `withdraw`, `approve`, `reject`, `execute` |
| `FinanceCalendarController` | `index` (page), `export` (`?format=csv\|pdf`; `xlsx` → `back()->withErrors(['format'=>…])` like P1-2), `ics` (unauthenticated, see below), `rotateFeed` (bumps `CalendarFeed.version`) |

`FinanceCalendarController@ics`:
- Requires the signed `u` and `v` parameters.
- Loads the user with `withoutGlobalScopes`. It returns 403 when the user is missing or inactive, `v` does not match the feed version, or the user lacks `view it-finance`.
- It touches `last_used_at`.
- It returns `FinanceCalendarService::ics($user)` with `Content-Type: text/calendar; charset=utf-8` and `Content-Disposition: inline; filename="it-finance.ics"`.
- The controller depends on WP-C's `FinanceCalendarService` signatures (§4.5), so WP-A does not test `index`, `export` or `ics`; QA's `CalendarTest` covers them.

**Form requests `app/Http/Requests/Finance/`:**
- `StoreContractRequest`. `UpdateContractRequest` extends it, with every rule prefixed `sometimes`.
  - Enums use the model constants. FKs use `Rule::exists(...)->where('organization_id', org)`. `ends_on` is `after_or_equal:commenced_on`.
  - Money fields use regex `^\d{1,16}(\.\d{1,2})?$`. `notification_offsets` is `array|max:12` with each item `integer|between:0,730`.
  - `key_clauses` is `array|max:20` of `{key: string|max:40, label: string|max:80, value: string|max:500}`. Text fields allow at most 10000 characters.
- `RecommendRenewalRequest` and `ExecuteRenewalRequest` (`terminated_on`, `commenced_on`, both nullable dates).
- `ContractTeamRequest` (`user_id`, `role`) and `ContractLinkRequest` (`type` in the TYPES keys, `id`, `note`).
- `StoreContractDocumentRequest`: `kind`, `title`, `file` nullable with `max:` from config and mimes `pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,msg,eml,zip`; `signed_on`, `expires_on` (`after_or_equal:signed_on`).
- `CalendarRequest`: `view` in month/quarter/year/timeline, `date` (Y-m-d), the filters, and `sources` as an array.

### 2.7 Routes `routes/web.php` (edit; add the `use` lines)

Inside the existing `finance.` group, after the P1 block (line ~805), with `->whereNumber()` on every id and `scopeBindings()` for the nested `{member}`/`{link}`/`{document}`:
```
GET    /contracts                                   finance.contracts.index              ContractController@index   (?q,status,type,vendor_id,owner_id,cost_centre_id,due=30|60|90,mine,passed)
GET    /contracts/{contract}                        finance.contracts.show
GET    /contracts/{contract}/documents/{document}/download   finance.contracts.documents.download
GET    /renewals                                    finance.renewals.index               (?status,recommendation,q,mine)
GET    /renewals/{renewal}                          finance.renewals.show
GET    /calendar                                    finance.calendar                     FinanceCalendarController@index (?view,date,filters…)
POST   /calendar/feed/rotate                        finance.calendar.feed.rotate
permission:export it-finance:
  GET  /calendar/export                             finance.calendar.export
permission:create it-finance:
  GET  /contracts/create                            finance.contracts.create   (declare BEFORE /contracts/{contract})
  POST /contracts                                   finance.contracts.store
permission:edit it-finance:
  GET    /contracts/{contract}/edit                 finance.contracts.edit
  PUT    /contracts/{contract}                      finance.contracts.update
  DELETE /contracts/{contract}                      finance.contracts.destroy
  POST   /contracts/{contract}/activate             finance.contracts.activate
  POST   /contracts/{contract}/recompute            finance.contracts.recompute
  POST   /contracts/{contract}/team                 finance.contracts.team.store
  DELETE /contracts/{contract}/team/{member}        finance.contracts.team.destroy
  POST   /contracts/{contract}/links                finance.contracts.links.store
  DELETE /contracts/{contract}/links/{link}         finance.contracts.links.destroy
  POST   /contracts/{contract}/documents            finance.contracts.documents.store
  POST   /contracts/{contract}/renewals             finance.renewals.open
  PUT    /renewals/{renewal}                        finance.renewals.recommend
  POST   /renewals/{renewal}/submit|withdraw|execute   finance.renewals.submit|withdraw|execute
permission:approve it-finance-contracts:
  POST /renewals/{renewal}/approve|reject           finance.renewals.approve|reject
  POST /contracts/{contract}/terminate              finance.contracts.terminate
```
**Outside** the auth group, next to the `ea.portal` block (C55):
```php
Route::get('/it-finance/calendar.ics', [FinanceCalendarController::class, 'ics'])
    ->middleware(['signed', 'throttle:30,1'])->name('finance.calendar.ics');   // ?u={user}&v={version}&signature=…
```
The feed URL is `URL::signedRoute('finance.calendar.ics', ['u' => $user->id, 'v' => $feed->version])`. It has no expiry, so the URL is stable until the user rotates the feed.

### 2.8 Roles

In `database/seeders/RolesAndPermissionsSeeder.php` (edit), `seedFinanceRoles` adds `approve it-finance-contracts` to `Finance Approver` (C51). Migration `000003` makes the same grant on existing databases.

### 2.9 Backend tests (WP-A owns these edits)

- `tests/Feature/Finance/FinanceTenancyAndRoutesTest.php`:
  - The exact GET list adds `finance.calendar`, `finance.calendar.export`, `finance.calendar.ics`, `finance.contracts.create`, `finance.contracts.documents.download`, `finance.contracts.edit`, `finance.contracts.index`, `finance.contracts.show`, `finance.renewals.index` and `finance.renewals.show`.
  - The render loop adds `finance.contracts.index` → `Finance/Contracts/Index`, `finance.contracts.create` → `Finance/Contracts/Create` and `finance.renewals.index` → `Finance/Renewals/Index`. Calendar is left to QA.
  - Add 404 checks across banks for a contract, a renewal and a document download.
- `tests/Feature/Workflows/WorkflowStudioModuleTest.php`: `summary.processes` changes from 14 to **15**. That is a one-line edit.
- New `tests/Feature/Finance/ContractBackendSmokeTest.php` (about 8 tests):
  - Create computes the dates.
  - Update recomputes them and re-derives the status.
  - Activate.
  - Team, link and document CRUD with the sha256 stored.
  - The renewal open → recommend → submit → approve → execute path, run unconfigured.
  - Masking.
  - Visibility: a budget owner sees only their own contracts; procurement sees all.
- Filter: `--filter "ContractBackendSmokeTest|FinanceTenancyAndRoutesTest|WorkflowStudioModuleTest|BudgetLifecycleTest"`. The last one is there because of the `FinanceApprovals` edit. **Do not** run `FinanceWorkflowStudioTest`: it stays red until WP-C updates its counts.

---

## 3. WP-B, frontend (fin-frontend-engineer, `npm run build`)

Use these as references: `Pages/Finance/Budgets/Show.jsx` (tabs synced to `?tab=` with `replaceState`, action bar driven by `can`), `Pages/Finance/Budgets/Index.jsx` (KPI strip, filters via `router.get(..., {preserveState:true})`), `Components/Finance/Budgets/{Money,ActivityList,BandSnapshot}.jsx`, `Components/Approvals/ApprovalTrail.jsx`, and `Pages/Changes/Calendar.jsx` (month grid maths). The layout renders `errors.workflow` and `errors.approval`; also show `errors.approval` inline next to the approve button. When `can.viewAmounts` is false, amounts arrive as `null`: render "•••", never `0`.

| file | contents |
|---|---|
| `resources/js/Config/navigation.js` (edit) | In the "IT Financial Management" children, above Settings: `{ name: 'Contracts & Renewals', href: 'finance.contracts.index', permission: 'view it-finance' }`, `{ name: 'Calendar', href: 'finance.calendar', permission: 'view it-finance' }` |
| `resources/js/Utils/finance.js` (edit, append) | `CONTRACT_TONES = {draft:'neutral', active:'success', in_renewal_window:'warning', notice_deadline_passed:'error', expired:'error', renewed:'muted', terminated:'muted', replaced:'muted'}`, `RENEWAL_TONES = {open:'info', submitted:'warning', approved:'success', executed:'success', rejected:'error', withdrawn:'muted'}`, `CAL_COLORS = {green, amber, red, grey, blue}` → Tailwind class sets (bg/border/text, all square or `rounded-md`), `DECISION_LABELS`, `CONTRACT_TYPE_LABELS`, `daysLabel(n)` ("in 12 days" / "today" / "3 days ago") |
| `Pages/Finance/Contracts/Index.jsx` | `ContractsNav` (active `contracts`); KPI strip from `summary` (active, in window, passed (red), due 30/60/90 as filter chips, auto-renew, annualised value); filters (search, status, type, vendor, owner, cost centre, "My contracts", "Passed only"); table (code, title, vendor, type, status chip, ends on + `DaysChip`, notice deadline + chip, value (base) + band, renewal chip); row → show; "New contract" (`can.create`); "Vendor spend" side card (`vendorSpend`, top 10). Empty state. |
| `Pages/Finance/Contracts/Create.jsx` | One page for create and edit (`contract` null or set): sections Basics (title, type, vendor, legal entity, cost centre, category, budget line, description) · People (owner, technical owner, procurement contact) · Term (commenced, ends, term months auto-filled, notice days, renewal type, auto-renew, lead days override with placeholder = `defaults.renewal_lead_days`, offsets editor as removable chips + add) · Live preview "Renewal window opens {date} · Notice deadline {date}" computed client-side · Money (currency, value, billing frequency, amount per billing, payment terms, escalation %) · Licence (metric, entitled, deployed) · Data protection (DP role, DPA status) · Clauses (SLA summary, exit terms, key clauses repeater, external reference). Create also offers a "Save and activate" checkbox (`activate`). POST `finance.contracts.store` or PUT `finance.contracts.update`. |
| `Pages/Finance/Contracts/Show.jsx` | Header: code, title, status chip, vendor, `DaysChip` for end and notice, value + band, renewed-from / renewed-to links. Action bar from `can`: Edit, Activate, Recompute dates, Open renewal, Terminate (modal: reason + date), Print. A "Passed" red banner when `contract.in_passed`, and an amber one in the window. Tabs: **Overview** (all fields, licence position chip, DPA, clauses) · **Team** (`TeamPanel`: list + add user/role + remove) · **Links** (`LinksPanel`: grouped by type, add from `linkOptions`, remove) · **Documents** (`DocumentsPanel`: list with kind/signed/expires/sha256 short + download; upload modal) · **Renewal** (`RenewalPanel`: current renewal summary or "Open renewal"; inline recommend form; submit/withdraw; `ApprovalTrail process="finance.contract_renewal" id={renewal.id}`; history table) · **Timeline** (`TimelinePanel`: `events[]` vertical list with Material Symbols per type: `notifications`, `priority_high`, `event_busy`, `autorenew`, `gavel`, `description`…) · **Spend** (`SpendPanel`: value, annualised, next-term forecast, billing schedule table, linked budget line current amount, "Actuals arrive with Expenses (P3)" placeholder). |
| `Pages/Finance/Renewals/Index.jsx` | `ContractsNav` (`renewals`); summary tiles; a "Window open, no renewal yet" card (`pendingWindow`) with an Open button; filters; table (code, contract, vendor, recommendation, proposed value, uplift %, band, status chip, requester, submitted). |
| `Pages/Finance/Renewals/Show.jsx` | Contract summary card; recommendation form when `can.recommend` (decision radio cards with Material Symbols: `autorenew`, `handshake`, `swap_horiz`, `cancel`, `more_time`; term, commences on, value + currency, base preview, uplift shown after save, justification, benchmark, alternatives); actions: submit/withdraw/approve/reject (note modal)/execute (`ExecuteRenewalModal`: terminated_on for terminate; commenced_on override); `ApprovalTrail` + `BandSnapshot` from `approval.context`; outcome card linking `new_contract`. |
| `Pages/Finance/Calendar.jsx` | Header "IT Finance Calendar"; a view switcher (segmented buttons: Month · Quarter · Year · Timeline) plus Prev/Today/Next, which re-request `finance.calendar` with `{view, date}` and keep the filters; `CalendarFilters` (vendor, owner, "My contracts" (`member=me`), cost centre, type, value band, status, source toggles); `Legend`; body by view: `MonthGrid` (Monday-first weeks; up to 3 chips per day + "+N more" popover; chip colour from `CAL_COLORS[event.color]`; click → `href`), `QuarterView` (3 mini-months with dot markers + list of the quarter's events grouped by week), `YearView` (12 mini-months heat dots + counts), `TimelineGantt` (one row per `timeline[]` contract, bar from start to end clipped to the range, amber tick at `window_opens_on`, red tick at `notice_deadline_on`, vertical today line, month header; horizontally scrollable under `md`); `PassedPanel` (right column on desktop, top on mobile: rows sorted as given, red "{n} days overdue", "notice"/"end" tag, "auto-renews" tag, click-through); `SubscribeLink` (read-only input with `feed.url`, Copy button, "Reset link" → POST `feed.rotate_url` with a confirm modal, short help text for Outlook/Google); Export menu (CSV, PDF links from `exportUrls`, shown when `can.export`); Print button (`window.print()`, `print:hidden` on the chrome). |
| `Components/Finance/Contracts/` (new) | `ContractsNav.jsx` (Contracts · Renewals · Calendar), `ContractStatusChip.jsx`, `DaysChip.jsx`, `ContractForm.jsx`, `TeamPanel.jsx`, `LinksPanel.jsx`, `DocumentsPanel.jsx`, `RenewalPanel.jsx`, `RecommendationForm.jsx`, `ExecuteRenewalModal.jsx`, `TerminateModal.jsx`, `TimelinePanel.jsx`, `SpendPanel.jsx` |
| `Components/Finance/Calendar/` (new) | `MonthGrid.jsx`, `QuarterView.jsx`, `YearView.jsx`, `TimelineGantt.jsx`, `PassedPanel.jsx`, `CalendarFilters.jsx`, `Legend.jsx`, `SubscribeLink.jsx` |
| `Pages/Settings/Notifications.jsx` (edit) | Add a row `['finance_daily_digest', 'IT finance daily digest', 'Renewals due and finance approvals waiting for you']` |
| `Components/Finance/Budgets/LineFormModal.jsx` (edit, small) | A Contract select when `dimensions.contracts` is non-empty (`{id, contract_code, title}`); WP-D fills the list |
| `Components/Finance/Budgets/VersionsDiff.jsx` (edit, small) | When the WP-D keys are present, show Original / Adjustments / Current per side and a "revision change" column (§5, M3) |

Use these Material Symbols for the event types: `play_circle` (commencement), `event_upcoming` (window), `alarm` (notice), `event_busy` (end), `autorenew` (auto-renew), `flag` (milestones).

---

## 4. WP-C, workflow (fin-workflow-integrator, DB `it_security_risk_fin_test`, after WP-A)

### 4.1 `database/migrations/2027_02_10_000002_add_idempotency_key_to_fin_notification_logs.php` (C62)

This migration adds a `Schema::table` unique index `fin_notification_logs_idem_unique` on (`notifiable_type`, `notifiable_id`, `notification_key`, `offset_days`, `sent_to_user_id`).
- Existing `approval_overdue` rows have `offset_days` NULL. NULLs never collide on Postgres, SQLite or MySQL, so the hourly approvals sweeper is unaffected.
- Contract keys always set a non-null `offset_days`.
- `down()` drops the index.

### 4.2 `app/Services/Finance/FinancePolicySeeder.php` (edit; C49/C50)

Add to `$plans`:
```php
'finance.contract_renewal' => [
    self::stage(10, 'Head of IT review', 'job_title', 'Head of IT', 72),
    self::stage(20, 'CFO approval (high value or uplift)', 'job_title', 'Chief Financial Officer', 72, 'requires_cfo', ['yes']),
],
```
The idempotency check is per process, so banks that are already seeded receive this process on the next `ensureDefaults`.

### 4.3 Notifications `app/Notifications/Finance/` (new; extend `BaseNotification`; scalar args; no amounts)

| class | `$type` | ctor | subject / message |
|---|---|---|---|
| `ContractRenewalDueNotification` | `finance.contract_renewal_due` | `(string $contractLabel, string $vendor, string $series /* end\|notice */, int $offset, int $daysLeft, string $date, string $url)` | "{label}: notice deadline in {n} days" or "… ends in {n} days" ("today" at 0) / "Record a renewal decision before {date}." |
| `ContractRenewalEscalationNotification` | `finance.contract_renewal_escalation` | `(string $contractLabel, string $vendor, string $noticeDate, int $daysLeft, ?string $ownerName, string $url)` | "Escalation: no renewal decision for {label}" / "Notice deadline {date} ({n} days); owner {owner} has not submitted a recommendation." |
| `ContractDeadlinePassedNotification` | `finance.contract_deadline_passed` | `(string $contractLabel, string $vendor, string $which /* notice\|end */, string $date, int $daysOverdue, bool $autoRenew, string $url)` | "{label}: {notice deadline\|end date} passed with no decision" (+ "it will auto-renew" when auto) |
| `ContractRenewedNotification` | `finance.contract_renewed` | `(string $contractLabel, ?string $successorLabel, string $outcome /* renewed\|replaced\|auto_renewed */, ?string $newEndsOn, string $url)` | "{label} {auto-renewed\|renewed\|replaced}" / "Next term {successor} runs to {date}." |
| `FinanceDailyDigestNotification` | `finance.daily_digest` | `(array $sections /* [{title, lines: string[]}] */, string $url)` | "IT finance digest, {date}"; mail renders each section; `toArray` carries `sections` |

### 4.4 `app/Services/Finance/ContractSweeper.php` (new) and `app/Listeners/Finance/SendContractRenewedNotification.php` (new, auto-discovered)

`sweep(?CarbonInterface $today = null): array{derived:int, rolled:int, notified:int, escalated:int, passed:int}` (default `FinanceClock::today()`). Contracts are read with `withoutGlobalScopes()`, ordered by org then id, with `organization_id` carried explicitly. Each org is wrapped in try/catch with `report()` so one bad tenant does not stop the rest (P1 L-item). The steps for each non-draft, non-terminal contract run in this order:
1. **Roll:** if `deriver->needsRoll`, call `RenewalService::autoRenew` and count it. The successor is processed in the same run.
2. **Derive:** `deriver->apply($c, $today)`.
3. **T-minus:** applies to status ∈ active, in_renewal_window, notice_deadline_passed (the end series only for the last of these; C61), and only when `decision === null`.
   - For series `end` (`ends_on`) and series `notice` (`notice_deadline_on`, only while it is ≥ today), with `d` = days left: pick the **smallest** offset `k` in `effective_offsets` such that `k − catch_up_days ≤ d ≤ k` (C60).
   - Skip when a `NotificationLog` exists for (Contract, key `renewal_due.{series}`, `offset_days = k`).
   - Otherwise claim it: insert one log row per recipient inside a transaction, catching unique violations as "already claimed". Then `Notification::send($contractService->recipients($c), new ContractRenewalDueNotification(...))`.
   - Set `last_notified_offset = k` and log a `notified` event `{series, offset, recipients: [ids]}`.
4. **Escalation:** when `days_to_notice ≤ config escalation_days_before_notice`, `escalated_at` is null, `decision` is null, and no renewal has status submitted, approved or executed:
   - Recipients are `FinanceApprovalNotifier::escalationUsers($org)` plus users whose `job_title` contains an entry of `config('finance.contracts.escalation_job_titles')`, de-duplicated.
   - Send, set `escalated_at`, log key `renewal_escalation` with offset 0, and log an `escalated` event.
5. **Passed:** for `which` ∈ {notice, end} where the date is < today, `decision` is null and the status is non-terminal:
   - Skip when a log exists for key `deadline_passed.{which}` with offset 0.
   - Otherwise send `ContractDeadlinePassedNotification` to recipients ∪ `financeUsers` ∪ escalation users, and log it.
   - (The `notice_deadline_passed` / `expired` contract events were already written by derive.)

The listener `handle(ContractRenewed $e)` sends `ContractRenewedNotification` to `recipients($e->contract)` ∪ `financeUsers(org)`. It logs key `contract_renewed` with offset 0 against the old contract, which is idempotent.

### 4.5 `app/Services/Finance/FinanceCalendarService.php` (new; WP-A's controller calls these exact signatures)
```php
public function range(string $view, CarbonInterface $anchor): array          // [from, to]: month = month ±6 days to full weeks; quarter; year; timeline = anchor month −1 … +11
public function events(int $orgId, CarbonInterface $from, CarbonInterface $to, array $filters, User $viewer): array  // §7 Calendar `events[]`
public function timeline(int $orgId, CarbonInterface $from, CarbonInterface $to, array $filters, User $viewer): array
public function passed(int $orgId, array $filters, User $viewer, ?CarbonInterface $today = null): array
public function options(int $orgId): array                                  // filter option lists + eventTypes + legend
public function ics(User $user): string
public function csv(array $events): string
public function pdf(array $events, array $passed, string $title): string    // App\Services\Ea\PdfWriter (landscape table)
```
- Sources form a private registry: `['contracts' => …, 'budget' => cycles opens_on/closes_on, 'periods' => periods ends_on / closed_at / locked_at]`. Investments, invoices, gates and value reviews are added in P3 and P5 (C54).
- Contract event types: `contract_commencement`, `contract_window_opens`, `contract_notice_deadline`, `contract_end`, `contract_auto_renew` (`ends_on` + 1 day, auto contracts only).
- Colour by contract status: active → `green`, in_renewal_window → `amber`, notice_deadline_passed → `red`, and expired with no decision → `red`. Expired with a decision, renewed, terminated, replaced and draft → `grey`. Milestones → `blue`.
- Filters: `vendor_id`, `owner_id`, `member` (`me` ⇒ the viewer is owner, technical owner, procurement contact or team member), `cost_centre_id`, `type`, `value_band` (DoA key on `base_contract_value`), `status`, and `sources[]`.
- Every query applies `Contract::visibleTo($viewer)` and an explicit `organization_id`.
- The amount in `meta.base_value` is only included when the viewer `can('view it-finance-amounts')`; otherwise it is null.
- **ICS (RFC 5545):**
  - `BEGIN:VCALENDAR`, `VERSION:2.0`, `PRODID:-//Atheris//IT Finance//EN`, `CALSCALE:GREGORIAN`, `METHOD:PUBLISH`, `X-WR-CALNAME:IT Finance – {org name}`.
  - One `VEVENT` per event: `UID:{id}@{parse_url(APP_URL host)}`, `DTSTAMP` (UTC `Ymd\THis\Z`), `DTSTART;VALUE=DATE`, `DTEND;VALUE=DATE` (+1 day), `SUMMARY`, `DESCRIPTION`, `URL`, `CATEGORIES`, `STATUS:CONFIRMED`.
  - A `VALARM` (`TRIGGER:-P1D`) on notice-deadline events.
  - Text is escaped (`\\`, `\;`, `\,`, `\n`), lines are folded at 75 octets (multibyte-safe), and every line ends in CRLF.
  - The range runs from today − `ics_past_days` to + `ics_future_days`, restricted to the user's org and visibility. Amounts are never included.

### 4.6 `app/Services/Finance/FinanceDigest.php` (new; C63)

`send(?CarbonInterface $now = null): int`:
- Takes users, per org, with `notification_preferences['finance_daily_digest'] === true` and `can('view it-finance')`, whose org's `fin_settings.digest_hour == FinanceClock::now()->hour`.
- It runs once per day per user: log key `daily_digest.{Ymd}` with offset 0, notifiable = the User.
- Sections:
  - "Renewals due in 30 days" covers contracts where the user ∈ `recipients`, plus the **Passed** count.
  - "Approvals waiting for you" covers pending `finance.%` requests whose current step the user `canDecide`.
- It skips users with empty sections. P3 and P4 add sections for invoices and variances.

`app/Http/Controllers/SettingsController.php` (edit): add `'finance_daily_digest' => false` to `NOTIFICATION_PREFS`.

### 4.7 `app/Services/Finance/FinanceApprovalNotifier.php` (edit)

- `url()` adds `'finance.contract_renewal' => route('finance.renewals.show', $subject)`, before the default arm.
- `amountText()` reads `approvalBaseAmount()` when the subject has it.

### 4.8 `routes/console.php` (edit, in the IT Financial Management block)
```php
Artisan::command('fin:contracts-sweep {--date= : Y-m-d, run as if today (demo/testing)}', function (ContractSweeper $s) {
    $r = $s->sweep($this->option('date') ? CarbonImmutable::parse($this->option('date'), config('finance.timezone')) : null);
    $this->info(sprintf('%d derived, %d rolled, %d T-minus sent, %d escalated, %d passed notices.', $r['derived'], $r['rolled'], $r['notified'], $r['escalated'], $r['passed']));
})->purpose('Derive contract statuses, auto-renew, send renewal T-minus, escalation and passed notices (ATH-FIN-001 §5.4)');
Schedule::command('fin:contracts-sweep')->dailyAt('06:30')->timezone(config('finance.timezone'))->name('finance.contracts-sweep')->withoutOverlapping();
Artisan::command('fin:digest', function (FinanceDigest $d) { $this->info($d->send().' finance digest(s) sent.'); })
    ->purpose('Send the IT finance daily digest to opted-in users at their bank\'s digest hour');
Schedule::command('fin:digest')->hourly()->name('finance.digest')->withoutOverlapping();
```

### 4.9 Tests owned by WP-C

`tests/Feature/Finance/FinanceWorkflowStudioTest.php` (edit):
- `assertCount(3, $wfs)` becomes 4, plus `assertCount(2, $wfs['finance.contract_renewal']->stages)`.
- `has('processes.IT Financial Management', 3)` becomes 4 (two places).
- `summary.processes` changes from 14 to 15.

Filter: `--filter "FinanceWorkflowStudioTest|ContractBackendSmokeTest"`. Also check `php artisan schedule:list | grep fin:` and `php artisan event:list | grep ContractRenewed`; the listener should appear once.

---

## 5. WP-D, P1 carry-over (fin-backend-engineer #2, DB `it_security_risk_fin_d_test`)

**Overlap analysis:** WP-D touches only P1 files (`Budget.php`, `BudgetService.php`, `SupplementaryBudgetService.php`, `BudgetLedger.php`, `BudgetController.php`, `Concerns/PresentsBudgets.php`), its own new test, and P1 tests if their assertions shift. WP-A, WP-B and WP-C edit none of these. **So WP-D is not folded into WP-A.** It runs in parallel with WP-A. D5 alone waits for WP-A's `Contract` model.

| item | exact code | fix |
|---|---|---|
| **M4** (FIN-07.06) | `app/Models/Finance/Budget.php:37` `LOCKED_WRITABLE` includes `status`; the `booted()` updating guard (lines 49-57) lets `$baseline->update(['status'=>'draft'])` through | Add `public const LOCKED_STATUS_MOVES = ['approved' => ['baseline', 'revised'], 'baseline' => ['revised'], 'revised' => []];`. In `updating`, when the original status ∈ LOCKED: (a) if `status` is dirty and the new value ∉ `LOCKED_STATUS_MOVES[original]`, throw `workflow` "A {orig} version cannot move to {new}." (b) `superseded_by_id` may change only together with a move to `revised`. (c) `baseline_slot`, `baselined_at` and `baselined_by_id` may be set only with a move to `baseline`; on a move to `revised`, `baseline_slot` may only go to null. Today's `baseline()` paths satisfy all of this; check `BudgetService::baseline` lines 728-741. |
| **M3a** diff lineage | `BudgetService::diff` (line ~908) matches `$aLines->firstWhere('id', $bl->source_line_id)` only, so v1 vs v3 shows every line as added or removed | Make `a` the older version (swap when `a.version_no > b.version_no`). Preload every line of the versions with that `budget_code` whose `version_no` is between a and b (`id => source_line_id`). For each b line, walk `source_line_id` up the chain, bounded by the version count, until it reaches a line whose `budget_id = a.id`. Keep the `line_no` fallback only for lines with no lineage. |
| **M3b** diff amounts | the diff `mini`, `base_delta`, `periods` and `totals` use original `base_annual_amount` / `total_*`, while `cloneLine` (line ~199) folds the previous version's **current** amounts into the new original, so virements look like revision edits | Per line, report `a:{…, original_base, adjustments_base, current_base}` and `b:{…}` the same way. Add `revision_delta = b.original − a.current` (what the revision changed relative to what it was cloned from), `current_delta = b.current − a.current` and `periods` = per-period `b.original − a.current` (`BudgetLedger::currentByPeriod`). `change = 'changed'` when the non-amount watched fields differ or `revision_delta` ≠ 0. Totals: `{original_delta, adjustments: {a, b}, current_delta, capex_delta, opex_delta}`, with capex/opex on current. Keep `base_delta` as an alias of `revision_delta` for the existing UI. |
| **M3c** Index KPI | `BudgetController@index` line ~66 `total_baseline_base` sums `total_base_amount` (original) | Load the adjustments for each listed budget in one grouped query (`fin_budget_adjustments` joined to lines, grouped by `budget_id`). Summary: `total_baseline_base` (original), `total_baseline_adjustments_base`, `total_baseline_current_base`. `budgetSummary()` adds `adjustments_base` and `current_base_amount` per row, using the preloaded map to avoid N+1. Add the new keys to `PresentsBudgets::MONEY_KEYS`. |
| **M3d** by-period / priority / rgt | `PresentsBudgets::totalsPayload` lines ~130-141 sum `base_amounts` / `base_annual_amount` | `linesPayload` adds `current_base_amounts` (period → current base, from per-line per-period adjustment sums in one grouped query). `by_period`, `by_priority` and `by_rgt` sum **current**. Add `by_period_original`, `capex_current` and `opex_current`. `MONEY_MAPS` gets `current_base_amounts` and `by_period_original`; `MONEY_KEYS` gets `capex_current` and `opex_current`. |
| **M1a** re-quote | `SupplementaryBudgetService::validated` line ~209 converts at `now()` spot only at create and update | `submit()` re-runs `CurrencyService::convert($org, amount, currency, FinanceClock::today(), config('finance.fx.planning_rate_type'))` and force-fills `fx_rate`, `fx_rate_type`, `fx_rate_date` and `base_amount` **before** `FinanceApprovals::start`, so the DoA snapshot uses today's rate. `validated()` uses the planning rate type too (fallback to spot through the config). |
| **M1b** ledger rate | `BudgetLedger::post` lines 63-70 derives the line-currency amount as base ÷ the line's **budget** rate (USD 18,000 books as USD 18,213) | `post(..., ?string $lineAmount = null)` stores `$lineAmount` when given. In `SupplementaryBudgetService::apply`: when the request currency = the target line currency, spread the request `amount` with the same weights as the base spread and pass the per-period line amounts. A **new** supplementary line is created in the **request currency**, not the base currency, so the amounts match exactly. Different currencies keep base ÷ line rate (documented). `BudgetLedger` gains `currentByPeriodLine(BudgetLine): array` (line currency = period `amount` + adjustment `amount`), and `BudgetService::cloneLine` (line ~210) uses it instead of base ÷ `fx_rate` when folding. |
| **D5** contract link on lines (after WP-A) | `BudgetService::saveLine` line ~351 refuses `contract_id`; `PresentsBudgets::dimensionsPayload` returns `'contracts' => []` | Accept `contract_id` when `Contract::withoutGlobalScopes()->where('organization_id',$org)->whereKey($id)->exists()`; `investment_id` stays refused (P5). `dimensionsPayload.contracts` = live and draft contracts `{id, contract_code, title}` ordered by code. |

**Tests:**
- New `tests/Feature/Finance/BudgetDomainReviewRegressionTest.php`:
  - `test_locked_versions_refuse_illegal_status_moves` covers baseline → draft, approved → draft, revised → baseline, and baseline → revised without supersession. The legal `baseline()` / `revise()` path still passes.
  - `test_diff_follows_lineage_across_three_versions`: v1 vs v3 shows unchanged and changed lines, not added or removed ones.
  - `test_diff_separates_revision_change_from_virements`: a transfer is applied on v1, then the version is revised. The transferred line has `revision_delta` 0 and a non-zero `current_delta` only when a later adjustment is made.
  - `test_index_kpi_and_totals_use_current_amounts`: a supplementary request applied to a baseline raises `total_baseline_current_base` and `by_period`.
  - `test_supplementary_is_requoted_at_submit`: a new rate dated today before submit changes `base_amount` and `approval_requests.context.base_amount`.
  - `test_foreign_currency_top_up_books_exact_line_amount`: a USD line at a 1500 planning rate gets a USD 18,000 request at a 1520 rate, and Σ adjustment `amount` = 18000.00.
  - `test_new_supplementary_line_takes_request_currency`.
  - `test_budget_line_accepts_same_bank_contract_and_refuses_foreign` (D5).
- WP-D may also edit `BudgetLifecycleTest`, `BudgetTransferTest` and `BudgetBackendSmokeTest` where assertions encode the old semantics (for example `BudgetTransferTest:275` `'spot'`, which stays valid when no budget rate exists). It must not edit `RouteSmokeTest`, which QA owns. The seeded mid-year diff should still report 3 changed lines under `revision_delta`.
- Filter: `--filter "BudgetDomainReviewRegressionTest|BudgetLifecycleTest|BudgetTransferTest|BudgetWorkbookTest|BudgetBackendSmokeTest|FinanceDoaFxAndCurrencyGuardTest"`.

---

## 6. WP-E, QA (fin-qa-engineer, DB `it_security_risk_fin_qa`, after B, C and D)

### 6.1 Demo data: `database/seeders/FinanceContractDemoSeeder.php` (new; C69) + `DatabaseSeeder.php` (call right after `FinanceDemoSeeder`)

The P1 `FinanceDemoSeeder` is guarded on budgets, so it would never add contracts to databases that are already seeded. A separate seeder with its own guard fixes that.
- **Guard:** `Contract::withoutGlobalScopes()->where('organization_id', $org)->exists()`.
- Seed with `mt_srand(8121/8122)` and `Auth::setUser($admin)`.
- Every date is relative to `FinanceClock::today()`; never hardcode 2026 or 2027.
- Use `whereRaw('lower(name) like ?')`, never `ilike` (P1 M5).
- Look up vendors by name, and `firstOrCreate` missing ones (Kano: CrowdStrike, Fortinet) with a `vendor_code` of `{P}-VEN-9xx`.
- Call `FinancePolicySeeder::ensureDefaults($org)` before any renewal is submitted.
- Codes `{P}-CON-0001` and `{P}-REN-0001` use the `recode` pattern.
- Wrap historic actions (executed, rejected, auto-rolled) in `Event::fakeFor(..., [FinanceApprovalRequested::class, FinanceApprovalDecided::class, ContractRenewed::class])`. Pending renewals use the real engine, as the P1 pending items did.

**Per bank:**
- Kano gets 30 contracts and First Bank 36, all built through `ContractService` / `RenewalService`.
- Types and estate: Finacle AMC, Microsoft EA (USD), Oracle DB support (USD), Cisco SmartNet, Fortinet (Kano) / Palo Alto (FBN), CrowdStrike (USD), Interswitch switching, NIBSS NIP, MTN/Airtel/Globacom links, AWS / Azure commitments, Rack Centre colocation, Tenable, Qualys, KPMG advisory (professional services), hardware warranties, and Temenos T24 licence (FBN only).
- FBN also has GBP and GHS contracts for its UK and Ghana legal entities.
- End dates are spread across −60 to +540 days, so every calendar view is populated.

| required case (§13) | Kano / FBN example | how |
|---|---|---|
| 2 in renewal window | Microsoft EA (ends +75, notice 30, lead 90) and Cisco SmartNet (ends +60, notice 30) | one has no renewal (shows under "Window open, no renewal yet"); one has an `open` renewal with a draft recommendation |
| 1 past notice deadline (Passed) | Fortinet FortiGate support (Kano) / Palo Alto (FBN): ends +20, notice 60, no decision | status `notice_deadline_passed`, in Passed |
| 1 lapsed after end (Passed, end) | Qualys VM subscription: ended −12, manual, no decision | status `expired`, in Passed |
| 1 auto-renewing | MTN MPLS link: `renewal_type=auto`, `auto_renew`, ends +120 | active |
| 1 already auto-rolled | Airtel internet link: ended −10, auto | `RenewalService::autoRenew` makes old `renewed` and the successor active |
| 1 terminated | Infobip legacy SMS gateway | `terminate` renewal submitted → approved → executed |
| renewals in progress | (a) `submitted`, pending "Head of IT review", contract owner `digital@`; (b) Finacle AMC renegotiated, uplift 12 % so `requires_cfo=yes`, headofit@ approved, pending CFO; (c) `approved` awaiting execution; (d) `executed` renewal with successor; (e) `rejected` with reason | Owners are never `headofit@`, so a decider always exists (P1 C33 lesson) |
| deliberate gaps | one contract with no owner; one over-licensed (deployed > entitled); one with DPA `pending` and processor role; one draft | don't clean them up |

- Every contract gets 2–4 team members (`project_lead` plus members) from the bank's IT users, and some links: Kano to assets and risks, FBN to EA applications, business services and assets.
- 1–2 documents go on the major contracts. Store a small PDF generated with `PdfWriter` on the `local` disk under `evidence/{org}/contracts/`, with its sha256.
- **T-minus history:** for offsets already passed in the current term, insert `NotificationLog` rows and `notified` events directly, without sending, so the Timeline tab shows history.
- Users: no new ones. `headofit@` and `cfo@` receive `approve it-finance-contracts` through the `Finance Approver` role.

### 6.2 Tests `tests/Feature/Finance/` (new)

- **`ContractRenewalLifecycleTest`:**
  - window/notice computed on create and recomputed on edit, with the lead-days override against the settings default
  - every status-derivation path, including moving back to active when dates move forward
  - manual contract with no decision goes to `notice_deadline_passed`, then `expired`
  - auto-renew rolls exactly once, even when the sweep runs twice, with the escalation uplift applied
  - recommend → submit → approve (configured stages: Head of IT, then CFO only when `requires_cfo`) → execute creates a successor linked both ways, copies team and links, and dispatches `ContractRenewed` with the budget line
  - the `replace` and `terminate` outcomes
  - SoD: the contract owner and the initiator cannot approve, and nobody decides two stages
  - only one active renewal per contract
  - documents create Evidence with a sha256
  - masking without `view it-finance-amounts`
  - visibility: a budget owner, a team member and procurement
  - cross-bank 404
  - activity-log entries for create, update, approve and execute
- **`ContractNotificationSweepTest`:**
  - `Carbon::setTestNow` on each offset day, `Notification::fake()`, `fin:contracts-sweep` run twice. `assertSentToTimes(..., 1)` per offset for the end series and the notice series.
  - Recipients are exactly team ∪ owner ∪ technical owner ∪ procurement; an unrelated user and the other bank get nothing.
  - per-contract offset override
  - catch-up within 3 days, and nothing outside that window
  - escalation at notice −7, once, to Head of IT, CIO and CFO, and not when a renewal is submitted
  - deadline-passed notice once per `which`
  - auto-roll sends `ContractRenewedNotification` to the team and Finance
  - digest: once, at the digest hour, to opted-in users only
  - `--date=` option
  - schedule registration (`fin:contracts-sweep` at 06:30, `fin:digest` hourly)
  - every contract notification renders `toMail` and `toArray`
- **`CalendarTest`:**
  - events for every contract milestone, cycles and periods in range
  - colours by status
  - month, quarter, year and timeline ranges
  - each filter (vendor, owner, me, cost centre, type, value band, status, sources)
  - Passed list sorted by days overdue and cleared once a decision is recorded
  - ICS: unsigned → 403, tampered `u` → 403, rotate invalidates the old URL, an inactive user → 403, body is valid (CRLF, ≤ 75 octets per line, balanced BEGIN/END, UID/DTSTAMP/DTSTART on every VEVENT), no other-bank events, no amounts
  - CSV and PDF export; XLSX gives an error on `format`
  - a bank with no data renders an empty calendar
- **`tests/Feature/RouteSmokeTest.php`** (edit):
  - Exclude `finance.calendar.ics` from the parameterless loop.
  - The finance workflow count goes from 3 to 4.
  - New `test_finance_contracts_estate_is_seeded_and_renders_for_every_demo_tenant`:
    - The contract count per bank is between 25 and 40.
    - Show renders every tab, and Renewals index and show render.
    - Calendar renders in all four views.
    - Passed holds at least 1 notice and 1 end entry.
    - Each pending renewal step has a decider (`eligibleUsers` with `can('approve it-finance-contracts')` and `canDecide`).
    - Run `fin:contracts-sweep --date=` against a seeded contract and assert one `renewal_due` log appears.
- Filter: `--filter "ContractRenewalLifecycleTest|ContractNotificationSweepTest|CalendarTest|FinanceWorkflowStudioTest|FinanceTenancyAndRoutesTest|ContractBackendSmokeTest"`.

### 6.3 Playwright (`tests/Playwright/specs/`, seeded scratch DB; serve with `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch php artisan serve --host=127.0.0.1 --port=8000`)

- **`finance-contract-renewal.spec.js`:**
  1. Log in with fresh contexts and inline logins, not stored state.
  2. As `procurement@kanoheritage.ng`, create a contract titled "E2E renewal {timestamp}": vendor MTN, ends +60, notice 30, value small. Choose "Save and activate". The status chip reads "In renewal window".
  3. Open the renewal, recommend `renew_renegotiated` (12 months, uplift < 10 %), and submit.
  4. As `headofit@`, go to Renewals, open it and approve. The status becomes Approved; there is a single stage because `requires_cfo=no`.
  5. As `procurement@`, execute. The successor link is visible and the old contract shows Renewed.
  6. Anchor on `getByRole('heading', …)` and use `.filter({ visible: true })`.
- **`finance-calendar.spec.js`** (stored state `kano-heritage-ciso.json`):
  1. The heading "IT Finance Calendar" is visible.
  2. Switch Month, Quarter, Year and Timeline.
  3. The Passed panel lists "Fortinet"; click through to the contract heading.
  4. The vendor filter narrows the events.
  5. The subscribe input contains `/it-finance/calendar.ics?`.
  6. `request.get(feedUrl)` returns 200 with `text/calendar` and a body starting `BEGIN:VCALENDAR`.
  7. The CSV export triggers a download.
- Run them with `npx playwright test tests/Playwright/specs/finance-*.spec.js --project=chromium-desktop`.

---

## 7. Props contract (WP-A ⇄ WP-B). Decimals are strings; dates are `Y-m-d`; timestamps ISO-8601; masked amounts are `null`

The shared shapes used below are:
- `UserMini {id, name}`
- `ContractMini {id, contract_code, title}`
- `Opt {value, label}`
- `Options {types, statuses, renewalTypes, billingFrequencies, licenceMetrics, dpRoles, dpaStatuses, decisions, teamRoles, documentKinds, linkTypes}`. Each is an `Opt[]`.

**ContractRow:** `{id, contract_code, title, type, status, vendor:{id,name}|null, owner:UserMini|null, technical_owner:UserMini|null, cost_centre:{id,code,name}|null, commenced_on, ends_on, term_months, notice_period_days, renewal_window_opens_on, notice_deadline_on, days_to_end, days_to_notice, renewal_type, auto_renew, is_auto, currency, contract_value, base_contract_value, annualised_base_value, amount_band, amount_band_label, decision, open_renewal:{id,renewal_code,status}|null, in_passed, licence_position}`

**ContractFull** = ContractRow plus:

`{legal_entity:{id,code,name}|null, category:{id,code,name}|null, procurement_contact:UserMini|null, budget_line:{id,budget_id,label,current_base_amount}|null, description, renewal_lead_days, effective_lead_days, notification_offsets, effective_offsets:int[], fx_rate, fx_rate_date, billing_frequency, amount_per_billing, licence_metric, licence_quantity, deployed_quantity, payment_terms_days, price_escalation_pct, next_term_forecast, data_processing_role, dpa_status, sla_summary, exit_terms, key_clauses:[{key,label,value}], external_reference, renewed_from:ContractMini|null, renewed_to:ContractMini|null, decision_at, decision_by:UserMini|null, terminated_on, termination_reason, last_notified_offset, escalated_at, days_to_window, created_at, updated_at}`

**RenewalSummary:**

`{id, renewal_code, status, recommendation, opened_on, justification, benchmark_notes, alternatives_considered, proposed_term_months, proposed_commences_on, proposed_value, proposed_currency, proposed_base_value, uplift_pct, uplift_band, amount_band, amount_band_label, requires_cfo, is_auto, requested_by:UserMini|null, submitted_at, approved_by:UserMini|null, approved_at, rejection_reason, executed_by:UserMini|null, executed_at, new_contract:ContractMini|null, contract:{id, contract_code, title, vendor:{id,name}|null, owner:UserMini|null, ends_on, notice_deadline_on, days_to_notice, base_contract_value, annualised_base_value, status}}`

| page · route | props |
|---|---|
| `Finance/Contracts/Index` · `finance.contracts.index` | `filters{q,status,type,vendor_id,owner_id,cost_centre_id,due,mine,passed}`, `contracts: ContractRow[]`, `summary{count,active,in_window,passed,due_30,due_60,due_90,auto_renew,value_base_total,annualised_base_total}`, `vendorSpend:[{vendor:{id,name},contracts,base_contract_value,annualised_base_value}]` (top 10, contracts only; expenses P3), `options:Options`, `vendors:[{id,name}]`, `owners:UserMini[]`, `costCentres:[{id,code,name}]`, `baseCurrency`, `can{create,viewAmounts}` |
| `Finance/Contracts/Create` · `finance.contracts.create` / `.edit` | `contract: ContractFull\|null`, `options`, `vendors`, `users:[{id,name,job_title}]`, `costCentres`, `legalEntities:[{id,code,name}]`, `categories:[{id,code,name,kind}]`, `budgetLines:[{id,label,cost_centre_id,current_base_amount}]` (baseline lines of the open fiscal years), `currencies:[{code,decimals,is_base}]`, `defaults{renewal_lead_days,notification_offsets,notice_period_days,currency,owner_id}`, `baseCurrency`, `can{viewAmounts}`. Submit fields = §2.6 `StoreContractRequest` keys + `activate` (create only). |
| `Finance/Contracts/Show` · `finance.contracts.show` | `contract: ContractFull`, `team:[{id,role,user:{id,name,job_title,email}}]`, `links:[{id,type,linkable_id,label,href\|null,note}]`, `documents:[{id,kind,title,signed_on,expires_on,sha256,file_name\|null,download_url\|null,uploaded_by:UserMini\|null,created_at}]`, `renewal: RenewalSummary\|null` (active or latest), `renewals: RenewalSummary[]`, `approval` (`FinanceApprovals::trail` of the active renewal, or null), `events:[{id,type,label,payload,user:UserMini\|null,occurred_at}]`, `spend{contract_value,base_contract_value,annualised_base_value,next_term_forecast,billing:[{due_on,amount,base_amount}],budget_line:{id,budget_id,label,current_base_amount}\|null,actual_to_date:null}`, `daysToEnd`, `daysToNotice`, `daysToWindow`, `linkOptions{asset,ea_application,business_service,risk}:[{id,label}]`, `users:[{id,name,job_title}]`, `options`, `tab`, `baseCurrency`, `can{edit,activate,recompute,terminate,manageTeam,addDocument,openRenewal,viewAmounts}` |
| `Finance/Renewals/Index` · `finance.renewals.index` | `filters{status,recommendation,q,mine}`, `renewals: RenewalSummary[]`, `summary{open,submitted,approved,executed_90d,rejected}`, `pendingWindow: ContractRow[]` (in window, no renewal), `options{statuses,decisions}`, `can{viewAmounts}` |
| `Finance/Renewals/Show` · `finance.renewals.show` | `renewal: RenewalSummary`, `contract: ContractFull`, `approval` (trail incl. `context`), `events` (contract events of type `renewal_*`, `decision_recorded`, `renewed`, `terminated`), `options{decisions}`, `currencies`, `baseCurrency`, `can{recommend,submit,withdraw,approve,reject,execute,viewAmounts}` |
| `Finance/Calendar` · `finance.calendar` | `view`, `anchor`, `from`, `to`, `today`, `events:[{id,type,title,start,end,color:'green'\|'amber'\|'red'\|'grey'\|'blue',status,href,meta:{source,contract_code?,vendor?,owner?,cost_centre?,contract_type?,value_band?,base_value?,days_left?,auto_renew?}}]`, `timeline:[{id,contract_code,title,vendor,start,end,window_opens_on,notice_deadline_on,status,color,href}]`, `passed:[{id,contract_code,title,vendor,owner,which:'notice'\|'end',date,days_overdue,status,base_value,auto_renew,href}]`, `filters{vendor_id,owner_id,member,cost_centre_id,type,value_band,status,sources:string[]}`, `options{vendors,owners,costCentres,types,valueBands:[{key,label}],statuses,sources:Opt[],eventTypes:[{type,label,color}]}`, `legend:[{color,label}]`, `feed{url,rotate_url}`, `exportUrls{csv,pdf}`, `can{export,viewAmounts}` |
| `Settings/Notifications` (edit) | `prefs.finance_daily_digest: bool` |

Writes (flash on success, `errors.*` on refusal):
- `team.store {user_id, role}`
- `links.store {type, id, note}`
- `documents.store` (multipart) `{kind, title, file?, signed_on?, expires_on?}`
- `renewals.recommend {recommendation, proposed_term_months, proposed_commences_on, proposed_value, proposed_currency, justification, benchmark_notes, alternatives_considered}`
- `renewals.approve|reject {note}`
- `renewals.execute {terminated_on?, commenced_on?}`
- `contracts.terminate {reason, terminated_on?}`

---

## 8. File ownership (no overlaps)

| WP | files |
|---|---|
| **A** | `config/finance.php`; migrations `2027_02_10_000001…`, `2027_02_10_000003…`; models `app/Models/Finance/{Contract,ContractTeamMember,ContractLink,ContractDocument,ContractEvent,Renewal,CalendarFeed}.php`; `app/Services/Finance/{FinanceClock,ContractStatusDeriver,ContractService,RenewalService}.php` (new), `FinanceApprovals.php` (edit); `app/Events/Finance/ContractRenewed.php`; `app/Policies/Finance/FinancePolicy.php`; `app/Services/Approvals/ApprovalProcesses.php`; `app/Http/Controllers/Finance/{ContractController,ContractRelationsController,RenewalController,FinanceCalendarController}.php`, `Concerns/PresentsContracts.php`; `app/Http/Requests/Finance/*` (7 new); `routes/web.php`; `database/seeders/RolesAndPermissionsSeeder.php`; tests `ContractBackendSmokeTest` (new), `FinanceTenancyAndRoutesTest`, `Workflows/WorkflowStudioModuleTest` |
| **B** | `resources/js/Config/navigation.js`, `resources/js/Utils/finance.js`, `Pages/Finance/Contracts/{Index,Create,Show}.jsx`, `Pages/Finance/Renewals/{Index,Show}.jsx`, `Pages/Finance/Calendar.jsx`, `Components/Finance/Contracts/*`, `Components/Finance/Calendar/*`, `Pages/Settings/Notifications.jsx`, `Components/Finance/Budgets/{LineFormModal,VersionsDiff}.jsx` |
| **C** | migration `2027_02_10_000002…`; `app/Services/Finance/{ContractSweeper,FinanceCalendarService,FinanceDigest}.php` (new), `FinancePolicySeeder.php`, `FinanceApprovalNotifier.php` (edit); `app/Notifications/Finance/{ContractRenewalDue,ContractRenewalEscalation,ContractDeadlinePassed,ContractRenewed,FinanceDailyDigest}Notification.php`; `app/Listeners/Finance/SendContractRenewedNotification.php`; `routes/console.php`; `app/Http/Controllers/SettingsController.php`; test `FinanceWorkflowStudioTest` |
| **D** | `app/Models/Finance/Budget.php`, `app/Services/Finance/{BudgetService,SupplementaryBudgetService,BudgetLedger}.php`, `app/Http/Controllers/Finance/BudgetController.php`, `Concerns/PresentsBudgets.php`; tests `BudgetDomainReviewRegressionTest` (new), and `BudgetLifecycleTest`, `BudgetTransferTest`, `BudgetBackendSmokeTest` only if an assertion encodes old semantics |
| **E** | `database/seeders/FinanceContractDemoSeeder.php` (new), `database/seeders/DatabaseSeeder.php`; tests `ContractRenewalLifecycleTest`, `ContractNotificationSweepTest`, `CalendarTest` (new), `tests/Feature/RouteSmokeTest.php`; `tests/Playwright/specs/finance-{contract-renewal,calendar}.spec.js` |

These files are touched by nobody: `ApprovalEngine.php`, `AppServiceProvider.php`, `Workflows/Process.jsx`, `FinanceDemoSeeder.php`, `FinanceFoundationSeeder.php`, `EvidenceController.php`.

---

## 9. §12 acceptance → test

| §12 / feature | test |
|---|---|
| FIN-06.01 register (M) | `ContractBackendSmokeTest`, `ContractRenewalLifecycleTest::test_register_fields_codes_and_fx` |
| FIN-06.02 lifecycle, derived nightly; "status derivation for every path" | `ContractRenewalLifecycleTest::test_status_derivation_every_path`, `…::test_manual_no_decision_notice_passed_then_expired`, `…::test_auto_renew_rolls_one_new_term` |
| FIN-06.03 window & notice computed and recomputed | `ContractRenewalLifecycleTest::test_dates_computed_on_create_and_recomputed_on_edit` |
| FIN-06.04 T-minus once per offset to whole team; escalation at notice −7 | `ContractNotificationSweepTest::test_end_series_once_per_offset_to_whole_team`, `…::test_notice_series…`, `…::test_escalation_at_notice_minus_seven_once`, `…::test_digest…` |
| FIN-06.05 approval executes successor linked both ways (+ expense request) | `ContractRenewalLifecycleTest::test_approved_renewal_executes_successor_linked_both_ways` (expense request: `ContractRenewed` dispatched; creation is P3, C52) |
| FIN-06.06 calendar events for all sources; correct passed list; `.ics` validates; export; filters | `CalendarTest::*` |
| FIN-06.07 licence compliance (S) | `ContractRenewalLifecycleTest::test_licence_position_flags` (manual deployed quantity only) |
| FIN-06.08 documents & clauses (S) | `ContractRenewalLifecycleTest::test_documents_store_evidence_with_hash` |
| FIN-06.09 price escalation (S) | covered by the auto-roll test (successor value) and the `next_term_forecast` assertion; the budget seed is deferred |
| FIN-06.10 vendor spend (M) | `ContractBackendSmokeTest::test_index_vendor_spend` (contracts part; the expenses part is P3) |
| FIN-07.01 process in Workflow Studio, SoD, default when unconfigured | `FinanceWorkflowStudioTest` (4 processes), `ContractRenewalLifecycleTest::test_renewal_sod…`, `…::test_unconfigured_default_needs_contracts_approver` |
| FIN-07.03 notification classes render mail + database | `ContractNotificationSweepTest::test_contract_notifications_render_mail_and_database` |
| FIN-07.05 unified calendar (cycles, periods) | `CalendarTest::test_cycle_and_period_milestones` |
| FIN-07.06 audit | `ContractRenewalLifecycleTest::test_lifecycle_is_activity_logged` |
| FIN-07.10 masking | `ContractRenewalLifecycleTest::test_amounts_masked…`, `CalendarTest::test_ics_has_no_amounts` |
| Tenancy | `FinanceTenancyAndRoutesTest`, `RouteSmokeTest` (orgs 2 and 3; org 1 empty) |
| P1 carry-over M1, M3, M4 | `BudgetDomainReviewRegressionTest::*` |

---

## 10. Spec ⇄ codebase conflicts (numbering continues from P1's C48), with recommendations

| # | conflict | recommendation |
|---|---|---|
| C49 | §6 renewal default "owner → Head of IT", but §6 SoD says "contract owner may recommend but not approve". The engine also blocks the initiator, and the owner usually submits, so an owner stage would deadlock. | No owner stage. Defaults: Head of IT, then CFO. The owner's voice is the recommendation; `owner_cannot_approve` uses `contract_owner_id`. **Needs sign-off.** |
| C50 | "+CFO above 10m **or** uplift > 10 %" is an OR; engine stages take one condition field. Two CFO stages would collide with "nobody decides two stages". | Derived `requires_cfo` accessor with config thresholds (`renewal_cfo_from`, `renewal_cfo_uplift_pct`), measured on the proposed total in base currency. The §6 conditions `amount_band`, `decision`, `contract_type` and `uplift_band` are also registered. |
| C51 | §6 default permission `approve it-finance-contracts` is held only by IT Finance Controller and Admin; the Head of IT and CFO demo users can't approve. | Grant it to `Finance Approver` (seeder + migration 000003). **Needs sign-off.** |
| C52 | §5.4/FIN-06.05 "creates an ExpenseRequest" needs P3 tables. | Dispatch `ContractRenewed`. P3 adds a listener that creates the request and fills `expense_request_id`, which ships as a null column. |
| C53 | `fin_contract_links` targets include `Investment` (P5). | Allowlist asset, ea_application, business_service, risk; P5 adds investment. |
| C54 | The calendar spec lists investment, invoice, gate and value-review sources (P3/P5). §12 asks for "all sources". | Source registry in `FinanceCalendarService`. P2 ships contracts, budget cycles and periods; later phases register theirs. |
| C55 | `.ics?token=` with a "signed per-user feed". | Laravel `signed` middleware with `?u=&v=&signature=` and a per-user `fin_calendar_feeds.version` for rotation. No secret is stored; the URL stays stable until rotated. The route sits outside auth. |
| C56 | Export "XLSX/PDF"; no spreadsheet library (P1-2). | CSV + PDF (existing `App\Services\Ea\PdfWriter`); XLSX returns a `format` error. The Print button covers "printable". |
| C57 | FIN-01.09 "notice-period default" has no `fin_settings` column. | `config('finance.contracts.default_notice_period_days')`; move it to per-tenant settings with P1 M5 in P7. |
| C58 | §4.4 lacks `fx_rate*` columns, but §4 says every amount stores its rate. | Add `fx_rate`, `fx_rate_type`, `fx_rate_date` (and the proposed equivalents on renewals). |
| C59 | Three different "no decision" predicates (status / Passed / escalation). | Implemented literally (§2.4 table). |
| C60 | Exact-day equality (`ends_on − today == offset`) silently skips a notification if a nightly run is missed. | Tolerance of `catch_up_days` (3): it sends the smallest offset whose window contains today, still exactly once per offset. |
| C61 | T-minus scope "active/in-window". | Also send the **end** series for `notice_deadline_passed` contracts; suppress both series once a decision is recorded. |
| C62 | `fin_notification_logs` has no uniqueness. | Unique (type, id, key, offset, user) plus claim-before-send. Contract keys always carry a non-null offset. |
| C63 | Digest "preference" has no finance field. | `users.notification_preferences.finance_daily_digest`. P2 digest sections are renewals and approvals; P3/P4 append theirs. |
| C64 | Optional webhook channel (Teams/Slack); `BaseNotification` has none. | Defer to the integrations work (FIN-07.09). |
| C65 | `draft` status has no action; early termination has no route in §9. | Add `finance.contracts.activate` and `finance.contracts.terminate` (additive). |
| C66 | Auto-roll value unspecified. | Successor value = current × (1 + `price_escalation_pct`) (FIN-06.09). |
| C67 | §4.4 event types lack renewal and team events. | Additive types listed in §2.3. |
| C68 | ATH-FIN-002 decision 3: Vendor Management keeps a summary synced from the primary contract. | `syncVendorSummary` writes the vendor's contract dates and value from its largest live finance contract; vendors without finance contracts are untouched. |
| C69 | "Extend FinanceDemoSeeder": its guard is on budgets, so already-seeded databases would never get contracts. | New `FinanceContractDemoSeeder` with its own tenant guard, right after `FinanceDemoSeeder`. **Needs sign-off** (deviates from the brief's wording). |
| C70 | §8 cost-centre restriction vs procurement's need to see every contract. | Contract visibility rule in §2.5 (Procurement Officer sees all; team members and owners see theirs). |
| C71 | `FinanceApprovals` hard-codes `approve it-finance` and `base_amount`. | `$permission` parameter and an `approvalBaseAmount()` hook; budget behaviour is unchanged. |
| C72 | `Evidence::SUBJECTS` excludes Contract, and `EvidenceController@download` authorises through subjects. | Finance-specific download route. Contract documents also appear in the Evidence library list (evidenceable = Contract), which is acceptable. |
| C73 | App timezone is UTC; Nigerian banks run on WAT, so "today" flips at 23:00 UTC. | `config('finance.timezone')='Africa/Lagos'` through `FinanceClock`; the sweep is scheduled at 06:30 in that timezone. Per-org timezone is P7. |

## 11. Risks, open questions, deferrals

- **Sign-off needed:** C49 (no owner stage), C51 (grant to Finance Approver), C69 (separate seeder). Also Q1: may the seeder's T-minus *history* write log rows without sending? Recommended yes.
- **R1:** the seeder's pending renewals start real engine requests, so notifications queue to `jobs` (as in P1). Acceptable.
- **R2:** the Head of IT stage matches both `headofit@` and `itops@`. `itops@` owns cost centres but is not a Finance Approver, so the notifier's permission filter drops them as an approver. They do receive escalations (by design, same job-title rule).
- **R3:** the `signed` URL is tied to `APP_KEY` and `APP_URL`. Rotating the key or changing the host breaks every subscribed feed; document this in the help text.
- **R4:** the ICS route is outside auth, so any defect there leaks across tenants. `CalendarTest` must assert org isolation and the 403 paths; the security reviewer should look at it first.
- **R5:** the WP-D M3 diff semantics change the JSON (keys are added, `base_delta` is kept), and `RouteSmokeTest` expects "3 changed". The orchestrator must re-check it in SMOKE.
- **Deferred:**
  - Contracts CSV import (P3, with `fin_import_batches`).
  - Licence counts from Asset Management / EA (manual `deployed_quantity` only).
  - Escalation-driven next-year budget seed (FIN-06.09 budget part).
  - The expenses half of vendor spend (P3).
  - Custom fields (FIN-01.11).
  - Webhook channel and API (FIN-07.09).
  - Renewals-due report and the "contracts past notice deadline" KRI (P4).
  - Dashboard `renewalsDue` (P4).
  - Investment, invoice and value-review calendar sources (P3/P5).
