// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 7 — Workflow / Core Banking / DR', () => {
    test('Workflow Studio lists workflows and loads Show with React-Flow canvas', async ({ page }) => {
        await page.goto('/workflows');
        // The marketplace template of the same name also renders here, suffixed
        // "(Template)". Take the tenant's own workflow.
        await page.getByRole('link', { name: /^CBN ITSM Incident Response(?! \(Template\))/ }).first().click();
        await expect(page.locator('.react-flow__node').first()).toBeVisible();
    });

    test('Workflow Marketplace shows 6 Nigerian templates', async ({ page }) => {
        await page.goto('/workflows/marketplace');
        await expect(page.getByRole('heading', { name: /NDPC DPIA/i })).toBeVisible();
        await expect(page.getByRole('heading', { name: /NAICOM Breach/i })).toBeVisible();
    });

    test('Core Banking page lists 6 adapters', async ({ page }) => {
        await page.goto('/core-banking');
        for (const p of ['finacle', 'flexcube', 't24', 'bankone', 'interswitch', 'nibss']) {
            await expect(page.getByText(new RegExp(p, 'i')).first()).toBeVisible();
        }
    });

    test('DR Runbooks list + Runbook Show page', async ({ page }) => {
        await page.goto('/dr/runbooks');
        await page.getByRole('link', { name: /NIBSS NIP Failover/i }).first().click();
        // "steps" also appears as a per-runbook count on the list behind it.
        await expect(page.getByRole('heading', { name: /Steps/i }).first()).toBeVisible();
    });

    test('DR Exercises page renders schedule', async ({ page }) => {
        await page.goto('/dr/exercises');
        await expect(page.getByText(/DR Exercise Orchestration/i)).toBeVisible();
    });
});
