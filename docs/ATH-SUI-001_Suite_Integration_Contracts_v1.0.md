# ATH-SUI-001 — Suite Integration Contracts (IT Governance ⇄ Information Security) v1.0

| | |
|---|---|
| Status | Draft v1.2 for review (31 Oct 2026) — all decisions D1–D6 resolved |
| Applies to | `IT-Governance` and `Info-Sec`, both split from `IT-Security-Risk` `main` @ `70b5e99` |
| Referenced by | `docs/SPLIT_PRUNE_IT_GOVERNANCE.md`, `docs/SPLIT_PRUNE_INFO_SEC.md` (C1, C8, C9, C10, C12, C13, C14, C15) |
| Backend neutrality | Contracts are HTTP + JSON. **D2 resolved 31 Oct 2026: the products ship on Java Spring Boot.** The contracts are unchanged by that; §10 maps them to both stacks, and the Laravel column remains the reference for the current base code. |

## 1. Purpose

Today the two products are one codebase, and the modules talk to each other through in-process Laravel events (`registerEaContracts()` in `AppServiceProvider`). Once they are pruned into two applications with two databases, every flow that crosses the product boundary needs a defined network contract. This document defines those contracts so that:

1. pruning can proceed module by module without silently breaking a cross-product flow;
2. each product still works on its own when the other is not licensed, not deployed, or down;
3. a bank buying both gets the same joined-up behaviour it gets from the single product today.

## 2. Where the boundary cuts today's code

These are the in-process flows found in the base repo that end up split across the two products. Each becomes a contract.

| Today (base repo) | Producer → consumer after split | Contract |
|---|---|---|
| `CveMatchedToComponent` → `Listeners/Ea/OpenVulnerability` (I-2) | EA (ITG) → Vulnerability register (ISec) | **C1** |
| `IncidentDeclared` → `Listeners/Core/AttachBlastRadius` (I-13) | Incident (ISec) → EA blast radius (ITG) | **C2** (new number) |
| `IncidentSaved` → `Listeners/Core/OpenCustomerImpactCapa` (CAPA §3.3) | Incident (ISec) → CAPA (ITG) | **C3** (new number) |
| Both products keep Asset Management; vulnerabilities, drift and PCI scope point at `assets.id` | Asset register (ITG) → asset mirror (ISec) | **C4** (new number) |
| Users, organisations and roles exist in both databases | Both | **C5** (new number) |
| `DashboardController`, `ReportController`, `BoardPackBuilder` import `Vulnerability`, `Incident`, `DataBreach` | Security figures (ISec) → board packs/reports (ITG) | **C6** (new number) |
| `ReturnCompiler` + `CsatMapper` (see §9, D3) | Evidence (ISec) ⇄ Returns (ITG) | **C7** (new number) |
| `config_drift_events.change_request_id` FK to `change_requests` | Drift (ISec) → Change (ITG) | **C8** |
| Vendor register + `VendorAssessment` + `TprmSecurityRating` | Vendor (ITG) → security assessment (ISec) → rating back | **C9** |
| `ChangeApprovalStage` key `security_review` (approver job title `CISO`, 48 h, `ChangePolicySeeder`) | Change (ITG) → security review (ISec) | **C10** |
| — | Reserved, not assigned | C11 |
| `Kri` / `KriReading` (cyber category) feeding board KRIs | KRIs (ISec) → KRI dashboards/board packs (ITG) | **C12** |
| `DrRunbook` / `DrExercise` triggered from a major incident | Incident (ISec) → DR invocation (ITG) | **C13** |
| EA security entities (`Zone`, `ZoneAssignment`, `ThreatModel`, `ControlMapping`, `DpiaAssessment`) | Security architecture (ISec, owner) ⇄ EA (ITG, mirror) | **C14** |
| ROPA register held by the DPO (22 Oct decision); `ConsentPurpose`, `DpiaAssessment`, `DataBreach` NDPA fields | ROPA (ITG, owner) → mirror (ISec); security measures back | **C15** |

ITG = IT Governance, ISec = Information Security. C2–C7 were not numbered in the prune checklists; this document assigns them. C11 is deliberately left free.

**Internal flows that do *not* cross the boundary** (no contract needed): I-1 obsolescence → risk, I-6 BIA → process criticality, I-9 ARB → issues, I-10 evidence pack → vault, and B3 seal-break all stay inside IT Governance.

## 3. Shared conventions (apply to every contract)

### 3.1 Transport and base path

- HTTPS, JSON (`application/json; charset=utf-8`), UTF-8, ISO-8601 timestamps with offset (`2026-10-31T09:15:00+01:00`).
- Base path on each product: `/api/suite/v1`. Routes live in `routes/suite.php` (Laravel) or a `/api/suite/v1` controller package (Spring).
- Two styles:
  - **Events** (asynchronous, fire-and-forget with guaranteed delivery): `POST /api/suite/v1/events`. Used by every contract except the queries below.
  - **Queries** (synchronous, read-only, with a timeout): `GET /api/suite/v1/<resource>`. Used where a user is waiting for an answer: C2 blast radius, C6 board-pack figures, C8 change lookup, C10 review status.

### 3.2 Deployment and authentication

**Deployment (D4, resolved).** When a client buys both products, they are deployed on the **same server**: two applications, two databases (on the same database server is fine), each with its own URL / virtual host. `SUITE_PEER_URL` then points at the peer over the local interface (e.g. `http://127.0.0.1:8081` or an internal hostname), so suite traffic never leaves the machine. Separate hosts remain technically possible (nothing in the contracts assumes a shared host), but same-server is the standard bundled install and the one the installer and documentation target.

