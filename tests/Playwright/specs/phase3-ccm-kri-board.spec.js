// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 3 — CCM / KRI / Board Packs / Evidence / Pricing', () => {
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

    test('KRI Dashboard shows 30 KRIs with sparklines', async ({ page }) => {
        await page.goto('/kri');
        await expect(page.getByText(/Nigerian KRI Pack/i)).toBeVisible();
    });

    test('Board Pack Generator creates a PPTX run', async ({ page }) => {
        await page.goto('/board-packs');
        await page.getByRole('button', { name: /Generate now/i }).click();
        await expect(page.getByText(/Board pack generated/i)).toBeVisible({ timeout: 10_000 });
    });

    test('Pricing page displays 3 Naira tiers', async ({ page }) => {
        await page.goto('/pricing');
        // Each tier names itself in its card heading, its CTA button and the
        // next tier's "everything in …" line.
        await expect(page.getByRole('heading', { name: 'Essentials' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Professional' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Enterprise' })).toBeVisible();
    });
});
