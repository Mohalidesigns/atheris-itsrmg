# ATH-FIN-002 — IT Financial Management: Build Orchestration Plan (agents, models, prompts)

**Builds:** [ATH-FIN-001 Module Specification](ATH-FIN-001_IT_Expense_and_Budget_Management_Module_Specification_v1.0.md) · **Date:** 15 Oct 2026 · **Owner:** Mohammed Ali (Atheris Limited)

This document is what you paste into Claude Code. It assigns every task in the spec to a named agent and model, fixes the order, and gives the kick-off prompt for each phase. The agent definitions are shipped in `docs/agents/fin-*.md`. **Before the first session, install them** so Claude Code loads them as subagents:

```bash
mkdir -p .claude/agents && cp docs/agents/fin-*.md .claude/agents/
```

(`docs/agents/` is the versioned source; `.claude/agents/` is what Claude Code reads.)

---

## 1. The team

| agent (`docs/agents/` → `.claude/agents/`) | model | role | writes code? |
|---|---|---|---|
| **orchestrator** — your main Claude Code session | Opus-class (Fable 5.1 / Opus 5.5) | Runs each phase: asks `fin-architect` for the plan, dispatches builders in parallel where files don't overlap, collects reviews, decides fixes, keeps the task list | yes (small fixes, wiring) |
| `fin-architect` | **opus** | Turns a spec section into a file-by-file plan that matches this codebase; flags collisions and spec/code conflicts | no |
| `fin-backend-engineer` | **sonnet** | Migrations, models, services, controllers, routes, policies, seeders, feature tests | yes |
| `fin-frontend-engineer` | **sonnet** | Inertia/React pages, navigation, workbook grid, calendar, charts | yes |
| `fin-workflow-integrator` | **sonnet** | Workflow Studio processes & default stages, notifications, sweeps, calendar feed, KRIs, board-pack figures, reports, integration hooks | yes |
| `fin-domain-reviewer` | **opus** | Reviews a built phase against COBIT/ITIL/ISO 20000/TBM/FinOps/IFRS and the acceptance criteria | no |
| `fin-security-reviewer` | **opus** | Tenancy, permissions, SoD, immutability, input handling, scheduler safety | no |
| `fin-qa-engineer` | **sonnet** | Tests, demo seeder, Playwright, smoke, the full gate, hand-off docs | yes |

Why these models: planning and review need the strongest reasoning and are read-only, so Opus-class there is cheap relative to the cost of a wrong design; the building agents run many file edits and test loops, where Sonnet is fast and accurate given a precise plan; the orchestrator should be your strongest available model because it makes the merge decisions. If you are on a plan where Opus is constrained, run `fin-architect` on Opus and the two reviewers on Sonnet with the prompt "be exhaustive" — do not downgrade the architect.

Parallelism rule: run builders concurrently only when their file sets don't overlap (backend vs frontend is safe; two backend agents on the same service are not). Reviewers always run after the phase's gate passes, never on a red suite.

---

## 2. Phase plan

Each phase ends with the **gate**: `composer test` green, `php artisan migrate:fresh --seed` clean, `npm run build` clean, `./vendor/bin/pint` on touched files, then the two reviews, then fixes, then commit on a `feature/it-finance-pN` branch.

