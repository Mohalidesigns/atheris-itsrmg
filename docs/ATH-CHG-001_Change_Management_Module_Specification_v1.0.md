# ATH-CHG-001 — Change Management (Change Enablement) Module Specification v1.0

**Product:** Atheris ITSRM&G · **Module:** Change Management · **Status:** Build contract (backend, frontend, tests and demo data are built against this document)
**Date:** 13 Oct 2026 · **Owner:** Mohammed Ali (Atheris Limited)

---

## 1. Purpose and positioning

A bank-grade, ITIL 4–aligned change management module that every organisation on the platform can run out of the box: risk-based approval routing, a Change Advisory Board (CAB) and Emergency CAB (ECAB), freeze windows, standard (pre-approved) change templates, a forward schedule of change with conflict detection, post-implementation review (PIR) and a complete, tenant-scoped audit trail. It is a key selling feature: competitors (Jira Service Management, ServiceNow Change) do this generically; Atheris does it with the GRC context (linked risks, incidents, assets, controls, issues, EA impact) and Nigerian regulatory framing already in the platform.

### Standards the design follows

| Standard | What the module implements |
|---|---|
| ITIL 4 Change Enablement | Standard / Normal / Emergency change types; change authority by risk; CAB as *advisory*, Change Manager as *authority*; forward schedule of change; PIR |
| ISO/IEC 20000-1:2018 §8.5.1 | Change policy (what is a change, what needs CAB), assessment of risk/impact/service continuity, approval before deployment, review after |
| COBIT 2019 BAI06 (Managed IT Changes) | BAI06.01 evaluate/prioritise/authorise; BAI06.02 emergency changes with post-facto authorisation; BAI06.03 track and report status; BAI06.04 close and document |
| NIST SP 800-53 r5 CM-3 / CM-4 | Configuration change control, security impact analysis before approval, record of decisions |
| PCI DSS v4.0 req. 6.5.1 | Documented reason, impact, approval, testing, back-out for every change to in-scope systems |
| CBN Risk-Based Cybersecurity Framework / IT Standards Blueprint | Change control over critical systems, segregation of duties (requester ≠ approver ≠ independent reviewer), evidence retention for supervisory review |
| ISO/IEC 27001:2022 A.8.32 | Change management procedures for information processing facilities |

---

## 2. Domain model

All tables carry `organization_id` and all models use the strict `App\Models\Traits\BelongsToOrganization` scope (never `BelongsToTenant`). Models live in `app/Models/Change/`, services in `app/Services/Change/`, controllers in `app/Http/Controllers/Change/`, pages in `resources/js/Pages/Changes/`. Everything is driver-agnostic (SQLite in tests, MySQL in dev/prod).

### 2.1 `change_requests` — `App\Models\Change\ChangeRequest`

| column | type | notes |
|---|---|---|
| id, organization_id | | strict tenant scope |
| change_code | string(32) index | per-bank series via `GeneratesSequentialCode`, default prefix `CHG-` (`KHB-CHG-0001`, `FBN-CHG-0001`) |
| title | string | |
| description | text | what is changing |
| justification | text nullable | why (business/regulatory driver) |
| change_type | string(16) | `standard` · `normal` · `emergency` |
| category | string(32) | `application` · `infrastructure` · `network` · `security` · `database` · `core_banking` · `data` · `vendor` · `process` · `other` |
| priority | string(16) | `low` · `medium` · `high` · `critical` (urgency) |
| impact | tinyint 1–5 | 1 Insignificant … 5 Catastrophic |
| likelihood | tinyint 1–5 | likelihood of failure/disruption, 1 Rare … 5 Almost certain |
| risk_score | tinyint | impact × likelihood (1–25), computed by service |
| risk_level | string(16) | `low` (≤4) · `medium` (5–9) · `high` (10–15) · `critical` (≥16), computed |
| impact_assessment | text nullable | narrative; required before submit for normal/emergency |
| implementation_plan | text nullable | required before submit |
| backout_plan | text nullable | required before submit |
| test_plan | text nullable | required before approval for high/critical |
| communication_plan | text nullable | |
| outage_required | boolean default false | |
| expected_downtime_minutes | unsignedInteger nullable | |
| affected_asset_ids | json nullable | ids of `assets` in the same bank |
| affected_services | json nullable | free-text service names (e.g. "Internet banking", "Finacle CBS") |
| source_type | string(32) nullable | `risk` · `incident` · `vulnerability` · `issue` · `audit` · `regulatory` · `project` · `other` |
| source_id | unsignedBigInteger nullable | the in-bank record for risk/incident/vulnerability/issue |
| source_reference | string nullable | free-text citation when no record |
| standard_template_id | FK nullable → change_standard_templates | set when change_type = standard |
| requester_id | FK users | who raised it |
| change_owner_id | FK users nullable | accountable owner (business/application owner) |
| implementer_id | FK users nullable | who will implement |
| change_manager_id | FK users nullable | the Change Manager assigned (final authority) |
| cab_required | boolean default false | set by router |
| status | string(24) | see §3 |
| scheduled_start_at, scheduled_end_at | timestamp nullable | implementation window |
| actual_start_at, actual_end_at | timestamp nullable | |
| freeze_override_reason | text nullable | when scheduled inside a freeze window |
| submitted_at, approved_at, rejected_at, implemented_at, reviewed_at, closed_at, cancelled_at | timestamp nullable | |
| rejection_reason | text nullable | |
| approved_by_id | FK users nullable | the Change Manager who gave final authorisation |
| pir_required | boolean default false | true for high/critical/emergency and any failed change |
| pir_outcome | string(32) nullable | `successful` · `successful_with_issues` · `failed` · `backed_out` |
| pir_summary | text nullable | lessons learned |
| pir_by_id | FK users nullable | independent reviewer |
| closure_code | string(32) nullable | `implemented` · `backed_out` · `failed` · `cancelled` · `rejected` · `superseded` |
| created_by_id | FK users nullable | |
| timestamps, softDeletes | | |

