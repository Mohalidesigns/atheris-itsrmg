# ATH-VAL-018 — Reports, Integrations Hub, Settings (modules 18, 20, 22)

## Findings and fixes

| # | Finding | Fix |
|---|---|---|
| 1 | Risk report counted treatments with status `not_started`, which does not exist | `ReportFigures::treatmentStats()` uses draft/submitted/approved; label now "Not started (draft / submitted / approved)" |
| 2 | Executive `open` equalled `total` | `open` = risks in identified/assessed/treating/under_review |
| 3 | Risk report total included closed risks; executive did not | Both pages use `ReportFigures::riskStats()` (active basis) |
| 4 | Scheduled reports was a static placeholder | `scheduled_reports` / `scheduled_report_runs`, CRUD + pause (create/edit reports), `reports:run-due` (daily) records a key-figures snapshot and advances `next_run_at`. Delivery is not configured and the UI says so. Types limited to executive, risks, compliance |
| 5 | Connector status / last sync hard-coded | `integration_connections` (per bank, config encrypted); catalog status default `not_configured` |
| 6 | "Save configuration" posted to the test route | `integrations.save` (edit platform); secrets never returned, masked as `*_set` flags; blank secret keeps stored value |
| 7 | Test connection used `rand()`, view-only permission | Deterministic sandbox probe (fails naming missing base URL / key; otherwise records "connected", labelled sandbox); gated by edit platform |
| 8 | KPI tiles used `Math.random()`; webhook hard-coded `khb-tenant` | Tiles show status / last tested / last result; webhook uses the bank slug |
| 10 | Theme and custom fields read unscoped | `BelongsToOrganization` on `TenantTheme`, `CustomField`; theme falls back to platform defaults |
| 11 | Theme page read-only | `settings.theme.update` (hex + URL validated). Truth: the app shell does not yet read the tokens, so the page says changes are stored, not applied |
| 12 | "+ Add field" dead | Store/delete (edit platform); entity from fixed list, slug key unique per bank+entity, types text/number/date/select |
| 13 | Any bank admin toggled flags for all banks | Bank toggle writes an override row; page shows default / override / effective; global default toggle is Super Admin only. `FeatureFlag::enabledFor()` added and used by `CoreBankingConnector` |
| 14 | Notification toggles were placeholders | `users.notification_preferences` json, PATCH for the signed-in user only; six honest toggles; UI notes email delivery is not configured |
| 15 | Theme/field seeds only for Kano | Moved to `ReportsIntegrationsSettingsDemoSeeder` (both banks, tenant-scoped guards), plus schedules + runs and 3 connections per bank (connected, configured, failed) |

## Tests
`tests/Feature/Settings/ReportsIntegrationsSettingsModuleTest.php` (7 tests): real report statuses and one basis; schedule isolation + `reports:run-due`; integration save masks secrets and is bank-isolated; test fails without required fields, Viewer gets 403; theme/custom fields bank-scoped; flag override isolation and Super Admin-only default; notification prefs for self only.

## Not done
No email delivery for scheduled reports; connector data sync remains unimplemented (sandbox probe only); theme tokens are not yet consumed by the app shell.