On-prem pairs at one bank, so no third-party identity provider is assumed. Same-server deployment does **not** remove the signature requirement below: it is what stops any other process on the host, or a misrouted request, from writing into the peer.

- Each product holds a **suite client ID** and a **shared secret** for its peer (`SUITE_PEER_URL`, `SUITE_CLIENT_ID`, `SUITE_SECRET` in `.env` / `application.yml`).
- Every request carries:
  - `X-Suite-Client: <client id>`
  - `X-Suite-Timestamp: <unix seconds>` (rejected if more than 300 s from server time)
  - `X-Suite-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" with the shared secret>` (body is empty string for GET)
- Secrets rotate without downtime: each side accepts the current and previous secret for 24 h after rotation.
- Suite endpoints sit **outside** the `auth` and CSRF groups (like SCIM today) and are gated only by the signature middleware. They must be added to the `UNAUTHENTICATED_BY_DESIGN` list in the authorisation tests.
- mTLS is not needed for the same-server install. If a bank ever splits the products across hosts, mTLS is recommended in addition to (not instead of) the signature.

### 3.3 Event envelope

```json
{
  "id": "0193f2c4-7b1e-7c2a-9d51-3f0e4a8b9c10",
  "type": "vendor.handed_over",
  "version": 1,
  "occurred_at": "2026-10-31T09:15:00+01:00",
  "source": "itg",
  "tenant": "kano-heritage-bank",
  "subject": "itg:vendor:VEN-0042",
  "correlation_id": "0193f2c4-0000-7000-8000-000000000001",
  "actor": { "email": "ciso@kanoheritage.ng", "name": "Amina Bello" },
  "data": { }
}
```

- `id`: UUIDv7, generated by the producer. **The idempotency key.**
- `type`: `<entity>.<past-tense verb>`; the catalogue is in §5.
- `version`: integer per `type`. Additive changes keep the version; removing or renaming a field bumps it, and the consumer must accept version N and N-1 for one release.
- `tenant`: the organisation slug (C5), never a database ID.
- `subject`: the suite reference of the thing the event is about (§3.4).
- `correlation_id`: ties a request to its reply (e.g. a C10 review request to its decision).
- `actor`: who caused it, for the receiving side's activity log. May be `null` for system events.

Response: `202 Accepted` with `{ "received": "<id>" }`. A duplicate `id` also returns `202` (not an error). `400` for schema violations, `401` for signature failures, `409` if the tenant is unknown, `422` if `data` fails validation. Only `5xx` and network errors are retried.

### 3.4 Suite references

Database IDs never cross the boundary: the two databases number rows independently. Every cross-product pointer is a **suite reference**:

```
<product>:<entity>:<business code>
itg:change:CHG-2026-0042
isec:incident:INC-2026-0107
itg:vendor:VEN-0042
itg:asset:AST-00311
itg:application:APP-CBS-01
itg:ropa:PA-0019
isec:threat-model:TM-0008
```

The business codes already exist (`change_code`, `incident_id_code`, `vendor_code`, `asset_id_code`, EA `code` columns). Each table that holds a foreign reference gets a `suite_ref varchar(96)` column (indexed), replacing the integer FK. Example: `config_drift_events.change_request_id` → `config_drift_events.change_suite_ref` (C8, as the Info-Sec checklist says).

Deep links: each product exposes `GET /suite/open?ref=<suite ref>` (inside `auth`), which redirects to the record's page. This is what the "Open in IT Governance" / "Open in Information Security" sidebar links and every cross-product reference in the UI use.

### 3.5 Delivery: outbox and inbox

- **Outbox (producer).** Writing the business change and writing the event row happen in the same database transaction (`suite_outbox` table: `id`, `type`, `version`, `tenant`, `subject`, `payload json`, `status`, `attempts`, `next_attempt_at`, `last_error`, `created_at`, `delivered_at`). A queue worker / scheduled job delivers pending rows.
- **Retry.** Exponential backoff: 30 s, 2 min, 10 min, 30 min, 1 h, then hourly up to 72 h. After 72 h the row goes to `failed` and appears on an admin page ("Suite → Delivery failures") with a **Retry** button. Ordering is per `subject`: a later event for the same subject is held until the earlier one is delivered.
- **Inbox (consumer).** `suite_inbox` table: `id` (primary key = event id), `type`, `received_at`, `processed_at`, `result`. Insert first; if the insert hits the primary key, the event is a duplicate — return `202` and do nothing.
- **Handlers are idempotent on business keys too** (as `OpenVulnerability` already is on `vuln_id_code`), because an operator may replay a failed event by hand.

### 3.6 When the peer is absent or down

Each product must work alone. Rules:

1. `config/suite.php` → `enabled` false (no peer licensed): no outbox rows are written, suite links are hidden, and every flow falls back to the local behaviour listed per contract.
2. Peer configured but unreachable: events queue in the outbox; **queries time out at 3 s** and the UI shows a "Information Security is unavailable — showing last known data" banner with the cached copy, never a 500.
3. Each contract has its own toggle (`suite.contracts.<key>`, env `SUITE_C<n>`), following the existing `ea.contracts.*` pattern, so a bank can switch one flow off without touching the rest.

### 3.7 Audit trail

Both sides write to `spatie/laravel-activitylog` (or the Java equivalent) with log name `suite`, recording event id, type, subject and outcome — on send, on receipt, and on failure. This replaces today's `AuditLogger::logBare('contract.i2.open', …)` calls. Bank auditors need to see that, for example, a critical vendor was handed over and assessed, across two systems.

## 4. Roles and ownership