Model: constants `TYPES`, `CATEGORIES`, `PRIORITIES`, `STATUSES`, `OPEN_STATUSES`, `RISK_LEVELS`, `SOURCES` (same shape as `Issue::SOURCES` with model/code/route for risk `risks.show`, incident `incidents.show`, vulnerability `vulnerabilities.show`, issue `issues.show`); relations `requester`, `owner`, `implementer`, `changeManager`, `approvedBy`, `pirBy`, `template`, `approvals()` (ordered by sequence, id), `tasks()`, `events()` (desc), `cabItems()`, `votes()`; helpers `generateNextCode($orgId)`, `isOpen()`, `sourceSummary()`, `affectedAssets()` (tenant-scoped `Asset::whereIn`), `scopeUpcoming($q)` (approved/scheduled with `scheduled_start_at` ≥ now), `scopeInWindow($q, $from, $to)`.

### 2.2 `change_approval_stages` — `App\Models\Change\ChangeApprovalStage` (per-bank approval policy)

| column | notes |
|---|---|
| organization_id, name, sequence (unsignedTinyInteger) | |
| key | string(32): `technical_review` · `security_review` · `business_approval` · `cab` · `ecab` · `change_manager` · custom |
| applies_to_types | json: subset of `['standard','normal','emergency']` |
| min_risk_level | string(16): `low` · `medium` · `high` · `critical` — stage applies when change risk ≥ this |
| approver_type | string(16): `role` · `job_title` · `user` · `board` |
| approver_ref | string: role name (e.g. `Change Manager`), job-title fragment (e.g. `CISO`), user id, or board id |
| quorum | unsignedTinyInteger nullable — for `board`: minimum approving voting members (default = board quorum) |
| sla_hours | unsignedInteger default 48 — decision due time |
| is_active | boolean default true |
| timestamps | |

Default policy seeded for every organisation (by `ChangePolicySeeder::ensureDefaults($orgId)`, idempotent, also called lazily by the service if an org has no stages):

| seq | key | name | types | min risk | approver | sla |
|---|---|---|---|---|---|---|
| 10 | technical_review | Technical / peer review | normal | medium | job_title `Head of IT` *(falls back to role `Organization Admin`)* | 24 |
| 20 | security_review | Information security review | normal | medium | job_title `CISO` | 48 |
| 30 | business_approval | Business owner approval | normal | high | user = change_owner_id (dynamic: approver_type `owner`) | 48 |
| 40 | cab | Change Advisory Board | normal | high | board = org's CAB | 72 |
| 50 | ecab | Emergency CAB | emergency | low | board = org's ECAB (quorum 2) | 4 |
| 60 | change_manager | Change Manager authorisation | normal, emergency | low | role `Change Manager` | 24 |

So: **standard** → no stages (pre-approved by template); **normal/low** → Change Manager only; **normal/medium** → technical + security + Change Manager; **normal/high & critical** → technical + security + business owner + CAB + Change Manager; **emergency** → ECAB + Change Manager (ECAB may be recorded retrospectively, see §3.3).

### 2.3 `change_approvals` — `App\Models\Change\ChangeApproval` (instances per change)

