import { beforeEach, describe, expect, it } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import UserMenu from '~/components/UserMenu.vue'
import JobsSlideover from '~/components/JobsSlideover.vue'
import NoSiteNotice from '~/components/NoSiteNotice.vue'
import PageNavbar from '~/components/PageNavbar.vue'
import LocaleSwitch from '~/components/LocaleSwitch.vue'
import SiteCreateModal from '~/components/SiteCreateModal.vue'
import AdminInvitationModal from '~/components/admin/InvitationModal.vue'
import { readJson, seedState, waitFor } from '../support/api'
import { mountInApp, resetAppState } from '../support/mount'
import { authConfig, makeSite, makeUser } from '../support/fixtures'

registerEndpoint('/api/v1/auth/config', { method: 'GET', handler: () => ({ data: authConfig }) })
registerEndpoint('/api/v1/auth/me', {
  method: 'PATCH',
  handler: async event => ({ data: { user: makeUser({ locale: (await readJson<{ locale: string }>(event)).locale }), csrf_token: 'c', session: { id: 'a', absolute_expires_at: '2026-10-01T00:00:00Z' } } })
})
registerEndpoint('/api/v1/admin/jobs', {
  method: 'GET',
  handler: () => ({
    data: {
      jobs: [
        { job: 'rollup:run', last_started_at: '2026-09-17T09:00:00Z', last_status: 'ok', last_message: null, last_duration_ms: 4200 },
        { job: 'geo:update', last_started_at: null, last_status: 'failed', last_message: 'Download failed', last_duration_ms: null }
      ],
      rollup_lag_minutes: 120,
      dirty_days: 2,
      geo_db_age_days: null,
      pending_consent_drafts: [{ site_id: 1, site_name: 'Example' }]
    }
  })
})
registerEndpoint('/api/v1/sites', { method: 'POST', handler: async event => ({ data: makeSite({ id: 7, ...(await readJson(event)) }) }) })
registerEndpoint('/api/v1/invitations', {
  method: 'POST',
  handler: async event => ({ data: { id: 5, email: (await readJson<{ email: string }>(event)).email, global_role: 'member', site_roles: [], status: 'pending', expires_at: '2026-10-01T00:00:00Z', created_at: '2026-09-17T00:00:00Z', link: 'https://stats.example.net/invite/token-123' } })
})

function menuLabels(wrapper: { findComponent: (s: { name: string }) => { props: (p: string) => unknown } }) {
  const groups = wrapper.findComponent({ name: 'UDropdownMenu' }).props('items') as { label: string, children?: { label: string, onSelect?: (e: Event) => void }[] }[][]
  return groups.flat()
}

describe('UserMenu', () => {
  beforeEach(() => {
    resetAppState()
    seedState({ user: makeUser({ display_name: 'Ada Admin' }) })
  })

  it('shows the signed-in user, version and source link', async () => {
    const wrapper = await mountInApp(UserMenu)
    await waitFor(() => !!useState('auth:config').value)

    expect(wrapper.text()).toContain('Ada Admin')
    const labels = menuLabels(wrapper).map(i => i.label)
    expect(labels).toContain('Version 1.0.0 (0123456)')
    expect(labels).toContain('Source code')
    expect(labels).toContain('Log out')
  })

  it('switches the interface language and stores it on the profile', async () => {
    const wrapper = await mountInApp(UserMenu)
    const language = menuLabels(wrapper).find(i => i.label === 'Language')!

    language.children!.find(c => c.label === 'Italiano')!.onSelect!(new Event('click'))
    await waitFor(() => useNuxtApp().$i18n.locale.value === 'it')

    expect(useState('auth:user').value).toMatchObject({ locale: 'it' })
    await useNuxtApp().$i18n.setLocale('en')
  })
})