| Data | System of record | Other product holds |
|---|---|---|
| Organisations, users, roles | Each product (synced by C5) | — |
| IT assets, applications | IT Governance | Read-only mirror (C4, C14) |
| Vendors (register, contracts, onboarding docs) | IT Governance | Mirror with security fields (C9) |
| Vendor security assessment, CTPRA, security rating | Information Security | Rating and status only (C9) |
| Change requests | IT Governance | Reference + security review (C8, C10) |
| Incidents, vulnerabilities, breaches | Information Security | Summary figures, CAPA link (C3, C6) |
| CAPA | IT Governance | Status link (C3) |
| DR runbooks and exercises | IT Governance | Invocation status (C13) |
| Cyber KRIs | Information Security | Readings (C12) |
| Security architecture (zones, threat models, control mappings) | Information Security | Read-only mirror (C14) |
| ROPA (processing activities) | IT Governance (DPO) | Mirror + security measures (C15) |
| Policies and documents | Each product (own instance) | — (no contract) |

## 5. Contract catalogue

Each contract lists: direction, trigger, message, payload, consumer behaviour, local fallback, toggle, and the code it replaces.

### C1 — CVE matched to a component → vulnerability

- **Direction:** ITG → ISec (event `vulnerability.cve_matched`).
- **Trigger:** `SyncCveFeed` matches a CVE to a deployed `TechComponent` (today fires `CveMatchedToComponent`).
- **Payload `data`:**
  ```json
  {
    "cve_id": "CVE-2026-12345",
    "cvss": 9.1,
    "feed_severity": "CRITICAL",
    "published_at": "2026-10-28T00:00:00Z",
    "component": { "ref": "itg:tech-component:TC-0213", "name": "Apache Tomcat 9.0.70", "cpe": "cpe:2.3:a:apache:tomcat:9.0.70" },
    "applications": [ { "ref": "itg:application:APP-CBS-01", "name": "Core Banking" } ],
    "assets": [ { "ref": "itg:asset:AST-00311", "name": "cbs-app-01" } ]
  }
  ```
- **Consumer:** create or update a `Vulnerability` keyed on `vuln_id_code = EA-CVE-<component code>-<cve>` (the component **code**, not the database id used today). Never reopen a vulnerability a human has moved to remediated/verified — the existing rule in `OpenVulnerability` carries over unchanged. Severity mapping and due-date rules stay in Info-Sec.
- **Fallback (ISec alone):** no CVE feed from EA; vulnerabilities come from scanners/manual entry. **(ITG alone):** CVE matches are shown on the component only.
- **Toggle:** `suite.contracts.cve_to_vulnerability` (`SUITE_C1`). Replaces `ea.contracts.cve_to_vulnerability` in ITG.
- **Replaces:** `Listeners/Ea/OpenVulnerability` (moves to Info-Sec as the event handler); the `CveMatchedToComponent` listener in ITG becomes "write outbox row".

### C2 — Incident declared → blast radius

- **Direction:** ISec → ITG (synchronous query), result stored on the incident in ISec.
- **Trigger:** an `Incident` is created (today `IncidentDeclared`, create-only).
- **Request:** `GET /api/suite/v1/blast-radius?tenant=<slug>&system=<name or code>&system=<…>&depth=2`
- **Response `200`:**
  ```json
  {
    "matched_application": { "ref": "itg:application:APP-CBS-01", "name": "Core Banking" },
    "match": "exact_unique",
    "affected": [
      { "ref": "itg:application:APP-MOB-01", "name": "Mobile Banking", "kind": "application", "rto_minutes": 60 },
      { "ref": "itg:business-service:BS-PAY", "name": "Payments", "kind": "business_service", "rto_minutes": 30 },
      { "ref": "itg:vendor:VEN-0042", "name": "Interswitch", "kind": "third_party" }
    ],
    "computed_at": "2026-10-31T09:15:02+01:00"
  }
  ```
  `204` when no application matches uniquely. Matching stays conservative, as in `AttachBlastRadius::matchApplication`: exact name or code, and only when exactly one application matches. A wrong blast radius during a live incident is worse than none.
- **Consumer (ISec):** store the response on the incident (`tags` gets `ea_application:<ref>`, `affected_systems` gets the list), so it survives ITG being slow or down later. Call is made by a queued job immediately after create, **with a 3 s timeout and one retry at 60 s**; the 30-minute CBN response window is the reason this is a query, not an eventual event.
- **Fallback (ISec alone):** `affected_systems` stays as the responder typed it.
- **Toggle:** `SUITE_C2`.
- **Replaces:** `Listeners/Core/AttachBlastRadius` (logic moves to an ITG query endpoint over `BlastRadiusService::changeImpact`).

### C3 — Customer-impacting incident → CAPA

- **Direction:** ISec → ITG (event `incident.customer_impact_flagged`), status back ITG → ISec (event `capa.opened`, `capa.closed`).
- **Trigger:** `Incident` saved with `customer_impact = true` (today `IncidentSaved`).
- **Payload `data`:** `incident_code`, `title`, `severity`, `detected_at`, `customer_impact_summary`, `lead_investigator` (email), `assigned_to` (email).
- **Consumer (ITG):** open one CAPA issue per incident (`source_type = 'suite_incident'`, `source_ref = isec:incident:<code>`), framework/provisions from `config('capa.auto_trigger')` (CBN CPR §6.0), same rules as `OpenCustomerImpactCapa` today — re-sending never opens a second. Owner resolved by email via C5; if not found, owner left empty and the CAPA lead is notified. Replies `capa.opened` with the CAPA code so the incident shows "CAPA ISS-2026-0311 (open) — Open in IT Governance".
- **Fallback (ISec alone):** incident shows a "Customer impact — log a CAPA in your issue tracker" prompt. ISec keeps its own Issues module for findings; CAPA stays in ITG per the checklist.
- **Toggle:** `SUITE_C3` (and `capa.auto_trigger.incident_customer_impact` on ITG must also be on).
- **Replaces:** `Listeners/Core/OpenCustomerImpactCapa`.

