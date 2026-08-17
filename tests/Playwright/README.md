# Atheris ITSRM&G — Playwright E2E harness

E2E test suite aligned to phase exit-criteria in `ATHERIS-ITSRMG-IMPLEMENTATION-PLAN-v1.0.md`,
plus the EA rebuild's WS 4.6 journeys from `ATH-EAR-002`.

**Current state: 170 passing** — 56 journeys across desktop, tablet and mobile,
plus 2 auth setups.

## Setup

```bash
cd IT-Security-Risk
php artisan migrate:fresh --seed            # the journeys read the seeded demo estate
php artisan serve --host=127.0.0.1 --port=8000 &
npx playwright install chromium             # one-time
```

The suite needs a **freshly seeded** database. Several journeys write to it — a
CCM test run, a board pack, a marketplace install, a compiled return — so a
database that has drifted a long way from seed will not behave the way the specs
describe. They are written to be re-runnable (anything a journey consumes, it
provisions), but re-seeding is the reliable starting point.

## Running

```bash
npm run e2e                                  # all three viewports
npx playwright test --project=chromium-desktop   # just desktop, while iterating
npm run e2e:ui                               # inspector
npm run e2e:report                           # open the HTML report
```

## Two demo tenants, and they are not interchangeable

Tenancy scoping means a session sees only its own organisation's rows. A spec
pointed at the wrong tenant does not fail loudly — it walks an empty page and
asserts nothing, which is exactly how these specs came to be written against
data they could not see.

| org | login | holds |
| --- | --- | --- |
| 2 | `admin@kanoheritage.ng` | Tier-2 bank demo — risks, CSAT, assets, issues — **and the whole platform layer**: SSO, SCIM, public API, CCM, KRIs, evidence vault, board packs, Copilot, SIEM, FAIR, doc-intel, workflows, core banking, DR, marketplace, theming, obligations |
| 3 | `admin@firstbanknigeria.ng` | Tier-1 bank demo **and the entire EA repository**, including the institution half of the Nigerian reference pack — vendor register, legal-entity tree, channel estate |

The reference pack's market structure — 19 data centres and 16 payment rails —
is seeded untenanted on purpose. `TenantScope` resolves `NULL` rows for every
organisation, so shipped reference content is visible to both tenants without
being duplicated per bank.

All passwords are `password`. Organisation 1 ("Acme Nigeria Ltd") still exists
as the bare organisation DatabaseSeeder creates, but no longer owns demo data:
the platform layer used to be seeded against it, which made those modules
invisible from either bank login.

## Conventions

- Two setup specs (`auth.setup`, `auth-firstbank.setup`) write session state to
  `tests/Playwright/.auth/`. They run as their own `setup` project that the
  device projects depend on, so the suite cannot race its own login. That
  directory is gitignored — it holds live session cookies.
- Every spec opens with `test.use({ storageState: … })` naming the tenant whose
  data it needs. Check the table above before adding one.
- **The suite runs with `workers: 1`.** The journeys share one database and one
  set of auth files; a parallel run of this exact suite fails about two thirds
  of its tests. Do not "speed it up" by raising the worker count.
- The tablet and mobile projects pin `browserName: 'chromium'`. The iPad and
  iPhone device descriptors otherwise select WebKit, which the project names do
  not claim and which is not installed.

## Writing assertions that hold

Two traps this app sets, both of which cost a full debugging pass:

- **Page titles appear four times** — sidebar link, top bar, breadcrumb and
  heading — so a bare `getByText(/Obligations Register/i)` is a strict-mode
  failure, not a landmark. Use `getByRole('heading', { name: … })`.
- **`.first()` is blind to visibility.** It takes the first match in DOM order,
  which is usually the sidebar link, and the sidebar collapses below the desktop
  breakpoint. For anything that must hold on tablet or mobile, use
  `.filter({ visible: true }).first()`.

Prefer asserting the outcome of a journey over the presence of a widget. A test
that only checks a heading exists passes on an empty page — which is exactly how
these specs came to be written against tenants that held none of their data.

## Phases covered

| Phase | Spec file | Tenant | Summary |
| --- | --- | --- | --- |
| 0 | `phase0-foundations.spec.js` | Kano | Dashboard widgets, breadcrumbs on every landing page |
| 1 | `phase1-csat-sso-api.spec.js` | Kano | CBN-CSAT index, SSO/SCIM pages, Public API portal |
| 2 | `phase2-risk-aucs-issues.spec.js` | Kano | Risk Register + heat-map toggle, Risk Show 10 tabs, AUCS, BS graph, Issues Kanban |
| 3 | `phase3-ccm-kri-board.spec.js` | Kano | CCM run-now, Evidence Vault, KRI, Board-pack generator, Pricing |
| 4 | `phase4-copilot-regintel-tprm.spec.js` | Kano | Copilot chat, Reg-intel, Obligations, Vendor Ratings |
| 5 | `phase5-siem-fair-notif.spec.js` | Kano | SIEM, Notifications (CBN 24h / NDPC 72h / NFIU), FAIR Monte Carlo, Vulns |
| 6 | `phase6-docintel-returns.spec.js` | Kano | Doc intel queue, Returns generator |
| 7 | `phase7-workflows-corebanking-dr.spec.js` | Kano | Workflow Studio (React-Flow), Core Banking adapters, DR runbooks & exercises |
| 8 | `phase8-marketplace-theme-integrations.spec.js` | Kano | Marketplace, Theme editor, Feature flags, Integrations Hub + per-connector pages |
| EA | `ea-phase4-depth-and-scale.spec.js` | **First Bank** | ATH-EAR-002 WS 4.1–4.7: diagram editor, n-hop impact, plateau diff, round-trip gate, GraphQL draft-and-approve, cost model, and the four §9 journeys (ARB, survey campaign, DPIA, return generation) |

The Phase 0–8 specs run as Kano Heritage, which now owns both the bank data and
the platform layer, so tightening one of them to assert on *rows* rather than on
page furniture no longer needs a different session.
