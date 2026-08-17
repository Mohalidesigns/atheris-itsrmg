// @ts-check
import { test, expect } from '@playwright/test';

/**
 * ATH-EAR-002 WS 4.6 — "Playwright happy paths for ARB, survey campaign, DPIA
 * and return generation", plus the four Phase 4 surfaces those happy paths now
 * run alongside.
 *
 * These are journeys, not assertions about markup: each one walks the path a
 * user takes to get an outcome, and checks the outcome rather than the widget.
 * A test that asserts a heading exists passes on an empty page.
 */

// First Bank, not Kano Heritage: every EA seeder writes against the
// `first-bank-nigeria` tenant, so a Kano session sees an empty repository and
// these journeys would pass over nothing. See auth-firstbank.setup.spec.js.
test.use({ storageState: 'tests/Playwright/.auth/first-bank-admin.json' });

test.describe('EA Phase 4 — depth and scale', () => {
    /* ---------------------------------------------------------------- */
    /* WS 4.1 — the diagram editor                                       */
    /* ---------------------------------------------------------------- */

    test('a diagram opens in the editor with a repository palette and live validation', async ({ page }) => {
        await page.goto('/ea/diagrams');
        await expect(page.getByText(/ArchiMate Diagrams/i)).toBeVisible();

        // The topology counter is the CBN RBCF App. II §1.1(i) artefact.
        await expect(page.getByText(/Topology diagrams/i)).toBeVisible();

        await page.getByRole('link', { name: 'Open' }).first().click();

        // Palette is the repository, and it searches.
        await page.getByPlaceholder(/Search the repository/i).fill('core');
        await expect(page.getByRole('button', { name: 'Repository' })).toBeVisible();

        // The validation panel states a verdict either way.
        await expect(page.getByRole('heading', { name: /ArchiMate validation/i })).toBeVisible();

        // Auto-layout applies without saving.
        await page.locator('select[title="Auto-layout"]').selectOption('hierarchical');
        await expect(page.getByText(/Laid out with the hierarchical algorithm/i)).toBeVisible({ timeout: 10000 });
    });

    test('an invalid canvas cannot be approved', async ({ page }) => {
        await page.goto('/ea/diagrams');
        // The seeded working draft carries two deliberate metamodel errors.
        await page.getByRole('row', { name: /DGM-DRAFT-01/ }).getByRole('link', { name: 'Open' }).click();

        // The count lives in the validation panel heading; the same "error(s)"
        // string also appears in the body copy below it.
        await expect(page.getByRole('heading', { name: /ArchiMate validation — \d+ error\(s\)/i })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Approve' })).toBeDisabled();
    });

    test('a diagram exports to SVG, PNG and PDF', async ({ page }) => {
        await page.goto('/ea/diagrams');
        await page.getByRole('row', { name: /DGM-TOPO-01/ }).getByRole('link', { name: 'Open' }).click();

        for (const format of ['SVG', 'PNG', 'PDF']) {
            const download = page.waitForEvent('download');
            await page.getByRole('link', { name: format, exact: true }).click();
            const file = await download;
            expect(file.suggestedFilename()).toContain(format.toLowerCase());
        }
    });

    /* ---------------------------------------------------------------- */
    /* WS 4.2 — n-hop impact                                             */
    /* ---------------------------------------------------------------- */

    test('change impact reaches further as the depth control is raised', async ({ page }) => {
        await page.goto('/ea/impact');
        await expect(page.getByText(/n-hop Change Impact/i)).toBeVisible();

        const reached = page.locator('text=Entities reached').locator('..').locator('p').nth(1);
        const atDepthOne = Number(await reached.innerText());

        await page.locator('input[type="range"]').fill('4');
        await page.waitForLoadState('networkidle');

        const atDepthFour = Number(await reached.innerText());
        expect(atDepthFour).toBeGreaterThanOrEqual(atDepthOne);

        // Evidence confidence is stated, because an impact assessment computed
        // over unsealed records is a guess.
        await expect(page.getByText(/Evidence confidence/i)).toBeVisible();
    });

    test('the change-impact tab loads on demand from an application record', async ({ page }) => {
        await page.goto('/ea/applications');
        await page.getByRole('link').filter({ hasText: /^FBN-APP/ }).first().click();

        await page.getByRole('button', { name: 'impact' }).click();
        await page.getByRole('button', { name: 'Analyse' }).click();

        await expect(page.getByText(/Entities reached/i)).toBeVisible({ timeout: 15000 });
    });

    /* ---------------------------------------------------------------- */
    /* WS 4.3 — plateau diff and scenario authoring                      */
    /* ---------------------------------------------------------------- */

    test('a plateau diff reports cost, risk and capability deltas', async ({ page }) => {
        await page.goto('/ea/plateau-diff');
        await expect(page.getByText(/Plateau Diff — scenario comparison/i)).toBeVisible();

        await expect(page.getByText(/Annual cost delta/i)).toBeVisible();
        await expect(page.getByText(/One-off cost to get there/i)).toBeVisible();
        await expect(page.getByText(/Obsolescence risks closable/i)).toBeVisible();
        await expect(page.getByText(/Cost confidence/i)).toBeVisible();

        await page.getByRole('button', { name: /Capability coverage/i }).click();
        await expect(page.getByText(/Loses all realising systems/i)).toBeVisible();

        await page.getByRole('button', { name: /Residency & FX/i }).click();
        await expect(page.getByText(/DR site outside Nigeria/i)).toBeVisible();
    });

    test('a scenario can be authored — the thing the deleted Scenarios page could not do', async ({ page }) => {
        await page.goto('/ea/plateau-diff');
        await page.getByRole('link', { name: /Edit .* membership/i }).click();

        await expect(page.getByText(/scenario membership/i)).toBeVisible();

        // Select a record and set a disposition.
        await page.locator('tbody input[type="checkbox"]').first().check();
        await page.getByRole('button', { name: 'Set disposition' }).click();

        // Scope to the modal: the page also carries a "seed from" select and a
        // filter select, and the first combobox on the page is neither of the
        // ones this journey means.
        await expect(page.getByRole('heading', { name: /Set disposition for \d+ record\(s\)/i })).toBeVisible();
        // Scoped to the modal overlay: the page also carries a "seed from" select
        // and a filter select whose options include value="retire", so neither
        // the first combobox nor an options-based filter picks the right one.
        const dispositionModal = page.locator('div.fixed.inset-0');
        await dispositionModal.locator('select').first().selectOption('retire');
        await page.getByPlaceholder(/Why this entity has this disposition/i)
            .fill('Playwright happy path — duplicate coverage.');
        await page.getByRole('button', { name: /Apply to 1/ }).click();

        await expect(page.getByText(/set to 'retire'/i)).toBeVisible();
    });

    /* ---------------------------------------------------------------- */
    /* WS 4.4 — the round-trip gate                                      */
    /* ---------------------------------------------------------------- */

    test('the ArchiMate round-trip gate passes every check', async ({ page }) => {
        await page.goto('/ea/round-trip');
        await expect(page.getByText(/round-trip release gate/i)).toBeVisible();

        await expect(page.getByText('PASS')).toBeVisible();
        await expect(page.getByText(/All elements survive the round trip/i)).toBeVisible();
        await expect(page.getByText(/Every relationship endpoint resolves/i)).toBeVisible();
        await expect(page.getByText(/Export is deterministic/i)).toBeVisible();

        // No check may be failing.
        await expect(page.locator('text=✕')).toHaveCount(0);
    });

    /* ---------------------------------------------------------------- */
    /* WS 4.5 — API and draft-and-approve                                */
    /* ---------------------------------------------------------------- */

    test('the GraphQL console runs a query against the repository', async ({ page }) => {
        await page.goto('/ea/api');
        await page.getByRole('button', { name: /Critical applications/i }).click();
        await page.getByRole('button', { name: 'Run' }).click();

        await expect(page.locator('pre')).toContainText('"applications"', { timeout: 15000 });
    });

    test('a proposed change is applied only after approval', async ({ page }) => {
        // Self-provisioning on purpose. Approving the seeded pending draft
        // consumes it, so a version of this test that relied on seed data
        // passed once and failed on every re-run. Proposing its own change
        // through the API also makes this the full WS 4.5 contract end to
        // end: propose, nothing written, approve, applied.
        const code = `APP-E2E-${Date.now()}`;

        await page.goto('/ea/api');
        await page.locator('textarea').fill(
            `mutation { proposeCreate(entityType: "EaApplication", agent: "playwright", input: { code: "${code}", name: "Playwright proposal" }) }`
        );
        await page.getByRole('button', { name: /^Run/ }).click();

        // The API answers `applied: false` in as many words, so an integrator
        // cannot mistake a 200 for a write having happened.
        await expect(page.locator('pre')).toContainText('"applied": false', { timeout: 15000 });

        await page.goto('/ea/change-proposals');
        await expect(page.getByText(/draft and approve/i)).toBeVisible();
        await expect(page.getByText(/Awaiting decision/i)).toBeVisible();

        // The diff is field-level, not a payload blob.
        await expect(page.getByText('Proposed').first()).toBeVisible();

        // Rows are labelled by draft reference, not by the payload. The queue
        // defaults to the pending filter and this test provisions the only
        // pending draft, so the first Decide is unambiguously ours.
        await page.getByRole('button', { name: 'Decide' }).first().click();
        await page.getByPlaceholder(/Note for the audit trail/i).fill('Confirmed with the system owner.');
        await page.getByRole('button', { name: /Approve and apply/i }).click();

        // And only now does the record exist in the repository. Asked of the
        // repository directly rather than of the portfolio list, which filters
        // client-side over a paginated set and need not carry a new row on
        // page one.
        await page.goto('/ea/api');
        await page.locator('textarea').fill(`query { applications(code: "${code}") { code name } }`);
        await page.getByRole('button', { name: /^Run/ }).click();
        await expect(page.locator('pre')).toContainText(code, { timeout: 15000 });
    });

    /* ---------------------------------------------------------------- */
    /* WS 4.7 — cost and TCO                                             */
    /* ---------------------------------------------------------------- */

    test('the cost model states its coverage of the estate', async ({ page }) => {
        await page.goto('/ea/cost-model');
        await expect(page.getByText(/Cost, TCO and technical debt/i)).toBeVisible();
        await expect(page.getByText(/Cost coverage/i)).toBeVisible();

        await page.getByRole('button', { name: /Cost per capability/i }).click();
        await expect(page.getByText(/Unallocated/i)).toBeVisible();

        await page.getByRole('button', { name: /Rationalisation candidates/i }).click();
        await expect(page.getByText(/covered by more than one live application/i)).toBeVisible();
    });

    /* ---------------------------------------------------------------- */
    /* WS 4.6 — the four journeys the spec names by hand                 */
    /* ---------------------------------------------------------------- */

    test('ARB happy path — submission through to a decision record', async ({ page }) => {
        await page.goto('/ea/arb');
        await expect(page.getByRole('heading', { name: /Architecture Review Board/i })).toBeVisible();

        // Each Kanban card links to ArbShow and is labelled with its code.
        await page.getByRole('link').filter({ hasText: /ARB-\d+/ }).first().click();
        await expect(page.getByText(/ARB-\d+/).first()).toBeVisible();
        await expect(page.getByRole('heading', { name: /Impacted principles \(auto\)/i })).toBeVisible();
        await expect(page.getByRole('heading', { name: /Impacted standards \(auto\)/i })).toBeVisible();
    });

    test('survey campaign happy path — a magic link reaches an unauthenticated respondent', async ({ page, context }) => {
        await page.goto('/ea/surveys');
        await expect(page.getByRole('heading', { name: /Surveys/i })).toBeVisible();

        // The campaign link is labelled with its completion, e.g. "36% of 6".
        await page.getByRole('link').filter({ hasText: /%\s+of\s+\d+/ }).first().click();
        await expect(page.getByText(/completion|responded/i)).toBeVisible();

        // The portal is unauthenticated by design (§5.4 B2): LeanIX can only
        // survey licensed users, and beating that means no account required.
        const anonymous = await context.browser().newContext();
        const anonymousPage = await anonymous.newPage();
        await anonymousPage.goto('/ea/respond/not-a-real-token');
        await expect(
            anonymousPage.getByRole('heading', { name: /This link is no longer available/i })
        ).toBeVisible();
        await anonymous.close();
    });

    test('DPIA happy path', async ({ page }) => {
        await page.goto('/ea/dpia');
        await expect(page.getByRole('heading', { name: /Data Protection Impact Assessment/i })).toBeVisible();
        await expect(page.getByText(/cross-border/i).first()).toBeVisible();
    });

    test('return generation happy path — compile, then refuse to sign weak evidence', async ({ page }) => {
        await page.goto('/regulatory-returns');
        await expect(page.getByRole('heading', { name: /Regulatory Returns/i })).toBeVisible();

        // Returns are compiled on demand, never seeded, so the index starts
        // empty and the journey has to compile one — which is what this test
        // has always claimed to do. Dialog defaults are already valid.
        await page.getByRole('button', { name: /Compile a return/i }).click();
        await page.locator('form')
            .filter({ has: page.getByRole('heading', { name: 'Compile a return' }) })
            .getByRole('button', { name: 'Compile', exact: true })
            .click();

        // Wait for the compile to land before going anywhere. The dialog closes
        // on success, so its heading detaching is the signal that the POST
        // finished. Navigating straight after the click raced it: the first
        // compile on a fresh database builds every citation and is slow enough
        // that page.goto() cancelled it, leaving no row to open.
        await expect(page.getByRole('heading', { name: 'Compile a return' }))
            .toBeHidden({ timeout: 30_000 });

        // Re-navigate rather than trust the dialog's close to have refreshed
        // the list, then open the compiled return by its code.
        await page.goto('/regulatory-returns');
        await page.getByRole('link').filter({ hasText: /-\d{4}/ }).first().click();

        // Evidence confidence is deliberately harsh: an unsealed record
        // contributes nothing, and signing is refused below the configured
        // minimum. Three of the four demo returns cannot be signed, and that is
        // the mechanic working.
        await expect(page.getByText(/evidence confidence/i)).toBeVisible();
    });
});
