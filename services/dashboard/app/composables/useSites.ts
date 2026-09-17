import type { ApiResponse, Site, SiteInput } from '~/types'

export const SITE_STORAGE_KEY = 'analytics:site'

export function useSites() {
  const api = useApi()
  const router = useRouter()
  // currentRoute keeps the query reactive when the composable runs outside a component.
  const route = router.currentRoute
  const { isGlobalAdmin } = useAuth()
  const sites = useState<Site[]>('sites:list', () => [])
  const loaded = useState<boolean>('sites:loaded', () => false)
  const loading = useState<boolean>('sites:loading', () => false)

  async function refreshSites(): Promise<Site[]> {
    loading.value = true
    try {
      const res = await api<ApiResponse<'/sites'>>('/sites')
      sites.value = res.data
      loaded.value = true
    } finally {
      loading.value = false
    }
    return sites.value
  }

  async function ensureSites(): Promise<Site[]> {
    if (loaded.value) return sites.value
    try {
      return await refreshSites()
    } catch {
      return sites.value
    }
  }

  const currentSiteId = computed<number | null>(() => {
    const all = sites.value ?? []
    const available = all.filter(s => !s.archived)
    const fromQuery = Number(route.value.query.site)
    if (Number.isInteger(fromQuery) && all.some(s => s.id === fromQuery)) return fromQuery
    const stored = Number(readStorage(SITE_STORAGE_KEY))
    if (Number.isInteger(stored) && available.some(s => s.id === stored)) return stored
    return available[0]?.id ?? null
  })

  const currentSite = computed<Site | null>(() => (sites.value ?? []).find(s => s.id === currentSiteId.value) ?? null)

  /** Site admins and global admins may change site configuration. */
  const canManage = computed(() => isGlobalAdmin.value || currentSite.value?.role === 'admin')

  async function selectSite(id: number) {
    writeStorage(SITE_STORAGE_KEY, String(id))
    await router.push({ query: { ...route.value.query, site: String(id) } })
  }

  async function createSite(input: SiteInput): Promise<Site> {
    const res = await api<ApiResponse<'/sites', 'post'>>('/sites', { method: 'POST', body: input })
    sites.value = [...sites.value, res.data]
    return res.data
  }

  async function updateSite(id: number, input: SiteInput): Promise<Site> {
    const res = await api<ApiResponse<'/sites/{siteId}', 'patch'>>(`/sites/${id}`, { method: 'PATCH', body: input })
    sites.value = sites.value.map(s => (s.id === id ? res.data : s))
    return res.data
  }

  return {
    sites,
    loaded,
    loading,
    currentSiteId,
    currentSite,
    canManage,
    refreshSites,
    ensureSites,
    selectSite,
    createSite,
    updateSite
  }
}