### C4 — Asset register mirror

- **Direction:** ITG → ISec (events `asset.created`, `asset.updated`, `asset.retired`); security attributes back ISec → ITG (`asset.security_attributes_changed`).
- **Payload (ITG → ISec):** `asset_code`, `name`, `asset_type`, `category`, `criticality`, `status`, `owner` (email), `custodian` (email), `department`, `location`, `hostname`, `ip_address`, `vendor`, `version`, `end_of_life`, `data_classification`.
- **Payload (ISec → ITG):** `asset_code`, `pci_scope`, `pci_scope_note`, `open_critical_vulnerabilities`, `last_scanned_at`.
- **Consumer (ISec):** upsert into its `assets` table on `asset_id_code`, `source = 'suite'`. Mirror rows are read-only in the ISec UI except the security attributes. **(ITG):** stores the security attributes as display-only columns.
- **Initial load:** `GET /api/suite/v1/assets?tenant=<slug>&cursor=<…>` paged (500 per page) for first connection and for re-sync.
- **Fallback (ISec alone):** asset register fully editable, as today.
- **Toggle:** `SUITE_C4`.
- **Owner (D1, resolved):** IT Governance owns the IT asset register. Information Security keeps Asset Management only as the C4 mirror plus its security attributes; it does not create or edit register records while C4 is on.

### C5 — Tenants, users and roles

- **Tenants:** `organizations.slug` is the tenant key in every envelope. Both products must hold the same slug for the same bank; set at installation. A tenant mismatch returns `409`.
- **Users:** matched across products by **email** (lower-cased). Where the bank uses SSO/SCIM (already in the platform layer), both products are provisioned from the same IdP and nothing else is needed. Without SSO: event `user.provisioned` / `user.deactivated` from whichever product the admin uses, carrying `email`, `name`, `job_title`, `department`, `active`. Roles are **not** synced — each product grants its own roles; a deactivation in either product deactivates in both.
- **Toggle:** `SUITE_C5` (user sync only; tenant check is always on).

### C6 — Security figures for dashboards, reports and board packs

- **Direction:** ITG pulls from ISec (synchronous query).
- **Request:** `GET /api/suite/v1/security-summary?tenant=<slug>&period=2026-Q3`
- **Response:**
  ```json
  {
    "period": "2026-Q3",
    "vulnerabilities": { "open_by_severity": {"critical": 3, "high": 17, "medium": 42, "low": 60}, "overdue": 9, "mean_days_to_remediate": 21.4 },
    "incidents": { "opened": 12, "closed": 10, "major": 1, "customer_impacting": 2 },
    "data_breaches": { "count": 0, "ndpc_notified_within_72h": 0 },
    "csat": { "last_score": 3.2, "last_submitted_at": "2026-07-15" },
    "generated_at": "2026-10-31T09:15:00+01:00"
  }
  ```
- **Consumer (ITG):** `BoardPackBuilder`, `DashboardController`, `ReportController`, `ReportFigures` read this instead of the `Vulnerability`/`Incident`/`DataBreach` models. Board pack runs **snapshot** the response into the run record so a signed pack never changes afterwards.
- **Fallback (ITG alone):** the security section of the board pack is omitted with the note "Information Security not connected".
- **Toggle:** `SUITE_C6`.

### C7 — Security evidence for regulatory returns

- **Direction:** ISec → ITG (event `evidence.sealed`) plus query `GET /api/suite/v1/evidence?tenant=&control=&framework=`.
- **Purpose:** ITG's Returns Centre (ITSB maturity, NDPC CAR, localisation gap) cites security evidence that will live in ISec. The returns engine refuses signing below `ea.returns.minimum_evidence_confidence` and counts unsealed evidence as zero — that rule must keep working with remote evidence.
- **Payload:** `evidence_ref`, `title`, `control_refs[]`, `framework`, `seal_state` (`sealed` / `stale` / `unsealed`), `sealed_at`, `confidence` (0–1), `download_url` (signed, 15-minute expiry).
- **Consumer (ITG):** stores a citation with the seal state **as captured** (same snapshot rule as today), never the file itself.
- **Toggle:** `SUITE_C7`.
- **CSAT (D3, resolved):** the CBN-CSAT return belongs to Information Security and is compiled there against local evidence, so C7 does not carry CSAT evidence. C7 serves only ITG's returns (ITSB maturity, NDPC CAR, localisation gap).

### C8 — Configuration drift ↔ authorised change

- **Direction:** ISec queries ITG; ITG notifies ISec when a change completes.
- **Query:** `GET /api/suite/v1/changes/authorising?tenant=<slug>&asset=<asset code>&at=<ISO time>` — returns the approved/implemented change(s) whose scheduled window covers `at` and whose `affected_asset_ids` include the asset.
  ```json
  { "changes": [ { "ref": "itg:change:CHG-2026-0042", "title": "Patch Tomcat on CBS app tier", "status": "implemented", "window": ["2026-10-30T22:00:00+01:00", "2026-10-31T02:00:00+01:00"] } ] }
  ```
- **Event ITG → ISec:** `change.implemented` (`change_code`, `asset_codes[]`, `actual_start_at`, `actual_end_at`) so ISec can auto-mark open drift on those assets in that window as "authorised — pending baseline update".
- **Schema change (ISec):** `config_drift_events.change_request_id` → `change_suite_ref varchar(96) null` (driver-agnostic migration: add column, backfill, drop old).
- **Fallback (ISec alone):** drift resolution note is free text, as before the link existed.
- **Toggle:** `SUITE_C8`.