| phase | spec | agents (in order) | parallel? | gate test classes |
|---|---|---|---|---|
| **P0 Foundation** | §3.1, §4.1, §8, §9 (settings), §10 (nav, Settings page) | architect → backend + frontend → qa → reviewers | backend ∥ frontend | `FinanceConfigurationTest`, `FinancePermissionsTest`, `FinanceTenancyAndRoutesTest` |
| **P1 Budgeting** | §3.2, §4.2, §5.1, §6 (3 budget processes), §7 (approval notifications) | architect → backend → frontend + integrator → qa → reviewers | frontend ∥ integrator | `BudgetLifecycleTest`, `BudgetWorkbookTest`, `BudgetTransferTest`, `FinanceWorkflowStudioTest` (partial) |
| **P2 Contracts & renewals** | §3.6, §4.4, §5.4, §6 (`finance.contract_renewal`), §7 (renewal notifications, sweep, **calendar**) | architect → backend → frontend + integrator → qa → reviewers | frontend ∥ integrator | `ContractRenewalLifecycleTest`, `ContractNotificationSweepTest`, `CalendarTest` |
| **P3 Expenses** | §3.3 (01–08, 14, 15), §4.3 (requests, PO, GRN, invoices, actuals, recurring, accruals), §5.2–5.3, §6 (expense & invoice processes) | architect → backend → frontend + integrator → qa → reviewers | frontend ∥ integrator | `ExpenseApprovalAndAvailabilityTest`, `ProcurementThreeWayMatchTest`, `ActualsImportAndReconciliationTest` |
| **P4 Monitoring** | §3.4, §4.6, §5.6–5.7, §6 (`finance.period_reopen`), §7 (variance/period notifications), reports | architect → backend → frontend + integrator → qa → reviewers | frontend ∥ integrator | `MonitoringAndVarianceTest`, `PeriodCloseTest`, `FinanceReportsTest` |
| **P5 Investments & value** | §3.5, §4.5, §5.5, §6 (`finance.business_case_approval`), §7 (value review notifications) | architect → backend → frontend + integrator → qa → reviewers | frontend ∥ integrator | `InvestmentAndBusinessCaseTest`, `BusinessCaseCalculatorTest`, `BenefitsAndValueReviewTest` |
| **P6 Cloud, showback, capitalisation** (S-priority) | §3.3 (09–13), §4.3 (cloud, capitalisation), §4.2 (allocations), §5.3 | architect → backend → frontend + integrator → qa → reviewers | frontend ∥ integrator | `CloudCostTest`, showback/capitalisation tests |
| **P7 Hardening & hand-off** | §12–§15 | qa → both reviewers (full module) → orchestrator fixes → qa gate | — | full suite, smoke, Playwright ×3 |

Estimated effort with this team: P0 ½ day · P1 1½ days · P2 1 day · P3 1½ days · P4 1 day · P5 1 day · P6 1 day · P7 1 day — about 8–9 working days of agent time, in sessions of one phase each.

---

## 3. Session protocol (same every phase)

1. Start Claude Code in the repo root on a fresh branch: `git checkout -b feature/it-finance-pN`. First time only: `mkdir -p .claude/agents && cp docs/agents/fin-*.md .claude/agents/`.
2. Paste the **phase kick-off prompt** from §4.
3. The orchestrator must: create a task list; call `fin-architect` and save its plan to `docs/build/ATH-FIN-PLAN-pN.md`; dispatch builders with the plan path and the spec section numbers; after each builder reports, run the gate itself; call `fin-qa-engineer`; call both reviewers in parallel; apply Critical/High fixes (dispatching back to the relevant builder); re-run the gate; write the phase summary into `docs/ATH-FIN-003_IT_Finance_Build_Update.md` (append a section per phase); commit.
4. Never let an agent "finish" on a red suite; never skip the reviewers on P1, P2, P3 and P7.
5. If an agent reports a conflict between the spec and the codebase, the orchestrator decides, records the decision in ATH-FIN-003 §"Decisions", and continues — it does not silently change the spec.

---

## 4. Kick-off prompts (copy-paste)

### P0 — Foundation

