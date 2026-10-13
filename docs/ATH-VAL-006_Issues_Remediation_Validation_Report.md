# ATH-VAL-006 — Issues & Remediation: End-to-End Validation Report

**Module:** Issues & Remediation (sidebar group 6 of the platform validation programme). It covers:
- Issues Console (`issues.index`, plus the new issue page `issues.show`)
- SLA Policies (`issues.sla-policies`)
- ITSM Integrations (`itsm.index`)

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- Every page, form and action was exercised in the in-app browser as the Kano CISO (Organization Admin). Verification was done as Kano's CRO (Risk Manager), and isolation checked as the First Bank admin.
- Controllers, models and seeders were traced, and findings were fixed and re-tested.
- Locked in by `tests/Feature/Issues/IssuesModuleTest.php` (12 tests, 172 assertions).

All three pages were read-only `PlatformController` stubs:
- The console was a kanban of every bank's issues, with no way to open, raise, assign or move one.
- SLA Policies dumped both banks' policy rows, with the escalation chain shown as raw JSON.
- ITSM Integrations was a hard-coded array: "Jira, connected, 128 tickets synced" for every bank, and a "Test connection" button with no handler.

They now live in `IssueController`, backed by three services in `app/Services/Issues/`:
- `IssueWorkflow`: lifecycle, SLA clock and escalation
- `ItsmConnector`: ticket push, connection test and the signed status webhook
- `IssueMetrics`: shared by the console and the executive dashboard

