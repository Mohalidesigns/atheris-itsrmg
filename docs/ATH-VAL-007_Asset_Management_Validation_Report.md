# ATH-VAL-007 — Asset Management: End-to-End Validation Report

**Module:** Asset Management (sidebar group 7 of the platform validation programme). It covers:
- IT Assets (`assets.*`): the asset register
- Asset Discovery (`asset-discovery.*`)
- Business Services (`business-services.index`, `business-services.graph`)
- Business Assets (`business-assets.*`): the business-service register

**Tenants:** Kano Heritage Bank (org 2), cross-checked against First Bank (org 3).

**Method:**
- Every page, form and action was exercised in the in-app browser as the Kano CISO (Organization Admin), and isolation was checked as the First Bank admin.
- Controllers, models, seeders and the downstream consumers of asset data were traced: the vulnerability prioritiser, the EA asset panel, change requests and the executive dashboard. Findings were fixed and re-tested.
- Locked in by `tests/Feature/Assets/AssetManagementModuleTest.php` (12 tests, 215 assertions).

**Intended capability** (implementation plan, Phase 2, gaps D1.1–D1.3):
- discovery connectors for AD/Entra, AWS, Tenable and Qualys, plus a bulk CSV importer;
- asset provenance (source, external id, sync time) and criticality inherited from business services;
- classification and data-owner mapping;
- a business-service and dependency graph over the Nigerian DMB reference model.

**What the module did before this pass:**
- **IT Assets:** a basic CRUD register.
- **Asset Discovery:** a `PlatformController` stub. "Trigger sync" wrote a job to organization 1 with `rand()` totals and touched no asset.
- **Business Services:** a stub whose graph drew 16 spokes around a hard-coded "Kano Heritage Core" hub, with no edges, for every bank.
- **Business Assets:** a CRUD form that could not save a capability.

The two stubs now live in `app/Http/Controllers/Assets/`: `AssetDiscoveryController` and `BusinessServiceMapController`. They are backed by three services in `app/Services/Assets/`:
- `AssetDiscoveryService`: connectors, CSV import, and one reconciler
- `AssetCriticality`: inheritance from business services
- `AssetMetrics`: shared by the register, Discovery and the executive dashboard

| Area | Before → after |
|---|---|
| Asset register | **Before:**<ul><li>Any `?sort=` value went straight into `ORDER BY`.</li><li>Pickers accepted users from other banks.</li><li>New codes were `AST-0001`, outside the bank's `KHB-AST-…` series.</li><li>Delete was unreachable.</li><li>Edit showed purchase and end-of-life dates blank (ISO strings in a date input).</li><li>No provenance, no custodian, no hygiene view, no export.</li></ul>**After:**<ul><li>Whitelisted sort with clickable column headers.</li><li>Tenant-checked owner, custodian and service pickers.</li><li>Per-bank codes (`KHB-AST-0001`).</li><li>Effective criticality with an "inherited" marker.</li><li>Hygiene tiles that double as filters: by criticality, unowned, past end of life, end of life within 90 days, critical/high assets not mapped to a service.</li><li>Source and flag filters, CSV export (`export assets`), archive.</li><li>Retired assets hidden unless asked for.</li></ul> |
| Asset page | **Before:** details, risks, vulnerabilities, and an EA panel that was always empty.<br>**After:** owner and custodian, provenance (source, external id, last seen, reviewed by), the business services supported with a link manager, change requests naming the asset, the EA panel (now populated for First Bank), an audit trail, and a review panel for discovered assets |
| Asset Discovery | **Before:** cross-tenant jobs, invented totals, writes to org 1.<br>**After:**<ul><li>Five sandbox connectors (AD, Entra ID, AWS, Tenable, Qualys) and a real CSV import with a template.</li><li>One reconciler: match by source and external id, then de-duplicate on hostname or IP, then create.</li><li>True created/updated/rejected counts, with row errors.</li><li>A review queue: accepting needs an owner; rejecting needs a reason and retires the asset.</li></ul> |
| Business Services | **Before:** read edges that never matched, a hard-coded bank name, and a graph with no edges.<br>**After:**<ul><li>Capability → service → supporting-asset map with KPIs: unowned, no supporting asset, dependency count.</li><li>Layered dependency graph with a criticality filter and an assets toggle; click-through to records.</li><li>Gated on `view assets` instead of `view platform`, which Application Owners and Solution Architects lack.</li></ul> |
| Business Assets | **Before:** `capability_id` was validated against the retired `business_capabilities` table, so any capability failed; no assets, owner or dependencies.<br>**After:**<ul><li>Validated against the bank's own `ea_capabilities`.</li><li>Owner column, supporting-asset manager, upstream and downstream dependencies with add and remove.</li><li>Cycle refusal and an RTO-conflict warning.</li><li>Per-bank `BS-` codes.</li></ul> |

