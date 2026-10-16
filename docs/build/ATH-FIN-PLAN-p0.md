# ATH-FIN-PLAN-p0: IT Financial Management, Phase P0 (Foundation)

**Spec:** ATH-FIN-001 §3.1 (FIN-01.01 to .12), §4.1, §8, §9 (settings routes only), §10 (nav group + `Finance/Settings`). **Orchestration:** ATH-FIN-002 §2 P0 row, §4 P0 prompt, §6 decisions 1 to 3. **Branch:** `feature/it-finance-p0`.
**Builders run in parallel.** BACKEND owns every PHP/config/test file listed in §2. FRONTEND owns every JS/JSX file listed in §3. Neither touches the other's files. The interface between them is §4 (props contract).

**DB rule (all agents):** never touch `it_security_risk`. Suite: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_test php vendor/bin/phpunit --filter <Class>`. Seed: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch php artisan migrate:fresh --seed`. Smoke: `SMOKE=1 DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch php vendor/bin/phpunit --filter RouteSmokeTest`. Frontend check: `npm run build`. Run Pint only on the files you touched.

---

## 1. Context and conventions found

| concern | pattern to copy (path) | what to take from it |
|---|---|---|
| Strict tenancy | `app/Models/Traits/BelongsToOrganization.php` | Global `OrganizationScope`, auto-fills `organization_id` on create. Finance uses this trait only, never `BelongsToTenant`. |
| Record codes | `app/Models/Concerns/GeneratesSequentialCode.php`, used in `app/Models/Change/ChangeRequest.php:158` | Not needed in P0, because the §4.1 tables have user-entered `code`s. It starts in P1 (`KHB-BUD-`). |
| Audit | `app/Models/Vendor.php:116` (`Spatie\Activitylog\Models\Concerns\LogsActivity`, `Spatie\Activitylog\Support\LogOptions`) | Change models do **not** use LogsActivity. The spec requires it (FIN-07.06), so finance follows Vendor. The `activity_log` diff column is `attribute_changes` (v5). |
| Model style | `app/Models/Change/ChangeRequest.php` | `$guarded = []`, enum `const` arrays, explicit `$casts`, typed relations, a one-paragraph docblock that cites the spec. |
| Migration style | `database/migrations/2027_01_15_000001_create_change_management_tables.php` | `unsignedBigInteger('organization_id')->index()` (no FK on org), `foreignId()->constrained()` for in-module parents, docblock with the spec reference. |
| Permission migration for existing DBs | `database/migrations/2027_01_15_000002_grant_change_management_permissions.php` | Idempotent `DB::table` inserts, grants skipped when the role is absent (a fresh DB gets them from the seeder), `forgetCachedPermissions()`. |
| Permissions/roles | `database/seeders/RolesAndPermissionsSeeder.php` (`$modules` loop, `seedChangeManagerRole()`) | Add `'it-finance'` to `$modules`. Create the extra permissions **before** line 63 (`Super Admin`/`Organization Admin` take `Permission::all()`). Add a private `seedFinanceRoles()`. Roles are global (`config/permission.php` `teams => false`). |
| Lazy per-org defaults | `app/Services/Change/ChangePolicySeeder.php::ensureDefaults()` called from `ChangePolicyController::policy()` (GET) | `FinanceSettings::ensureDefaults($orgId)` is idempotent and called on the Settings GET and by the demo seeder. This is how "TBM seeded for every new org" (FIN-01.03) is met. |
| Controller style | `app/Http/Controllers/Change/ChangePolicyController.php` | `Inertia::render` with flat props plus a `canManage` flag, `$r->validate`, `back()->with('success', …)`, private `validatedX()` helpers. |
| Business-rule refusal | `app/Services/Change/ChangeWorkflow.php:107,634` (`TRANSITIONS` map + `fail()` → `ValidationException`) | Finance services throw `ValidationException::withMessages(['workflow' => …])`. The `workflow` key is rendered globally by `AuthenticatedLayout.jsx:146`, so no page code is needed. |
| Imports | `HandleInertiaRequests.php:65` shares `flash.import_report`; `Ea/DepthController.php:582` | `back()->with('import_report', $report)->with('success', …)`. |
| Route group | `routes/web.php:618-678` (`changes.` group) | `Route::middleware('permission:view …')->prefix()->name()->group()`, nested `permission:` for writes, `->whereNumber()` on every id. |
| Tenancy/route test | `tests/Feature/Changes/ChangeTenancyAndRoutesTest.php` | Collect `finance.*` GET routes, assert the exact name list, assert 200 + `has('auth.user')`, 403 without the permission, 404 cross-tenant. |
| Smoke test | `tests/Feature/RouteSmokeTest.php` | Kano admin only, `<500` only. P0 adds a finance-specific method (§5). |
| Demo seeder | `database/seeders/ChangeManagementDemoSeeder.php:40-160` | `BANKS` const (slug, prefix, admin, seed), `Auth::setUser($admin)`, `mt_srand`, guard with `withoutGlobalScopes()->where('organization_id', …)->exists()`, people found by job title with `stripos`, `Auth::forgetUser()` at the end. |
| Settings-with-tabs UI | No tabbed settings page exists yet (`Pages/Settings/*` are single panels). Tab bar: `resources/js/Pages/Policies/Show.jsx:83,154`. Forms/tables/modals: `resources/js/Pages/Changes/Policy.jsx`. | Gold-underline tab buttons, `useForm`, `Modal`, `InputError`, field classes `mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm`. |
| Sidebar | `resources/js/Config/navigation.js` (group `{name, icon, permission?, children[]}`); `SidebarNavItem.jsx` renders `#` for unknown route names | Add only items whose routes exist. |
| Icons | `resources/js/Components/Material/Symbols.jsx` (no heroicons) | `CurrencyDollarIcon` (= Material `payments`) for the group. |
| Approvals (P1+) | `app/Services/Approvals/ApprovalProcesses.php` (`PROCESSES` const, `conditions` with static `values`) | P0 registers nothing. See conflict C8. |

**Route-collision check:** `php artisan route:list --except-vendor` gives 691 lines, with 0 matches for `finance`, `it-finance` or `budget`. The `/it-finance` prefix and the `finance.*` names are free. The existing `settings.*` (platform settings) does not collide with `finance.settings`.

**Scratch-DB facts (seeded):** org 1 `acme-nigeria`, 2 `kano-heritage-bank`, 3 `first-bank-nigeria`, all `organizations.currency = NGN`. Legal entities exist **only for org 3** (`GRP`, `NG-BANK`, `GH-BANK`, `KE-BANK`, `UK-BANK`, `NG-PSP`); org 2 has none. Users matched by job title: Kano `admin@kanoheritage.ng` (CISO), `itops@` (Head of IT Operations), `digital@` (Head of Digital Channels), `treasury.it@`, `soc.analyst@`, `network@`, `finacle@`, `cro@`, `audit@`, `tprm@`. First Bank: `admin@firstbanknigeria.ng` and `ciso@` (Group CISO), `itops@`, `digital@`, `treasury.it@`, `soc@`, `network@`, `finacle.app@`, `isms@`, `cro@`, `internal.audit@`. There is no CFO, CIO, finance or procurement user in either bank.

---

## 2. BACKEND (fin-backend-engineer)

Build in this order. Run your own `--filter` class after each slice.

### 2.1 `config/finance.php` (new)

