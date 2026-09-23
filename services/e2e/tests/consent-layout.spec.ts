import { type Page, expect, test } from '@playwright/test'
import { Api } from '../support/api'
import { createSite, expectBannerVisible, hideWebdriver } from '../support/tracking'

/**
 * Consent banner layout and theming (docs/integration/consent-banner.md):
 * mobile layouts with equal side gaps for every position, the reopen button
 * variants per device, and Accept/Reject parity under custom CSS (Garante B2).
 *
 * One site is republished with a different theme per case: publishing clears the
 * server's cached consent block and `an_cb` busts the browser cache of the bundle.
 */
test.describe.configure({ mode: 'serial' })

const PHONE = { width: 390, height: 844 }
const DESKTOP = { width: 1280, height: 800 }

let site: { id: number, publicKey: string }
let api: Api

test.beforeAll(async ({}, testInfo) => {
  site = await createSite(`e2e-consent-layout-${testInfo.project.name}-${Date.now()}`, { cookieDomain: 'site.test' })
  api = await Api.login()
  await api.updateSite(site.id, { cookie_level_enabled: true, cookie_domain: 'site.test' })
})

test.afterAll(async () => {
  await api?.dispose()
})

const V1 = { bg: '#ffffff', fg: '#111827', ac: '#1d4ed8', acf: '#ffffff', rad: 8 }

async function openFixture(page: Page): Promise<void> {
  // No entrance animation: boxes are measured where they settle, not mid-slide.
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await hideWebdriver(page)
  await page.goto(`https://www.site.test/?an_key=${site.publicKey}&an_cb=${Date.now()}`)
  await expectBannerVisible(page)
}

/** Left and right distance of an element from the edges of the layout viewport. */
async function sideGaps(page: Page, selector: string): Promise<{ left: number, right: number, width: number }> {
  const box = await page.locator(selector).boundingBox()
  expect(box, `${selector} is rendered`).not.toBeNull()
  const viewport = await page.evaluate(() => document.documentElement.clientWidth)
  return { left: box!.x, right: viewport - (box!.x + box!.width), width: box!.width }
}

const DIALOG = '[data-analytics-banner] [role="dialog"]'
const REOPEN = '[data-analytics-banner] [data-o]'

for (const pos of ['bottom', 'bottom-left', 'bottom-right'] as const) {
  test(`v1 theme "${pos}": the banner has equal side gaps on a 390px phone @tracker`, async ({ page }) => {
    await api.publishConsent(site.id, { theme: { ...V1, pos } })
    await page.setViewportSize(PHONE)
    await openFixture(page)

    const gaps = await sideGaps(page, DIALOG)
    expect(Math.abs(gaps.left - gaps.right), `left ${gaps.left}px vs right ${gaps.right}px`).toBeLessThanOrEqual(1)
    expect(gaps.left).toBeGreaterThanOrEqual(8)
    const box = (await page.locator(DIALOG).boundingBox())!
    expect(box.y + box.height, 'inside the viewport').toBeLessThanOrEqual(PHONE.height)
  })
}

for (const [position, gap] of [['bottom', 16], ['top', 16], ['center', 16], ['sheet', 0]] as const) {
  test(`v2 mobile position "${position}": equal side gaps on a 390px phone @tracker`, async ({ page }) => {
    await api.publishConsent(site.id, {
      theme: { layout: { desktop: { position: 'bottom-right' }, mobile: { position, offset: 16 } } },
    })
    await page.setViewportSize(PHONE)
    await openFixture(page)

    const gaps = await sideGaps(page, DIALOG)
    expect(Math.abs(gaps.left - gaps.right), `left ${gaps.left}px vs right ${gaps.right}px`).toBeLessThanOrEqual(1)
    expect(Math.round(gaps.left)).toBe(gap)
    const box = (await page.locator(DIALOG).boundingBox())!
    expect(box.y).toBeGreaterThanOrEqual(0)
    expect(box.y + box.height).toBeLessThanOrEqual(PHONE.height)
    if (position === 'top') expect(Math.round(box.y)).toBe(16)
    if (position === 'sheet') expect(Math.round(box.y + box.height)).toBe(PHONE.height)
  })
}

test('the desktop position still applies above the breakpoint @tracker', async ({ page }) => {
  await api.publishConsent(site.id, {
    theme: { layout: { desktop: { position: 'bottom-right', maxWidth: 480, offset: 24 }, mobile: { position: 'sheet' } } },
  })
  await page.setViewportSize(DESKTOP)
  await openFixture(page)
  const gaps = await sideGaps(page, DIALOG)
  expect(Math.round(gaps.right)).toBe(24)
  expect(Math.round(gaps.width)).toBe(480)
})

test('the reopen button shows an icon on phones and text on desktop @tracker', async ({ page }) => {
  await api.publishConsent(site.id, {
    theme: {
      reopen: {
        icon: 'shield',
        desktop: { variant: 'text', position: 'bottom-left' },
        mobile: { variant: 'icon', position: 'bottom-right' },
      },
    },
  })
  await page.setViewportSize(DESKTOP)
  await openFixture(page)
  await page.locator('[data-analytics-banner] [data-r]').click()

  const reopen = page.locator(REOPEN)
  await expect(reopen).toBeVisible()
  await expect(reopen).toHaveAttribute('aria-label', 'Cookie preferences')
  await expect(reopen.locator('svg')).toBeHidden()
  await expect(reopen.getByText('Cookie preferences')).toBeVisible()
  const desktop = await sideGaps(page, REOPEN)
  expect(Math.round(desktop.left)).toBe(16)

  await page.setViewportSize(PHONE)
  await expect(reopen.locator('svg')).toBeVisible()
  await expect(reopen.getByText('Cookie preferences')).toBeHidden()
  const phone = await sideGaps(page, REOPEN)
  expect(Math.round(phone.right)).toBe(16)
  // Icon-only is still named for assistive technology, and reopens the banner.
  await expect(page.getByRole('button', { name: 'Cookie preferences' })).toBeVisible()
  await reopen.click()
  await expect(page.locator(DIALOG)).toBeVisible()
})

test('custom CSS styles Accept and Reject identically (Garante B2) @tracker', async ({ page }) => {
  await api.publishConsent(site.id, {
    theme: {
      css: [
        '.banner { max-width: 420px; }',
        '.title { font-size: 20px; letter-spacing: 0.02em; }',
        '.button { border-radius: 999px; padding: 12px 20px; font-weight: 700; text-transform: uppercase; }',
        '.actions > .button:hover { background-color: #0b3a8f; }',
      ].join('\n'),
    },
  })
  await page.setViewportSize(DESKTOP)
  await openFixture(page)

  const styles = async (selector: string): Promise<Record<string, string>> =>
    await page.locator(selector).evaluate((el) => {
      const s = getComputedStyle(el)
      const out: Record<string, string> = {}
      for (const name of Array.from(s)) out[name] = s.getPropertyValue(name)
      return out
    })
  const accept = await styles('[data-analytics-banner] [data-a]')
  const reject = await styles('[data-analytics-banner] [data-r]')
  expect(accept['border-top-left-radius']).toBe('999px')
  expect(accept['text-transform']).toBe('uppercase')
  expect(accept).toEqual(reject)

  const a = (await page.locator('[data-analytics-banner] [data-a]').boundingBox())!
  const r = (await page.locator('[data-analytics-banner] [data-r]').boundingBox())!
  expect(Math.abs(a.height - r.height)).toBeLessThanOrEqual(0.5)
  expect(Math.round((await sideGaps(page, DIALOG)).width)).toBe(420)
})