| Area | Before → after |
|---|---|
| Console | Cross-tenant: Kano saw "27 open", its 3 plus First Bank's 24. Cards were not clickable. Dates showed as `2026-08-20T00:00:00.000000Z`. "Escalated" issues were in no column. "Severity" sort was alphabetical → bank-only register, board and sortable list views, filters (status, past SLA, escalated, severity, source, owner, search), SLA state on every card, and a "Raise issue" form |
| Issue page | Did not exist → full record: lifecycle stepper, SLA clock, source link, edit, next-step actions, escalation, timeline with comments, ITSM ticket panel |
| SLA Policies | Cross-tenant, read-only, raw JSON. Nothing applied the policies to issues. Issue severity `moderate` had no matching policy (`medium`) → bank-only, editable hours and escalation chain, live open/past-SLA counts per severity, and the clock that drives every issue |
| ITSM | Hard-coded cards → per-bank connectors (Jira, ServiceNow, Freshservice) with encrypted credentials, live or sandbox mode, connection test, auto-push by severity, ticket push from an issue, and an HMAC-signed status webhook |
| Demo data | Random source ids (18 First Bank issues pointed at **Kano's** risks). Titles like "Physical: CCTV retention < 90 days (26)". Every Kano issue "raised" at seed time. Random SLA minutes. One event per issue → coherent per bank |

## 1. Security and tenancy

| # | Finding | Fix |
|---|---|---|
| S1 | `Issue` had only the `tenant_id` alias trait, with **no tenant scope**. Both banks saw all 115 issues (verified in the browser: Kano's console showed First Bank's issues). `Risk::issues()` relied on the same unscoped model. | `Issue` uses `BelongsToOrganization`. Issue routes bind through the scope: another bank's issue is a 404 (verified in the browser as First Bank). |
| S2 | `issuesSlaPolicies` read `vulnerability_sla_policies` with no `where`, so it listed both banks' rows. | Read and written per `organization_id`. |
| S3 | ITSM "connections" were a static array, identical for every bank. | `itsm_connections` table, strict scope. The API token and webhook secret use the `encrypted` cast and are hidden from serialisation. A blank token on edit keeps the stored one, and the page only ever receives `has_token` / `has_secret`. The secret is shown once on issue or rotation. |
| S4 | No write paths existed. The new ones validate pickers within the bank: the owner by a tenant-scoped `exists`, and the source record through the source model's own tenant scope. Another bank's risk or user is rejected. | See the tests `raising_an_issue…` and `itsm_push…`. |
| S5 | ITSM sat behind `view platform`, a permission that has nothing to do with issues. | All pages need `view issues` and raising needs `create issues`. Working an issue needs `edit issues`, **or being its owner**: owners are often IT staff with Viewer roles. Verifying, SLA policy changes and connector management need `approve issues`. |
| S6 | `AtherisPlatformSeeder`'s "already seeded" guard was a global `Issue::count() > 5`, so a second bank's issues suppressed the first bank's. | Scoped to the tenant (CLAUDE.md rule). |

## 2. Issue lifecycle

| # | Finding | Fix |
|---|---|---|
| L1 | The lifecycle (`open → in_progress → blocked → remediated → verified → closed`) existed only as an enum. Nothing could move an issue. | `IssueWorkflow::TRANSITIONS`: start, block, unblock, remediate, verify, reject (fails verification), close, reopen. Out-of-order steps are refused with a readable message. |
| L2 | `escalated` was a status, so an escalated issue left the board and lost its place in the lifecycle. | Escalation is a flag (`escalation_level`, `escalated_at`): an escalated issue is still being worked. The migration moves existing `escalated` rows to in-progress at level 1. |
| L3 | Nothing stopped an issue being closed without a fix. | **Remediating needs a root cause and a remediation plan.** **Verification must be by someone other than the remediator, holding `approve issues`** (blocked in the browser for the CISO who remediated; done by the CRO). A failed verification needs a reason and sends the issue back to in progress. Reopening a closed issue needs a reason and clears the remediation and verification. A closed issue is locked from editing. |
| L4 | No record of who did what. | `created_by_id`, `remediated_by_id`/`_at`, `verified_by_id`/`_at`, `closed_at`, and an `issue_events` timeline with a readable description per entry. It covers raise, assignment, status changes, edits, severity changes, escalations, tickets and comments. |
| L5 | No issue reference. | `issue_code`, continuing each bank's own series through `GeneratesSequentialCode` (`KHB-ISS-0026`, `FBN-ISS-0101`). |
| L6 | `source_type`/`source_id` were stored but never resolved or shown. Audit and NDPA findings have no record in the platform. | `Issue::SOURCES` maps each type to its model, code column and page: risk, incident, vulnerability, control (control test), compliance gap, ARB submission. A source panel links to the record. `source_reference` cites the report for audit and NDPA findings, and is required when no record is picked. The Risk page's Issues tab now links each issue. |

## 3. SLA clock and escalation

| # | Finding | Fix |
|---|---|---|
| C1 | `sla_minutes` was random (`rand(60, 43200)`) and nothing computed a due time. | On raise, `sla_due_at` = now + the bank's policy hours for the severity (issue `moderate` → policy `medium`). The target date defaults to the SLA date. |
| C2 | `sla_paused_minutes` existed but nothing paused the clock. | Blocking needs a reason and pauses the clock. Unblocking adds the blocked time to `sla_paused_minutes` and pushes `sla_due_at` out by the same amount. A blocked issue shows "Paused" and is never escalated. |
| C3 | A severity change left the SLA as it was. | It re-bases `sla_due_at` on the new policy, still counted from the raise time plus any paused time, and logs the change. |
| C4 | No escalation existed. The policy rows carried an `escalation_rules` chain that nothing read. | `issues:escalate` (hourly, in `routes/console.php`) escalates open and in-progress issues past their SLA: level 1 at breach, level 2 at 150% of the window. Each level notifies the users in the bank whose job title matches the chain step (CISO, CRO, Head of Compliance, Head of IT), via `IssueEscalatedNotification` (database and mail). Manual "Escalate to …" needs a reason. The sweep is idempotent. |
| C5 | SLA Policies could not be edited, and the page subtitle claimed a policy that the data did not enforce. | Inline edit of hours and chain (the owner is always first). Changes apply to issues raised afterwards and are written to the activity log. The page shows each severity's open and past-SLA counts, linking to the filtered console, and explains how the clock works. Policies are shared with the vulnerability prioritiser (module 5), so one change governs both. |

## 4. ITSM Integrations

| # | Finding | Fix |
|---|---|---|
| T1 | No connector model. | Add and edit connectors: name, tool, base URL (HTTPS), Jira project key, username and API token. Settings also cover mode, enabled or disabled, and auto-push severity. |
| T2 | "Test connection" did nothing (and the Integrations page's version returned a random 80% pass). | Live mode calls the tool (`/rest/api/2/myself`, `sys_user`, `agents/me`) and records the HTTP result; a failure sets the connector to `error`. Sandbox mode says plainly that no call is made. |
| T3 | Vulnerability tickets have no ITSM sync. Should Issues absorb them? | **Decision: link, not absorb.** A vulnerability ticket is the SOC's fix work order with its own lifecycle (module 5). An Issue is the CAPA record that auditors test. An issue raised from a vulnerability links to it, and ITSM sync happens at the Issue level, so one ticket per corrective action reaches the service desk. |
| T4 | Issues could not reach the service desk. | "Create ticket" on the issue page. Live mode posts a Jira issue, a ServiceNow incident (`correlation_id` = the issue code, urgency and impact from severity) or a Freshservice ticket. Sandbox mode records a reference in the tool's own format. A connector with an auto-push severity tickets every new issue at or above it. A failed push marks the connector in error and says why. An issue can be linked once. |
| T5 | No way back from the tool. | `POST /itsm/webhook/{connection}`, outside auth and CSRF, throttled, and signed like the SIEM webhook: `X-Atheris-Signature: sha256=HMAC(secret, "{timestamp}.{body}")`, with a 5-minute replay window. It accepts Jira, ServiceNow and Freshservice payload shapes or a flat `{ticket, status, comment}`, and mirrors the ticket status onto the issue timeline. **A resolved ticket marks an in-progress issue remediated, but only when its root cause and plan are recorded. Verification stays a human step in Atheris.** |
| T6 | No view of what had been ticketed. | Per-connector ticket and open counts, last sync, last test result, the webhook URL, and a "recently synced tickets" table. A banner counts open critical and high issues without a ticket. |

## 5. Dashboards

* **Issues Console KPIs:**
  * open (with critical count), past SLA, escalated and awaiting verification, each clickable to its filter;
  * fixed within SLA (share of issues remediated in the last 180 days before their due time);
  * mean time to remediate;
  * open issues ticketed.
* **Executive dashboard:** a new Issues & Remediation panel. It shows open, past SLA, escalated, critical open, awaiting verification and fixed-within-SLA, each linking to the filtered console. It also shows the open mix by source and the five most overdue issues with days over, escalation level and owner.
* Both read `IssueMetrics`, so they cannot disagree.

## 6. Demo data

**`IssuesDemoSeeder`** runs after `SecurityOperationsDemoSeeder`, so findings and SLA policies are final. It is deterministic (`mt_srand` per bank). Per bank:
- Every source resolves to a record of its type **in the same bank**. Audit and NDPA findings cite a report ("Internal Audit report IA-2026-07, finding 3", "NDPC compliance audit return 2025, observation 2").
- First Bank's numbered titles become titles from the source ("Remediate FBN-VLN-001: SolarWinds Web Help Desk RCE"). Placeholder descriptions and root causes are replaced.
- Raise dates spread by status. SLA due times come from the bank's policy. About four in five remediations land within the SLA. The verifier is never the remediator.
- Blocked issues have a reason and a paused clock. Overdue issues sit at the escalation level the sweep would have reached, with the escalation events in the timeline.
- Two open issues per bank are left unassigned (deliberate gaps).
- Every issue has a timeline (raise, assignment, start, block, remediate, verify, close, escalations, tickets, comments).
- Connectors:
  - Kano: IT Service Desk (Jira, auto-push high and above) and a disabled Freshservice.
  - First Bank: Group IT ServiceNow (auto-push critical) and Digital Channels Jira.
  - All sandbox, with secrets. Every auto-push-severity issue is ticketed, plus about 30% of the rest, with ticket status matching the issue.
- First Bank's high-severity chain stops at the CISO, to show bank-specific policy.
- Kano's register is topped up from 15 to the 25 the Kano seeder documents, from real findings.

## 7. Verification

* **Browser:**
  * As the Kano CISO: the console shows 15 open (was 27 with First Bank's), with SLA and escalation per card.
  * Raised KHB-ISS-0026 from risk KHB-RSK-016, critical. The SLA came out at 3 days, and the issue was auto-ticketed as KHB-118 in IT Service Desk (Jira).
  * Started it. "Mark remediated" was refused until the root cause and plan were recorded through Edit. Remediated it. Verification by the same CISO was not offered.
  * Webhook: a forged signature got `401`. A signed Jira "Done" with a comment was mirrored onto the ticket status and the timeline.
  * As the Kano CRO: verified (recorded as Fatima Bello), then closed. The executive dashboard panel updated (fixed within SLA 67% → 70%).
  * SLA Policies and ITSM pages render bank-only data.
  * As the First Bank admin: Kano's issue is a 404; the escalated list view shows First Bank's 21 escalated issues.
* **Fixed during the walk:**
  * The owner assignment was not logged when an issue was raised.
  * Sandbox ticket numbers were derived from a count, so an unlinked ticket could free its number for reuse; they now follow the highest number issued.
  * The seeder sent some critical First Bank issues to the non-auto-push connector, leaving them unticketed.
  * Issue references wrapped in the list view.
  * The remediation hint was duplicated under the error message.
* **Automated:**
  * `IssuesModuleTest` (12 tests, 172 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test
  * `npm run build`
* **Dev MySQL:**
  * The migration was applied and `IssuesDemoSeeder` run in place.
  * Re-running the seeder after the walk normalised the walk's issue (KHB-ISS-0026) like any other.

## 8. Not in this pass

* **Live ITSM:** demo connectors are sandbox. Live mode is implemented and tested with faked HTTP, but no real Jira or ServiceNow instance has been called. Push is outbound-on-demand plus auto-push on raise; there is no periodic reconciliation if a webhook is missed.
* **Evidence on verification:** the verifier records a note, but cannot attach evidence from the evidence vault yet.
* **Reminders before breach:** escalation starts at breach. There are no "due in 3 days" reminders to the owner.
* **Bulk actions:** no bulk assign, escalate or export from the console. No CSV export.
* **Other sources raising issues automatically:** only ARB conditions do (contract I-9). Failed CCM tests, gaps and incident lessons-learned do not yet raise issues on their own.
* **`DashboardController::boardPackExport`:** still hard-codes `organization_id => 1`. That belongs to the KRIs & Dashboards pass.