| column | notes |
|---|---|
| organization_id, change_request_id FK cascade | |
| stage_id FK nullable, stage_key, stage_name, sequence | snapshot of the stage at routing time |
| approver_type, approver_ref | snapshot |
| assignee_id FK users nullable | a concrete person when resolvable (owner / user / single role holder); null when any holder may decide |
| board_id FK nullable | for CAB/ECAB stages |
| status | `pending` · `approved` · `rejected` · `skipped` · `expired` |
| decided_by_id, decided_at, decision_note | |
| due_at | created_at + stage sla_hours |
| timestamps | |

Rule: approvals are **sequential** by `sequence`: only the lowest-sequence pending approval is actionable ("current stage"). A rejection at any stage sets the change to `rejected` and marks later pending approvals `skipped`. When the last approval is approved → change becomes `approved` (then `scheduled` once a window exists).

### 2.4 CAB

- `change_cab_boards` — `ChangeCabBoard`: organization_id, name, type (`cab` · `ecab`), chair_id FK users nullable, quorum (unsignedTinyInteger default 3; ecab default 2), meeting_cadence (string nullable, e.g. "Weekly, Thursdays 10:00"), description, is_active.
- `change_cab_members` — `ChangeCabMember`: board_id, user_id, role (`chair` · `member` · `advisor`), represents (string: `IT Operations` · `Information Security` · `Business` · `Risk` · `Compliance` · `Internal Audit` · `Applications` · `Infrastructure` · `Service Desk`), is_voting boolean default true. Unique (board_id, user_id).
- `change_cab_votes` — `ChangeCabVote`: organization_id, change_request_id, approval_id, user_id, vote (`approve` · `reject` · `abstain` · `defer`), comment, voted_at. Unique (approval_id, user_id); re-voting updates the row.
- `change_cab_meetings` — `ChangeCabMeeting`: organization_id, board_id, title, scheduled_at, status (`scheduled` · `held` · `cancelled`), chaired_by_id, minutes text, timestamps.
- `change_cab_meeting_items` — `ChangeCabMeetingItem`: meeting_id, change_request_id, sequence, decision (`pending` · `approved` · `rejected` · `deferred` · `more_info`), notes. Unique (meeting_id, change_request_id).

**CAB decision rule** (`ChangeApprovalRouter::evaluateBoard()`): a board approval is **approved** when approve-votes from voting members ≥ quorum and reject-votes = 0; **rejected** when reject-votes ≥ 1 and the chair (or any `approve changes` holder) confirms via the normal decide action with status rejected, or when reject-votes ≥ quorum. The chair may **override** (record decision directly) with a note — logged as `cab_override`. A pending board approval can also be decided in a CAB meeting: marking a meeting item `approved`/`rejected` applies the decision to the change's board approval with the meeting reference in the note.

### 2.5 Other tables

- `change_freeze_windows` — `ChangeFreezeWindow`: organization_id, name, starts_at, ends_at, scope (`all` or json list of categories in `categories` json nullable), reason, allow_emergency boolean default true, created_by_id. E.g. "Year-end close 28 Dec–3 Jan", "CBN returns week", "Month-end processing".
- `change_standard_templates` — `ChangeStandardTemplate`: organization_id, name, category, description, implementation_plan, backout_plan, risk_level (low/medium), expected_downtime_minutes, approved_by_id, approved_at, review_due_at (date), is_active, usage_count. A standard change can only be raised from an **active, approved** template whose review_due_at is not past.
- `change_tasks` — `ChangeTask`: organization_id, change_request_id, title, type (`pre_implementation` · `implementation` · `validation` · `backout` · `communication`), assignee_id, due_at, sequence, status (`pending` · `in_progress` · `done` · `skipped`), completed_at, completed_by_id, notes.
- `change_events` — `ChangeEvent`: change_request_id, event_type (`created` · `updated` · `submitted` · `routed` · `approval` · `cab_vote` · `cab_override` · `status` · `scheduled` · `conflict` · `freeze_override` · `task` · `comment` · `pir` · `assigned` · `notified`), description, actor_id, payload json, created_at. Timeline, newest first.

---

## 3. Lifecycle (state machine) — `App\Services\Change\ChangeWorkflow`

Statuses: `draft` → `submitted` → `in_approval` → `approved` → `scheduled` → `implementing` → `implemented` → `review` → `closed`; terminal side-states `rejected`, `cancelled`; outcome states `backed_out`, `failed` (both lead to `review` then `closed`).

