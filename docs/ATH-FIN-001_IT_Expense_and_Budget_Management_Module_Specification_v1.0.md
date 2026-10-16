# ATH-FIN-001 — IT Expense & Budget Management (IT Financial Management) Module Specification v1.0

**Product:** Atheris Govern (ITSRM&G platform) · **Module:** IT Financial Management (`it-finance`) · **Status:** Build contract — backend, frontend, workflows, notifications, scheduler, tests and demo data are built against this document
**Date:** 15 Oct 2026 · **Owner:** Mohammed Ali (Atheris Limited) · **Companion:** [ATH-FIN-002 Build Orchestration Plan](ATH-FIN-002_IT_Finance_Build_Orchestration_Plan.md) · **Agents:** `docs/agents/fin-*.md` (copy to `.claude/agents/`)

---

## 0. How to read this document

- §1–§2 set the positioning and the global standards the module is built to. Every feature below cites the standard it satisfies so the module can be sold in any market (Nigeria, the rest of Africa, UK/EU, Middle East, North America) without re-work.
- §3 is the feature catalogue, grouped into the seven capability areas. Each feature has an ID (`FIN-xx.yy`) that the build plan, agents, tests and the validation report reference.
- §4–§13 are the build contract: domain model, lifecycles, approval workflows, notifications & calendar, permissions, routes, pages, metrics, integrations, demo data, tests and the definition of done.
- Conventions follow the Change Management module (ATH-CHG-001): strict `BelongsToOrganization` scope, `GeneratesSequentialCode` for record codes, Workflow Studio for approvals (ATH-VAL-016), `BaseNotification` for notifications, `routes/console.php` for schedules, driver-agnostic migrations (Postgres/MySQL/SQLite), seeded demo data for both demo banks.

---

## 1. Purpose and positioning

A complete, bank-grade **IT Financial Management (ITFM)** capability inside Atheris Govern: the CIO, CFO, Head of IT, PMO, procurement and internal audit plan, allocate, spend, monitor and justify every unit of technology money in one place, with the GRC context (risks, assets, vendors, contracts, EA applications, projects, controls, KRIs) that stand-alone ITFM tools (Apptio/IBM Targetprocess, ServiceNow ITFM, Nicus, Planview, SAP, Oracle EPM) do not have, and at a price point and implementation effort that suits Tier-1 to Tier-3 institutions.

### What makes it stand out

1. **Standards-native, not Nigeria-only.** Budgeting, costing and investment governance implement COBIT 2019 APO05/APO06/EDM02, ITIL 4 Service Financial Management, ISO/IEC 20000-1 §8.4.1, the TBM Taxonomy and the FinOps Framework out of the box, with configurable fiscal calendars, multi-currency and multi-entity support. Local requirements (CBN, VAT/WHT, NGN) are configuration, not code.
2. **GRC-linked money.** Every budget line, expense, contract and investment links to the records already in the platform — vendors, assets, EA applications, risks, issues, policies, projects — so "what does this risk cost to treat?", "what is the TCO of this application?" and "which licences expire in Q1 and what do they protect?" are answered from one data set.
3. **Contract & renewal intelligence.** A contract/subscription register with a renewal calendar, notice-period tracking, T-minus notifications to the project team and a renewal-decision workflow — the single most common cause of unplanned IT spend and lapsed support in African banks.
4. **Workflow Studio approvals.** Every approval (budget, virement, expense, invoice, business case, renewal, period close) is a configurable multi-stage process with delegation-of-authority amount bands and segregation of duties — the same engine every other module uses.
5. **Value realisation, not just spend.** Business cases with NPV/IRR/payback, a benefits register, stage gates and post-implementation value reviews so the board sees return, not only cost.
6. **Audit-ready by design.** Immutable approved budget versions, full activity log, evidence attachments, period close/lock, exportable audit trail — the Head of IS Audit's view is built in.

### Standards the design follows

| Standard / framework | What the module implements |
|---|---|
| **COBIT 2019 APO06** Managed Budget and Costs | APO06.01 financial management framework (cost model, categories, cost centres); APO06.02 prioritise resource allocation (top-down targets, portfolio prioritisation); APO06.03 create & maintain budgets (cycles, versions, approvals, phasing); APO06.04 model & allocate costs (TBM cost pools/towers, allocation rules, showback/chargeback); APO06.05 manage costs (actuals, commitments, variance, forecasting, corrective action) |
| **COBIT 2019 APO05** Managed Portfolio · **EDM02** Ensured Benefits Delivery · **BAI01/BAI11** Programmes & Projects | Investment portfolio, business cases, prioritisation, stage gates, benefits register, value realisation reviews, portfolio reporting to the board |
| **ITIL 4** Service Financial Management | Budgeting, accounting (cost by service/tower), charging (showback/chargeback), service cost models, run/grow/transform classification |
| **ISO/IEC 20000-1:2018 §8.4.1** Budgeting and accounting for services | Budget by service, cost tracking against budget, reporting to management, financial forecast |
| **ISO/IEC 38500:2024** | Governance of IT investment — evaluate, direct, monitor; board visibility of IT spend and value |
| **TBM Taxonomy v4.0** (TBM Council) | Cost pools (Hardware, Software, Labour, Outside Services, Facilities & Power, Telecom, Cloud, Other) → IT Towers (Compute, Storage, Network, Data Centre, End User, Application, Delivery, Security & Compliance, IT Management…) → Solutions/Services → Business Units |
| **FinOps Framework** (FinOps Foundation) | Cloud cost ingestion, allocation by tags/accounts, anomaly detection, commitment/reserved-instance tracking, unit economics, Inform → Optimise → Operate |
| **IFRS 16 / ASC 842** (leases) · **IAS 38 / ASC 350-40** (intangibles, internal-use software) · **IAS 16** (PPE) | CapEx vs OpEx classification, capitalisation flags on investment costs, depreciation/amortisation schedules, lease vs subscription treatment, asset creation on capitalisation |
| **ISO 4217 / ISO 8601** | Currency codes with exchange-rate tables and rate-date capture; date handling and fiscal calendars |
| **Three-way match & Delegation of Authority (DoA)** (IIA GTAG, COSO ICFR, SOX-style ITGC) | Requisition → PO → GRN → invoice matching; amount-banded approval authority; requester ≠ approver ≠ payer; immutable approved records |
| **CBN** IT Standards Blueprint · Risk-Based Cybersecurity Framework · Guidelines for Licensing/Operations of banks | Evidence that IT and cybersecurity budgets are planned, approved and tracked; budget allocation for cybersecurity programmes; board-level reporting; supervisory evidence retention |
| **NDPA / GDPR** | Contract register records data-processing role and DPA status of each vendor contract (links to Vendor Management) |
| **PMI PMBOK / PRINCE2** cost management | EVM-style budget at completion (BAC), estimate at completion (EAC), cost variance, cost performance index (CPI) on investments |

---

## 2. Scope and boundaries

**In scope:** IT (and cybersecurity) budgets, expenses, commitments, actuals, forecasts, allocations, contracts/subscriptions/licences and their renewals, investments/business cases/benefits, chargeback/showback, cloud cost, approvals, notifications, calendar, dashboards, reports, ERP/GL/cloud/procurement integrations.

**Out of scope (v1):** general-ledger posting (the module *reads* actuals from the GL and *prepares* journals for export; it is not the ledger), payroll, treasury/payments execution, fixed-asset register of record (the module *creates/links* assets in Asset Management on capitalisation but Asset Management remains the register), tax filing.

**Tenancy:** every table carries `organization_id`; strict `App\Models\Traits\BelongsToOrganization` scope. Multi-entity banks use `legal_entity_id` (existing `LegalEntity`) as a dimension; group consolidation is a reporting roll-up, not shared rows.

---

## 3. Feature catalogue

Priority: **M** must (v1.0 GA) · **S** should (v1.0 if time; else v1.1) · **C** could (v1.x). Every M/S feature has acceptance criteria in §12 by ID.

### 3.1 Foundation & configuration (`FIN-01`)

| ID | Feature | Detail | Std | Pri |
|---|---|---|---|---|
| FIN-01.01 | Fiscal calendars | Per organisation: fiscal-year start month, 12 monthly periods (optionally 13 or 4-4-5), period status `open` · `closed` · `locked`; multiple fiscal years open at once (current + planning year) | APO06.03, ISO 20000 §8.4.1 | M |
| FIN-01.02 | Cost centres | Hierarchical (department → unit), code, owner (`users`), legal entity, active flag; import from CSV/ERP | APO06.01 | M |
| FIN-01.03 | Budget categories (TBM) | Seeded TBM Taxonomy v4 cost pools and IT towers; banks may add custom sub-categories; each category carries default CapEx/OpEx and default GL account | TBM | M |
| FIN-01.04 | GL account mapping | Chart-of-accounts codes (CapEx/OpEx, cost pool) so exports and ERP imports reconcile | APO06.04 | M |
| FIN-01.05 | Currencies & FX | Base currency per organisation (ISO 4217); additional transaction currencies; FX rate table (manual + CSV + optional provider feed) with rate date; every amount stores `amount`, `currency`, `fx_rate`, `base_amount` | ISO 4217 | M |
| FIN-01.06 | Taxes | Configurable tax types (VAT, WHT, sales tax, none) with rates and whether inclusive; applied on expenses/invoices; Nigeria defaults seeded (VAT 7.5 %, WHT 5 %/10 %) but editable | local | M |
| FIN-01.07 | Delegation of Authority (DoA) matrix | Amount bands per process (expense, PO, invoice, virement, business case) in base currency → required approval stages; edited in Workflow Studio as stage conditions (§6) | DoA / ICFR | M |
| FIN-01.08 | Variance thresholds | Organisation defaults for amber/red (e.g. ±5 % / ±10 %) and absolute floors; overridable per budget line | APO06.05 | M |
| FIN-01.09 | Renewal notification policy | Default T-minus schedule (180/90/60/30/14/7 days), escalation path, notice-period default; overridable per contract | — | M |
| FIN-01.10 | Dimensions | Standard dimensions on every money record: cost centre, category, legal entity, business unit, service (`BusinessService`), EA application (`Ea` application), asset, vendor, project/investment, contract, risk, issue | APO06.04 | M |
| FIN-01.11 | Custom fields | Reuse existing `CustomField` for budgets, expenses, contracts, investments | — | S |
| FIN-01.12 | Settings page | All of the above on `Finance/Settings` with tabs; changes audited | — | M |

