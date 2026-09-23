import { type Frame, type Page, expect, test } from '@playwright/test'
import { Api } from '../support/api'
import { createSite, expectBannerVisible, hideWebdriver } from '../support/tracking'
import { expectToast, login, preparePage, reportUrl } from '../support/dashboard'

/**
 * The dashboard's consent editor (theme v2) and its preview, which frames the real banner module
 * (services/dashboard/public/_preview/) fed with what POST …/consent/preview compiles:
 * edit a theme, check the phone preview, publish, and find the same banner on the tracked site.
 */
test.describe.configure({ mode: 'serial' })

const PHONE = { width: 390, height: 844 }
const ACCENT = '#0f766e'
const ACCENT_RGB = 'rgb(15, 118, 110)'
const GAP = 24

let site: { id: number, publicKey: string }
let api: Api

test.beforeAll(async ({}, testInfo) => {
  site = await createSite(`e2e-consent-editor-${testInfo.project.name}-${Date.now()}`, { cookieDomain: 'site.test' })
  api = await Api.login()
  // Published with a v1 theme, as an installation that predates v2 would have it.
  await api.enableCookieLevel(site.id, 'site.test')
})

test.afterAll(async () => {
  await api?.dispose()
})

/** The preview's frame, once its page has loaded. */
async function previewFrame(page: Page): Promise<Frame> {
  await expect(page.getByTestId('preview-frame')).toBeAttached()
  let frame: Frame | null = null
  await expect.poll(() => (frame = page.frames().find(f => f.url().includes('/_preview/banner.html')) ?? null)).not.toBeNull()
  return frame!
}

/** Left and right gaps of the banner inside a document, in that document's CSS pixels. */
async function bannerGaps(frame: Frame | Page): Promise<{ left: number, right: number, viewport: number, button: string }> {
  return frame.evaluate(() => {
    const root = document.querySelector('[data-analytics-banner]')!.shadowRoot!
    const box = root.querySelector('[role="dialog"]')!.getBoundingClientRect()
    const viewport = document.documentElement.clientWidth
    const button = getComputedStyle(root.querySelector('[data-a]')!).backgroundColor
    return { left: box.left, right: viewport - box.right, viewport, button }
  })
}

test('edit a v2 theme, check it on the phone preview, publish it', async ({ page }) => {
  // Everything the preview frame requests: its page, its stylesheet and two scripts, nothing else.
  const frameRequests: string[] = []
  page.on('request', (request) => {
    if (request.frame().url().includes('/_preview/') || request.url().includes('/_preview/')) frameRequests.push(new URL(request.url()).pathname)
  })

  await preparePage(page, site.id)
  await login(page)
  await page.goto(reportUrl('/settings/consent', { site: site.id }))

  // The stored theme is v1; the editor opens it as v2 (theme_v2) and the preview renders it.
  const frame = await previewFrame(page)
  await expect(frame.locator('[data-analytics-banner] [role="dialog"]')).toBeVisible()
  await expect(frame.locator('[data-analytics-banner] h2')).toHaveText('We use cookies')

  // Colours: one accent for Accept and Reject.
  const compiled = page.waitForResponse(r => r.url().includes('/consent/preview') && r.status() === 200)
  await page.locator('input[data-testid="color-theme.colors.accent"]').fill(ACCENT)
  await compiled

  // Layout → Mobile also switches the preview to the 390×844 phone.
  await page.getByTestId('layout-device').getByRole('tab', { name: 'Mobile' }).click()
  await expect(page.getByTestId('preview-frame')).toHaveAttribute('data-device', 'mobile')
  const offset = page.getByTestId('layout-mobile').getByLabel('Distance from the edges (px)')
  const recompiled = page.waitForResponse(r => r.url().includes('/consent/preview') && r.status() === 200)
  await offset.fill(String(GAP))
  await offset.blur()
  await recompiled

  await expect.poll(async () => (await bannerGaps(frame)).left, { message: 'the preview applies the new mobile offset' }).toBeCloseTo(GAP, 0)
  const gaps = await bannerGaps(frame)
  expect(gaps.viewport, 'the phone frame is 390px wide').toBe(PHONE.width)
  expect(Math.abs(gaps.left - gaps.right), `left ${gaps.left}px vs right ${gaps.right}px`).toBeLessThanOrEqual(1)
  expect(gaps.button).toBe(ACCENT_RGB)

  // The reopen button is previewed too. The upgraded v1 theme shows its label everywhere…
  await page.getByTestId('preview-view').getByRole('tab', { name: 'Reopen button' }).click()
  await expect(frame.locator('[data-analytics-banner] [data-o]')).toBeVisible()
  await expect(frame.locator('[data-analytics-banner] [role="dialog"]')).toHaveCount(0)
  await expect(frame.locator('[data-analytics-banner] [data-o] .t')).toBeVisible()

  // …switch phones to the icon only: still named for assistive technology.
  await page.getByTestId('reopen-mobile-variant').click()
  const iconOnly = page.waitForResponse(r => r.url().includes('/consent/preview') && r.status() === 200)
  await page.getByRole('option', { name: 'Icon', exact: true }).click()
  await iconOnly
  await expect(frame.locator('[data-analytics-banner] [data-o] svg')).toBeVisible()
  await expect(frame.locator('[data-analytics-banner] [data-o] .t')).toBeHidden()
  await expect(frame.locator('[data-analytics-banner] [data-o]')).toHaveAttribute('aria-label', 'Cookie preferences')

  // Desktop: 1280px wide, scaled down to fit the column.
  await page.getByTestId('preview-device').getByRole('tab', { name: /Desktop/ }).click()
  await expect(page.getByTestId('preview-frame')).toHaveAttribute('data-device', 'desktop')
  await expect.poll(() => frame.evaluate(() => document.documentElement.clientWidth)).toBe(1280)
  await expect(frame.locator('[data-analytics-banner] [data-o] .t')).toBeVisible()

  // The preview is isolated: an opaque origin (no cookies, no storage) and no requests of its own.
  expect(await frame.evaluate(() => window.origin)).toBe('null')
  expect(await frame.evaluate(() => { try { return document.cookie } catch { return 'blocked' } })).toBe('blocked')
  expect([...new Set(frameRequests)].sort()).toEqual(['/_preview/banner.html', '/_preview/banner.js', '/_preview/preview.css', '/_preview/preview.js'])

  await page.getByRole('button', { name: /Save draft|Salva bozza/ }).click()
  await expectToast(page, /Draft saved|Bozza salvata/)
  await page.getByTestId('open-publish').click()
  await page.getByTestId('confirm-publish').click()
  await expectToast(page, /Banner published|Banner pubblicato/)

  // Saved as v2.
  const state = await api.get(`/sites/${site.id}/consent`)
  expect(state.data.published.theme.colors.accent).toBe(ACCENT)
  expect(state.data.published.theme.layout.mobile.offset).toBe(GAP)
  expect(state.data.published.theme.reopen.mobile.variant).toBe('icon')
})

test('the tracked site shows the published theme @tracker', async ({ page }) => {
  await page.setViewportSize(PHONE)
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await hideWebdriver(page)
  await page.goto(`https://www.site.test/?an_key=${site.publicKey}&an_cb=${Date.now()}`)
  await expectBannerVisible(page)

  const gaps = await bannerGaps(page)
  expect(Math.round(gaps.left)).toBe(GAP)
  expect(Math.abs(gaps.left - gaps.right), `left ${gaps.left}px vs right ${gaps.right}px`).toBeLessThanOrEqual(1)
  expect(gaps.button).toBe(ACCENT_RGB)
})