### C9 — Vendor hand-off, security assessment and rating

The vendor flow agreed on 22 Oct 2026: IT registers vendors and collects documents; Information Security assesses risk; critical vendors get the Cybersecurity Third-Party Vendor Risk Assessment (CTPRA).

| Step | Message | Direction |
|---|---|---|
| 1. Documents complete | `vendor.handed_over` | ITG → ISec |
| 2. Vendor details change later | `vendor.updated` | ITG → ISec |
| 3. Assessment started | `vendor_assessment.started` | ISec → ITG |
| 4. Assessment completed / rating changes | `vendor_assessment.completed`, `vendor_rating.changed` | ISec → ITG |
| 5. Vendor offboarded | `vendor.offboarded` | ITG → ISec |

- **`vendor.handed_over` payload:** `vendor_code`, `name`, `category`, `tier`, `domain`, `country`, `services_provided`, `data_access_level`, `processes_personal_data`, `dpa_signed_at`, `material_outsourcing`, `regulator_notified_at`, `contract_start`, `contract_end`, `owner` (email), `contacts[]`, `criticality` (`critical` / `high` / `medium` / `low`), `documents[]` (`title`, `type`, `received_at`, `download_url` signed, 15-minute expiry), `ropa_refs[]` (C15).
- **Consumer (ISec):** upsert `Vendor` on `vendor_code` with `source = 'suite'`; vendor creation in the ISec UI is disabled except with a "local only" flag (checklist §2). If `criticality = critical`, open a `VendorAssessment` with `assessment_type = 'ctpra'`; otherwise the standard security assessment.
- **`vendor_assessment.completed` payload:** `vendor_code`, `assessment_ref`, `assessment_type`, `overall_score`, `residual_rating`, `completed_at`, `next_review_date`, `key_findings[]` (titles only, no detail), `rating` (`provider`, `rating_value`, `grade`, `captured_at`) when a `TprmSecurityRating` exists.
- **Consumer (ITG):** stores the residual rating, grade and next review date on the vendor (display-only) and blocks moving a critical vendor to `active` until either (a) a completed CTPRA has been received, or (b) an **approved, unexpired vendor risk exception** has been received (C9a below).
- **Fallback (ITG alone):** "Security assessment" tab shows a manual rating field, and the risk exception in C9a runs in ITG's own Workflow Studio. **(ISec alone):** vendors are created locally.

#### C9a — Vendor risk exception: CISO conditional approval before CTPRA (D6, resolved)

A CISO may approve a critical vendor before its CTPRA is complete, **only through a formal risk exception with conditional approval**, routed up a configurable approval chain. It is never a single-person override.

- **Where it lives:** Information Security (where the CTPRA, the CISO and the vendor security record are). New model `VendorRiskException`, modelled on the existing `PolicyException` (statuses `requested`, `approved`, `rejected`, `revoked`, `expired`; maximum duration by risk rating).
- **Fields:** `exception_code`, `vendor_code`, `risk_rating` (critical / high / medium / low), `business_justification`, `risk_description`, `compensating_controls[]` (e.g. no production data until CTPRA complete, VPN-only access, enhanced monitoring, contractual right to audit), `conditions[]` (what the vendor or business must do and by when), `ctpra_due_date`, `requested_by` (CISO), `starts_at`, `expires_at`, `status`, `approvals[]`, `revoked_reason`.
- **Duration limits:** `expires_at` may not exceed `ctpra_due_date`, nor a per-rating maximum (default critical 3 months, high 6 — the same defaults as `PolicyException::MAX_MONTHS`), configurable per bank in `config/tprm.php`.
- **Approval workflow:** a new generic process `vendor.risk_exception` in `App\Services\Approvals\ApprovalProcesses`, so it runs on the existing `ApprovalEngine` and its stages are configured per bank in **Workflow Studio** (approver types already supported: role, named person, job title, record owner, permission; stages can be limited by condition).
  - Initiator: the CISO (permission `request vendor exceptions`). The initiator can never approve their own request.
  - Stage condition fields: `risk_rating`, `vendor_tier`, `processes_personal_data`, `material_outsourcing`.
  - Default when the bank has configured nothing: CISO request → Chief Risk Officer → MD/CEO, plus Board Risk Committee notification when `material_outsourcing = true`. Banks change the chain in Workflow Studio without code.
- **Events to ITG:** `vendor_risk_exception.approved` (`exception_code`, `vendor_code`, `starts_at`, `expires_at`, `conditions[]`, `approved_by[]` emails), `vendor_risk_exception.revoked`, `vendor_risk_exception.expired`. ITG lets the vendor go `active` with a visible "Conditional — risk exception RE-2026-004 until 31 Jan 2027" badge.
- **Lifecycle:**
  - CTPRA completed → exception closes automatically (`vendor_assessment.completed` supersedes it); badge removed.
  - 14 and 3 days before expiry → reminder to the CISO, vendor owner and last approver.
  - Expiry or revocation without a completed CTPRA → ITG moves the vendor to `suspended` (new contracts and renewals blocked, existing contract unaffected) and notifies the vendor owner and procurement. Continuing needs a new exception through the same workflow; an approved exception's dates cannot be edited.
- **Reporting:** open exceptions, exceptions expiring within 30 days, and vendors activated under exception appear in the vendor register and in the C6 security summary (`vendor_exceptions: {open, expiring_30d, expired_unresolved}`) so they reach board packs.
- **Toggle:** `SUITE_C9`.

### C10 — Security review of a change request

