# ATH-FIN-PLAN-p7 — IT Financial Management: P7 Hardening & hand-off

Architect plan for ATH-FIN-002 §4 P7. **P7 adds no features.** Inputs: ATH-FIN-003 §P0–§P6 deferral lists, ATH-FIN-HANDOVER, spec §12–§15. Branch `feature/it-finance-p7` (= main 70b5e99 = P6 964d8bb). Every "open / resolved" status below was checked against the current tree (file:line evidence); items marked *(log)* were classified from the ATH-FIN-003 text only, because a grep could not prove them either way. They are all BACKLOG, and the P7 reviewers re-examine them.

DB rule for every agent: explicit `DB_CONNECTION=pgsql DB_DATABASE=<own db>` prefix on every command. Fixers use `it_security_risk_fin_wpa` … `_wpe`, QA uses `it_security_risk_fin_qa`, and reviewers use throwaway `it_security_risk_<agent>_test`. The orchestrator alone uses `it_security_risk_test` (suite) and `it_security_risk_scratch` (seed + SMOKE + Playwright). Never touch `it_security_risk`.

---

## 0. Decisions (state as decided; orchestrator logs them in ATH-FIN-003 §P7 "Decisions")

| # | decision | reason |
|---|---|---|
| P7-1 | **P3 L13, emergency spend: no automatic DoA band escalation.** Emergency requests keep the mandatory justification, the `emergency` flag in the audit trail and the UnbudgetedSpend notification. Banks that want an extra stage add one on the existing `emergency = yes` condition (`ApprovalProcesses.php:225`). Document this in the admin notes. | Escalating would slow exactly the spend that is urgent. FIN-03.08's "routed to higher DoA band" applies to *unbudgeted* spend, which P3-5 `effective_band` already escalates. The condition key already exists, so a bank needs no code. |
| P7-2 | **P5 gap "no path to reassign sponsor/manager/cost centre/rgt after approval": closed, already resolved** by C168 (`InvestmentChangeService.php:88-91`, kind `reassign`, always through the change gate). | Shipped in P6. |
| P7-3 | **Job-title approver matching for finance processes is segment-exact.** The user's `job_title` is split on `/ , & ;` and on the word "and", and each part is trimmed. The user matches when any part equals `approver_ref`, case-insensitively. This is enforced in the finance wrapper (`FinanceApprovals`), not in the shared `ApprovalEngine` (same precedent as P1-6). The engine's substring rule (`ApprovalEngine.php:212`) is logged as a platform defect for Change Management. Demo `headofit@` users get the job title "Head of IT". | Today "Head of IT Operations" (itops@) and "Deputy Managing Director" would satisfy the "Head of IT" and "Managing Director" stages, which is a DoA bypass. "Managing Director/CEO" keeps working. **Needs user confirmation; see §6 Q1.** |
| P7-4 | **Cross-legal-entity virements are refused**: a transfer whose from and to lines have different non-null `legal_entity_id` fails validation. No setting is added. | It mirrors P1-4 (CapEx↔OpEx refused). A transfer between group entities is an intercompany recharge, not a budget virement. |
| P7-5 | **Period close needs red variance notes *acknowledged*, not just explained.** Amber notes still only need an explanation (or the config fail flag). | Explain → acknowledge already has SoD (`VarianceService.php:186`). Closing on "explained" lets the explainer bypass the second pair of eyes (P4 dom M2). |
| P7-6 | **Re-opening a period** is refused while a non-voided chargeback run exists for that period: void it first, which needs `close it-finance-periods` with SoD. On re-open approval, `fin_allocation_results.is_frozen` is cleared for the period, so the close recomputes. Approved capitalisations that consumed the period's actuals show as a **warn** item in the re-open request. | P6 dom M7 / sec M2 / sec M3. The exported journal must not diverge silently. |
| P7-7 | **Accrual FX:** received-not-invoiced accruals are converted at the period-end rate (`closing`, falling back to `spot` at `period.ends_on`), not the PO rate. Reversals keep the accrual's own rate. | IAS 21.23: a monetary liability at the reporting date uses the closing rate (P4 dom M6). |
| P7-8 | **Prepayment releases that fall in a later fiscal year** post to the successor of the invoice's line in that FY's baseline (lineage by `line_key`). If there is no successor, they post with `budget_line_id = null`, so they land in the unbudgeted queue. | P3 M10. A release must never post against the prior-year line. |
| P7-9 | **GL duplicate key** = (`source_ref`, vendor if mapped, `base_amount`, `posted_on`), against approved invoice postings *and* earlier rows of the same file. A reference match alone with a different amount is flagged `possible_duplicate` in `match_reason`, never excluded. | P3 M11 / L15: a reference-only key gives both false positives and misses. |
| P7-10 | **Direct invoices against an approved expense request** are capped at the request's `total_amount` plus the bank's match tolerance (existing settings), summed over non-cancelled invoices. | P3 M12. Reuses the existing tolerance; no new setting. |
| P7-11 | **Investment `funded` criterion** = Σ current base amount of tagged baseline lines ≥ (BAC, or the draft business case's one-off cost when BAC is null). The criterion detail shows the gap. | P5 dom M4. One token line must not satisfy "funded". |
| P7-12 | **EVM edge cases:** once delivered (and in `benefits_tracking` / `closed`), EV = BAC. When EV = 0 and AC > 0, CPI = 0, EAC = AC + (BAC − EV) and health is `off_track`. | P5 dom M8: a division by zero must not read as "on track at BAC". |
| P7-13 | **Investment scoring SoD:** the sponsor and the manager cannot score their own investment. Gate approvers keep bank-wide visibility and may score (P5 sec M6 accepted as by design: the steering committee is bank-wide). | P5 dom M14. |
| P7-14 | **Benefit control fields** (`target_value`, `baseline_value`, `unit`, `due_on`) are frozen once the investment has passed the `approved` gate. A correction means cancelling the benefit and registering a new one, which is audited. Readings are refused on `closed` investments as well as cancelled ones. | P5 sec M4 / dom M7. No new change-request feature. |
| P7-15 | **Reference pickers** (contract, investment, vendor and cost-centre dropdowns, the user directory) stay bank-wide: titles only, no amounts (P2 L6, P5 sec L1, P6 sec M7 accepted). **Cross-module pickers** (risks, EA applications, business services) are emptied unless the user holds that module's view permission (P5 sec L2). | Cost-centre scoping applies to money records, not reference lists; module permissions must hold everywhere. |
| P7-16 | **Activity/audit rows shown inside finance pages:** for a viewer without `view it-finance-amounts`, `changes`/`properties` are dropped. Activity *descriptions* never carry amounts: a description that carries one is a bug, and the fix is at the write site. | P2 sec L5, P5 sec L10, P6 dom M15. |
| P7-17 | **TCO `unattributed` < 0** (FinOps cloud above the ledger cloud) is shown as `unattributed = 0` plus a reconciling line, `finops_excess` = the absolute value. The identity becomes `tco_total + unattributed − finops_excess = it_total`. | P6 domain follow-up. Keeps the "ties to the cent" property without showing a negative bucket. |
| P7-18 | **Finance KRI definitions** (`KRI-FIN-VAR`, `-UNB`, `-INV`, `-NOT`, `-CLD`) are provisioned by `FinanceKriSource::ensureDefinitions($orgId)`, called from `FinanceSettings::ensureDefaults`. Demo seeders keep their `updateOrCreate`. | P6 L8: today a real bank gets no finance KRIs. |
| P7-19 | **Kept as is, and documented:** `$guarded = []` on finance models (no controller mass-assigns; services whitelist with `Arr::only`); the workbook stays CSV only (P1-2); FY contiguity gaps are allowed (overlap is already refused, `FiscalCalendarService.php:31`); there are no credit notes or approved-invoice reversal; investment API endpoints, FIN-03.11 and the `forecast` allocation type are backlog. | All are features or design choices, not defects. |

---

## 1. Triage of every deferred item

Legend: **MF** = must-fix before UAT · **BL** = backlog · **RES** = already resolved · WP = work package in §3.

### P0
| id | description | status / evidence | class | WP |
|---|---|---|---|---|
| P0-sec-S9 | Settings shows DoA band, CFO, invoice-SoD and board thresholds to users without amounts | open: `FinanceSettingsController.php:78` renders unmasked; the route needs only `view it-finance` (`web.php:725`) | MF (unmasked amounts) | A |
| P0-sec-S9b | No cost-centre filter on Settings | open | BL | — |
| P0-sec-S10 | `import it-finance` unused | RES: imports gated on it (`FinancePolicy.php:172,277`; route middleware on actuals, cloud, expenses and invoices import) | RES | — |
| P0-sec-S13 | Mass deletes not audited *(log)* | open | BL (covered by checklist H4) | — |
| P0-sec-S15 | `$guarded = []` | open; no `$request->all()` in finance controllers | BL (P7-19) | — |
| P0-sec-S16 | Unbounded FX load on Settings | partly: list capped at 200 (`FinanceSettingsController.php:62`), "latest" query loads all (`:63`) | BL (checklist H3) | — |
| P0-dom-D15 / D17 / D20 / D11 | Cost-centre delegates; TBM v5 labels; `ensureDefaults` race; DoA "up to" wording | open | BL | — |
| P0→P1 S7/D5/C8, D3, D8, S8/C13, D16 | DoA versions, pool×tower, FX `rate_type`, base guard, per-bank bands | RES in P1 (ATH-FIN-003 §P1) | RES | — |
| P0→P3 D6/C15 | Nigeria tax regime (WHT 2024 / Tax Act 2025) | partly (VAT/WHT shipped, P3-3); the rates are unverified | BL + user question Q3 | — |
| P0→P3 D12, S11 | Match tolerance; CSV formula injection | RES (P3-8; `ReportExporter.php:14`, `FinanceCalendarService.php:192`); the workbook gap is P1-L3 | RES | — |
| P0→P3 D14 | Fuller IAS 16/38 / IFRS 16 chart of accounts *(log)* | open | BL | — |
| P0→P4 D10, C7 | Future-dated close guard; reopen + checklist | RES in P4 (`period_ended`, `finance.period_reopen`) | RES | — |

### P1
| id | description | status / evidence | class | WP |
|---|---|---|---|---|
| P1-dom-M1/M3/M4 | Supplementary FX re-quote; diff and totals on current amounts; locked baseline transitions | RES in P2 (ATH-FIN-003 §P2 intro) | RES | — |
| P1-dom-M5a | Bank policy in app config, not per tenant | partly (renewal CFO is per tenant since P2.1); the rest is open | BL (feature) | — |
| P1-dom-M5b | Postgres-only `ilike` in a seeder | open: `FinanceDemoSeeder.php:534` | MF (driver-agnostic rule) | A |
| P1-dom-M6 | Cross-legal-entity virements | open: no `legal_entity` check in `BudgetTransferService` | MF (accounting treatment, P7-4) | A |
| P1-dom-M7 | FX staleness limit; mixed planning rates | open | BL (new setting) | — |
| P1-dom-M8 | `FinanceNotificationsTest` missing (spec §14, FIN-07) | open: no such class | MF (acceptance coverage) | QA |
| P1-sec-M3 | Targets list and transfer/supplementary summaries not cost-centre filtered | open: `BudgetTargetController.php:34` (all targets, all CCs at `:54`); `BudgetTransferController.php:47`, `SupplementaryRequestController.php:46` (`$all` summary incl. `applied_base`) | MF (scoping leak) | A |
| P1-sec-M5 | SoD keyed on submitter and owner; preparer can reassign the owner in draft | open; owner is frozen after submit (`BudgetService::updateHeader` → `assertEditable`); submitter, owner and creator are excluded | BL (residual risk needs owner and preparer collusion; adding the cost-centre owner would strand Head of IT stages) | — |
| P1-L1 | Base-currency guard ignores targets and DoA bands | open: `config/finance.php:65` lacks `fin_budget_targets`, `fin_investments`, `fin_business_cases` | MF (data integrity, one line) | A |
| P1-L2 | Remainder phasing can go negative | open: `BudgetPhasing.php:60-68` (0.10 over 12 → last −0.01) | MF (correctness) | A |
| P1-L3 | Workbook CSV guard misses tab/CR and code columns | open: `BudgetWorkbook.php:358` only `=+-@` and TEXT columns | MF (security) | A |
| P1-L4 | Notifications mail amounts to users without rights | RES: `FinanceApprovalNotifier.php:74` | RES | — |
| P1-L5 | DoA activation and approval sweep loops lack per-tenant isolation | open: `FinanceApprovalSweeper.php:26`, `DoaBandService.php:172` | MF (scheduler safety) | A |
| P1-L6 / L7 / L9 | Return stored as rejection; no SoD on baseline/apply; seeded banks keep the 2-stage transfer | open | BL | — |
| P1-L8 | N+1 on index pages | open (e.g. `BudgetTransferController::lineOptions` calls `currentByPeriod` per line, `:103-108`) | BL → checklist H3 | — |
| P1-C25 | XLSX workbook | open by decision P1-2 | BL (P7-19) | — |
| P1-C26 / C27 | From-actuals mode; allocation execution | RES (P3, P6) | RES | — |
| P1-C35 | Listeners registered twice | not affecting finance: `AppServiceProvider` registers no Finance listener; finance relies on auto-discovery only | RES for finance; platform BL | — |

### P2 / P2.1
| id | description | status / evidence | class | WP |
|---|---|---|---|---|
| P2-dom-L1 / L3 / L5 / L7 | fixed_term vs evergreen; digest gaps; documents not encrypted at rest; single-approver deadlock | open | BL | — |
| P2-dom-L4 | IFRS 16 lease indicator | RES (P3 `lease_assessment`) | RES | — |
| P2-dom-L6 | Budget-line contract picker ignores visibility and terminal status | partly: terminal filtered (`PresentsBudgets.php:203`); visibility by decision P7-15 | RES/BL | — |
| P2-sec-L1 | N+1 in sweep and digest | open | BL → checklist H3 | — |
| P2-sec-L2 | Uploads keep the client extension | partly: MIME whitelist (`StoreContractDocumentRequest.php:21`), attachment download; `ContractService.php:239` still uses the client extension | BL (trivial; no exposure) | — |
| P2-sec-L3 | ICS poll writes an activity row per poll | open | BL | — |
| P2-sec-L4 | `owner` stage lets the owner approve | RES: `sod_excluded_ids` snapshot enforced (`FinanceApprovals.php:50,81`) | RES | — |
| P2-sec-L5 | Contract amounts unmasked in the audit trail | open (key-based masking does not cover activity `changes`) | MF (masking, P7-16) | E |
| P2-misc | ComplianceController evidence counts include contract docs | open | BL (counts only) | — |
| P2.1 open ×3 | Termination statuses in `optionsPayload`; terminations on renewals pages; "approved, not executed" badge inferred from `days_to_end` | open (calendar uses the backend flag) | BL (UI/feature) | — |

### P3
| id | description | status / evidence | class | WP |
|---|---|---|---|---|
| P3-sec-L1 | Line `category_id`/`asset_id` not bank-checked | open: PO lines `ProcurementService.php:70`, invoice lines `InvoiceService.php:390` (header is checked at `ExpenseService.php:377,386`) | MF (tenancy) | B |
| P3-sec-L2 | Recurring: no cost-centre access check | open: `RecurringExpenseService.php:45` (bank only) | MF (scoping) | B |
| P3-sec-L4 | Reject/return skips SoD with no pending request | open (`ExpenseService.php:192-199`) | BL (the requester can cancel anyway; no gain) | — |
| P3-dom-M7/M8/M9 | Accrual VAT, cut-off, close vs pending accruals | RES in P4 | RES | — |
| P3-dom-M10 | Prepayment releases cross into a planning FY | open: `AccrualService.php:198-216` (same `budget_line_id` across FYs) | MF (accounting, P7-8) | B |
| P3-dom-M11 / L15 | GL duplicates keyed on reference only; in-file duplicates | open: `ReconciliationService.php:128-139`, `ActualsService.php:166-170` | MF (integrity, P7-9) | B |
| P3-dom-M12 | Direct invoices uncapped vs the approved request | open: `InvoiceService.php:335-336` | MF (control bypass, P7-10) | B |
| P3-dom-L13 | Emergency spend escalation | decision P7-1 | BL (closed by decision) | — |
| P3-dom-L14 | Recurring dates skipped silently | open: `RecurringExpenseService.php:126-130` (advances `next_due_on` after a failure) | MF (completeness) | B |
| P3-misc | Webhook channel; expense CSV column-mapping UI; credit notes (C92) | open | BL (features) | — |
| P3-misc | Seeder dates drift if reseeded after December | partly: the foundation is relative to `now()`; `FinanceFinOpsDemoSeeder.php:659,665` hard-code 2026 | MF (UAT seed) | D (+ user Q2) |

### P4
| id | description | status / evidence | class | WP |
|---|---|---|---|---|
| P4-dom-M1 | BvA severity over unelapsed periods | open: `MonitoringService.php:384-392` classifies the range budget incl. future periods | MF (correctness) | B |
| P4-dom-M2 | Close needs no acknowledgement; closer may self-explain | open: `PeriodCloseService.php:102-110` (open notes only) | MF (SoD, P7-5) | B |
| P4-dom-M3 | Drill, commitments report and availability define commitments differently | open: drill `MonitoringService.php:543-561` (no prepayment releases, uses `remainingForPo` without accrual relief `BudgetAvailability.php:151-160`); `CommitmentsReport.php:52-54` (`OPEN_COMMITMENT` statuses) | MF (figures disagree) | B |
| P4-dom-M5 | Snapshots ignore overrides / replaced on re-close | RES in P5 (`superseded_at`) | RES | — |
| P4-dom-M6 | Accruals at PO-date FX | open: `AccrualService.php:46,86` | MF (IAS 21, P7-7) | B |
| P4-dom-M7 / M8 / M9 | Demo polish; scoped viewers' unallocated; "approvals complete" invoices only | open | BL (M9 per C107) | — |
| P4-dom-L1…L14 | Not itemised anywhere (ATH-FIN-003 and the plan list only "L1–L14") | unknown | BL; P7 domain reviewer re-finds them | — |
| P4-sec-M2 | KRIs / board pack unmasked | RES in P5 (`FinanceMasking::boardContent`) | RES | — |
| P4-sec-L1 / L2 / L5 / L6 / L7 / L9 | Org-wide checklist counts; report preview needs only view; issue title shows line label; reopen races; org filters on lookups (global scopes already apply); report memory | open | BL | — |
| P4-sec-L3 | `$guarded = []` | P7-19 | BL | — |
| P4-sec-L8 | Recipients not permission-checked | partly (new notifications filter on `view it-finance`) | BL | — |
| P4-C108 | FY contiguity | overlap refused (`FiscalCalendarService.php:31`); gaps allowed | BL (P7-19) | — |

### P5
| id | description | status / evidence | class | WP |
|---|---|---|---|---|
| P5-gap | No reassignment after approval | RES (P7-2) | RES | — |
| P5-dom-M1 / M2 / M3 / M5 / M9 / M10 / M11 / M13 | Lazy benefit status; demo audit prefix; DoA on one-off cost (C126); closure approval; self-attested delivery; mean of ratios (unit-agnostic, `BenefitsService.php:150-162`); BC↔register link; unconfigured workflow collapses to one approver | open | BL (decisions or features) | — |
| P5-dom-M4 | `funded` satisfied by one tagged line | open: `StageGateService.php:85` | MF (control, P7-11) | C |
| P5-dom-M6 | Gate criteria not re-evaluated at decision | open: `StageGateService.php:194-216` | MF (control) | C |
| P5-dom-M7 | Readings and financials mutable after closure | open: `BenefitsService.php:56-60` refuses only cancelled | MF (integrity, P7-14) | C |
| P5-dom-M8 | EVM edge cases | open: `InvestmentFinancials.php:165-168` | MF (correctness, P7-12) | C |
| P5-dom-M12 | Job-title substring match | open: `ApprovalEngine.php:212`; live in the demo (itops@ "Head of IT Operations" matches "Head of IT") | MF (DoA bypass, P7-3) | A |
| P5-dom-M14 | Scoring not independent of manager/sponsor | open: `InvestmentScoring.php:31-45` | MF (SoD, P7-13) | C |
| P5-dom-L1 | Advance race without row lock | RES: `StageGateService.php:130` | RES | — |
| P5-dom-L2 / L6 | Change apply clamp; IAS 38 flag | RES in P6 | RES | — |
| P5-dom-L3 / L5 / L7 / L8 | Fund line keeps delivered items; NPV note/sensitivity; board threshold in config; unlimited postponement | open | BL | — |
| P5-dom-L4 | Actual tagged to two investments counted twice | open: `InvestmentFinancials.php:180-186` (`investment_id OR line in tagged lines`) | MF (correctness) | C |
| P5-dom-L9 | Delivered end date read from `$criteria[0]` | open: `StageGateService.php:147` | MF (correctness) | C |
| P5-sec-M1 / M2 / M5 | Trail amount band; untag scope; one pending request | RES: `FinanceApprovals.php:168-176`; P6; `StageGateService.php:130` | RES | — |
| P5-sec-M4 | Benefit targets editable after approval | open: `BenefitsService.php:44-47` | MF (P7-14) | C |
| P5-sec-M6 | Approvers see and score every investment | by design (P7-13) | BL | — |
| P5-sec-M7 | Raw/bulk writes outside the audit trail *(log)* | open | BL → checklist H4 | — |
| P5-sec-L1 | Investment picker beyond cost-centre visibility | P7-15 | BL | — |
| P5-sec-L2 | Risk/EA/business-service pickers ignore module permissions | open: `PresentsBudgets.php:196-199` | MF (permission bypass) | A |
| P5-sec-L3 | `saveLine` accepts any open investment | open: `BudgetService.php:~390` (bank + open stage only) | MF (scoping: the investment's cost centre must be visible to the editor, or the editor bank-wide) | A |
| P5-sec-L4 / L7 / L8 / L9 | Orphaned evidence file; self-attested manual criteria; `$guarded`; sweeper queries | open | BL | — |
| P5-sec-L5 | Business-case inputs lack type/size rules | partly: array caps (`BusinessCaseFormRequest.php:19-21`); amounts `nullable` only, `year` unbounded | MF (input handling, small) | C |
| P5-sec-L6 | Null causer on business-case rejection/withdrawal audit | open: `BusinessCaseService.php:115,121` | MF (audit completeness) | C |
| P5-sec-L10 | Activity log shows business-case money regardless of amounts | open | MF (P7-16) | E |
| P5-misc | Investment API endpoints; value reviews in digest; webhooks; multi-currency BC; Change link | open | BL (features) | — |

### P6
| id | description | status / evidence | class | WP |
|---|---|---|---|---|
| P6-dom-M1 | "Reconciles to the cent" test is circular | open | MF (the acceptance criterion FIN-04 must be proven) | D |
| P6-dom-M3 / M4 / M10 / M12 / M13 / M14 | Criteria evidence docs; amortisation lifecycle; hard-coded local assumptions; FOCUS fidelity; asset cost/life (C158); allocation maker-checker | open | BL (features) | — |
| P6-dom-M6 | Allocation rules starting before the open floor cannot be edited (blocks chargeback) | open: `AllocationService.php:194-199` validates unchanged dates | MF (operational blocker) | D |
| P6-dom-M7 / sec-M2 / sec-M3 | Re-open ignores exported chargeback, `is_frozen` and capitalisations; closed showback/TCO half frozen | open: no chargeback/allocation reference in `PeriodCloseService` | MF (integrity, P7-6) | B |
| P6-dom-M8 / sec-M6 | Restatement deletes manual attributions and other batches' lines without a before/after record | open: `CloudCostService.php:212` | MF (integrity + audit) | D |
| P6-dom-M9 | One spike → 3 anomalies, inflating `KRI-FIN-CLD` | open: `KriMeasures.php:161` | MF (KRI correctness) | D |
| P6-dom-M11 / sec-M1 / sec-L3 / sec-L9 / dom-L15 | Tag rules, account creation and anomaly actions not cost-centre scoped; subquery without org filter; batch detail beyond the scope; dashboard count vs list scope | open: no visibility checks in `CloudTagRuleController`, `CloudImportController` or the anomaly actions; `CloudAnomalyController.php:30` | MF (scoping) | D |
| P6-dom-M15 / sec-M4 | Amounts in free text (activity description, error text, `blocked_reason`); capitalisation submit without an amounts check | open: `CapitalisationService.php:269` ("… approved: {$c->amount} capitalised") | MF (masking) | D (write sites) + E (rendering) |
| P6-dom-M16 | Capitalisation decided without `view it-finance-amounts` | open: `CapitalisationService.php:488` checks only the approve permission | MF (control) | D |
| P6-dom-L2 | Straight-line with a tiny amount ends negative | open: `AmortisationSchedule.php:55-61` | MF (accounting) | D |
| P6-dom-L8 | Finance KRIs only defined by the demo seeder | open: `FinanceMonitoringDemoSeeder.php:521-524`, `FinanceFinOpsDemoSeeder.php:812` | MF (P7-18) | D |
| P6-dom-L10 | Chargeback downloads not audited (tax treatment = BL) | open: `ChargebackController.php:25-30` | MF (audit) | D |
| P6-dom-L1 / L3 / L4 / L5 / L6 / L7 / L9 / L11 / L12 / L13 / L14 | Various precedence, labels, migration `down()`, SoD importer/dismisser, maturity | open | BL | — |
| P6-follow-up | `unattributed` TCO can go negative | open: `ApplicationTcoService.php:106-108` | MF (P7-17) | D |
| P6-follow-up | Recoverable-VAT capitalisation setting has no UI | open (default off) | BL (UI feature) | — |
| P6-follow-up / sec-M5 | Sweeper only notifies anomalies it created; not isolated per account | open: `CloudAnomalySweeper.php:33-50` (per-org try only) | MF (alerting) | D |
| P6-sec-M7 | Bank-wide dropdowns / user directory for scoped viewers | P7-15 | BL | — |
| P6-sec-M8 | Cloud CSV memory; `base_cost` overflow | partly: row cap (`CloudExportParser.php:21`), size cap (`CloudImportRequest.php:22`) | BL | — |
| P6-sec-M9 | Dashboard `investments_by_stage.bac` unmasked | open: `bac` missing from `PresentsMonitoring.php:20-25` | MF (unmasked amounts) | E |
| P6-sec-M10 | Capitalisation index loads everything | open: `CapitalisationController.php:43` | MF (perf on a main page; paginate) | D |
| P6-sec-L1 / L2 / L5 / L7 / L8 | `authorize(): true` (controllers authorise); `$guarded`; void race and filename; throttling; client file_name | open | BL | — |
| P6-sec-L4 | Capitalisation SoD snapshot omits the sponsor and survives reassignment | open (`Capitalisation::sodExcludedIds`) | MF (SoD) | D |
| P6-sec-L6 | Trail shows `requires_cfo` / `requires_board` to users without amounts | open: `FinanceApprovals.php:168-176` masks only `amount_band` | MF (masking) | A |
| P6-scope | FIN-03.11, `forecast` amount type, investment API, spec §6 update | open | BL / docs (§5) | — |

**Counts (per ID; bundled rows count each ID):** 62 MUST-FIX (61 in WP-A…E, 1 QA-owned: P1-M8) · 94 BACKLOG (incl. those closed by decision; the un-itemised P4 L1–L14 counted as one) · 31 already resolved.

---

## 2. Open decisions → recommendation

All are stated in §0: P7-1 (L13), P7-2 (reassign gap), P7-3 (job titles; **user confirmation**), P7-4 … P7-19. Spec errata to record, not to edit the spec silently: §6 now lists 10 finance processes, not 8; `finance.period_reopen` is decided with `approve it-finance` (P4-6 / L12); §14 `finance-budget.spec.js` is delivered in P7 (QA).

---

## 3. Work packages (disjoint file sets; run A–E in parallel, then QA)

Common rules for every fixer: sonnet, own DB, `--filter` only on the listed classes, pint only on touched files. Do **not** edit `RouteSmokeTest.php`, `DatabaseSeeder.php`, `config/finance.php` (WP-A only) or another WP's files. New tests go in one new class per WP under `tests/Feature/Finance/`.

### WP-A — Budgets, approvals wrapper, settings masking (DB `it_security_risk_fin_wpa`)
**Files:** `app/Services/Finance/FinanceApprovals.php`, `BudgetTransferService.php`, `BudgetPhasing.php`, `BudgetWorkbook.php`, `FinanceApprovalSweeper.php`, `DoaBandService.php`, `BudgetService.php` (saveLine only), `BudgetCycleService.php` (targetUsage scope if needed); `app/Http/Controllers/Finance/BudgetTargetController.php`, `BudgetTransferController.php`, `SupplementaryRequestController.php`, `FinanceSettingsController.php`, `Concerns/PresentsBudgets.php` (options only); `config/finance.php`; `database/seeders/FinanceDemoSeeder.php`; `resources/js/Pages/Finance/Settings.jsx` and its tab components (render nulls as "—").
**Changes:**
1. P5-M12 / P7-3: add `FinanceApprovals::jobTitleMatches(string $title, string $ref): bool` (segment-exact) and `assertEligible($process, $subject, $actor)`. When the current step is `job_title`, refuse a non-matching actor before `engine->gate()` in `approve()` and `decline()`; use the same predicate in `assertNoStageStranded` (replace `engine->matches` for job-title steps) and in `isCurrentOwnerApprover` if relevant. `FinanceDemoSeeder`: set `headofit@` `job_title` = "Head of IT" (both banks).
2. P6-sec-L6: in `trail()`, null `conditions.requires_cfo` / `requires_board` and `context.requires_cfo` / `requires_board` when the viewer cannot see amounts.
3. P1-sec-M3: targets index filters rows to visible cost centres (a target with null `cost_centre_id` is visible only to bank-wide viewers); the `costCentres` prop uses `visibleCostCentres`. Transfer and supplementary `summary` are computed from the gated `$rows`, not `$all`.
4. P1-M6 / P7-4: `BudgetTransferService::validate/submit/apply` refuses when both lines have `legal_entity_id` and they differ (field `to_line_id`).
5. P1-L2: `spread()` — when the running total exceeds `$total`, cap each share at the remaining amount (never negative for non-negative totals); keep exact sum.
6. P1-L3: `BudgetWorkbook` export guard applies to every non-numeric cell and to `\t` / `\r`. Import strips a leading `'` only before those characters.
7. P1-L5: wrap the per-step body in `FinanceApprovalSweeper::sweep` and the per-version `activate()` in `DoaBandService::activateDue` in `try { } catch (Throwable $e) { report($e); }`.
8. P1-L1: add `fin_budget_targets`, `fin_investments` and `fin_business_cases` to `money_tables`. Update the guard message.
9. P1-M5b: `ilike` → `whereRaw('lower(description) like ?', ['%'.mb_strtolower($needle).'%'])`.
10. P5-sec-L2 / P7-15: `PresentsBudgets::options` returns `risks` only if `can('view risks')`, and `eaApplications` / `businessServices` only if `can('view ea')` (check the exact permission names in `RolesAndPermissionsSeeder`).
11. P5-sec-L3: `saveLine` refuses an `investment_id` whose `cost_centre_id` is outside the editor's visible cost centres (unless bank-wide).
12. P0-S9: in `FinanceSettingsController::show`, for users without `view it-finance-amounts`, null DoA band min/max, `renewal_cfo_from`, `invoice_sod_from`, `capitalisation_*_from`, `business_case_board_from` and the FinOps money thresholds (keep the keys; null the values). The edit actions already need `manage it-finance-settings`.
**Tests:** `P7BudgetAndApprovalHardeningTest` (one test per item above). Extend nothing else.
**Seeder impact:** HIGH for item 1. Every seeded pending finance step with a `job_title` stage must still have a matching decider: CFO "Chief Financial Officer", CIO "Chief Information Officer", MD "Managing Director/CEO" → segment match, Head of IT → renamed headofit@. Item 4: check that no seeded FBN transfer crosses legal entities. If one does, change its to-line in `FinanceDemoSeeder` (same CC family).
**Run:** `--filter 'P7BudgetAndApprovalHardeningTest|BudgetTransferTest|BudgetLifecycleTest|BudgetWorkbookTest|BudgetDomainReviewRegressionTest|FinanceDoaFxAndCurrencyGuardTest|FinanceConfigurationTest|FinanceWorkflowStudioTest|ContractWorkflowIntegrationTest|ExpenseWorkflowIntegrationTest|MonitoringWorkflowIntegrationTest|InvestmentWorkflowIntegrationTest|FinOpsWorkflowIntegrationTest|ContractP21RegressionTest|WorkflowStudioModuleTest'`, then the orchestrator runs seed + SMOKE (the "nobody can decide" assertions).

### WP-B — Spend, ledger, monitoring and period close (DB `it_security_risk_fin_wpb`)
**Files:** `app/Services/Finance/ProcurementService.php`, `InvoiceService.php`, `RecurringExpenseService.php`, `AccrualService.php`, `ReconciliationService.php`, `ActualsService.php`, `FinanceTransactionImporter.php`, `BudgetAvailability.php`, `MonitoringService.php`, `PeriodCloseService.php`, `Reports/Definitions/CommitmentsReport.php`; `database/seeders/FinanceExpenseDemoSeeder.php`, `FinanceMonitoringDemoSeeder.php`.
**Changes:**
1. P3-sec-L1: `inBank(Category|Asset)` for every PO line and invoice line `category_id` / `asset_id`.
2. P3-sec-L2: `RecurringExpenseService::save` refuses a `cost_centre_id` outside `FinancePolicy::visibleCostCentreIds($by)` (null = bank-wide; Procurement Officer keeps its bank-wide behaviour as in the index).
3. P3-M10 / P7-8: `schedulePrepayment` resolves the line per release FY via the successor baseline line with the same `line_key`; else null.
4. P3-M11 / L15 / P7-9: `duplicateOf($orgId, $ref, $baseAmount, $postedOn, $vendorId)`, plus an in-file seen-set in the GL import; `possible_duplicate` marking.
5. P3-M12 / P7-10: in `InvoiceService` (direct path) refuse when Σ non-cancelled invoices of the request plus this one exceed `total_amount × (1 + tolerance)`.
6. P3-L14: in `generateFor`, on `ValidationException` do **not** advance `next_due_on`; write an `it-finance` audit row `generation_failed` with the reason, then `break`. Next run retries.
7. P4-M6 / P7-7: accrual `base_amount` via `CurrencyService::convert(..., $period->ends_on, 'closing')`, falling back to `'spot'`.
8. P4-M3: add `BudgetAvailability::commitmentItems(array $lineIds): list<{type,code,title,line_id,remaining_base,href}>` from the same rules as `committedMap` (incl. accrual relief and proposed prepayment releases); `remainingForPo` subtracts posted accruals. The BvA drill and `CommitmentsReport` consume it.
9. P4-M1: BvA severity is classified on budget **through the last ended period** in range (actuals likewise); future periods show amounts with `severity = null`.
10. P4-M2 / P7-5: checklist `variances_explained` fails while any red note is `open` or `explained`; message "N red variance(s) await acknowledgement".
11. P6-M7 / sec-M2 / M3 / P7-6: `requestReopen` refuses when `ChargebackRun` for the period has `status != 'voided'`. On final approval, set `is_frozen = false` on `fin_allocation_results` of the period. Approved capitalisations with consumed actuals in the period are listed in the request context as a warning.
**Tests:** `P7SpendLedgerAndCloseHardeningTest`.
**Seeder impact:** HIGH. `FinanceExpenseDemoSeeder` swallows exceptions (`attempt()` warns; the `catch (Throwable)` at `:245` and `:260` are silent). Make those two warn too. Item 10: `FinanceMonitoringDemoSeeder` must acknowledge (as a different user from the author) every red note on periods it closes, inside `whilePeriodsOpen()`. Item 5: check that seeded direct invoices stay under their request. Item 11: FBN's pending September re-open must not have an exported chargeback (the P6 seeder exports one; check its period).
**Run:** `--filter 'P7SpendLedgerAndCloseHardeningTest|ExpenseApprovalAndAvailabilityTest|ProcurementThreeWayMatchTest|ActualsImportAndReconciliationTest|ExpenseReviewRegressionTest|ExpenseServicesSmokeTest|ExpenseBackendSmokeTest|MonitoringAndVarianceTest|MonitoringReviewRegressionTest|PeriodCloseTest|FinanceReportsTest|ShowbackAndAllocationTest|FinanceP4CarryOverTest'`.

### WP-C — Investments and value (DB `it_security_risk_fin_wpc`)
**Files:** `app/Services/Finance/StageGateService.php`, `InvestmentFinancials.php`, `InvestmentScoring.php`, `BenefitsService.php`, `BusinessCaseService.php`; `app/Http/Requests/Finance/BusinessCaseFormRequest.php`; `database/seeders/FinanceInvestmentDemoSeeder.php`.
**Changes:**
1. P5-M4 / P7-11: `funded` compares Σ `current_base_amount` of tagged baseline lines with BAC (or the draft BC one-off cost); the detail shows the gap.
2. P5-M6: in `approve()`, before finalising, re-run `criteria()` for the gate's **auto** criteria (with the stored payload) and refuse when any is now unmet.
3. P5-L9: delivered payload reads the criterion keyed `actual_end`, not `[0]`.
4. P5-M7 / P7-14: `record()` refuses `closed` investments; `update()` refuses changes to `target_value`, `baseline_value`, `unit` and `due_on` once the investment has passed `approved` (P5-sec-M4).
5. P5-M8 / P7-12: EVM rules.
6. P5-L4: `actualsQuery` → `investment_id = X OR (investment_id IS NULL AND budget_line_id IN tagged)`.
7. P5-M14 / P7-13: `score()` refuses the sponsor and the manager.
8. P5-sec-L5: `costs.*.capex|opex|run` and `benefits.*.amount` get `nullable, numeric, between:-1e13,1e13`; `year` gets `max:30`.
9. P5-sec-L6: `markRejected` / `markWithdrawn` take `User $by` and pass it to `audit()` (update the callers in `StageGateService`).
**Tests:** `P7InvestmentHardeningTest`.
**Seeder impact:** MEDIUM. Item 1: seeded investments past `in_delivery` were advanced with one tagged line, so the seeder must tag lines whose amounts cover BAC before advancing. Item 7: scores must be entered by a non-sponsor, non-manager user (e.g. `steerco@`). Item 2: the pending CFO-stage gates (KHB-IVT-0004, FBN-IVT-0007) must still meet their auto criteria at decision time.
**Run:** `--filter 'P7InvestmentHardeningTest|InvestmentAndBusinessCaseTest|BenefitsAndValueReviewTest|InvestmentWorkflowIntegrationTest|InvestmentBackendSmokeTest|FinanceP5CarryOverTest|BusinessCaseCalculatorTest'`.

### WP-D — FinOps, allocation, capitalisation, KRIs (DB `it_security_risk_fin_wpd`)
**Files:** `app/Services/Finance/CloudCostService.php`, `CloudAnomalySweeper.php`, `CloudAnomalyService.php`, `AllocationService.php`, `ApplicationTcoService.php`, `AmortisationSchedule.php`, `CapitalisationService.php`, `ChargebackService.php`, `FinanceKriSource.php`, `FinanceSettings.php`, `FinanceMetrics.php`; `app/Services/Kri/KriMeasures.php`; `app/Models/Finance/Capitalisation.php`; `app/Http/Controllers/Finance/CloudTagRuleController.php`, `CloudAccountController.php`, `CloudAnomalyController.php`, `CloudImportController.php`, `CloudCostController.php`, `CapitalisationController.php`, `ChargebackController.php`, `TcoController.php`; `resources/js/Pages/Finance/Monitor/Tco.jsx`, `Capitalisations/Index.jsx`; `database/seeders/FinanceFinOpsDemoSeeder.php`; `tests/Feature/Finance/ShowbackAndAllocationTest.php` (the M1 test only).
**Changes:**
1. P6-M6: `AllocationService` validates only dates that change. Ending a rule needs `ends_on >= floor`.
2. P6-M8 / sec-M6: before a restatement delete, capture manual attributions keyed on (date, service, resource, usage type) and re-apply them to the inserted lines. Scope the delete to rows of the same source/provider export. Audit `restated` with before/after line counts and base totals.
3. P6-M9: `KRI-FIN-CLD` counts distinct (account, `detected_on`) events.
4. P6-M11 / sec-M1 / L3 / L9 / L15: tag-rule create/update/delete and account create need bank-wide visibility or `manage it-finance-settings`. Anomaly acknowledge/dismiss/resolve refuse anomalies outside the viewer's scope (same predicate as the index, with an org filter in the subquery). Import batch detail is filtered to visible cost centres. Dashboard `cloud_anomalies_open` uses the same scope.
5. P6-follow-up / sec-M5: the sweeper notifies every open anomaly not yet claimed (`claim()` idempotency), with a try/catch per account.
6. P6-L2: straight-line charge capped at `room`.
7. P6-M15 / sec-M4 / M16 / sec-L4: remove amounts from `CapitalisationService` activity descriptions and error texts, and from `blocked_reason` (move them to `properties`). Submit and decide need `view it-finance-amounts`. `sodExcludedIds` includes the source investment's sponsor and is recomputed when a `reassign` change applies.
8. P7-17 TCO presentation: return `unattributed` (≥ 0) and `finops_excess`; render both on `Monitor/Tco.jsx`.
9. P6-L8 / P7-18: `FinanceKriSource::ensureDefinitions(int $orgId)` (definitions moved from the two seeders, CFO-owned when a CFO exists), called from `FinanceSettings::ensureDefaults`.
10. P6-L10: audit `downloaded` on chargeback download (causer, run_code, sha256).
11. P6-sec-M10: paginate the capitalisation index (`paginate(25)`); status counts by SQL `groupBy`.
12. P6-M1: rewrite the showback reconciliation test so it compares statement totals with an independent `fin_actuals` + `fin_cloud_cost_lines` sum for a closed period.
13. P3 seeder drift: replace the literal `2026-` dates in `FinanceFinOpsDemoSeeder` with dates relative to the seeded FY.
**Tests:** `P7FinOpsHardeningTest` (+ the M1 rewrite in `ShowbackAndAllocationTest`).
**Seeder impact:** MEDIUM. Item 7: seeded capitalisation approvers must hold amounts (all finance roles do; check `headofit@` / `md@` if they are stage deciders). Item 9: demo seeders keep `updateOrCreate`, so no duplicates. Item 2: re-seeding restatements in `FinanceFinOpsDemoSeeder`.
**Run:** `--filter 'P7FinOpsHardeningTest|CloudCostTest|ShowbackAndAllocationTest|CapitalisationTest|ApplicationTcoTest|FinOpsBackendSmokeTest|FinOpsWorkflowIntegrationTest|P6ReviewHighFixesTest|AmortisationScheduleTest|CloudAnomalyEvaluateTest|CloudExportParserTest|MonitoringWorkflowIntegrationTest'`.

### WP-E — Masking of activity rows and dashboard (DB `it_security_risk_fin_wpe`)
**Files:** `app/Http/Controllers/Finance/BudgetController.php` (`activity()`), `Concerns/PresentsExpenses.php` (`activityRows`), `Concerns/PresentsInvestments.php` (`activityRows`), `Concerns/PresentsContracts.php` (timeline/activity rows), `Concerns/PresentsMonitoring.php`; the React activity list components under `resources/js/Components/Finance/**` that render `changes` (null-safe).
**Changes:** (1) P6-sec-M9: add `bac` (and `ac`, `ev`, `cv`, `vac`) to `MON_MONEY_KEYS`. (2) P7-16: every activity row builder takes the viewer and returns `changes = null` when the viewer cannot see amounts (Budget, Expense, Invoice, PO, Investment, Contract). Capitalisation rows are WP-D's, in `CapitalisationController`.
**Tests:** `P7MaskingHardeningTest`: a user with `view it-finance` only gets `changes === null` on each show page's activity, and `investments_by_stage.*.bac === null` on the dashboard.
**Seeder impact:** none.
**Run:** `--filter 'P7MaskingHardeningTest|FinancePermissionsTest|BudgetBackendSmokeTest|ContractReviewRegressionTest|MonitoringBackendSmokeTest|InvestmentBackendSmokeTest|ExpenseBackendSmokeTest'`.

### QA (after A–E merge; DB `it_security_risk_fin_qa`)
`FinanceNotificationsTest` (P1-M8, FIN-07: every class in `app/Notifications/Finance` (16) renders `toMail` + `toArray`, carries no amounts for a no-amounts recipient, and `FinanceApprovalRequested` shows the amount only with the permission). Then the checklist §4 items H1–H8, the Playwright `finance-budget.spec.js`, and the full gate.

---

## 4. Hardening checklist (verifiable, no features)

| # | check | how it's verified | owner |
|---|---|---|---|
| H1 | **Route permission sweep.** 281 `finance.*` routes. All carry `permission:view it-finance` except signed `finance.calendar.ics`. Every non-GET carries a second permission, except these 11 gated in the service: `benefits.readings.store`, `business-cases.evaluate`, `calendar.feed.rotate`, `expenses.approve/reject/return`, `investments.score`, `invoices.approve/reject/return`, `value-reviews.complete`. | New `FinanceRoutePermissionSweepTest` with a `GATED_IN_SERVICE` list (EA pattern); for each of the 11, a test showing a user with only `view it-finance` and no stake gets 403/422. | QA |
| H2 | **Amount masking sweep.** A view-only user (no `view it-finance-amounts`) on every parameterless finance GET plus one seeded show page per model: recursively assert money keys are null and that no string prop matches `/(₦|NGN|USD|GBP)\s?[\d,]{4,}/`. Also: KRI pages for `config('finance.masked_kri_codes')`; board pack `it_financial_position` / `it_investment_portfolio`; report exports, calendar CSV/ICS, chargeback CSV (refused); notifications (via H-QA test). | New SMOKE-gated `RouteSmokeTest::test_finance_amounts_masked_for_view_only_user` (QA owns `RouteSmokeTest`). | QA |
| H3 | **N+1 / performance** on Budgets/Index, Transfers, Supplementary, BvA (fy, 10 groupings), Dashboard, Contracts/Index, Invoices/Index, Investments/Index, Capitalisations/Index, Settings. | `DB::enableQueryLog` in SMOKE on the seeded estate: record the counts in ATH-FIN-003; fail above 60 queries per page. Known offender: `lineOptions` per-line `currentByPeriod` (Transfers, Supplementary). Fix only offenders, as a post-merge orchestrator fix. | QA → orchestrator |
| H4 | **Audit completeness (FIN-07).** create/update/approve on each finance model writes an `it-finance` activity row with a non-null causer (system sweeps excepted: auto-renew, anomaly detected, DoA activated). | New `FinanceAuditTrailTest` (one data-provider row per process subject). | QA |
| H5 | **RouteSmokeTest coverage.** Every one of the 69 finance GET routes is hit by at least one finance smoke test. | Add a visited-route recorder plus a final assertion (allow-list the ICS and JSON-only routes). | QA |
| H6 | **Playwright ×3 viewports.** `finance-contract-renewal`, `finance-calendar`, and new `finance-budget.spec.js` (spec §14: create a draft budget → add a line → submit → approve as CFO → baseline; creates its own subject). | `npx playwright test tests/Playwright/specs/finance-*.spec.js` on desktop, tablet and mobile, against the `php -S` server on scratch (handover recipe). | QA |
| H7 | **Scheduler.** 11 `fin:*` schedules, all `withoutOverlapping`, the Lagos ones with a timezone; each command runs clean once on scratch with `--date`. | `php artisan schedule:list \| grep finance`; run each. | QA |
| H8 | **Driver-agnostic and seed.** No `ilike`, raw `ALTER` or `SHOW` in finance migrations/seeders (grep); `migrate:fresh --seed` on scratch prints **no** "finance … seed skipped" warning. | grep plus a seed-log check. | QA |
| H9 | **FIN-xx M items → tests.** A table mapping each §12 clause to a passing test; list the S items deferred. | In ATH-FIN-003 §P7. | QA |
| H10 | Gate: full suite (background, ~30 min) on `it_security_risk_test`; seed + SMOKE; `npm run build`; pint on touched files; `InertiaTransformChainTest`. | — | orchestrator |

---

## 5. Hand-off documents

1. **ATH-FIN-003 §P7**:
   - Shipped (the WP fix list with IDs) and the decisions table P7-1…P7-19.
   - Deferred and backlog (§1 BL rows grouped by theme) and known gaps: credit notes, tax rates unverified, P4 L1–L14 un-itemised, the platform `ApprovalEngine` job-title defect, the C35 listener defect.
   - Spec errata (10 processes; reopen permission; §14 Playwright names).
   - H3 query counts, the H9 M-item map and the review sign-offs (security + domain, Opus, full module).
   - Gate figures.
   - An **"Administrator notes"** sub-section: roles and permission matrix; the 10 Workflow Studio processes and their default stages; the job-title matching rule (P7-3); how to add an emergency stage (P7-1); the per-tenant thresholds in Settings; the `config/finance.php` keys that are still global (P1-M5a); the scheduler entries and times; KRI provisioning (P7-18); amount masking (`view it-finance-amounts`); period close and re-open rules (P7-5, P7-6); tax-counsel caveat.
2. **README.md** module table: replace row 36 ("Specified") with "IT Financial Management — Built & validated — ATH-FIN-003".
3. **docs/VALIDATION_HANDOFF.md**: module entry, demo logins and what to look at per bank.
4. **CLAUDE.md "Modules"**: two lines, roughly: "IT Financial Management: `app/{Models,Services,Http/Controllers}/Finance`, routes `/it-finance` `finance.*`, single `FinancePolicy`, approvals only through Workflow Studio via `FinanceApprovals` (SoD snapshot, segment-exact job titles), amounts masked server-side without `view it-finance-amounts`, config `config/finance.php`. Seeders `Finance*DemoSeeder` (order matters; expense seeder swallows errors; monitoring uses `whilePeriodsOpen()`)." Editing CLAUDE.md is part of ATH-FIN-002 P7 step 4. The orchestrator does it.
5. `docs/build/ATH-FIN-HANDOVER.md` "Next session", the memory file, then commit, push and open the PR to main with the ATH-FIN-003 §P7 summary.

---

## 6. Risks and questions for the user

- **Q1 (P7-3):** should "Head of IT Operations" (itops@) remain able to decide "Head of IT" stages? The plan says no and renames headofit@ to "Head of IT". A real bank whose Head of IT has a longer title would need its Workflow Studio stage ref changed.
- **Q2:** will UAT re-seed after 31 Dec 2026? The seeders are anchored on `now()`. In Jan–Mar the "current FY" has few or no closed periods, and many P3/P4 demo records (Jan–Aug actuals, September GL batch, closed-period variances) would be thin or skipped. If yes, add a `FINANCE_DEMO_TODAY` anchor in a follow-up.
- **Q3:** tax rates (WHT 2024 / Nigeria Tax Act 2025) are still unverified (D6/C15). UAT needs tax-counsel sign-off or a disclaimer.
- **R1:** WP-A item 1 and WP-B item 10 change who can decide or close. SMOKE's "every pending step has a decider" check is the only reliable guard, so run seed + SMOKE straight after merging A and B, before the full suite.
- **R2:** `RouteSmokeTest` and `DatabaseSeeder` are QA/orchestrator-only, to avoid merge conflicts between fixers.
- **R3:** disk at 90 % (21 GB free). Drop the `_wpa`…`_wpe` DBs after merge.
