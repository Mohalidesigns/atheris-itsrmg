# ATH-VAL-020 — Integrated CAPA (IT-GOV-CAPA-001 v3.0)

Spec: [it_governance_multi_framework_capa_module.md](it_governance_multi_framework_capa_module.md). Props contract: [CAPA_PROPS_CONTRACT.md](CAPA_PROPS_CONTRACT.md).

## Design decision

CAPA is built on the existing Issues register (which already raised corrective actions from 13 finding sources, with SLA, escalation, ITSM sync and verification) rather than as a second register. An issue becomes a CAPA when a framework path is chosen (`issues.capa_framework`); issues without one keep the legacy workflow unchanged. Sidebar: **CAPA & Remediation › CAPA Register**.

## Spec coverage

| Spec | Implementation |
|---|---|
| §2 Framework choice | ISO/IEC 27001:2022 (nonconformity; cl. 10.1/10.2), COBIT 2019 (risk event / performance variance; APO12, MEA01), ITIL v4 (problem statement; Problem Mgmt, Continual Improvement) in `config/capa.php`. Classification and reference are filtered by path and lock after identification. |
| §3 CBN mapping | Four provisions (ITSB §3.5, §4.2, RBCSF §3.1.5, CPR §6.0), flagged "reference unverified" (config, not constants). At least one is required to leave identification; a CBN correlation is required to leave action planning and is frozen once the plan is approved. |
| §3.3 CPR auto-trigger | An incident saved with `customer_impact` opens an ITIL CAPA with CPR §6.0 pre-selected (critical if the incident is critical, else high), once per incident. Toggle: `CAPA_AUTO_TRIGGER_INCIDENT`. |
| §4 Six stages | `CapaWorkflow` stage gates: identification → containment (≥1 containment action) → RCA (≥3 whys or ≥1 fishbone cause, plus summary) → action plan (every action has owner + due date, CBN correlation, IT Governance sign-off) → implementation (all actions done/cancelled, ≥1 done) → verification → closed. Issue status follows the stage. |
| §4 Stage 3 RCA | 5 Whys chain and Ishikawa fishbone (People / Process / Technology / Governance), with the path's RCA focus prompt. The summary becomes the issue's root cause. |
| §4 Stage 5 Change routing | Corrective actions link to change requests in the bank's change register. |
| §4 Stage 6 Verification | 30–90-day settling period (default 30). Verifier must hold the Internal IT Auditor role and be independent: not the owner, the implementer or any action owner. All of the path's criteria must be met; ITIL also refuses if the problem recurred since implementation. A failed verification returns the CAPA to implementation. Closed CAPAs are not reopened: a recurrence is raised with `recurrence_of_id`. |
| §5 RACI | Matrix per spec in config, mapped to platform roles; shown per stage on the CAPA, with sign-off by the Accountable role. |
| §6 KPIs | Overdue action rate (target < 5%), 12-month recurrence rate (target 0%), CAPA MTTR (stage 1 → 5), verifications due / still settling — Register tiles and CSV register export (`export issues`, formula-injection safe). |
| §6 SLA & escalation | CAPAs run on their own windows from `config/capa.php` — critical 48 h, high 14 d, medium 30 d, low 90 d — independent of the bank's vulnerability SLA policy, which legacy issues still follow. Converting an issue re-bases its clock from when it was raised. Critical CAPAs notify CIO, CISO and the Risk Committee once, within 2 h (sweep in `escalateOverdue`). |

## Findings and fixes during validation

| # | Finding | Fix |
|---|---|---|
| 1 | "Raise issue" dialog 500 on Postgres (predates CAPA): source pick-lists selected `code`/`title` columns that `ccm_tenant_tests`, `kris`, `core_banking_integrations` and `access_review_campaigns` do not have | `Issue::SOURCES` declares real label/sort columns and eager loads; regression test iterates every source against the real schema |
| 2 | Raise-issue and identification forms never submitted (`form.transform(...).post` — `transform` is not chainable in this Inertia version) | Call `transform()` then `post()`/`put()` |
| 3 | CAPA page blanked after saving an RCA: the `rootCause` relation serialised over the `root_cause` text column (React error #31) | Relation renamed `capaRootCause`; Show test asserts `issue.root_cause` stays a string |
| 4 | ITSM ticket resolution marked a CAPA remediated, bypassing the gates | Ignored for CAPAs |
| 5 | Demo banks' 72 h critical policy would override the spec's 48 h for CAPAs | CAPA SLA windows separated from the shared vulnerability policy (decision recorded 2026-10-15) |
| 6 | CAPA header SLA chip counted "overdue" up to today on remediated CAPAs (the clock stops at remediation) | Chip shows the outcome from `slaState`: "SLA met" / "Remediated after SLA" |
| 7 | Demo seeder picked findings raised by seeders that ran after it (6 of 13 CAPAs seeded on a full `migrate:fresh --seed`) | `CapaDemoSeeder` runs last in `DatabaseSeeder` |
| 8 | Postgres-only flake in `ChangeManagementModuleTest::test_my_approvals_lists_only_actionable_approvals` (same-second `decided_at` tie) | `travel(1)->seconds()` in the test |

## Demo data

`CapaDemoSeeder` (after Change Management; tenant-guarded) moves twelve of Kano Heritage Bank's seeded issues onto the CAPA workflow — every stage, all three paths: one CAPA missing its CBN cross-reference (deliberate gap), a complete 5-whys and a draft fishbone, an action plan awaiting sign-off, implementation with actions linked to KHB-CHG-0006/0018/0024 (two overdue), one CAPA settling and one with verification due, two closed CAPAs verified by Internal Audit — one of which has recurred. Incident KHB-INC-010 (SIM-swap fraud, five customers) is flagged customer-impacting and opens its own CAPA through the real CPR §6.0 listener. Resulting KPIs: overdue rate 15.4% (red), recurrence 50% (red), MTTR 22.5 d.

## Tests

`tests/Feature/Issues/CapaWorkflowTest.php` (16 tests): each stage gate, RACI sign-off, independent verifier, settling-period refusal, failed-verification loop-back, ITIL recurrence refusal, auto-trigger idempotency, tenant isolation, KPI maths, CSV export, CAPA SLA windows vs bank policy, critical notification, and the Show props contract. `IssuesModuleTest` gains the source-columns regression test.

Definition of done (2026-10-15, PostgreSQL):
- Full PHP suite on an isolated test database: **364 tests, 4,303 assertions, OK** (1 skipped: the opt-in route smoke test). An earlier run on the shared `it_security_risk_test` failed five Workflow Studio tests because another session's suite was migrating the same database concurrently; they pass in isolation.
- `migrate:fresh --seed` on `it_security_risk_scratch`: clean; all 13 demo CAPAs present (12 seeded + 1 from the CPR §6.0 trigger).
- `npm run build`: passes.
- Seeded estate checked in the browser (scratch server): Register KPIs and stage counts match the seeded data; all 13 CAPA pages render without console errors.

Browser check (Kano admin, dev server): one CAPA raised and walked from identification through containment, RCA, action plan (with change link and sign-off) and implementation into verification; each gate showed its blocker until met; the verifier block correctly refused the implementer.

## Not done

- Passing verification and closing were not exercised in the browser (they need the 30-day settling period); they are covered by the feature tests.
- CBN provision references are unverified against the issued CBN texts (`verified => false`).
- The RACI "IT Infrastructure & Operations" role maps to Change Manager / Application Owner — the RBAC has no IT Operations role.
- First Bank (org 3) has no CAPA demo data; its register stays on the legacy workflow.
