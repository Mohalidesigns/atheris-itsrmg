# ATH-VAL-004 — Regulatory Intelligence: End-to-End Validation Report

**Module:** Regulatory Intelligence (sidebar group 4 of the platform validation programme). It covers:
- Circular Feed (`reg-intel.*`)
- Document Intelligence (`doc-intel.*`)
- Returns Centre (`returns.*`)
- Architecture Returns (`regulatory-returns.*`, the ReturnCompiler / ReturnMapper engine)

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3), which holds the EA repository behind Architecture Returns.

**Method:**
- Every page, form, table and action was exercised in the in-app browser as the Kano Head of Compliance (Compliance Officer), the Kano CISO (Organization Admin) and the First Bank CISO.
- Controllers, models, services and seeders were traced against the spec (implementation plan §4.4 and §6.4, ATH-EAR-002 §6.2–6.3), and findings were fixed and re-tested.
- Locked in by `tests/Feature/RegulatoryIntelligence/RegulatoryIntelligenceModuleTest.php` (14 tests, 194 assertions).

The three platform pages were demo stubs: the upload stored no file, a generated return held placeholder PDF/XLSX paths, and the circular feed had no per-bank state. They now implement the workflows the spec describes. The new controller is `RegulatoryIntelligenceController`; the stub methods were removed from `PlatformController`.

| Area | Before → after |
|---|---|
| Access | The Compliance Officer (Head of Compliance), the module's primary user, held no `platform` permission and could not open it → granted view, create and edit |
| Circular Feed | One shared read-only list. Raw ISO dates. Draft circulars visible to banks. `circular_impacts` unused → per-bank triage (Unread / Assessed / Actioned / Not applicable), impacts linked to the bank's own register, Content-Ops editorial gate |
| Document Intelligence | No file upload; "Queue" created a fake job for **org 1** with random fields. No review → real upload to private storage, text-layer extraction, confidence, segregated accept/reject, hand-off to the circular feed or vendor record |
| Returns Centre | Not tenant-scoped; "Generate" wrote **org 1** rows holding placeholder file paths. No values, review or approval → runs populated from the bank's register, overrides with reasons, Draft → In review → Approved → Submitted with separation of duties, CSV export |
| Architecture Returns | Calendar ignored filing (annual deadlines read "225d overdue" forever). Another bank's legal entity passed validation. The CSAT pack read no CSAT data. Nested answers rendered as raw JSON. No demo returns → all fixed; First Bank has five compiled returns |
| Dashboard | Nothing from this module reached the executive dashboard → Regulatory Intelligence panel |

---

## 1. Security and tenancy

