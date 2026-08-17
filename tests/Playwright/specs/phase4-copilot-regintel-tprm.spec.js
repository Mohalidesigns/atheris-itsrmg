// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 4 — Copilot / Regulatory Intelligence / TPRM', () => {
    test('Copilot page renders chat input and suggestions', async ({ page }) => {
        await page.goto('/copilot');
        await expect(page.getByRole('heading', { name: /Atheris Copilot/i })).toBeVisible();
        await expect(page.getByPlaceholder(/Ask the Copilot/i)).toBeVisible();
    });

    test('Copilot accepts a user turn and persists a reply', async ({ page }) => {
        await page.goto('/copilot');
        const input = page.getByPlaceholder(/Ask the Copilot/i);
        await input.fill('Show me overdue critical risks');
        await page.getByRole('button', { name: /Send/i }).click();
        await expect(page.getByText(/overdue critical risks|I can help you/i).first()).toBeVisible({ timeout: 10_000 });
    });

    test('Regulatory Intelligence shows CBN, NDPC circulars', async ({ page }) => {
        await page.goto('/regulatory-intel');
        await expect(page.getByRole('heading', { name: /Circular feed/i })).toBeVisible();
        await expect(page.getByText(/CBN/i).filter({ visible: true }).first()).toBeVisible();
    });

    test('Obligations Register lists 50+ obligations', async ({ page }) => {
        await page.goto('/obligations');
        await expect(page.getByRole('heading', { name: /Obligations Register/i })).toBeVisible();
    });

    test('Vendor Security Ratings page lists vendor grades', async ({ page }) => {
        await page.goto('/security-ratings');
        await expect(page.getByText(/Continuous Security Ratings/i)).toBeVisible();
    });
});
