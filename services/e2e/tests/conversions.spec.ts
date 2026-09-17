import { expect, test } from '@playwright/test'
import { Api, postConversions } from '../support/api'
import { createApiKey, createSite, expectBannerVisible, hideWebdriver, rollup, trackedCookie } from '../support/tracking'
import { expectToast, gotoReport, login, preparePage, reportUrl } from '../support/dashboard'

/**
 * Goals, server-side conversions, attribution, funnels and campaign costs
 * (plan §13.2, M7): a consented visit arriving from a campaign, a conversion
 * posted by the customer's backend, a follow-up matched by `customer_ref`, and
 * the cost import that turns it into CAC/ROAS.
 */
test.describe.configure({ mode: 'serial' })

const CAMPAIGN = 'autumn'
const CUSTOMER_REF = 'customer-42'

let site: { id: number, publicKey: string }
let api: Api
let apiKey: string
let visitorId: string

test.beforeAll(async ({}, testInfo) => {
  site = await createSite(`e2e-conv-${testInfo.project.name}-${Date.now()}`, { cookieDomain: 'site.test' })
  api = await Api.login()
  await api.enableCookieLevel(site.id, 'site.test')
  apiKey = await createApiKey(site.id)

  await api.createGoal(site.id, { name: 'Pricing seen', type: 'pageview', match: { path: '/pricing.html' } })
  await api.createGoal(site.id, { name: 'Signup click', type: 'event', match: { name: 'signup_click' } })
  await api.createGoal(site.id, { name: 'Purchase', type: 'conversion', match: { name: 'purchase' } })

  const goals = (await api.get(`/sites/${site.id}/goals`)).data
  const ids = Object.fromEntries(goals.map((goal: any) => [goal.name, goal.id]))
  await api.createFunnel(site.id, {
    name: 'Signup funnel',
    scope: 'visit',
    window_days: 30,
    goal_ids: [ids['Pricing seen'], ids['Signup click']],
  })
})

test.afterAll(async () => {
  await api?.dispose()
})

test('a consented visit from a campaign reaches the goals @tracker', async ({ page }) => {
  await hideWebdriver(page)

  // Every collect response of this scenario, so the assertions do not depend on
  // when each engine decides to flush the tracker's batch queue.
  const collected: number[] = []
  page.on('response', (response) => {
    if (response.url().includes('/t/e') && response.request().method() === 'POST') {
      collected.push(response.status())
    }
  })

  // Landing from the campaign, then consent: the funnel steps that follow are
  // consented, so they share one visit and one visitor id.
  await page.goto(`https://www.site.test/?an_key=${site.publicKey}&an_cb=${Date.now()}`
    + `&utm_source=newsletter&utm_medium=email&utm_campaign=${CAMPAIGN}`)
  await expectBannerVisible(page)

  const upgrade = page.waitForResponse(r => r.url().includes('/t/e') && r.request().method() === 'POST')
  await page.locator('[data-analytics-banner] [data-a]').click()
  expect((await upgrade).status()).toBe(202)

  visitorId = (await trackedCookie(page, 'an_vid'))!
  expect(visitorId).toMatch(/^[A-Za-z0-9_-]{22}$/)

  // Step 1 of the funnel: the pricing page, now at the cookie level.
  await page.goto(`https://www.site.test/pricing.html?an_key=${site.publicKey}`)
  await page.waitForTimeout(1500)

  // Step 2: a declarative custom event on the tracked page (data-analytics-event).
  await page.goto(`https://www.site.test/events.html?an_key=${site.publicKey}`)
  await page.getByRole('button', { name: 'Sign up' }).click()
  // The tracker batches for a second before sending.
  await page.waitForTimeout(1500)

  expect(collected.length, 'several batches were collected').toBeGreaterThanOrEqual(3)
  expect([...new Set(collected)], 'every batch was accepted').toEqual([202])
})

