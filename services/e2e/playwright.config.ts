import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end configuration for the compose test stack
 * (deploy/docker/compose.base.yml + compose.test.yml, project `analytics-test`).
 *
 * The suite always runs inside the pinned Playwright container, where every
 * host name of the stack is a network alias:
 *   https://analytics.test        service (dashboard + API + /t)
 *   https://www.site.test         tracked site
 *   https://app.site.test         SPA on the same cookie domain
 *   https://other.test            origin that is not registered for the site
 *   https://proxy.site.test       first-party proxy path /stats/
 *
 * TLS: the certificates come from the local CA in deploy/docker/certs. Node
 * trusts it through NODE_EXTRA_CA_CERTS, but installing a CA into the trust
 * store of all three browser engines inside the image is brittle, so the
 * browser contexts use `ignoreHTTPSErrors`. Certificate handling is therefore
 * not covered by these tests; it is covered by the deploy smoke test instead.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'https://analytics.test'

export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  outputDir: './test-results',
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI
    ? [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }], ['blob', { outputDir: 'blob-report' }]]
    : [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
    actionTimeout: 10_000,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
})
