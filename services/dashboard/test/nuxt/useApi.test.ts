import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, registerEndpoint } from '@nuxt/test-utils/runtime'
import { header, problem, readJson } from '../support/api'

const { navigateToMock } = vi.hoisted(() => ({ navigateToMock: vi.fn() }))
mockNuxtImport('navigateTo', () => navigateToMock)

describe('useApi', () => {
  beforeEach(async () => {
    clearNuxtState()
    navigateToMock.mockReset()
    await useRouter().replace('/')
    navigateToMock.mockReset()
  })

  it('sends the CSRF token on unsafe methods only', async () => {
    const seen: Record<string, string | undefined> = {}
    registerEndpoint('/api/v1/things', {
      method: 'GET',
      handler: (event) => {
        seen.get = header(event, 'x-csrf-token')
        return { data: 'get' }
      }
    })
    registerEndpoint('/api/v1/things', {
      method: 'POST',
      handler: async (event) => {
        seen.post = header(event, 'x-csrf-token')
        seen.accept = header(event, 'accept')
        return { data: await readJson(event) }
      }
    })

    useCsrfToken().value = 'token-abc'
    const api = useApi()

    await expect(api('/things')).resolves.toEqual({ data: 'get' })
    await expect(api('/things', { method: 'POST', body: { a: 1 } })).resolves.toEqual({ data: { a: 1 } })
    expect(seen.get).toBeUndefined()
    expect(seen.post).toBe('token-abc')
    expect(seen.accept).toBe('application/json')
  })

  it('redirects to /login on 401 and clears the session state', async () => {
    registerEndpoint('/api/v1/private', { method: 'GET', handler: event => problem(event, 401, 'unauthenticated') })
    useState('auth:user').value = { id: 1 }
    useCsrfToken().value = 'old'

    const error = await useApi()('/private').catch((e: unknown) => e) as ApiError

    expect(isApiError(error)).toBe(true)
    expect(error.status).toBe(401)
    expect(error.code).toBe('unauthenticated')
    expect(navigateToMock).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/' } })
    expect(useState('auth:user').value).toBeNull()
    expect(useCsrfToken().value).toBeNull()
  })

  it('does not redirect on 401 when asked to or on public pages', async () => {
    registerEndpoint('/api/v1/private', { method: 'GET', handler: event => problem(event, 401, 'unauthenticated') })

    await useApi()('/private', { skipAuthRedirect: true }).catch(() => null)
    expect(navigateToMock).not.toHaveBeenCalled()

    await useRouter().replace('/password/reset/abc')
    navigateToMock.mockReset()
    await useApi()('/private').catch(() => null)
    expect(navigateToMock).not.toHaveBeenCalled()
  })

  it('shows a toast on 403', async () => {
    registerEndpoint('/api/v1/forbidden', { method: 'DELETE', handler: event => problem(event, 403, 'forbidden', { detail: 'Site admins only' }) })
    const toast = useToast()
    toast.clear()

    const error = await useApi()('/forbidden', { method: 'DELETE' }).catch((e: unknown) => e) as ApiError

    expect(error.status).toBe(403)
    expect(toast.toasts.value.at(-1)).toMatchObject({ title: 'You do not have permission to do this', description: 'Site admins only', color: 'error' })

    await useApi()('/forbidden', { method: 'DELETE', skipForbiddenToast: true }).catch(() => null)
    expect(toast.toasts.value).toHaveLength(1)
  })

  it('exposes 422 field errors and the problem code', async () => {
    registerEndpoint('/api/v1/sites', {
      method: 'POST',
      handler: event => problem(event, 422, 'validation_failed', { detail: 'Invalid input', errors: { 'name': ['Required'], 'domains.0.host': ['Invalid host'] } })
    })

    const error = await useApi()('/sites', { method: 'POST', body: {} }).catch((e: unknown) => e) as ApiError

    expect(error).toBeInstanceOf(ApiError)
    expect(error.isValidation).toBe(true)
    expect(error.code).toBe('validation_failed')
    expect(error.errors).toEqual({ 'name': ['Required'], 'domains.0.host': ['Invalid host'] })
    expect(error.fieldErrors()).toEqual([{ name: 'name', message: 'Required' }, { name: 'domains.0.host', message: 'Invalid host' }])
  })

  it('rethrows non-HTTP errors unchanged', async () => {
    const api = useApi()
    const boom = new Error('boom')
    const throwing = () => {
      throw boom
    }
    await expect(api('/things', { onRequest: throwing })).rejects.toBe(boom)
  })

  it('recognises public routes', () => {
    expect(isPublicRoute('/login')).toBe(true)
    expect(isPublicRoute('/login/mfa')).toBe(true)
    expect(isPublicRoute('/invite/abc')).toBe(true)
    expect(isPublicRoute('/password/forgot')).toBe(true)
    expect(isPublicRoute('/password/reset/xyz')).toBe(true)
    expect(isPublicRoute('/account/email/confirm')).toBe(true)
    expect(isPublicRoute('/settings')).toBe(false)
  })
})