### 3.2 Budget planning & allocation (`FIN-02`)

| ID | Feature | Detail | Std | Pri |
|---|---|---|---|---|
| FIN-02.01 | Budget cycles | `annual` · `mid_year_reforecast` · `rolling_forecast` · `supplementary`; each cycle has a fiscal year, open/close dates, instructions, owner (budget coordinator) | APO06.03 | M |
| FIN-02.02 | Budget versions | Versioned budgets per cycle: `draft` → `submitted` → `under_review` → `approved` → `baseline` (locked) → `revised` (new version); diff between versions; only one baseline per fiscal year per cost centre | APO06.03 | M |
| FIN-02.03 | Top-down targets | Envelope/ceiling per cost centre or category set by Finance; bottom-up requests compared to ceiling; over-ceiling lines flagged | APO06.02 | M |
| FIN-02.04 | Budget lines | Description, category (TBM), cost centre, legal entity, CapEx/OpEx, recurring/one-off, vendor, contract, asset, EA application, service, investment, quantity × unit cost, currency, annual amount, **phasing** across periods (equal, custom, seasonal, front/back-loaded), justification, priority (`mandatory` · `regulatory` · `committed` · `discretionary`), run/grow/transform, risk link | APO06.03, ITIL SFM | M |
| FIN-02.05 | Zero-based & incremental modes | Create new budget from scratch, from prior-year baseline (+/- %), or from prior-year actuals | APO06.03 | M |
| FIN-02.06 | Driver-based lines | Lines computed from drivers (headcount, user count, branches, transactions, storage TB) with driver value × rate; drivers maintained centrally | ITIL SFM | S |
| FIN-02.07 | Scenarios | Side-by-side scenarios (base / cut 10 % / growth) on a version; promote a scenario to the submitted version | APO06.02 | S |
| FIN-02.08 | Contingency & reserves | Reserve lines (%, amount) with release approval | APO06.03 | S |
| FIN-02.09 | Carry-forward | Unspent committed CapEx carried into the next year with approval; tracked separately from new budget | APO06.03 | S |
| FIN-02.10 | Budget transfers (virements) | Move amount between lines/cost centres/periods; reason; approval by DoA band; audit trail; net-zero validation | APO06.05 | M |
| FIN-02.11 | Supplementary budget | Mid-year additional budget request with justification and approval | APO06.03 | M |
| FIN-02.12 | Budget allocation | Allocate approved budget to projects/investments, services, business units; allocation rules (fixed %, driver-based, direct) feeding showback | APO06.04 | M |
| FIN-02.13 | Budget workbook | Spreadsheet-style editing grid (periods as columns), copy/paste, bulk import/export (XLSX/CSV) with validation report | — | M |
| FIN-02.14 | Submission & approval | Submit a version to Workflow Studio process `finance.budget_approval`; approvers see totals vs ceiling, prior year, variance; approve/return with comments per line | APO06.03 | M |
| FIN-02.15 | Budget narratives & attachments | Executive summary, assumptions, risks; attachments stored as `Evidence` | — | S |
| FIN-02.16 | Prior-year comparison | Automatic columns: PY budget, PY actual, YoY % | — | M |

### 3.3 Expense management (`FIN-03`)

| ID | Feature | Detail | Std | Pri |
|---|---|---|---|---|
| FIN-03.01 | Expense requests (requisitions) | Raise a request against a budget line: description, category, vendor, amount, currency, tax, CapEx/OpEx, needed-by date, justification, attachments (quotes); availability check (budget − committed − actual) with over-budget warning or block (configurable) | APO06.05, DoA | M |
| FIN-03.02 | Approval by DoA | Workflow Studio process `finance.expense_approval` with amount-band and category conditions (§6); SoD enforced | DoA | M |
| FIN-03.03 | Commitments / purchase orders | Approved request becomes a commitment (PO number, vendor, lines, expected delivery, currency, FX at PO date); partial/complete receipt (GRN) | three-way match | M |
| FIN-03.04 | Invoices | Vendor invoice capture (number, date, due date, amount, tax, currency), link to PO/GRN, three-way match tolerance (%/absolute), approval `finance.invoice_approval`, payment status (`unpaid` · `scheduled` · `paid` · `disputed`), payment reference; attachments | three-way match | M |
| FIN-03.05 | Actuals | Actual spend recorded from approved invoices and/or imported from GL (CSV/API) against GL account + cost centre; reconciliation screen matches GL lines to budget lines; unmatched queue | APO06.05 | M |
| FIN-03.06 | Accruals & prepayments | Month-end accrual for received-not-invoiced; prepayment amortisation schedule for annual subscriptions paid upfront | ITIL SFM | S |
| FIN-03.07 | Recurring expenses | Subscriptions, licences, maintenance and support, telecoms, cloud: recurrence (monthly/quarterly/annual), next due, auto-generate expected invoices/accruals; linked to contract (§3.6) | — | M |
| FIN-03.08 | Unbudgeted & emergency spend | Expense with no budget line → mandatory justification, flagged, routed to higher DoA band; dashboard count | APO06.05 | M |
| FIN-03.09 | Cloud cost ingestion (FinOps) | Import AWS CUR / Azure Cost Management / GCP Billing exports (CSV) and generic provider CSV; map accounts/tags to cost centres, services, applications; daily granularity; untagged spend queue | FinOps | S |
| FIN-03.10 | Cloud anomaly detection | Day-over-day / week-over-week spikes above threshold → alert and KRI reading | FinOps | S |
| FIN-03.11 | Commitments & discounts | Reserved instances / savings plans / enterprise agreements tracked as contracts with utilisation % | FinOps | C |
| FIN-03.12 | Showback / chargeback | Allocation of costs to business units/services by rules (direct, %, driver); monthly showback statements; chargeback export journal | APO06.04, ITIL SFM | S |
| FIN-03.13 | Capitalisation | Investment costs flagged capitalisable (IAS 38 criteria checklist); on go-live create/link `Asset` with cost, useful life, depreciation method; amortisation schedule lines | IAS 38 / IAS 16 | S |
| FIN-03.14 | Expense attachments & evidence | Quotes, POs, invoices, GRNs, approvals stored via `Evidence`/evidence vault with hash | audit | M |
| FIN-03.15 | Bulk import | CSV/XLSX import of expenses/invoices/actuals with validation preview and error report (`flash.import_report`) | — | M |

### 3.4 Budget monitoring & variance analysis (`FIN-04`)

| ID | Feature | Detail | Std | Pri |
|---|---|---|---|---|
| FIN-04.01 | Budget vs actual (BvA) | For any dimension and period range: Budget · Committed · Actual · Available · Forecast · Variance (amount, %) · YTD · full-year | APO06.05 | M |
| FIN-04.02 | Forecasting (EAC) | Estimate at completion per line: `actual_ytd + remaining_budget` (default), run-rate (`actual_ytd / periods_elapsed × periods`), manual override with reason; forecast variance vs baseline | PMBOK EVM | M |
| FIN-04.03 | Variance thresholds & alerts | Amber/red by % and absolute; notifications to line owner, cost-centre owner, Finance; variance explanation required above threshold at period close | APO06.05 | M |
| FIN-04.04 | Burn rate & run-rate | Monthly burn, projected exhaustion date, months of budget remaining | — | M |
| FIN-04.05 | Period close | Close a period: all actuals posted, accruals recorded, variances explained, approvals complete → `closed`; Finance lock → `locked`; re-open with approval (`finance.period_reopen`) | ICFR | M |
| FIN-04.06 | Drill-down | From total → cost centre → category → line → transactions; export at every level | — | M |
| FIN-04.07 | Dashboards | CIO dashboard (spend vs plan, forecast, top variances, unbudgeted, renewals due, cloud trend, run/grow/transform split, CapEx/OpEx split); cost-centre owner dashboard; Finance dashboard | ISO 38500 | M |
| FIN-04.08 | KRIs feed | Platform-sourced KRIs: budget variance %, unbudgeted spend %, overdue invoices, contracts past notice deadline, cloud anomaly count — via existing `KriService` measurement | KRIs module | M |
| FIN-04.09 | Trend & seasonality | 24-month rolling trend charts per category/service | — | S |
| FIN-04.10 | Commentary & actions | Variance commentary thread per line/period; corrective actions raised as `Issue` (Issues & Remediation) | APO06.05 | M |
| FIN-04.11 | Board pack figures | `ReportFigures` summary + board-pack section "IT Financial Position" | board packs | M |

### 3.5 IT investment & value realisation (`FIN-05`)