```
draft ──submit──▶ submitted ──route──▶ in_approval ──all approved──▶ approved ──schedule──▶ scheduled ──start──▶ implementing
  │                                        │ reject                                                                 │
  └──cancel──▶ cancelled                   ▼                                                                        ├─complete──▶ implemented ──▶ review ──close──▶ closed
                                        rejected ──revise──▶ draft                                                  ├─back out──▶ backed_out ──▶ review ──close──▶ closed
                                                                                                                    └─fail──────▶ failed ─────▶ review ──close──▶ closed
```

`submit` and `route` happen in one action (`submit`): validation, risk scoring, approval routing, notifications. `submitted` is therefore transient but remains a valid status (a change with no applicable stages — a standard change — goes `submitted` → `approved` immediately).

### 3.1 Actions (`TRANSITIONS` = action ⇒ statuses allowed from)

| action | from | guards / effects |
|---|---|---|
| `submit` | draft | normal/emergency need description, impact_assessment, implementation_plan, backout_plan, impact, likelihood; standard needs an eligible template. Computes risk, sets `pir_required` (high/critical/emergency), builds approvals via router. If no approvals → `approved` immediately (standard). If scheduled window falls in a freeze window → blocked unless emergency (and window allows) or `freeze_override_reason` given (logged `freeze_override`). Notifies current-stage approvers. |
| `decide` | in_approval | input `decision` (approve/reject) + `note` (required to reject). Actor must be eligible for the **current** approval (§4). Requester, implementer and change owner cannot approve their own change (SoD) except the `business_approval` stage which *is* the owner. On approve → next stage notified or change `approved` (+`approved_by_id` when stage is change_manager/last). On reject → `rejected`, later approvals `skipped`, requester notified. |
| `vote` | in_approval (current stage is a board) | voting member of that board records approve/reject/abstain/defer with comment; router re-evaluates quorum; may auto-approve the stage. |
| `revise` | rejected | back to `draft`, approvals archived (status stays for history), note required. |
| `schedule` | approved, scheduled | set/alter window; conflict detection returns warnings (not blocking); freeze window blocking rule as in submit; high/critical require `test_plan` present. Status → `scheduled`. |
| `start` | scheduled (or approved for emergency) | must be within ±24h of window unless emergency; sets `actual_start_at`, status `implementing`. Only implementer, change manager, or `edit changes` holder. |
| `complete` | implementing | `actual_end_at`; status `implemented`; then → `review` if `pir_required`, else → `closed` with closure_code `implemented` (closed_at). |
| `back_out` | implementing | note required; status `backed_out`; `pir_required = true`; then `review`. |
| `fail` | implementing | note required; status `failed`; `pir_required = true`; then `review`. |
| `review` (PIR) | review | input pir_outcome, pir_summary; reviewer must hold `approve changes` and must not be the implementer (SoD); sets `pir_by_id`, `reviewed_at`, status `closed`, closure_code from outcome. |
| `cancel` | draft, submitted, in_approval, approved, scheduled | note required; `cancelled`, closure_code `cancelled`. |
| `reopen` | closed | not allowed — a closed change is immutable; raise a new change (service throws). |

Every transition writes a `change_events` row. All validation failures throw `ValidationException` on field `action`/`note`/`decision` like `IssueWorkflow`.

### 3.2 Risk scoring — `ChangeRiskScorer`

`score = impact × likelihood`; level bands above. Automatic uplifts (each logged in `payload`): +1 likelihood step if `outage_required`; level raised to at least `high` if any affected asset has `criticality = critical` or category = `core_banking`; emergency changes are never below `medium`. `scoreFor(ChangeRequest $c): array{score:int, level:string, factors:array}`.

### 3.3 Emergency changes (ECAB)

- `change_type = emergency` requires `source_type` ∈ {incident, vulnerability, regulatory} or justification ≥ 20 chars.
- Routing: ECAB (board, quorum 2, sla 4h) + Change Manager.
- **Retrospective authorisation (COBIT BAI06.02):** an emergency change may `start` from `in_approval` when the actor holds `approve changes` and supplies `emergency_start_reason`; the change records `started_before_approval = true` (boolean column on change_requests) and the ECAB/Change Manager approvals must still be completed within 24h (overdue ones are flagged in metrics as "unauthorised emergency changes"). PIR is always mandatory.

### 3.4 Freeze windows — `ChangeFreezeChecker`

`blocking(ChangeRequest|array $window, int $orgId): ?ChangeFreezeWindow` — returns the first active freeze window overlapping `[scheduled_start_at, scheduled_end_at]` whose scope covers the change's category. Emergency changes pass when `allow_emergency`. Normal/standard changes need `freeze_override_reason` **and** the final authoriser is then always required (standard changes lose their pre-approval inside a freeze: router adds the `change_manager` stage).

