# IT Financial Management — session handover

Living note: overwrite the "Next session" block at every milestone. Spec: `docs/ATH-FIN-001_…v1.0.md`; orchestration: `docs/ATH-FIN-002_IT_Finance_Build_Orchestration_Plan.md`.

## State (2026-10-15, P0 done)

- P0 Foundation is committed on `feature/it-finance-p0`. See ATH-FIN-003 §P0 for what shipped, the review fixes and what's deferred.
- `.claude/agents/` holds the fin-* agents. If they don't show up as agent types in a session, run them as general-purpose agents told to "act as `.claude/agents/fin-X.md`" with that file's model. This works fine.
- **Suite speed:** the RolesAndPermissionsSeeder fix cut a seeded test from ~13 s to ~2 s. The full suite is ~11 min on Postgres, so run it in the background.
- **Disk:** the data volume is ~94 % full. A suite run once hit "No space left on device" (17 false EA errors), so free space before P1.
- **DBs:** the orchestrator uses `it_security_risk_test` for the suite and `it_security_risk_scratch` for seed and smoke. Agents use `it_security_risk_fin_test` with `--filter` only, so parallel runs never collide.
- **Verify seeded behaviour yourself.** A builder's "source-text" test missed a real SoD conflict.

## Token-economy rules for every phase

1. The orchestrator does not read the spec or the codebase itself. `fin-architect` reads them and writes the plan to `docs/build/ATH-FIN-PLAN-pN.md`. The orchestrator reads only that plan and the agents' reports.
2. Models: architect on **opus** (never downgrade). Builders and QA on **sonnet**. Reviewers on **sonnet with "be exhaustive"** for P0, P4, P5 and P6, and on **opus** for P1, P2, P3 and P7 (the plan requires reviewers on those).
3. Run the full gate **once** per phase, after QA, plus one re-run after fixes. Builders run only their own `--filter` classes.
4. No browser walks. Playwright runs only at P2 (calendar and renewal journeys) and P7.
5. Fix only Critical and High findings. Log Medium and Low findings in ATH-FIN-003.
6. At each milestone: commit, update the memory file `project_it_finance_build.md`, overwrite "Next session" below, then start a new session.

## Next session — paste this

```
P1 (Budgeting) of IT Financial Management. P0 is committed on feature/it-finance-p0; branch feature/it-finance-p1 from it.
Read docs/build/ATH-FIN-HANDOVER.md (state + token rules) and run the P1 kick-off prompt in docs/ATH-FIN-002 §4, with these overrides:
- Gate = Postgres: full suite in background on it_security_risk_test; seed + SMOKE on it_security_risk_scratch; agents use it_security_risk_fin_test with --filter only; npm run build; pint on touched files.
- P1 reviewers run on opus.
- The P1 plan must also cover the P1-deferred items in ATH-FIN-003 §P0: versioned DoA bands with effective_from + maker-checker + band snapshot on approval requests (C8); TBM cost_pool_id + tower_id on budget lines; FX rate_type + rate_date; base-currency change guard; per-bank DoA bands.
- Don't read the spec yourself; fin-architect plans to docs/build/ATH-FIN-PLAN-p1.md.
At the end: append Phase P1 to docs/ATH-FIN-003, commit on feature/it-finance-p1, update memory and this handover for P2.
```