| ID | Feature | Detail | Std | Pri |
|---|---|---|---|---|
| FIN-05.01 | Investment portfolio | Programmes/projects/initiatives with code, sponsor, PM, type (`run` · `grow` · `transform`), driver (`regulatory` · `strategic` · `risk_reduction` · `efficiency` · `growth` · `obsolescence`), status, dates (start, planned end, actual end), lifecycle stage | APO05 | M |
| FIN-05.02 | Business case | Costs (one-off CapEx/OpEx, recurring run cost for N years), benefits (quantified financial by year; non-financial scored), discount rate, horizon → NPV, IRR, payback period, ROI, TCO; assumptions; risks (link `Risk`); options considered; recommended option | APO05, EDM02 | M |
| FIN-05.03 | Prioritisation scoring | Weighted scoring (strategic alignment, regulatory necessity, risk reduction, financial return, delivery risk) with configurable weights; ranking view; "fund line" against envelope | APO06.02 | M |
| FIN-05.04 | Stage gates | `idea` → `business_case` → `approved` → `in_delivery` → `delivered` → `benefits_tracking` → `closed`; gate approvals through `finance.business_case_approval`; gate criteria checklist | BAI01 | M |
| FIN-05.05 | Investment budget & actuals | Investment has its own budget (BAC) from allocated lines; actual, committed, EAC, CPI, cost variance; spend by phase | PMBOK EVM | M |
| FIN-05.06 | Benefits register | Benefit: description, type (cost saving, cost avoidance, revenue, risk reduction, compliance, productivity), owner, baseline, target, measurement method, due date, realised value by period, status; realised vs planned chart | EDM02 | M |
| FIN-05.07 | Value reviews | Post-implementation review at +3/+6/+12 months: benefits realised %, lessons; schedule and notify owners | EDM02 | M |
| FIN-05.08 | Strategy & capability alignment | Link investments to EA business capabilities / applications (`Ea`) and to objectives; capability heat-map of spend | APO05 | S |
| FIN-05.09 | Portfolio dashboard | Run/grow/transform split, pipeline by stage, planned vs actual cost, benefits realisation, at-risk investments, regulatory-mandated share | ISO 38500 | M |
| FIN-05.10 | Application TCO | TCO per EA application / service = allocated budget lines + contracts + cloud + labour; feeds EA rationalisation | TBM | S |
| FIN-05.11 | Investment change control | Scope/cost change requests on an approved investment → re-approval if over tolerance (% of BAC) | BAI01 | S |

### 3.6 Contracts, subscriptions & renewals (`FIN-06`) — explicit requirement

| ID | Feature | Detail | Std | Pri |
|---|---|---|---|---|
| FIN-06.01 | Contract / subscription register | Code, title, type (`licence` · `subscription` · `saas` · `support_maintenance` · `managed_service` · `professional_services` · `lease` · `telecom` · `cloud_commitment` · `hardware_warranty` · `other`), vendor (`Vendor`), legal entity, owner (business), technical owner, **project team** (users), cost centre, budget line, linked assets / EA applications / services, **commencement date**, **end date**, term (months), **notice period (days)**, **auto-renew** flag, renewal type (`manual` · `auto` · `evergreen` · `fixed_term`), currency, contract value, cost per period & billing frequency, licence metric & quantity (seats, cores, users), payment terms, data-processing role & DPA status, document attachments, status | ISO 20000, NDPA | M |
| FIN-06.02 | Contract lifecycle | `draft` → `active` → `in_renewal_window` → `notice_deadline_passed` → `expired` / `renewed` / `terminated` / `replaced`; statuses derived nightly from dates + decisions (§5.4) | — | M |
| FIN-06.03 | Renewal window & notice deadline | `renewal_window_opens_at = end_date − renewal_lead_days` (default 90); `notice_deadline = end_date − notice_period_days`; both visible on the record and calendar | — | M |
| FIN-06.04 | Renewal notifications (T-minus) | Scheduled notifications to the project team, owner, procurement and Finance at configurable offsets before end date and before notice deadline (defaults 180/90/60/30/14/7 and on-the-day); escalation to Head of IT/CIO if no decision by notice deadline −7; daily digest option; channels: in-app, email, optional webhook (Teams/Slack) | — | M |
| FIN-06.05 | Renewal decision workflow | From the window, owner records a recommendation: `renew_as_is` · `renew_renegotiated` · `replace` · `terminate` · `extend_short_term`; business justification, proposed cost, benchmark; approval through `finance.contract_renewal`; approved renewal creates the next contract term (new record linked `renewed_from_id`) and a budget line/expense request | — | M |
| FIN-06.06 | Renewal calendar | Calendar page (month / quarter / year / timeline-Gantt) showing commencement, renewal window opening, notice deadline, end date and value-review dates for contracts and investments; colour-coded status; filters by vendor, owner, team, cost centre, type, value band; click-through; **"passed" lane** listing contracts/investments whose end or notice date has passed with no decision; iCal (`.ics`) feed & export; printable | — | M |
| FIN-06.07 | Licence compliance | Entitled quantity vs deployed (manual or from Asset Management/EA) → over/under-licensed flags | ISO 19770 | S |
| FIN-06.08 | Contract documents & clauses | Attach signed contract, SLAs, DPAs (evidence vault); key clauses captured (SLA credits, exit, data return, price escalation %) | — | S |
| FIN-06.09 | Price escalation forecasting | Expected uplift % applied to next-term forecast and next-year budget seed | — | S |
| FIN-06.10 | Vendor spend view | Spend by vendor (contracts + expenses) feeding Vendor Management risk/concentration | TPRM | M |

### 3.7 Approvals, notifications, calendar, audit & reporting (`FIN-07`)

| ID | Feature | Detail | Std | Pri |
|---|---|---|---|---|
| FIN-07.01 | Workflow Studio processes | `finance.budget_approval`, `finance.budget_transfer`, `finance.supplementary_budget`, `finance.expense_approval`, `finance.invoice_approval`, `finance.business_case_approval`, `finance.contract_renewal`, `finance.period_reopen` registered in `ApprovalProcesses` with conditions (amount band, CapEx/OpEx, category, cost centre, type) and sensible defaults | ATH-VAL-016 | M |
| FIN-07.02 | Segregation of duties | Requester ≠ approver; budget owner cannot be the sole approver of own budget; invoice approver ≠ PO approver for amounts above a band; nobody decides two stages | ICFR | M |
| FIN-07.03 | Notification catalogue | §7 — approvals requested/decided/overdue, variance breaches, unbudgeted spend, renewal T-minus, notice-deadline passed, contract lapsed, invoice due/overdue, period close reminders, value review due, cloud anomaly, import completed | — | M |
| FIN-07.04 | Notification preferences | Per user: channel per notification group, daily digest; organisation: escalation targets | — | S |
| FIN-07.05 | Unified IT finance calendar | The renewal calendar also shows: budget cycle milestones, period close dates, invoice due dates, value reviews, investment stage-gate dates | — | M |
| FIN-07.06 | Audit trail | `spatie/activitylog` on every model; approved versions immutable (edits create revisions); export audit trail per record/period | audit | M |
| FIN-07.07 | Reports | Budget book (approved budget by cost centre/category), BvA by period, variance report with commentary, commitments register, invoice ageing, unbudgeted spend, contract register & renewals due (30/60/90), investment portfolio & benefits, cloud spend, vendor spend, chargeback statements; export XLSX/CSV/PDF; scheduled via `ScheduledReport` | — | M |
| FIN-07.08 | Copilot skills | Natural-language queries ("what is our Q2 variance in cybersecurity?", "which contracts expire before March?") through the existing Copilot with read-only tool access to finance figures | — | S |
| FIN-07.09 | API | REST endpoints (existing API layer) for budgets, expenses, actuals, contracts, investments; webhooks for approvals and renewals | — | S |
| FIN-07.10 | Data retention & privacy | Retention policy per record class; contract documents encrypted at rest; sensitive amounts masked for users without `view it-finance-amounts` | NDPA/GDPR | S |

---

## 4. Domain model

