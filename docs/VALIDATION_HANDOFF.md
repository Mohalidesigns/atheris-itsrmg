# Atheris ITSRM&G — Module Validation Programme: Handoff

**Repo:** `https://github.com/Mohalidesigns/atheris-itsrmg` (private), branch `main`
**Last commit:** `493f44b` "Compliance: end-to-end validation fixes"

## The brief (from the user)

Validate each sidebar module end to end, in order. For every module:
- check every feature, form submission, table, button and modal, and every relationship between records
- check how the module feeds the dashboards
- write up detailed findings and implement the fixes, so the module matches the solution's intended capabilities
- test in the **in-app browser** so the user can watch the process

## Workflow per module (what worked)

1. Map the routes, controllers, models, pages and seeders, then inspect the demo data in MySQL.
2. Walk every page in the in-app browser as the Kano CISO (`admin@kanoheritage.ng` / `password`). Cross-check isolation as First Bank (`admin@firstbanknigeria.ng` / `password`).
3. Fix findings across security/tenancy, lifecycle, forms, tables, demo-data consistency and dashboard feeds.
4. Add `tests/Feature/<Module>/<Module>ModuleTest.php`.
5. Write `docs/ATH-VAL-00N_<Module>_Validation_Report.md`.
6. The Definition of Done is in CLAUDE.md. Run:
   - Pint on the touched files only
   - the full PHP suite: `php vendor/bin/phpunit` (about 26 minutes)
   - `migrate:fresh --seed` against a **scratch SQLite DB**, never the user's MySQL
   - the `RouteSmokeTest` with `SMOKE=1`
   - `npm run build`
7. Commit and push **only after the full suite passes**, then **pause and wait for the user's go-ahead** before starting the next module.

## Done so far

| # | Module | Commit | Report | Tests |
|---|---|---|---|---|
| 1 | IT Risk Management | `4db2b92` | `docs/ATH-VAL-001_IT_Risk_Management_Validation_Report.md` | `tests/Feature/Risk/RiskModuleTest.php` |
| 2 | CBN Cyber Assessment (CSAT) | `af5afd8` | `docs/ATH-VAL-002_CBN_Cyber_Assessment_Validation_Report.md` | `tests/Feature/Csat/CsatModuleTest.php` (9) |
| 3 | Compliance | `493f44b` | `docs/ATH-VAL-003_Compliance_Validation_Report.md` | `tests/Feature/Compliance/ComplianceModuleTest.php` (12) |

Last full suite: **193 tests, 0 failures**. The one skip is the route smoke test, which runs separately and passed.

### Compliance highlights (module 3)

- **Security:**
  - cross-tenant result edits are closed
  - evidence attachment types are allowlisted, and files sit on the private disk behind an authorised download
  - Obligations and the Evidence Vault are tenant-scoped (First Bank used to see Kano's rows)
- **Assessments:**
  - results cover leaf requirements only
  - the score excludes not-assessed items
  - lifecycle: start, complete (only when everything is assessed), cancel and reopen, with locking
- **Gaps:**
  - `ComplianceGapService` raises, re-grades and resolves gaps from results
  - full gap register with codes that keep the bank's prefix
  - one severity and status scale shared with ISMS, via migration `2027_01_11_000001` (this also fixed the ISMS heat grid, which always showed zero)
- **Evidence:** upload, and review where the uploader cannot approve their own evidence.
- **Controls:** control-to-requirement mapping UI, coverage %, and testing history.
- **Demo data:** `ComplianceDemoSeeder` adds about 1,800 real results, 667 mappings, gaps and evidence.

## Next phase: module 4, Regulatory Intelligence

Sidebar group `Regulatory Intelligence` (gated by `view platform`):

| Page | Route |
|---|---|
| Circular Feed | `reg-intel.index` / `reg-intel.show` (PlatformController) |
| Document Intelligence | `doc-intel.index` |
| Returns Centre | `returns.index` (PlatformController) |
| Architecture Returns | `regulatory-returns.index` (`view ea`; ReturnCompiler / ReturnMapper engine in `app/Services/Returns/`) |

**Known items to pick up there:**
- `CsatMapper` reads no CSAT data, and its Vendor queries are cross-tenant. A chip was raised for this earlier.
- Many `PlatformController` actions hard-code `organization_id => 1`, for example `assetDiscoverySync` and `boardPackExport`. Check every write in each module.

**Remaining sidebar order after that:** Security Operations → Issues & Remediation → Asset Management → Vendor Management → Policy Management → ISMS → PCI Management → Business Continuity → Continuous Monitoring → KRIs & Dashboards → the rest of the sidebar (`resources/js/Config/navigation.js`).

## Open follow-ups (not blocking)

- **ReportController** counts a treatment status that doesn't exist (`not_started`). A chip was raised for this.
- **CSAT spec items not yet built:**
  - BR-AW-05 deadline reminders (60/30/14/7 days)
  - BR-MA-04 comment threads
  - section assignments and the evidence UI
  - CISO email/phone encryption
- **Compliance:** no notifications for gap due dates or evidence expiry. The ISMS Statement of Applicability is not yet linked to assessment results, which belongs in the ISMS module pass.

## Environment gotchas (important)

- **iCloud eviction:** the project lives in iCloud-synced Documents, and iCloud keeps evicting files to "dataless". Reads and `git` then hang, and `phpunit.xml` and `.git/objects` have both been affected.
  - Detect with `ls -lO <file>` (look for the `dataless` flag).
  - Fix by copying from a fresh `git clone` of the remote in the scratchpad, or `git clone --bare` and copy its pack into `.git/objects/pack`, then `rm` the dataless loose objects.
  - Never `mv` dataless files to another volume; that forces a download and hangs.
  - **Recommended:** move the project out of iCloud Documents.
- **MySQL** is XAMPP: `/Applications/XAMPP/xamppfiles/bin/mysql -uroot it-security-risk`. The dev DB has been migrated and seeded in place, and includes the browser-test data (e.g. KHB-GAP-046, completed CBN assessment #10).
- **Dev servers** come from `.claude/launch.json` (uncommitted): `atheris` on port 8010. The Vite dev server's port 5180 clashes with another project, so use `npm run build` (delete `public/hot`) and serve built assets.
- **Tests:** ParaTest isn't installed, so run `php vendor/bin/phpunit`. Never name a test helper `result()`, because it clashes with a final method on PHPUnit's TestCase.
