// @ts-check
import { test, expect } from '@playwright/test';

/**
 * Auth setup — First Bank of Nigeria admin, and persist storage state.
 *
 * The EA module has its own pilot tenant. Every EA seeder — phases 1 to 4, the
 * wedge estate, stewardship and the depth demo — writes against
 * `first-bank-nigeria`, while the older platform specs run as Kano Heritage.
 * A Kano session on an EA page is not a smaller version of this: tenancy
 * scoping means it sees only the shared reference rows, so the EA journeys
 * would walk through empty tables and assert nothing.
 */
test('login as First Bank admin and store session', async ({ page, context }) => {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('admin@firstbanknigeria.ng');
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await page.waitForURL('**/dashboard');

    // Anchor on the heading: the dashboard title also appears in the top bar
    // and the breadcrumb, and a bare getByText() matches all three.
    await expect(page.getByRole('heading', { name: /Dashboard/i })).toBeVisible();

    await context.storageState({ path: 'tests/Playwright/.auth/first-bank-admin.json' });
});