Namespaces: models `App\Models\Finance\`, services `App\Services\Finance\`, controllers `App\Http\Controllers\Finance\`, notifications `App\Notifications\Finance\`, jobs `App\Jobs\Finance\`, pages `resources/js/Pages/Finance/`, tests `tests/Feature/Finance/`. All tables carry `organization_id`, `created_by_id`, timestamps; main records have `softDeletes`. Money columns: `amount decimal(18,2)`, `currency char(3)`, `fx_rate decimal(18,8)`, `base_amount decimal(18,2)`. Codes via `GeneratesSequentialCode` with per-bank prefix (`KHB-BUD-0001`, `FBN-CON-0001`).

### 4.1 Configuration tables

| table | key columns |
|---|---|
| `fin_fiscal_years` | `year_label` (e.g. `FY2027`), `starts_on`, `ends_on`, `status` (`planning` · `open` · `closed`), `is_current` |
| `fin_periods` | `fiscal_year_id`, `sequence` 1–13, `name`, `starts_on`, `ends_on`, `status` (`open` · `closed` · `locked`), `closed_at`, `closed_by_id`, `locked_at`, `locked_by_id` |
| `fin_cost_centres` | `code`, `name`, `parent_id`, `owner_id`, `legal_entity_id`, `business_unit`, `is_active` |
| `fin_categories` | `code`, `name`, `kind` (`cost_pool` · `tower` · `sub_category`), `parent_id`, `tbm_reference`, `default_capex` bool, `default_gl_account_id`, `is_system` (seeded TBM rows), `is_active` |
| `fin_gl_accounts` | `code`, `name`, `type` (`capex` · `opex` · `balance_sheet`), `is_active` |
| `fin_currencies` | `code` (ISO 4217), `name`, `symbol`, `decimals`, `is_base`, `is_active` |
| `fin_fx_rates` | `from_currency`, `to_currency`, `rate`, `rate_date`, `source` (`manual` · `import` · `provider`) |
| `fin_tax_types` | `code`, `name`, `rate`, `is_inclusive`, `applies_to` (json: expense/invoice), `is_active` |
| `fin_drivers` | `code`, `name`, `unit`, values by period in `fin_driver_values` (`driver_id`, `period_id`, `value`) |
| `fin_settings` | one row per org: `variance_amber_pct`, `variance_red_pct`, `variance_abs_floor`, `renewal_lead_days` (90), `renewal_notice_offsets` json `[180,90,60,30,14,7,0]`, `escalation_role` (`Head of IT`), `overbudget_mode` (`warn` · `block`), `match_tolerance_pct`, `match_tolerance_abs`, `eac_method` (`remaining_budget` · `run_rate`), `showback_enabled`, `digest_hour` |

### 4.2 Budgeting

**`fin_budget_cycles`** — `App\Models\Finance\BudgetCycle`: `cycle_code`, `fiscal_year_id`, `name`, `type` (`annual` · `mid_year_reforecast` · `rolling_forecast` · `supplementary`), `opens_on`, `closes_on`, `instructions`, `coordinator_id`, `status` (`planned` · `open` · `in_review` · `closed`).

**`fin_budget_targets`** — top-down ceilings: `cycle_id`, `cost_centre_id` nullable, `category_id` nullable, `legal_entity_id` nullable, `capex_ceiling`, `opex_ceiling`, `set_by_id`.

**`fin_budgets`** (versions) — `App\Models\Finance\Budget`: `budget_code`, `cycle_id`, `fiscal_year_id`, `cost_centre_id`, `legal_entity_id` nullable, `version_no`, `title`, `mode` (`zero_based` · `incremental` · `from_actuals`), `status` (`draft` · `submitted` · `under_review` · `approved` · `baseline` · `revised` · `rejected` · `withdrawn`), `owner_id`, `submitted_by_id`, `submitted_at`, `approved_at`, `approved_by_id`, `baselined_at`, `superseded_by_id`, `executive_summary`, `assumptions`, `total_capex`, `total_opex`, `total_base_amount` (denormalised, recomputed by service), `rejection_reason`. Approved/baseline rows are immutable: any edit creates a new version (`revised`) via `BudgetService::revise()`.

**`fin_budget_lines`** — `BudgetLine`: `budget_id`, `line_no`, `description`, `category_id`, `gl_account_id`, `cost_centre_id` (inherits), `legal_entity_id`, `is_capex`, `is_recurring`, `priority` (`mandatory` · `regulatory` · `committed` · `discretionary`), `rgt` (`run` · `grow` · `transform`), `vendor_id`, `contract_id`, `asset_id`, `ea_application_id`, `business_service_id`, `investment_id`, `risk_id`, `driver_id` nullable, `driver_rate`, `quantity`, `unit_cost`, `currency`, `fx_rate`, `annual_amount`, `base_annual_amount`, `phasing_method` (`equal` · `custom` · `front` · `back` · `seasonal`), `justification`, `variance_amber_pct` / `variance_red_pct` overrides, `py_budget`, `py_actual` (snapshots), `sort_order`.

**`fin_budget_line_periods`** — phasing: `budget_line_id`, `period_id`, `amount`, `base_amount`.

**`fin_budget_scenarios`** — `budget_id`, `name`, `adjustment_pct`, `line_adjustments` json, `is_promoted`.

**`fin_budget_transfers`** — `Virement`: `transfer_code`, `fiscal_year_id`, `from_line_id`, `to_line_id` (or `to_cost_centre_id`/`to_category_id` when creating a line), `from_period_id` nullable, `to_period_id` nullable, `amount`, `base_amount`, `reason`, `requested_by_id`, `status` (`draft` · `submitted` · `approved` · `rejected` · `applied` · `withdrawn`), `approved_by_id`, `approved_at`, `applied_at`.

**`fin_supplementary_requests`** — `request_code`, `fiscal_year_id`, `cost_centre_id`, `category_id`, `amount`, `base_amount`, `justification`, `requested_by_id`, `status` (as transfers), `resulting_line_id`.

**`fin_allocations`** — rules: `allocation_code`, `source_type` (`budget_line` · `cost_centre` · `category` · `contract` · `cloud_account`), `source_id`, `target_type` (`business_unit` · `business_service` · `ea_application` · `investment` · `legal_entity`), `target_id`, `method` (`direct` · `percentage` · `driver`), `percentage`, `driver_id`, `effective_from`, `effective_to`. `fin_allocation_results` — computed per period: `allocation_id`, `period_id`, `amount_type` (`budget` · `actual` · `forecast`), `base_amount`.

### 4.3 Expenses

**`fin_expense_requests`** — `ExpenseRequest`: `request_code`, `title`, `description`, `budget_line_id` nullable (null ⇒ unbudgeted), `cost_centre_id`, `category_id`, `gl_account_id`, `legal_entity_id`, `vendor_id`, `contract_id`, `investment_id`, `asset_id`, `ea_application_id`, `is_capex`, `is_emergency`, `needed_by`, `amount`, `currency`, `fx_rate`, `base_amount`, `tax_type_id`, `tax_amount`, `total_amount`, `justification`, `requested_by_id`, `status` (`draft` · `submitted` · `approved` · `rejected` · `committed` · `partially_received` · `received` · `invoiced` · `closed` · `cancelled` · `withdrawn`), `approved_at`, `approved_by_id`, `rejection_reason`, `period_id` (expected).

**`fin_purchase_orders`** — `PurchaseOrder`: `po_code`, `expense_request_id`, `vendor_id`, `po_date`, `expected_delivery_on`, `currency`, `fx_rate`, `subtotal`, `tax_amount`, `total_amount`, `base_total_amount`, `status` (`open` · `partially_received` · `received` · `closed` · `cancelled`), `issued_by_id`, `external_reference` (ERP PO number). `fin_purchase_order_lines`: `po_id`, `line_no`, `description`, `quantity`, `unit_cost`, `amount`, `received_quantity`, `budget_line_id`, `category_id`, `asset_id`.

**`fin_goods_receipts`** — `grn_code`, `po_id`, `received_on`, `received_by_id`, `notes`; `fin_goods_receipt_lines`: `grn_id`, `po_line_id`, `quantity`, `accepted` bool, `rejection_reason`.

**`fin_invoices`** — `Invoice`: `invoice_code` (internal), `vendor_invoice_no`, `vendor_id`, `po_id` nullable, `contract_id` nullable, `expense_request_id` nullable, `budget_line_id`, `cost_centre_id`, `category_id`, `gl_account_id`, `invoice_date`, `due_on`, `period_id`, `currency`, `fx_rate`, `subtotal`, `tax_type_id`, `tax_amount`, `wht_amount`, `total_amount`, `base_total_amount`, `match_status` (`unmatched` · `matched` · `variance` · `override`), `match_variance_amount`, `status` (`draft` · `submitted` · `approved` · `rejected` · `scheduled` · `paid` · `disputed` · `cancelled`), `approved_at`, `approved_by_id`, `paid_on`, `payment_reference`, `dispute_reason`, `submitted_by_id`. `fin_invoice_lines` mirrors PO lines.

**`fin_actuals`** — `Actual`: `period_id`, `posted_on`, `source` (`invoice` · `gl_import` · `cloud_import` · `accrual` · `prepayment` · `manual` · `allocation`), `source_ref`, `invoice_id` nullable, `gl_account_id`, `cost_centre_id`, `category_id`, `budget_line_id` nullable (null ⇒ unmatched queue), `vendor_id`, `contract_id`, `investment_id`, `asset_id`, `ea_application_id`, `description`, `amount`, `currency`, `fx_rate`, `base_amount`, `is_capex`, `import_batch_id`, `matched_by_id`, `matched_at`.

**`fin_recurring_expenses`** — `recurring_code`, `title`, `contract_id`, `budget_line_id`, `vendor_id`, `category_id`, `cost_centre_id`, `frequency` (`monthly` · `quarterly` · `semi_annual` · `annual`), `amount`, `currency`, `next_due_on`, `last_generated_on`, `auto_generate` (`expected_invoice` · `accrual` · `none`), `is_active`.

**`fin_accruals`** — `period_id`, `type` (`accrual` · `prepayment_release`), `budget_line_id`, `po_id` nullable, `amount`, `base_amount`, `reversal_period_id`, `status` (`proposed` · `posted` · `reversed`), `posted_by_id`.

**`fin_cloud_accounts`** — `provider` (`aws` · `azure` · `gcp` · `oci` · `other`), `account_identifier`, `name`, `default_cost_centre_id`, `tag_mapping` json (tag key → dimension). **`fin_cloud_cost_lines`** — `cloud_account_id`, `usage_date`, `service`, `resource_id`, `region`, `tags` json, `cost`, `currency`, `base_cost`, `cost_centre_id`, `ea_application_id`, `business_service_id`, `is_untagged`, `import_batch_id`. **`fin_cloud_anomalies`** — `cloud_account_id`, `detected_on`, `scope` (`account` · `service` · `application`), `scope_ref`, `baseline_cost`, `observed_cost`, `deviation_pct`, `status` (`open` · `acknowledged` · `resolved` · `false_positive`), `owner_id`.

**`fin_import_batches`** — `type` (`actuals` · `cloud` · `budget` · `contracts` · `fx` · `cost_centres`), `file_name`, `row_count`, `accepted_count`, `rejected_count`, `report` json, `imported_by_id`, `status`.

**`fin_capitalisations`** — `investment_id` or `expense_request_id`, `asset_id` (created in Asset Management), `capitalised_on`, `amount`, `useful_life_months`, `method` (`straight_line` · `declining`), `criteria_checklist` json (IAS 38), `approved_by_id`. `fin_amortisation_lines`: `capitalisation_id`, `period_id`, `amount`.

### 4.4 Contracts & renewals

**`fin_contracts`** — `Contract`: `contract_code`, `title`, `type` (§3.6), `vendor_id`, `legal_entity_id`, `cost_centre_id`, `budget_line_id`, `owner_id` (business), `technical_owner_id`, `procurement_contact_id`, `category_id`, `description`, `commenced_on`, `ends_on`, `term_months`, `notice_period_days`, `renewal_type` (`manual` · `auto` · `evergreen` · `fixed_term`), `auto_renew` bool, `renewal_lead_days` (default from settings), `renewal_window_opens_on`, `notice_deadline_on` (both computed, stored for indexing), `notification_offsets` json nullable (override), `currency`, `contract_value`, `base_contract_value`, `billing_frequency`, `amount_per_billing`, `licence_metric` (`seat` · `user` · `core` · `device` · `instance` · `tb` · `transaction` · `site` · `enterprise` · `other`), `licence_quantity`, `deployed_quantity`, `payment_terms_days`, `price_escalation_pct`, `data_processing_role` (`none` · `processor` · `controller` · `joint`), `dpa_status` (`not_required` · `pending` · `signed`), `sla_summary`, `exit_terms`, `external_reference`, `status` (§3.6), `renewed_from_id`, `renewed_to_id`, `decision` (`renew_as_is` · `renew_renegotiated` · `replace` · `terminate` · `extend_short_term` nullable), `decision_at`, `decision_by_id`, `terminated_on`, `termination_reason`, `last_notified_offset` (int nullable), `escalated_at`.

**`fin_contract_team`** — `contract_id`, `user_id`, `role` (`project_lead` · `member` · `approver` · `watcher`) — the **project team** that receives renewal notifications.

**`fin_contract_links`** — polymorphic: `contract_id`, `linkable_type`, `linkable_id` (`Asset`, EA application, `BusinessService`, `Investment`, `Risk`).

**`fin_contract_renewals`** — `Renewal`: `renewal_code`, `contract_id`, `opened_on` (window), `recommendation` (decision enum), `justification`, `benchmark_notes`, `proposed_term_months`, `proposed_value`, `proposed_currency`, `proposed_base_value`, `uplift_pct`, `alternatives_considered`, `status` (`open` · `submitted` · `approved` · `rejected` · `executed` · `withdrawn`), `requested_by_id`, `approved_by_id`, `approved_at`, `executed_at`, `new_contract_id`, `expense_request_id`.

**`fin_contract_events`** — timeline: `contract_id`, `type` (`created` · `notified` · `escalated` · `window_opened` · `notice_deadline_passed` · `decision_recorded` · `renewed` · `terminated` · `expired` · `document_added` · `status_changed`), `payload` json, `user_id` nullable, `occurred_at`.

**`fin_contract_documents`** — `contract_id`, `evidence_id` (evidence vault), `kind` (`contract` · `sla` · `dpa` · `order_form` · `renewal_quote` · `termination_letter` · `other`), `signed_on`, `expires_on`.

### 4.5 Investments & value

**`fin_investments`** — `Investment`: `investment_code`, `title`, `type` (`programme` · `project` · `initiative`), `rgt`, `driver` (§3.5), `sponsor_id`, `manager_id`, `cost_centre_id`, `legal_entity_id`, `stage` (`idea` · `business_case` · `approved` · `in_delivery` · `delivered` · `benefits_tracking` · `closed` · `cancelled`), `status` (`on_track` · `at_risk` · `off_track` · `on_hold`), `starts_on`, `planned_ends_on`, `actual_ends_on`, `description`, `strategic_objective`, `bac` (budget at completion, base), `eac`, `cost_tolerance_pct`, `priority_score`, `priority_rank`, `is_regulatory`, `regulatory_reference`, `value_review_dates` json (`[+3m,+6m,+12m]` computed from `actual_ends_on`), `external_reference`.

**`fin_business_cases`** — `investment_id`, `version_no`, `status` (`draft` · `submitted` · `approved` · `rejected` · `superseded`), `horizon_years`, `discount_rate_pct`, `option_summary`, `recommended_option`, `costs` json (by year: capex, opex, run), `benefits` json (by year: financial), `non_financial_benefits` json (scored), `npv`, `irr_pct`, `payback_months`, `roi_pct`, `tco`, `assumptions`, `risks_summary`, `approved_by_id`, `approved_at`.

**`fin_investment_scores`** — `investment_id`, `criterion` (`strategic_alignment` · `regulatory` · `risk_reduction` · `financial_return` · `delivery_risk`), `score` 1–5, `weight_pct`, `scored_by_id`. Weights default `25/25/20/20/10` (settings).

**`fin_stage_gates`** — `investment_id`, `gate` (stage enum), `criteria` json (checklist), `passed_at`, `passed_by_id`, `approval_request_id`.

**`fin_benefits`** — `Benefit`: `benefit_code`, `investment_id`, `title`, `type` (`cost_saving` · `cost_avoidance` · `revenue` · `risk_reduction` · `compliance` · `productivity` · `customer`), `owner_id`, `measurement_method`, `baseline_value`, `target_value`, `unit` (`currency` · `pct` · `hours` · `count` · `score`), `currency`, `due_on`, `status` (`planned` · `tracking` · `realised` · `partially_realised` · `not_realised` · `cancelled`). `fin_benefit_readings`: `benefit_id`, `period_id`, `value`, `evidence_id`, `recorded_by_id`.

**`fin_value_reviews`** — `investment_id`, `review_type` (`pir_3m` · `pir_6m` · `pir_12m` · `ad_hoc`), `due_on`, `completed_on`, `reviewer_id`, `benefits_realised_pct`, `cost_outcome` (`under` · `on` · `over`), `summary`, `lessons`, `status` (`scheduled` · `due` · `completed` · `overdue`).

**`fin_investment_changes`** — `investment_id`, `change_code`, `description`, `cost_delta`, `schedule_delta_days`, `status`, `approval_request_id`.

### 4.6 Monitoring

**`fin_forecasts`** — `budget_line_id`, `period_id`, `method` (`remaining_budget` · `run_rate` · `manual`), `amount`, `base_amount`, `reason`, `set_by_id`. **`fin_variance_notes`** — `budget_line_id`, `period_id`, `variance_amount`, `variance_pct`, `severity` (`amber` · `red`), `explanation`, `corrective_action`, `issue_id` nullable, `author_id`, `acknowledged_by_id`. **`fin_period_closes`** — `period_id`, `checklist` json (actuals posted, accruals done, variances explained, invoices approved, cloud imported), `closed_by_id`, `closed_at`, `reopen_reason`, `reopen_approval_request_id`.

### 4.7 Shared

`fin_notification_logs` — `notifiable_type`, `notifiable_id`, `notification_key`, `offset_days` nullable, `sent_to_user_id`, `sent_at`, `channel` — idempotency for scheduled notifications (never send the same offset twice).

All models: `use BelongsToOrganization, LogsActivity (spatie), SoftDeletes` (main records); constants for every enum (`STATUSES`, `TYPES`, …); `scopeOpen`, `scopeForFiscalYear`, `scopeForPeriod`; relations to the dimensions above.

---

## 5. Lifecycles and services

### 5.1 `BudgetService`

`createVersion(cycle, costCentre, mode)`, `addLine`, `phase(line, method, custom[])` (validates phasing sums to annual), `recalculate(budget)`, `submit(budget, user)` → Workflow Studio `finance.budget_approval`, `approve`/`return`/`reject` (engine callbacks), `baseline(budget)` (locks; marks prior baseline `revised`; snapshots `py_*` for next year), `revise(budget)` (clones to new `draft` version `version_no + 1`, `superseded_by_id` on old once new is baselined), `diff(a, b)`, `importWorkbook`, `exportWorkbook`, `checkCeiling(budget)`.

Transitions (`TRANSITIONS` map, action ⇒ statuses allowed from): `submit` ← draft/rejected · `withdraw` ← submitted/under_review · `approve` ← submitted/under_review (final stage only) · `return` ← submitted/under_review → draft · `reject` ← submitted/under_review · `baseline` ← approved · `revise` ← approved/baseline → new draft.

### 5.2 `ExpenseService` / `ProcurementService` / `InvoiceService`

`availability(line, period?)` = `base_budget − committed_open − actual_posted` (and forecast where enabled). `submit(request)` → `finance.expense_approval` with context `{amount_band, is_capex, is_unbudgeted, is_emergency, category}`; over-budget: `warn` (allowed with justification) or `block` (settings). `commit(request)` → PO; `receive(po, lines)` → GRN; `captureInvoice`, `match(invoice)` (three-way: PO total vs GRN quantities vs invoice; tolerance from settings; `variance` requires override with reason + `finance.invoice_approval` stage condition `match_status=variance`), `approve(invoice)` → posts `fin_actuals` (source `invoice`) in `period_id`, `markPaid`, `dispute`.

### 5.3 `ActualsService` / `CloudCostService` / `ReconciliationService`

`importGl(batch)` (CSV columns configurable mapping: date, gl code, cost centre, description, amount, currency, reference), auto-match to budget line by (cost centre, gl account/category, vendor, contract) rules, unmatched queue, `match(actual, line)`, `postAccruals(period)`, `releasePrepayments(period)`. Cloud: `importProviderExport(account, file)` (AWS CUR / Azure / GCP column maps + generic), `allocateByTags`, `detectAnomalies(account)` (rolling 7-day baseline, deviation > settings threshold).

### 5.4 `ContractService` / `RenewalService` / `RenewalScheduler`

`create` computes `renewal_window_opens_on`, `notice_deadline_on`; `recompute(contract)` on date edits. Nightly `fin:contracts-sweep` (`Schedule::command(...)->dailyAt('06:30')`):

1. **Status derivation**: `active` → `in_renewal_window` when `today ≥ renewal_window_opens_on`; → `notice_deadline_passed` when `today > notice_deadline_on` and no executed decision; → `expired` when `today > ends_on` and no renewal executed (auto-renew contracts roll: new term created automatically with `decision=renew_as_is`, event `renewed`, and Finance notified); `renewed` / `terminated` / `replaced` set by decisions.
2. **T-minus notifications**: for each active/in-window contract and each offset in effective `notification_offsets`, if `ends_on − today == offset` (or `notice_deadline_on − today == offset` for the notice series) and no `fin_notification_logs` row for `(contract, key, offset)`: notify **project team + owner + technical owner + procurement contact** (`ContractRenewalDueNotification`), log it.
3. **Escalation**: if `notice_deadline_on − today ≤ 7` and no `fin_contract_renewals` row with status ≥ `submitted`: notify escalation role (`Head of IT` job title → `Organization Admin` fallback) and CIO/CFO roles once (`escalated_at`).
4. **Passed**: when `notice_deadline_on` or `ends_on` passes with no decision: event + `ContractDeadlinePassedNotification` to owner, team, Finance, Head of IT; the record appears in the calendar "Passed" lane until a decision is recorded.
5. **Digest**: users with digest preference get one `FinanceDailyDigestNotification` at `digest_hour` summarising renewals due, approvals pending, variances, invoices due.

`RenewalService`: `open(contract)` (manual or auto at window), `recommend(renewal, decision, …)`, `submit` → `finance.contract_renewal`, `approve` → `execute`: creates successor contract (`renewed_from_id`), optionally an `ExpenseRequest` for the renewal cost against the linked budget line, sets old contract `renewed`/`replaced`/`terminated`, `renewed_to_id`.

### 5.5 `InvestmentService` / `BusinessCaseCalculator` / `BenefitsService`

`BusinessCaseCalculator::evaluate(costs[], benefits[], rate, horizon)` → `npv`, `irr` (Newton–Raphson, null if no sign change), `payback_months`, `roi_pct`, `tco`. Unit-tested against known examples. `score(investment)` → weighted priority; `rank(fiscalYear)`. Stage gate `advance(investment, gate)` → `finance.business_case_approval` for `business_case → approved` and `in_delivery → delivered` (configurable). `scheduleValueReviews(investment)` on `delivered` (+3/+6/+12 months); `fin:value-reviews-sweep` marks `due`/`overdue` and notifies reviewers (`ValueReviewDueNotification`). `BenefitsService::record(benefit, period, value, evidence)` recomputes realised %.

### 5.6 `MonitoringService` / `VarianceService` / `PeriodCloseService`

`bva(filters, periodRange)` returns the §3.4 matrix; `forecast(line)` by settings method; `evaluateVariances(period)` (nightly `fin:variances-sweep`) creates/updates `fin_variance_notes` stubs with severity and sends `BudgetVarianceNotification` once per (line, period, severity). `PeriodCloseService::checklist(period)`, `close(period, user)` (refuses with the open items listed), `lock`, `requestReopen` → `finance.period_reopen`.

### 5.7 `FinanceMetrics::summary(orgId, fiscalYearId?)`

`budget_total`, `committed`, `actual_ytd`, `forecast_fy`, `variance_pct`, `available`, `unbudgeted_count/amount`, `capex_opex_split`, `rgt_split`, `top_variances[]`, `contracts_active`, `renewals_due_30/60/90`, `contracts_passed`, `invoices_overdue`, `investments_by_stage`, `benefits_realised_pct`, `cloud_mtd`, `cloud_anomalies_open`. Also `ReportFigures` registration for board packs and `KriService` platform sources (§3.4.8).

---

## 6. Approval workflows (Workflow Studio)

Register in `App\Services\Approvals\ApprovalProcesses::PROCESSES` (engine `generic`):

| key | label | model | initiator | owner | conditions | default when no stages |
|---|---|---|---|---|---|---|
| `finance.budget_approval` | Budget version approval | `Budget` | `submitted_by_id` | `owner_id` (owner_cannot_approve) | `amount_band` (total base amount), `cost_centre_id`, `cycle_type` | one approval by anyone with `approve it-finance` other than submitter |
| `finance.budget_transfer` | Budget transfer (virement) | `BudgetTransfer` | `requested_by_id` | — | `amount_band`, `crosses_cost_centre` (bool), `is_capex` | one approval, `approve it-finance` |
| `finance.supplementary_budget` | Supplementary budget | `SupplementaryRequest` | `requested_by_id` | — | `amount_band`, `category_id` | one approval, `approve it-finance` |
| `finance.expense_approval` | Expense request | `ExpenseRequest` | `requested_by_id` | — | `amount_band`, `is_capex`, `is_unbudgeted`, `is_emergency`, `category_id`, `cost_centre_id` | one approval, `approve it-finance` |
| `finance.invoice_approval` | Invoice approval | `Invoice` | `submitted_by_id` | — | `amount_band`, `match_status`, `has_po` | one approval, `approve it-finance-invoices` |
| `finance.business_case_approval` | Business case / stage gate | `Investment` (per gate) | `manager_id` | `sponsor_id` | `amount_band` (BAC), `rgt`, `is_regulatory`, `gate` | one approval, `approve it-finance-investments` |
| `finance.contract_renewal` | Contract renewal decision | `Renewal` | `requested_by_id` | contract `owner_id` | `amount_band` (proposed value), `decision`, `contract_type`, `uplift_band` | one approval, `approve it-finance-contracts` |
| `finance.period_reopen` | Re-open a closed period | `Period` | requester | — | `period_status` (closed/locked) | one approval, `close it-finance-periods` |

**`amount_band`** condition values come from the organisation's DoA matrix (`fin_settings.doa_bands` json, default `['0-1m','1m-10m','10m-50m','50m-250m','250m+']` in base currency, editable). The engine's existing `conditions` mechanism matches the record's computed `amount_band` attribute (accessor on each model).

**Seeded default stages** (`FinancePolicySeeder::ensureDefaults($orgId)`, idempotent): expense — `0–1m`: cost-centre owner; `1m–10m`: + Head of IT; `10m–50m`: + CIO/CFO (job title); `50m+`: + MD/CEO. Budget approval: Head of IT → CFO → CIO (+ MD/CEO above 250m). Invoice: cost-centre owner; `variance` match → + Finance Controller. Business case: Head of IT → CFO → IT Steering Committee (role) → Board (role) above 250m. Renewal: owner → Head of IT (+ CFO above 10m or uplift > 10 %). Virement: Finance Controller (+ CFO when crossing cost centre). Period re-open: CFO.

**Segregation of duties** (engine + module rules): initiator never approves; nobody decides two stages; budget owner cannot be sole approver; invoice final approver ≠ PO approver when `amount_band ≥ 10m–50m`; contract owner may recommend but not approve the renewal.

---

## 7. Notifications & calendar

All notifications extend `BaseNotification` (`database` + `mail`; add `webhook` channel when configured in Integrations). Each carries `module = 'IT Financial Management'`, a typed `type`, and an `actionUrl`.

| class (`App\Notifications\Finance\`) | trigger | recipients |
|---|---|---|
| `FinanceApprovalRequestedNotification` | any `finance.*` stage opens | stage approvers |
| `FinanceApprovalDecisionNotification` | approved / returned / rejected | initiator, owner |
| `FinanceApprovalOverdueNotification` | stage past SLA (hourly `fin:approvals-sweep`) | approvers + escalation role |
| `BudgetVarianceNotification` | amber/red on line/period | line owner, cost-centre owner, Finance role |
| `UnbudgetedSpendNotification` | expense submitted with no line / over budget | Finance role, Head of IT |
| `ContractRenewalDueNotification` | T-minus offsets (end date series & notice-deadline series) | project team, owner, technical owner, procurement |
| `ContractRenewalEscalationNotification` | notice deadline −7 with no decision | Head of IT, CIO, CFO |
| `ContractDeadlinePassedNotification` | notice deadline / end date passed with no decision | owner, team, Finance, Head of IT |
| `ContractRenewedNotification` | renewal executed / auto-renewed | team, Finance |
| `InvoiceDueNotification` | due in 7 days / overdue | Finance role, cost-centre owner |
| `PeriodCloseReminderNotification` | 5 working days before period end; and on blocked close | cost-centre owners, Finance |
| `ValueReviewDueNotification` | value review due / overdue | reviewer, sponsor |
| `CloudAnomalyNotification` | anomaly detected | cloud account owner, Finance |
| `ImportCompletedNotification` | import batch finished | importer |
| `FinanceDailyDigestNotification` | daily at `digest_hour` for opted-in users | user |

**Calendar (`Finance/Calendar`)** — single page, views `month` · `quarter` · `year` · `timeline`; event sources: contract commencement, renewal window opens, notice deadline, end date, auto-renew date; investment start/planned end/actual end/gate dates/value reviews; budget cycle open/close; period end/close/lock; invoice due dates. Colour: green (active, no action), amber (in renewal window), red (notice deadline passed / overdue), grey (expired/terminated/closed), blue (milestones). Lanes/filters: vendor, owner, team member ("my contracts"), cost centre, type, value band, status. **Passed** panel lists every contract/investment whose notice deadline or end date is in the past with no decision/closure, sorted by days overdue. `GET /it-finance/calendar.ics?token=` signed per-user iCal feed; `GET /it-finance/calendar/export` XLSX/PDF.

**Schedules (`routes/console.php`)**: `fin:contracts-sweep` daily 06:30 · `fin:approvals-sweep` hourly · `fin:variances-sweep` daily 07:00 · `fin:recurring-generate` daily 00:30 · `fin:invoices-sweep` daily 07:30 · `fin:value-reviews-sweep` daily 07:45 · `fin:period-reminders` daily 08:00 · `fin:cloud-anomalies` daily 05:45 · `fin:digest` hourly (sends to users whose `digest_hour` matches) · `fin:fx-sync` daily 01:00 (if provider configured). All `->withoutOverlapping()` and tenant-iterating with `withoutGlobalScopes()` as the existing sweeps do.

---

## 8. Permissions and roles

Permissions (add to `RolesAndPermissionsSeeder`): `view it-finance`, `create it-finance`, `edit it-finance`, `approve it-finance`, `approve it-finance-invoices`, `approve it-finance-investments`, `approve it-finance-contracts`, `close it-finance-periods`, `manage it-finance-settings`, `view it-finance-amounts` (unmask), `import it-finance`, `export it-finance`.

Roles (new, seeded per org alongside existing ones): **IT Budget Owner** (view/create/edit, own cost centre), **IT Finance Controller** (all + approve + close periods + import/export), **Procurement Officer** (contracts, POs, invoices create/edit), **Investment Sponsor** (investments approve), **IT Steering Committee** (business-case stage), **Finance Approver** (approve it-finance, invoices). Existing **CISO / Head of IT / Organization Admin** get `view` + `approve it-finance`; **Super Admin** bypasses. Routes gated with `permission:view it-finance` etc.; cost-centre-level row restriction via policy `FinancePolicy` (budget owners see their cost centres; Finance/Admin see all).

---

## 9. Routes (`routes/web.php`, auth group; prefix `/it-finance`, names `finance.*`)

Check `php artisan route:list --except-vendor` for `finance.` collisions before adding.

| group | routes |
|---|---|
| Dashboard | `GET finance.dashboard` |
| Settings | `GET finance.settings` · `PUT finance.settings.update` · fiscal years/periods CRUD `finance.fiscal-years.*`, `finance.periods.close/lock/reopen` · `finance.cost-centres.*` · `finance.categories.*` · `finance.gl-accounts.*` · `finance.currencies.*`, `finance.fx-rates.*` (+ import) · `finance.tax-types.*` · `finance.drivers.*` |
| Budgets | `finance.cycles.*` · `finance.budgets.index/create/store/show/edit/update` · `finance.budgets.lines.store/update/destroy/reorder` · `finance.budgets.phase` · `finance.budgets.submit/withdraw/approve/return/reject/baseline/revise` · `finance.budgets.scenarios.*` · `finance.budgets.diff` · `finance.budgets.workbook` (GET export, POST import) · `finance.targets.*` · `finance.transfers.*` (+ submit/approve/reject/apply) · `finance.supplementary.*` · `finance.allocations.*` (+ run) |
| Expenses | `finance.expenses.*` (+ submit/withdraw/approve/reject/commit/cancel) · `finance.purchase-orders.*` (+ receive, close) · `finance.invoices.*` (+ submit/match/override/approve/reject/schedule/pay/dispute) · `finance.actuals.index/import/match/unmatch` · `finance.recurring.*` · `finance.accruals.*` (+ post/reverse) · `finance.cloud.accounts.*` · `finance.cloud.import` · `finance.cloud.costs` · `finance.cloud.anomalies.*` (+ acknowledge/resolve) · `finance.capitalisations.*` |
| Monitoring | `finance.monitor.bva` · `finance.monitor.forecast` (POST override) · `finance.monitor.variances` (+ explain, raise-issue) · `finance.monitor.showback` · `finance.periods.close-checklist` |
| Contracts | `finance.contracts.*` (+ team store/destroy, links store/destroy, documents store, recompute) · `finance.renewals.*` (+ open/recommend/submit/approve/reject/execute) · `finance.calendar` · `finance.calendar.ics` · `finance.calendar.export` |
| Investments | `finance.investments.*` (+ score, advance-gate, hold, cancel) · `finance.business-cases.*` (+ submit/approve/reject, evaluate POST returns NPV/IRR live) · `finance.benefits.*` (+ readings.store) · `finance.value-reviews.*` (+ complete) · `finance.investment-changes.*` · `finance.portfolio` |
| Reports | `finance.reports.index` · `finance.reports.{report}` with `?format=xlsx|csv|pdf` · `finance.imports.index/show` |
| API (existing `api.` layer) | `GET /api/v1/it-finance/{budgets,expenses,actuals,contracts,investments}` read; `POST actuals`, `POST cloud-costs` write; webhooks `finance.approval.*`, `finance.contract.renewal_due` |

---

## 10. Pages (`resources/js/Pages/Finance/`) and navigation

Sidebar group **"IT Financial Management"** (icon `BanknotesIcon`, permission `view it-finance`): Dashboard · Budgets · Expenses & Invoices · Budget Monitor · Contracts & Renewals · Calendar · Investments & Value · Reports · Settings.

| page | key props |
|---|---|
| `Dashboard.jsx` | `metrics` (§5.7), `spendByCategory[]`, `monthlyBvA[]`, `topVariances[]`, `renewalsDue[]`, `pendingApprovals[]`, `fiscalYears[]`, `selectedFiscalYear` |
| `Budgets/Index.jsx`, `Budgets/Create.jsx`, `Budgets/Show.jsx` (tabs: Lines workbook · Phasing · Scenarios · Approvals · Versions/diff · Narrative · Activity), `Budgets/Workbook.jsx` (grid: lines × periods, inline edit, totals, ceiling bar), `Budgets/Transfers.jsx`, `Budgets/Supplementary.jsx`, `Budgets/Targets.jsx`, `Budgets/Allocations.jsx`, `Budgets/Cycles.jsx` | `budget`, `lines[]`, `periods[]`, `ceiling`, `availableDimensions{}`, `approval` (engine state: current step, who can decide), `versions[]`, `can{}` |
| `Expenses/Index.jsx`, `Expenses/Create.jsx`, `Expenses/Show.jsx` (availability widget, approval panel, PO/GRN/invoice chain, evidence) · `PurchaseOrders/Index|Show.jsx` · `Invoices/Index|Create|Show.jsx` (match panel) · `Actuals/Index.jsx` (unmatched queue, import) · `Recurring/Index.jsx` · `Accruals/Index.jsx` · `Cloud/Accounts.jsx`, `Cloud/Costs.jsx`, `Cloud/Anomalies.jsx` · `Capitalisations/Index.jsx` | per record + `availability{budget,committed,actual,available}` |
| `Monitor/BvA.jsx` (pivot by dimension × period, drill-down, export), `Monitor/Variances.jsx` (explain/raise issue), `Monitor/Forecast.jsx`, `Monitor/Showback.jsx`, `Monitor/PeriodClose.jsx` (checklist) | `matrix`, `filters`, `thresholds` |
| `Contracts/Index.jsx` (status chips, renewals-due filter), `Contracts/Create.jsx`, `Contracts/Show.jsx` (tabs: Overview · Team · Links · Documents · Renewal · Timeline · Spend), `Renewals/Index|Show.jsx` | `contract`, `team[]`, `links[]`, `documents[]`, `renewal`, `events[]`, `spend{}`, `daysToEnd`, `daysToNotice` |
| `Calendar.jsx` (month/quarter/year/timeline + Passed panel + filters + ics link) | `events[]` `{id,type,title,start,end,color,status,href,meta}`, `passed[]`, `filters` |
| `Investments/Index.jsx`, `Investments/Create.jsx`, `Investments/Show.jsx` (tabs: Overview · Business case (live NPV/IRR) · Scoring · Gates · Budget & actuals · Benefits · Value reviews · Changes · Activity), `Investments/Portfolio.jsx` (ranking, fund line, RGT split, bubble chart) | `investment`, `businessCase`, `scores[]`, `gates[]`, `benefits[]`, `reviews[]`, `evm{bac,ac,eac,cv,cpi}` |
| `Reports/Index.jsx`, `Imports/Index|Show.jsx`, `Settings.jsx` (tabs: General · Fiscal years & periods · Cost centres · Categories (TBM tree) · GL accounts · Currencies & FX · Taxes · Drivers · DoA bands · Variance thresholds · Renewal policy) | |

UI follows `tailwind.config.js` (navy `#0A1F44`, gold `#C9A86A`), Material Symbols, the shared table/form/modal components used by Change Management; amounts formatted with `Intl.NumberFormat` in the user's locale and the record currency, base-currency equivalent on hover. Charts follow the platform's existing chart components (dashboards).

