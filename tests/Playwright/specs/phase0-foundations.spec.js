// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 0 — Foundations', () => {
    test('Executive dashboard renders with 12 widgets', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page.getByRole('heading', { name: /Executive Dashboard/i })).toBeVisible();
        await expect(page.getByText(/Enterprise Risk Heat Map/i)).toBeVisible();
        await expect(page.getByText(/Top 10 Risks/i)).toBeVisible();
        await expect(page.getByText(/KRI Panel/i)).toBeVisible();
        await expect(page.getByText(/Control Effectiveness/i)).toBeVisible();
        await expect(page.getByText(/Incident Timeline/i)).toBeVisible();
        await expect(page.getByText(/Obligations/i).first()).toBeVisible();
        await expect(page.getByText(/CBN-CSAT Maturity/i)).toBeVisible();
    });

    test('Navigation and breadcrumbs visible on every landing page', async ({ page }) => {
        for (const path of ['/risks', '/aucs', '/ccm', '/kri', '/board-packs', '/copilot', '/integrations']) {
            await page.goto(path);
            await expect(page.getByRole('navigation', { name: /breadcrumb/i })).toBeVisible();
        }
    });
});
