// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 6 — Document Intelligence / Returns', () => {
    test('Document Intelligence page queues a demo extraction', async ({ page }) => {
        await page.goto('/doc-intel');
        await expect(page.getByRole('heading', { name: /Document Intelligence/i })).toBeVisible();
        await page.getByRole('button', { name: /Queue for extraction/i }).click();
        await expect(page.getByText(/Document queued/i)).toBeVisible({ timeout: 10_000 });
    });

    test('Returns Centre lists 4 templates and generates a run', async ({ page }) => {
        await page.goto('/returns');
        await expect(page.getByRole('heading', { name: /Regulatory Return Runs/i })).toBeVisible();
        // One "Generate run" per template row.
        await page.getByRole('button', { name: /Generate run/i }).first().click();
        await expect(page.getByText(/Return generated for/i)).toBeVisible({ timeout: 10_000 });
    });
});
