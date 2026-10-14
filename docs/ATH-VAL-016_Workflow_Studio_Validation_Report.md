# ATH-VAL-016 — Workflow Studio: Redesign and Validation Report

**Module:** Workflow Studio (sidebar group 16 of the platform validation programme), redesigned at the user's request. It is now the single place where a bank configures every approval in the platform:
- who may start each process;
- which stages approve it, in what order and by whom;
- how many approvals each stage needs, and for which records.

The marketplace has been removed from the product, including Pricing.

**Tenants:** Kano Heritage Bank (org 2) and First Bank (org 3).

**Method:**
- The approval code of every module was traced.
- Pages were walked in the in-app browser as the Kano CISO.
- Locked in by `tests/Feature/Workflows/WorkflowStudioModuleTest.php`, together with the module tests of every flow it now drives (Policies, ISMS, Business Continuity, Continuous Monitoring, KRIs & board packs, EA maturity, Change Management, CSAT).

**What Workflow Studio was before this pass:**
- **A read-only stub:** a list of demo "workflows" (with a React-Flow canvas), instances and tasks that no process in the platform ever ran.
- **A marketplace tab:** templates under the same Studio.
- **A Marketplace sidebar group:** a content marketplace whose "Install" wrote rows for organisation 1, an installed-packs list, and a Pricing page of subscription tiers.
- **Approvals were hard-coded in each module:**
  - one approver holding the module's `approve` permission, plus that module's own segregation rule;
  - Change Management and CSAT had multi-stage engines, configured on their own pages;
  - nothing let a bank say "a policy needs the Head of Compliance and then the CISO", or "only Compliance Officers may submit".

## 1. The approval engine

**Tables** (migration `2027_01_25_000001`):
- `approval_workflows`: one per bank and process, holding the initiator roles, an active flag and notes;
- `approval_workflow_stages`: ordered, with approver, approvals needed, SLA and an optional condition;
- `approval_requests`, `approval_steps` (stages snapshotted when an approval starts) and `approval_decisions`.

**`ApprovalProcesses`** is the registry of every approval point. For each it records:
- the module and the permission needed to act;
- the record model;
- who initiated it and who owns it;
- the conditions a stage can be limited to;
- where the process starts;
- what happens with no stages configured.

**`ApprovalEngine`:**
* **Approvers:** a stage is decided by anyone holding a role, a named person, anyone with a job title, the record owner, or anyone holding a permission. The `min_approvals` setting asks for more than one.
* **Conditions:** a stage can apply only to some records, e.g. CISO sign-off only for high and critical policy exceptions, or only for critical drift.
* **Order:** a module's approve action asks the engine first. Until the last stage approves, the record stays awaiting approval and the action reports the next stage ("Approved at "Head of Compliance review". Now awaiting "CISO approval"."). Only the final approval lets the module complete its own transition.
* **Out-of-turn approvals** are refused with the stage and who it needs ("This is awaiting "Head of Compliance review": anyone whose job title includes "Head of Compliance".").
* **Segregation of duties,** on top of each module's own rules:
  * whoever started the approval never approves it;
  * nobody decides two stages of the same approval;
  * the record owner never approves where the module forbids it (policies, BCP plans, BIAs, drift).
* **Returns and rejections** close the open approval. A material change (BCP plan scope or RTO, BIA objectives) or remediating the drift withdraws it.
* **Default:** with no stages configured, the module's existing single approval applies unchanged.
* **Initiators:** where roles are set, only those roles may start the process at its starting point:
  * submitting a policy, a SoA version, a change, a CSAT assessment or an EA maturity assessment;
  * requesting an exception;
  * recording a BIA;
  * launching an access review;
  * generating a board pack.

## 2. Processes covered

| Module | Process | Engine | Starts at |
|---|---|---|---|
| Change Management | Change approval | Change engine (stages by type and risk, CAB/ECAB) | Submitting a change |
| CBN Cyber Assessment | CSAT sign-off | CSAT engine (HMAC-signed stages) | Submitting the assessment |
| Policy Management | Policy approval | Generic | Submitting for review |
| Policy Management | Policy exception | Generic | Requesting an exception |
| ISMS | Statement of Applicability | Generic | Submitting a version |
| Business Continuity | BCP / DR plan approval | Generic | The plan owner |
| Business Continuity | BIA approve & apply | Generic | Recording a BIA |
| Continuous Monitoring | Drift risk acceptance | Generic | The drift owner |
| Continuous Monitoring | Access review sign-off | Generic | Launching a campaign |
| KRIs & Dashboards | Board pack approval | Generic | Generating a pack |
| Enterprise Architecture | EA maturity assessment | Generic | Submitting the assessment |