### 3.5 Conflict detection — `ChangeConflictDetector`

`conflicts(ChangeRequest $c): array` — other open changes (approved/scheduled/implementing) in the same bank whose window overlaps and that share ≥1 affected asset id, or the same category when both are `core_banking`/`network`/`infrastructure`, or any affected service name match (case-insensitive). Each conflict: `{id, change_code, title, status, scheduled_start_at, scheduled_end_at, reason}`. Logged as `conflict` event on schedule; shown as warnings.

---

## 4. Approver resolution — `ChangeApprovalRouter`

`route(ChangeRequest $c): Collection<ChangeApproval>` — selects active stages where `change_type ∈ applies_to_types` and `rank(risk_level) ≥ rank(min_risk_level)`, ordered by sequence; creates `change_approvals` snapshots. Approver resolution:

| approver_type | eligible to decide |
|---|---|
| `role` | any user in the bank with that Spatie role (Super Admin always) |
| `job_title` | any user in the bank whose `job_title` LIKE `%ref%`; fallback: role `Organization Admin` if none exist |
| `user` | that user id (must be in the bank) |
| `owner` | `change_owner_id` (dynamic) |
| `board` | voting members of the board vote; the chair or any `approve changes` holder can decide/override |

`canDecide(ChangeApproval $a, User $u): bool` encapsulates this + SoD (requester/implementer cannot decide any stage other than `business_approval` when they are the owner; nobody can decide twice in a row on two consecutive stages unless Organization Admin — keep simple: requester ≠ approver, implementer ≠ approver). `pendingFor(User $u)` returns the approvals the user can currently act on (for the My Approvals inbox and the dashboard).

Overdue approvals (`due_at < now`, pending): `changes:sweep` artisan command sends `ChangeApprovalOverdueNotification` to the eligible approvers and the change manager, once per 24h (track `reminded_at` timestamp column on change_approvals). Scheduled hourly in `routes/console.php`.

---

## 5. Notifications (`app/Notifications/Change/`, all extend `BaseNotification`)

| class | to | when |
|---|---|---|
| `ChangeApprovalRequestedNotification` | eligible approvers of the current stage (board → voting members) | stage becomes current |
| `ChangeDecisionNotification` | requester, change owner, implementer | approved / rejected / stage approved |
| `ChangeScheduledNotification` | implementer, owner, CAB chair when cab_required | scheduled / rescheduled |
| `ChangeApprovalOverdueNotification` | current approvers + change manager | sweep |
| `ChangeOutcomeNotification` | requester, owner, change manager, CAB chair | implemented / backed_out / failed / PIR closed |

Type strings `changes.approval_requested` etc., module `changes`, actionUrl `route('changes.show', $change)`.

---

## 6. Permissions and roles

Add module `changes` to `RolesAndPermissionsSeeder::$modules` (generates `view|create|edit|delete|approve|export changes`) **and** ship migration `2027_01_15_000002_grant_change_management_permissions.php` that creates the permissions and grants (idempotent, uses `firstOrCreate`, forgets cached permissions):

| role | grants |
|---|---|
| Super Admin, Organization Admin | all |
| **Change Manager** (new role) | all `changes` + `view assets`, `view risks`, `view incidents`, `view vulnerabilities`, `view issues`, `view ea`, `view platform` |
| Risk Manager | view, create, edit, approve changes (sits on CAB for Risk) |
| Compliance Officer | view, create, approve changes (CAB for Compliance) |
| Security Analyst | view, create, edit changes |
| Enterprise Architect, Solution Architect, Application Owner, Data Steward | view, create, edit changes (Application Owner also `approve changes` — business owner approval) |
| Auditor | view, export changes |
| Viewer | view changes |

Route gating: `permission:view changes` on the group; `create changes` on store; `edit changes` or being requester/implementer/owner for update/tasks (controller check `canWork`); `approve changes` for policy, boards, templates approval, freeze windows, PIR, override; decide/vote gated in the router (`canDecide`) not by a blanket permission, so a CISO without `approve changes` can still clear the security stage — but **the final `change_manager` stage always requires `approve changes`**.

---

## 7. Routes (`routes/web.php`, inside the auth group; prefix `/changes`, names `changes.*`)

