# ATH-VAL-017 — Core Banking: Validation Report

**Module:** Core Banking (sidebar group 17): read-only adapters onto Finacle, Flexcube, T24, BankOne, Interswitch and NIBSS NIP.

**Tenants:** Kano Heritage Bank (org 2) and First Bank (org 3).

**Locked in by:** `tests/Feature/CoreBanking/CoreBankingModuleTest.php` (5 tests).

## 1. Findings and fixes

| # | Defect | Fix |
|---|---|---|
| 1 | Pages were `PlatformController` stubs with no tenant scope. | `CoreBankingController` on the strict `BelongsToOrganization` models. Records are resolved with `findOrFail` inside the controller, so another bank's id is a 404 and permission middleware runs first. |
| 2 | "Sync technical metadata" did nothing. | `core-banking.sync` runs `CoreBankingConnector::sync`, with a daily `core-banking:sync` command per bank. |
| 3 | Health values were `rand()`. | Deterministic sandbox catalogue per product, varied by integration id and date (crc32). Mode is labelled "Sandbox" since no credentials exist. |
| 4 | Nothing enforced the customer-data embargo. | Artefact allowlist. Any other key (the sandbox BankOne payload includes `customer_records`) is dropped and its name stored in `embargoed_keys`. |
| 5 | No assessment. | Findings: support ended or ending, patch older than 90 days, failed end-of-day, dormant privileged and generic accounts, interface TLS below 1.2, certificate expiry, switch success rate. |
| 6 | Platform changes went unnoticed. | Snapshots are diffed. A version or patch-level change with no approved change request listing the asset gives a high "Unapproved platform change" finding. |
| 7 | Findings went nowhere. | A critical or high finding raises one issue (`source_type core_banking`, registered in `Issue::SOURCES`), not repeated while it is open. |
| 8 | Switched-off adapters could still sync. | A disabled integration or a `core_banking.{product}` flag set to off refuses the sync and records `last_status = failed` with the reason. |
| 9 | Integrations were not linked to assets or owners. | Name, environment, linked asset and owner (tenant-scoped validation), add/edit form, detail page. |
| 10 | Demo data existed for Kano only and was random. | `CoreBankingDemoSeeder` for both banks, driven through the real connector. |
| 11 | Not on the executive dashboard. | `CoreBankingMetrics` and a Core Banking panel on the dashboard. |

## 2. Demo data

- **Kano:** Finacle production and DR, Flexcube legacy, NIBSS NIP, Interswitch.
- **First Bank:** the same plus BankOne (CB-006) and T24 (CB-005).
- **History:** each adapter is synced 14 days ago and again today, so every one has a change history.
- **Findings by design:**
  - Flexcube has support ending soon, a TLS 1.0 interface and a stale patch.
  - NIBSS has a certificate expiring within 30 days.
  - BankOne drops `customer_records`.
  - First Bank's T24 sync is refused (flag off).
- **Patch between captures:**
  - Kano: the Interswitch gateway, which no approved change lists, so it shows the unapproved-change finding and an issue.
  - First Bank: Finacle production, covered by approved changes FBN-CHG-0013 and 0015, which list the asset.
  - Kano's Finacle production is listed by the equivalent approved changes, so patching it would have been covered.
- Deliberate gaps are left in place.

## 3. Verification

- `phpunit tests/Feature/CoreBanking`: 5 tests pass.
- They cover isolation, the allowlist and embargo, the disabled and flag refusal, the unapproved change raising one issue, and the Viewer permission gate.
- `migrate:fresh --seed` and `npm run build` pass.
- The full PHP suite and browser walk-through were not run in this pass.
