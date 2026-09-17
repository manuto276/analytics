import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, registerEndpoint } from '@nuxt/test-utils/runtime'
import { problem, readJson } from '../support/api'
import { authConfig, makeUser } from '../support/fixtures'

const { navigateToMock } = vi.hoisted(() => ({ navigateToMock: vi.fn() }))
mockNuxtImport('navigateTo', () => navigateToMock)

describe('useAuth', () => {
  beforeEach(async () => {
    clearNuxtState()
    await useRouter().replace('/login')
    navigateToMock.mockReset()
  })

  it('signs in, stores the CSRF token and redirects to the requested page', async () => {
    registerEndpoint('/api/v1/auth/login', {
      method: 'POST',
      handler: async event => ({ data: { status: 'ok', user: makeUser(), csrf_token: 'csrf-1', ...(await readJson(event)).email ? {} : {} } })
    })
    await useRouter().replace('/login?redirect=/pages')

    const auth = useAuth()
    const status = await auth.login({ email: 'admin@example.com', password: 'secret' })

    expect(status).toBe('ok')
    expect(auth.user.value?.email).toBe('admin@example.com')
    expect(auth.isGlobalAdmin.value).toBe(true)
    expect(useCsrfToken().value).toBe('csrf-1')
    expect(navigateToMock).toHaveBeenCalledWith('/pages')
  })

  it('sends the user to the second factor page when MFA is required', async () => {
    registerEndpoint('/api/v1/auth/login', { method: 'POST', handler: () => ({ data: { status: 'mfa_required' } }) })

    const status = await useAuth().login({ email: 'a@example.com', password: 'x' })

    expect(status).toBe('mfa_required')
    expect(useAuth().user.value).toBeNull()
    expect(navigateToMock).toHaveBeenCalledWith(expect.objectContaining({ path: '/login/mfa' }))
  })

  it('completes MFA and lands on the dashboard', async () => {
    registerEndpoint('/api/v1/auth/mfa', { method: 'POST', handler: () => ({ data: { status: 'ok', user: makeUser({ global_role: 'member' }), csrf_token: 'csrf-2' } }) })

    await useAuth().verifyMfa('123456')

    expect(useAuth().isGlobalAdmin.value).toBe(false)
    expect(navigateToMock).toHaveBeenCalledWith('/')
  })

  it('ignores an off-site redirect target', async () => {
    registerEndpoint('/api/v1/auth/login', { method: 'POST', handler: () => ({ data: { status: 'ok', user: makeUser(), csrf_token: 'c' } }) })
    await useRouter().replace('/login?redirect=//evil.example.com')

    await useAuth().login({ email: 'a@example.com', password: 'x' })

    expect(navigateToMock).toHaveBeenCalledWith('/')
  })

  it('treats 401 on /auth/me as signed out', async () => {
    registerEndpoint('/api/v1/auth/me', { method: 'GET', handler: event => problem(event, 401, 'unauthenticated') })

    const auth = useAuth()
    expect(await auth.ensureLoaded()).toBeNull()
    expect(auth.loaded.value).toBe(true)
    expect(navigateToMock).not.toHaveBeenCalled()
  })

  it('loads the public config once and clears the session on logout', async () => {
    let configCalls = 0
    registerEndpoint('/api/v1/auth/config', { method: 'GET', handler: () => {
      configCalls++
      return { data: authConfig }
    } })
    registerEndpoint('/api/v1/auth/logout', { method: 'POST', handler: () => null })

    const auth = useAuth()
    await auth.loadConfig()
    await auth.loadConfig()
    expect(configCalls).toBe(1)
    expect(auth.config.value?.source_url).toBe(authConfig.source_url)

    auth.user.value = makeUser()
    await auth.logout()
    expect(auth.user.value).toBeNull()
    expect(useCsrfToken().value).toBeNull()
    expect(navigateToMock).toHaveBeenCalledWith('/login')
  })

  it('persists the locale of the signed-in user', async () => {
    const patched: string[] = []
    registerEndpoint('/api/v1/auth/me', {
      method: 'PATCH',
      handler: async (event) => {
        const body = await readJson<{ locale: string }>(event)
        patched.push(body.locale)
        return { data: { user: makeUser({ locale: body.locale }), csrf_token: 'csrf-3', session: { id: 'abc', absolute_expires_at: '2026-10-01T00:00:00Z' } } }
      }
    })

    const auth = useAuth()
    auth.user.value = makeUser({ locale: 'en' })
    await auth.setLocale('it')

    expect(patched).toEqual(['it'])
    expect(useNuxtApp().$i18n.locale.value).toBe('it')
    await auth.setLocale('en')
  })
})
