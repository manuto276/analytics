import { registerEndpoint } from '@nuxt/test-utils/runtime'
import { getQuery, readRawBody, setResponseHeader, setResponseStatus } from 'h3'
import type { H3Event } from 'h3'
import type { Site, User } from '~/types'
import { authConfig, makeSite, makeUser } from './fixtures'

export { getQuery, readRawBody }

/**
 * Request header lookup. The mocked server keeps the original header casing,
 * so read through the case-insensitive Headers object instead of h3's getHeader.
 */
export function header(event: H3Event, name: string): string | undefined {
  return event.headers.get(name) ?? undefined
}

export function problem(event: H3Event, status: number, code: string, extra: Record<string, unknown> = {}) {
  setResponseStatus(event, status)
  setResponseHeader(event, 'content-type', 'application/problem+json')
  return { type: 'about:blank', title: code, status, code, ...extra }
}

export async function readJson<T = Record<string, unknown>>(event: H3Event): Promise<T> {
  const raw = await readRawBody(event, 'utf8')
  return (raw ? JSON.parse(raw) : {}) as T
}

/** Registers a signed-in session with the given user and sites. */
export function mockSession(options: { user?: User, sites?: Site[], csrf?: string } = {}) {
  const user = options.user ?? makeUser()
  const sites = options.sites ?? [makeSite()]
  registerEndpoint('/api/v1/auth/me', {
    method: 'GET',
    handler: () => ({ data: { user, csrf_token: options.csrf ?? 'csrf-123', session: { id: '0123456789abcdef', absolute_expires_at: '2026-10-01T00:00:00Z' } } })
  })
  registerEndpoint('/api/v1/sites', { method: 'GET', handler: () => ({ data: sites }) })
  registerEndpoint('/api/v1/auth/config', { method: 'GET', handler: () => ({ data: authConfig }) })
  return { user, sites }
}

/** Seeds auth and site state directly (without HTTP) for component tests. */
export function seedState(options: { user?: User | null, sites?: Site[], csrf?: string | null } = {}) {
  useState('auth:user').value = options.user === undefined ? makeUser() : options.user
  useState('auth:csrf').value = options.csrf === undefined ? 'csrf-123' : options.csrf
  useState('auth:loaded').value = true
  useState('sites:list').value = options.sites ?? [makeSite()]
  useState('sites:loaded').value = true
}

/** Polls until the predicate holds (or fails after the timeout). */
export async function waitFor(predicate: () => boolean | undefined, timeout = 2000): Promise<void> {
  const started = Date.now()
  while (Date.now() - started < timeout) {
    await nextTick()
    if (predicate()) return
    await new Promise(resolve => setTimeout(resolve, 10))
  }
  throw new Error('waitFor timed out')
}
