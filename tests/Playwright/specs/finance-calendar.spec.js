// @ts-check
import { test, expect } from '@playwright/test';

/**
 * IT Financial Management P2 — the unified IT finance calendar (FIN-06.06, FIN-07.05): the four views, the Passed
 * panel, the vendor filter, the signed .ics feed and the CSV export. Read-only over the seeded Kano Heritage estate;
 * the "Fortinet" contract is the seeded notice-deadline-passed case.
 */
test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('IT Finance — calendar', () => {
    test('views, Passed panel, filter, subscribe feed and CSV export', async ({ page, request }) => {
        await page.goto('/it-finance/calendar');
        await expect(page.getByRole('heading', { name: 'IT Finance Calendar' }).filter({ visible: true }).first()).toBeVisible();

        // the four views
        const views = page.getByRole('group', { name: 'Calendar view' });
        for (const label of ['Month', 'Quarter', 'Year', 'Timeline']) {
            await views.getByRole('button', { name: label, exact: true }).click();
            await expect(views.getByRole('button', { name: label, exact: true })).toHaveAttribute('aria-pressed', 'true');
        }
        await views.getByRole('button', { name: 'Month', exact: true }).click();

        // Passed lists the seeded Fortinet contract; clicking through opens its page
        const passedFortinet = page.locator('li', { hasText: 'Fortinet' }).getByRole('link').filter({ visible: true }).first();
        await expect(passedFortinet).toBeVisible();
        await passedFortinet.click();
        await expect(page.getByRole('heading', { name: /Fortinet/ }).filter({ visible: true }).first()).toBeVisible();
        await page.goBack();
        await expect(page.getByRole('heading', { name: 'IT Finance Calendar' }).filter({ visible: true }).first()).toBeVisible();

        // the vendor filter narrows the events to that vendor
        await views.getByRole('button', { name: 'Year', exact: true }).click();
        await expect(page).toHaveURL(/view=year/);
        const all = await page.locator('[data-event], a[href*="/it-finance/contracts/"]').count();
        await page.locator('#cf-vendor').selectOption({ label: 'Fortinet' });
        await expect(page).toHaveURL(/vendor_id=\d+/);
        await expect(page.locator('#cf-vendor')).toHaveValue(/\d+/);
        const narrowed = await page.locator('[data-event], a[href*="/it-finance/contracts/"]').count();
        expect(narrowed).toBeLessThanOrEqual(all);

        // the subscribe link is a signed feed that serves text/calendar
        const feedUrl = await page.locator('#cal-feed-url').inputValue();
        expect(feedUrl).toContain('/it-finance/calendar.ics?');
        const feed = await request.get(feedUrl, { headers: { cookie: '' } });
        expect(feed.status()).toBe(200);
        expect(feed.headers()['content-type']).toContain('text/calendar');
        const body = await feed.text();
        expect(body.startsWith('BEGIN:VCALENDAR')).toBe(true);
        expect(body).toContain('END:VCALENDAR');
        expect(body).toContain('Fortinet');
        const tampered = await request.get(feedUrl.replace(/u=\d+/, 'u=1'), { headers: { cookie: '' } });
        expect(tampered.status()).toBe(403);

        // CSV export triggers a download
        await page.locator('summary', { hasText: 'Export' }).click();
        const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'CSV' }).click()]);
        expect(download.suggestedFilename()).toBe('it-finance-calendar.csv');
    });
});
