# ATH-VAL-005 — Security Operations: End-to-End Validation Report

**Module:** Security Operations (sidebar group 5 of the platform validation programme). It covers:
- Dashboard (`security-ops.dashboard`)
- Vulnerabilities, Vulnerability Prioritiser, Vulnerability Tickets
- Threat Advisories
- Incidents and Response Procedures
- SIEM Integrations, Security Alerts
- Regulatory Notifications, Data Breaches

**Tenants:** First Bank (org 3), cross-checked against Kano Heritage Bank (org 2).

**Method:**
- Every page, form, table and action was exercised in the in-app browser as the First Bank CISO (Organization Admin).
- Controllers, models, services and seeders were traced, and findings were fixed and re-tested.
- Locked in by `tests/Feature/SecurityOperations/SecurityOperationsModuleTest.php` (12 tests, 206 assertions).

The four `PlatformController` pages were stubs:
- The prioritiser invented a new random EPSS score on every page load.
- "Am I affected?" was a button with no handler.
- The notification form was a raw "Incident ID" box defaulting to `1`.
- SIEM showed every bank's connectors and signals.

They now live in `SecurityOperationsController`, backed by four services in `app/Services/SecurityOps/`:
- `VulnerabilityPrioritiser`
- `NotificationDrafter`
- `SiemIngestService`
- `SecurityOpsMetrics`

Regulatory clocks are config values (`config/secops.php`), as CLAUDE.md requires for unverified regulatory dates.

| Area | Before → after |
|---|---|
| Dashboard | It read `stats.total_vulns` and `activeIncidents`, which the controller never sent, so it showed blank totals, "0 overdue" and "No active incidents" against 30 incidents → a SOC overview: exposure, KEV, SLA breach, active incidents, the alert queue, response times and running regulatory clocks |
| Prioritiser | Random EPSS (`mt_rand`) re-rolled each load, and KEV derived from that random number → CVSS × EPSS × CISA KEV × asset criticality from `epss_cache` / `kev_cache`, with SLA due dates from the bank's policy |
| Threat Advisories | Fake CVEs (`CVE-2026-1111`) and a dead "Am I affected?" button → real CVEs matched against the bank's own findings and assets |
| SIEM | Cross-tenant, read-only, and no ingest despite the "HMAC-signed webhook" subtitle → tenant-scoped, a signed webhook with replay protection and PII masking, auto-alerting, and manual "Raise alert" |
| Regulatory Notifications | Cross-tenant. Any `incident_id` accepted. Template body copied verbatim. 24h from *now* for every regulator. No approval → drafted from the incident, a per-regulator clock from detection, and Draft → In review → Approved → Submitted with segregation of duties |
| Incidents | "Advance to" never worked. Status values disagreed across the model, controller, form and seed. The add-event form offered 9 invalid types → a working lifecycle with timestamps, a root cause before closing, response procedures and notifications on the incident |
| Data Breaches | The index page **crashed** (wrong Ziggy route names). The NDPA clock started when the record was typed. Data types could not be submitted → the clock runs from discovery, with record-notification actions and working filters |
| Demo data | CVE ids `CVE-2024-3400-v63`. Only critical and high findings. Incident timelines contradicting their status. "Data breach event #3". "SIEM alert #8956 — Suspicious activity" → coherent per bank |

## 1. Security and tenancy

| # | Finding | Fix |
|---|---|---|
| S1 | `SiemIntegration` and `SiemSignal` had only the `tenant_id` alias trait, with **no tenant scope**. First Bank saw Kano's four connectors and 25 signals (verified in the browser). | Both use `BelongsToOrganization`. Signal actions bind through the scope (another bank's signal → 404). |
| S2 | `IncidentNotification` had no `organization_id` and no scope. The page listed every bank's drafts. `notifications.draft` accepted any integer `incident_id`, so a bank could draft against another bank's incident. | The column was added and backfilled from the incident (orphans dropped). Strict scope. A tenant-scoped `exists` on the incident. |
| S3 | Pickers were validated with bare `exists`: `assigned_to`, `lead_investigator_id`, `asset_ids.*`, a ticket's `vulnerability_id` and a breach's `incident_id`. Another bank's ids passed. A breach could link to another bank's incident (the follow-up `is_data_breach` update then silently missed). | Every picker uses a tenant-scoped `Rule::exists`. |
| S4 | `sort` and `direction` went straight into `orderBy` on five index pages. A bad column was a 500; the direction was unchecked. | Per-controller whitelists. |
| S5 | `notifications.draft` sat behind `view platform` only. Security Analysts, the module's users, hold only `view platform`, so a platform-permission gate would lock them out. | SecOps writes are gated on the module's own permissions: `create incidents` to draft, `edit incidents` to edit and transition, `approve incidents` to approve, `create security-alerts` to raise an alert. Connector secrets need `edit platform`. |
| S6 | Webhook secrets. | Stored with the `encrypted` cast and hidden from serialisation. Shown once on issue or rotation. |

