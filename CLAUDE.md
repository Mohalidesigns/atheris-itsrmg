# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Atheris ITSRM&G — a multi-tenant IT security risk / GRC platform for Nigerian banks (CBN-CSAT, NDPC, ITSB regulatory returns, Enterprise Architecture repository, TPRM, CCM, KRIs, etc.). Laravel 13 (PHP 8.3+) + Inertia 2 + React 18 + Tailwind 3 + Vite. Auth/RBAC via Breeze + `spatie/laravel-permission`; auditing via `spatie/laravel-activitylog`; routes exposed to JS via Ziggy.

The root `README.md` is the stock Laravel README. Product specs live in the root `ATH*`/`ATHERIS*`/`CBN_CSAT_*` markdown files and in `docs/` (notably `docs/ATH-EAR-002_...Rebuild_Plan_v1.0.md` for the EA module). `design-system.md` is the original UI brief, but its hex values are superseded by `tailwind.config.js` (navy `#0A1F44`, gold `#C9A86A`).

## Commands

```bash
composer dev                      # serve + queue:listen + pail logs + vite, concurrently
composer test                     # config:clear then php artisan test
php artisan test --filter EaTenancyTest          # single test class
php artisan test tests/Feature/Ea/EaDepthAndScaleTest.php
php artisan migrate:fresh --seed  # rebuild DB with full demo estate (both bank tenants)
npm run build                     # vite build — use as the frontend compile check
./vendor/bin/pint path/to/EditedFile.php         # format ONLY the files you touched
```

Route smoke test (skipped in a plain `php artisan test`; needs a seeded DB):

```bash
SMOKE=1 DB_CONNECTION=sqlite DB_DATABASE=/abs/path/seeded.sqlite php artisan test --filter RouteSmokeTest
```

Playwright E2E (journeys over the seeded demo estate; see `tests/Playwright/README.md`):

```bash
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
npx playwright test               # setup project (logins) runs first; desktop/tablet/mobile
npx playwright test tests/Playwright/specs/phase0-foundations.spec.js --project=chromium-desktop
```

`workers: 1` is required — specs share one DB and the auth-state files in `tests/Playwright/.auth/`. Only Chromium is installed (tablet/mobile projects are pinned to it). npm installs need `--legacy-peer-deps`.

A change isn't done until the PHP suite, `migrate:fresh --seed`, and `npm run build` all pass — individual test files passing has repeatedly hidden suite/seed breakage.

## Testing environment

PHPUnit runs on in-memory SQLite (`phpunit.xml`); dev/prod may be MySQL. Migrations must be driver-agnostic: no `SHOW TABLES` / raw `ALTER ... CHANGE`; use `Schema::getTableListing()` (and de-duplicate — on MySQL it spans every visible schema) and `renameColumn`.

## Architecture

**Request flow.** `routes/web.php` (single large file, ~860 lines) defines all Inertia routes inside an `auth` group, each module gated with `permission:view <module>` / `permission:create <module>` middleware. Controllers return `Inertia::render('Module/Page', [...])`; pages resolve from `resources/js/Pages/**/*.jsx` (`resources/js/app.jsx`). `HandleInertiaRequests` shares `auth.user` (incl. `roles`, `permissions`), `auth.organization`, and `flash.{success,error,warning,import_report}`. A 403 on a non-JSON request renders the `Error` Inertia page (`bootstrap/app.php`). `Super Admin` bypasses all gates (`Gate::before` in `AppServiceProvider`).

Before adding a route group, check for route-name collisions (`php artisan route:list --except-vendor`) — e.g. `returns.*` (PlatformController) vs. suite-level `regulatory-returns.*`.

Routes deliberately outside auth/CSRF: SCIM (`scim/v2/*`), SAML ACS/SLS, `ea/mcp/rpc`, and the EA magic-link survey portal `ea.portal.*`. `POST /ea/graphql` is gated in the service (mutation resolver), not on the route, because it also carries reads — `EaAuthorizationTest` tracks these via `GATED_IN_SERVICE` / `UNAUTHENTICATED_BY_DESIGN` lists.

