import { beforeEach, describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import { readJson, seedState } from '../support/api'
import { makeSite, makeUser } from '../support/fixtures'
import { SITE_STORAGE_KEY } from '~/composables/useSites'

const sites = [makeSite({ id: 1, name: 'Alpha' }), makeSite({ id: 2, name: 'Beta', role: 'viewer' }), makeSite({ id: 3, name: 'Old', archived: true })]

registerEndpoint('/api/v1/sites', { method: 'GET', handler: () => ({ data: sites }) })
registerEndpoint('/api/v1/sites', { method: 'POST', handler: async event => ({ data: makeSite({ id: 9, ...(await readJson(event)) }) }) })

describe('useSites', () => {
  beforeEach(async () => {
    clearNuxtState()
    localStorage.clear()
    // A signed-in session keeps the global auth middleware from redirecting.
    seedState({ sites: [] })
    useState('sites:loaded').value = false
    await useRouter().replace('/')
  })

  it('loads sites once and picks the first non-archived site', async () => {
    const { ensureSites, sites: list, currentSite, loaded } = useSites()
    await ensureSites()
    await ensureSites()

    expect(loaded.value).toBe(true)
    expect(list.value).toHaveLength(3)
    expect(currentSite.value?.id).toBe(1)
  })

  it('prefers ?site= over the stored choice', async () => {
    const { ensureSites, currentSiteId } = useSites()
    await ensureSites()
    writeStorage(SITE_STORAGE_KEY, '2')
    expect(currentSiteId.value).toBe(2)

    await useRouter().replace('/?site=1')
    expect(currentSiteId.value).toBe(1)
  })

  it('ignores an unknown or archived stored site', async () => {
    const { ensureSites, currentSiteId } = useSites()
    await ensureSites()
    writeStorage(SITE_STORAGE_KEY, '3')
    expect(currentSiteId.value).toBe(1)
    writeStorage(SITE_STORAGE_KEY, '404')
    expect(currentSiteId.value).toBe(1)
  })

  it('persists the selection and puts it in the URL', async () => {
    const { ensureSites, selectSite, currentSiteId } = useSites()
    await ensureSites()
    await selectSite(2)

    expect(readStorage(SITE_STORAGE_KEY)).toBe('2')
    expect(useRoute().query.site).toBe('2')
    expect(currentSiteId.value).toBe(2)
  })

  it('exposes management rights per role', async () => {
    const { ensureSites, canManage } = useSites()
    await ensureSites()
    useState('auth:user').value = makeUser({ global_role: 'member' })

    await useRouter().replace('/?site=1')
    expect(canManage.value).toBe(true)
    await useRouter().replace('/?site=2')
    expect(canManage.value).toBe(false)

    useState('auth:user').value = makeUser({ global_role: 'admin' })
    expect(canManage.value).toBe(true)
  })

  it('adds a created site to the list', async () => {
    const { ensureSites, createSite, sites: list } = useSites()
    await ensureSites()
    const site = await createSite({ name: 'Gamma', timezone: 'UTC', domains: [{ host: 'gamma.example', include_subdomains: false }] })

    expect(site.id).toBe(9)
    expect(list.value.map(s => s.name)).toContain('Gamma')
  })
})
