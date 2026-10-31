# IT Financial Management — session handover

Living note: overwrite the "Next session" block at every milestone. Spec: `docs/ATH-FIN-001_…v1.0.md`; orchestration: `docs/ATH-FIN-002_IT_Finance_Build_Orchestration_Plan.md`.

## State (2026-10-15, P1 done)

- P0 is on `feature/it-finance-p0` (f2a5dfa). P1 Budgeting is committed on `feature/it-finance-p1` (f30deaa), which was branched from P0.
- What shipped, the review fixes, decisions P1-1 to P1-6 and what's deferred are in ATH-FIN-003 §P1. Gate: 452 tests OK on Postgres, plus the smoke test, seed and build.
- **Before P3 (must land):** the domain review's Medium items M1–M4, listed in ATH-FIN-003 §P1. They cover the supplementary FX re-quote, the diff and totals using current amounts, and status transitions on a locked baseline. P3 availability builds on the current-budget figure.
- `.claude/agents/` holds the fin-* agents. In this session they were registered as agent types. If they aren't, run general-purpose agents told to "act as `.claude/agents/fin-X.md`".
- **DB collisions are the main time sink.** In P1, a reviewer ran tests on `it_security_risk_test` while the gate suite was running, which caused 42 false errors and a second 13-minute run. Tell **every** agent, reviewers included, which DB to use: `it_security_risk_fin_test` for builders, `it_security_risk_fin_qa` for QA, a throwaway `it_security_risk_<agent>_test` for reviewers. The orchestrator alone uses `it_security_risk_test` (suite) and `it_security_risk_scratch` (seed + SMOKE).
- `it_security_risk_fin_qa` was left behind by P1 QA. Reuse it, or drop it if disk is tight.
- **Suite:** about 13 min on Postgres, run in the background.
- **Disk:** the data volume is about 94 % full, with 14 GB free.
- **Verify seeded behaviour yourself.** In P1, a new approval stage, the CIO stage on transfers, left a demo transfer stuck, and only the SMOKE seeded-estate check caught it.

## Token-economy rules for every phase

1. The orchestrator does not read the spec or the codebase itself. `fin-architect` reads them and writes the plan to `docs/build/ATH-FIN-PLAN-pN.md`. The orchestrator reads only that plan and the agents' reports.
2. Models and effort are set in `.claude/agents/*.md`:
   - **Architect:** opus, effort high.
   - **Builders, QA and the integrator:** sonnet, effort medium.
   - **Reviewers:** sonnet, effort high by default. Pass `model: opus` only for a final/whole-module review or a high-risk phase (P7 used it). Don't spawn a cold agent for something a few tool calls settle; do it inline.
   - **Token budget:** every agent ends with one. It covers reading only the named sections, `--filter` tests only, no full suite, and reports of at most 300 words.
   - **Cost reference:** in P7 an Opus reviewer used about 230–310k tokens, and a Sonnet fixer about 35–180k.
3. Run the full gate **once** per phase, after QA, plus one re-run after fixes. Builders run only their own `--filter` classes.
4. No browser walks. Playwright runs only at P2 (calendar and renewal journeys) and P7.
5. Fix only Critical and High findings. Log Medium and Low findings in ATH-FIN-003.
6. At each milestone: commit, update the memory file `project_it_finance_build.md`, overwrite "Next session" below, then start a new session.

## State (2026-10-16, P2 done)

- P2 Contracts, renewals & calendar is committed on `feature/it-finance-p2` (branched from 723ffbd on P1). ATH-FIN-003 §P2 records what shipped, the review fixes, decisions P2-1 to P2-8 and what's deferred.
- **P2.1 before UAT** is done (ATH-FIN-003 §P2.1). It was:
  - Termination through a full approval workflow.
  - An alert for approved but unexecuted renewals.
  - Post-deadline termination of auto-renewing contracts.
  - The per-tenant CFO threshold.
  - Uplift computed excluding FX.
- **Playwright against a non-default DB:** `php artisan serve` drops `DB_*` env overrides and falls back to `.env`, which is the dev DB. Serve instead with `PHP_CLI_SERVER_WORKERS=4 DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync php -S 127.0.0.1:8000 -t public`. Kill the worker PIDs afterwards: `lsof -ti tcp:8000`. Don't run Playwright while the full suite is running, because 5 s waits time out under the load.
- **Playwright cache:** version 1.62 needs the symlinks `~/Library/Caches/ms-playwright/chromium_headless_shell-1234 -> -1248` and `ffmpeg-1011 -> ffmpeg-1009`.
- **Inertia 2.3:** `form.transform()` returns undefined, so never chain `.put()` or `.post()` onto it. `Pages/Ea/Surveys.jsx` was fixed in P2.1, and `InertiaTransformChainTest` guards against it.