All regulatory and default values live here, never as constants (CLAUDE.md rule):
```php
return [
  'settings_defaults' => [
    'fiscal_year_start_month' => 1, 'period_scheme' => 'monthly',
    'variance_amber_pct' => 5, 'variance_red_pct' => 10, 'variance_abs_floor' => 500000,
    'renewal_lead_days' => 90, 'renewal_notice_offsets' => [180, 90, 60, 30, 14, 7, 0],
    'escalation_role' => 'Head of IT', 'overbudget_mode' => 'warn',
    'match_tolerance_pct' => 2, 'match_tolerance_abs' => 50000, 'eac_method' => 'remaining_budget',
    'showback_enabled' => false, 'digest_hour' => 7, 'fx_provider' => null,
    'doa_bands' => [ // base currency; min inclusive, max exclusive; last max null
      ['key' => '0-1m', 'label' => 'Up to 1m', 'min' => 0, 'max' => 1000000],
      ['key' => '1m-10m', 'label' => '1m to 10m', 'min' => 1000000, 'max' => 10000000],
      ['key' => '10m-50m', 'label' => '10m to 50m', 'min' => 10000000, 'max' => 50000000],
      ['key' => '50m-250m', 'label' => '50m to 250m', 'min' => 50000000, 'max' => 250000000],
      ['key' => '250m+', 'label' => 'Above 250m', 'min' => 250000000, 'max' => null],
    ],
    'investment_score_weights' => ['strategic_alignment' => 25, 'regulatory' => 25, 'risk_reduction' => 20, 'financial_return' => 20, 'delivery_risk' => 10],
  ],
  'currencies' => [ // catalogue for "add currency"; defaults seeded = org currency + USD, GBP, EUR
    'NGN' => ['Nigerian naira', '₦', 2], 'USD' => ['US dollar', '$', 2], 'GBP' => ['Pound sterling', '£', 2], 'EUR' => ['Euro', '€', 2],
    'GHS' => ['Ghanaian cedi', 'GH₵', 2], 'KES' => ['Kenyan shilling', 'KSh', 2], 'ZAR' => ['South African rand', 'R', 2], 'XOF' => ['West African CFA franc', 'CFA', 0],
  ],
  'default_currencies' => ['USD', 'GBP', 'EUR'],
  // Nigeria defaults (spec FIN-01.06). UNVERIFIED against the Nigeria Tax Act 2025 WHT schedule; editable per bank.
  'tax_types' => [
    ['code' => 'VAT', 'name' => 'Value Added Tax 7.5%', 'kind' => 'vat', 'rate' => 7.5, 'is_inclusive' => false, 'applies_to' => ['expense', 'invoice']],
    ['code' => 'WHT-5', 'name' => 'Withholding tax 5% (supplies, contracts)', 'kind' => 'wht', 'rate' => 5, 'is_inclusive' => false, 'applies_to' => ['invoice']],
    ['code' => 'WHT-10', 'name' => 'Withholding tax 10% (professional / consultancy services)', 'kind' => 'wht', 'rate' => 10, 'is_inclusive' => false, 'applies_to' => ['invoice']],
    ['code' => 'NONE', 'name' => 'No tax / exempt', 'kind' => 'none', 'rate' => 0, 'is_inclusive' => false, 'applies_to' => ['expense', 'invoice']],
  ],
  'gl_accounts' => [ // starter chart of accounts, editable
    ['1810', 'Computer hardware', 'capex'], ['1820', 'Software and intangibles', 'capex'], ['1830', 'IT capital work-in-progress', 'capex'],
    ['1401', 'Prepaid IT expenses', 'balance_sheet'], ['2101', 'Accrued IT expenses', 'balance_sheet'],
    ['6101', 'IT staff costs', 'opex'], ['6102', 'IT contractors and external labour', 'opex'],
    ['6201', 'Software licences and subscriptions', 'opex'], ['6202', 'Software maintenance and support (AMC)', 'opex'],
    ['6203', 'Hardware maintenance', 'opex'], ['6301', 'Telecoms and connectivity', 'opex'], ['6302', 'Cloud services', 'opex'],
    ['6401', 'IT consultancy and outsourced services', 'opex'], ['6501', 'Data centre facilities and power', 'opex'],
    ['6601', 'IT training', 'opex'], ['6901', 'Other IT expenses', 'opex'],
  ],
  'import_max_rows' => 5000,
];
```

### 2.2 Migration `database/migrations/2027_01_30_000001_create_it_finance_foundation_tables.php`

Every table gets `id()`, `unsignedBigInteger('organization_id')->index()`, `foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete()` and `timestamps()`. Create the tables in this order (FK dependencies). `down()` drops them in reverse.

| table | columns (type, default) | indexes / FKs |
|---|---|---|
| `fin_settings` | `fiscal_year_start_month` tinyInt 1 · `period_scheme` string(16) `monthly` · `variance_amber_pct` decimal(6,2) 5 · `variance_red_pct` decimal(6,2) 10 · `variance_abs_floor` decimal(18,2) 0 · `renewal_lead_days` smallInt 90 · `renewal_notice_offsets` json · `escalation_role` string(64) nullable · `overbudget_mode` string(8) `warn` · `match_tolerance_pct` decimal(6,2) 0 · `match_tolerance_abs` decimal(18,2) 0 · `eac_method` string(24) `remaining_budget` · `showback_enabled` bool false · `digest_hour` tinyInt 7 · `doa_bands` json · `investment_score_weights` json · `fx_provider` string(32) nullable | `unique(organization_id)` |
| `fin_fiscal_years` | `year_label` string(16) · `starts_on` date · `ends_on` date · `period_scheme` string(16) `monthly` · `status` string(16) `planning` · `is_current` bool false · `softDeletes()` | `unique(organization_id, year_label)` · `index(organization_id, status)` |
| `fin_periods` | `fiscal_year_id` FK→fin_fiscal_years cascadeOnDelete · `sequence` tinyInt · `name` string(32) · `starts_on` date · `ends_on` date · `status` string(8) `open` · `closed_at` timestamp null · `closed_by_id` FK users null nullOnDelete · `locked_at` timestamp null · `locked_by_id` FK users null nullOnDelete | `unique(fiscal_year_id, sequence)` · `index(organization_id, starts_on)` · `index(organization_id, status)` |
| `fin_gl_accounts` | `code` string(32) · `name` string(120) · `type` string(16) · `is_active` bool true · `softDeletes()` | `unique(organization_id, code)` |
| `fin_cost_centres` | `code` string(32) · `name` string(120) · `parent_id` FK→fin_cost_centres null nullOnDelete · `owner_id` FK users null nullOnDelete · `legal_entity_id` FK→legal_entities null nullOnDelete · `business_unit` string(120) null · `is_active` bool true · `softDeletes()` | `unique(organization_id, code)` · `index(parent_id)` · `index(owner_id)` |
| `fin_categories` | `code` string(32) · `name` string(120) · `kind` string(16) · `parent_id` FK→fin_categories null nullOnDelete · `tbm_reference` string(64) null · `default_capex` bool false · `default_gl_account_id` FK→fin_gl_accounts null nullOnDelete · `is_system` bool false · `is_active` bool true · `sort_order` smallInt 0 · `softDeletes()` | `unique(organization_id, code)` · `index(organization_id, kind)` · `index(parent_id)` |
| `fin_currencies` | `code` char(3) · `name` string(64) · `symbol` string(8) null · `decimals` tinyInt 2 · `is_base` bool false · `is_active` bool true | `unique(organization_id, code)` |
| `fin_fx_rates` | `from_currency` char(3) · `to_currency` char(3) · `rate` decimal(18,8) · `rate_date` date · `source` string(16) `manual` | `unique(organization_id, from_currency, to_currency, rate_date)` · `index(organization_id, to_currency, rate_date)` |
| `fin_tax_types` | `code` string(16) · `name` string(120) · `kind` string(16) `vat` · `rate` decimal(7,4) · `is_inclusive` bool false · `applies_to` json · `is_active` bool true | `unique(organization_id, code)` |
| `fin_drivers` | `code` string(32) · `name` string(120) · `unit` string(32) · `is_active` bool true | `unique(organization_id, code)` |
| `fin_driver_values` | `driver_id` FK→fin_drivers cascadeOnDelete · `period_id` FK→fin_periods cascadeOnDelete · `value` decimal(18,4) | `unique(driver_id, period_id)` |

Currency codes are stored as `char(3)` rather than FKs (ISO 4217 is the key, which keeps the table driver-agnostic). Do not use raw SQL.

### 2.3 Migration `database/migrations/2027_01_30_000002_grant_it_finance_permissions.php`

Copy `2027_01_15_000002`. `PERMISSIONS` = the 13 names in §2.7. Create the 6 finance roles if they are missing. `GRANTS` = the matrix in §2.7, plus `Super Admin`/`Organization Admin` ⇒ all finance permissions, `Auditor` ⇒ `view it-finance`, `export it-finance`, `view it-finance-amounts`, and `Viewer` ⇒ `view it-finance`. It must be idempotent, and `down()` is a no-op.

### 2.4 Models `app/Models/Finance/` (namespace `App\Models\Finance`)

Shared trait `app/Models/Finance/Concerns/FinanceRecord.php`:
- `use BelongsToOrganization, LogsActivity;`
- `bootFinanceRecord()`: on `creating`, set `created_by_id ??= auth()->id()`.
- `getActivitylogOptions()`: `LogOptions::defaults()->logAll()->logExcept(['created_at','updated_at','deleted_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('it-finance')`.

Every model: `use FinanceRecord;`, `protected $guarded = [];`, explicit `$table`, and `creator()` belongsTo User `created_by_id`.

