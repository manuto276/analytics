import { randomBytes } from 'node:crypto'
import { type APIRequestContext, type Page, expect, request as playwrightRequest } from '@playwright/test'
import { console_ } from './console'
import { BASE_URL } from './api'

/** Base64url id of 12 random bytes: the event id format of payload v1. */
export function eventId(): string {
  return randomBytes(12).toString('base64url')
}

export interface CollectEvent {
  id?: string
  t: 'pv' | 'ev' | 'en' | 'cu' | 'cs'
  u: string
  r?: string
  a?: number
  n?: string
  p?: Record<string, string | number | boolean>
  ms?: number
  sp?: number
  cs?: string
  ck?: string
}

export function pageview(url: string, referrer?: string): CollectEvent {
  return { id: eventId(), t: 'pv', u: url, a: 0, ...(referrer ? { r: referrer } : {}) }
}

export function customEvent(name: string, url: string, props?: Record<string, string | number | boolean>): CollectEvent {
  return { id: eventId(), t: 'ev', u: url, n: name, a: 0, ...(props ? { p: props } : {}) }
}

/**
 * Sends a batch exactly like the tracker does (text/plain, Origin of the
 * tracked site). Returns the raw response so a test can assert the status.
 */
export async function collect(
  publicKey: string,
  events: CollectEvent[],
  {
    origin = 'https://www.site.test',
    level = 'b',
    vid,
    sid,
    consentVersion = 0,
    path = '/t/e',
  }: { origin?: string, level?: 'b' | 'c', vid?: string, sid?: string, consentVersion?: number, path?: string } = {},
): Promise<{ status: number, headers: Record<string, string> }> {
  const context = await playwrightRequest.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true })
  const response = await context.post(path, {
    headers: { 'Content-Type': 'text/plain', Origin: origin },
    data: JSON.stringify({
      v: 1,
      k: publicKey,
      l: level,
      ...(vid ? { vid } : {}),
      ...(sid ? { sid } : {}),
      cv: consentVersion,
      sw: 1440,
      e: events.map(event => ({ id: eventId(), a: 0, ...event })),
    }),
  })
  const result = { status: response.status(), headers: response.headers() }
  await context.dispose()
  return result
}

/** Asks the backend to forget a consented visitor (the tracker's /t/forget). */
export async function forget(publicKey: string, vid: string, origin = 'https://www.site.test'): Promise<number> {
  const context = await playwrightRequest.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true })
  const response = await context.post('/t/forget', {
    headers: { 'Content-Type': 'text/plain', Origin: origin },
    data: JSON.stringify({ k: publicKey, vid }),
  })
  const status = response.status()
  await context.dispose()
  return status
}

/**
 * Playwright sets `navigator.webdriver`; the tracker skips automated browsers
 * (src/collector.ts), so every test that expects tracking hides it first.
 */
export async function hideWebdriver(page: Page): Promise<void> {
  await page.addInitScript(() => {
    Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false, configurable: true })
  })
}

/** Opens a fixture page tracking into `publicKey` and waits for its pageview batch. */
export async function visitFixture(
  page: Page,
  url: string,
  publicKey: string,
  { expectCollect = true, collectPath = '/t/e' }: { expectCollect?: boolean, collectPath?: string } = {},
): Promise<number | null> {
  const separator = url.includes('?') ? '&' : '?'
  const target = `${url}${separator}an_key=${publicKey}`

  if (!expectCollect) {
    await page.goto(target)
    return null
  }
  const collected = page.waitForResponse(
    response => response.url().includes(collectPath) && response.request().method() === 'POST',
    { timeout: 20_000 },
  )
  await page.goto(target)
  const response = await collected
  return response.status()
}

/** Reads a first-party cookie of the tracked site from the browser context. */
export async function trackedCookie(page: Page, name: string): Promise<string | undefined> {
  const cookies = await page.context().cookies(['https://www.site.test', 'https://app.site.test'])
  return cookies.find(cookie => cookie.name === name)?.value
}

/** The consent banner lives in a shadow root on the tracked page. */
export function banner(page: Page) {
  return page.locator('[data-analytics-banner]')
}

export async function expectBannerVisible(page: Page, visible = true): Promise<void> {
  const host = banner(page)
  if (visible) {
    await expect(host).toBeAttached({ timeout: 15_000 })
    await expect(host.locator('button').first()).toBeVisible()
  } else {
    await page.waitForTimeout(1500)
    await expect(host).toHaveCount(0)
  }
}

/** Runs the rollups so the dashboard reports include everything collected so far. */
export async function rollup(siteId: number): Promise<void> {
  await console_('rollup:run', [`--site=${siteId}`])
}

/** Creates a site through the console and returns its id and public key. */
export async function createSite(
  name: string,
  { domain = '*.site.test', cookieDomain, timezone = 'UTC' }: { domain?: string, cookieDomain?: string, timezone?: string } = {},
): Promise<{ id: number, publicKey: string }> {
  const args = [`--name=${name}`, `--domain=${domain}`, `--timezone=${timezone}`]
  if (cookieDomain) args.push(`--cookie-domain=${cookieDomain}`)
  const out = await console_('site:create', args)
  const id = Number(out.match(/\(id (\d+)\)/)?.[1])
  const publicKey = out.match(/pk_[A-Za-z0-9]{21}/)?.[0]
  if (!id || !publicKey) throw new Error(`could not parse site:create output:\n${out}`)
  return { id, publicKey }
}

/** Creates an API key for the server-side conversions API. */
export async function createApiKey(siteId: number, scopes = 'conversions:write'): Promise<string> {
  const out = await console_('api-key:create', [`--site=${siteId}`, `--name=e2e`, `--scopes=${scopes}`])
  const secret = out.match(/ak_[A-Za-z0-9]{8}_[A-Za-z0-9_-]{43}/)?.[0]
  if (!secret) throw new Error(`could not parse api-key:create output:\n${out}`)
  return secret
}

export type { APIRequestContext }