## State (2026-10-17, P3 done)

- P3 Expense management is committed and pushed on `feature/it-finance-p3` (branched from P2.1 e1c6ca8). ATH-FIN-003 §P3 records what shipped, decisions P3-1 to P3-18, the review fixes and what's deferred.
- Gate: 624 tests green on Postgres (22 min), seed + SMOKE pass, build passes.
- **Logged for P3.1 / before UAT:** domain M7–M12 (accrual VAT and cut-off, period close vs pending accruals, prepayments into a planning FY, GL duplicate keying, direct-invoice cap) and security L1, L2, L4. L13 (emergency spend escalation) needs a decision.
- **Seeder gotcha:** `FinanceExpenseDemoSeeder` swallows exceptions in `attempt()`, `advance()` and `first()`. After any SoD change, a demo record can silently go missing. Only SMOKE catches it ("has a X invoice" / "nobody can decide"). Debug by logging the swallowed exceptions temporarily.
- **The full suite does not cover `finance.imports.show` with batch actuals.** SMOKE caught a missing import there.

## State (2026-10-18, P4 done)

- P4 Monitoring, variance, period close & reports is committed and pushed on `feature/it-finance-p4` (branched from P3 3add6af). ATH-FIN-003 §P4 has what shipped, decisions P4-1..P4-14 / C101..C125, review fixes and deferrals.
- Gate: 692 tests green on Postgres (22 min), seed + SMOKE (6, 233 routes) pass, build passes.
- **Logged for P4.1 / before UAT:** domain M1–M3, M5–M9 and security M2 (finance KRIs and stored board-pack content unmasked). Full list in ATH-FIN-003 §P4.
- **Period immutability:** variance notes on closed or locked periods are frozen (explain, acknowledge, comment, raise-issue and evaluate are refused). The monitoring seeder therefore wraps its writes in `whilePeriodsOpen()` and passes `force` to evaluate. New seeders must do the same.
- **Scheduled finance reports** run as the schedule's `created_by` and store no money in `summary`.
- `FinanceConfigurationTest` FX import test was time-of-day flaky; now pinned to midday.

## State (2026-10-18, P5 done)

- P5 Investments & value realisation is committed and pushed on `feature/it-finance-p5` (branched from P4 5ad7b14). ATH-FIN-003 §P5 has what shipped, decisions C126–C143, review fixes and deferrals.
- Gate: 763 passed / 7 skipped on Postgres (26 min), seed + SMOKE (7) pass, build passes.
- **Logged for P5.1 / before UAT:** domain M1–M14, L1–L9 and security M1, M2, M4–M7, L1–L11 (full list in ATH-FIN-003 §P5). Open gap: no investment-change path to reassign sponsor/manager/cost centre/rgt after approval (frozen).
- **Approval stages:** `finance.business_case_approval` = Head of IT → CFO → IT Steering Committee (role) → MD (job title). Never give md@ the steerco role; `assertNoStageStranded()` refuses a decision that strands a later stage. Each bank has a `steerco@` user.
- **SoD exclusions are snapshotted** on `fin_stage_gates.sod_excluded_ids` at request time; control fields are frozen after approval and while a gate/change is pending. Seeders must set sponsor/manager before requesting gates.
- **PIR independence:** the manager and the delivered-gate requester cannot complete a value review; seeders complete as the sponsor.
- **Forecast snapshots** are never deleted on re-close: read live ones with `whereNull('superseded_at')`.
- A running suite can be stopped with TaskStop on its background task (pkill is refused by auto mode).

## State (2026-10-19, P6 done)