## 2. Vulnerabilities, Prioritiser and Tickets

| # | Finding | Fix |
|---|---|---|
| V1 | Prioritiser EPSS came from `mt_rand` on every request, and "KEV" meant EPSS > 0.8. Priorities changed on refresh. | `VulnerabilityPrioritiser` scores 0-100: CVSS/10×40 + EPSS×25 + 20 if on CISA KEV (+5 for ransomware use) + the most critical linked asset (10/7/4/2). Bands are P1-P4. The formula and the bank's SLA are printed on the page. A test asserts two loads return identical rows. |
| V2 | The asset column was always "—" (it read `v.asset`/`v.host`, which do not exist). | Most critical linked asset plus count. Band and KEV filters. A "Raise ticket" link for findings without one. A "P1/P2 without a ticket" KPI. |
| V3 | `vulnerability_sla_policies` existed (Kano only) and nothing read it. | Read by the prioritiser, the dashboard, the default due date on create, and the default ticket SLA. Defaults are C 72h, H 14d, M 30d, L 90d. **KEV-listed CVEs take the critical SLA.** First Bank now has a policy too. |
| V4 | `Vulnerability::STATUSES`/`SEVERITIES` (`verified`, `closed`, `informational`) disagreed with the controller and data (`false_positive`, `info`). The index status filter offered `mitigated`, `resolved` and `closed`, which match nothing. The edit form's date input received an ISO string. | The constants match the data, with an `outstanding()` scope. Filters gain "Outstanding" and "Past SLA". The date input is fixed. A CVE format rule rejects ids like `CVE-2024-3400-v12`. |
| V5 | `remediated_at` was never stamped. | Stamped on remediation and cleared on reopen. It feeds mean time to remediate. |
| V6 | Tickets: the priority was free text (a MOVEit critical seeded as P5), with no link back to the finding. | Priority defaults from severity (KEV → P1), and the SLA from the policy. Raising a ticket moves an open finding to In progress. **Resolving needs resolution notes.** When the last ticket closes, the analyst is told to verify and remediate the finding. Remediation is not automatic, because a fix needs verifying. |
| V7 | The vulnerability page had no priority context. | A priority panel: band and score, EPSS, KEV date and ransomware use, the most critical asset, SLA due, and a "Raise remediation ticket" button. |

## 3. Threat Advisories

| # | Finding | Fix |
|---|---|---|
| T1 | "Am I affected?" had no `onClick`. | It matches the advisory's CVEs against the bank's own findings. It shows open/closed matches, links to each finding and the affected assets, and says "not affected on current evidence" when nothing matches. For advisories with no CVE it says matching is impossible and shows the IOCs. Advisories affecting open findings carry a "You are affected" badge and count. |
| T2 | Seeded advisories cited non-existent CVEs. Raw ISO dates. The source showed as "Us Cert". | Six realistic ngCERT / NITDA / CISA advisories citing real CVEs (Citrix Bleed, PAN-OS/FortiOS, MOVEit/Jenkins, regreSSHion, HTTP/2 Rapid Reset, an NDPC phishing lure). Formatted dates and a source filter. |

## 4. SIEM Integrations and Security Alerts

| # | Finding | Fix |
|---|---|---|
| I1 | No ingest path existed. | `POST /siem/ingest/{integration}` sits outside auth and CSRF, and is throttled. It is authenticated by `X-Atheris-Signature: sha256=HMAC(secret, "{timestamp}.{body}")`. Deliveries more than 5 minutes off are rejected as replays. It de-duplicates on the external id. Payload keys that look like PII (phone, BVN, NIN, account, card, email…) are masked. High and critical signals raise a Security Alert automatically. A delivery marks the connector active. A missing external id gets a generated one (the column is NOT NULL, so this would otherwise have been a 500). |
| I2 | Signals could not be acted on. | "Raise alert" (with alert or incident links shown once triaged), severity and triage filters, an expandable redacted payload, connector health, 24h volumes, ingest URLs and secret issue/rotation. |
| I3 | Alerts could only be promoted to a *new* incident. | "Link to an open incident" attaches the alert and writes an evidence event to the incident timeline. |
| I4 | Alert codes restarted at `ALR-0051` beside the seeded `FBN-ALR-050`. Incident, vulnerability and breach generators only recognised `INC-`/`VUL-`/`BRH-`, so a seeded bank got `VUL-0001`. | `GeneratesSequentialCode` continues the bank's own series (prefix and padding), counting soft-deleted rows so a code is never reissued. |