```
We are building the IT Financial Management module to docs/ATH-FIN-001_IT_Expense_and_Budget_Management_Module_Specification_v1.0.md, following docs/ATH-FIN-002_IT_Finance_Build_Orchestration_Plan.md. This session is Phase P0 (Foundation): spec §3.1, §4.1, §8, §9 (settings routes), §10 (navigation group and Settings page).

Follow the session protocol in ATH-FIN-002 §3. Steps:
1. Use the fin-architect agent to produce the P0 plan (migrations for all §4.1 tables, models, FinanceSettings service, FinancePolicy, permissions/roles in RolesAndPermissionsSeeder, TBM Taxonomy seed, Nigeria tax defaults, FX table, nav group, Settings page with tabs, FinanceTenancyAndRoutesTest). Save it to docs/build/ATH-FIN-PLAN-p0.md.
2. Dispatch fin-backend-engineer (backend of the plan) and fin-frontend-engineer (Settings page + nav) in parallel.
3. Run the gate yourself: composer test, php artisan migrate:fresh --seed, npm run build, pint on touched files.
4. Dispatch fin-qa-engineer to add FinanceConfigurationTest, FinancePermissionsTest and extend RouteSmokeTest, then re-run the gate.
5. Dispatch fin-security-reviewer and fin-domain-reviewer in parallel on P0; fix Critical/High; re-run the gate.
6. Append a "Phase P0" section to docs/ATH-FIN-003_IT_Finance_Build_Update.md (create it) and commit on feature/it-finance-p0.
Report at the end: tests count, routes added, anything deferred.
```

### P1 — Budget planning & allocation

```
Phase P1 (Budgeting) of the IT Financial Management module: spec §3.2, §4.2 (budget cycles, targets, budgets/versions, lines, line periods, scenarios, transfers, supplementary requests; allocations tables may be created now but allocation execution is P6), §5.1 BudgetService, §6 processes finance.budget_approval, finance.budget_transfer, finance.supplementary_budget with FinancePolicySeeder::ensureDefaults and the amount_band condition from the DoA bands, §7 approval notifications, §10 Budgets pages including the Workbook grid and version diff.

Follow ATH-FIN-002 §3. fin-architect → plan to docs/build/ATH-FIN-PLAN-p1.md. fin-backend-engineer builds the backend first (it defines the props contract); then fin-frontend-engineer (pages) and fin-workflow-integrator (ApprovalProcesses entries, default stages, FinanceApproval* notifications, fin:approvals-sweep) in parallel. fin-qa-engineer adds BudgetLifecycleTest, BudgetWorkbookTest, BudgetTransferTest, the budget part of FinanceWorkflowStudioTest, and seeds FY2026 baseline + FY2027 drafts for both demo banks per §13. Gate, then both reviewers, fix, gate, append to ATH-FIN-003, commit on feature/it-finance-p1.
Acceptance: every FIN-02 M item in §12 proven by a test; baseline immutability; phasing sums; ceiling flag; virement net-zero and approval; workbook round-trip.
```

### P2 — Contracts, subscriptions, renewals & calendar

```
Phase P2 (Contracts & renewals) of the IT Financial Management module: spec §3.6, §4.4, §5.4 (ContractService, RenewalService, the fin:contracts-sweep status derivation, T-minus notification series for both end date and notice deadline, escalation at notice −7, "passed" handling, auto-renew roll), §6 finance.contract_renewal, §7 contract notifications and the Calendar (month/quarter/year/timeline, Passed panel, filters, signed .ics feed, export), §10 Contracts/Renewals/Calendar pages.

Follow ATH-FIN-002 §3. fin-architect → docs/build/ATH-FIN-PLAN-p2.md. fin-backend-engineer builds contracts/team/links/documents/renewals/events + routes; then in parallel fin-frontend-engineer (Contracts Index/Create/Show with tabs, Renewals, Calendar.jsx) and fin-workflow-integrator (process + default stages, ContractRenewalDue/Escalation/DeadlinePassed/Renewed notifications, fin_notification_logs idempotency, fin:contracts-sweep + fin:digest in routes/console.php, FinanceCalendarService with .ics). fin-qa-engineer: ContractRenewalLifecycleTest, ContractNotificationSweepTest (Carbon::setTestNow, Notification::fake, run sweep twice → one send per offset, recipients = project team + owner + technical owner + procurement), CalendarTest, 25–40 demo contracts per bank including two in window, one past notice deadline, one auto-renewing, one terminated; Playwright finance-contract-renewal.spec.js and finance-calendar.spec.js. Gate, reviewers, fix, gate, ATH-FIN-003, commit on feature/it-finance-p2.
This phase is a headline selling feature — the calendar and notifications must be demo-ready for a bank CIO.
```

### P3 — Expense management

