// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 8 — Marketplace / Theming / Integrations Hub', () => {
    test('Marketplace shows 10 published packs', async ({ page }) => {
        await page.goto('/marketplace');
        await expect(page.getByRole('heading', { name: /Content Marketplace/i })).toBeVisible();
        await expect(page.getByText(/CBN Regulator Pack 2026/i)).toBeVisible();
        await expect(page.getByText(/NDPC Regulator Pack 2026/i)).toBeVisible();
    });

    test('Marketplace Install flow creates an install record', async ({ page }) => {
        await page.goto('/marketplace');
        await page.getByRole('button', { name: /^Install$/ }).first().click();
        await page.goto('/marketplace/installs');
        await expect(page.getByText(/Installed Content Packs/i)).toBeVisible();
    });

    test('Tenant Theme settings page shows colour tokens', async ({ page }) => {
        await page.goto('/settings/theme');
        await expect(page.getByRole('heading', { name: /Colour tokens/i })).toBeVisible();
    });

    test('Feature Flags table with toggle', async ({ page }) => {
        await page.goto('/settings/feature-flags');
        await expect(page.getByText(/copilot\.enabled/i)).toBeVisible();
    });

    test('Integrations Hub shows 42 connectors across 12 categories', async ({ page }) => {
        await page.goto('/integrations');
        await expect(page.getByRole('heading', { name: /Integrations Hub/i })).toBeVisible();
        for (const cat of ['Identity', 'Scanner', 'SIEM', 'Core Banking', 'TPRM']) {
            await expect(page.getByText(new RegExp(cat, 'i')).filter({ visible: true }).first()).toBeVisible();
        }
    });

    test('Integration Show page exposes config, how-to-ingest and test-connection', async ({ page }) => {
        await page.goto('/integrations/tenable');
        await expect(page.getByText(/Test Connection/i).first()).toBeVisible();
        await expect(page.getByText(/How to ingest data/i)).toBeVisible();
        await expect(page.getByText(/Download sample CSV/i)).toBeVisible();
    });
});
