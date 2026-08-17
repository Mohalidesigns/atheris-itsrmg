// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * Atheris ITSRM&G — Playwright E2E configuration
 * Aligned to the Implementation Plan's phase exit-criteria.
 *
 * Two structural constraints, both learned the hard way:
 *
 * 1. **These specs share one database.** They are journeys over the seeded demo
 *    estate, and several of them write to it — running a CCM test, generating a
 *    board pack, installing a marketplace pack, compiling a return, approving a
 *    change proposal. Run them concurrently and they interleave on the same
 *    rows: a full-parallel run of the same suite that passes serially fails
 *    around two thirds of its tests. Hence `workers: 1`. It costs a few minutes
 *    and buys a result that means something.
 *
 * 2. **Auth state is a file, and the specs read it.** The setup specs write
 *    `tests/Playwright/.auth/*.json`; every other spec loads one. Without an
 *    ordering guarantee the suite races its own login, so the setup specs are
 *    their own project and the device projects declare a dependency on it.
 *
 * The two demo tenants are not interchangeable: the platform modules and the
 * bank data belong to Kano Heritage, the EA repository to First Bank. A session
 * pointed at the wrong one sees an empty page and asserts nothing — see
 * tests/Playwright/README.md.
 */
export default defineConfig({
    testDir: './tests/Playwright/specs',
    timeout: 30_000,
    expect: { timeout: 5_000 },
    fullyParallel: false,
    workers: 1, // shared database — see note 1 above
    retries: 1,
    reporter: [['list'], ['html', { outputFolder: 'tests/Playwright/report', open: 'never' }]],
    use: {
        baseURL: process.env.APP_URL || 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        ignoreHTTPSErrors: true,
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth.*\.setup\.spec\.js/,
        },
        {
            name: 'chromium-desktop',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
            dependencies: ['setup'],
            testIgnore: /auth.*\.setup\.spec\.js/,
        },
        // Tablet and mobile run the same journeys at narrower viewports. The
        // specs assert on content rather than on layout, but the app's
        // navigation collapses below the desktop breakpoint, so treat a failure
        // here as a question about the spec's navigation path before assuming a
        // responsive bug.
        {
            name: 'chromium-tablet',
            // The device descriptors select WebKit, which these project names do
            // not claim and which is not installed here; pin the engine to match
            // the name and keep the viewport and touch profile.
            use: { ...devices['iPad (gen 7)'], browserName: 'chromium' },
            dependencies: ['setup'],
            testIgnore: /auth.*\.setup\.spec\.js/,
        },
        {
            name: 'chromium-mobile',
            use: { ...devices['iPhone 14 Pro'], browserName: 'chromium' },
            dependencies: ['setup'],
            testIgnore: /auth.*\.setup\.spec\.js/,
        },
    ],
});
