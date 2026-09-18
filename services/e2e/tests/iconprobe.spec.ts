import { test } from '@playwright/test'
import { createSite } from '../support/tracking'
import { login, preparePage } from '../support/dashboard'

test('probe admin', async ({ page }) => {
  const site = await createSite(`e2e-probe-${Date.now()}`)
  await preparePage(page, site.id)
  await login(page)
  for (const p of ['/admin/sites', '/admin/users', '/admin/audit', '/realtime', '/funnels', '/attribution']) {
    await page.goto(p)
    await page.waitForLoadState('networkidle')
    const info = await page.evaluate(() => ({
      svg: document.querySelectorAll('svg.iconify').length,
      empty: Array.from(document.querySelectorAll('.iconify:not(svg)')).map(e => String(e.getAttribute('class'))).slice(0, 6)
    }))
    console.log(p, JSON.stringify(info))
  }
})