---

## 11. Integrations

| system | direction | mechanism (v1) |
|---|---|---|
| ERP / GL (SAP, Oracle, Dynamics 365, Sage, QuickBooks, Finacle GL extracts) | in (actuals, cost centres, GL accounts, FX) · out (approved budget, chargeback journal, PO/invoice export) | CSV/XLSX with saved column mappings per connection (`IntegrationConnection` type `erp`); REST adapter interface `ErpAdapter` with a `GenericCsvAdapter` shipped; SAP/Oracle/Dynamics adapters as v1.x |
| Cloud billing (AWS CUR, Azure Cost Management export, GCP Billing export, OCI) | in | CSV import + column maps; S3/Blob fetch adapter v1.x |
| Procurement / e-procurement | out/in | PO export, invoice import (CSV); webhook on approval |
| HR / headcount | in | driver values import |
| Vendor Management (existing) | both | contracts/spend per vendor; vendor concentration; vendor assessments linked from contract |
| Asset Management (existing) | both | assets ↔ budget lines/contracts/capitalisation; deployed quantities for licence compliance |
| Enterprise Architecture (existing) | both | application TCO; capability alignment; obsolescence-driven investments |
| Risks, Issues, Policies, Change Mgmt (existing) | both | risk-treatment cost, variance corrective actions as issues, policy exceptions for unbudgeted spend, changes linked to investments |
| KRIs & Dashboards, Board packs, Reports (existing) | out | platform-sourced KRIs, `ReportFigures`, scheduled reports |
| Copilot (existing) | read | finance figure tools |
| Email / Teams / Slack | out | notification channels (webhook) |

