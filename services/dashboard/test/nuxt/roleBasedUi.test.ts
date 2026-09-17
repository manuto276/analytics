import { beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import GoalsSettings from '~/pages/settings/goals.vue'
import MembersSettings from '~/pages/settings/members.vue'
import { seedState } from '../support/api'
import { makeSite, makeUser } from '../support/fixtures'

registerEndpoint('/api/v1/sites/1/goals', {
  method: 'GET',
  handler: () => ({ data: [{ id: 1, name: 'Signup', type: 'event', match: { name: 'signup' } }] })
})
registerEndpoint('/api/v1/sites/1/members', {
  method: 'GET',
  handler: () => ({ data: [{ user_id: 2, email: 'viewer@example.com', display_name: 'Vic Viewer', status: 'active', global_role: 'member', role: 'viewer', inherited: false }] })
})

async function tick() {
  await new Promise(resolve => setTimeout(resolve, 30))
  await nextTick()
}

describe('role-based UI', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
  })

  it('lets a site admin manage goals', async () => {
    seedState({ user: makeUser({ global_role: 'member' }), sites: [makeSite({ role: 'admin' })] })
    const wrapper = await mountSuspended(GoalsSettings)
    await tick()

    expect(wrapper.find('[data-testid="add-goal"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="goal-actions"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Signup')
  })

  it('hides every manage action from a viewer', async () => {
    seedState({ user: makeUser({ global_role: 'member' }), sites: [makeSite({ role: 'viewer' })] })
    const wrapper = await mountSuspended(GoalsSettings)
    await tick()

    expect(wrapper.find('[data-testid="add-goal"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="goal-actions"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('Signup')
  })

  it('keeps the member list out of reach of viewers', async () => {
    seedState({ user: makeUser({ global_role: 'member' }), sites: [makeSite({ role: 'viewer' })] })
    const wrapper = await mountSuspended(MembersSettings)
    await tick()

    expect(wrapper.text()).toContain('Only site administrators')
    expect(wrapper.text()).not.toContain('viewer@example.com')
  })

  it('shows the member list to site admins', async () => {
    seedState({ user: makeUser({ global_role: 'admin' }), sites: [makeSite({ role: 'admin' })] })
    const wrapper = await mountSuspended(MembersSettings)
    await tick()

    expect(wrapper.text()).toContain('viewer@example.com')
    expect(wrapper.text()).toContain('Invite people')
  })
})
