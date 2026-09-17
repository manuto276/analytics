import { beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import SitesMenu from '~/components/SitesMenu.vue'
import { seedState } from '../support/api'
import { makeSite, makeUser } from '../support/fixtures'

const sites = [makeSite({ id: 1, name: 'Alpha' }), makeSite({ id: 2, name: 'Beta' }), makeSite({ id: 3, name: 'Archived', archived: true })]

function itemLabels(wrapper: { findComponent: (s: { name: string }) => { props: (p: string) => unknown } }) {
  const groups = wrapper.findComponent({ name: 'UDropdownMenu' }).props('items') as { label: string }[][]
  return groups.map(group => group.map(item => item.label))
}

describe('SitesMenu', () => {
  beforeEach(() => {
    clearNuxtState()
    localStorage.clear()
  })

  it('shows the current site and only active sites', async () => {
    seedState({ sites })
    const wrapper = await mountSuspended(SitesMenu)

    expect(wrapper.text()).toContain('Alpha')
    const [siteGroup] = itemLabels(wrapper)
    expect(siteGroup).toEqual(['Alpha', 'Beta'])
  })

  it('offers site management to global admins only', async () => {
    seedState({ sites, user: makeUser({ global_role: 'admin' }) })
    const adminWrapper = await mountSuspended(SitesMenu)
    expect(itemLabels(adminWrapper).at(-1)).toEqual(['Add site', 'Manage sites'])

    seedState({ sites, user: makeUser({ global_role: 'member' }) })
    const memberWrapper = await mountSuspended(SitesMenu)
    expect(itemLabels(memberWrapper)).toHaveLength(1)
  })

  it('selects a site and remembers the choice', async () => {
    seedState({ sites })
    const wrapper = await mountSuspended(SitesMenu, { route: '/' })
    const [siteGroup] = wrapper.findComponent({ name: 'UDropdownMenu' }).props('items') as { label: string, onSelect: () => void }[][]

    siteGroup![1]!.onSelect()
    await nextTick()
    await new Promise(resolve => setTimeout(resolve, 10))

    expect(readStorage('analytics:site')).toBe('2')
    expect(useRouter().currentRoute.value.query.site).toBe('2')
  })

  it('falls back to a placeholder when no site exists', async () => {
    seedState({ sites: [] })
    const wrapper = await mountSuspended(SitesMenu)
    expect(wrapper.text()).toContain('No site')
  })
})
