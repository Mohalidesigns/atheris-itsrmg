# ATH-VAL-002 — CBN Cyber Assessment (CBN-CSAT): End-to-End Validation Report

**Module:** CBN Cyber Assessment (sidebar group 2 of the platform validation programme)
**Tenants used:** Kano Heritage Bank (org 2), checked against First Bank (org 3)
**Method:**
- Every section of the assessment was exercised in the in-app browser:
  - profile, inherent risk, maturity tool, narratives, targets, threats, vulnerabilities, workflow, reports, insights
  - the approval flow, signed by three different officers
- The code was traced against the BRD (`CBN_CSAT_BRD_v1.0`) and TRD.
- Findings were fixed and re-tested.
- Locked in by `tests/Feature/Csat/CsatModuleTest.php` (9 tests, 55 assertions).

| Area | Before → after |
|---|---|
| Security | Cross-tenant edits possible, read-only roles could write → fixed |
| Lifecycle | No locking; one approval after any rejection finalised a cycle; "Submitted" unreachable → BR-AW-01 routing |
| Maturity scoring | Never computed; badges were hard-coded seed values; N/A counted as failure → BR-MA-05 engine |
| Data integrity | Every answer click erased its comment → preserved |
| Screens | Empty charts, dead compensating-control modal, wrong field keys, no submission package → working |
| Dashboard feed | Hard-coded radar for every bank → live cycle data |

---

## 1. Security and tenancy

| # | Finding | Fix |
|---|---|---|
| S1 | `{threat}`, `{vulnerability}` and `{recommendation}` were bound globally and never checked against the assessment. **Using your own assessment id you could edit or delete another bank's threats and vulnerabilities, or dismiss their recommendations.** | Child records must belong to the assessment (404 otherwise). `CsatAssessment` is now tenant-scoped (`BelongsToOrganization`). |
| S2 | Every write needed only `view csat`, so Viewer, Auditor and Architect roles could answer, delete and submit. | Writes need `edit csat`, deletes need `delete csat`, approvals need `approve csat`, and the package needs `export csat`. |
| S3 | A compensating control could be attached to another tenant's answer, or to a non-Yes [CC] answer. `assigned_to` accepted any user. | The answer must be a Yes [CC] in this assessment, and the assignee must be in the bank. |
| S4 | `authorizeAssessment` used strict `!==` on mixed types. | Compares as integers. |

## 2. Lifecycle and approval (BR-AW-01, BR-AW-04)

| # | Finding | Fix |
|---|---|---|
| W1 | Answers, registers and profile stayed editable while Pending Approval or Approved. | All writes are locked outside draft/in-progress. Each page shows a lock banner and disabled controls, and the server rejects writes with a clear message. |
| W2 | Submit had no checks (it worked from any status). The Sheet 6 checklist didn't exist. | A live **Items to Submit** checklist (11 items: profile, 10 stakeholder attestations, 47/47 IR, IR narratives, 344/344 statements, CC for every Yes [CC], narrative minimum lengths, 5 targets, both registers, deadline) **blocks submission** until complete. |
| W3 | `stage_number = max + 1` counted rejections, so **one approval after any rejection set Approved**. The same person could sign twice. There were 2 stages instead of the spec's 4, and no role check. | Four configurable stages: Preparer → CISO → CRO/CIO/COO → MD/CEO (`csat_approval_stages`, with defaults when none are configured). Each stage needs its permission and **a different officer** (segregation of duties). Each submission cycle starts afresh. |
| W4 | Return for revision didn't require comments in the UI flow. | Comments are mandatory and the cycle goes back to In Progress. |
| W5 | `submitted` could never be reached and `submitted_at` was never set. The page used a non-existent `submitted_to_cbn` key. | **Mark as Submitted to CBN** after full approval. It records `submitted_at` and closes the cycle. |
| W6 | The signature was a plain SHA-256. | HMAC-SHA256 keyed with the app key over (user, assessment, stage, action, sequence, timestamp). Tokens are unique per action. |
| W7 | There was no way to set the deadline after creation, and no countdown. | Deadline editor with days-remaining and an overdue "escalate to MD/CEO" cue. |

## 3. Scoring

| # | Finding | Fix |
|---|---|---|
| M1 | **Domain and component maturity scores were never computed for the demo cycles.** Overview, Maturity, Targets and Reports were empty while the badge said "Evolving" (a hard-coded seed value). | `MaturityScoringService` was rewritten per BR-MA-05 and seeders now compute every score. |
| M2 | Factor scores were never persisted, and the factor used `min` instead of the BRD's average. | Factor = average of its component levels. Domain = lowest factor. Overall = lowest domain. All three are persisted. |
| M3 | N/A counted as a failure, so it blocked a level. | N/A is excluded from a level's population. The spec gives N/A as a valid answer but no failure semantics. |
| M4 | About 250 queries per answer click. | Statements are loaded once and responses once, giving an in-memory roll-up. |
| M5 | The IR composite averaged only answered categories, with no completeness signal. | Equal-weighted mean of the five category averages (TRD §4.1), flagged **provisional** until 47/47 (BR-IR-06). |
| M6 | The Sheet 9 matrix paired domain *i* with IR category *i* and used `floor(avg)-1`, an arbitrary diagonal. | Each domain is plotted at (**composite** inherent-risk level, domain maturity), with dashed target markers. Cells are shaded by the FFIEC expectation (Least needs Baseline … Most needs Innovative), with an alignment verdict. |
| M7 | The maturity colour scale differed between pages. | One scale in `resources/js/Utils/csat.js`. |

