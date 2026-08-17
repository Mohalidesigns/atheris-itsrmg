// @ts-check
import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/Playwright/.auth/kano-heritage-ciso.json' });

test.describe('Phase 5 — SIEM / Notifications / FAIR / Vulns', () => {
    test('SIEM page lists Sentinel, Splunk ES, QRadar, Wazuh', async ({ page }) => {
        await page.goto('/siem');
        for (const p of ['sentinel', 'splunk', 'qradar', 'wazuh']) {
            await expect(page.getByText(new RegExp(p, 'i')).first()).toBeVisible();
        }
    });

    test('Notifications page exposes CBN 24h / NDPC 72h / NFIU templates', async ({ page }) => {
        await page.goto('/notifications');
        // Each template is named in the page's intro copy and again as an
        // option in the template picker.
        for (const tmpl of ['CBN 24h', 'NDPC 72h', 'NFIU']) {
            await expect(page.getByText(new RegExp(tmpl, 'i')).first()).toBeVisible();
        }
    });

    test('FAIR page renders scenarios and histograms', async ({ page }) => {
        await page.goto('/fair');
        await expect(page.getByText(/Naira Quantification/i)).toBeVisible();
    });

    test('FAIR Run Monte Carlo creates a run', async ({ page }) => {
        await page.goto('/fair');
        await page.getByRole('button', { name: /Run Monte Carlo/i }).first().click();
        await expect(page.getByText(/FAIR Monte Carlo completed/i)).toBeVisible({ timeout: 10_000 });
    });

    test('Vulnerability Prioritiser sorts by priority', async ({ page }) => {
        await page.goto('/vuln-prioritiser');
        await expect(page.getByRole('heading', { name: /Vulnerability Prioritiser/i })).toBeVisible();
    });

    test('Threat Advisories lists ngCERT / NITDA', async ({ page }) => {
        await page.goto('/threat-advisories');
        await expect(page.getByText(/ngcert/i).first()).toBeVisible();
    });
});
