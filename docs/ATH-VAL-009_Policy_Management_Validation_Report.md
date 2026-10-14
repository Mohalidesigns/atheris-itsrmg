# ATH-VAL-009 — Policy Management: End-to-End Validation Report

**Module:** Policy Management (sidebar group 9 of the platform validation programme). It covers:
- Policies (`policies.*`)
- Control Standards (`control-standards.index`)
- Policy Attestations (`policy-attestations.*`)
- Exceptions (`policy-exceptions.*`)
- My Attestations (`my-attestations.index`), new

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- Every page and action was exercised in the in-app browser:
  - as Kano's Policy Author (`policy@kanoheritage.ng`, Compliance Officer);
  - as the Kano CISO (Organization Admin);
  - as a SOC analyst (`soc.analyst@kanoheritage.ng`, Security Analyst, no policy permission).
- Controls, models and seeders were traced, together with the consumers of policy data: the executive dashboard, the Returns Centre's "policies in force", and Reports.
- Locked in by `tests/Feature/Policies/PolicyManagementModuleTest.php` (8 tests).

**What the module did before this pass:**

| Page | Before |
|---|---|
| Policies | A CRUD register. |
| Control Standards | A placeholder page with nothing behind it. |
| Exceptions | A placeholder page with no table behind it. |
| Attestations | A read-only list. |

Underneath that:
- **Two status vocabularies:**
  - **Policies:** the model, filters and Publish used `draft / in_review / approved / published / retired`, while the data used `draft / under_review / approved / active / expired / archived`. Most demo policies matched no filter, and Publish wrote a status nothing else read.
  - **Attestations:** the model expected `acknowledged`, while the data and the dashboard used `completed`, so the "acknowledged" figure was always 0.
  - **Categories:** the data used `security / privacy / risk / governance`, which the model did not know.
- **Publish:** it worked from any status, by anyone with `approve policies`, and needed no review. It did not create the attestation requests the attestation controller's own comment said it did.
- **Status could be set directly through Edit.**
- **Attestation for staff:** attesting required `view policies`, which only six roles hold. Risk managers, SOC analysts, IT operations and application owners — the staff policies are written for — could not acknowledge them.
- **Demo data:**
  - owners and approvers were random (the Information Security Policy was owned by a control tester and approved by a network engineer);
  - 428 attestations were attached to drafts and retired policies;
  - every "overdue" attestation had a future due date;
  - 417 "completed" attestations had no date.

**Now:**
- **Services** in `app/Services/Policies/`: `PolicyWorkflow` (lifecycle, attestation campaigns and attestation) and `PolicyMetrics` (shared by every page and the dashboard).
- **Exceptions:** `policy_exceptions`, with a request and decision flow.
- **Control Standards:** `control_policy` maps each policy to the controls that implement it.
- **Expiry:** a daily `policies:sweep` expires lapsed exceptions.

## 1. Security and tenancy

* **Pickers:**
  * The owner was `exists:users,id`, so it accepted any bank's user. It is now tenant-checked.
  * Controls and exception policies, controls, risks and owners are all tenant-checked.
  * Exceptions can only be raised against this bank's approved or active policies.
* **Sort:** `?sort=` went straight into `ORDER BY`; it is now whitelisted (`Policy::SORTABLE`).
* **Isolation:** another bank's policy, attestation action and exception decision are all 404s. The exception register and the attestation statistics count only the bank's own records.
* **Gates:**
  * Workflow actions need `edit policies` (submit) or `approve policies` (approve, return, publish, retire).
  * Waiving an attestation needs `approve policy-attestations`.
  * Deciding an exception needs `approve policies`.
  * Requesting an exception needs `view policies`.
* **Attestation access:** attesting is now open to any signed-in member of staff who has been asked to attest, or who can view policies. "My Attestations" is in every user's sidebar.

## 2. Policy lifecycle

* **One vocabulary** (migration `2027_01_18_000001`):

  | Before | After |
  |---|---|
  | `under_review` | `in_review` |
  | `published` | `active` |
  | `expired` | `active`, with the review date marked overdue |
  | `archived` | `retired` |

  Categories map to the model's set, with Governance and Risk Management added.
* **Workflow** (`PolicyWorkflow::TRANSITIONS`):

  | Action | From | To | Rule |
  |---|---|---|---|
  | Submit | draft | in review | Needs the policy text and an owner. |
  | Return | in review | draft | Needs a reason. |
  | Approve | in review | approved | Segregated: the policy owner and the person who submitted it cannot approve. |
  | Publish | approved | active | Sets the effective date and a review date (a year out if none is set). |
  | Retire | approved or active | retired | Needs a reason; waives the policy's open attestation requests. |

  * The status is no longer editable on the form.
  * Only drafts can be deleted.
  * A retired policy cannot be edited.