## 1. Security and tenancy

* **Asset Discovery.** `AssetSyncJob` had no tenant scope, so every bank saw every bank's sync history. The stub also wrote new jobs with `organization_id => 1`. The model is now strict-scoped (`BelongsToOrganization`), and jobs are written for the signed-in bank.
* **Pickers.**
  * The asset owner was `exists:users,id`, which accepted a user from any bank.
  * Owner, custodian, linked services, the review owner, CSV owner e-mails, supporting assets and dependency providers are now all checked against the signed-in bank.
* **Sort.** `AssetController@index` passed `?sort=` and `?direction=` straight into `orderBy`. Both are now whitelisted (`Asset::SORTABLE`).
* **Business services.**
  * The EA models use `BelongsToTenant`, which also admits platform-shared rows (`organization_id IS NULL`).
  * Route binding stays read-compatible, but every write on Business Assets (`ownBank()`) requires the bank's own row.
  * The map and graph query the bank's own services only.
* **EA asset catalogue.** `AssetApplicationClient` cached the asset catalogue under one global key for five minutes. Whichever bank loaded it first had its catalogue served to every other bank, including into `ea.sync.assets`. The key is now per bank.
* **Gates:**
  * Discovery runs and CSV import need `create assets`.
  * Linking, reviewing and dependency changes need `edit assets`.
  * Export needs `export assets`.
  * The UI hides each action without its permission.
* **Isolation check:** as the First Bank admin, Kano's asset and business service are 404s. Discovery shows only First Bank's runs: the Tenable run made as Kano during the walk does not appear.

## 2. Discovery and the register's provenance

* **Data model** (migration `2027_01_16_000001`, plan §2.4.1):
  * `assets` gains `source`, `external_id`, `last_seen_at`, `custodian_id`, `criticality_inherited`, `reviewed_at` and `reviewed_by`.
  * `asset_sync_jobs` gains `mode`, `triggered_by`, `file_name`, `records_created`, `records_skipped` and `errors`.
* **One reconciler for every source.** For each record:
  1. A record known by (source, external id) is updated and marked seen.
  2. Otherwise a hostname or IP match adopts the existing asset. This is the de-duplication: a scanner does not create a second Finacle server.
  3. Anything else is created.
  * A connector find lands as `under_review` with no owner.
  * A CSV row lands as written, because a person curated it.
  * A manual asset that a connector sees is adopted by that connector, which sets its provenance.
* **Connectors run in sandbox mode, and say so.** No bank has supplied AD, AWS, Tenable or Qualys credentials.
  * Each connector reads a deterministic catalogue: the bank's own assets of the types it would see, plus a few hosts the bank has not registered. Examples: an unregistered CentOS 7 host on Tenable, a Windows 10 vendor jump host on Qualys, an S3 bucket and an EC2 worker on AWS.
  * A second run creates nothing.
  * During the walk a first build counted a find twice on its second run (as a new find and as a known asset). It is now de-duplicated by external id.
