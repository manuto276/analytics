import { expect, test } from '@playwright/test'
import { hideWebdriver } from '../support/tracking'

/**
 * Smoke: the stack is wired correctly.
 *
 *  1. the service answers /api/v1/health with JSON
 *  2. a real browser visit to the fixture site produces an accepted /t/e batch
 *     and no cookie is ever set on the service domain
 *
 * The richer scenarios of plan §13.2 live in the other spec files next to this
 * one (auth, members, sites, tracking, consent, conversions, a11y, visual).
 */

test('health endpoint answers with a status', async ({ request }) => {
  const response = await request.get('/api/v1/health')

  expect(response.status()).toBe(200)
  expect(response.headers()['content-type']).toContain('json')

  const body = await response.json()
  expect(body).toHaveProperty('status')
  // `warn` is a healthy fresh install (no geo database downloaded yet).
  expect(['ok', 'warn', 'fail']).toContain(String(body.status))
  expect(body).toHaveProperty('version')
})

test('a visit to www.site.test is collected', async ({ page }) => {
  await hideWebdriver(page)

  const collected = page.waitForResponse(
    response => response.url().includes('/t/e') && response.request().method() === 'POST',
    { timeout: 20_000 },
  )

  await page.goto('https://www.site.test/')
  await expect(page.locator('h1')).toHaveText('Example shop')

  const response = await collected
  expect(response.status()).toBe(202)
})

test('the collect endpoint answers a batch without ever setting a cookie', async ({ request }) => {
  const publicKey = process.env.ANALYTICS_PUBLIC_KEY
  expect(publicKey, 'global setup must resolve the site public key').toBeTruthy()

  // A beacon response exposes few headers to the page, so the invariants of
  // plan §3.1 (no Set-Cookie on /t/*, no-store) are asserted on a direct call.
  const response = await request.post('/t/e', {
    headers: { 'Content-Type': 'text/plain', Origin: 'https://www.site.test' },
    data: JSON.stringify({
      v: 1,
      k: publicKey,
      l: 'b',
      cv: 0,
      sw: 1440,
      e: [{ id: 'aaaaaaaaaaaaaaaa', t: 'pv', u: 'https://www.site.test/pricing.html', a: 0 }],
    }),
  })

  expect(response.status()).toBe(202)
  const headers = response.headers()
  expect(headers['set-cookie']).toBeUndefined()
  expect(headers['cache-control']).toContain('no-store')
  expect(headers['access-control-allow-origin']).toBe('https://www.site.test')
})

test('the tracker bundle is served for the fixture site', async ({ page, request }) => {
  await hideWebdriver(page)
  await page.goto('https://www.site.test/')

  const publicKey = process.env.ANALYTICS_PUBLIC_KEY
  expect(publicKey, 'global setup must resolve the site public key').toBeTruthy()

  const response = await request.get(`/t/${publicKey}.js`, { headers: { Origin: 'https://www.site.test' } })
  expect(response.status()).toBe(200)
  expect(response.headers()['content-type']).toContain('javascript')
  expect(response.headers()['set-cookie']).toBeUndefined()

  const body = await response.text()
  expect(body).toContain('window.__an_cfg')
})
