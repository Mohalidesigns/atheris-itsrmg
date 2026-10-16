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
2. Models: architect on **opus** (never downgrade). Builders and QA on **sonnet**. Reviewers on **sonnet with "be exhaustive"** for P0, P4, P5 and P6, and on **opus** for P1, P2, P3 and P7 (the plan requires reviewers on those).
3. Run the full gate **once** per phase, after QA, plus one re-run after fixes. Builders run only their own `--filter` classes.
4. No browser walks. Playwright runs only at P2 (calendar and renewal journeys) and P7.
5. Fix only Critical and High findings. Log Medium and Low findings in ATH-FIN-003.
6. At each milestone: commit, update the memory file `project_it_finance_build.md`, overwrite "Next session" below, then start a new session.

## State (2026-10-16, P2 done)

- P2 Contracts, renewals & calendar is committed on `feature/it-finance-p2` (branched from 723ffbd on P1). ATH-FIN-003 §P2 records what shipped, the review fixes, decisions P2-1 to P2-8 and what's deferred.
- **P2.1 before UAT** (ATH-FIN-003 §P2 Deferred):
  - Termination through a full approval workflow.
  - An alert for approved but unexecuted renewals.
  - Post-deadline termination of auto-renewing contracts.
  - The per-tenant CFO threshold.
  - Uplift computed excluding FX.
- **Playwright against a non-default DB:** `php artisan serve` drops `DB_*` env overrides and falls back to `.env`, which is the dev DB. Serve instead with `PHP_CLI_SERVER_WORKERS=4 DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync php -S 127.0.0.1:8000 -t public`. Kill the worker PIDs afterwards: `lsof -ti tcp:8000`. Don't run Playwright while the full suite is running, because 5 s waits time out under the load.
- **Playwright cache:** version 1.62 needs the symlinks `~/Library/Caches/ms-playwright/chromium_headless_shell-1234 -> -1248` and `ffmpeg-1011 -> ffmpeg-1009`.
- **Inertia 2.3:** `form.transform()` returns undefined, so never chain `.put()` or `.post()` onto it. `Pages/Ea/Surveys.jsx` still does.

## Next session — paste this

```
Start P3 of IT Financial Management (see ATH-FIN-002 for the phase scope), branching feature/it-finance-p3 from feature/it-finance-p2.
Read docs/build/ATH-FIN-HANDOVER.md (token rules + P2 state) first. Don't read the spec yourself: dispatch fin-architect (opus) to write docs/build/ATH-FIN-PLAN-p3.md, and include the P2 deferrals that touch P3 (IFRS 16 lease indicator, domain L4; current-budget figure from P1 M3 is now in place).
Then: builders (sonnet) on their own DBs → QA on it_security_risk_fin_qa → gate (suite on it_security_risk_test, seed + SMOKE on it_security_risk_scratch, npm run build, Playwright served via php -S) → opus reviewers on throwaway DBs → fix Critical/High → re-gate → ATH-FIN-003 §P3, commit, memory, handover.
Check disk first (df -h).
```