| model (table) | extra traits | constants | casts | relations / scopes / methods |
|---|---|---|---|---|
| `Setting` (`fin_settings`) | none | `OVERBUDGET_MODES=['warn','block']`, `EAC_METHODS=['remaining_budget','run_rate']`, `SECTIONS=['general','doa','variance','renewal']` | `renewal_notice_offsets`, `doa_bands`, `investment_score_weights` ⇒ array; `*_pct`/`variance_abs_floor`/`match_tolerance_abs` ⇒ `decimal:2`; `showback_enabled` bool; ints | none |
| `FiscalYear` (`fin_fiscal_years`) | SoftDeletes | `STATUSES=['planning','open','closed']`, `PERIOD_SCHEMES=['monthly','monthly_13','4-4-5']` | `starts_on`,`ends_on` date; `is_current` bool | `periods()` hasMany ordered by sequence; `scopeCurrent`; `scopeOpen` (status open) |
| `Period` (`fin_periods`) | none | `STATUSES=['open','closed','locked']` | dates; `closed_at`,`locked_at` datetime | `fiscalYear()`, `closedBy()`, `lockedBy()`; `scopeOpen`; `scopeForFiscalYear($id)`; `scopeContaining(Carbon $d)` (starts_on ≤ d ≤ ends_on) |
| `GlAccount` (`fin_gl_accounts`) | SoftDeletes | `TYPES=['capex','opex','balance_sheet']` | `is_active` bool | `categories()` hasMany via `default_gl_account_id`; `scopeActive` |
| `CostCentre` (`fin_cost_centres`) | SoftDeletes | none | `is_active` bool | `parent()`, `children()`, `owner()` User, `legalEntity()` (`App\Models\LegalEntity`); `descendantIds(): array` (iterative BFS over the org's rows); `scopeActive` |
| `Category` (`fin_categories`) | SoftDeletes | `KINDS=['cost_pool','tower','sub_category']` | `default_capex`,`is_system`,`is_active` bool | `parent()`, `children()` (ordered by sort_order), `defaultGlAccount()`; `scopeCostPools`, `scopeTowers`, `scopeActive` |
| `Currency` (`fin_currencies`) | none | none | `is_base`,`is_active` bool; `decimals` int | `scopeActive`; `scopeBase` |
| `FxRate` (`fin_fx_rates`) | none | `SOURCES=['manual','import','provider']` | `rate` ⇒ `decimal:8`; `rate_date` date | none |
| `TaxType` (`fin_tax_types`) | none | `KINDS=['vat','wht','sales_tax','none']`, `APPLIES_TO=['expense','invoice']` | `rate` ⇒ `decimal:4`; `applies_to` array; bools | `scopeActive`; `appliesTo(string $doc): bool` |
| `Driver` (`fin_drivers`) | none | none | `is_active` bool | `values()` hasMany DriverValue |
| `DriverValue` (`fin_driver_values`) | none | none | `value` ⇒ `decimal:4` | `driver()`, `period()` |

`LegalEntity` is `BelongsToTenant` (NULL rows are allowed). Do not change it.

### 2.5 Services `app/Services/Finance/`

**`TbmTaxonomy`** (final class, constants + `seed(int $orgId): void`). Seeds per-org rows with `is_system = true`, idempotent via `firstOrCreate(['organization_id','code'])` under `withoutGlobalScopes()`. It resolves default GL by code from the org's `fin_gl_accounts`. It has 49 rows:
- Cost pools (`kind=cost_pool`, `tbm_reference='TBM v4.0 cost pool'`): `CP-HW` Hardware (capex, GL 1810) · `CP-SW` Software (GL 6201) · `CP-LAB` Labour (GL 6101) · `CP-OS` Outside Services (GL 6401) · `CP-FAC` Facilities & Power (GL 6501) · `CP-TEL` Telecom (GL 6301) · `CP-CLD` Cloud (GL 6302) · `CP-OTH` Other (GL 6901). Sub-pools (`sub_category`): `CP-LAB-INT` Internal labour (6101), `CP-LAB-EXT` External labour (6102).
- IT towers (`kind=tower`, `tbm_reference='TBM v4.0 IT tower'`), each with its `sub_category` children:
  - `TW-DC` Data Centre (capex): `TW-DC-ENT` Enterprise data centre, `TW-DC-OTH` Other facilities.
  - `TW-CMP` Compute (capex): `TW-CMP-SRV` Servers, `TW-CMP-MF` Mainframe.
  - `TW-STO` Storage (capex): `TW-STO-ONL` Online storage, `TW-STO-BKP` Backup & archive.
  - `TW-NET` Network (capex): `TW-NET-LAN` LAN/WAN, `TW-NET-VOI` Voice, `TW-NET-INT` Internet & connectivity.
  - `TW-PLT` Platform: `TW-PLT-DB` Database, `TW-PLT-MW` Middleware.
  - `TW-OUT` Output: `TW-OUT-PRT` Print.
  - `TW-EU` End User: `TW-EU-WKS` Workspace devices, `TW-EU-COL` Collaboration & productivity, `TW-EU-SD` Service desk.
  - `TW-APP` Application: `TW-APP-DEV` Development, `TW-APP-SUP` Support & operations, `TW-APP-SAAS` Business software / SaaS.
  - `TW-DEL` Delivery: `TW-DEL-PM` Project & programme management, `TW-DEL-ITSM` IT service management, `TW-DEL-OPS` Operations centre.
  - `TW-SEC` Security & Compliance: `TW-SEC-SEC` Security, `TW-SEC-CMP` Compliance, `TW-SEC-DR` Disaster recovery.
  - `TW-ITM` IT Management: `TW-ITM-STR` IT management & strategy, `TW-ITM-EA` Enterprise architecture, `TW-ITM-FIN` IT finance, `TW-ITM-VM` Vendor management.
- Public: `const ROWS` (code ⇒ [name, kind, parent_code, default_capex, gl_code]) and `public static function count(): int`.

**`FinanceSettings`**
- `ensureDefaults(int $orgId): Setting`. Idempotent. In this order: settings row from `config('finance.settings_defaults')`; currencies (org `currency` as `is_base`, then `default_currencies`); GL starter chart; `TbmTaxonomy::seed`; tax types. It creates **no** fiscal years, cost centres or FX rates.
- `for(int $orgId): Setting`. Calls `ensureDefaults` once and memoizes it per request (a static array keyed by org).
- `update(Setting $s, string $section, array $validated): Setting`. Saves only the section's keys (activity logged). It normalises `renewal_notice_offsets` (unique, sorted descending) and regenerates DoA band `key`s from min/max (`0-1m` style; `N+` for the last band).
- `bandFor(int $orgId, string|float $baseAmount): string`. Returns the key of the band where min ≤ amount < max (the last band is open-ended). P1+ uses it for every `amount_band` accessor.
- `baseCurrency(int $orgId): string`.
- `validateBands(array $bands): void`. 1 to 8 bands. The first `min` is 0, each `min` equals the previous `max`, `max > min`, and only the last band has `max = null`. Errors go under `doa_bands`.

**`FiscalCalendarService`**
- `const PERIOD_TRANSITIONS = ['close' => ['open'], 'lock' => ['closed']];` (reopen is P4 and needs `finance.period_reopen`.)
- `createYear(int $orgId, array $data, User $by): FiscalYear`. Data: `year_label`, `starts_on`, `period_scheme`, `status`, `is_current`. It computes `ends_on`: `monthly`/`monthly_13` = starts_on + 1 year − 1 day; `4-4-5` = starts_on + 364 days − 1. It refuses overlap with another of the org's fiscal years (`workflow` error). It generates periods: `monthly` = 12 calendar months named `M Y` (e.g. `Jan 2027`) when the start is on day 1; `monthly_13` = the 12 monthly periods plus P13 `Adjustment` with starts_on = ends_on = FY end; `4-4-5` = 12 periods of 4,4,5 weeks named `P01` to `P12`. Periods are created `open`. It calls `makeCurrent` if `is_current`. Everything runs in a DB transaction.
- `updateYear(FiscalYear $fy, array $data): FiscalYear`. Only `year_label`, `status` and `is_current` can change. Status rules: planning→open; open→closed only when every period is `closed`/`locked`; nothing goes back to planning, and closed→open is refused (P4). `is_current` requires status `open`.
- `makeCurrent(FiscalYear $fy): void`. Unsets the org's other `is_current`.
- `deleteYear(FiscalYear $fy): void`. Allowed only when status is `planning` and no period is closed (P1 adds "no budgets"). Soft delete.
- `closePeriod(Period $p, User $by): Period`. Allowed from `open`. Refused if an earlier period of the same FY is still `open`, or if the FY is `planning`. Sets `closed_at`/`closed_by_id`. (P4 `PeriodCloseService` wraps this with the checklist.)
- `lockPeriod(Period $p, User $by): Period`. Allowed from `closed`. Sets `locked_at`/`locked_by_id`.
- `periodFor(int $orgId, CarbonInterface $date): ?Period`.

**`CurrencyService`** (bcmath, scale 8)
- `rate(int $orgId, string $from, string $to, CarbonInterface $on): ?string`. Same currency gives `'1.00000000'`. Otherwise it takes the latest direct rate with `rate_date ≤ on`. If there is none, it uses the inverse (1/rate). If that is also missing, it triangulates through the base currency. It returns null if nothing is found.
- `convert(int $orgId, string|float $amount, string $currency, CarbonInterface $on): array{amount:string, currency:string, fx_rate:string, base_amount:string}`. It throws `ValidationException(['currency' => 'No exchange rate …'])` when no rate exists. `base_amount` is rounded to 2 dp half-up. Every P1+ money write uses this (§4 `amount, currency, fx_rate, base_amount`).
- `setBase(Currency $c): void`. Sets one base and unsets the others. It refuses an inactive currency. (P1 adds a refusal once money rows exist.)

**`FinanceCsvImporter`**
- `fxRates(int $orgId, UploadedFile $f): array` (report). Headers `from_currency,to_currency,rate,rate_date`. It upserts on the unique key with `source='import'`. It rejects unknown or inactive codes, from = to, rate ≤ 0, a bad date or a future date.
- `costCentres(int $orgId, UploadedFile $f): array`. Headers `code,name,parent_code,owner_email,legal_entity_code,business_unit,is_active`. It upserts by code and resolves the parent from existing rows or earlier rows in the file. Owner is looked up by email within the org, and legal entity by code among visible legal entities. An unknown parent, owner or entity rejects the row.
- Report shape (it mirrors future `fin_import_batches` columns so P3 can persist it): `['type' => 'fx_rates'|'cost_centres', 'file_name' => string, 'row_count' => int, 'accepted_count' => int, 'rejected_count' => int, 'errors' => [['row' => int, 'message' => string], …]]`. The file is limited to `config('finance.import_max_rows')` rows and 2 MB, mimes `csv,txt`.

### 2.6 Policy `app/Policies/Finance/FinancePolicy.php`

- Mapping: add `registerFinancePolicies()` to `app/Providers/AppServiceProvider.php`, copying `registerEaPolicies()` (line 95). It globs `app/Models/Finance/*.php` and calls `Gate::policy($class, FinancePolicy::class)`.
- Abilities: `viewAny(User)` ⇒ `can('view it-finance')`; `view(User, Model $m)` ⇒ viewAny and same org (`$m->organization_id === $u->organization_id`); `create`/`update`/`delete(User, …)` on config models ⇒ `can('manage it-finance-settings')`; `closePeriod(User, Period)` ⇒ `can('close it-finance-periods')`.
- `public static function visibleCostCentreIds(User $u): ?array`. Returns null (all) when the user has any of `manage it-finance-settings`, `approve it-finance` or `close it-finance-periods`, or holds the role `Organization Admin` or `Auditor`. Otherwise it returns the ids of cost centres the user owns plus all their descendants (possibly `[]`). This is the cost-centre row restriction in §8. P1+ applies it to budgets, expenses and so on. In P0 it is exercised only by tests, and the Settings page shows all reference data to every `view it-finance` user.
- Controllers call `$this->authorize()`/`Gate::authorize` on mutations in addition to the route middleware (defence in depth, as in `DepthController`).

### 2.7 Permissions and roles in `database/seeders/RolesAndPermissionsSeeder.php`

- Add `'it-finance'` to `$modules`. This creates view/create/edit/delete/approve/export it-finance, so `Auditor` gets view+export and `Viewer` gets view through the existing loops.
- Directly after the module loop (before `Super Admin`), add `Permission::firstOrCreate` for: `approve it-finance-invoices`, `approve it-finance-investments`, `approve it-finance-contracts`, `close it-finance-periods`, `manage it-finance-settings`, `view it-finance-amounts`, `import it-finance`.
- Add `private function seedFinanceRoles(): void` and call it at the end of `run()`. It also gives `Auditor` `view it-finance-amounts`.

| role | permissions |
|---|---|
| IT Budget Owner | view, create, edit, export it-finance |
| IT Finance Controller | all 13 finance permissions (view, create, edit, delete, approve, export it-finance + the 7 extras) |
| Procurement Officer | view, create, edit, import it-finance · view it-finance-amounts · view vendors |
| Investment Sponsor | view it-finance · approve it-finance-investments · view it-finance-amounts |
| IT Steering Committee | view it-finance · approve it-finance-investments · view it-finance-amounts |
| Finance Approver | view, export it-finance · approve it-finance · approve it-finance-invoices · view it-finance-amounts |

### 2.8 Controllers `app/Http/Controllers/Finance/`

All four controllers redirect to `route('finance.settings', ['tab' => <tab>])` with `success` after a mutation. Business-rule refusals come from services as a `workflow` ValidationException.

| controller | methods |
|---|---|
| `FinanceSettingsController` | `show(Request)`: `FinanceSettings::for($orgId)` then `Inertia::render('Finance/Settings', props §4.1)`. `update(Request)`: validate per §4.2 `finance.settings.update`, then `FinanceSettings::update`. |
| `FiscalCalendarController` | `store`, `update(FiscalYear)`, `destroy(FiscalYear)`, `closePeriod(Period)`, `lockPeriod(Period)` |
| `FinanceDimensionsController` | `costCentreStore/Update/Destroy`, `costCentreImport`, `categoryStore/Update/Destroy`, `glAccountStore/Update/Destroy`, `driverStore/Update/Destroy`, `driverValuesUpdate(Driver)` |
| `FinanceCurrencyController` | `currencyStore/Update`, `fxRateStore/Destroy`, `fxRateImport`, `taxTypeStore/Update` |

Refusals (all `workflow` errors):
- Deleting a cost centre that has children.
- Deleting a system category, or a category that has children.
- Changing `code`/`kind`/`parent_id`/`tbm_reference` on a system category. Silently ignore those keys instead of failing: only `name`, `default_capex`, `default_gl_account_id`, `is_active` and `sort_order` are writable.
- Deleting a GL account referenced by a category.
- Deactivating the base currency.
- A cost-centre `parent_id` that is the row itself or one of its descendants.

### 2.9 Routes, in `routes/web.php` inside the `auth` group after the `changes.` group (line 678)

Add `use App\Http\Controllers\Finance\{FinanceSettingsController, FiscalCalendarController, FinanceDimensionsController, FinanceCurrencyController};`.

```
// IT Financial Management: foundation & configuration (ATH-FIN-001 §3.1, §9)
Route::middleware('permission:view it-finance')->prefix('it-finance')->name('finance.')->group(function () {
  GET    /settings                                  finance.settings                  FinanceSettingsController@show
  middleware('permission:manage it-finance-settings'):
    PUT    /settings                                finance.settings.update           @update
    POST   /fiscal-years                            finance.fiscal-years.store
    PUT    /fiscal-years/{fiscalYear}               finance.fiscal-years.update
    DELETE /fiscal-years/{fiscalYear}               finance.fiscal-years.destroy
    POST   /cost-centres                            finance.cost-centres.store
    PUT    /cost-centres/{costCentre}               finance.cost-centres.update
    DELETE /cost-centres/{costCentre}               finance.cost-centres.destroy
    POST   /categories                              finance.categories.store
    PUT    /categories/{category}                   finance.categories.update
    DELETE /categories/{category}                   finance.categories.destroy
    POST   /gl-accounts                             finance.gl-accounts.store
    PUT    /gl-accounts/{glAccount}                 finance.gl-accounts.update
    DELETE /gl-accounts/{glAccount}                 finance.gl-accounts.destroy
    POST   /currencies                              finance.currencies.store
    PUT    /currencies/{currency}                   finance.currencies.update
    POST   /fx-rates                                finance.fx-rates.store
    DELETE /fx-rates/{fxRate}                       finance.fx-rates.destroy
    POST   /tax-types                               finance.tax-types.store
    PUT    /tax-types/{taxType}                     finance.tax-types.update
    POST   /drivers                                 finance.drivers.store
    PUT    /drivers/{driver}                        finance.drivers.update
    DELETE /drivers/{driver}                        finance.drivers.destroy
    PUT    /drivers/{driver}/values                 finance.drivers.values.update
    middleware('permission:import it-finance'):
      POST /cost-centres/import                     finance.cost-centres.import      (declare BEFORE /cost-centres/{costCentre})
      POST /fx-rates/import                         finance.fx-rates.import
  middleware('permission:close it-finance-periods'):
    POST   /periods/{period}/close                  finance.periods.close
    POST   /periods/{period}/lock                   finance.periods.lock
});
```

Every `{param}` gets `->whereNumber()`. Implicit binding plus `OrganizationScope` makes another bank's ids return 404. `finance.periods.reopen` is **not** added in P0.

### 2.10 Seeder `database/seeders/FinanceFoundationSeeder.php` (new), wired into `DatabaseSeeder`

- Wiring: in `DatabaseSeeder::run()`, add it directly **after** `ChangeManagementDemoSeeder` and before `AssetManagementDemoSeeder`. The comment reads: `// IT Financial Management foundation: settings, TBM taxonomy, chart of accounts, currencies & FX, taxes, fiscal calendar, cost centres, drivers and finance users per bank (ATH-FIN-001 §3.1, §13)`. It needs the users and the First Bank legal entities, which both exist by then. P1's `FinanceDemoSeeder` will follow it.
- `BANKS = ['kano-heritage-bank' => ['prefix' => 'KHB', 'admin' => 'admin@kanoheritage.ng', 'domain' => 'kanoheritage.ng', 'seed' => 8101], 'first-bank-nigeria' => ['prefix' => 'FBN', 'admin' => 'admin@firstbanknigeria.ng', 'domain' => 'firstbanknigeria.ng', 'seed' => 8102]]`.
- Per bank: skip if the org or admin is missing. Then `Auth::setUser($admin)`, `mt_srand(seed)`, and run these steps in order:
  1. **Users (idempotent, `updateOrCreate` by email, `Hash::make('password')`, `email_verified_at` now):**
     - `finance@{domain}`: "IT Finance Controller", dept Finance, role IT Finance Controller.
     - `procurement@{domain}`: "Procurement Officer", dept Procurement, role Procurement Officer.
     - `cfo@{domain}`: "Chief Financial Officer", dept Finance, roles Finance Approver, Investment Sponsor, IT Steering Committee.
     - `cio@{domain}`: "Chief Information Officer", dept Technology, roles Finance Approver, IT Steering Committee.
     - Names: Kano `Zainab Garba`, `Ibrahim Yusuf`, `Hadiza Bello`, `Musa Danladi`. First Bank `Olumide Adeyemi`, `Chinwe Eze`, `Folake Ogunleye`, `Emeka Nwosu`.
     - Existing users: `itops@` (Head of IT Operations) gets IT Budget Owner and Finance Approver. Cost-centre owners (step 6) get IT Budget Owner. The bank admin (CISO) gets Finance Approver; it is already Organization Admin.
  2. `FinanceSettings::ensureDefaults($orgId)`.
  3. **Guard:** stop here for this bank if `CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->exists()`. Steps 1 and 2 are idempotent anyway.
  4. **Settings tweaks** via the model:
     - Kano: `overbudget_mode=warn`, `variance_abs_floor=1000000`.
     - First Bank: `overbudget_mode=block`, `variance_abs_floor=5000000`, `match_tolerance_pct=1`, `escalation_role='Chief Information Officer'`.
  5. **Fiscal years** via `FiscalCalendarService::createYear`, with `$y = now()->year`:
     - `FY{$y}`: status open, is_current, monthly.
     - `FY{$y+1}`: status planning, monthly.
     - For FY{$y}: periods ending before the start of the current month are closed (closed_by = finance user, closed_at = period end + 5 days). Periods ending on or before 30 June are then locked (locked_by = finance user, locked_at = 15 July). With today's date this gives Jan–Sep closed, Jan–Jun locked and Oct–Dec open.
     - Write those statuses directly with `forceFill` (the service enforces ordering, which the loop satisfies anyway).
  6. **Cost centres:**
     - **Kano (10):**
       - `KHB-IT` IT Division (owner itops). Its children:
         - `KHB-IT-INF` IT Infrastructure (itops).
         - `KHB-IT-DC` Data Centre & Cloud (sysadmin).
         - `KHB-IT-NET` Network & Telecoms (network).
         - `KHB-IT-APP` Core Banking Applications (finacle).
         - `KHB-IT-DIG` Digital Channels (digital).
         - `KHB-IT-TRY` Treasury Systems (treasury.it).
         - `KHB-IT-ITSM` IT Service Management (**owner null**, a deliberate gap).
       - `KHB-SEC` Information Security (admin/CISO). Its child: `KHB-SEC-SOC` Security Operations (soc.analyst).
       - Kano has no legal entity, so `legal_entity_id` is null. Business unit is "Technology" or "Information Security".
     - **First Bank (12):**
       - `FBN-GT` Group Technology (itops, `GRP`). Its children:
         - `FBN-GT-INF` Infrastructure (itops, `NG-BANK`).
         - `FBN-GT-DC` Data Centres (sysadmin, `NG-BANK`).
         - `FBN-GT-NET` Network (network, `NG-BANK`).
         - `FBN-GT-CBS` Core Banking (Finacle) (finacle.app, `NG-BANK`).
         - `FBN-GT-DIG` Digital Banking (digital, `NG-BANK`).
         - `FBN-GT-DATA` Data & Analytics (**owner null**, `NG-BANK`).
         - `FBN-GT-PAY` Payments Technology (treasury.it, `NG-PSP`).
         - `FBN-GT-GH` Ghana Subsidiary IT (itops, `GH-BANK`).
         - `FBN-GT-UK` UK Subsidiary IT (itops, `UK-BANK`).
       - `FBN-GIS` Group Information Security (ciso, `GRP`). Its child: `FBN-GIS-SOC` Cyber Defence Centre (soc, `NG-BANK`).
     - Resolve people by job title with `stripos`, falling back to the admin, as in `ChangeManagementDemoSeeder::people()`.
  7. **Custom sub-categories** (`is_system=false`):
     - Kano: `KHB-REGTECH` "CBN regulatory technology", parent `TW-SEC`.
     - First Bank: `FBN-RECHARGE` "Group shared-services recharge", parent `CP-OTH`, plus `FBN-SWIFT` "SWIFT & correspondent connectivity", parent `TW-NET`.
     - One extra Kano GL account `6205` "Core banking AMC (Finacle)" opex. One inactive First Bank GL `6999` "Legacy suspense (do not use)", `is_active=false` (a deliberate gap).
  8. **FX** (`source` alternating manual/import, deterministic `mt_rand` jitter):
     - Monthly rates on the 1st of each month from Jan `$y` to the current month, plus today, for USD→NGN (base ≈1,540, ±2.5 %), GBP→NGN (≈2,060) and EUR→NGN (≈1,790).
     - First Bank also gets GHS (activate a `fin_currencies` row first) GHS→NGN ≈ 128.
  9. **Drivers** (values for every FY{$y} period, slight monthly growth):
     - `HEADCOUNT` Staff headcount (FTE): Kano 2,150, First Bank 9,800.
     - `BRANCHES` Branches (count): 74 / 750.
     - `M365_SEATS` Microsoft 365 seats (users): 2,400 / 11,500.
     - `TXN_VOL` Transaction volume (millions/month): 18 / 210.
     - `STORAGE_TB` Primary storage (TB): 380 / 4,200.
  10. Finish with `mt_srand(); Auth::forgetUser();`.
- Org 1 (Acme) gets nothing. Its first Settings visit creates only the defaults from step 2.

### 2.11 Backend test `tests/Feature/Finance/FinanceTenancyAndRoutesTest.php` (written by the backend builder)

- **setUp:** `withoutVite()`, seed `RolesAndPermissionsSeeder`. Create two orgs via the factory. In org A: an Organization Admin (job_title CISO), `FinanceSettings::ensureDefaults`, one fiscal year via the service, one cost centre, one driver and one FX rate.
- `test_every_finance_page_renders_for_an_organization_admin`: the collected GET `finance.*` names equal exactly `['finance.settings']`. Each returns 200, `component('Finance/Settings')` and `has('auth.user')`. Also check `?tab=categories` returns `tab = 'categories'`, and an unknown tab falls back to `general`.
- `test_every_finance_page_is_forbidden_without_view_it_finance`: a user with no role gets 403 on GET settings, PUT settings.update and POST cost-centres.store.
- `test_records_404_for_another_banks_records`: org B's Organization Admin gets 404 on PUT/DELETE of org A's cost centre, category, GL, fiscal year, period close, driver values and FX rate destroy. Org B's settings props contain none of org A's cost centres or fiscal years (counts 0), and contain its own 49 TBM rows (ensureDefaults is lazy per org).
- `test_a_new_bank_with_no_finance_data_renders_empty_not_errors` (FIN-01.03, §12 tenancy): a fresh org's admin GETs settings ⇒ 200; `fiscalYears` 0, `costCentres` 0, `categories` = `TbmTaxonomy::count()`, 4 currencies with exactly one `is_base` = org currency, 4 tax types.

---

## 3. FRONTEND (fin-frontend-engineer)

Your files: `resources/js/Config/navigation.js` (edit), `resources/js/Pages/Finance/Settings.jsx`, `resources/js/Components/Finance/**`, `resources/js/Utils/finance.js`. Do **not** edit `Symbols.jsx`, `StatusBadge.jsx` or the layout. Unknown statuses (`planning`, `locked`) render as neutral chips, and the Chip `tone` prop covers anything else you need.

### 3.1 `resources/js/Config/navigation.js`

Add `CurrencyDollarIcon` to the Symbols import. Insert a new group directly after "Change Management":
```js
{
    name: 'IT Financial Management',
    icon: CurrencyDollarIcon,
    permission: 'view it-finance',
    children: [
        { name: 'Settings', href: 'finance.settings', permission: 'view it-finance' },
    ],
},
```
Later phases append Dashboard · Budgets · Expenses & Invoices · Budget Monitor · Contracts & Renewals · Calendar · Investments & Value · Reports, in the §10 order, **above** Settings.

### 3.2 `resources/js/Pages/Finance/Settings.jsx`

- Shell: `AuthenticatedLayout` with header text "IT Financial Management", `<Head title="Finance Settings" />`, and a `PageHeader` with title "Finance Settings". The subtitle is "Fiscal calendar, cost model, currencies, taxes and approval thresholds (COBIT APO06.01)". Breadcrumbs are `IT Financial Management › Settings`.
- When `!can.manage`, show an info banner: "Read-only. Changing finance settings needs the manage it-finance-settings permission". In that mode hide every add/edit/delete/import control.
- Tab bar: the pattern from `Pages/Policies/Show.jsx:154`. It must scroll horizontally on mobile (`overflow-x-auto whitespace-nowrap`). The tab keys and labels, in order:

| key | label | component |
|---|---|---|
| `general` | General | `GeneralTab` |
| `calendar` | Fiscal years & periods | `FiscalCalendarTab` |
| `cost-centres` | Cost centres | `CostCentresTab` |
| `categories` | Categories (TBM) | `CategoriesTab` |
| `gl-accounts` | GL accounts | `GlAccountsTab` |
| `currencies` | Currencies & FX | `CurrenciesTab` |
| `taxes` | Taxes | `TaxesTab` |
| `drivers` | Drivers | `DriversTab` |
| `doa` | DoA bands | `DoaBandsTab` |
| `variance` | Variance thresholds | `VarianceTab` |
| `renewal` | Renewal policy | `RenewalPolicyTab` |

- Active tab: initialise local state from the `tab` prop. On click, set the state and call `window.history.replaceState(null, '', route('finance.settings', { tab: key }))`, with no server round trip. The server redirects every mutation back to the right `?tab=`.
- Components live in `resources/js/Components/Finance/Settings/<Name>.jsx`. Each receives the props it needs plus `can`.

### 3.3 Tab contents

- **GeneralTab:** form with fiscal-year start month (select of `options.months`), period scheme, over-budget mode (warn/block radio, with help text "block refuses submission over availability; warn allows it with a justification"), three-way match tolerance % and absolute amount (base currency), EAC method, showback enabled, digest hour (0 to 23). It submits `section: 'general'` (§4.2).
- **FiscalCalendarTab:**
  - Each fiscal year is a card: label, dates, scheme, status chip, and a "Current" chip.
  - Below it, a period table with seq, name, dates, status chip, closed at/by and locked at/by.
  - Per-period actions (`can.closePeriods`): **Close** on open periods, **Lock** on closed ones. Use `confirm()` first, then `router.post`.
  - Per-year actions (`can.manage`): edit (label, status, current) in a modal, and delete (planning only).
  - An "Add fiscal year" modal.
  - Empty state: "No fiscal years yet. Add the current year and next year's planning year."
- **CostCentresTab:**
  - An indented tree table built client-side from `parent_id` (code, name, owner, legal entity, business unit, active).
  - "Unowned" chip (warning tone) when `owner_id` is null.
  - Add/edit modal. The parent select excludes the row itself and its descendants. Delete is a button.
  - Import card (`can.import`) using `CsvImportForm`. Header hint: `code,name,parent_code,owner_email,legal_entity_code,business_unit,is_active`.
- **CategoriesTab:**
  - A two-section tree: "Cost pools" and "IT towers", each with its sub-categories indented.
  - Columns: code, name, TBM reference, default CapEx/OpEx, default GL, system/custom chip, active.
  - System rows: edit modal limited to name, default CapEx, default GL and active. Custom rows: full edit and delete.
  - "Add sub-category" modal: parent select of pools and towers.
- **GlAccountsTab:** table with code, name, type and active, plus an add/edit modal and delete.
- **CurrenciesTab:**
  - Currencies table: code, name, symbol, decimals, a "Base" chip, active. Edit modal includes "Make base currency". Add modal: code select from `options.currencyCatalogue` filtered to codes not already present, which prefills name, symbol and decimals.
  - FX rates table: date, from→to, rate (formatted with 4 to 8 decimals), source chip, delete. Above it, "Latest rates" tiles from `latestRates`.
  - Add-rate modal. Import card with hint `from_currency,to_currency,rate,rate_date`.
- **TaxesTab:** table with code, name, kind, rate %, inclusive, applies to and active, plus an add/edit modal. No delete.
- **DriversTab:**
  - Drivers table: code, name, unit, active, add/edit/delete.
  - Selecting a driver opens a values grid for the current fiscal year's periods (the first `is_current` FY, else the latest). Inputs are per period, and "Save values" submits `finance.drivers.values.update`.
- **DoaBandsTab:**
  - An editable ordered list of bands with label, min (read-only, equal to the previous max) and max (the last is fixed "No upper limit"). "Add band" splits the last band, and "Remove" is available on non-first bands.
  - Client-side check of contiguity and ascending order.
  - Note: "Bands drive the amount_band condition on every finance approval in Workflow Studio. Amounts are in {baseCurrency}." Submits `section: 'doa'`.
- **VarianceTab:** amber % and red %, the absolute floor in base currency, and a live preview chip strip (green < amber ≤ red). Client check amber < red. Submits `section: 'variance'`.
- **RenewalPolicyTab:**
  - Renewal lead days and the escalation target: free text with a datalist of `jobTitles`, hint "job title, e.g. Head of IT; falls back to Organization Admin".
  - Notice offsets as a chip list (add a number, remove a chip), sorted descending, with `0` shown as "On the day". Submits `section: 'renewal'`.

### 3.4 Shared bits

- `resources/js/Utils/finance.js`:
  - `formatMoney(value, currency = 'NGN', { decimals = 2 } = {})` uses `Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: decimals, maximumFractionDigits: decimals })`. Inputs are numeric strings, so coerce with `Number`.
  - `formatRate(value)` shows up to 8 dp with trailing zeros trimmed to a minimum of 4.
  - `formatPct(value)`.
  - `PERIOD_TONES = { open: 'success', closed: 'neutral', locked: 'warning' }`, `FY_TONES = { planning: 'info', open: 'success', closed: 'neutral' }`. Use `Chip` tones from `Components/Material/Chip.jsx`, `TONES`.
  - Re-export `humanize` from `@/Utils/risk`.
- `resources/js/Components/Finance/CsvImportForm.jsx`: props `{ routeName, hint, label }`. It wraps a `useForm({ file: null })` with `post(route(routeName), { forceFormData: true, preserveScroll: true })`.
- `resources/js/Components/Finance/ImportReport.jsx`: reads `usePage().props.flash.import_report`. When `type` matches the tab, it renders accepted/rejected counts and an errors table (row, message), collapsible and limited to the first 50.
- Reuse: `AuthenticatedLayout`, `PageHeader`, `Modal`, `InputError`, `StatusBadge` (fiscal year and period statuses), `Chip`, Material Symbols (`PencilIcon`, `TrashIcon`, `PlusIcon`/`ArrowUpTrayIcon`, `LockClosedIcon` where they exist in `Symbols.jsx`; check before importing).
- Action-level errors arrive as `errors.workflow`, which the layout already renders. Field errors go under each input via `InputError`.
- Styling: navy `#0A1F44`, gold `#C9A86A`, secondary text `#718096`, the field classes from `Changes/Policy.jsx`, no `rounded-full` pills, and it must stay usable at 375 px (tables in `overflow-x-auto`).

---

## 4. PROPS CONTRACT (backend ⇄ frontend)

Conventions for every payload:
- Decimals are serialised as **strings**, because Laravel `decimal:n` casts produce strings.
- Dates are `YYYY-MM-DD`, and timestamps are ISO-8601.
- Booleans are booleans.
- Ids are ints.
- Every mutation redirects (303/302) to `finance.settings?tab=<tab in brackets>` with `flash.success`. Validation failures return Inertia `errors` keyed by field, and business-rule refusals return `errors.workflow`.

### 4.1 `GET finance.settings` (`/it-finance/settings?tab=`) ⇒ `Inertia::render('Finance/Settings', …)`

```ts
{
  tab: 'general'|'calendar'|'cost-centres'|'categories'|'gl-accounts'|'currencies'|'taxes'|'drivers'|'doa'|'variance'|'renewal', // unknown ⇒ 'general'
  baseCurrency: string,                       // e.g. 'NGN'
  settings: {
    id: number, fiscal_year_start_month: number, period_scheme: 'monthly'|'monthly_13'|'4-4-5',
    variance_amber_pct: string, variance_red_pct: string, variance_abs_floor: string,
    renewal_lead_days: number, renewal_notice_offsets: number[], escalation_role: string|null,
    overbudget_mode: 'warn'|'block', match_tolerance_pct: string, match_tolerance_abs: string,
    eac_method: 'remaining_budget'|'run_rate', showback_enabled: boolean, digest_hour: number,
    doa_bands: { key: string, label: string, min: number, max: number|null }[],
    investment_score_weights: Record<string, number>, updated_at: string,
  },
  fiscalYears: {                              // ordered starts_on desc
    id, year_label, starts_on, ends_on, period_scheme, status: 'planning'|'open'|'closed', is_current: boolean,
    periods: { id, sequence, name, starts_on, ends_on, status: 'open'|'closed'|'locked',
               closed_at: string|null, closed_by: {id, name}|null, locked_at: string|null, locked_by: {id, name}|null }[],
  }[],
  costCentres: { id, code, name, parent_id: number|null, owner_id: number|null, owner: {id, name, job_title}|null,
                 legal_entity_id: number|null, legal_entity: {id, code, name}|null, business_unit: string|null, is_active: boolean }[], // ordered code
  categories: { id, code, name, kind: 'cost_pool'|'tower'|'sub_category', parent_id: number|null, tbm_reference: string|null,
                default_capex: boolean, default_gl_account_id: number|null, is_system: boolean, is_active: boolean, sort_order: number }[], // ordered kind, sort_order, code
  glAccounts: { id, code, name, type: 'capex'|'opex'|'balance_sheet', is_active: boolean }[],  // ordered code
  currencies: { id, code, name, symbol: string|null, decimals: number, is_base: boolean, is_active: boolean }[], // base first, then code
  fxRates: { id, from_currency, to_currency, rate: string, rate_date: string, source: 'manual'|'import'|'provider' }[], // latest 200 by rate_date desc, id desc
  latestRates: { from_currency, to_currency, rate: string, rate_date: string }[],   // newest rate per pair
  taxTypes: { id, code, name, kind: 'vat'|'wht'|'sales_tax'|'none', rate: string, is_inclusive: boolean, applies_to: ('expense'|'invoice')[], is_active: boolean }[],
  drivers: { id, code, name, unit, is_active: boolean, values: { period_id: number, value: string }[] }[],
  users: { id, name, job_title: string|null }[],                 // org users, ordered name
  legalEntities: { id, code, name }[],                           // visible to the org (BelongsToTenant), ordered code
  jobTitles: string[],                                           // distinct non-null org job titles
  options: {
    months: { value: 1..12, label: 'January'… }[], periodSchemes: string[], fiscalYearStatuses: string[], periodStatuses: string[],
    categoryKinds: string[], glTypes: string[], fxSources: string[], taxKinds: string[], taxAppliesTo: string[],
    overbudgetModes: string[], eacMethods: string[],
    currencyCatalogue: { code, name, symbol, decimals }[],       // from config('finance.currencies')
  },
  can: { manage: boolean /* manage it-finance-settings */, closePeriods: boolean /* close it-finance-periods */, import: boolean /* import it-finance */ },
}
```

### 4.2 Submit payloads (route name · method · body · redirect tab)

| route | method | body (validation) | tab |
|---|---|---|---|
| `finance.settings.update` | PUT | `section` required in `general/doa/variance/renewal` + **only that section's keys**: **general** `fiscal_year_start_month` int 1–12 · `period_scheme` in schemes · `overbudget_mode` in modes · `match_tolerance_pct` numeric 0–100 · `match_tolerance_abs` numeric ≥0 · `eac_method` in methods · `showback_enabled` bool · `digest_hour` int 0–23 — **doa** `doa_bands` array 1–8 of `{label string≤40, min numeric≥0, max numeric\|null}` (key ignored, regenerated) — **variance** `variance_amber_pct` numeric 0.01–100 · `variance_red_pct` numeric gt:amber ≤1000 · `variance_abs_floor` numeric ≥0 — **renewal** `renewal_lead_days` int 1–730 · `renewal_notice_offsets` array 1–12 distinct ints 0–730 · `escalation_role` nullable string ≤64 | the section key (`general`/`doa`/`variance`/`renewal`) |
| `finance.fiscal-years.store` | POST | `year_label` required, regex `^[A-Za-z0-9 /-]{2,16}$`, unique in org · `starts_on` date · `period_scheme` in schemes · `status` in `planning,open` · `is_current` bool | `calendar` |
| `finance.fiscal-years.update` | PUT | `year_label` (as above, ignore self) · `status` in statuses · `is_current` bool | `calendar` |
| `finance.fiscal-years.destroy` | DELETE | none | `calendar` |
| `finance.periods.close` / `finance.periods.lock` | POST | none | `calendar` |
| `finance.cost-centres.store` / `.update` | POST / PUT | `code` required ≤32 regex `^[A-Z0-9][A-Z0-9._-]*$` unique in org (ignore self) · `name` required ≤120 · `parent_id` nullable int exists in org cost centres · `owner_id` nullable int user in org · `legal_entity_id` nullable int visible legal entity · `business_unit` nullable ≤120 · `is_active` bool | `cost-centres` |
| `finance.cost-centres.destroy` | DELETE | none | `cost-centres` |
| `finance.cost-centres.import` | POST multipart | `file` required file mimes csv,txt max 2048 KB | `cost-centres` (+ `flash.import_report`) |
| `finance.categories.store` | POST | `code` (as cost centre) · `name` · `parent_id` required, a cost_pool or tower of the org · `default_capex` bool · `default_gl_account_id` nullable in org · `tbm_reference` nullable ≤64 · `is_active` bool. `kind` is forced to `sub_category` | `categories` |
| `finance.categories.update` | PUT | the same keys; system rows accept only `name`, `default_capex`, `default_gl_account_id`, `is_active`, `sort_order` | `categories` |
| `finance.categories.destroy` | DELETE | none | `categories` |
| `finance.gl-accounts.store` / `.update` | POST / PUT | `code` required ≤32 unique in org · `name` ≤120 · `type` in types · `is_active` bool | `gl-accounts` |
| `finance.gl-accounts.destroy` | DELETE | none | `gl-accounts` |
| `finance.currencies.store` | POST | `code` required size 3 uppercase, unique in org · `name` ≤64 · `symbol` nullable ≤8 · `decimals` int 0–4 · `is_active` bool | `currencies` |
| `finance.currencies.update` | PUT | `name` · `symbol` · `decimals` · `is_active` · `is_base` bool (true ⇒ `CurrencyService::setBase`) | `currencies` |
| `finance.fx-rates.store` | POST | `from_currency` active org code · `to_currency` active org code, different:from · `rate` numeric gt:0, ≤ 9999999999 · `rate_date` date ≤ today. Upsert on the unique key, `source=manual` | `currencies` |
| `finance.fx-rates.destroy` | DELETE | none | `currencies` |
| `finance.fx-rates.import` | POST multipart | `file` (as cost-centre import) | `currencies` (+ `flash.import_report`) |
| `finance.tax-types.store` / `.update` | POST / PUT | `code` ≤16 unique in org · `name` ≤120 · `kind` in kinds · `rate` numeric 0–100 · `is_inclusive` bool · `applies_to` array min 1 of `expense,invoice` · `is_active` bool | `taxes` |
| `finance.drivers.store` / `.update` | POST / PUT | `code` ≤32 unique in org · `name` ≤120 · `unit` ≤32 · `is_active` bool | `drivers` |
| `finance.drivers.destroy` | DELETE | none | `drivers` |
| `finance.drivers.values.update` | PUT | `values` array of `{period_id int (period of the org), value numeric ≥0 \| null}`. Null deletes the value, otherwise upsert | `drivers` |

`flash.import_report` = `{ type: 'fx_rates'|'cost_centres', file_name, row_count, accepted_count, rejected_count, errors: {row, message}[] }`.

---

## 5. QA (fin-qa-engineer), after both builders are green

### `tests/Feature/Finance/FinanceConfigurationTest.php`

| test | asserts | FIN ID |
|---|---|---|
| `test_monthly_fiscal_year_has_twelve_contiguous_periods` | `createYear` monthly from Jan 1 gives 12 periods: seq 1–12, each starts the day after the previous ends, P12 ends Dec 31, FY ends_on is Dec 31. Also check `monthly_13` (13th starts = ends = FY end) and `4-4-5` (12 periods, 364 days, weeks 4,4,5). | 01.01 |
| `test_fiscal_years_cannot_overlap_and_only_one_is_current` | Overlap is refused (`workflow`). Making FY2 current unsets FY1. A planning FY cannot be current. | 01.01 |
| `test_periods_close_in_order_and_lock_only_after_close` | Closing P2 while P1 is open is refused. Close P1 then lock P1 is ok. Locking an open period is refused. Closing a period in a `planning` FY is refused. `closed_by_id` and `locked_by_id` are set. FY→closed is refused while any period is open. | 01.01 |
| `test_cost_centre_hierarchy_and_csv_import` | Parent from another org fails validation. Self or descendant parent is refused. Deleting with children is refused. A CSV with 3 good rows and 2 bad rows (unknown parent, unknown owner email) gives accepted 3, rejected 2, row numbers in `import_report`, and an upsert on re-import (no duplicates). | 01.02 |
| `test_tbm_taxonomy_is_seeded_for_every_new_org` | GET settings for a fresh org gives `TbmTaxonomy::count()` (49) system rows: 8 cost pools, 11 towers, `CP-HW.default_capex = true` with GL 1810. Running ensureDefaults twice adds no duplicates. A system row cannot be deleted, and its code is unchanged after an update attempt. A custom sub-category under `TW-SEC` can be created and deleted. | 01.03 |
| `test_gl_codes_are_unique_per_org_only` | Duplicate code in the same org gives a validation error. The same code in another org is ok. Deleting a GL referenced by a category is refused. | 01.04 |
| `test_fx_conversion_stores_rate_and_base_amount` | With USD→NGN 1500 on D-10 and 1550 on D-1, `convert(…, '100.00', 'USD', D)` returns `fx_rate '1550.00000000'`, `base_amount '155000.00'`. A date before any rate throws. The inverse (NGN→USD) gives 1/1550 at scale 8. GBP→USD triangulates through NGN. The same currency gives rate 1. | 01.05 |
| `test_fx_rates_import_and_single_base_currency` | Import upserts (re-import gives the same count) and rejects from = to, rate 0 and a future date. `setBase('USD')` leaves exactly one `is_base`. Deactivating the base is refused. | 01.05 |
| `test_nigeria_tax_defaults_are_seeded_and_editable` | VAT 7.5 exclusive applies to expense+invoice; WHT-5 and WHT-10 are `wht` and apply to invoice; NONE 0. An update changes the rate and the change is activity-logged. | 01.06 |
| `test_doa_bands_are_editable_and_banding_is_correct` | Defaults are the 5 keys in order. `bandFor(999999.99)` = `0-1m`, `bandFor(1000000)` = `1m-10m`, `bandFor(300000000)` = `250m+`. A PUT of 3 contiguous bands regenerates keys (`0-5m`,`5m-20m`,`20m+`). Non-contiguous, descending, or a first min ≠ 0 gives a `doa_bands` error. | 01.07 (Workflow Studio reflection is in P1) |
| `test_variance_thresholds_validate` | Defaults 5/10. Red ≤ amber fails. A valid update persists. | 01.08 |
| `test_renewal_policy_offsets_normalised` | Defaults are `[180,90,60,30,14,7,0]` with lead 90. A PUT of `[7,30,30,0]` fails validation (distinct). A PUT of `[7,30,0]` is stored `[30,7,0]`. | 01.09 |
| `test_settings_props_expose_every_dimension_source` | Props include `costCentres`, `categories`, `glAccounts`, `legalEntities`, `users`, `drivers`, `currencies` and `taxTypes` (the dimension masters for FIN-01.10). `legalEntities` includes NULL-org platform rows if any. | 01.10 |
| `test_settings_changes_are_audited` | A PUT to `finance.settings.update` (variance) writes an `activity_log` row with `log_name = it-finance`, a `subject_type` of `Setting` and the old and new values. Creating a cost centre also logs, and `created_by_id` is set. | 01.12, 07.06 |

### `tests/Feature/Finance/FinancePermissionsTest.php`

| test | asserts | ref |
|---|---|---|
| `test_all_finance_permissions_and_roles_exist` | The 13 permissions exist. The 6 roles exist with exactly the §2.7 grants. Organization Admin has all 13. Auditor has view+export+amounts but not manage or approve. Viewer has view only. | §8 |
| `test_settings_page_is_read_only_without_manage` | IT Budget Owner: GET 200 with `can.manage = false`, PUT settings.update 403, POST cost-centres.store 403. IT Finance Controller: GET with `can.manage = can.closePeriods = can.import = true`, and the PUT redirects with success. | §8, 01.12 |
| `test_period_close_and_import_need_their_own_permissions` | A user with `manage it-finance-settings` but not `close it-finance-periods` gets 403 on periods.close. Without `import it-finance`, fx-rates.import and cost-centres.import return 403. | §8 |
| `test_budget_owner_sees_only_owned_cost_centres_and_descendants` | `FinancePolicy::visibleCostCentreIds` returns [own, child, grandchild] for the Budget Owner who owns the root, `[]` for an owner of nothing, and `null` for Finance Controller, Finance Approver, Org Admin and Auditor. | §8 row restriction |
| `test_policy_is_mapped_for_finance_models` | `Gate::getPolicyFor(CostCentre::class)` is a `FinancePolicy`. `$controller->can('update', $otherOrgCostCentre)` is false. | §8 |
| `test_grant_migration_is_idempotent` | Running the `2027_01_30_000002` migration's `up()` twice after the seeder gives no duplicate `role_has_permissions` rows. | §8 |

### `tests/Feature/RouteSmokeTest.php` extension

Add `test_finance_pages_render_for_every_demo_tenant()`, skipped unless `SMOKE=1`. For `admin@kanoheritage.ng`, `admin@firstbanknigeria.ng` and `admin@acme.ng`, every parameterless `finance.*` GET route, plus `finance.settings` with each of the 11 `?tab=` values, returns **200** (not merely <500) with `component('Finance/Settings')`.
- Kano: `fiscalYears ≥ 2`, `costCentres = 10`.
- First Bank: `costCentres = 12`, with at least one carrying `legal_entity`.
- Acme: `costCentres = 0`, `fiscalYears = 0`, `categories = 49`.
- At least one cost centre with `owner_id = null` per demo bank (the deliberate gap stays).
- `finance@kanoheritage.ng` exists with role IT Finance Controller.

Also confirm the existing parameterless smoke still passes.

### Gate (orchestrator)

Run the full suite on `it_security_risk_test`, `migrate:fresh --seed` on scratch, the smoke above, `npm run build`, and Pint on touched files.

---

## 6. Spec/code conflicts and recommended decisions

| # | conflict | recommendation |
|---|---|---|
| C1 | §8 says the new roles are "seeded per org", but spatie roles are global (`teams => false`). | Create global roles and assign them per org through users (as Change Manager does). |
| C2 | §8 says "existing CISO / Head of IT … get view + approve", but those are **job titles**, not roles. | The demo seeder assigns `Finance Approver` to the CISO and Head of IT Operations users. `escalation_role` stays a job-title string (Organization Admin fallback in P2). |
| C3 | The `fin_settings` columns in §4.1 omit `doa_bands` (§6), `investment_score_weights` (§4.5 "settings"), fiscal-year start/period scheme (FIN-01.01) and `fx_provider` (§7 `fin:fx-sync`). | Add all five now to avoid P1/P5 migrations. Weights get no UI until P5, and the provider gets no UI until a provider exists. |
| C4 | `fin_tax_types` has no type column, but FIN-01.06 types (VAT/WHT/sales/none) are needed for `wht_amount` on invoices (§4.3). | Add `kind`. |
| C5 | `fin_fiscal_years` has no scheme, so 12/13/4-4-5 periods (FIN-01.01) cannot be regenerated or validated. | Add `period_scheme`. Dates and scheme are immutable after creation. |
| C6 | FIN-01.02/.05 imports land in P0, but `fin_import_batches` is a §4.3/P3 table. | P0 reports through `flash.import_report` in the batch-column shape. P3 creates the table and persists the same array. |
| C7 | §9 lists `finance.periods.close/lock/reopen` under Settings, but the close checklist and the `finance.period_reopen` workflow are P4. The §12 assertion "period close refused while actuals unposted" needs P3 actuals. | P0 ships close and lock with sequencing rules only. `reopen` and the checklist come in P4 (`PeriodCloseService` wraps `FiscalCalendarService::closePeriod`). The test moves to `PeriodCloseTest`. |
| C8 | FIN-01.07 / §12 "DoA bands … reflected in Workflow Studio conditions", but `ApprovalProcesses::PROCESSES` is a `const` with static `conditions.values`, so per-org bands cannot appear. Band keys also change when thresholds are edited, which would orphan stage conditions. | P1 adds a dynamic condition-values hook (e.g. `ApprovalProcesses::conditionValues($key, $orgId)` reading `FinanceSettings`). On a band edit, `FinanceSettings::update` remaps existing stage conditions by band **position**. P0 only stores and validates. |
| C9 | §4.7 says "all models LogsActivity"; the Change models (the stated template) do not use it. | Follow the spec (Vendor's pattern, via the `FinanceRecord` trait, log name `it-finance`). |
| C10 | §10 icon `BanknotesIcon` is a heroicon; the project rule is Material Symbols only, and `Symbols.jsx` has no `BanknotesIcon`. | Use the existing `CurrencyDollarIcon` (`payments`). Do not edit `Symbols.jsx`. |
| C11 | §10 lists 9 nav items, but only Settings exists in P0. `SidebarNavItem` renders missing routes as `#` links. | Ship the group with Settings only. Each phase appends its own item. |
| C12 | TBM "seeded … for every new org": platform-shared NULL rows (the `BelongsToTenant` style) would conflict with the strict `BelongsToOrganization` scope the spec mandates, and banks must be able to deactivate or extend rows. | Per-org copies with `is_system = true`, created lazily by `FinanceSettings::ensureDefaults` on the Settings GET, mirroring `ChangePolicySeeder`. |
| C13 | `organizations.currency` already exists, and finance adds `fin_currencies.is_base`. | Seed the base from `organizations.currency`. Do not write back to `organizations`. P1 refuses a base change once any money row exists. |
| C14 | EA has its own static FX (`config('ea.fx.rates')` via `Services/Ea/FxExposureService`). | Leave EA untouched in P0. Unify in P5 (application TCO) by making `FxExposureService` read `CurrencyService` when finance rates exist. |
| C15 | The Nigeria WHT defaults (5 %/10 %) predate the Nigeria Tax Act 2025 / WHT Regulations changes. | Seed from `config('finance.tax_types')`, keep them editable, and flag them as unverified in ATH-FIN-003. |
| C16 | §12 names `FinanceTenancyTest` while §14 and ATH-FIN-002 name `FinanceTenancyAndRoutesTest`. | Use `FinanceTenancyAndRoutesTest`. |
| C17 | The §6 default stages need CFO/CIO/MD approvers, but the demo banks have no such users, and §13 only adds finance@ and procurement@. | P0 seeds `cfo@` and `cio@` per bank too (password `password`). P1 decides MD/CEO (role fallback). |
| C18 | First Bank's demo "admin": CLAUDE.md says `admin@firstbanknigeria.ng`, while `ChangeManagementDemoSeeder` uses `ciso@`. Both exist (Group CISO). | The finance seeders use `admin@firstbanknigeria.ng`. |
| C19 | Adding `'it-finance'` to the `$modules` loop also creates `delete it-finance`, which §8 does not list. | Accept it; it is harmless and used by no route. Config deletes are gated by `manage it-finance-settings`. |
| C20 | §13 says "FinanceDemoSeeder runs after ChangeManagementDemoSeeder", but P0 has only config data. | P0's `FinanceFoundationSeeder` occupies that slot. P1's `FinanceDemoSeeder` runs right after it. |
| C21 | Kano has no legal entities (only First Bank does). | Kano cost centres keep `legal_entity_id = null`. Do not create entities for Kano (EA owns that data). |

**Deferred out of P0:**
- Dashboard and all P1+ pages and routes.
- Workflow Studio processes and `FinancePolicySeeder::ensureDefaults` (P1).
- `finance.periods.reopen` and the close checklist (P4).
- Base-currency change guard and `amount_band` accessors (P1).
- Custom fields on finance records (FIN-01.11, S, revisit at P7).
- Amount masking for users without `view it-finance-amounts` (FIN-07.10, S, P7).
- FX provider feed / `fin:fx-sync` (no provider in v1).
- Notification preferences (FIN-07.04).
- Playwright (P2/P7 only, per handover token rules).
