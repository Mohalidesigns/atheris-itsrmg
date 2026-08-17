// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 1 — CBN-CSAT + SSO/SCIM + Public API', () => {
    test('CBN-CSAT index lists Kano Heritage assessment', async ({ page }) => {
        await page.goto('/csat');
        await expect(page.getByText(/CBN/i).filter({ visible: true }).first()).toBeVisible();
    });

    test('SSO connections page shows Entra ID, Okta, Ping', async ({ page }) => {
        await page.goto('/identity/sso');
        // Each provider names itself in the page's intro copy as well as in its
        // own card heading, so anchor on the card.
        await expect(page.getByRole('heading', { name: /Entra ID/i })).toBeVisible();
        await expect(page.getByRole('heading', { name: /Okta/i })).toBeVisible();
        await expect(page.getByRole('heading', { name: /Ping Identity/i })).toBeVisible();
    });

    test('SCIM tokens page renders token list', async ({ page }) => {
        await page.goto('/identity/scim');
        await expect(page.getByText(/SCIM v2 Provisioning/i)).toBeVisible();
    });

    test('Public API portal exposes OpenAPI endpoints + quickstart', async ({ page }) => {
        await page.goto('/identity/api');
        await expect(page.getByRole('heading', { name: /Atheris Public API/i })).toBeVisible();
        // The endpoint appears in the reference table and again in the curl
        // quickstart block.
        await expect(page.getByText(/\/api\/v1\/risks/).first()).toBeVisible();
        await expect(page.getByText(/oauth\/token/).first()).toBeVisible();
    });
});