* **Revisions:** editing the text of an approved or in-force policy creates the next minor version (5.0 → 5.1), with its change summary in the version history, and sends it back for approval. Publishing it asks staff to attest to the new version.
* **Attestation campaigns:** publishing a mandatory policy asks every active member of staff, who has not attested to that version, to attest within 30 days.
  * An earlier version's open request is superseded, so it is waived.
  * Inactive users are not asked.
  * Attestations record the version attested.
* **Overdue** is derived (a pending request past its due date), not stored, so it can no longer disagree with the dates.
* **Waiving** an attestation (long leave, exit) records the reason.

## 3. Control Standards and exceptions

* **Control Standards** lists, for each policy in review, approved or in force:
  * the controls mapped to it (from the policy form);
  * their effectiveness and the percentage effective;
  * whether any control is failing.

  It counts policies with no control mapped ("a policy on paper only") and those with a failing control. The policy page has a Controls tab.
* **Exceptions** are time-bound, risk-accepted departures from a policy or one of its controls:
  * fields: scope, justification, compensating controls, owner, optional risk-register link;
  * request → approved or rejected; approved → revoked, or expired once the end date passes;
  * the requester cannot decide their own request; a rejection or revocation needs a reason;
  * the term is capped by risk rating (critical 3 months, high 6, medium and low 12), and the approver may shorten it;
  * high and critical exceptions need compensating controls;
  * per-bank codes (`KHB-EXC-0001`).

## 4. Dashboards and cross-module feeds

* **Executive dashboard.** "Policy Attestation Coverage" counted attestations on drafts and retired policies, and waived requests as not done. It is now a **Policy Compliance** card:
  * from `PolicyMetrics`: attested %, overdue attestations, reviews overdue, awaiting approval, live exceptions, and policies in force with no control (each linking to the filtered list);
  * coverage by department, counting only requests for policies in force.

  The "Policies" headline now counts policies in force.
* **Returns Centre.** `policies_in_force` counted approved-but-unpublished policies as in force; it now counts active policies only.
* **Reports.** The "published" count read a status that never existed; it now reads `active`.

## 5. Demo data

`PolicyManagementDemoSeeder` runs after `VendorManagementDemoSeeder`. Per bank it:
- **normalises** what the bank seeders write, which still uses the old vocabulary on a fresh install;
- **sets category, owner and approver from each policy's title:**
  - the CISO owns security policies, the DPCO data protection, the Vendor Risk Manager third-party and outsourcing, the CRO risk, IT operations operational policies;
  - the approver is never the owner;
- **records the Policy Author as submitter** of anything past draft;
- **keeps attestations only for policies in force**, stamped with the version;
- **maps controls by domain**, leaving two policies in force unmapped (deliberate gap);
- **raises six exceptions:** two awaiting a decision, two approved (one ending within 30 days), one rejected, one expired.

## 6. Verification

* **Browser (dev PostgreSQL):**
  * **As the Policy Author:**
    * The register shows 14 in force, 62% attested and 2 live exceptions, with hygiene tiles: drafts, awaiting approval, approved not published, review overdue, unmapped, attestations overdue.
    * Approving the Outsourcing Policy, which they submitted, was refused: "The policy owner and the person who submitted it cannot approve it."
    * Publishing the approved Data Classification Policy put it in force and asked 28 staff to attest.
  * **As the SOC analyst** (no policy permission): My Attestations listed 8 policies, 3 overdue. Read and attested to the Data Classification Policy ("You have attested to KHB-POL-008 version 5.0"); the list dropped to 7.
  * **As the CISO:**
    * Approved KHB-EXC-0002.
    * A critical exception requested for six months was refused: "A critical exception may run for at most 3 months (until 13 Jan 2027)."
    * Control Standards: 20 policies, 46 mappings, 7 unmapped, 6 with a failing control.
    * The attestation register agrees with the register (57% after the new campaign).
* **Fixed during the walk:**
  * the success message read "Policy active.";
  * seeded exceptions fell back to the Information Security Policy when the intended policy was not in force (they now try related policies, and approved policies are accepted);
  * a fresh install kept the bank seeders' old vocabulary.
* **Automated:**
  * `PolicyManagementModuleTest` (8 tests)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test (204 routes)
  * `npm run build`
* **Dev PostgreSQL:** the migration was applied (it mapped the vocabulary in place) and `PolicyManagementDemoSeeder` run.

## 7. Not in this pass

* **Reminders and escalation:** no attestation reminders or escalation to line managers, and no notification to policy owners before a review date.
* **Rich text and documents:** policy text is plain text. There are no attachments, no comparison between versions, and no PDF export of the signed policy.
* **Exception renewal:** renewing is a new request. Expired exceptions do not raise an issue or a risk-acceptance record in the risk register.
* **Control Standards depth:** a mapping has an optional clause field that the UI does not edit yet. Effectiveness comes from the Compliance module's control records (whose demo titles are generic, e.g. "Governance control 1"), which belongs to the Compliance and CCM data.
* **ISMS:** the Statement of Applicability is not yet linked to these mappings; that belongs to the ISMS pass, which is next.
