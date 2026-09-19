import type { Problem } from '~/types'

type FetchOptions = NonNullable<Parameters<typeof $fetch>[1]>

interface FetchErrorLike {
  name: string
  message: string
  statusCode?: number
  response?: { status: number }
  data?: unknown
}

function isFetchError(error: unknown): error is FetchErrorLike {
  return !!error && typeof error === 'object' && (error as FetchErrorLike).name === 'FetchError'
}

export const API_BASE = '/api/v1'
export const PUBLIC_ROUTES = ['/login', '/login/mfa', '/password/forgot', '/account/email/confirm']
export const PUBLIC_ROUTE_PREFIXES = ['/invite/', '/password/reset/']

export function isPublicRoute(path: string): boolean {
  return PUBLIC_ROUTES.includes(path) || PUBLIC_ROUTE_PREFIXES.some(prefix => path.startsWith(prefix))
}

export interface ApiRequestOptions extends Omit<FetchOptions, 'responseType'> {
  /** Do not redirect to /login on 401 (used while probing the session). */
  skipAuthRedirect?: boolean
  /** Do not show a toast on 403. */
  skipForbiddenToast?: boolean
  responseType?: 'json' | 'text' | 'blob'
}

export type ApiClient = <T = unknown>(url: string, options?: ApiRequestOptions) => Promise<T>

const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS']

export function useCsrfToken() {
  return useState<string | null>('auth:csrf', () => null)
}

/**
 * Same-origin API client: base URL /api/v1, session cookie, CSRF header on
 * unsafe methods, problem+json errors turned into ApiError.
 */
export function useApi(): ApiClient {
  const nuxtApp = useNuxtApp()
  const csrf = useCsrfToken()
  const toast = useToast()

  const raw = $fetch.create({
    baseURL: API_BASE,
    credentials: 'same-origin',
    onRequest({ options }) {
      const method = (options.method ?? 'GET').toUpperCase()
      // ofetch normalises headers to a Headers instance before this hook; mutate it in place.
      const existing = options.headers as unknown
      const headers = existing && typeof (existing as Headers).set === 'function'
        ? existing as Headers
        : new Headers(existing as HeadersInit | undefined)
      if (!headers.has('Accept')) headers.set('Accept', 'application/json')
      if (!SAFE_METHODS.includes(method) && csrf.value) {
        headers.set('X-CSRF-Token', csrf.value)
      }
      options.headers = headers
    }
  })

  return async function api<T>(url: string, options: ApiRequestOptions = {}): Promise<T> {
    const { skipAuthRedirect, skipForbiddenToast, ...fetchOptions } = options
    try {
      return await raw<T>(url, fetchOptions as FetchOptions) as T
    } catch (error) {
      if (!isFetchError(error)) throw error
      const status = error.response?.status ?? error.statusCode ?? 0
      const data = error.data as Partial<Problem> | string | undefined
      const problem = data && typeof data === 'object' ? data : null
      const apiError = new ApiError(status, problem, error.message)

      if (status === 401 && !skipAuthRedirect) {
        const route = nuxtApp.$router.currentRoute.value
        useState('auth:user').value = null
        csrf.value = null
        if (!isPublicRoute(route.path)) {
          await nuxtApp.runWithContext(() => navigateTo({ path: '/login', query: { redirect: route.fullPath } }))
        }
      } else if (status === 403 && !skipForbiddenToast) {
        const t = nuxtApp.$i18n.t
        toast.add({
          title: t('errors.forbidden'),
          description: apiError.message,
          color: 'error',
          icon: 'i-tabler-shield-exclamation'
        })
      }
      throw apiError
    }
  }
}
