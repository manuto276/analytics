import { beforeEach, describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import AdminSites from '~/pages/admin/sites.vue'
import HealthCard from '~/components/admin/HealthCard.vue'
import MembersSettings from '~/pages/settings/members.vue'
import { getQuery, readJson, seedState, waitFor } from '../support/api'
import { makeSite, makeUser } from '../support/fixtures'
import { mountInApp } from '../support/mount'

const sites = [makeSite({ id: 1, name: 'Alpha' }), makeSite({ id: 2, name: 'Old site', archived: true })]
const listQueries: (string | undefined)[] = []
const patches: { id: number, body: Record<string, unknown> }[] = []

registerEndpoint('/api/v1/sites', {
  method: 'GET',
  handler: (event) => {
    const archived = (getQuery(event) as Record<string, string>).archived
    listQueries.push(archived)
    return { data: archived === '1' ? sites : sites.filter(s => !s.archived) }
  }
})
registerEndpoint('/api/v1/sites/2', {
  method: 'PATCH',
  handler: async (event) => {
    const body = await readJson(event)
    patches.push({ id: 2, body })
    return { data: makeSite({ id: 2, name: 'Old site', archived: false }) }
  }
})
registerEndpoint('/api/v1/admin/health', {
  method: 'GET',
  handler: () => ({
    data: {
      status: 'warn',
      version: '1.0.0',
      commit: '0123456789abcdef',
      checks: {
        database: { status: 'ok', detail: 'MySQL 8.4 reachable' },
        geo_db: { status: 'warn', detail: 'Database is 52 days old' }
      }
    }
  })
})
registerEndpoint('/api/v1/sites/1/members', { method: 'GET', handler: () => ({ data: [] }) })
registerEndpoint('/api/v1/sites/1/invitations', {
  method: 'GET',
  handler: () => ({
    data: [
      { id: 11, email: 'pending@example.com', global_role: 'member', site_roles: [{ site_id: 1, role: 'viewer' }], status: 'pending', expires_at: '2026-10-01T00:00:00Z', created_at: '2026-09-17T00:00:00Z' },
      { id: 12, email: 'gone@example.com', global_role: 'member', site_roles: [], status: 'revoked', expires_at: '2026-10-01T00:00:00Z', created_at: '2026-09-10T00:00:00Z' }
    ]
  })
})
const revoked: number[] = []
registerEndpoint('/api/v1/sites/1/invitations/11', {
  method: 'DELETE',
  handler: () => {
    revoked.push(11)
    return { data: { id: 11, email: 'pending@example.com', global_role: 'member', site_roles: [], status: 'revoked', expires_at: '2026-10-01T00:00:00Z', created_at: '2026-09-17T00:00:00Z' } }
  }
})

async function tick() {
  await new Promise(resolve => setTimeout(resolve, 30))
  await nextTick()
}

describe('admin sites', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
    listQueries.length = 0
    patches.length = 0
    seedState({ user: makeUser({ global_role: 'admin' }), sites })
  })

  it('asks for archived sites in one request and restores them', async () => {
    const wrapper = await mountInApp(AdminSites)
    await tick()

    expect(listQueries).toEqual(['1'])
    expect(wrapper.text()).toContain('Old site')
    expect(wrapper.text()).toContain('Archived')

    await wrapper.get('[data-testid="restore-site"]').trigger('click')
    await waitFor(() => patches.length > 0)

    expect(patches[0]).toEqual({ id: 2, body: { archived: false } })
  })
})

describe('admin health card', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
    seedState({ user: makeUser({ global_role: 'admin' }) })
  })

  it('shows the overall status and every check', async () => {
    const wrapper = await mountInApp(HealthCard)
    await tick()

    const card = wrapper.get('[data-testid="health-card"]')
    expect(card.text()).toContain('Degraded')
    expect(card.text()).toContain('database')
    expect(card.text()).toContain('MySQL 8.4 reachable')
    expect(card.text()).toContain('Database is 52 days old')
    expect(card.text()).toContain('Version 1.0.0 (0123456)')
  })
})

describe('site invitations', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
    revoked.length = 0
    seedState({ user: makeUser({ global_role: 'member' }), sites: [makeSite({ id: 1, role: 'admin' })] })
  })

  it('lists pending invitations of the site and revokes them', async () => {
    const wrapper = await mountInApp(MembersSettings)
    await tick()

    const list = wrapper.get('[data-testid="site-invitations"]')
    expect(list.text()).toContain('pending@example.com')
    expect(list.text()).not.toContain('gone@example.com')

    await wrapper.get('[data-testid="revoke-invitation"]').trigger('click')
    await waitFor(() => revoked.length > 0)

    expect(revoked).toEqual([11])
  })
})
