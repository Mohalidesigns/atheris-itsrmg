// @ts-check
import { test, expect } from '@playwright/test';

/**
 * IT Financial Management P1 — the budget journey (ATH-FIN-001 FIN-02.01, .03, .08, FIN-07.01, .02).
 *
 * Finance starts a draft budget for a cost centre in the open cycle, adds a line, and submits it; the approvers
 * (Head of IT, CFO, CIO and, above the board threshold, the MD, as many stages as the workflow needs) approve it; Finance sets it as the baseline.
 * The spec creates its own subject: it tries each cost centre in turn until one has no open version in the cycle,
 * so it can be re-run on the same database. Three different people take part, so it logs in inline with fresh
 * contexts rather than stored state, against the Kano Heritage tenant.
 */
test.use({ storageState: { cookies: [], origins: [] } });

/** @param {import('@playwright/test').Browser} browser @param {string} baseURL @param {string} email */
async function loginAs(browser, baseURL, email) {
    const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    page.on('dialog', (dialog) => dialog.accept()); // submit / baseline ask for confirmation
    await page.goto('/login');
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await page.waitForURL('**/dashboard');

    return { context, page };
}

/** The input/select/textarea that follows a <label> in the line modal (the labels carry no `for`). */
const field = (/** @type {import('@playwright/test').Locator} */ scope, /** @type {string} */ label) =>
    scope.locator(`xpath=.//label[normalize-space()="${label}"]/following-sibling::*[self::input or self::select or self::textarea][1]`);

test.describe('IT Finance — budget journey', () => {
    test('finance drafts, adds a line and submits; the approvers approve; finance baselines', async ({ browser }, testInfo) => {
        test.setTimeout(180_000); // four logins, and earlier runs leave cost centres taken
        const baseURL = String(testInfo.project.use.baseURL || process.env.APP_URL || 'http://127.0.0.1:8000');
        const title = `E2E budget ${Date.now()}`;

        // 1. Finance creates a draft in the first cost centre that has no open version in the cycle
        const fin = await loginAs(browser, baseURL, 'finance@kanoheritage.ng');
        let page = fin.page;
        await page.goto('/it-finance/budgets/create');
        await expect(page.getByRole('heading', { name: 'New budget' }).filter({ visible: true }).first()).toBeVisible();
        await page.locator('#b-cycle').selectOption({ index: 1 });
        const ccValues = await page.locator('#b-cc option').evaluateAll((opts) => opts.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter(Boolean));
        expect(ccValues.length).toBeGreaterThan(0);
        let created = false;
        for (const cc of ccValues) {
            await page.locator('#b-cc').selectOption(cc);
            await page.locator('#b-title').fill(title);
            await page.getByRole('button', { name: 'Create draft' }).click();
            // Either the edit page opens, or "already has an open budget version in the cycle": try the next cost centre
            await page.waitForLoadState('networkidle');
            if (/\/it-finance\/budgets\/\d+\/edit/.test(page.url())) {
                created = true;
                break;
            }
        }
        expect(created, 'a cost centre with no open version in the cycle').toBe(true);
        const budgetId = /\/budgets\/(\d+)\//.exec(page.url())?.[1];
        const showUrl = `/it-finance/budgets/${budgetId}`;

        // 2. Add a line on the Lines tab
        await page.goto(showUrl);
        await expect(page.getByRole('heading', { name: /BUD-\d+/ }).filter({ visible: true }).first()).toBeVisible();
        await page.getByRole('button', { name: 'Add line' }).filter({ visible: true }).first().click();
        const modal = page.getByRole('dialog');
        await expect(modal.getByRole('heading', { name: 'Add line' })).toBeVisible();
        await field(modal, 'Description').fill('E2E core switch refresh');
        await field(modal, 'Cost pool').selectOption({ index: 1 });
        await field(modal, 'Tower').selectOption({ index: 1 });
        await field(modal, 'Annual amount').fill('900000');
        await field(modal, 'Justification').fill('End-of-life hardware replaced on the vendor roadmap.');
        await modal.getByRole('button', { name: 'Save' }).click();
        await expect(modal).toBeHidden();
        await expect(page.getByText('E2E core switch refresh').filter({ visible: true }).first()).toBeVisible();

        // 3. Submit for approval
        await page.getByRole('button', { name: 'Submit for approval' }).filter({ visible: true }).first().click();
        await expect(page.getByText('Submitted', { exact: true }).filter({ visible: true }).first()).toBeVisible();
        await fin.context.close();

        // 4. The approvers decide in stage order; a stage the workflow does not need shows no Approve button
        for (const email of ['headofit@kanoheritage.ng', 'cfo@kanoheritage.ng', 'cio@kanoheritage.ng', 'md@kanoheritage.ng']) {
            const approver = await loginAs(browser, baseURL, email);
            await approver.page.goto(showUrl);
            await expect(approver.page.getByRole('heading', { name: /BUD-\d+/ }).filter({ visible: true }).first()).toBeVisible();
            const approve = approver.page.getByRole('button', { name: 'Approve', exact: true }).filter({ visible: true }).first();
            if (await approve.isVisible()) {
                await approve.click();
                await approver.page.getByRole('dialog').getByRole('button', { name: 'Approve' }).click();
                await expect(approver.page.getByRole('dialog')).toBeHidden();
            }
            await approver.context.close();
        }

        // 5. Finance sets it as the baseline; the page shows it immutable
        const again = await loginAs(browser, baseURL, 'finance@kanoheritage.ng');
        page = again.page;
        await page.goto(showUrl);
        const baseline = page.getByRole('button', { name: 'Set as baseline' }).filter({ visible: true }).first();
        await expect(baseline).toBeVisible();
        await baseline.click();
        await expect(page.getByText('Baseline', { exact: true }).filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByText(/is baseline and immutable/i).filter({ visible: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Add line' })).toHaveCount(0);
        await again.context.close();
    });
});
