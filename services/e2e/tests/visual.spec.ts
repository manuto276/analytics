import { expect, test } from '@playwright/test'
import { console_ } from '../support/console'
import { createSite } from '../support/tracking'
import { gotoReport, login, preparePage } from '../support/dashboard'

/**
 * Screenshot baselines (plan §13.2 "screenshot baselines vs template look").
 *
 * Only Chromium, light colour mode, reduced motion and a seeded dataset with a
 * fixed seed, so the pixels depend on the build and not on the day. Volatile
 * areas (site name with a timestamp, dates, chart) are masked.
 *
 * Regenerate after an intentional design change:
 *   make e2e-snapshots
 */
test.describe.configure({ mode: 'serial' })

let site: { id: number, publicKey: string }

test.beforeAll(async ({}, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium', 'baselines are kept for one engine only')
  site = await createSite(`e2e-visual-${testInfo.project.name}-${Date.now()}`)
  await console_('dev:seed', [`--site=${site.id}`, '--days=7', '--visits=30', '--seed=20260917'])
})

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'chromium', 'baselines are kept for one engine only')
  await page.setViewportSize({ width: 1280, height: 900 })
  await preparePage(page, site.id)
})

test('the sign-in page matches its baseline @visual', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()

  await expect(page).toHaveScreenshot('login.png', {
    fullPage: true,
    maxDiffPixelRatio: 0.05,
    threshold: 0.3,
    animations: 'disabled',
  })
})

test('the overview KPI cards match their baseline @visual', async ({ page }) => {
  await login(page)
  await gotoReport(page, '/', 'overview', { site: site.id, period: '7d' })

  const stats = page.getByTestId('overview-stats')
  await expect(stats).toBeVisible()
  // The seeded numbers are deterministic; only the loading skeletons are not.
  await expect(stats.locator('.animate-pulse')).toHaveCount(0)

  await expect(stats).toHaveScreenshot('overview-stats.png', {
    maxDiffPixelRatio: 0.05,
    threshold: 0.3,
    animations: 'disabled',
  })
})
