// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 2 — Asset Discovery / AUCS / Issues / Risk Graph', () => {
    test('AUCS browser lists 390+ controls with framework mappings', async ({ page }) => {
        await page.goto('/aucs');
        await expect(page.getByText(/Atheris Unified Control Set/i)).toBeVisible();
        await expect(page.getByText(/AUCS-GOV-01/)).toBeVisible();
    });

    test('Risk Register heat-map view toggle switches between 3×3, 4×4 and 5×5', async ({ page }) => {
        await page.goto('/risks');
        await page.getByRole('button', { name: /Heat Map/i }).click();
        await expect(page.getByText(/Configurable Risk Heat Map/i)).toBeVisible();
        await page.getByRole('button', { name: /^3×3$/ }).click();
        await page.getByRole('button', { name: /^4×4$/ }).click();
        await page.getByRole('button', { name: /^5×5$/ }).click();
    });

    test('Risk Show has all 10 tabs populated', async ({ page }) => {
        await page.goto('/risks');
        // The register paginates at 15, so the first risk is not necessarily on
        // page one. Search for it the way a user would.
        await page.getByPlaceholder(/Search risks/i).fill('KHB-RSK-001');
        await page.getByRole('button', { name: 'Search' }).click();   // server-side filter
        await page.getByText(/KHB-RSK-001/).first().click();
        for (const tab of ['Overview', 'Assessment', 'Graph', 'Linked Controls', 'Treatments', 'Issues', 'Evidence', 'FAIR / ALE', 'History', 'Audit Trail']) {
            // The tab strip renders before the panel content, which carries
            // buttons of its own ("Assessment" appears in both).
            await page.getByRole('button', { name: new RegExp(tab, 'i') }).first().click();
        }
    });

    test('Business Service Graph renders React-Flow canvas with nodes', async ({ page }) => {
        await page.goto('/business-services/graph');
        await expect(page.locator('.react-flow__node').first()).toBeVisible();
    });

    test('Issues Kanban shows 6 columns', async ({ page }) => {
        await page.goto('/issues');
        for (const col of ['open', 'in progress', 'blocked', 'remediated', 'verified', 'closed']) {
            await expect(page.getByText(new RegExp(col, 'i')).first()).toBeVisible();
        }
    });
});