* **CSV import.**
  * Template download. `name` and `asset_type` are required; enums are normalised ("Hardware" → `hardware`).
  * Owners are matched by e-mail inside the bank.
  * Each bad row is skipped with a reason, for example ``Row 4: asset_type "laptop" is not one of …`` or ``Row 5: owner … is not a user of this bank``.
  * The errors are shown under the job in the history.
* **Review queue.** Accept sets the owner and, optionally, a revised criticality, and stamps the reviewer. Reject needs a reason, retires the asset (`decommissioned`, kept for audit) and appends the reason to its description. Only `under_review` assets can be decided.
* **Audit trail.** The asset page shows the activity log. A nightly run re-confirming an asset (`last_seen_at` only) is not logged, so real changes are not buried.

## 3. Business services, dependencies and inherited criticality

* **The overlap question from the recon is settled.** Business architecture is already canonical in EA (ATH-EAR-002 D1). `App\Models\BusinessService`, `BusinessCapability`, `BusinessProcess` and `ServiceDependency` are projections over `ea_business_services`, `ea_capabilities`, `ea_processes` and `ea_relationships`. The Asset Management pages are the GRC face of that one model; there is no second copy. Decisions recorded:
  * **Service → service dependencies are EA edges.** They are stored as `serving` (provider → consumer) between `App\Models\Ea\BusinessService` rows. ArchiMate 3.2 permits a business service serving a business service; `RelationshipValidator` now says so.
  * **Asset → service links are a GRC pivot** (`asset_business_service`), not EA edges. A physical asset is not an ArchiMate element, and `ea_relationships` is what goes to Archi on export.
  * **EA application → asset stays `ea_applications_ext.asset_id`** (contract I-3).
* **Dead dependency edges.** The platform seeder wrote 20 random edges with `source_type = 'service'`. The projection filters on the class name, so the graph never drew one. The migration rewrites any such rows to canonical edges, the seeder no longer writes them, and the demo seeder builds real chains.
* **Inherited criticality.**
  * An asset inherits the criticality of the most critical service it supports. The owner's own rating is never overwritten; `effective_criticality` is the higher of the two.
  * It is recomputed when links change, when a service's criticality changes, and when a service is deleted.
  * The **vulnerability prioritiser** now weighs effective criticality. For example, Kano's RabbitMQ is rated medium by its owner, but it carries NIBSS NIP, so it scores as critical.
* **Dependency integrity.**
  * Adding a dependency that would close a loop is refused. In the walk: "Finacle Core depends on ATM Network" was refused, because ATM Network already depends on Finacle Core.
  * A service whose upstream dependency has a longer RTO shows an **RTO conflict**. In the walk: ATM Network (15 min) depends on Finacle Core (30 min) and Card Issuance (60 min).

## 4. Dashboards and cross-module feeds

* **Executive dashboard.** A new Asset Management panel reads `AssetMetrics`:
  * critical assets;
  * critical assets with open critical or high vulnerabilities;
  * assets past end of life;
  * unowned assets;
  * finds awaiting review;
  * critical and high assets not mapped to a service;
  * a footer with live total, inherited uplifts and services with no asset;
  * an end-of-life watch.

  Each tile links to the filtered register. The "Assets" headline count no longer includes retired assets.
* **Register and Discovery** show the same metrics, so the three screens cannot disagree.
* **EA.** First Bank's 53 EA applications had no physical asset, so the EA panel on every asset page was empty. 29 are now linked, and Finacle Core Banking (Prod) shows its capabilities, zone, TIME, and the out-of-support technology running on it. The links were written without model events, so stewardship seals are unchanged; the panel still shows "Approved".
* **Change Management.** The asset page lists the change requests that name the asset; all 148 demo links are in-bank. Change risk scoring keeps using the owner's rating.

## 5. Demo data

