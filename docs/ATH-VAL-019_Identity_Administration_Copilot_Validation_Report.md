# ATH-VAL-019 — Identity & Access, Administration, Copilot (modules 19, 21, 23)

## Findings and fixes

| # | Finding | Fix |
|---|---|---|
| 1 | SCIM accepted any bearer starting `demo-`; routes are CSRF-exempt and unauthenticated, giving cross-tenant user CRUD | Bypass removed. Bearer is sha256-hashed and matched to a non-revoked, unexpired `ScimToken`; its `organization_id` is the tenant for list/show/patch/put/delete/create. Create refuses an email owned by another bank (409), as does a PUT that renames to one. |
| 2 | `SsoConnection`/`ScimToken` unscoped | Added `BelongsToOrganization`; new `IdentityController` (token hash hidden from props). |
| 3 | No way to issue SCIM tokens | `POST identity/scim` (edit platform) generates a random token, stores only the hash, shows it once via flash; `POST identity/scim/{id}/revoke`. UI wired. Migration adds `revoked_at`, `created_by`, `token_prefix`. |
| 4 | SSO buttons did nothing | Add/edit form (protocol, name, IdP entity ID, SSO URL, certificate, enabled) and a real SP metadata XML download (entityID = app URL, ACS = `saml.acs`), gated edit platform. |
| 5 | SAML ACS accepted any `SAMLResponse` and sent the user to the dashboard; unscoped `index()` | No SAML library is installed, so ACS now always refuses (redirect to login with an explanatory error, never logs in). `index()` removed. |
| 6 | API portal listed 14 invented endpoints | Lists the routes actually registered under `api/`. |
| 7 | SSO/SCIM demo data only for Kano, tokens unusable | New `IdentityAdminCopilotDemoSeeder` seeds SSO + a hashed SCIM token for both banks, tenant-guarded. |
| 8 | User admin: any org selectable; edit/update/delete/toggle had no org check; Super Admin assignable | Org forced to the actor's unless Super Admin; other-bank users 404; Super Admin role assignable only by Super Admin; self delete/deactivate already blocked (kept). |
| 9 | Roles are global but any bank admin could edit them | Create/edit/update/destroy are Super Admin only (403); UI buttons hidden otherwise. |
| 10 | Copilot lists/messages unscoped, `find` unscoped, writes hard-coded org 1 | `CopilotConversation` scoped; only the user's own conversations; writes use the user's org; messages are reached only through a scoped conversation. |
| 11 | Canned reply claiming `claude-sonnet-4-6` with fake token counts | Deterministic offline reply from the bank's risks, controls and AUCS catalogue (codes, counts), model `atheris-offline`, tokens 0. UI labelled "Offline assistant — no language model connected". |
| 12 | `?c=` ignored | Honoured (scoped); form follows the active conversation; send redirects to it. |
| 13 | Copilot demo data Kano only | Seeded for both banks. |

## Tests
`tests/Feature/Identity/IdentityAdminCopilotModuleTest.php` (6 tests): demo token 401 and cross-bank isolation; SCIM create 409/in-org; user admin cross-bank 404 / org pinning / Super Admin assignment 403; role change 403 vs Super Admin; Copilot isolation, offline model and org; SSO bank scoping and ACS never authenticating.

## Not done
No real SAML assertion validation (needs a library such as php-saml); OIDC sign-in is not implemented. SCIM Groups endpoints are advertised but not implemented. Seeded SCIM token plaintext is not retained: issue a new one to test.