**Navigation.** Sidebar IA is `resources/js/Config/navigation.js` (hrefs are Ziggy route names); EA workspaces are in `resources/js/Config/eaWorkspaces.js`. App shell is `resources/js/Layouts/AuthenticatedLayout.jsx`.

**Multi-tenancy.** Every tenant table uses `organization_id` (the old `tenant_id` column was renamed on 80 tables). Two global-scope traits exist:
- `App\Models\Concerns\BelongsToTenant` → `TenantScope`: matches the user's org **or `organization_id IS NULL`**. NULL rows are intentional platform-shared reference data (e.g. the Nigerian reference architecture's data centres and payment rails) — do not "fix" them to a tenant id.
- `App\Models\Traits\BelongsToOrganization` → `OrganizationScope`: strict org match.

Both auto-fill `organization_id` on create from the authenticated user. `HasTenantIdAlias`/`BelongsToTenant` provide a `tenant_id` accessor/mutator, but it does not reach the query builder — always write `organization_id` in `where`, `DB::table()`, and `updateOrCreate` lookups. `SetTenantContext` middleware binds `current_organization_id` in the container.

**Modules.**
- Core GRC models in `app/Models/`, controllers in `app/Http/Controllers/`.
- CBN-CSAT is a self-contained module under `app/Modules/CBNCSAT/` (own models, services, controller; provider registered in `bootstrap/providers.php`), with routes under the `csat.` group in `web.php`.
- Enterprise Architecture: services in `app/Services/Ea/` (graph resolver, quality seals, ownership, surveys, impact analysis, ArchiMate import/export, GraphQL, MCP server, etc.), models in `app/Models/Ea/`, controllers in `app/Http/Controllers/Ea/`, a single `EaPolicy` mapped in `AppServiceProvider`, config flags in `config/ea.php`.
- Regulatory returns engine: `app/Services/Returns/` — `ReturnCompiler` + per-return `ReturnMapper` subclasses (CSAT, ITSB maturity, NDPC CAR, localisation gap). Signing is refused below `ea.returns.minimum_evidence_confidence`; unsealed evidence contributes zero by design. Citations snapshot seal state at capture time.
- Cross-module integration contracts are domain events in `app/Events/{Core,Ea}` with listeners in `app/Listeners/{Core,Ea}`, all wired in `AppServiceProvider::registerEaContracts()` and toggleable via `config('ea.contracts.*')`.
- Regulatory dates flagged as unverified in the specs are config values, not constants.

## Demo data & seeding

`DatabaseSeeder` order matters (EA estate/wedge/depth seeders must run before `EaStewardshipSeeder`, so break-on-edit correctly invalidates seals). Every new module/page is expected to ship with seeded demo data. Seeders write `organization_id` directly, and "already seeded" guards must be scoped to the tenant, not global `count() > 0` (migrations pre-populate some EA tables).

Demo tenants (all passwords `password`):

| org | login | holds |
|---|---|---|
| 2 | `admin@kanoheritage.ng` | Tier-2 bank data + the whole platform layer (SSO, SCIM, API, CCM, KRIs, evidence vault, board packs, Copilot, SIEM, FAIR, workflows, DR, marketplace, obligations…) |
| 3 | `admin@firstbanknigeria.ng` | Tier-1 bank data + the entire EA repository |

Org 1 (Acme) exists but owns no demo data. Logging in to the wrong tenant shows empty pages, not errors. Demo data deliberately contains gaps (unowned records, mixed seal states, metamodel errors) — don't "clean them up".

## E2E spec conventions

Page titles appear in the sidebar, top bar, breadcrumb and heading — anchor on `getByRole('heading', …)`, never a bare `getByText`. `.first()` usually hits the sidebar link (hidden below desktop width); use `.filter({ visible: true }).first()`. Specs that mutate data must create their own subject rather than consume a single seeded record.