Change Management and CSAT keep their proven engines, which have CAB quorum, freeze windows and signed sign-offs. Workflow Studio edits their stages in place: change stages through the existing change-policy endpoints, with the type × risk routing table; CSAT stage names and the permission each needs.

## 3. Workflow Studio pages

* **Approval Workflows:**
  * every process grouped by module;
  * whether it has configured stages or uses the module default;
  * who may start it;
  * how many approvals are awaiting a decision.
* **Process page:**
  * the flow drawn left to right (initiator, then each stage, then approved);
  * who may start it (roles);
  * the stage editor: add, edit, delete, move up and down, with approver type, approvals needed, SLA and condition;
  * a warning when a chosen role does not hold the process's permission, since that role could not act;
  * recent approvals.
* **Approval Inbox:**
  * everything awaiting a decision across all processes, including change approvals at their current stage;
  * with the stage, who must act, the due date (SLA) and whether it is the signed-in user's decision;
  * "Awaiting me" filter.
* **Record pages:** an approval trail on the policy, BCP plan, drift, access review, board pack and EA maturity pages. It shows the configured stages, then each step with its decisions, and re-reads itself after every action.
* **Permissions:** a `workflows` set. Organization Admin and Super Admin hold all of it. Compliance Officer, Risk Manager, Auditor and Viewer can view.

## 4. Marketplace removed

* **Data:** the migration drops `content_marketplace_items`, `content_installs`, `pricing_tiers`, and the demo `workflows`, `workflow_instances` and `workflow_tasks` tables, and deletes the `workflows.marketplace` and `marketplace.public` feature flags.
* **Routes and code:** routes, `PlatformController` methods, models, pages (`Marketplace/*`, `Pricing/*`, `Workflows/Marketplace`, `Workflows/Show`, `Workflows/Instances`), the Marketplace sidebar group and the platform seeder's marketplace, pricing and demo-workflow data are gone.
* **Playwright specs:** updated for the removed pages. Two specs that had been stale since module 14 were also fixed: the board pack button and the KRI heading.

## 5. Demo data

`WorkflowStudioDemoSeeder` runs last. Per bank it configures:

| Process | Initiators | Stages |
|---|---|---|
| Policy approval | Compliance Officers | Head of Compliance review → CISO approval |
| Policy exception | — | Compliance review → CISO sign-off for high and critical exceptions only |
| Statement of Applicability | Compliance Officers | ISMS steering review → CISO approval |
| BCP plan approval | — | CISO → executive sign-off |
| Drift acceptance | — | Security operations review → CISO for high and critical drift |
| Access reviews | Compliance Officers and Organization Admins | Compliance sign-off |
| Board packs | Risk Managers and Organization Admins | CISO approval |
| EA maturity | — | CISO approval |

BIA approval stays on the module default to show the difference.

Live approvals are started for policies already in review where every stage has an eligible approver (one past its first stage) and for draft board packs, so the inbox has work in it.

## 6. Verification

* **Browser, as the Kano CISO:**
  * The Studio lists 11 processes (10 configured, 10 awaiting).
  * On Policy approval, adding a "Legal review" stage redrew the flow; moving it up reordered the stages; deleting it removed it.
  * The inbox ("Awaiting me") showed two change approvals, the CISO Weekly board pack and KHB-POL-007 at "CISO approval (1/2 done)".
  * **Approving KHB-POL-007** completed its final stage. The policy moved to Approved, and the trail shows both decisions (Head of Compliance, then CISO).
  * **Approving KHB-POL-009,** still at its first stage, was refused with "This is awaiting "Head of Compliance review"…".
  * The change-approval page shows the six change stages and the routing table; the CSAT page shows the four sign-off stages.
* **Fixed during the walk:**
  * The trail and inbox offered the CISO a policy the CISO owns, which the policy module then refused. The owner rule is now part of the engine for the modules that have it.
  * The demo starts live approvals only where someone can decide every stage.
  * Change approvals in the inbox show the board's name rather than "board: 1".
* **Automated:**
  * `WorkflowStudioModuleTest`
  * the module tests of every integrated flow
  * the full PHP suite
  * a fresh `migrate:fresh --seed`
  * the route smoke test
  * `npm run build`

## 7. Not in this pass

* **Other modules' approvals:** ARB decisions, EA exceptions, evidence review, regulatory-return sign-off and issue verification keep their own rules. They are not yet in the registry, but can be added as generic processes the same way.
* **Approve and reject in the Studio:** the engine checks every decision, but the module's own page remains where a decision is made (the inbox links to it). Approving from the inbox would need each module's final transition exposed as a callable step.
* **Notifications:** stage SLAs are shown and flagged when past due, but no reminder or escalation is sent.
