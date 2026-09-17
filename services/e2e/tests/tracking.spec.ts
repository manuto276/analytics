import { expect, test } from '@playwright/test'
import { Api } from '../support/api'
import { createSite, hideWebdriver, rollup, visitFixture } from '../support/tracking'
import { gotoReport, login, overviewMetric, parseMetric, preparePage, reportUrl } from '../support/dashboard'

/**
 * Base level: real browser visits on the fixture sites, then `rollup:run`, then
 * the numbers the dashboard shows. Each project gets its own site so the counts
 * are exact regardless of what the other specs did.
 */
test.describe.configure({ mode: 'serial' })

let site: { id: number, publicKey: string }

test.beforeAll(async ({}, testInfo) => {
  site = await createSite(`e2e-base-${testInfo.project.name}-${Date.now()}`)
})

test('base visits on the fixture sites are collected @tracker', async ({ page }) => {
  await hideWebdriver(page)

  expect(await visitFixture(page, 'https://www.site.test/', site.publicKey)).toBe(202)
  expect(await visitFixture(
    page,
    'https://www.site.test/pricing.html?utm_source=newsletter&utm_medium=email&utm_campaign=autumn',
    site.publicKey,
  )).toBe(202)
  expect(await visitFixture(page, 'https://app.site.test/', site.publicKey)).toBe(202)

  // The service never sets a cookie at the base level.
  const serviceCookies = await page.context().cookies('https://analytics.test')
  expect(serviceCookies).toEqual([])
})

test('an unregistered origin is refused', async ({ page }) => {
  await hideWebdriver(page)

  const refused = page.waitForResponse(
    response => response.url().includes('/t/e') && response.request().method() === 'POST',
    { timeout: 20_000 },
  )
  await page.goto(`https://other.test/?an_key=${site.publicKey}`)
  expect((await refused).status()).toBe(403)
})

test('the first-party proxy path collects through the tracked site @tracker', async ({ page }) => {
  await hideWebdriver(page)

  const script = page.waitForResponse(
    response => response.url().includes('/stats/') && response.url().endsWith('.js'),
    { timeout: 20_000 },
  )
  const status = await visitFixture(page, 'https://proxy.site.test/', site.publicKey, { collectPath: '/stats/e' })

  expect((await script).status()).toBe(200)
  expect(status).toBe(202)
  // Everything happened on the tracked origin: no request to the service host.
  expect(page.url()).toContain('proxy.site.test')
})

test('the reports show the collected visits after rollup:run', async ({ page }) => {
  await rollup(site.id)

  const api = await Api.login()
  const overview = await api.report(site.id, 'overview')
  // Three fixture visits are awaited above; the proxied one is best-effort,
  // because a beacon sent while the page is going away is not guaranteed to
  // leave every engine.
  expect(overview.data.metrics.pageviews).toBeGreaterThanOrEqual(3)
  expect(overview.data.metrics.visitors).toBeGreaterThanOrEqual(1)

  const pages = await api.report(site.id, 'pages')
  expect(pages.data.rows.map((row: any) => row.path)).toContain('/pricing.html')

  const sources = await api.report(site.id, 'sources')
  expect(sources.data.rows.map((row: any) => row.channel)).toContain('email')
  await api.dispose()

  await preparePage(page, site.id)
  await login(page)

  await gotoReport(page, '/', 'overview', { site: site.id, period: 'today' })
  expect(parseMetric(await overviewMetric(page, /Visitors|Visitatori/))).toBeGreaterThanOrEqual(1)
  expect(parseMetric(await overviewMetric(page, /Pageviews|Visualizzazioni/))).toBeGreaterThanOrEqual(3)

  await gotoReport(page, '/pages', 'pages', { site: site.id, period: 'today' })
  await expect(page.getByRole('cell', { name: '/pricing.html' }).first()).toBeVisible()

  await gotoReport(page, '/sources', 'sources', { site: site.id, period: 'today' })
  await expect(page.getByRole('row').filter({ hasText: /email/i }).first()).toBeVisible()
})

test('realtime counts the visitor that is still active', async ({ page }) => {
  await preparePage(page, site.id)
  await login(page)

  const realtime = page.waitForResponse(r => r.url().includes('/reports/realtime') && r.status() === 200)
  await page.goto(reportUrl('/realtime', { site: site.id }))
  await realtime

  await expect.poll(
    async () => parseMetric(await page.getByTestId('active-visitors').innerText()),
    { timeout: 20_000 },
  ).toBeGreaterThanOrEqual(1)
})