```
GET    /changes                               changes.index          view changes
GET    /changes/create                        changes.create         create changes
POST   /changes                               changes.store          create changes
GET    /changes/approvals                     changes.approvals      (My Approvals inbox)
GET    /changes/calendar                      changes.calendar       (forward schedule + freeze windows; ?from=&to=)
GET    /changes/cab                           changes.cab.index      (boards, members, meetings)
POST   /changes/cab/boards                    changes.cab.boards.store        approve changes
PUT    /changes/cab/boards/{board}            changes.cab.boards.update       approve changes
POST   /changes/cab/boards/{board}/members    changes.cab.members.store       approve changes
DELETE /changes/cab/members/{member}          changes.cab.members.destroy     approve changes
POST   /changes/cab/meetings                  changes.cab.meetings.store      approve changes
GET    /changes/cab/meetings/{meeting}        changes.cab.meetings.show
PUT    /changes/cab/meetings/{meeting}        changes.cab.meetings.update     approve changes (status/minutes)
POST   /changes/cab/meetings/{meeting}/items  changes.cab.meetings.items.store   approve changes (add change to agenda)
PUT    /changes/cab/meeting-items/{item}      changes.cab.meetings.items.update  approve changes (decision/notes)
GET    /changes/policy                        changes.policy          (approval stages)
PUT    /changes/policy/{stage}                changes.policy.update   approve changes
POST   /changes/policy                        changes.policy.store    approve changes
DELETE /changes/policy/{stage}                changes.policy.destroy  approve changes
GET    /changes/templates                     changes.templates
POST   /changes/templates                     changes.templates.store      create changes
PUT    /changes/templates/{template}          changes.templates.update     edit changes
POST   /changes/templates/{template}/approve  changes.templates.approve    approve changes
GET    /changes/freeze-windows                changes.freeze.index
POST   /changes/freeze-windows                changes.freeze.store         approve changes
PUT    /changes/freeze-windows/{window}       changes.freeze.update        approve changes
DELETE /changes/freeze-windows/{window}       changes.freeze.destroy       approve changes
GET    /changes/{change}                      changes.show           (whereNumber)
PUT    /changes/{change}                      changes.update         canWork
POST   /changes/{change}/transition           changes.transition     {action, note, ...}
POST   /changes/{change}/decide               changes.decide         {decision, note}
POST   /changes/{change}/vote                 changes.vote           {vote, comment}
POST   /changes/{change}/schedule             changes.schedule       {scheduled_start_at, scheduled_end_at, freeze_override_reason?}
POST   /changes/{change}/tasks                changes.tasks.store    canWork
PUT    /changes/{change}/tasks/{task}         changes.tasks.update   canWork or assignee
POST   /changes/{change}/comment              changes.comment
GET    /changes/export                        changes.export         export changes (CSV of register)
```

Static paths (`/changes/create`, `/approvals`, `/calendar`, `/cab`, `/policy`, `/templates`, `/freeze-windows`, `/export`) are declared **before** `/changes/{change}` and `{change}` is `whereNumber`. Existing `change-requests.index` (Policies stub) becomes a redirect to `changes.index` (keep the route name — nav and smoke test reference it; remove the sidebar entry under Policy Management).

Controllers: `Change\ChangeRequestController` (index/create/store/show/update/transition/decide/vote/schedule/comment/approvals/calendar/export), `Change\ChangeTaskController`, `Change\ChangeCabController`, `Change\ChangePolicyController` (stages + templates + freeze windows). Move the old root `App\Http\Controllers\ChangeRequestController::index` to a redirect (keep the class, one method).

---

## 8. Pages and props (Inertia) — `resources/js/Pages/Changes/`

Shared JS: `resources/js/Utils/changes.js` exporting `CHANGE_TYPES`, `CATEGORIES`, `PRIORITIES`, `RISK_LEVELS`, `STATUS_STEPS` (`['draft','in_approval','approved','scheduled','implementing','implemented','review','closed']`), `riskLevelFor(impact, likelihood)`, colour maps (navy `#0A1F44`, gold `#C9A86A`, green `#2D7D46`, red `#B3261E`, amber `#E5A100`, blue `#1D4ED8`, grey `#718096`). Use `formatDate`, `humanize`, `toDateInput` from `@/Utils/risk`. Layout `AuthenticatedLayout header="…"`, `PageHeader` with breadcrumbs `[{label:'Change Management'}, …]`, `KpiCard`, `StatusBadge` (add change statuses to `StatusBadge` map: `submitted`, `in_approval`, `scheduled`, `implementing`, `implemented`, `review`, `backed_out`, `failed`, `cancelled`, `rejected`, `pending`, `skipped`, `expired`, `standard`, `normal`, `emergency`). Headings via `<h2>` in PageHeader so Playwright can anchor on `getByRole('heading')`.

