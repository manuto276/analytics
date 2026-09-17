import type { ApiBody, ApiResponse, AuthConfig, User } from '~/types'

type LoginResponse = ApiResponse<'/auth/login', 'post'>
type MeResponse = ApiResponse<'/auth/me'>

export function useAuth() {
  const api = useApi()
  const user = useState<User | null>('auth:user', () => null)
  const csrf = useCsrfToken()
  const loaded = useState<boolean>('auth:loaded', () => false)
  const config = useState<AuthConfig | null>('auth:config', () => null)
  const { $i18n } = useNuxtApp()

  const isAuthenticated = computed(() => !!user.value)
  const isGlobalAdmin = computed(() => user.value?.global_role === 'admin')

  function applyLocale(locale: string | undefined) {
    if (locale && ($i18n.availableLocales as string[]).includes(locale) && $i18n.locale.value !== locale) {
      void $i18n.setLocale(locale as 'en' | 'it')
    }
  }

  async function fetchMe(): Promise<User | null> {
    try {
      const res = await api<MeResponse>('/auth/me', { skipAuthRedirect: true })
      user.value = res.data.user
      csrf.value = res.data.csrf_token
      applyLocale(res.data.user.locale)
    } catch (error) {
      if (!isApiError(error) || error.status !== 401) {
        loaded.value = true
        throw error
      }
      user.value = null
    }
    loaded.value = true
    return user.value
  }

  async function ensureLoaded(): Promise<User | null> {
    if (loaded.value) return user.value
    try {
      return await fetchMe()
    } catch {
      return null
    }
  }

  async function loadConfig(): Promise<AuthConfig | null> {
    if (config.value) return config.value
    try {
      const res = await api<ApiResponse<'/auth/config'>>('/auth/config', { skipAuthRedirect: true })
      config.value = res.data
    } catch {
      config.value = null
    }
    return config.value
  }

  function redirectTarget(): string {
    const redirect = useRouter().currentRoute.value.query.redirect
    return typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/'
  }

  async function handleLoginResponse(res: LoginResponse) {
    if (res.data.status === 'mfa_required') {
      if (res.data.csrf_token) csrf.value = res.data.csrf_token
      await navigateTo({ path: '/login/mfa', query: useRouter().currentRoute.value.query })
      return res.data.status
    }
    if (res.data.csrf_token) csrf.value = res.data.csrf_token
    if (res.data.user) {
      user.value = res.data.user
      applyLocale(res.data.user.locale)
    } else {
      await fetchMe()
    }
    loaded.value = true
    await navigateTo(redirectTarget())
    return res.data.status
  }

  async function login(body: ApiBody<'/auth/login', 'post'>) {
    const res = await api<LoginResponse>('/auth/login', { method: 'POST', body, skipAuthRedirect: true })
    return handleLoginResponse(res)
  }

  async function verifyMfa(code: string) {
    const res = await api<LoginResponse>('/auth/mfa', { method: 'POST', body: { code }, skipAuthRedirect: true })
    return handleLoginResponse(res)
  }

  async function acceptInvitation(token: string, body: ApiBody<'/invitations/{token}/accept', 'post'>) {
    const res = await api<LoginResponse>(`/invitations/${encodeURIComponent(token)}/accept`, { method: 'POST', body, skipAuthRedirect: true })
    return handleLoginResponse(res)
  }

  async function logout() {
    try {
      await api('/auth/logout', { method: 'POST', skipAuthRedirect: true })
    } finally {
      user.value = null
      csrf.value = null
      await navigateTo('/login')
    }
  }

  async function updateMe(body: ApiBody<'/auth/me', 'patch'>) {
    const res = await api<MeResponse>('/auth/me', { method: 'PATCH', body })
    user.value = res.data.user
    csrf.value = res.data.csrf_token
    return res.data.user
  }

  async function setLocale(locale: 'en' | 'it') {
    await $i18n.setLocale(locale)
    if (user.value && user.value.locale !== locale) {
      try {
        await updateMe({ locale })
      } catch {
        // The UI locale still changes; persisting it is best effort.
      }
    }
  }

  return {
    user,
    csrf,
    config,
    loaded,
    isAuthenticated,
    isGlobalAdmin,
    fetchMe,
    ensureLoaded,
    loadConfig,
    login,
    verifyMfa,
    acceptInvitation,
    logout,
    updateMe,
    setLocale
  }
}