---

## 12. Acceptance criteria (by feature ID — the test suite maps 1:1)

- **FIN-01**: fiscal year with 12 periods created; period close refused while actuals unposted; FX conversion stores rate & base amount; TBM categories seeded for every new org; DoA bands editable and reflected in Workflow Studio conditions.
- **FIN-02**: draft → submitted → approved → baseline with immutable baseline (edit attempt creates v2); phasing must sum to annual; ceiling breach flagged; transfer net-zero enforced and applied only after approval; workbook round-trips import/export; PY columns populate from prior baseline/actuals.
- **FIN-03**: availability = budget − committed − actual; over-budget `block` mode refuses submission, `warn` requires justification; PO → GRN → invoice three-way match within tolerance auto-`matched`, outside → `variance` requiring override + stage; invoice approval posts actual in correct period; GL import matches by rules and leaves unmatched queue; recurring expense generates expected invoice on due date; cloud import allocates by tag and flags untagged; anomaly detected on a seeded spike.
- **FIN-04**: BvA matrix totals reconcile to lines/actuals to the cent; EAC by both methods; amber/red notifications sent once per (line, period, severity); period close blocked by unexplained red variance; KRI readings produced by `kri:measure`.
- **FIN-05**: NPV/IRR/payback match reference values (unit test); priority rank recomputed on score change; gate advance requires approval; benefits realised % updates on readings; value reviews scheduled on delivery and notified when due; CPI/EAC computed from actuals.
- **FIN-06**: window & notice dates computed and recomputed on edit; nightly sweep sends T-minus notifications exactly once per offset to the whole project team; escalation at notice −7; status derivation for every path (auto-renew rolls a new term; manual with no decision → `notice_deadline_passed` → `expired`); renewal approval executes successor contract linked both ways + expense request; calendar returns events for all sources and a correct "passed" list; `.ics` validates.
- **FIN-07**: all eight processes appear in Workflow Studio, run through `ApprovalEngine`, enforce SoD, default when unconfigured; every notification class renders mail + database payload; audit log entries exist for create/update/approve on each model; reports export in 3 formats; amounts masked without `view it-finance-amounts`.
- **Tenancy**: `FinanceTenancyTest` — every `finance.*` GET route 200 for org 2 and 3 with no cross-tenant leakage (`RouteSmokeTest` extended); org 1 sees empty pages, not errors.