test('the customer backend posts conversions, including a follow-up by customer_ref', async () => {
  const first = await postConversions(apiKey, site.publicKey, {
    id: `order-1-${site.id}`,
    name: 'purchase',
    visitor_id: visitorId,
    customer_ref: CUSTOMER_REF,
    value: { amount_minor: 12000, currency: 'EUR' },
    props: { plan: 'pro' },
  })
  expect(first.status).toBe(202)
  expect(first.body).toMatchObject({ accepted: 1, duplicates: 0, rejected: [] })

  // Same id again: idempotent, counted as a duplicate.
  const repeat = await postConversions(apiKey, site.publicKey, {
    id: `order-1-${site.id}`,
    name: 'purchase',
    visitor_id: visitorId,
    value: { amount_minor: 12000, currency: 'EUR' },
  })
  expect(repeat.body).toMatchObject({ accepted: 0, duplicates: 1 })

  // A later order from the same customer, without any visitor id: the
  // customer_ref hash carries the attribution.
  const followUp = await postConversions(apiKey, site.publicKey, [{
    id: `order-2-${site.id}`,
    name: 'purchase',
    customer_ref: CUSTOMER_REF,
    value: { amount_minor: 8000, currency: 'EUR' },
  }])
  expect(followUp.status).toBe(202)
  expect(followUp.body).toMatchObject({ accepted: 1 })

  await rollup(site.id)

  const goals = await api.report(site.id, 'goals', { period: 'today' })
  const purchase = goals.data.rows.find((row: any) => row.name === 'Purchase')
  expect(purchase, 'the Purchase goal has rows').toBeTruthy()
  expect(purchase.conversions).toBe(2)
})

test('attribution credits the campaign of the consented visit', async () => {
  const report = await api.report(site.id, 'attribution', {
    period: 'today',
    model: 'last_non_direct',
    group: 'campaign',
    window: 30,
  })

  const row = report.data.rows.find((r: any) => r.utm_campaign === CAMPAIGN)
  expect(row, `a row for utm_campaign=${CAMPAIGN}`).toBeTruthy()
  expect(row.target_conversions).toBeGreaterThanOrEqual(2)
  expect(row.revenue_minor).toBe(20000)
  expect(report.data.totals.target_conversions).toBeGreaterThanOrEqual(2)
})

test('the funnel counts the steps of the visitor', async () => {
  const funnels = (await api.get(`/sites/${site.id}/funnels`)).data
  const funnel = funnels[0]
  const report = await api.report(site.id, `funnels/${funnel.id}`, { period: 'today' })

  expect(report.data.entered).toBeGreaterThanOrEqual(1)
  expect(report.data.completed).toBeGreaterThanOrEqual(1)
  expect(report.data.steps).toHaveLength(2)
  expect(report.data.steps[0].count).toBeGreaterThanOrEqual(1)
  expect(report.data.steps[1].count).toBeGreaterThanOrEqual(1)
})

test('importing campaign costs turns the conversions into CAC and ROAS', async ({ page }) => {
  await preparePage(page, site.id)
  await login(page)

  const today = new Date().toISOString().slice(0, 10)
  const csv = [
    'day_from,day_to,channel,utm_source,utm_medium,utm_campaign,amount,currency,note',
    `${today},${today},email,newsletter,email,${CAMPAIGN},100.00,EUR,e2e`,
  ].join('\n')

  await page.goto(reportUrl('/settings/costs', { site: site.id }))
  await page.getByRole('button', { name: /Import CSV|Importa CSV/ }).click()
  await page.getByTestId('csv-input').fill(csv)
  await page.getByTestId('csv-preview').click()
  await expect(page.getByTestId('csv-preview-result')).toContainText(/1/)
  await page.getByTestId('csv-confirm').click()
  await expectToast(page, /imported|importate/i)

  // The dashboard shows the imported cost and the derived numbers. The page
  // groups by channel by default; the campaign breakdown is asserted on the API
  // below, where the exact minor units are visible.
  await gotoReport(page, '/attribution', 'attribution', { site: site.id, period: 'today' })
  await expect(page.getByText(/^Cost$|^Costo$/).first()).toBeVisible()
  const emailRow = page.getByRole('row').filter({ hasText: /email/i }).first()
  await expect(emailRow).toBeVisible()
  await expect(emailRow).toContainText(/100/)
  await expect(emailRow).toContainText(/[×x]/)

  const report = await api.report(site.id, 'attribution', {
    period: 'today',
    model: 'last_non_direct',
    group: 'campaign',
    window: 30,
  })
  const campaign = report.data.rows.find((r: any) => r.utm_campaign === CAMPAIGN)
  expect(campaign.cost_minor).toBe(10000)
  expect(campaign.cac_minor).toBe(5000)
  expect(campaign.roas).toBeCloseTo(2, 1)
})

test('the funnels page shows the steps @visual-safe', async ({ page }) => {
  await preparePage(page, site.id)
  await login(page)

  const funnels = (await api.get(`/sites/${site.id}/funnels`)).data
  const response = page.waitForResponse(r => r.url().includes(`/reports/funnels/${funnels[0].id}`) && r.status() === 200)
  await page.goto(reportUrl('/funnels', { site: site.id, period: 'today' }))
  await response

  const steps = page.getByTestId('funnel-steps').locator('li')
  await expect(steps).toHaveCount(2)
  await expect(steps.first()).toContainText('Pricing seen')
  await expect(steps.nth(1)).toContainText('Signup click')
})