- **Direction:** ITG → ISec (`change.security_review_requested`), decision ISec → ITG (`change.security_review_decided`).
- **Trigger:** a change reaches an approval stage with key `security_review` (seeded today for normal changes, approver job title `CISO`, 48 h SLA).
- **Request payload:** `change_code`, `title`, `change_type`, `category`, `priority`, `risk_level`, `risk_score`, `description`, `implementation_plan`, `backout_plan`, `test_plan`, `outage_required`, `asset_codes[]`, `affected_services[]`, `scheduled_start_at`, `scheduled_end_at`, `requester` (email), `due_at` (stage SLA).
- **Consumer (ISec):** opens a "Change security review" item in a review queue for users with the security reviewer role. Reviewer sees the open vulnerabilities and drift on the listed assets (local data) next to the change.
- **Decision payload:** `change_code`, `decision` (`approved` / `approved_with_conditions` / `rejected` / `more_info`), `conditions[]`, `comment`, `reviewer` (email), `decided_at`.
- **Consumer (ITG):** records it as the `ChangeApproval` for the `security_review` stage (the reviewer appears as approver by email). `more_info` returns the change to the requester. A later `change.updated` after `more_info` re-sends the request with the same `correlation_id`.
- **Status query:** `GET /api/suite/v1/change-reviews/<change_code>?tenant=` for the ITG change page.
- **Fallback (ITG alone):** the stage is approved in ITG by the CISO user, as today.
- **Toggle:** `SUITE_C10`.

### C11 — Reserved

Not used. Kept free so contract numbers in the prune checklists stay stable.

### C12 — Cyber KRIs

- **Direction:** ISec → ITG (events `kri.defined`, `kri.reading_recorded`, `kri.breached`).
- **`kri.defined`:** `kri_code`, `name`, `description`, `category`, `unit`, `direction`, `threshold_green`, `threshold_amber`, `threshold_red`.
- **`kri.reading_recorded`:** `kri_code`, `period`, `value`, `status` (`green`/`amber`/`red`), `recorded_at`.
- **Consumer (ITG):** upsert into `kris` with `source = 'suite'` (read-only in ITG) and append readings, so the ITG KRI dashboards and board packs show cyber KRIs beside IT governance KRIs without re-entry. Breaches raise the same notifications as local KRIs.
- **Fallback (ITG alone):** cyber KRIs can be defined locally.
- **Toggle:** `SUITE_C12`.

### C13 — DR invocation (D5, resolved)

**Who invokes depends on the cause of the outage:**

| Cause | Invoking authority | Where invoked | Examples |
|---|---|---|---|
| **Malicious**: systems compromised, data integrity lost, or environment unsafe to operate | Information Security | ISec, from the security incident | ransomware, intrusion, tampered data, active attacker in the network |
| **Non-malicious**: infrastructure, hardware or environmental failure; systems offline but not compromised | IT / Operations | ITG, directly in the DR module | storage or power failure, data-centre outage, hardware fault, failed upgrade |

ITG always **executes** the runbook (DR runbooks and exercises stay in IT Governance); the cause decides who has the authority to start it.

**Data changes (ITG):** `dr_exercises` gains `mode` (`exercise` / `invocation`), `cause` (`malicious` / `non_malicious`), `invoked_by` (user), `invoking_product` (`itg` / `isec`), `source_ref` (e.g. `isec:incident:INC-2026-0107` or an ITSM ticket reference), and new status values `invoked` and `escalated`. New permissions: `invoke dr` (IT/Ops roles, in ITG) and `invoke dr security` (incident commander / CISO roles, in ISec).

**Path A — malicious (ISec invokes):**
- **Trigger:** incident commander clicks "Invoke DR" on a security incident in ISec (never automatic). ISec requires the commander to confirm the **recovery environment is not compromised** (DR site and backups checked clean, or restore from a known-good point dated before the compromise) — restoring into a compromised environment repeats the incident.
- **Event ISec → ITG:** `dr.invocation_requested` with `incident_code`, `severity`, `cause: "malicious"`, `affected_application_refs[]` (from C2), `recovery_point_before` (timestamp), `clean_environment_confirmed: true`, `invoked_by` (email), `reason`.
- **Consumer (ITG):** starts the invocation immediately (no second approval — authority sits with Information Security for this cause), selects the runbook(s) linked to the affected applications and assigns them to the DR owner, who executes. If no runbook matches, the DR owner is paged to pick one.
- **Progress ITG → ISec:** `dr.invocation_started`, `dr.invocation_step_completed`, `dr.invocation_completed` (`runbook`, `step`, `completed_at`, `rto_minutes`, `elapsed_minutes`) so the incident timeline shows DR progress against the RTO clock.

**Path B — non-malicious (IT/Ops invokes):**
- **Trigger:** a user with `invoke dr` starts an invocation in ITG's DR module, optionally referencing an ITSM ticket (existing `ItsmConnector`). No dependency on ISec.
- **Event ITG → ISec (awareness only):** `dr.invocation_started` with `cause: "non_malicious"`, so the SOC knows failover traffic and unusual activity are expected.
- **Escalation:** if IT/Ops or the SOC finds signs of compromise during a non-malicious invocation, either side marks it **escalated to security**. ITG sends `dr.escalated_to_security` (invocation ref, observations, escalated_by); ISec opens a linked security incident and the invocation's `cause` becomes `malicious`. From then on Information Security holds the authority (Path A rules, including the clean-environment check), and ITG shows the invocation as `escalated` until ISec confirms how to continue.

- **Fallback (ISec alone):** "Invoke DR" is hidden; the incident timeline has a free-text DR entry. **(ITG alone):** Path B only; cause can still be recorded as malicious for reporting.
- **Toggle:** `SUITE_C13`.

### C14 — Security architecture sync

Ownership per both checklists: Information Security owns the write surface for security architecture; IT Governance keeps the data in EA as a read-only mirror. Security architecture entities point at EA applications and components, so the application catalogue flows the other way.

