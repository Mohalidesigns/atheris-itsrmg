# ATH-CHG-002 — Change Management: Build Update and Validation

**Module:** Change Management (Change Enablement) — a new sidebar module, built to the contract in [ATH-CHG-001](ATH-CHG-001_Change_Management_Module_Specification_v1.0.md). It covers:
- Change Register (`changes.index`), raise/edit change (`changes.create`, `changes.edit`), change record (`changes.show`)
- My Approvals inbox (`changes.approvals`)
- CAB / ECAB boards and meetings (`changes.cab.*`)
- Change calendar and freeze windows (`changes.calendar`, `changes.freeze-windows.*`)
- Approval policy — stages and routing matrix (`changes.policy.*`)
- Standard change templates (`changes.templates.*`)
- CSV export (`changes.export`), dashboard pulse, hourly `changes:sweep`

**Standards followed:** ITIL 4 Change Enablement (normal / standard / emergency change types, CAB and ECAB, post-implementation review), ISO/IEC 20000-1:2018 §8.5.1 (change authority, risk assessment, scheduling, back-out), COBIT 2019 BAI06 (BAI06.01 impact and prioritisation, BAI06.02 emergency change with retrospective authorisation, BAI06.03 tracking, BAI06.04 closure and documentation). Segregation of duties is enforced in code, which is what a CBN IT examiner or an IIA-standard IS auditor looks for first.

**Tenants:** Kano Heritage Bank (org 2) and First Bank (org 3), 28 seeded changes each; a `Change Manager` role with demo user `changemanager@atheris.ng` (password `Password@123`, from `RoleDemoUsersSeeder`).

**Method:**
- Built from the spec, then exercised in the browser (Playwright smoke over all ten pages, zero console errors; screenshots under `storage/app/claude-sync/screens/`).
- Locked in by `tests/Feature/Changes/ChangeManagementModuleTest.php` (18 tests) and `ChangeTenancyAndRoutesTest.php` (3 tests): **21 passed, 816 assertions**.
- Definition of done from ATH-CHG-001 §12 ticked off below.

---

## 1. What was built

| Layer | Delivered |
|---|---|
| Schema | `2027_01_15_000001_create_change_management_tables.php` — `change_requests`, `change_approval_stages`, `change_approvals`, `change_cab_boards`, `change_cab_members`, `change_cab_meetings`, `change_cab_meeting_items`, `change_cab_votes`, `change_freeze_windows`, `change_standard_templates`, `change_tasks`, `change_events`; all tenant tables carry `organization_id`. `2027_01_15_000002_grant_change_management_permissions.php` creates `view/create/edit/delete/approve/export changes` and grants them per role, idempotently. |
| Models | `app/Models/Change/*` (12), strict `BelongsToOrganization` scope on every tenant table. |
| Services | `ChangeWorkflow` (state machine, every transition writes a `change_events` row), `ChangeApprovalRouter` (stage selection, approver resolution, SoD, quorum, `pendingFor`), `ChangeRiskScorer` (impact × likelihood with uplifts), `ChangeFreezeChecker`, `ChangeConflictDetector`, `ChangeMetrics` (register KPIs + dashboard pulse), `ChangePolicySeeder` (default stages, CAB and ECAB per bank). |
| Notifications | `ChangeApprovalRequested`, `ChangeDecision`, `ChangeScheduled`, `ChangeOutcome`, `ChangeApprovalOverdue` — all extend `BaseNotification`. |
| Controllers / routes | `app/Http/Controllers/Change/` — `ChangeRequestController`, `ChangeCabController`, `ChangePolicyController`; 37 `changes.*` routes under `permission:view changes`, with `create`, `edit`/owner, `approve` and `export` gates per the spec. The old `change-requests.index` stub now redirects to `changes.index`. |
| Scheduler | `changes:sweep` hourly (`routes/console.php`): reminds overdue approvers once per 24 h (`reminded_at`). |
| Frontend | `resources/js/Pages/Changes/` — Index, Create, Show, Approvals, Calendar, FreezeWindows, Policy, Templates, `Cab/Index`, `Cab/Meeting`; `Components/Changes/RiskMatrix.jsx`; `Utils/changes.js`; `StatusBadge` tones for the new statuses; sidebar group in `Config/navigation.js`; "Change pulse" panel on the executive dashboard (`DashboardController::changesPulse`). |
| Demo data | `ChangeManagementDemoSeeder` (after `IssuesDemoSeeder`): per-bank policy, boards, templates, freeze windows, and 28 changes spread across every status, including an unauthorised emergency change, a freeze-window override, a backed-out change awaiting PIR and overlapping core-banking changes for conflict detection. |

## 2. Controls that are enforced in code (what to show a prospect or an examiner)

