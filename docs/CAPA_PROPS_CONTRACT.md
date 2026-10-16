# CAPA props contract (IT-GOV-CAPA-001, Phase A backend)

The Issues register is the CAPA register. An issue becomes a CAPA once `capa_framework` is set;
issues without it keep the legacy `open → in_progress ⇄ blocked → remediated → verified → closed`
lifecycle and the legacy `issues.transition` endpoint.

Source of truth: `app/Services/Issues/CapaPresenter.php` (shapes), `app/Services/Issues/CapaWorkflow.php`
(gates), `config/capa.php` (frameworks, provisions, stages, RACI). Change this file with them.

## Conventions

- Dates are ISO-8601 strings (`toIso8601String`, UTC); `due_date` on actions is `YYYY-MM-DD`.
- Severity is stored as `critical | high | moderate | low`. **Display `moderate` as "Medium"**
  (`capaConfig.severityLabels`).
- Gate and rule failures come back as Inertia validation errors. Key `capa` carries stage-gate,
  sign-off and verifier refusals; field keys (`capa_classification`, `cbn_provisions.0`,
  `settling_days`, `cbn_correlation`, `note`, `criteria`, `owner_id`, `status`, …) carry field errors.
  Success is `flash.success`.
- Legacy `issues.transition` on a CAPA: only `block` / `unblock` are accepted (error key `action`
  otherwise). Unblocking returns the issue to the status its stage implies.

## Stage ↔ Issue.status

| capa.stage | Issue.status |
|---|---|
| identification, containment | open |
| rca, action_planning, implementation | in_progress |
| verification | remediated |
| closed | closed (`verified_at`/`verified_by_id` set) |

`blocked` can overlay any open stage; advancing is refused while blocked.

## Issues/Show props (added)

```ts
capa: {
  enabled: boolean,                 // issue is a CAPA
  framework: 'iso27001'|'cobit2019'|'itilv4'|null,
  framework_label: string|null,
  classification: string|null,      // key from the framework's classifications
  classification_label: string|null,
  framework_reference: string|null, // key from the framework's references
  framework_reference_label: string|null,
  rca_focus: string|null,           // framework RCA prompt (§4 stage 3)
  cbn_provisions: string[],         // keys of capaConfig.cbnProvisions
  cbn_correlation: string|null,
  stage: StageKey|null,
  stage_label: string|null,
  stages: { key, label, status: 'done'|'current'|'pending', completed_at: string|null }[],  // 7 items, [] when not a CAPA
  signoffs: { [stage]: { user_id, name, raci_role, at, note } },   // object (may be {})
  signoff_required: boolean,        // current stage needs an Accountable sign-off before advancing
  can_sign_off: boolean,            // current user is Accountable for the current stage
  accountable: RaciRoleKey|null,    // Accountable role for the current stage
  my_raci_roles: RaciRoleKey[],
  settling_days: number|null,
  implemented_at: string|null,
  verification_due_at: string|null, // implemented_at + settling_days
  verification_open: boolean,       // in verification and the settling period is over
  can_verify: boolean,              // in verification and current user is an eligible independent verifier
  verifier_blockers: string[],      // why the current user cannot verify (only in verification)
  verification_criteria: { key, label, checked }[],  // framework checklist + last recorded results
  gate: { can_advance: boolean, next_stage: StageKey|null, blockers: string[] },
  raci: { stage, label, assignments: { it_governance, it_operations, infosec, internal_audit: 'R'|'A'|'C'|'I' } }[],
  critical_notified_at: string|null,
},
containmentActions: { id, action, notes, performed_at, performed_by: {id,name}|null }[],
rootCause: null | {
  id, method: 'five_whys'|'fishbone'|'both',
  five_whys: string[],
  fishbone: { people: string[], process: string[], technology: string[], governance: string[] },
  root_cause_summary: string|null,
  complete: boolean,                // ≥3 whys OR ≥1 fishbone cause, AND a summary
  completed_by: {id,name}|null, updated_at,
},
capaActions: {
  id, action_code /* e.g. ISS-0007-A2 */, title, description, owner_id, owner: {id,name}|null,
  due_date, status: 'planned'|'in_progress'|'done'|'cancelled', overdue: boolean, completed_at,
  change_request: { id, code, title, status, url } | null,
}[],
recurrence: { of: IssueRef|null, later: IssueRef[] },   // IssueRef = { id, issue_code, title, status, capa_stage, created_at, closed_at }
capaConfig: CapaConfig,
changeRequests?: { id, change_code, title, status }[]   // Inertia optional prop: router.reload({ only: ['changeRequests'] })
```

### capaConfig (Show and Index)