- **ITG → ISec:** `ea_application.upserted` / `ea_application.retired`, `ea_component.upserted` / `ea_component.retired` (`code`, `name`, `type`, `lifecycle`, `criticality`, `owner` email, `hosting`, `data_flows[]` codes). Initial load via `GET /api/suite/v1/ea/applications?tenant=&cursor=`.
- **ISec → ITG:** `security_zone.upserted` (`code`, `name`, `trust_level`, `description`), `zone_assignment.changed` (`zone_code`, `application_ref`, `assignment_type`), `threat_model.upserted` (`code`, `name`, `subject_ref`, `methodology`, `summary`, `risk_score`, `status`), `control_mapping.changed` (`component_ref`, `control_ref`, `framework`, `coverage`, `notes`), `dpia.decided` (see C15).
- **Consumer (ITG):** upsert into `ea_zones`, `ea_zone_assignments`, `ea_threat_models`, `ea_control_mappings` with `source = 'suite'`; edit actions hidden in the "Security Architecture & Assurance" workspace, replaced with "Open in Information Security". **An inbound change must fire `ArchitectureEntityChanged` so quality seals break exactly as they do for local edits (B3).**
- **Order of work:** the ITG checklist says the sidebar entry is removed only after C14 exists — this contract is a prerequisite for that step.
- **Toggle:** `SUITE_C14`.

### C15 — ROPA mirror and security measures

- **Direction:** ITG (DPO, system of record) → ISec (`processing_activity.upserted`, `processing_activity.retired`); ISec → ITG (`processing_activity.security_measures_updated`, `dpia.decided`, `data_breach.linked`).
- **`processing_activity.upserted`:** `code`, `name`, `purpose`, `lawful_basis`, `data_subject_categories[]`, `personal_data_categories[]`, `special_categories` (bool), `recipients[]`, `processor_vendor_refs[]` (C9), `cross_border` (bool), `transfer_countries[]`, `retention_period`, `system_refs[]` (applications, C14), `dpo_owner` (email), `consent_purpose_codes[]`.
- **`processing_activity.security_measures_updated`:** `code`, `technical_measures[]`, `organisational_measures[]`, `encryption_at_rest`, `encryption_in_transit`, `access_control_summary`, `last_reviewed_at`, `reviewer` (email).
- **`dpia.decided`:** `dpia_code`, `processing_activity_code`, `risk_score`, `risk_band`, `dpo_decision`, `decided_on`, `mitigation_plan`.
- **`data_breach.linked`:** `breach_ref`, `processing_activity_codes[]`, `detected_at`, `ndpc_notification_due_at` (72 h), `notified_at`.
- **Rights:** DPO has read/write on the register in ITG; in ISec the DPO role edits **security fields only** (22 Oct decision).
- **Consumer (ISec):** mirror table read-only except security measures; data-breach screen lets the responder tick affected processing activities from the mirror, which drives the NDPC timeline.
- **Consumer (ITG):** shows measures, DPIA outcome and linked breaches on the ROPA record; feeds `NdpcCarMapper`.
- **Toggle:** `SUITE_C15`.
- **Prerequisite:** the `processing_activities` table does not exist yet in either repo (ROPA data is spread across EA data flows and `ConsentPurpose`). It is listed under "Add" in the IT-Governance checklist.

## 6. Endpoint summary

| Method | Path | Served by | Contracts |
|---|---|---|---|
| POST | `/api/suite/v1/events` | both | all events |
| GET | `/api/suite/v1/health` | both | connection test (returns product, version, contracts enabled) |
| GET | `/api/suite/v1/blast-radius` | ITG | C2 |
| GET | `/api/suite/v1/assets` | ITG | C4 initial load |
| GET | `/api/suite/v1/security-summary` | ISec | C6 |
| GET | `/api/suite/v1/evidence` | ISec | C7 |
| GET | `/api/suite/v1/changes/authorising` | ITG | C8 |
| GET | `/api/suite/v1/change-reviews/{change_code}` | ISec | C10 |
| GET | `/api/suite/v1/ea/applications` | ITG | C14 initial load |
| GET | `/api/suite/v1/ropa` | ITG | C15 initial load |
| GET | `/suite/open?ref=` | both (inside `auth`) | deep links |

## 7. Event catalogue

| Type | Producer | Contract |
|---|---|---|
| `vulnerability.cve_matched` | ITG | C1 |
| `incident.customer_impact_flagged` | ISec | C3 |
| `capa.opened`, `capa.closed` | ITG | C3 |
| `asset.created`, `asset.updated`, `asset.retired` | ITG | C4 |
| `asset.security_attributes_changed` | ISec | C4 |
| `user.provisioned`, `user.deactivated` | both | C5 |
| `evidence.sealed` | ISec | C7 |
| `change.implemented` | ITG | C8 |
| `vendor.handed_over`, `vendor.updated`, `vendor.offboarded` | ITG | C9 |
| `vendor_assessment.started`, `vendor_assessment.completed`, `vendor_rating.changed` | ISec | C9 |
| `vendor_risk_exception.approved`, `vendor_risk_exception.revoked`, `vendor_risk_exception.expired` | ISec | C9a |
| `change.security_review_requested`, `change.updated` | ITG | C10 |
| `change.security_review_decided` | ISec | C10 |
| `kri.defined`, `kri.reading_recorded`, `kri.breached` | ISec | C12 |
| `dr.invocation_requested` | ISec | C13 |
| `dr.invocation_started`, `dr.invocation_step_completed`, `dr.invocation_completed`, `dr.escalated_to_security` | ITG | C13 |
| `ea_application.upserted`, `ea_application.retired`, `ea_component.upserted`, `ea_component.retired` | ITG | C14 |
| `security_zone.upserted`, `zone_assignment.changed`, `threat_model.upserted`, `control_mapping.changed` | ISec | C14 |
| `processing_activity.upserted`, `processing_activity.retired` | ITG | C15 |
| `processing_activity.security_measures_updated`, `dpia.decided`, `data_breach.linked` | ISec | C14/C15 |