| Control | Where | Test |
|---|---|---|
| Risk-based routing: stages apply by change type and minimum risk level; the matrix is per bank and editable | `ChangeApprovalRouter::route`, Policy page | `routing_matrix_by_type_and_risk_level` |
| Risk uplifts: outage → likelihood +1; critical asset or core-banking category → at least High; emergency never below Medium | `ChangeRiskScorer` | `risk_scoring_bands_and_automatic_uplifts` |
| Only the current stage is actionable; a rejection skips the remaining stages and returns the change to the requester | `ChangeWorkflow::decide` | `only_the_current_stage_is_actionable…` |
| Segregation of duties: requester, implementer and change owner cannot approve their own change (except the business-approval stage, which *is* the owner); the final `change_manager` stage always needs `approve changes`; PIR reviewer ≠ implementer | `ChangeApprovalRouter::canDecide`, `ChangeWorkflow::review` | `segregation_of_duties…`, `…needs_an_independent_pir` |
| CAB quorum: votes count toward the board's quorum, one reject blocks, the chair (or an `approve changes` holder) decides and the override is logged | `ChangeApprovalRouter::evaluateBoard` | `cab_votes_reach_quorum…` |
| Freeze windows block normal and standard changes unless a documented override is given (logged `freeze_override`, and the standard change loses its pre-approval); emergency changes pass when the window allows | `ChangeFreezeChecker`, `ChangeWorkflow::submit/schedule` | `freeze_window_refuses…` |
| Emergency change may start before approval only with `approve changes` and a reason; flagged as unauthorised if ECAB has not ratified within 24 h; PIR mandatory | `ChangeWorkflow::start`, `ChangeMetrics` | `emergency_change_may_start_before_approval…` |
| High/critical changes require a test plan before scheduling; conflict warnings on overlapping windows sharing an asset, service or critical category | `ChangeWorkflow::schedule`, `ChangeConflictDetector` | `a_test_plan_is_required…`, `conflict_detection…` |
| Back-out or failure always forces a PIR; a closed change is immutable | `ChangeWorkflow` | `backing_out_or_failing_forces_a_pir…` |
| Standard changes need an approved, in-date template; usage is counted | `ChangeWorkflow::submit` | `standard_changes_need_an_approved…` |
| Tenant isolation: every register, board, policy, template and freeze window is bank-only; another bank's records 404; export is tenant-scoped and needs `export changes` | `BelongsToOrganization`, controllers | `ChangeTenancyAndRoutesTest`, `csv_export…` |
| Every page is forbidden without `view changes` | route middleware | `every_changes_page_is_forbidden…` |

## 3. Fixes made during validation (13 Oct 2026)

| Finding | Fix |
|---|---|
| Register page loaded the full user list (`owners`) on every request although the page never used it | Prop removed from `ChangeRequestController::index`; it stays on Create/Edit where the owner picker needs it. |
| Approval policy: a `user`-type approver was entered as a raw numeric id and displayed as `User #5` | Policy page now receives the bank's users and shows a name picker; the stage table shows the name. Server-side check that the user belongs to the bank was already in place. |
| My Approvals: the "decided by me" history serialised the whole `ChangeRequest` model while the pending list used a flat shape | History now uses the same `{id, change_code, title, change_type, risk_level, status}` shape and carries `stage_key`. |
| `RouteSmokeTest` used `status()` on the test response | Changed to `getStatusCode()`. |
| Twelve tests failed on a fresh checkout with "Please provide a valid cache path" | Environment only — `storage/framework/{cache,views,sessions}` missing after restore. No code change; noted under environment gotchas in the handoff. |

## 4. Definition of done (ATH-CHG-001 §12)

| Check | Result |
|---|---|
| `php artisan test tests/Feature/Changes` | 21 passed, 816 assertions |
| Full PHP suite | 251 passed, 1 skipped, 2,198 assertions (13 Oct 2026, cloud mirror, PHP 8.4) |
| `php artisan migrate:fresh --seed` on scratch SQLite | green — 56 changes, both banks, `changemanager@atheris.ng` present |
| `npm run build` | green |
| Pint on touched files | pass |
| `SMOKE=1 … --filter RouteSmokeTest` | pass |
| README module table, `docs/VALIDATION_HANDOFF.md` | updated |

## 5. Open follow-ups (not blocking)

- Notifications record to the database channel; no email/portal delivery yet (same as Issues).
- CAB meeting minutes are structured per item but there is no PDF/board-pack export.
- No ITSM ticket link from a change (Issues has one; the same `ItsmConnector` could be reused).
- Approval SLAs use fixed hours per stage; no business-calendar awareness.
- Conflict detection warns but does not block; a bank-level "block on conflict" policy flag would be a small addition.
- Change templates are per bank; a platform-level library (Nigerian core-banking patches, CBN-mandated changes) could seed them.