```ts
{
  enabled: boolean,                 // module switch (CAPA_ENABLED)
  frameworks: { key, label, focus, classification_hint, rca_focus,
                classifications: {key,label}[], references: {key,label}[], verification_criteria: {key,label}[] }[],
  cbnProvisions: { key, label, source, description, verified: boolean }[],   // verified=false → show "reference unverified"
  stages: { key, label }[],
  raciRoles: { key, label, roles: string[] }[],   // Spatie role names behind each RACI role
  raci: { stage, label, assignments }[],
  settling: { default: 30, min: 30, max: 90 },
  severityLabels: { critical: 'Critical', high: 'High', moderate: 'Medium', low: 'Low' },
  fishboneCategories: ['people','process','technology','governance'],
  rcaMethods: ['five_whys','fishbone','both'],
  minWhys: 3,
  actionStatuses: ['planned','in_progress','done','cancelled'],
  kpiTargets: { overdue_rate_pct: 5, recurrence_rate_pct: 0 },
}
```

## Issues/Index props (added)

- Each `issues[]` row gains `capa_framework`, `capa_stage` (null when not a CAPA), `verification_due_at`.
- `filters` accepts `framework` (`any` | `none` | `iso27001` | `cobit2019` | `itilv4`) and `stage` (a stage key; implies CAPA only).
- `capaConfig` as above.
- `capaKpis`:

```ts
{
  total, open,
  actions_total, actions_overdue,
  overdue_rate_pct: number|null, overdue_rate_target_pct: 5,     // open past due, or done after due; cancelled excluded
  closed_12m, recurred_12m,
  recurrence_rate_pct: number|null, recurrence_rate_target_pct: 0, // CAPAs closed in last 12 months with a recurrence within 12 months of closure
  mttr_days: number|null,                                           // mean days raised → implemented_at
  by_framework: { iso27001, cobit2019, itilv4 },
  by_stage: { identification, containment, rca, action_planning, implementation, verification, closed },
  verifications_due,     // in verification, settling period over
  in_settling,           // in verification, settling period running
}
```

## Endpoints (all inside the `permission:view issues` group)

| Route name | Method / URI | Body | Who |
|---|---|---|---|
| `issues.store` (existing) | POST `/issues` | + optional `capa_framework`, `capa_classification`, `framework_reference`, `cbn_provisions[]`, `settling_days`, `recurrence_of_id` | `create issues` |
| `issues.capa.update` | PUT `/issues/{issue}/capa` | `capa_framework` (required the first time), `capa_classification`, `framework_reference`, `cbn_provisions[]`, `cbn_correlation`, `settling_days` | edit issues or owner |
| `issues.capa.stage` | POST `/issues/{issue}/capa/stage` | `note?` | edit issues or owner |
| `issues.capa.signoff` | POST `/issues/{issue}/capa/signoff` | `note?` — signs the **current** stage | RACI Accountable for that stage |
| `issues.capa.containment.store` | POST `/issues/{issue}/capa/containment` | `action`, `notes?`, `performed_at?`, `performed_by_id?` | edit issues or owner; stage identification/containment |
| `issues.capa.containment.destroy` | DELETE `/issues/{issue}/capa/containment/{action}` | — | same |
| `issues.capa.root-cause` | PUT `/issues/{issue}/capa/root-cause` | `method`, `five_whys[]`, `fishbone{people[],process[],technology[],governance[]}`, `root_cause_summary` | edit issues or owner; until RCA is complete |
| `issues.capa.actions.store` | POST `/issues/{issue}/capa/actions` | `title`, `description?`, `owner_id?`, `due_date?`, `status?`, `change_request_id?` | edit issues or owner; until implementation |
| `issues.capa.actions.update` | PUT `/issues/{issue}/capa/actions/{action}` | any of the above | edit issues, issue owner, or the action's owner |
| `issues.capa.actions.destroy` | DELETE `/issues/{issue}/capa/actions/{action}` | — | until action planning ends (cancel afterwards) |
| `issues.capa.verify` | POST `/issues/{issue}/capa/verify` | `outcome: pass|fail`, `criteria: {key: bool}`, `note` (required for fail) | Internal audit (Auditor), independent |
| `issues.capa.export` | GET `/issues/capa/export?framework=&stage=` | — (CSV download) | `export issues` |

Rules worth surfacing in the UI:

- Actions can be set `in_progress`/`done` only in implementation; `cancelled` any time.
- Any change to the plan (add/edit/remove action, or CBN correlation) during action planning
  **withdraws** the plan sign-off.
- Framework and classification lock after identification; the CBN correlation locks after the plan
  is approved; the settling period locks during verification; a closed CAPA is fully locked (raise a
  recurrence with `recurrence_of_id`).
- `pass` is refused before `verification_due_at`, unless every criterion is checked, and (ITIL)
  while a recurrence exists. `fail` is allowed any time in verification and returns to implementation.
- Verifier must not be the issue owner, the implementer (`remediated_by_id`) or any action owner.
- ITIL recurrence = a CAPA with `recurrence_of_id` = this issue, a new issue on the same source
  record, or an incident tagged `capa:{issue_code}`, created since `implemented_at`.