## 5. Incidents and Response Procedures

| # | Finding | Fix |
|---|---|---|
| N1 | The "Advance to" buttons PUT only `{status}` to the full update endpoint, which requires a title and severity, so **every click failed validation**. The transition map used statuses that do not exist (`reported`, `contained`, `post_incident`), so a new (`detected`) incident had no buttons at all. | Buttons call `incidents.update-status`. The map matches `Incident::STATUSES`. |
| N2 | Three different type lists (store, update/model, seed). Seeded `dos`/`data_leak` incidents could not be saved from Edit. | `Incident::TYPES` everywhere. The migration maps `dos` → `denial_of_service` and `data_leak` → `data_breach`. The factory and seeders are updated. |
| N3 | The status endpoint rejected `resolved` and `post_incident_review`. Any jump was allowed, including backwards. | Forward-only, with "reopen" into investigation from resolved, review or closed (clearing the resolved and closed times). Response timestamps are stamped the first time each stage is reached, including skipped stages. **Closing needs a root cause.** |
| N4 | The add-event form offered 11 event types; only 2 passed validation. | The form offers the validated types. |
| N5 | Procedures were loaded on Create but never stored or shown. | `incidents.response_procedure_id`. A picker on create and edit filtered by type. A "Procedure" tab with steps and escalation chain, suggesting matching procedures when none is followed. The procedure page lists the incidents following it. |
| N6 | Regulatory duties were not visible on the incident. | A "Notifications" tab: drafted notices with status and deadline, plus one-click drafting. CBN is highlighted for every incident and NDPC for personal-data breaches. |

## 6. Regulatory Notifications and Data Breaches