## 4. Data loss and forms

| # | Finding | Fix |
|---|---|---|
| D1 | **Every IR or maturity answer click posted `comment: null`, erasing the comment.** The stakeholder status change erased its comment the same way. | Comments are only sent when edited. Inline comment fields were added for IR questions and maturity statements. |
| D2 | The stakeholder matrix had no comment field and showed unattested roles as "N/A". | Per-row name, status and comment with Save. "Not attested" is explicit. A comment is required for No / Yes-with-comment (client and server). |
| D3 | The compensating-control modal opened blank (existing control never loaded), closed even on validation errors and showed no errors. A control was optional. | Choosing Yes [CC] **opens the capture form automatically** (BR-MA-03). It loads the existing control, shows errors and closes only on success. A red "CC required" badge shows until it's documented. Changing the answer away from Yes [CC] removes the control. |
| D4 | Maturity narratives existed only for the first assessment ever seeded. New cycles and First Bank showed "run the seeder". Minimum length was never enforced. | Templates live on `CsatMaNarrative` and are provisioned on create and for every seeded cycle. A live character counter is checked by the checklist. |
| D5 | Threats: catalogue picks used the wrong keys (`default_source`), so source, category, likelihood and impact were lost. "Added by" never showed. No errors, residual or comment fields. No 3×3 matrix (BR-TR-03). | All fixed. An interactive 3×3 likelihood × impact matrix filters the register, and unmitigated threats are flagged. |
| D6 | Vulnerabilities: raw ISO due date, blank date on edit, no delete, no errors, no comment. | All fixed. A score ≥6 with no owner is flagged. |
| D7 | Targets: the gap list showed every "No" regardless of target. | Gaps are the "No" answers at or below each domain's target. |
| D8 | The overview claimed "494 statements" (344 are loaded), had ambiguous "Inherent Risk 100%" rings and "Cat 1–5" chart labels. | Real counts, labelled completion, category names, checklist summary, and achieved-vs-target bars. |
| D9 | The maturity sidebar showed 0/0 for every domain except the open one. | Progress is served for all domains. |

## 5. Reports, insights and submission package

| # | Finding | Fix |
|---|---|---|
| R1 | **Nothing generated recommendations**, so Insights and the Reports count were always empty. Type keys and the `estimated_effort` field didn't match the schema. | `CsatInsightService` (rule-based, labelled `atheris-rules-v1` rather than claiming generative AI) produces gap analysis vs targets with the blocking statements, checklist blockers, weak or overdue compensating controls, and unmitigated high threats. It also computes the readiness score with RAG (85/65 thresholds). |
| R2 | Charts rendered empty. | Fixed (bars now draw) and categories named. |
| R3 | There was no submission package or cover letter (BR-AW-02/03). | **CBN Submission Package** page: Sheet 8 cover letter citing §3.9.3, plus (a) summary and stakeholders, (b) IR results, (c) domain/factor maturity, (d) threat and vulnerability registers, (e) signed attestation trail, (f) evidence index. Print / Save as PDF. Signatories come from the current cycle only. |

## 6. Feeds

* **Executive Dashboard** — the CSAT radar was hard-coded ([3.2, 2.8, …], "60% complete") for every bank. It now plots the bank's latest cycle (achieved level plus progress to the next level, against targets), with status, overall maturity, inherent risk, completion and readiness. The link is permission-aware and opens that cycle.
* **Regulatory return** — `CsatMapper` reads no CSAT data, and its Vendor queries are cross-tenant. This was raised as a separate task because it belongs to the Returns module.

## 7. Demo data

`CsatDemoCompletionSeeder` (runs after both bank seeders):
* **Kano Heritage**:
  * a complete, deterministic cycle that passes all 11 checklist items
  * domains at Baseline/Evolving against an Intermediate target, under Moderate inherent risk, so the matrix shows a real alignment gap
  * 8 threats and 6 vulnerabilities, compensating controls, narratives, stakeholders, and generated insights
  * this lets the full submit → approve → return/approve → submit-to-CBN flow be demonstrated
* **First Bank**: ~75% of statements answered, so it is Sub-Baseline with readiness 55 (red). This shows the checklist blocking submission.

## 8. Verification

* Browser walkthroughs:
  * submit as preparer (Adaeze), with editing locked and the preparer blocked from Stage 2
  * Stage 2 signed by Segun Akinola
  * Stage 3 returned with a comment by Amina Lawal, so the cycle is back In Progress
  * Yes [CC] auto-capture, validation, save and removal
  * catalogue threat add/remove
* Automated: `CsatModuleTest` (9), plus the full PHP suite, a fresh `migrate:fresh --seed` (SQLite), the route smoke test and `npm run build`.

## 9. Not yet built (spec items outside this pass)

* Deadline **reminder notifications** at 60/30/14/7 days and overdue escalation (BR-AW-05). The UI shows the countdown, but nothing is scheduled.
* Multi-participant **comment threads** (BR-MA-04). Single comments are supported and preserved.
* Section assignments and evidence attachments (tables exist with no UI). Encryption of CISO email/phone at rest (TRD).