---

## 13. Demo data — `FinanceDemoSeeder` (runs after `ChangeManagementDemoSeeder`; tenant-scoped guard on `fin_budgets`; `mt_srand` per bank)

Both demo banks get: FY2026 (closed periods Jan–Sep, open Oct–Dec) and FY2027 (planning); 8–12 cost centres; TBM categories; NGN base + USD/GBP/EUR with FX; VAT/WHT; a baselined FY2026 budget (~60 lines, realistic Nigerian bank IT estate: Finacle AMC, core network, Microsoft EA, cybersecurity tooling, cloud, data centre, telecoms, staff training), with actuals through September showing some amber/red variances and one unbudgeted emergency expense; FY2027 draft budgets in various approval states; 25–40 contracts (Finacle, Microsoft, Oracle, Cisco SmartNet, Fortinet, CrowdStrike, Interswitch, NIBSS, MTN/Airtel/Globacom links, AWS/Azure, Temenos (First Bank only)) with a spread of statuses including two in renewal window, one past notice deadline (appears in "Passed"), one auto-renewing, one terminated; renewal records in progress; 8–12 investments across stages with business cases (one regulatory, one transform), benefits and a due value review; cloud accounts with 90 days of cost lines and a seeded anomaly; invoices in each status including an overdue one; pending approvals for each process so Workflow Studio demos work; KRI sources registered.