| page | props |
|---|---|
| `Index.jsx` (Change Register) | `changes[]` {id, change_code, title, change_type, category, priority, risk_level, status, requester, owner, implementer, scheduled_start_at, scheduled_end_at, current_stage (name or null), approvals_pending, cab_required, pir_required, created_at}; `counts` {status⇒n}; `stats` (ChangeMetrics::summary); `filters` {status,type,risk,category,q,view,sort,dir,mine}; `owners[]` {id,name,job_title}. Board view by status columns + list view; KPI row: Open, Awaiting approval, Scheduled (7 d), Emergency (30 d), Success rate (90 d), Overdue approvals. "Raise change" button → `changes.create`. |
| `Create.jsx` | `templates[]` (active approved), `assets[]` {id,name,criticality}, `owners[]`, `sourceRecords` {risk:[{id,code,title}], incident:[], vulnerability:[], issue:[]} (Inertia::optional), `freezeWindows[]` upcoming, `defaults` {change_type: 'normal'}. Form with type selector (cards: Standard / Normal / Emergency with one-line meaning), live risk matrix preview (`riskLevelFor`) and the routing preview text ("This change will need: Technical review → Security review → CAB → Change Manager"), via prop `routingPreview` (object keyed by type+risk from the controller, computed from the org's stages). Save as draft (`status draft`) or Save & submit (`submit: true`). |
| `Show.jsx` | `change` (full with requester, owner, implementer, change_manager, approved_by, pir_by, template, approvals (with assignee, decided_by, board, votes.user), tasks (assignee), events (actor), cab_items.meeting); `source` (sourceSummary); `affectedAssets[]`; `conflicts[]`; `freeze` (blocking window or null); `currentApproval` (id or null); `canWork`, `canDecide`, `canVote`, `myVote`, `canApprove` (`approve changes`), `canStart`, `canReview`; `owners[]`, `assets[]`, `boardsMembers` (for CAB stage: members with their vote). Sections: status stepper; approval stage tracker (vertical list with status, approver, due, decision note; current stage highlighted with Approve/Reject form or Vote buttons + member vote tally with quorum bar); details (editable like Issues Show); schedule card (window, conflicts, freeze warning, reschedule form); tasks checklist (add/complete); PIR card (form when status review and canReview); timeline with comment box; side panel with risk matrix cell, type, category, priority, people, source link, linked assets. |
| `Approvals.jsx` (My Approvals) | `approvals[]` {approval_id, change:{id,change_code,title,change_type,risk_level,requester,scheduled_start_at}, stage_name, due_at, overdue, is_board, my_vote}; `history[]` last 20 decided by me. Inline Approve/Reject with note. |
| `Calendar.jsx` | `from`, `to`, `changes[]` {id, change_code, title, change_type, risk_level, status, scheduled_start_at, scheduled_end_at, outage_required, category}, `freezeWindows[]`, `conflictIds[]`. Week/month grid (simple CSS grid of days with chips; no external calendar lib) + list below; freeze windows as shaded bands; legend. |
| `Cab/Index.jsx` | `boards[]` {id,name,type,quorum,meeting_cadence,chair,members[]{id,user:{id,name,job_title},role,represents,is_voting}, pending_count}, `meetings[]` upcoming/held {id,title,scheduled_at,status,board,items_count}, `users[]`, `agendaCandidates[]` (changes whose current stage is a board). Forms: add board, add member, schedule meeting. |
| `Cab/Meeting.jsx` | `meeting` {…, board, chaired_by, items[]{id, sequence, decision, notes, change:{id,change_code,title,change_type,risk_level,status,requester,scheduled_start_at, votes_tally}}}, `candidates[]`, `canManage`. Agenda ordering, per-item decision buttons, minutes textarea, mark held. |
| `Policy.jsx` | `stages[]`, `boards[]`, `roles[]` (names), `matrix` (type × risk ⇒ ordered stage names) for the explanatory grid. Edit sequence/name/min risk/types/approver/sla/active; add custom stage. |
| `Templates.jsx` | `templates[]` with approver, usage_count, review_due_at state; create/edit form; Approve button (`approve changes`). |
| `FreezeWindows.jsx` | `windows[]` (upcoming first), create/edit/delete; `categories[]`. |

Navigation (`resources/js/Config/navigation.js`): new group **Change Management** (icon `ArrowPathIcon`) after *Issues & Remediation*: Change Register `changes.index` · My Approvals `changes.approvals` · Change Calendar `changes.calendar` · CAB & ECAB `changes.cab.index` · Standard Changes `changes.templates` · Approval Policy `changes.policy` · Freeze Windows `changes.freeze.index` — all `permission: 'view changes'`. Remove *Change Requests* from the Policy Management group.

Executive dashboard: `DashboardController` adds `'changes' => $orgId ? $this->changesPulse() : null` (`ChangeMetrics::summary()` + `upcoming(5)` + `myApprovals` count); `Pages/Dashboard.jsx` adds a *Change Management* panel in the same style as the Issues panel: tiles (Awaiting approval, Scheduled next 7 days, Emergency last 30 d, Change success rate 90 d, Failed/backed out 90 d, Overdue approvals) + the next 5 scheduled changes with risk badge.

---

## 9. Metrics — `ChangeMetrics::summary()`

`open`, `awaiting_approval`, `scheduled_7d`, `implementing`, `emergency_30d`, `emergency_ratio_pct` (emergency ÷ all raised, 90 d), `success_rate_pct` (closed with closure_code implemented & pir_outcome ∈ {successful, null} ÷ closed with implementation attempt, 90 d), `failed_or_backed_out_90d`, `overdue_approvals`, `unauthorised_emergency` (started_before_approval and any approval still pending > 24h after start), `mean_lead_time_days` (submitted → approved, 90 d), `cab_pending`, `in_freeze_overrides_90d`. Also `upcoming(int $limit)`, `byType()`, `byRisk()`.

---

## 10. Demo data — `ChangeManagementDemoSeeder` (runs after `IssuesDemoSeeder`, before `RegulatoryIntelligenceDemoSeeder`; `mt_srand` seeded per bank; tenant-scoped "already seeded" guard on `change_requests`)

Per bank (Kano Heritage `KHB-CHG-`, First Bank `FBN-CHG-`): default approval policy; CAB (chair = CISO or admin, 6–7 members from existing users across IT Ops/Security/Business/Risk/Compliance/Audit, quorum 3) and ECAB (3 members, quorum 2); 3 freeze windows (month-end, year-end, CBN returns week — one current/upcoming); 6 standard templates (password policy push, routine OS patching, certificate renewal, firewall rule per template, DNS record change, user access review script) with 1 awaiting approval; 2 upcoming CAB meetings (one held with minutes); ~24 change requests across types/categories/statuses with coherent dates, approvals matching the routing, votes on CAB stages, tasks, events, a couple of conflicts, one emergency started before approval, 2 failed/backed-out with PIR, 2 in review, linked to real in-bank incidents/risks/vulnerabilities/issues/assets. Add Change Manager demo user `changemanager@atheris.ng` (role `Change Manager`, title "IT Change Manager", dept "IT Service Management") in `RoleDemoUsersSeeder`, and give one existing user per bank the Change Manager role (e.g. Kano: the Head of IT Operations user, First Bank: equivalent) so the demo routing has a real authority.

---

## 11. Tests — `tests/Feature/Changes/ChangeManagementModuleTest.php`

1. tenant isolation of register, boards, policy, templates, freeze windows (other bank's change → 404; index excludes).
2. risk scoring bands + uplifts (critical asset, core_banking, outage).
3. routing matrix: standard → approved at once; normal low → change manager only; normal medium → 3 stages; normal high → 5 incl. CAB; emergency → ECAB + change manager.
4. sequential approvals: only current stage actionable; approving advances; rejection skips rest and notifies requester.
5. segregation of duties: requester cannot approve own change; implementer cannot do PIR; final stage needs `approve changes`.
6. CAB quorum: votes below quorum keep pending; reaching quorum approves; one reject + chair confirm rejects; chair override logged.
7. freeze window: normal change scheduled inside is refused; with override reason it passes and logs; emergency passes.
8. conflict detection returns overlapping change sharing an asset.
9. emergency retrospective start is allowed with `approve changes` and reason; flagged unauthorised if approvals overdue.
10. implementation outcomes: complete → closed when no PIR; high → review → PIR by independent reviewer → closed; back_out forces PIR.
11. standard template eligibility (unapproved / expired template refused; usage_count increments).
12. my approvals inbox lists only actionable approvals; sweep sends overdue notifications once per 24h.
13. CSV export gated on `export changes` and tenant-scoped.
14. dashboard feeds `changes` pulse; calendar range returns changes and freeze windows.

Use `Notification::fake()`, `RefreshDatabase`, `$this->withoutVite()`, `RolesAndPermissionsSeeder`, factories for Organization/User/Asset/Incident/Risk; create policy via `ChangePolicySeeder::ensureDefaults`.

---

## 12. Definition of done

PHP suite green, `php artisan migrate:fresh --seed` on a scratch SQLite DB green, `npm run build` green, Pint on touched files, `RouteSmokeTest` with `SMOKE=1` green, README module table and `docs/VALIDATION_HANDOFF.md` updated, this spec + `docs/ATH-CHG-002_Change_Management_Build_Update.md` written.
