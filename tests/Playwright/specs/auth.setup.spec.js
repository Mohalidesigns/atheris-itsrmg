// @ts-check
import { test, expect } from '@playwright/test';

/**
 * Auth setup — login as Kano Heritage CISO and persist storage state
 * for subsequent specs.
 */
test('login as Kano Heritage CISO and store session', async ({ page, context }) => {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('admin@kanoheritage.ng');
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await page.waitForURL('**/dashboard');
    // Anchor on the page heading: the string also appears in the top bar and the
    // breadcrumb, and a bare getByText() matches all three (strict-mode failure).
    await expect(page.getByRole('heading', { name: /Executive Dashboard/i })).toBeVisible();
    await context.storageState({ path: 'tests/Playwright/.auth/kano-heritage-ciso.json' });
});