| # | Finding | Fix |
|---|---|---|
| R1 | Drafting copied the template body verbatim. The button said "LLM-Draft"; no LLM is attached. | `NotificationDrafter` fills `{{tokens}}` from the incident and its breach (bank, reference, type, severity, detection time in WAT, systems, owner, records, data types). Templates without tokens get an incident-facts block. The label reads "Draft from incident record". The CBN, NDPC, NFIU and NAICOM templates are rewritten in the regulators' formats. |
| R2 | Every deadline was `now() + 24h`, whatever the regulator. | Per-regulator hours (CBN 24, NDPC 72, NFIU 24, NAICOM 72, in `config/secops.php`) **from detection** (NDPC: from the breach's discovery). Countdown and overdue on every list. |
| R3 | No approval route, though the subtitle promised "Preparer → CISO → …". | Draft → In review → Approved (signed) → Submitted (regulator reference required), with return-to-draft. **The preparer cannot approve their own notification.** Approved text is locked. Submission writes a communication event to the incident timeline, noting whether it was within the deadline. **An NDPC submission marks the incident's breach as notified.** `delivery_status` became a string; the old enum had no "submitted". |
| R4 | Breaches index **crashed**: the pages called `breaches.create/index/show/store`, but the routes are `data-breaches.*`. | Route names fixed. Every module page's `route()` calls were checked against `route:list`; these four were the only misses. |
| R5 | The NDPA 72h deadline ran from when the record was created. | `data_breaches.discovered_at` (backfilled from the incident's detection). The deadline is computed from it and moves if discovery is corrected. Returns Centre counts breaches by discovery date. |
| R6 | No way to record the notification. Raw ISO dates. Wrong status map. | "Record NDPC notification" (late → a warning to document the delay, NDPA s.40(3)) and "Record notice to individuals". Links to the NDPC form. Formatted dates. |
| R7 | Create sent data types as a string to an `array` rule, so **any breach with data types failed**. Edit could not change them. The type list offered `combined` (invalid). The status and NDPA filters used values the controller ignored. | Comma-separated input transformed to an array, on both forms. Valid lists. The NDPA filter (pending / notified / not required) is wired. The index badge shows "Due in Nh" or "Overdue". |

## 7. Dashboards

* **Security Operations Dashboard:** six KPI tiles, each linking to its filtered list (open findings, known exploited, past SLA, active incidents, new alerts, notifications overdue). Plus:
  * "Fix first": the top five risk-ranked findings.
  * Regulatory clocks: unsubmitted notifications and NDPA deadlines, soonest first.
  * Active incidents, by severity.
  * Alerts awaiting triage, with untriaged SIEM signals.
  * Mean time to respond, contain, resolve and remediate.
  * The open-findings severity mix, and degraded connectors.
* **Executive dashboard:** a Security Operations panel (KEV open, past SLA, active incidents, new high/critical alerts, NDPA pending, notifications overdue) and the next five regulatory clocks. The "Open Vulnerability Funnel" counted only `open` and dropped in-progress work; it now counts outstanding findings.
* Both read `SecurityOpsMetrics`, so they cannot disagree.

## 8. Demo data

**Bank seeders:**
- Real CVE ids (the `-vNN` suffix is gone).
- Seven medium and two low findings added to the CVE mix.
- Due dates from the SLA.
- Each finding linked to its asset.
- Incident types normalised.

**`AtherisPlatformSeeder`:**
- Real EPSS and KEV rows for every CVE in the banks' registers, plus regreSSHion. The values are an illustrative snapshot; production refreshes from the FIRST EPSS and CISA KEV feeds.
- Six realistic advisories; the placeholder advisories and cache rows are removed.
- The tokenised regulator templates.
- It no longer seeds SIEM data or notifications.

**`SecurityOperationsDemoSeeder`** runs after `DemoCrossLinkSeeder` and before Regulatory Intelligence. It is deterministic (`mt_srand` per bank). Per bank, it adds:
- an SLA policy;
- outstanding findings discovered in the last 40 days, so the SLA picture is mixed;
- incident timelines consistent with status, with 7–10 incidents following a procedure;
- breaches **one per incident**, discovered at detection, with the NDPA clock from discovery: notified in time, late, missed, and two still running;
- 16 realistic alert titles, some linked to incidents;
- four SIEM connectors (one degraded, all with secrets) and 24 masked signals, about four high/critical untriaged;
- eight notifications across the lifecycle (one overdue CBN draft), prepared by `incident@` so the bank admin can approve;
- remediation tickets on the top-ranked findings, with some P1/P2 left without one.

`Incident::breach()` is now `oldestOfMany()`, so a page always shows the same breach.

## 9. Verification

* **Browser (First Bank CISO):**
  * The dashboard renders with real figures.
  * The prioritiser is stable across reloads.
  * "Am I affected?": Citrix Bleed matched; regreSSHion not affected; the phishing advisory has no CVE.
  * SIEM shows only First Bank's connectors. "Raise alert" created an alert (numbered `ALR-0051`, which led to fix I4), then linked it to FBN-INC-020.
  * The incident moved Eradicating → Recovering. A CBN report was drafted from the incident (24h deadline from detection, correctly flagged overdue).
  * Self-approval of that draft was blocked.
  * A seeded NDPC notice was approved and submitted with a reference.
  * The Data Breaches list, filters and NDPA clocks, then a breach page.
  * The executive dashboard panel.
* **Bugs found and fixed during the walk:**
  * the NOT NULL `external_id` on ingest;
  * the `delivery_status` enum rejecting "submitted";
  * non-deterministic breach selection with several breaches per incident;
  * UTC detection times in letters to Nigerian regulators (now WAT);
  * a regulator-agnostic reference placeholder.
* **Automated:**
  * `SecurityOperationsModuleTest` (12 tests, 206 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test (192 GET routes)
  * `npm run build`
* **Dev MySQL:**
  * The migration was applied. The new platform intel, templates and `SecurityOperationsDemoSeeder` were run in place.
  * Kano's old cross-tenant SIEM rows and two orphaned notification drafts were replaced.
  * About a third of the existing findings were remapped to the new medium/low CVEs, and due dates recomputed.
  * The walk left one CBN draft (FBN-INC-020, in review) and one submitted NDPC notice (FBN-INC-017).

## 10. Not in this pass

* **Live feeds:** EPSS, KEV and advisory feeds are seeded snapshots. Nothing fetches FIRST, CISA or ngCERT on a schedule yet.
* **Delivery to regulators:** submission is recorded with the regulator's reference. There is no portal or e-mail delivery, and no PDF export of the notice.
* **SOAR:** no playbook automation. Response procedures are guidance with incident linkage, not executable runbooks.
* **Reminders:** nothing alerts on notification deadlines or SLA breach other than the dashboards.
* **Ticketing:** no ITSM sync. That belongs to Issues & Remediation (module 6).
* **Notification templates:** a single shared set, with no per-bank edits.
* **`DashboardController::boardPackExport`:** still hard-codes `organization_id => 1`. That belongs to the KRIs & Dashboards pass.
