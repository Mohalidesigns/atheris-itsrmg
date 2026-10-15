# IT Financial Management — session handover

Living note: overwrite the "Next session" block at every milestone. Spec: `docs/ATH-FIN-001_…v1.0.md`; orchestration: `docs/ATH-FIN-002_IT_Finance_Build_Orchestration_Plan.md`.

## State (2026-10-15, preliminary done)

- `main` @ `f47f8c2`: CAPA module committed (`a017eff`), then the FIN spec, plan and `docs/agents/fin-*.md`.
- Branch `feature/it-finance-p0` is cut from `main` and clean. No finance code yet.
- Agents are installed in `.claude/agents/` (gitignored, so re-copy after editing `docs/agents/`). They load at session start, so P0 needs a fresh session.
- Agents patched: every gate command now uses Postgres `it_security_risk_test` for the suite and `it_security_risk_scratch` for seed and smoke. The original plan's `composer test` and bare `migrate:fresh --seed` would have used SQLite and wiped the dev DB.

## Token-economy rules for every phase

1. The orchestrator does not read the spec or the codebase itself. `fin-architect` reads them and writes the plan to `docs/build/ATH-FIN-PLAN-pN.md`. The orchestrator reads only that plan and the agents' reports.
2. Models: architect on **opus** (never downgrade). Builders and QA on **sonnet**. Reviewers on **sonnet with "be exhaustive"** for P0, P4, P5 and P6, and on **opus** for P1, P2, P3 and P7 (the plan requires reviewers on those).
3. Run the full gate **once** per phase, after QA, plus one re-run after fixes. Builders run only their own `--filter` classes.
4. No browser walks. Playwright runs only at P2 (calendar and renewal journeys) and P7.
5. Fix only Critical and High findings. Log Medium and Low findings in ATH-FIN-003.
6. At each milestone: commit, update the memory file `project_it_finance_build.md`, overwrite "Next session" below, then start a new session.

## Next session — paste this

```
P0 (Foundation) of IT Financial Management. Branch feature/it-finance-p0 is ready; agents are in .claude/agents.
Read docs/build/ATH-FIN-HANDOVER.md (token rules) and run the P0 kick-off prompt in docs/ATH-FIN-002 §4, with these overrides:
- Gate = Postgres: DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_test php vendor/bin/phpunit; seed and smoke on it_security_risk_scratch; npm run build; pint on touched files only.
- Reviewers for P0 run with model sonnet and the prompt "be exhaustive".
- Don't read the spec yourself. fin-architect plans, and you dispatch from docs/build/ATH-FIN-PLAN-p0.md.
At the end: append Phase P0 to docs/ATH-FIN-003, commit on feature/it-finance-p0, update memory and the Next-session block of this handover for P1.
```