```
Phase P3 (Expenses) of the IT Financial Management module: spec §3.3 items 01–08, 14, 15; §4.3 expense requests, purchase orders + lines, goods receipts + lines, invoices + lines, actuals, recurring expenses, accruals, import batches; §5.2 ExpenseService/ProcurementService/InvoiceService and §5.3 ActualsService/ReconciliationService (GL CSV import with saved column mapping, auto-match rules, unmatched queue); §6 finance.expense_approval and finance.invoice_approval with conditions amount_band, is_capex, is_unbudgeted, is_emergency, match_status, has_po; §7 UnbudgetedSpend, InvoiceDue, ImportCompleted notifications and fin:recurring-generate, fin:invoices-sweep; §10 Expenses/PurchaseOrders/Invoices/Actuals/Recurring/Accruals pages.

Follow ATH-FIN-002 §3. Order: fin-architect → fin-backend-engineer → (fin-frontend-engineer ∥ fin-workflow-integrator) → fin-qa-engineer (ExpenseApprovalAndAvailabilityTest, ProcurementThreeWayMatchTest, ActualsImportAndReconciliationTest; demo expenses/POs/invoices in every status, an overdue invoice, one unbudgeted emergency expense, Sept actuals for FY2026) → gate → reviewers → fix → gate → ATH-FIN-003 → commit on feature/it-finance-p3.
Key correctness points: availability = budget − committed − actual; settings.overbudget_mode warn vs block; three-way match tolerance → matched / variance (+override with reason and extra stage); invoice approval posts the actual in invoice.period_id; SoD: PO approver ≠ invoice approver above the band.
```

### P4 — Budget monitoring, variance analysis & reporting

```
Phase P4 (Monitoring) of the IT Financial Management module: spec §3.4, §4.6 (forecasts, variance notes, period closes), §5.6 MonitoringService/VarianceService/PeriodCloseService, §5.7 FinanceMetrics + ReportFigures + KRI sources, §6 finance.period_reopen, §7 BudgetVariance and PeriodCloseReminder notifications with fin:variances-sweep and fin:period-reminders, §3.7 reports (budget book, BvA, variance with commentary, commitments, invoice ageing, unbudgeted, contract register/renewals due, vendor spend) in XLSX/CSV/PDF via ScheduledReport; §10 Dashboard.jsx, Monitor/BvA (pivot + drill-down), Monitor/Variances, Monitor/Forecast, Monitor/PeriodClose, Reports/Index.

Follow ATH-FIN-002 §3 with the usual order. fin-qa-engineer adds MonitoringAndVarianceTest (BvA reconciles to the cent; EAC by both methods; amber/red sent once per line/period/severity), PeriodCloseTest (blocked by unexplained red variance; lock; re-open needs approval), FinanceReportsTest, and seeds amber/red variances for FY2026. Gate, reviewers, fix, gate, ATH-FIN-003, commit on feature/it-finance-p4.
```

### P5 — IT investment & value realisation

```
Phase P5 (Investments & value) of the IT Financial Management module: spec §3.5, §4.5 (investments, business cases, scores, stage gates, benefits + readings, value reviews, investment changes), §5.5 InvestmentService / BusinessCaseCalculator (NPV, IRR via Newton–Raphson with null on no sign change, payback months, ROI, TCO) / BenefitsService, EVM figures (BAC, AC, EAC, CV, CPI) from P3 actuals, §6 finance.business_case_approval per gate with conditions amount_band (BAC), rgt, is_regulatory, gate, §7 ValueReviewDue notification and fin:value-reviews-sweep, §10 Investments pages (business case tab with live NPV/IRR via finance.business-cases.evaluate), Portfolio.jsx (ranking, fund line, RGT split).

Follow ATH-FIN-002 §3. fin-qa-engineer adds BusinessCaseCalculatorTest (reference: cost 1,000,000 at year 0, benefit 300,000 for 5 years at 10 % → NPV ≈ 137,236; IRR ≈ 15.24 %; payback 40 months), InvestmentAndBusinessCaseTest, BenefitsAndValueReviewTest, and seeds 8–12 investments across stages per bank with one due value review. Gate, reviewers (the domain reviewer must check the finance maths), fix, gate, ATH-FIN-003, commit on feature/it-finance-p5.
```