describe('JobsSlideover', () => {
  beforeEach(() => {
    resetAppState()
    clearNuxtData()
    seedState()
  })

  it('summarises background jobs for global admins', async () => {
    const wrapper = await mountInApp(JobsSlideover)
    useDashboard().isJobsSlideoverOpen.value = true
    await waitFor(() => document.body.textContent!.includes('rollup:run'))

    expect(document.body.textContent).toContain('120 min')
    expect(document.body.textContent).toContain('2 days waiting')
    expect(document.body.textContent).toContain('Missing')
    expect(document.body.textContent).toContain('Download failed')
    expect(document.body.textContent).toContain('Example')
    // failing job + stale geo db + pending draft + rollup lag
    expect(wrapper.findComponent(JobsSlideover).vm.issues).toBe(4)
    useDashboard().isJobsSlideoverOpen.value = false
  })
})

describe('shell components', () => {
  beforeEach(() => {
    resetAppState()
  })

  it('invites people to create the first site', async () => {
    seedState({ user: makeUser({ global_role: 'admin' }), sites: [] })
    const admin = await mountInApp(NoSiteNotice)
    expect(admin.text()).toContain('Add your first site')

    seedState({ user: makeUser({ global_role: 'member' }), sites: [] })
    const member = await mountInApp(NoSiteNotice)
    expect(member.text()).toContain('Ask an administrator')
  })

  it('opens the system status from the navbar for global admins only', async () => {
    seedState({ user: makeUser({ global_role: 'admin' }) })
    const wrapper = await mountInApp(PageNavbar, { props: { title: 'Overview' } })
    expect(wrapper.text()).toContain('Overview')

    const button = wrapper.get('button[aria-label="System status"]')
    await button.trigger('click')
    expect(useDashboard().isJobsSlideoverOpen.value).toBe(true)
    useDashboard().isJobsSlideoverOpen.value = false

    seedState({ user: makeUser({ global_role: 'member' }) })
    const member = await mountInApp(PageNavbar, { props: { title: 'Overview' } })
    expect(member.find('button[aria-label="System status"]').exists()).toBe(false)
  })

  it('lists the available locales in the switch', async () => {
    seedState()
    const wrapper = await mountInApp(LocaleSwitch)
    const items = wrapper.findComponent({ name: 'USelect' }).props('items') as { value: string }[]
    expect(items.map(i => i.value)).toEqual(['en', 'it'])
  })

  it('creates a site from the modal', async () => {
    seedState({ sites: [] })
    const wrapper = await mountInApp(SiteCreateModal)
    useDashboard().isSiteModalOpen.value = true
    await waitFor(() => !!document.querySelector('input'))

    const inputs = [...document.querySelectorAll('input')] as HTMLInputElement[]
    inputs[0]!.value = 'New site'
    inputs[0]!.dispatchEvent(new Event('input'))
    wrapper.findComponent({ name: 'UInputTags' }).vm.$emit('update:modelValue', ['new.example'])
    await nextTick()

    const submit = [...document.querySelectorAll('button')].find(b => b.textContent?.trim() === 'Create')!
    submit.click()
    await waitFor(() => (useState('sites:list').value as { id: number }[]).some(s => s.id === 7))

    expect((useState('sites:list').value as { name: string }[]).at(-1)).toMatchObject({ name: 'New site' })
  })

  it('creates an invitation and shows the link once', async () => {
    seedState()
    const wrapper = await mountInApp(AdminInvitationModal, { props: { open: true } })
    await waitFor(() => !!document.querySelector('input[type="email"]'))

    const email = document.querySelector('input[type="email"]') as HTMLInputElement
    email.value = 'new@example.com'
    email.dispatchEvent(new Event('input'))
    await nextTick()

    const form = document.querySelector('form') as HTMLFormElement
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await waitFor(() => [...document.querySelectorAll('input')].some(i => i.value.includes('invite/token-123')))

    expect(document.body.textContent).toContain('Invitation created')
    expect(wrapper.findComponent(AdminInvitationModal).emitted('created')).toHaveLength(1)
  })
})
