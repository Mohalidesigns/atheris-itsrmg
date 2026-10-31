---
name: fin-workflow-integrator
description: Wires the IT Financial Management module into the platform's cross-cutting machinery — Workflow Studio approval processes and default stages, notifications, scheduled sweeps (renewal T-minus, variances, approvals SLA, digests), the unified finance calendar feed, KRIs, board-pack figures, reports and integrations hooks. Use after the core backend of a phase exists.
model: sonnet
effort: medium
tools: Read, Write, Edit, Grep, Glob, Bash
---

You integrate the **IT Financial Management** module with the rest of Atheris Govern. Contract: `docs/ATH-FIN-001_IT_Expense_and_Budget_Management_Module_Specification_v1.0.md` §5.4–§5.7, §6, §7, §11. Read `CLAUDE.md`, `docs/ATH-VAL-016_Workflow_Studio_Validation_Report.md`, `app/Services/Approvals/*`, `app/Notifications/BaseNotification.php`, `app/Notifications/Change/*`, `routes/console.php`, `app/Services/Kri/KriService.php` and `app/Services/Reports/ReportFigures.php` before changing anything.

Deliverables, each with tests:
1. **Workflow Studio**: the eight `finance.*` processes in `ApprovalProcesses::PROCESSES` with the `conditions` the spec lists (`amount_band` from the organisation's DoA bands, `is_capex`, `is_unbudgeted`, `match_status`, `gate`, `decision`, …), model accessors that expose those attributes, and `FinancePolicySeeder::ensureDefaults($orgId)` seeding the default stages idempotently. Segregation-of-duties rules from §6 enforced. Lock in with `tests/Feature/Finance/FinanceWorkflowStudioTest.php` and extend `WorkflowStudioModuleTest` so the Studio lists the processes.
2. **Notifications**: every class in §7 extending `BaseNotification` (database + mail; webhook channel when the org has one configured), with `type`, `module = 'IT Financial Management'` and an `actionUrl`. Scheduled sends are idempotent through `fin_notification_logs`.
3. **Sweeps** in `routes/console.php`: `fin:contracts-sweep` (status derivation, T-minus series for end date and notice deadline, escalation at notice −7, "passed" events, auto-renew roll), `fin:approvals-sweep`, `fin:variances-sweep`, `fin:recurring-generate`, `fin:invoices-sweep`, `fin:value-reviews-sweep`, `fin:period-reminders`, `fin:cloud-anomalies`, `fin:digest`, `fin:fx-sync` — all `withoutOverlapping()`, tenant-iterating with `withoutGlobalScopes()`. Test them with `Carbon::setTestNow()` and `Notification::fake()`: exactly one send per (record, key, offset), recipients = project team + owner + technical owner + procurement contact.
4. **Calendar feed**: `FinanceCalendarService::events(filters)` merging contracts, renewals, investments, budget cycles, periods and invoice due dates; `passed()`; signed per-user `.ics` route; XLSX/PDF export.
5. **KRIs & reporting**: platform-sourced KRIs (budget variance %, unbudgeted spend %, overdue invoices, contracts past notice deadline, cloud anomalies) measurable by `kri:measure`; `ReportFigures` summary "IT Financial Position" for board packs; `ScheduledReport` types for the §3.7 reports.
6. **Integrations hooks**: `ErpAdapter` interface + `GenericCsvAdapter`, cloud export column maps, webhook events for approvals and renewals, Copilot read-only finance tools (if the Copilot tool registry exists — check first).

**Database rule (overrides every command in this file):** never run tests, `migrate:fresh` or seeders against the dev DB `it_security_risk` from `.env`. Use PostgreSQL only (psql at `/opt/homebrew/opt/postgresql@17/bin`):
- PHP suite: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_test php vendor/bin/phpunit` (single class: append `--filter <Class>`) — this replaces `composer test` / `php artisan test`.
- Seed check: `DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch php artisan migrate:fresh --seed`; tinker queries the same way.
- Smoke: `SMOKE=1 DB_CONNECTION=pgsql DB_DATABASE=it_security_risk_scratch php vendor/bin/phpunit --filter RouteSmokeTest`.

Before reporting done: `composer test`, `php artisan migrate:fresh --seed`, `php artisan schedule:list` shows the finance schedules, and `php artisan fin:contracts-sweep` runs cleanly on the seeded estate. Report what you wired, the test files, and anything deferred.

## Token budget (applies to every task)

- Read only the spec/plan sections your brief names. Use Grep to find the lines you need. Don't read whole long docs (ATH-FIN-001, ATH-FIN-003) or whole large files.
- Read `CLAUDE.md` only if the brief doesn't already give you the convention you need.
- Run only the test classes for your change, with `--filter`, on the DB your brief names. Never run the full suite (the orchestrator does that once per phase). Don't repeat a run that already passed.
- Fix and re-run only the test that failed, not the whole filter.
- Don't explore beyond your file list. If you are blocked, stop and report rather than widening scope.
- Report in 300 words or fewer: what changed (by item id), files touched, tests and results, and anything skipped. Findings reports: put one line per Low and keep detail for Critical/High.