Users: existing demo users get the new roles (Kano: CISO = approver, Head of IT, a Finance Controller `finance@kanoheritage.ng`, Procurement `procurement@kanoheritage.ng`; same for First Bank). Passwords `password`.

---

## 14. Tests (`tests/Feature/Finance/`)

`FinanceConfigurationTest`, `BudgetLifecycleTest`, `BudgetWorkbookTest`, `BudgetTransferTest`, `ExpenseApprovalAndAvailabilityTest`, `ProcurementThreeWayMatchTest`, `ActualsImportAndReconciliationTest`, `CloudCostTest`, `MonitoringAndVarianceTest`, `PeriodCloseTest`, `ContractRenewalLifecycleTest`, `ContractNotificationSweepTest` (time-travelled with `Carbon::setTestNow`, asserts `Notification::fake()` counts and idempotency), `CalendarTest`, `InvestmentAndBusinessCaseTest` (+ `BusinessCaseCalculatorTest` unit), `BenefitsAndValueReviewTest`, `FinanceWorkflowStudioTest` (all 8 processes, SoD), `FinanceNotificationsTest`, `FinanceReportsTest`, `FinancePermissionsTest`, `FinanceTenancyAndRoutesTest`; `RouteSmokeTest` list extended; Playwright journeys `finance-budget.spec.js`, `finance-contract-renewal.spec.js`, `finance-calendar.spec.js`.

---

## 15. Definition of done

1. Every **M** feature implemented against §12 with passing tests; **S** features implemented or explicitly deferred in the build update (ATH-FIN-003).
2. `composer test`, `php artisan migrate:fresh --seed`, `npm run build`, `SMOKE=1 … RouteSmokeTest`, and the three Playwright journeys pass on Postgres.
3. Migrations driver-agnostic; `./vendor/bin/pint` on touched files; no `tenant_id` in queries.
4. Workflow Studio lists the eight finance processes and the seeded default stages for both demo banks.
5. The renewal calendar shows commencement, window, notice deadline and end for every seeded contract; the "Passed" lane shows the seeded lapsed contract; T-minus notifications demonstrably sent by `php artisan fin:contracts-sweep` with `Carbon::setTestNow`.
6. Navigation, permissions, roles, demo users, README module table row and `docs/VALIDATION_HANDOFF.md` updated; `ATH-FIN-003_IT_Finance_Build_Update.md` written with what shipped, deferred and known gaps.
7. Security review (fin-security-reviewer) and finance-domain review (fin-domain-reviewer) sign-offs recorded in ATH-FIN-003.

---

## 16. Phasing (delivery order; see ATH-FIN-002 for agent assignment and prompts)

| phase | scope | exit |
|---|---|---|
| **P0 Foundation** | §4.1 tables, settings page, TBM seed, FX, taxes, permissions/roles, nav, `FinancePolicy`, tenancy test | settings usable; routes 200 |
| **P1 Budgeting** | cycles, versions, lines, phasing, workbook, targets, transfers, supplementary, `finance.budget_approval` + `finance.budget_transfer` + `finance.supplementary_budget` in Workflow Studio, notifications | budget lifecycle test green |
| **P2 Contracts & renewals** | contract register, team, links, documents, lifecycle, sweep, T-minus notifications, escalation, renewal workflow, **calendar** (+ Passed lane, ics) | renewal + calendar tests green; demo contracts seeded |
| **P3 Expenses** | expense requests, availability, DoA approval, PO/GRN/invoice, three-way match, actuals import & reconciliation, recurring, accruals, evidence | expense/procurement tests green |
| **P4 Monitoring** | BvA, forecast/EAC, variance sweep & notes, period close/lock/re-open, dashboards, KRIs, board-pack figures, reports | monitoring/close tests green |
| **P5 Investments & value** | portfolio, business case calculator, scoring, gates, benefits, value reviews, EVM, portfolio page | investment tests green |
| **P6 Cloud, showback, capitalisation** (S) | cloud import/allocation/anomalies, allocations/showback, capitalisation & amortisation | cloud/showback tests green |
| **P7 Hardening** | security & domain reviews, Playwright, smoke, seed, README/handoff, ATH-FIN-003 | DoD §15 |