## Example Show payload (abridged)

```json
{
  "issue": { "id": 42, "issue_code": "ISS-0042", "title": "Finacle EOD batch failures", "severity": "high", "status": "in_progress" },
  "capa": {
    "enabled": true,
    "framework": "iso27001", "framework_label": "ISO/IEC 27001:2022",
    "classification": "nonconformity", "classification_label": "Nonconformity",
    "framework_reference": "iso27001_10_2", "framework_reference_label": "Clause 10.2 — Nonconformity & corrective action",
    "rca_focus": "Isolate the breakdown in information security controls (e.g. failed access reviews, missing policy).",
    "cbn_provisions": ["cbn_itsb_3_5"],
    "cbn_correlation": "Fixes CBN ITSB §3.5 on persistent downtime.",
    "stage": "action_planning", "stage_label": "Action plan & CBN alignment",
    "stages": [
      { "key": "identification", "label": "Identification & classification", "status": "done", "completed_at": "2026-10-01T09:12:00+00:00" },
      { "key": "containment", "label": "Containment & immediate mitigation", "status": "done", "completed_at": "2026-10-01T11:40:00+00:00" },
      { "key": "rca", "label": "Root cause analysis", "status": "done", "completed_at": "2026-10-03T15:02:00+00:00" },
      { "key": "action_planning", "label": "Action plan & CBN alignment", "status": "current", "completed_at": null },
      { "key": "implementation", "label": "Implementation & deployment", "status": "pending", "completed_at": null },
      { "key": "verification", "label": "Independent effectiveness verification", "status": "pending", "completed_at": null },
      { "key": "closed", "label": "Closed", "status": "pending", "completed_at": null }
    ],
    "signoffs": {},
    "signoff_required": true, "can_sign_off": true, "accountable": "it_governance",
    "my_raci_roles": ["it_governance"],
    "settling_days": 30, "implemented_at": null, "verification_due_at": null, "verification_open": false,
    "can_verify": false, "verifier_blockers": [],
    "verification_criteria": [
      { "key": "risk_within_threshold", "label": "Residual risk is within the bank's acceptable threshold", "checked": false },
      { "key": "nonconformity_mitigated", "label": "The nonconformity is mitigated and the control operates as designed", "checked": false },
      { "key": "evidence_retained", "label": "Evidence of the corrective action is retained in the ISMS record", "checked": false },
      { "key": "no_new_nonconformity", "label": "No new nonconformity has arisen from the change", "checked": false }
    ],
    "gate": { "can_advance": false, "next_stage": "implementation",
              "blockers": ["The action plan needs sign-off by IT Governance / GRC."] },
    "raci": [
      { "stage": "identification", "label": "Identification & classification",
        "assignments": { "it_governance": "A", "it_operations": "R", "infosec": "R", "internal_audit": "C" } }
    ],
    "critical_notified_at": null
  },
  "containmentActions": [
    { "id": 7, "action": "Failed batch node isolated.", "notes": null, "performed_at": "2026-10-01T10:05:00+00:00", "performed_by": { "id": 12, "name": "Chiamaka Nnamdi" } }
  ],
  "rootCause": {
    "id": 3, "method": "five_whys",
    "five_whys": ["Batch failed", "Disk full", "No log rotation"],
    "fishbone": { "people": [], "process": [], "technology": [], "governance": [] },
    "root_cause_summary": "Log rotation missing from the EOD server baseline.",
    "complete": true, "completed_by": { "id": 12, "name": "Chiamaka Nnamdi" }, "updated_at": "2026-10-03T14:58:00+00:00"
  },
  "capaActions": [
    { "id": 11, "action_code": "ISS-0042-A1", "title": "Add log rotation to baseline", "description": null,
      "owner_id": 12, "owner": { "id": 12, "name": "Chiamaka Nnamdi" }, "due_date": "2026-10-20",
      "status": "planned", "overdue": false, "completed_at": null,
      "change_request": { "id": 5, "code": "CHG-0005", "title": "EOD server baseline update", "status": "approved", "url": "https://…/changes/5" } }
  ],
  "recurrence": { "of": null, "later": [] },
  "capaConfig": { "enabled": true, "frameworks": ["…"], "cbnProvisions": ["…"], "stages": ["…"], "settling": { "default": 30, "min": 30, "max": 90 } }
}
```

## RACI role mapping (config/capa.php → raci_roles)

| RACI role | Spatie roles |
|---|---|
| it_governance | Compliance Officer, Risk Manager, Organization Admin |
| it_operations | Change Manager, Application Owner |
| infosec | Security Analyst, Organization Admin |
| internal_audit | Auditor |

Super Admin holds every RACI role. Accountable: identification, rca, action_planning → it_governance;
verification → internal_audit; containment and implementation have no Accountable.