- P6 FinOps, allocation/showback/chargeback, Application TCO, capitalisation and P5 carry-overs is committed and pushed on `feature/it-finance-p6` (branched from P5 7da2d7c). ATH-FIN-003 §P6 has what shipped, decisions P6-1..P6-22 / C145–C172, review fixes and deferrals. Plan: `docs/build/ATH-FIN-PLAN-p6.md`.
- Gate after fixes: 875 passed / 8 skipped on Postgres (29 min), seed + SMOKE (8, 4,226 assertions) pass, build passes, pint clean.
- **Logged for P6.1 / P7:** all domain and security Mediums and Lows except the ones fixed in the High pass (full list in ATH-FIN-003 §P6). Notably: `unattributed` TCO can go negative when FinOps cloud exceeds ledger cloud; the recoverable-VAT capitalisation setting has no UI; the anomaly sweeper only notifies anomalies it created itself.
- **Classification is refused for budget lines with actuals in closed or locked periods** (domain H1). Seeders must tag lines directly, before allocation rules and runs (see `FinanceFinOpsDemoSeeder`).
- **EA `CostModelService::tco()` is pure EA.** The finance-measured figure comes only from `tcoWithFinance()`, gated by `view it-finance` + amounts + cost-centre scope. `ApplicationTcoService` is a scoped singleton; never call finance per app in EA loops.
- **Chargeback create, download and void need `close it-finance-periods`** (only the IT Finance Controller holds it), and the exporter cannot void a run. The seeder voids as `cfo`.
- **Builders sharing a DB interfere:** the two P6 fixers both ran on `fin_test` / `fin_wf_test` at times. Give every concurrent agent its own DB, and tell it to use an explicit `DB_CONNECTION=pgsql DB_DATABASE=…` prefix on every command. One builder accidentally ran on in-memory SQLite without it.
- **Pint on a directory** reformats unrelated files. Run it only on the touched files.
- The P1 allocation tables were rebuilt by a guarded drop-and-recreate, because they were empty and unused (C149).

## State (2026-10-31, P7 done)

- P7 Hardening & hand-off is on `feature/it-finance-p7` (branched from P6 main 70b5e99; first P7 commit c7d0cc1). ATH-FIN-003 §P7 has what shipped (WP-A..E, QA, H3 query work, review fixes F1-F4), decisions P7-1..P7-23, the H3 query table, the H9 requirement-to-test table, the P4 L1-L18 list, the backlog, spec errata and the Administrator notes. Plan: `docs/build/ATH-FIN-PLAN-p7.md`; reviews: `docs/build/ATH-FIN-REV-p7.md`.
- Gate at c7d0cc1: 950 passed / 10 skipped on Postgres (32 min), seed + SMOKE 10/10, build, Playwright 11/11. The final gate after the review fixes is recorded in ATH-FIN-003 §P7.
- **Final reviews (Opus, whole module):** security NO-GO (C1, platform audit trail cross-tenant and unmasked) and domain CONDITIONAL GO (H1, H2, H3 = C1); all fixed. Audit trail is now `App\Models\Activity` with `organization_id`.
- **Job titles are matched segment-exact for finance stages** (P7-3). `headofit@` is titled "Head of IT"; `itops@` ("Head of IT Operations") cannot decide Head of IT stages. The platform `ApprovalEngine` still uses the substring rule for non-finance processes.
- **Query budget:** 60 queries per finance page is enforced in `RouteSmokeTest` (dashboard allow-listed at 80). `RequestMemo` is the per-request GET memo, cleared on terminate; keep keys user, organisation and cost-centre aware.
- **Scheduler:** 10 `fin:*` schedules plus the shared `reports:run-due`, all `withoutOverlapping`.
- **Open questions:** re-seeding after 31 Dec 2026 (add a `FINANCE_DEMO_TODAY` anchor if UAT re-seeds), and Nigeria WHT / VAT rates need tax-counsel sign-off or a disclaimer before UAT.
- **Seeder gotchas still apply:** the expense seeder reports swallowed errors, monitoring seeds inside `whilePeriodsOpen()`, FBN's FinOps seeder avoids the pending re-open period.

## Next session — paste this

```
IT Financial Management P7 is complete (feature/it-finance-p7, PR open to main). Read docs/build/ATH-FIN-HANDOVER.md first.
Next: UAT support and backlog triage. Start from ATH-FIN-003 §P7 "Deferred / logged", the P4 L1-L18 list and the open questions (FINANCE_DEMO_TODAY, tax-counsel sign-off). Pick backlog items with the user before touching code; the first candidates are domain M1 (prepayment successor at post time), M6 (accrual FX rate type/date), FIN-07.06 audit-trail export and a PHPUnit test for invoice bulk import.
After merging the PR, run the gate once on main (suite on it_security_risk_test in the background, seed + SMOKE on it_security_risk_scratch, npm run build). Check disk first (df -h).
```
