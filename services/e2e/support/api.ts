import { type APIRequestContext, request as playwrightRequest } from '@playwright/test'
import { ADMIN } from './dashboard'

/**
 * Thin client for the JSON API used to set scenarios up (and to assert on the
 * same data the dashboard shows).
 *
 * Two things the backend requires and a plain APIRequestContext does not do by
 * itself: an `Origin` header matching APP_URL (SameOriginMiddleware) and the
 * `X-CSRF-Token` header returned by the login response.
 */
export const BASE_URL = process.env.E2E_BASE_URL ?? 'https://analytics.test'

export class Api {
  private constructor(
    readonly context: APIRequestContext,
    private csrf: string,
  ) {}

  /** Signs in and keeps the session cookie of this context. */
  static async login(email: string = ADMIN.email, password: string = ADMIN.password): Promise<Api> {
    const context = await playwrightRequest.newContext({
      baseURL: BASE_URL,
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: { Origin: BASE_URL },
    })
    const response = await context.post('/api/v1/auth/login', { data: { email, password } })
    if (!response.ok()) {
      throw new Error(`login failed for ${email}: ${response.status()} ${await response.text()}`)
    }
    const body = await response.json()
    if (body.data?.status !== 'ok') {
      throw new Error(`login for ${email} did not complete: ${JSON.stringify(body)}`)
    }
    return new Api(context, body.data.csrf_token)
  }

  async dispose(): Promise<void> {
    await this.context.dispose()
  }

  private headers(): Record<string, string> {
    return { 'X-CSRF-Token': this.csrf }
  }

  async get<T = any>(path: string, params?: Record<string, string | number>): Promise<T> {
    const response = await this.context.get(`/api/v1${path}`, { params })
    if (!response.ok()) throw new Error(`GET ${path} → ${response.status()}: ${await response.text()}`)
    return await response.json() as T
  }

  async post<T = any>(path: string, data: unknown): Promise<T> {
    const response = await this.context.post(`/api/v1${path}`, { data, headers: this.headers() })
    if (!response.ok()) throw new Error(`POST ${path} → ${response.status()}: ${await response.text()}`)
    return await response.json() as T
  }

  async patch<T = any>(path: string, data: unknown): Promise<T> {
    const response = await this.context.patch(`/api/v1${path}`, { data, headers: this.headers() })
    if (!response.ok()) throw new Error(`PATCH ${path} → ${response.status()}: ${await response.text()}`)
    return await response.json() as T
  }

  async put<T = any>(path: string, data: unknown): Promise<T> {
    const response = await this.context.put(`/api/v1${path}`, { data, headers: this.headers() })
    if (!response.ok()) throw new Error(`PUT ${path} → ${response.status()}: ${await response.text()}`)
    return await response.json() as T
  }

  /** Raw call, for the cases where the status itself is the assertion. */
  raw(): APIRequestContext {
    return this.context
  }

  csrfToken(): string {
    return this.csrf
  }

  // ---------------------------------------------------------------- helpers

  async site(siteId: number): Promise<any> {
    return (await this.get(`/sites/${siteId}`)).data
  }

  async updateSite(siteId: number, patch: Record<string, unknown>): Promise<any> {
    return (await this.patch(`/sites/${siteId}`, patch)).data
  }

  /** Enables the cookie level with a first-party cookie domain and publishes a banner. */
  async enableCookieLevel(siteId: number, cookieDomain = 'site.test'): Promise<void> {
    await this.updateSite(siteId, { cookie_level_enabled: true, cookie_domain: cookieDomain })
    await this.publishConsent(siteId, { material: false })
  }

  async publishConsent(
    siteId: number,
    { material = false, title = 'We use cookies' }: { material?: boolean, title?: string } = {},
  ): Promise<any> {
    await this.put(`/sites/${siteId}/consent/draft`, {
      default_locale: 'en',
      texts: {
        en: {
          title,
          body: 'We measure how the site is used. Analytics cookies are optional.',
          accept: 'Accept',
          reject: 'Reject',
          close: 'Close',
          policy: 'Privacy policy',
          reopen: 'Cookie preferences',
        },
        it: {
          title: 'Usiamo i cookie',
          body: 'Misuriamo come viene usato il sito. I cookie analitici sono facoltativi.',
          accept: 'Accetta',
          reject: 'Rifiuta',
          close: 'Chiudi',
          policy: 'Informativa privacy',
          reopen: 'Preferenze cookie',
        },
      },
      policy_urls: { en: 'https://www.site.test/privacy', it: 'https://www.site.test/privacy' },
      theme: { bg: '#ffffff', fg: '#111827', ac: '#1d4ed8', acf: '#ffffff', rad: 8, pos: 'bottom' },
      accepted_ttl_days: 180,
      rejected_ttl_days: 180,
      show_floating_reopen: true,
    })
    return (await this.post(`/sites/${siteId}/consent/publish`, { material_change: material })).data
  }

  async createGoal(siteId: number, goal: Record<string, unknown>): Promise<any> {
    return (await this.post(`/sites/${siteId}/goals`, goal)).data
  }

  async createFunnel(siteId: number, funnel: Record<string, unknown>): Promise<any> {
    return (await this.post(`/sites/${siteId}/funnels`, funnel)).data
  }

  async report<T = any>(siteId: number, name: string, params: Record<string, string | number> = {}): Promise<T> {
    return await this.get(`/sites/${siteId}/reports/${name}`, params)
  }
}

/** Posts a server-side conversion with an API key (no session involved). */
export async function postConversions(
  apiKey: string,
  publicKey: string,
  conversions: unknown,
): Promise<{ status: number, body: any }> {
  const context = await playwrightRequest.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true })
  const response = await context.post(`/api/v1/server/sites/${publicKey}/conversions`, {
    headers: { Authorization: `Bearer ${apiKey}`, 'Content-Type': 'application/json' },
    data: conversions,
  })
  const body = await response.json().catch(() => null)
  await context.dispose()
  return { status: response.status(), body }
}