## 8. Build plan

The common plumbing is built once and shared by both products. Under D2 that happens in the Spring Boot stack (for example a shared `suite-core` library), not on the Laravel base `main`. Contracts are then built in the order the pruning needs them.

| Phase | Scope | Unblocks |
|---|---|---|
| S0 — Plumbing (in the Spring Boot products, per D2) | `config/suite.php` with per-contract toggles; signature middleware; `routes/suite.php`; `suite_outbox` / `suite_inbox` tables + delivery job + retry; `SuiteClient`; `SuiteWebhookController` with handler registry by `type`; `/health`; `/suite/open`; admin "Delivery failures" page; activity log `suite`; tests including a two-app loopback test (one install talking to itself under two client IDs) | everything |
| S1 — Identity & reference data | C5, C4, C14 (application catalogue direction only) | every contract that names users, assets or applications |
| S2 — Cuts needed to remove CSAT/SecOps from ITG | C1, C2, C3, C6 | pruning SecOps/CSAT out of ITG without losing board-pack figures, CAPA or CVE flow |
| S3 — Cuts needed to remove Change/BCP/EA from ISec | C8, C10, C13, C14 (security entities) | pruning Change, BCP, EA out of ISec |
| S4 — Vendor and data protection | C9, C15 (after `processing_activities` exists), C12 | vendor flow of 22 Oct; ROPA |
| S5 — Returns | C7 | Returns Centre with remote evidence |

Definition of done for each contract (in addition to the repo rule — PHP suite, `migrate:fresh --seed` and `npm run build` all pass):

1. Feature tests on both sides: producer writes the outbox row in the same transaction; consumer is idempotent on event id **and** business key; schema violation returns `422`; bad signature returns `401`.
2. Peer-down test: producer keeps working, outbox retries, UI shows the "unavailable" banner for queries.
3. Toggle-off test: the local fallback in §5 works.
4. Demo data: both demo tenants (`kano-heritage-bank`, `first-bank-nigeria` slugs) seeded so the flow is visible end-to-end when both apps run locally.
5. Activity log entries on send, receipt and failure.

## 9. Decisions needed before or during build

| # | Decision | Why it matters | Suggested answer |
|---|---|---|---|
| D1 | Which product owns the IT asset register? Both checklists keep Asset Management. | Two editable registers at one bank will drift apart; vulnerabilities, drift and PCI scope all point at assets. | **Resolved 31 Oct 2026:** IT Governance owns the register. Information Security mirrors it (C4) and owns only the security attributes. |
| D2 | Java rewrite first, or prune Laravel now? (open since 25 Oct) | Determines whether S0 is written in Laravel or Spring Boot. | **Resolved 31 Oct 2026:** Java Spring Boot. S0 and the contracts are built in the Spring Boot products; no suite plumbing is built in Laravel. |
| D3 | Where is the CBN-CSAT return compiled? The Info-Sec checklist removed `app/Services/Returns/**` **except** `CsatMapper`, but `CsatMapper` extends `ReturnMapper` and is driven by `ReturnCompiler`. | As written, CSAT returns would stop compiling in Info-Sec after pruning. | **Resolved 31 Oct 2026:** CBN-CSAT belongs to Information Security. Info-Sec keeps a slim returns engine (`ReturnCompiler`, `ReturnMapper`, the citation/seal classes and `CsatMapper`) for the CSAT return only; ITG keeps the full Returns Centre. The Info-Sec checklist is corrected. |
| D4 | Deployment topology | Affects mTLS and network set-up at install. | **Resolved 31 Oct 2026:** when bought together, both products are deployed on the same server (two apps, two databases). HMAC signature stays mandatory; mTLS not needed. See §3.2. |
| D5 | Who can invoke DR (C13)? | Accountability during a live event. | **Resolved 31 Oct 2026:** by cause. Information Security for malicious incidents (compromise, loss of integrity, unsafe environment); IT/Operations for non-malicious infrastructure, hardware or environmental failures. ITG executes the runbook in both cases. See C13. |
| D6 | Can a CISO activate a critical vendor before the CTPRA is complete (C9)? | Regulator will ask who approved an unassessed critical vendor. | **Resolved 31 Oct 2026:** yes, only through a formal risk exception / conditional approval, routed through an approval chain to senior management that each bank configures in Workflow Studio. See C9a. |

## 10. Mapping to each stack

| Concern | Laravel 13 (current) | Spring Boot (target stack, D2) |
|---|---|---|
| Routes | `routes/suite.php`, `api` middleware + `suite.signature` | `@RestController` under `/api/suite/v1`, a `OncePerRequestFilter` for the signature |
| Outbox write | in the same `DB::transaction()` as the model save | same `@Transactional` method as the repository save |
| Delivery | queued job + scheduler (`schedule:run`) | `@Scheduled` poller or Spring Modulith event publication registry |
| Handlers | `SuiteWebhookController` dispatching to one handler class per `type` | `Map<String, SuiteEventHandler>` bean registry |
| Toggles | `config('suite.contracts.*')` | `@ConfigurationProperties("suite.contracts")` |
| Audit | `activity('suite')` | Envers or an `audit_log` table written by an aspect |

Because events, endpoints and payloads are identical in both columns, a Laravel product and a Java product can run as a pair during a phased rewrite.
