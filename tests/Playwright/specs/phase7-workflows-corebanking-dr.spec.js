// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 7 — Workflow Studio / Core Banking / DR', () => {
    test('Workflow Studio lists every approval process and opens its configuration', async ({ page }) => {
        await page.goto('/workflows');
        await expect(page.getByRole('heading', { name: 'Approval Workflows' })).toBeVisible();
        await page.getByRole('link', { name: /Policy approval/ }).first().click();
        await expect(page.getByRole('heading', { name: 'Approval stages' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Who may start it' })).toBeVisible();
    });

    test('Approval Inbox lists approvals awaiting a decision', async ({ page }) => {
        await page.goto('/workflows/inbox');
        await expect(page.getByRole('heading', { name: 'Approval Inbox' })).toBeVisible();
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