`AssetManagementDemoSeeder` runs after `ChangeManagementDemoSeeder` and is deterministic per bank:
- **Owners, custodians and departments** follow the asset category and the bank's job titles. Before, the Board Risk Committee Chair owned Kano's Finacle. Real hostnames replace slugified names.
- **Deliberate gaps:**
  - two unowned assets per bank and one unclassified;
  - a legacy core still active past its end of life;
  - assets reaching end of life within 90 days;
  - one stale discovered asset per bank;
  - services with no owner and with no supporting asset;
  - RTO conflicts.
- **Business services:**
  - Kano: the 16 existing services gain owners, supporting assets and 20 dependencies. The processes named "Process 1 for service 3" are renamed.
  - First Bank: had none, and now has 16, on its BIAN capabilities, with 19 dependencies.
- **Discovery history** comes from actually running the five sandbox connectors twice (a week ago, then last night) and a CSV import with two rejected rows. Two finds are accepted with owners, one is rejected (a personal Entra app registration), and the rest wait in the queue.
- **Linking and inheritance:** First Bank's EA applications are linked to their assets, then inherited criticality is computed.
- **Platform seeder fixes:** `AtherisPlatformSeeder` no longer writes `'service'`-typed edges or "Process N for service M" names.

## 6. Verification

* **Browser (dev PostgreSQL), as the Kano CISO:**
  * The register shows 61 live assets: 42 found by connectors and 7 rated up by their services. The hygiene tiles filter the table.
  * RabbitMQ shows "Owner rates it medium; inherits critical", source Qualys, a last-seen time, and NIBSS NIP as its service.
  * Ran a Tenable sync from Discovery, and the last-run card updated.
  * In the queue, accepting without an owner was refused ("The owner id field is required…"). Accepting with an owner removed the jump host from the queue.
  * The Business Services map shows 16 services, 2 unowned, 2 without assets and 20 dependencies. The graph draws 52 nodes and 70 edges.
  * ATM Network shows its RTO conflict. The cycle was refused on Finacle Core.
  * Edit shows real dates. An end of life before the purchase date was refused.
  * The dashboard panel and end-of-life watch render.
* **Browser, as the First Bank admin:** Kano's asset and service are 404s, and Discovery shows only First Bank's history. Finacle Core Banking (Prod) shows its change requests and a populated EA panel.
* **Fixed during the walk:**
  * the double-counted find on a connector's second run;
  * retired assets showing in the default register, so "62 matching" disagreed with "61 live";
  * the audit trail filling with "last seen" noise;
  * overlapping asset nodes on the graph;
  * service descriptions quoting the bank slug instead of its name.
* **Automated:**
  * `AssetManagementModuleTest` (12 tests, 215 assertions)
  * the full PHP suite
  * a fresh `migrate:fresh --seed` (SQLite)
  * the route smoke test
  * `npm run build`
* **Dev PostgreSQL:** the migration was applied and `AssetManagementDemoSeeder` run in place.

## 7. Not in this pass

* **Live connectors:** discovery runs in sandbox mode. There are no credential settings and no AD, Graph, AWS, Tenable or Qualys API clients; runs are on demand, not scheduled. The reconciler, provenance and review queue are what a live client would plug into.
* **Stale assets:** they are reported, but not raised as issues or retired automatically.
* **BIA:** RTO and RPO are kept on the service. Business Continuity (a later module) owns the BIA workflow that should write them; the RTO-conflict check is the hook for it.
* **Asset → vendor links:** the plan's `service_dependencies` allowed vendor endpoints. Vendors serve EA applications today; the Vendor Management pass should decide whether assets link to vendors directly.
* **Change risk scoring** reads the owner's criticality, not the effective one. That is a Change Management decision to revisit.
* **`DashboardController::boardPackExport`:** still hard-codes `organization_id => 1`. That belongs to the KRIs & Dashboards pass.
