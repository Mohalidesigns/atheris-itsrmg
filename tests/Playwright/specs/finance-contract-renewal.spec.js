// @ts-check
import { test, expect } from '@playwright/test';

/**
 * IT Financial Management P2 — the contract renewal journey (ATH-FIN-001 FIN-06.01, .03, .05, FIN-07.01).
 *
 * Procurement registers a contract, opens its renewal and recommends a renegotiated renewal; the Head of IT
 * approves it (a single stage, because it needs no CFO); Procurement executes it and the successor term appears.
 * The spec creates its own subject contract, so it can be re-run on the same database. It logs in inline with
 * fresh contexts rather than the stored state, because three different people take part.
 */
test.use({ storageState: { cookies: [], origins: [] } });

/** @param {import('@playwright/test').Browser} browser @param {string} baseURL @param {string} email */
async function loginAs(browser, baseURL, email) {
    const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    page.on('dialog', (dialog) => dialog.accept()); // submit / withdraw ask for confirmation
    await page.goto('/login');
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await page.waitForURL('**/dashboard');

    return { context, page };
}

const iso = (/** @type {Date} */ d) => d.toISOString().slice(0, 10);

test.describe('IT Finance — contract renewal journey', () => {
    test('procurement registers, recommends and executes; the Head of IT approves', async ({ browser }, testInfo) => {
        const baseURL = String(testInfo.project.use.baseURL || process.env.APP_URL || 'http://127.0.0.1:8000');
        const title = `E2E renewal ${Date.now()}`;
        const today = new Date();
        const ends = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate() + 60));
        const commences = new Date(Date.UTC(ends.getUTCFullYear() - 1, ends.getUTCMonth(), ends.getUTCDate() + 1));

        // 1. Procurement registers and activates the contract
        const proc = await loginAs(browser, baseURL, 'procurement@kanoheritage.ng');
        let page = proc.page;
        await page.goto('/it-finance/contracts/create');
        await expect(page.getByRole('heading', { name: 'New contract' }).filter({ visible: true }).first()).toBeVisible();
        await page.locator('#k-title').fill(title);
        await page.locator('#k-vendor').selectOption({ label: 'MTN Nigeria' });
        const owner = await page.locator('#k-owner option', { hasText: 'Head of Digital Channels' }).first().getAttribute('value');
        await page.locator('#k-owner').selectOption(String(owner));
        await page.locator('#k-from').fill(iso(commences));
        await page.locator('#k-to').fill(iso(ends));
        await page.locator('#k-notice').fill('30');
        await page.locator('#k-val').fill('2000000');
        await page.getByLabel('Save and activate').check();
        await page.getByRole('button', { name: 'Create and activate' }).click();

        await expect(page.getByRole('heading', { name: new RegExp(title) }).filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByText('In renewal window').filter({ visible: true }).first()).toBeVisible();

        // 2. Open the renewal and recommend a renegotiated renewal with a small uplift
        await page.getByRole('button', { name: 'Open renewal' }).filter({ visible: true }).first().click();
        await expect(page.getByRole('heading', { name: /REN-\d+/ }).filter({ visible: true }).first()).toBeVisible();
        const renewalUrl = page.url();
        await page.getByText('Renew (renegotiated)').filter({ visible: true }).first().click();
        await page.locator('#r-term').fill('12');
        await page.locator('#r-val').fill('2100000');
        await page.locator('#r-just').fill('Business-critical connectivity; the vendor accepted a 5% uplift against a 12% ask.');
        await page.getByRole('button', { name: 'Save recommendation' }).click();
        const submit = page.getByRole('button', { name: 'Submit for approval' }).filter({ visible: true }).first();
        await expect(submit).toBeVisible(); // appears once the decision and justification are saved
        await submit.click();
        await expect(page.getByText('Submitted').filter({ visible: true }).first()).toBeVisible();
        await proc.context.close();

        // 3. The Head of IT finds it under Renewals and approves: one stage, because no CFO is needed
        const head = await loginAs(browser, baseURL, 'headofit@kanoheritage.ng');
        page = head.page;
        await page.goto('/it-finance/renewals');
        await expect(page.getByRole('heading', { name: 'Renewals' }).filter({ visible: true }).first()).toBeVisible();
        // Follow the row's link by its href: a click right after the list renders was occasionally swallowed.
        const renewalHref = await page.getByRole('row', { name: new RegExp(title) }).getByRole('link', { name: /REN-\d+/ }).first().getAttribute('href');
        await page.goto(String(renewalHref));
        await expect(page.getByRole('heading', { name: new RegExp(title) }).filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByText('Needs CFO')).toHaveCount(0);
        await page.getByRole('button', { name: 'Approve' }).filter({ visible: true }).first().click();
        await page.getByRole('dialog').getByRole('button', { name: 'Approve' }).click();
        await expect(page.getByText('Approved').filter({ visible: true }).first()).toBeVisible();
        await head.context.close();

        // 4. Procurement executes; the successor term is linked and the old contract shows Renewed
        const again = await loginAs(browser, baseURL, 'procurement@kanoheritage.ng');
        page = again.page;
        await page.goto(new URL(renewalUrl).pathname);
        await page.getByRole('button', { name: 'Execute' }).filter({ visible: true }).first().click();
        await page.getByRole('dialog').getByRole('button', { name: 'Execute' }).click();
        await expect(page.getByText('Executed').filter({ visible: true }).first()).toBeVisible();
        const successor = page.getByRole('link', { name: /CON-\d+/ }).filter({ hasText: title }).first();
        await expect(successor).toBeVisible();

        await page.getByRole('link', { name: 'Open contract' }).filter({ visible: true }).first().click();
        await expect(page.getByRole('heading', { name: new RegExp(title) }).filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByText('Renewed', { exact: true }).filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: /Renewed to .*CON-\d+/ })).toBeVisible();
        await again.context.close();
    });
});
