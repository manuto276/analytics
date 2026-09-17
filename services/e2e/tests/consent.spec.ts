import { expect, test } from '@playwright/test'
import { Api } from '../support/api'
import { createSite, expectBannerVisible, forget, hideWebdriver, trackedCookie, visitFixture } from '../support/tracking'
import { expectToast, login, preparePage, reportUrl } from '../support/dashboard'

/**
 * Consent banner and cookie level (plan §13.2, M6):
 * accept → first-party cookie shared across the tracked subdomains, reject →
 * silence until a material change, reopen link, and /t/forget.
 *
 * The banner lives in an open shadow root, so CSS selectors pierce it:
 * `[data-analytics-banner] [data-a]` is the accept button ([data-r] reject,
 * [data-x] close, [data-o] the floating reopen button).
 */
test.describe.configure({ mode: 'serial' })

let site: { id: number, publicKey: string }
let api: Api

test.beforeAll(async ({}, testInfo) => {
  site = await createSite(`e2e-consent-${testInfo.project.name}-${Date.now()}`, { cookieDomain: 'site.test' })
  api = await Api.login()
  await api.enableCookieLevel(site.id, 'site.test')
})

test.afterAll(async () => {
  await api?.dispose()
})

const fixture = (path: string, extra = ''): string =>
  `${path}${path.includes('?') ? '&' : '?'}an_key=${site.publicKey}&an_cb=${Date.now()}${extra}`

test('the banner asks before anything is stored @tracker', async ({ page }) => {
  await hideWebdriver(page)
  await page.goto(fixture('https://www.site.test/'))

  await expectBannerVisible(page)
  await expect(page.locator('[data-analytics-banner] [role="dialog"]')).toHaveAttribute('aria-modal', 'false')
  await expect(page.locator('[data-analytics-banner] h2')).toHaveText('We use cookies')

  // Nothing is stored before the choice: no visitor cookie yet.
  expect(await trackedCookie(page, 'an_vid')).toBeUndefined()
})

test('accepting shares the visitor id across the tracked subdomains @tracker', async ({ page }) => {
  await hideWebdriver(page)
  await page.goto(fixture('https://www.site.test/?utm_source=newsletter&utm_medium=email&utm_campaign=autumn'))
  await expectBannerVisible(page)

  const upgrade = page.waitForResponse(
    response => response.url().includes('/t/e') && response.request().method() === 'POST',
    { timeout: 20_000 },
  )
  await page.locator('[data-analytics-banner] [data-a]').click()
  expect((await upgrade).status()).toBe(202)

  const visitorId = await trackedCookie(page, 'an_vid')
  expect(visitorId, 'an_vid is written after consent').toMatch(/^[A-Za-z0-9_-]{22}$/)
  expect(await trackedCookie(page, 'an_consent')).toMatch(/^1\.\d+\.a\./)
  // The service domain still holds nothing.
  expect(await page.context().cookies('https://analytics.test')).toEqual([])

  // Same cookie domain (.site.test): the app subdomain sees the same visitor.
  await visitFixture(page, 'https://app.site.test/', site.publicKey)
  expect(await page.evaluate(() => (window as any).analytics.getVisitorId())).toBe(visitorId)

  // No banner on the subdomain: the decision travelled with the cookie.
  await expect(page.locator('[data-analytics-banner] [role="dialog"]')).toHaveCount(0)
})

test('rejecting is remembered until a material change republishes the banner @tracker', async ({ page }) => {
  await hideWebdriver(page)
  await page.goto(fixture('https://www.site.test/'))
  await expectBannerVisible(page)

  await page.locator('[data-analytics-banner] [data-r]').click()
  await expect(page.locator('[data-analytics-banner] [role="dialog"]')).toHaveCount(0)
  expect(await trackedCookie(page, 'an_consent')).toMatch(/^1\.\d+\.r\./)
  expect(await trackedCookie(page, 'an_vid')).toBeUndefined()

  // Reload: the decision is remembered, only the floating reopen button shows.
  await page.goto(fixture('https://www.site.test/'))
  await expect(page.locator('[data-analytics-banner] [data-o]')).toBeVisible()
  await expect(page.locator('[data-analytics-banner] [role="dialog"]')).toHaveCount(0)

  // The footer link reopens the preferences.
  await page.locator('footer a[data-analytics-consent]').click()
  await expect(page.locator('[data-analytics-banner] [role="dialog"]')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.locator('[data-analytics-banner] [role="dialog"]')).toHaveCount(0)

  // An operator publishes a material change from the dashboard…
  const dashboard = await page.context().newPage()
  await preparePage(dashboard, site.id)
  await login(dashboard)
  await dashboard.goto(reportUrl('/settings/consent', { site: site.id }))

  const title = dashboard.getByLabel('Title', { exact: true })
  await expect(title).toBeVisible()
  await title.fill('We updated our cookies')
  await dashboard.getByRole('button', { name: /Save draft|Salva bozza/ }).click()
  await expectToast(dashboard, /Draft saved|Bozza salvata/)

  await dashboard.getByTestId('open-publish').click()
  await dashboard.getByTestId('material-change').click()
  await expect(dashboard.getByTestId('next-version')).toContainText(/2/)
  await dashboard.getByTestId('confirm-publish').click()
  await expectToast(dashboard, /Banner published|Banner pubblicato/)
  await dashboard.close()

  // …and the visitor is asked again, with the new text.
  await page.goto(fixture('https://www.site.test/'))
  await expectBannerVisible(page)
  await expect(page.locator('[data-analytics-banner] h2')).toHaveText('We updated our cookies')
})

test('forget erases the visitor and falls back to the base level @tracker', async ({ page }) => {
  await hideWebdriver(page)
  await page.goto(fixture('https://www.site.test/'))
  await expectBannerVisible(page)
  await page.locator('[data-analytics-banner] [data-a]').click()

  const visitorId = await trackedCookie(page, 'an_vid')
  expect(visitorId).toBeTruthy()

  // The tracker API sends /t/forget and wipes the first-party cookies.
  const forgotten = page.waitForResponse(
    response => response.url().includes('/t/forget'),
    { timeout: 20_000 },
  )
  await page.evaluate(() => (window as any).analytics.consent.forget())
  expect((await forgotten).status()).toBe(202)

  await expect.poll(async () => await trackedCookie(page, 'an_vid')).toBeUndefined()
  expect(await page.evaluate(() => (window as any).analytics.getVisitorId())).toBeNull()

  // The endpoint itself is idempotent for an unknown visitor id.
  expect(await forget(site.publicKey, visitorId!)).toBe(202)
})

test('consent decisions are counted in the consent report', async () => {
  // The banner reports every interaction as a base-level `cs` event; the
  // counters are additive per day and consent version, so the report totals
  // cover both the version published in this file and the material change.
  const report = await api.report(site.id, 'consent', { period: 'today' })
  const totals = report.data.totals

  expect(totals.shown).toBeGreaterThanOrEqual(2)
  expect(totals.accepted).toBeGreaterThanOrEqual(1)
  expect(totals.rejected).toBeGreaterThanOrEqual(1)
  expect(totals.dismissed).toBeGreaterThanOrEqual(1)
  expect(totals.reopened).toBeGreaterThanOrEqual(1)
  expect(totals.acceptance_rate).toBeGreaterThan(0)
})
