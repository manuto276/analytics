import { expect, test } from '@playwright/test'
import { createSite } from '../support/tracking'
import { login, preparePage } from '../support/dashboard'

/**
 * The interface uses one icon family, Tabler, and it must be served by the installation itself:
 * an icon resolved through the Iconify API would be a request to a third party from the page an
 * operator logs into, which is exactly what this product exists to avoid.
 */
test('icons are Tabler and come from the installation, never from a remote service', async ({ page }) => {
  const remote: string[] = []
  page.on('request', (request) => {
    const host = new URL(request.url()).hostname
    if (!['analytics.test', 'localhost', '127.0.0.1'].includes(host)) remote.push(request.url())
  })

  const site = await createSite(`e2e-icons-${Date.now()}`)
  await preparePage(page, site.id)
  await login(page)

  for (const path of ['/', '/pages', '/sources', '/settings']) {
    await page.goto(path)
    await page.waitForLoadState('networkidle')
    // The icons are inlined as SVG by the client bundle: an empty <span class="iconify"> would mean
    // the icon was never delivered, which is how they silently disappeared before.
    expect(await page.locator('svg.iconify--tabler').count(), `Tabler icons on ${path}`).toBeGreaterThan(0)
    expect(await page.locator('.iconify:not(svg)').count(), `icons that rendered empty on ${path}`).toBe(0)
    expect(await page.locator('svg.iconify--lucide').count(), `Lucide icons left on ${path}`).toBe(0)
  }

  expect(remote, 'requests that left the installation').toEqual([])
})
