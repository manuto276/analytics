import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { console_ } from '../support/console'
import { Api } from '../support/api'
import { createSite, expectBannerVisible, hideWebdriver } from '../support/tracking'
import { login, preparePage, gotoReport } from '../support/dashboard'

/**
 * Accessibility gate (plan §13.2): no serious or critical axe violations on the
 * consent banner or on the dashboard, in both interface languages.
 */
test.describe.configure({ mode: 'serial' })

const PASSWORD = 'Fixture-Passw0rd-2026'

let site: { id: number, publicKey: string }
let italianAdmin: string

test.beforeAll(async ({}, testInfo) => {
  site = await createSite(`e2e-a11y-${testInfo.project.name}-${Date.now()}`, { cookieDomain: 'site.test' })
  const api = await Api.login()
  await api.enableCookieLevel(site.id, 'site.test')
  await api.dispose()

  // Deterministic data so the dashboard has tables, charts and numbers to audit.
  await console_('dev:seed', [`--site=${site.id}`, '--days=7', '--visits=25', '--seed=20260917'])

  // The dashboard applies the locale stored on the user, so Italian needs an
  // Italian user rather than a cookie.
  italianAdmin = `it-${testInfo.project.name}-${Date.now()}@analytics.test`
  await console_('user:create-admin', [`--email=${italianAdmin}`, '--name=Amministratore', '--locale=it'], PASSWORD)
})

/**
 * Serious and critical violations fail the test; minor ones are only reported.
 * `known` lists the signatures of issues already reported to the owning team,
 * so the gate keeps catching everything else.
 */
async function audit(page: Page, { context, known = [] }: { context?: string, known?: RegExp[] } = {}): Promise<void> {
  const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
  const results = await (context ? builder.include(context) : builder).analyze()

  const blocking = results.violations
    .filter(violation => violation.impact === 'serious' || violation.impact === 'critical')
    .map(violation => `${violation.id} (${violation.impact}): ${violation.nodes.map(node => node.target.join(' ')).join(', ')}`)
    .filter(signature => !known.some(pattern => pattern.test(signature)))

  expect(blocking, 'serious or critical axe violations').toEqual([])
}

test('the consent banner is accessible @tracker', async ({ page }, testInfo) => {
  await hideWebdriver(page)
  await page.goto(`https://www.site.test/?an_key=${site.publicKey}&an_cb=${Date.now()}`)
  await expectBannerVisible(page)

  await audit(page, {
    // WebKit alone reports the banner buttons as low contrast: axe cannot read
    // the colours applied through `adoptedStyleSheets` inside the shadow root
    // there, so it falls back to an unknown background. The real values are
    // checked twice elsewhere — by the backend (ConsentService refuses a theme
    // below 4.5:1) and by this same audit in Chromium and Firefox.
    known: testInfo.project.name === 'webkit' ? [/^color-contrast .*data-analytics-banner/] : [],
  })

  // Keyboard flow: the dialog is focusable and Escape rejects.
  await page.locator('[data-analytics-banner] [role="dialog"]').focus()
  await page.keyboard.press('Escape')
  await expect(page.locator('[data-analytics-banner] [role="dialog"]')).toHaveCount(0)
})

test('the dashboard overview is accessible in English', async ({ page }) => {
  await preparePage(page, site.id)
  await login(page)
  await gotoReport(page, '/', 'overview', { site: site.id, period: '7d' })
  await expect(page.getByTestId('overview-stats')).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')

  await audit(page)
})

test('the dashboard overview is accessible in Italian', async ({ page }) => {
  await preparePage(page, site.id)
  await login(page, italianAdmin, PASSWORD)
  await gotoReport(page, '/', 'overview', { site: site.id, period: '7d' })
  await expect(page.locator('html')).toHaveAttribute('lang', 'it')
  await expect(page.getByTestId('overview-stats')).toBeVisible()

  await audit(page)
})

test('a report table page is accessible', async ({ page }) => {
  await preparePage(page, site.id)
  await login(page)
  await gotoReport(page, '/pages', 'pages', { site: site.id, period: '7d' })
  await expect(page.getByRole('table').first()).toBeVisible()

  await audit(page)
})

test('the sign-in page is accessible', async ({ page }) => {
  await preparePage(page, null)
  await page.goto('/login')
  await expect(page.getByRole('heading', { name: /Sign in|Accedi/ })).toBeVisible()

  await audit(page)
})
