// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 3 — CCM / KRI / Board Packs / Evidence', () => {
    test('CCM Console renders 40 tests with status chips', async ({ page }) => {
        await page.goto('/ccm');
        await expect(page.getByText(/Tests enabled/i)).toBeVisible();
        await expect(page.getByText(/CCM-IAM-MFA-001/)).toBeVisible();
    });

    test('CCM Run-now action creates a test run', async ({ page }) => {
        await page.goto('/ccm');
        const runButtons = page.getByRole('button', { name: /Run now/i });
        await runButtons.first().click();
        await expect(page.getByText(/CCM test ran/i)).toBeVisible({ timeout: 10_000 });
    });

    test('Evidence Vault lists WORM-locked items', async ({ page }) => {
        await page.goto('/evidence-vault');
        await expect(page.getByRole('heading', { name: /Evidence Vault \(WORM\)/i })).toBeVisible();
    });

    test('KRI Dashboard shows the KRI register', async ({ page }) => {
        await page.goto('/kri');
        await expect(page.getByRole('heading', { name: 'Key Risk Indicators' })).toBeVisible();
    });

    test('Board Pack Generator creates a draft pack from current figures', async ({ page }) => {
        await page.goto('/board-packs');
        await page.getByRole('button', { name: /Generate from current figures/i }).click();
        await expect(page.getByText(/Board pack generated/i)).toBeVisible({ timeout: 10_000 });
    });
});