| # | Finding | Fix |
|---|---|---|
| S1 | `DocIntelligenceJob` and `ReturnRun` had only the `tenant_id` alias trait, with **no tenant scope**. Every bank saw every bank's documents and return runs. | Both use `BelongsToOrganization` (strict). Show, download, export and every write bind through the scope, so another bank's record returns 404. Verified in the browser (First Bank → Kano job #24 and run #20: 404 on all four URLs) and in tests. |
| S2 | `docIntelUpload` and `returnsGenerate` hard-coded `organization_id => 1`. Your dev MySQL holds 3 org-1 Document Intelligence jobs and 3 org-1 return runs created by this bug; they belong to Acme and were left in place. | Writes take the signed-in user's organisation. |
| S3 | Write routes sat behind `view platform` only. | Upload and generate need `create platform`. Triage, impacts, review, overrides and send-for-review need `edit platform`. Approve, send-back and submit need `approve platform`. Content-Ops editorial is Super Admin only. |
| S4 | `regulatory-returns.compile` validated `legal_entity_id` with a bare `exists`. Another bank's entity id passed, and the scoped `find()` then returned null, **silently compiling a group-level return**. | Tenant-scoped `exists` (own org or platform-shared NULL). |
| S5 | Circular impacts had no organisation, although they point at bank records. | `circular_impacts.organization_id`. Targets resolve through the target model's tenant scope, so another bank's obligation, control, policy or risk cannot be linked. Owners must belong to the bank. |
| S6 | The handoff flagged `CsatMapper` Vendor queries as cross-tenant. | Not reproducible in a request: `Vendor` carries `BelongsToOrganization`, and First Bank's pack counts its own 57 vendors. The risk is console or queue context with no user, so the demo seeder compiles while signed in as the bank's CISO. |

## 2. Circular Feed

| # | Finding | Fix |
|---|---|---|
| C1 | Spec §4.4.3 wants status chips "Unread / Assessed / Actioned" per bank. There was no per-bank state at all. | `circular_assessments` (one row per bank and circular). KPI tiles, a triage rail and filters, owner, due date, severity, notes, assessor and timestamps. |
| C2 | No rules: anything could be marked done. | Assessed and Actioned need an applicability decision and an owner. **Actioned needs at least one linked record**, and unlinking the last one drops the circular back to Assessed. Not applicable needs a written reason. |
| C3 | Spec §4.4.6 requires impacts that carry citations so an examiner can trace circular → obligation → control → policy. `circular_impacts` existed but nothing wrote it. | An "Impact on your register" panel links obligations, controls, policies and risks with an impact score, rationale and required action. Each link opens the record, and duplicates are refused. |
| C4 | Draft, summarising and editorial-review circulars were shown to banks. Spec: "Content-Ops review required before any circular goes live to tenants". | `visibleToTenant()` shows published and superseded platform circulars plus the bank's own. Super Admin sees everything and can publish, return to review or supersede. |
| C5 | Dates rendered as `2026-10-04T00:00:00.000000Z`. No search. No source link. | Date casts. Search on title and reference. A source link, ingestion time, and a "Uploaded by your bank" badge for private circulars. |
| C6 | `atheris:crawl-regulators` existed but nothing ran it. | Scheduled daily at 05:30. New circulars land as drafts behind the editorial gate. |

## 3. Document Intelligence

| # | Finding | Fix |
|---|---|---|
| D1 | No file was ever uploaded. The drop zone said "demo: click submit to queue a sample". | Real upload: PDF, DOCX, TXT, MD or CSV, up to 20 MB, stored on the private disk under `doc-intel/{org}/`, with an authorised download. |
| D2 | Extraction was random (`BSD/DIR/GEN/LAB/14/` plus `rand()`, a fixed 0.92 confidence). | `DocumentExtractionService` reads the text layer (text formats, DOCX XML, PDF content streams including FlateDecode) and extracts fields per type: regulator, reference, title, issued and effective dates, summary, respondent, penalty, auditor, period, findings counts, vendor, and DDQ answer counts. Confidence is the share of expected fields found. A scanned PDF scores 0% and the reviewer types the fields in. |
| D3 | No review step. Spec §6.4.6 requires human review below 90% confidence. | A review page with editable fields. Accept and reject are recorded with reviewer, time and notes. **The uploader cannot accept their own extraction.** Below 90%, the reviewer must confirm every field. Reject needs a reason. |
| D4 | The spec's "Send to Circular pipeline" and "Send to Vendor DDQ" did nothing. | Accepting a circular or NDPC decision creates the **bank's private circular**, which is then triaged like any other. Accepting a DDQ requires one of the bank's vendors and stamps its `last_assessed`. |

## 4. Returns Centre

| # | Finding | Fix |
|---|---|---|
| R1 | A run held `auto_populated: true` and nothing else, with "PDF · XLSX" pointing at files that never existed. | `ReturnRunPopulator` defines each template's fields and cadence, and resolves every field from the bank's own register for the run's period: risks, risks above appetite, critical vulnerabilities, incidents, ineffective key controls, CSAT status/maturity/risk, data breaches and NDPC notifications, policies in force, and vendors (active, critical, assessed, expiring). Every query names the organisation. |
| R2 | Spec §6.4.3 wants a per-return wizard with manual overrides. There was none. | A run page with every field, its source, and overrides that **require a reason**. "Recompute from register" keeps overrides. CSV export carries the filed value, computed value and override reason. |
| R3 | No lifecycle. | Draft → In review → Approved → Submitted, with send-back. Only drafts can be edited. **The preparer cannot approve their own return.** Submitting requires the regulator's acknowledgement reference. Transitions are written to the activity log. |
| R4 | "Generate" always used the current month, for annual and quarterly returns too, and duplicates piled up. | Periods follow the template's cadence (`2026-10`, `2026-Q4`, `2026`), with a format check and an "Other period" option. A second generate for the same period opens the existing run. |

## 5. Architecture Returns

| # | Finding | Fix |
|---|---|---|
| A1 | The compliance calendar computed the due date from the current year only. After the deadline passed it read "225d overdue" even with a filed return. | The calendar reads the bank's returns. Once that period is signed or submitted, an annual deadline rolls to the next cycle; overdue shows only when nothing has been filed. Each row links its return and state, and the Compile button opens on that obligation's template (Recompile when one exists, hidden once filed). |
| A2 | `CsatMapper` read no CSAT data, although the CSAT return *is* the self-assessment. | A new **RBCF-2.5 section** from the bank's CBN CSAT cycle: year, status, maturity, inherent risk, statements answered, and domain achieved vs target. Its caveat blocks filing until the CSAT cycle is approved. CSAT records sit outside the EA quality-seal regime, so they are cited (`not_sealable`) without raising or diluting evidence confidence. |
| A3 | Nested answers (domains, roadmap, vendors by role) rendered as raw JSON. | The answer renderer is recursive: lists become tables, maps become key/value lists, booleans read Yes/No. |
| A4 | No demo returns for either bank. | First Bank: CSAT 2025 (prior period, for the diff) and CSAT, NDPC CAR, localisation gap and ITSB maturity for 2026. All are honestly in preparation: evidence confidence (4–43%) mostly sits below the 30% signing floor. |

## 6. Feeds

* **Executive dashboard:** a new Regulatory Intelligence panel shows unread circulars, circulars awaiting action, overdue circular actions, documents to review, returns not yet filed, and architecture returns open. It also lists the next five open returns. Each tile links to the filtered page.
* **Circular Feed ↔ Document Intelligence:** an accepted circular appears in the feed, and links back to its extraction.
* **Returns Centre ↔ CBN Cyber Assessment, Risk, Vulnerabilities, Incidents, Controls, Policies, Data Breaches, Vendors:** read live at generation or recompute.
* **Architecture Returns ↔ CBN Cyber Assessment:** the RBCF-2.5 section above.

## 7. Demo data (`RegulatoryIntelligenceDemoSeeder`, after EA stewardship)

Each part is guarded per tenant. `AtherisPlatformSeeder` now seeds only the platform-wide return templates. The seeder produces:
- **Circulars:** for each bank, 3 actioned, 3 assessed (one overdue) and 1 not applicable, the rest unread. Each actioned or assessed circular has 2–3 impacts on the bank's own obligations, controls, policies or risks. Two platform circulars are still in editorial review, so they are visible to Super Admin only.
- **Documents (6 per bank, real files):** a PDF circular (FlateDecode, accepted, so it is in the feed); an NDPC order (awaiting review, 100%); a DOCX audit report (awaiting review, 83%, so the reviewer must confirm fields); a DDQ (accepted, linked to a vendor); a scanned PDF with no text layer (0%); and a rejected draft.
- **Return runs:** for each bank and template, the prior period submitted with an approver and acknowledgement reference, and the current period in draft. The CRMS IT run is in review with an override and its reason.
- **Legacy upgrade:** runs from the earlier seed are re-populated from the register and lose their dead file paths.
- **Architecture returns:** First Bank's five compiled returns (§5, A4).

## 8. Verification

* **Browser:**
  * Kano Head of Compliance: open the module; triage refused as Actioned without impacts, then accepted after linking an obligation; upload a DDQ, extracted at 100%, with self-acceptance blocked.
  * Kano CISO: DDQ accept refused without a vendor, then accepted against e-Tranzact; generate CRMS IT 2026-10 from the register; send for review, with self-approval blocked; the dashboard panel.
  * First Bank CISO: calendar linked to the return in preparation; recompile showing the CSAT section; cross-tenant 404s.
* **Bugs found and fixed during the walk:**
  * a `confirm_fields` rule that blocked every acceptance;
  * the PDF line splitter (`\b` after `T*`), which merged all text onto one line;
  * a non-chainable `useForm().transform()` that broke "Generate".
* **Automated:**
  * `RegulatoryIntelligenceModuleTest` (14 tests, 194 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test (192 GET routes)
  * `npm run build`
* **Dev MySQL:** both migrations applied, and `RegulatoryIntelligenceDemoSeeder` run in place.

## 9. Not in this pass

* **OCR and LLM:** no OCR engine (Tesseract / PaddleOCR) or LLM is attached. Extraction is deterministic pattern matching over the text layer; a scanned document needs manual entry. Circular summaries and impact notes are seeded content, not generated.
* **Regulator crawlers:** they are scheduled but not exercised against live regulator sites here (licensing per plan A9).
* **Returns Centre PDF/XLSX:** CSV is the export. A regulator-formatted PDF or XLSX, and portal delivery, are not built.
* **Notifications:** none for circular action due dates or returns approaching their deadline.
* **KRI `KRI-COMP-CBN` ("CBN circular actions open"):** still reads seeded values rather than the triage count. That belongs in the KRIs & Dashboards pass.
* **`DashboardController::boardPackExport`:** it hard-codes `organization_id => 1`. That belongs in the KRIs & Dashboards pass.