### P6 — Cloud cost (FinOps), showback & capitalisation

```
Phase P6 of the IT Financial Management module (S-priority features): spec §3.3 items 09–13, §4.3 cloud accounts / cloud cost lines / anomalies / capitalisations / amortisation lines, §4.2 allocations + allocation results, §5.3 CloudCostService (AWS CUR, Azure Cost Management, GCP Billing and generic CSV column maps; tag→dimension allocation; untagged queue; 7-day rolling baseline anomaly detection), AllocationService (direct / percentage / driver; per-period results; showback statements; chargeback journal export), CapitalisationService (IAS 38 checklist; creates/links Asset in Asset Management; straight-line and declining amortisation), §7 CloudAnomaly notification and fin:cloud-anomalies, §10 Cloud/*, Monitor/Showback, Capitalisations pages.

Follow ATH-FIN-002 §3. fin-qa-engineer adds CloudCostTest (import, allocation by tag, untagged flag, seeded spike detected), showback reconciliation and capitalisation/amortisation tests; seeds two cloud accounts per bank with 90 days of cost and one anomaly. Gate, reviewers, fix, gate, ATH-FIN-003, commit on feature/it-finance-p6.
```

### P7 — Hardening & hand-off

```
Phase P7 (Hardening & hand-off) of the IT Financial Management module. No new features. 
1. fin-qa-engineer: run the complete gate (composer test, migrate:fresh --seed, npm run build, SMOKE=1 RouteSmokeTest, all three finance Playwright journeys), fix flaky or missing tests, confirm every FIN-xx.yy M item in spec §12 has a passing test and list any S items deferred.
2. fin-security-reviewer and fin-domain-reviewer in parallel on the whole module (all phases), exhaustive mode.
3. Fix every Critical and High finding (dispatch to the right builder), re-run the gate.
4. fin-qa-engineer: update README module table (new row "IT Financial Management — Built & validated — ATH-FIN-003"), docs/VALIDATION_HANDOFF.md, finalise docs/ATH-FIN-003_IT_Finance_Build_Update.md with shipped / deferred / known gaps / decisions / review sign-offs, and add the module to CLAUDE.md "Modules" section in two lines.
5. Commit on feature/it-finance-p7 and open a PR to the integration branch with the ATH-FIN-003 summary as the description.
```

---

## 5. Orchestrator checklist per phase

- [ ] Branch created; task list created from the phase row in §2
- [ ] `fin-architect` plan saved under `docs/build/`
- [ ] Builders dispatched with: plan path, spec section numbers, the rule "run `php artisan test --filter <Class>` after every slice"
- [ ] Gate run by the orchestrator (not trusted from the builder's report)
- [ ] `fin-qa-engineer` tests + seed + gate
- [ ] Both reviewers run; findings triaged; Critical/High fixed; Medium/Low logged in ATH-FIN-003
- [ ] ATH-FIN-003 section appended (what shipped, tests, deferred, decisions, review verdicts)
- [ ] Commit with attribution lines; PR only at P7

## 6. Decisions log (fill as you go — also mirrored in ATH-FIN-003)

| # | date | decision | reason |
|---|---|---|---|
| 1 | 2026-10-15 | Routes prefixed `/it-finance`, names `finance.*`; sidebar group "IT Financial Management" | avoids collision with any future `budget.*`/`contracts.*` naming; "IT Financial Management" is the ITIL/COBIT term buyers search for |
| 2 | 2026-10-15 | Approvals exclusively through Workflow Studio (`generic` engine) — no module-private engine | ATH-VAL-016 made Workflow Studio the single approval configuration surface |
| 3 | 2026-10-15 | Contracts live in this module and link to `Vendor`; Vendor Management keeps `contract_start/end/value` as a summary synced from the primary contract | avoids two registers; Vendor Management stays the TPRM view |
